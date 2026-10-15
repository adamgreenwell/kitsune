<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kitsune\Commerce\Blueprints\StorefrontBlueprint;
use Kitsune\Commerce\Catalogue\CatalogueWriter;
use Kitsune\Commerce\Catalogue\ProductWriter;
use Kitsune\Commerce\Models\Sellable;
use Kitsune\Commerce\Offers\OfferTerms;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture;
use Kitsune\Core\Tests\Fixtures\OrderFixture;
use Kitsune\Core\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    config(['auth.providers.users.model' => TestUser::class]);
    $this->org = Org::create(['slug' => 'offers', 'name' => 'Offers', 'currency' => 'USD']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'offers-main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);
    Module::create(['handle' => 'kitsune/commerce', 'version' => 'dev-main', 'is_enabled' => true, 'installed_at' => now()]);
    BlueprintApplier::apply(new StorefrontBlueprint);
    $this->writer = app(CatalogueWriter::class);
    $this->products = app(ProductWriter::class);
    $this->commerce = ['sku' => 'SURF-101', 'price_minor' => '2000', 'stock' => null];
    $this->content = ['title' => 'Surf 101', 'slug' => 'surf-101', 'status' => 'published'];
});

afterEach(function (): void {
    Auth::guard('web')->logout();
    app(Context::class)->forget();
    Permissions::forget();
});

it('defaults older creates to no mapping and never infers one from a SKU or slug', function (): void {
    $row = $this->writer->createSellable('COURSE.SURF-101', 2000, null, 'USD');
    $product = $this->products->createProduct($this->commerce, 'USD', $this->content);

    expect($row->entitlement_name)->toBeNull()->and($row->fresh()->entitlement_name)->toBeNull()
        ->and($product->fresh()->entitlement_name)->toBeNull()
        ->and(DB::table('entitlements')->count())->toBe(0);
});

it('stores an undeclared canonical entitlement name and grants nobody access', function (): void {
    $name = 'course.'.str_repeat('a', 93);
    $row = $this->writer->createSellable('FUTURE-COURSE', 2000, null, 'USD', $name);
    $direct = Sellable::create(['sku' => 'DIRECT', 'price_minor' => 0, 'entitlement_name' => 'download.future-course']);

    expect(strlen($name))->toBe(100)->and($row->fresh()->entitlement_name)->toBe($name)
        ->and($direct->fresh()->entitlement_name)->toBe('download.future-course')
        ->and(DB::table('entitlements')->count())->toBe(0);
});

it('preserves omitted metadata, audits a real change once and explicitly clears with a named null argument', function (): void {
    $row = $this->writer->createSellable('SURF-101', 2000, null, 'USD', 'course.surf-101');
    $this->writer->updateSellable($row->getKey(), 'SURF-101', 2000, null, 'USD');

    expect($row->fresh()->entitlement_name)->toBe('course.surf-101')
        ->and(AuditLog::query()->where('action', 'commerce.sellable_updated')->count())->toBe(0);

    $this->writer->updateSellable($row->getKey(), 'SURF-101', 2000, null, 'USD', entitlementName: 'course.surf-201');
    $this->writer->updateSellable($row->getKey(), 'SURF-101', 2000, null, 'USD', entitlementName: 'course.surf-201');

    expect($row->fresh()->entitlement_name)->toBe('course.surf-201')
        ->and(AuditLog::query()->where('action', 'commerce.sellable_updated')->count())->toBe(1);

    $this->writer->updateSellable($row->getKey(), 'SURF-101', 2000, null, 'USD', entitlementName: null);

    expect($row->fresh()->entitlement_name)->toBeNull()
        ->and(AuditLog::query()->where('action', 'commerce.sellable_updated')->count())->toBe(2);
});

it('shares one mapping across sites while omitted old product payloads preserve it and explicit null clears it', function (): void {
    $row = $this->products->createProduct([...$this->commerce, 'entitlement_name' => 'course.surf-101'], 'USD', $this->content, [], true);
    $other = Site::create(['handle' => 'other', 'slug' => 'offers-other', 'name' => 'Other', 'locale' => 'en']);
    app(Context::class)->setSite($other);
    $this->products->updateProduct($row->getKey(), $this->commerce, 'USD', [...$this->content, 'title' => 'Another brand'], [], true);

    expect($row->fresh()->entitlement_name)->toBe('course.surf-101')
        ->and($row->listings()->firstOrFail()->sellable->entitlement_name)->toBe('course.surf-101');

    $this->products->updateProduct($row->getKey(), [...$this->commerce, 'entitlement_name' => 'course.surf-201'], 'USD', $this->content);
    app(Context::class)->setSite($this->site);

    expect($row->listings()->firstOrFail()->sellable->entitlement_name)->toBe('course.surf-201');

    $this->products->updateProduct($row->getKey(), [...$this->commerce, 'entitlement_name' => null], 'USD', $this->content);

    expect($row->fresh()->entitlement_name)->toBeNull()->and(DB::table('entitlements')->count())->toBe(0);
});

it('refuses malformed offer metadata through each write door without writing product state', function (mixed $name): void {
    $row = $this->writer->createSellable('KEPT', 2000, null, 'USD', 'course.kept');
    $before = DB::table('commerce_sellables')->orderBy('id')->get()->all();
    $audit = AuditLog::query()->count();
    $entries = DB::table('entries')->count();

    foreach ([
        fn () => $this->writer->createSellable('BAD', 2000, null, 'USD', $name),
        fn () => $this->writer->updateSellable($row->getKey(), 'KEPT', 2000, null, 'USD', $name),
        fn () => Sellable::create(['sku' => 'BAD', 'price_minor' => 2000, 'entitlement_name' => $name]),
        fn () => $row->fresh()->update(['entitlement_name' => $name]),
        fn () => $this->products->createProduct([...$this->commerce, 'entitlement_name' => $name], 'USD', $this->content),
    ] as $write) {
        expect($write)->toThrow(RuntimeException::class, 'must be a canonical entitlement name or null');
    }

    expect(DB::table('commerce_sellables')->orderBy('id')->get()->all())->toEqual($before)
        ->and(AuditLog::query()->count())->toBe($audit)
        ->and(DB::table('entries')->count())->toBe($entries);
})->with([
    'empty' => [''],
    'spaces' => [' course.surf-101 '],
    'uppercase' => ['Course.surf-101'],
    'numeric string' => ['81234'],
    'integer' => [81234],
    'float' => [1.0],
    'boolean' => [false],
    'array' => [['course.surf-101']],
    'object' => [(object) ['reader' => '81234']],
    'newline' => ["course.surf-101\n"],
    'overlong' => ['course.'.str_repeat('a', 94)],
]);

it('checks final observer values and refuses bulk or quiet metadata writes', function (): void {
    $row = $this->writer->createSellable('KEPT', 2000, null, 'USD', 'course.kept');
    Sellable::updating(static fn (Sellable $row) => $row->entitlement_name = '81234');

    try {
        expect(fn () => $this->writer->updateSellable($row->getKey(), 'KEPT', 2000, null, 'USD', 'course.changed'))
            ->toThrow(RuntimeException::class, 'must be a canonical entitlement name or null')
            ->and($row->fresh()->entitlement_name)->toBe('course.kept');
    } finally {
        Sellable::flushEventListeners();
        Sellable::clearBootedModels();
    }

    expect(Sellable::columnsRequiringModelSave())->toHaveKey('entitlement_name')
        ->and(fn () => Sellable::query()->whereKey($row->getKey())->update(['entitlement_name' => 'course.changed']))->toThrow(RuntimeException::class);
    $row = $row->fresh();
    $row->entitlement_name = 'course.changed';

    expect(fn () => $row->saveQuietly())->toThrow(RuntimeException::class)
        ->and(DB::table('commerce_sellables')->where('id', $row->getKey())->value('entitlement_name'))->toBe('course.kept');
});

it('gives members no authority over offer metadata and never writes across orgs', function (): void {
    $row = $this->writer->createSellable('KEPT', 2000, null, 'USD', 'course.kept');
    CredentialFixture::member(owner: false);

    expect(fn () => $this->writer->updateSellable($row->getKey(), 'KEPT', 2000, null, 'USD', 'course.changed'))
        ->toThrow(RuntimeException::class, 'Only an org owner');

    Auth::guard('web')->logout();
    Permissions::forget();
    $rival = Org::create(['slug' => 'rival', 'name' => 'Rival', 'currency' => 'USD']);
    app(Context::class)->setOrg($rival);

    expect(fn () => $this->writer->updateSellable($row->getKey(), 'KEPT', 2000, null, 'USD', 'course.changed'))
        ->toThrow(RuntimeException::class, 'not available in this org')
        ->and(DB::table('commerce_sellables')->where('id', $row->getKey())->value('entitlement_name'))->toBe('course.kept');
});

it('refuses a destructive mapping rollback without org or module context', function (): void {
    $this->writer->createSellable('KEPT', 2000, null, 'USD', 'course.kept');
    app(Context::class)->forget();
    DB::table('modules')->where('handle', 'kitsune/commerce')->update(['is_enabled' => false]);
    $migration = require dirname(__DIR__, 2).'/packages/commerce/database/migrations/0001_01_01_000202_add_entitlement_name_to_commerce_sellables.php';

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Clear every product entitlement mapping explicitly')
        ->and(Schema::hasColumn('commerce_sellables', 'entitlement_name'))->toBeTrue()
        ->and(DB::table('commerce_sellables')->value('entitlement_name'))->toBe('course.kept');
});

it('checks the primary before dropping mapped offers even when a lagging read connection has no mapping', function (): void {
    // An isolated level-zero connection: RefreshDatabase's transaction would otherwise force every read to the primary
    // and conceal a missing useWritePdo(). The two PDOs hold only this migration's relevant columns, on SQLite in memory.
    $name = 'offer_metadata_rollback_probe';
    config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'probe_']]);
    $connection = DB::connection($name);
    $primary = $connection->getPdo();
    $primary->exec('create table probe_commerce_sellables (id integer primary key, entitlement_name varchar(100) null)');
    $primary->exec("insert into probe_commerce_sellables (entitlement_name) values ('course.kept')");
    $replica = new PDO('sqlite::memory:');
    $replica->exec('create table probe_commerce_sellables (id integer primary key, entitlement_name varchar(100) null)');
    $replica->exec('insert into probe_commerce_sellables (entitlement_name) values (null)');
    $connection->setReadPdo($replica);
    $migration = require dirname(__DIR__, 2).'/packages/commerce/database/migrations/0001_01_01_000202_add_entitlement_name_to_commerce_sellables.php';
    (new ReflectionProperty($migration, 'connection'))->setValue($migration, $name);

    try {
        expect($connection->transactionLevel())->toBe(0)
            ->and($connection->table('commerce_sellables')->whereNotNull('entitlement_name')->exists())->toBeFalse()
            ->and($connection->table('commerce_sellables')->useWritePdo()->whereNotNull('entitlement_name')->exists())->toBeTrue()
            ->and(fn () => $migration->down())->toThrow(RuntimeException::class, 'Clear every product entitlement mapping explicitly')
            ->and($connection->table('commerce_sellables')->useWritePdo()->value('entitlement_name'))->toBe('course.kept')
            ->and($primary->query('pragma table_info(probe_commerce_sellables)')->fetchAll(PDO::FETCH_COLUMN, 1))->toContain('entitlement_name');
    } finally {
        DB::purge($name);
        config(['database.connections.'.$name => null]);
    }
});

it('can roll back and reapply the mapping column while every product explicitly has no mapping', function (): void {
    if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('DDL commits the wrapper on MySQL and MariaDB; SQLite and PostgreSQL exercise the non-destructive rollback.');
    }

    $row = $this->writer->createSellable('KEPT', 2000, null, 'USD');
    $migration = require dirname(__DIR__, 2).'/packages/commerce/database/migrations/0001_01_01_000202_add_entitlement_name_to_commerce_sellables.php';
    $migration->down();

    expect(Schema::hasColumn('commerce_sellables', 'entitlement_name'))->toBeFalse()
        ->and(DB::table('commerce_sellables')->where('id', $row->getKey())->value('sku'))->toBe('KEPT');

    $migration->up();

    expect(Schema::hasColumn('commerce_sellables', 'entitlement_name'))->toBeTrue()
        ->and($row->fresh()->entitlement_name)->toBeNull();
});

it('hides arbitrary malformed metadata at every parameter that receives it', function (): void {
    foreach ([
        [Sellable::class, 'entitlementNameValue', 'value'],
        [CatalogueWriter::class, 'createSellable', 'entitlementName'],
        [CatalogueWriter::class, 'updateSellable', 'entitlementName'],
        [ProductWriter::class, 'createProduct', 'commerceData'],
        [ProductWriter::class, 'updateProduct', 'commerceData'],
        [ProductWriter::class, 'write', 'commerce'],
        [ProductWriter::class, 'onlyKeys', 'data'],
    ] as [$class, $method, $name]) {
        $parameters = array_filter((new ReflectionMethod($class, $method))->getParameters(), static fn (ReflectionParameter $parameter): bool => $parameter->getName() === $name);

        expect($parameters)->toHaveCount(1)
            ->and(array_values($parameters)[0]->getAttributes(SensitiveParameter::class))->toHaveCount(1);
    }

    $previous = ini_set('zend.exception_ignore_args', '0');

    try {
        $this->writer->createSellable('BAD', 2000, null, 'USD', '81234');
        $this->fail('a malformed metadata value was accepted');
    } catch (RuntimeException $invalid) {
        expect($invalid->getMessage())->not->toContain('81234')
            ->and($invalid->getTraceAsString())->not->toContain('81234')
            ->and($invalid->getTraceAsString())->toContain('SensitiveParameterValue');
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous);
    }
});

it('shares explicit purchase terms with sibling sites and preserves omitted terms without granting', function (): void {
    $terms = ['version' => 1, 'kind' => 'fixed', 'days' => 30];
    $product = $this->products->createProduct([...$this->commerce, 'entitlement_name' => 'course.surf', 'offer_terms' => $terms], 'USD', $this->content, [], true);
    $other = Site::create(['handle' => 'offer-other', 'slug' => 'terms-other', 'name' => 'Other', 'locale' => 'en']);
    app(Context::class)->setSite($other);
    $this->products->updateProduct($product->getKey(), $this->commerce, 'USD', [...$this->content, 'title' => 'Other brand'], [], true);

    expect(OfferTerms::normalize($product->fresh()->offer_terms))->toBe($terms)
        ->and(DB::table('commerce_listings')->where('org_id', $this->org->getKey())->where('sellable_id', $product->getKey())->count())->toBe(2);
    $this->products->updateProduct($product->getKey(), [...$this->commerce, 'offer_terms' => ['version' => 1, 'kind' => 'permanent']], 'USD', $this->content, [], true);
    app(Context::class)->setSite($this->site);
    expect(OfferTerms::normalize($product->fresh()->offer_terms))->toBe(['version' => 1, 'kind' => 'permanent'])
        ->and($product->fresh()->entitlement_name)->toBe('course.surf')->and(DB::table('entitlements')->count())->toBe(0);

    $this->writer->updateSellable($product->getKey(), 'SURF-101', '2000', null, 'USD', entitlementName: null);
    expect($product->fresh()->offer_terms)->toBeNull()->and($product->fresh()->entitlement_name)->toBeNull();
});

it('treats reordered offer object keys as one semantic change', function (): void {
    $product = $this->writer->createSellable('TERMS', '2000', null, 'USD', 'course.surf', ['kind' => 'fixed', 'days' => 30, 'version' => 1]);
    // A database JSON implementation is allowed to change key order, not scalar types or meaning.
    DB::table('commerce_sellables')->where('id', $product->getKey())->update(['offer_terms' => '{"days":30,"version":1,"kind":"fixed"}']);
    $this->writer->updateSellable($product->getKey(), 'TERMS', '2000', null, 'USD', 'course.surf', ['version' => 1, 'kind' => 'fixed', 'days' => 30]);
    expect(AuditLog::query()->where('action', 'commerce.sellable_updated')->count())->toBe(0);
    $this->writer->updateSellable($product->getKey(), 'TERMS', '2000', null, 'USD', 'course.surf', ['version' => 1, 'kind' => 'permanent']);
    expect(AuditLog::query()->where('action', 'commerce.sellable_updated')->count())->toBe(1);
});

it('refuses incompatible terms through all catalogue write doors without partial state', function (mixed $terms): void {
    $product = $this->writer->createSellable('KEPT', '2000', null, 'USD', 'course.kept', ['version' => 1, 'kind' => 'permanent']);
    $before = DB::table('commerce_sellables')->orderBy('id')->get()->all();
    $audit = AuditLog::query()->count();
    foreach ([
        fn () => $this->writer->createSellable('BAD', '2000', null, 'USD', 'course.bad', $terms),
        fn () => $this->writer->updateSellable($product->getKey(), 'KEPT', '2000', null, 'USD', 'course.kept', $terms),
        fn () => Sellable::create(['sku' => 'BAD', 'price_minor' => '2000', 'entitlement_name' => 'course.bad', 'offer_terms' => $terms]),
        fn () => $product->fresh()->update(['offer_terms' => $terms]),
        fn () => $this->products->createProduct([...$this->commerce, 'entitlement_name' => 'course.bad', 'offer_terms' => $terms], 'USD', $this->content),
    ] as $write) {
        expect($write)->toThrow(RuntimeException::class);
    }
    expect(DB::table('commerce_sellables')->orderBy('id')->get()->all())->toEqual($before)
        ->and(AuditLog::query()->count())->toBe($audit);
})->with([
    'unknown version' => [['version' => 2, 'kind' => 'permanent']], 'scalar' => ['permanent'],
    'float day' => [['version' => 1, 'kind' => 'fixed', 'days' => 1.0]],
    'extra term' => [['version' => 1, 'kind' => 'permanent', 'reader_id' => 'private']],
]);

it('refuses observer and audit substitutions of access terms and bulk or quiet bypasses', function (): void {
    $terms = ['version' => 1, 'kind' => 'fixed', 'days' => 30];
    $product = $this->writer->createSellable('KEPT', '2000', null, 'USD', 'course.kept', $terms);
    Sellable::updating(static fn (Sellable $row) => $row->offer_terms = ['version' => 1, 'kind' => 'fixed', 'days' => 30.0]);
    try {
        expect(fn () => $this->writer->updateSellable($product->getKey(), 'KEPT', '2000', null, 'USD', 'course.kept', ['version' => 1, 'kind' => 'permanent']))
            ->toThrow(RuntimeException::class);
    } finally {
        Sellable::flushEventListeners();
        Sellable::clearBootedModels();
    }
    AuditLog::creating(static function (AuditLog $row) use ($product): void {
        if ($row->action === 'commerce.sellable_updated') {
            DB::table('commerce_sellables')->where('id', $product->getKey())->update(['offer_terms' => '{"version":1,"kind":"fixed","days":36500}']);
        }
    });
    try {
        expect(fn () => $this->writer->updateSellable($product->getKey(), 'KEPT', '2000', null, 'USD', 'course.kept', ['version' => 1, 'kind' => 'permanent']))
            ->toThrow(RuntimeException::class);
    } finally {
        AuditLog::flushEventListeners();
        AuditLog::clearBootedModels();
    }
    expect(OfferTerms::normalize($product->fresh()->offer_terms))->toBe($terms)
        ->and(fn () => Sellable::query()->whereKey($product->getKey())->update(['offer_terms' => null]))->toThrow(RuntimeException::class);
    $product = $product->fresh();
    $product->offer_terms = ['version' => 1, 'kind' => 'permanent'];
    expect(fn () => $product->saveQuietly())->toThrow(RuntimeException::class)
        ->and(OfferTerms::normalize($product->fresh()->offer_terms))->toBe($terms);
});

it('gives neither another org nor ordinary members authority to replace configured terms', function (): void {
    $terms = ['version' => 1, 'kind' => 'fixed', 'days' => 30];
    $product = $this->writer->createSellable('KEPT', '2000', null, 'USD', 'course.kept', $terms);
    CredentialFixture::member(owner: false);
    expect(fn () => $this->writer->updateSellable($product->getKey(), 'KEPT', '2000', null, 'USD', 'course.kept', ['version' => 1, 'kind' => 'permanent']))
        ->toThrow(RuntimeException::class);
    Auth::guard('web')->logout();
    Permissions::forget();
    app(Context::class)->setOrg(Org::create(['slug' => 'terms-rival', 'name' => 'Rival', 'currency' => 'USD']));
    expect(fn () => $this->writer->updateSellable($product->getKey(), 'KEPT', '2000', null, 'USD', 'course.kept', ['version' => 1, 'kind' => 'permanent']))
        ->toThrow(RuntimeException::class)
        ->and(OfferTerms::stored(DB::table('commerce_sellables')->where('id', $product->getKey())->value('offer_terms')))->toBe($terms);
});

it('refuses dropping retained product terms without org or module context', function (): void {
    $this->writer->createSellable('KEPT', '2000', null, 'USD', 'course.kept', ['version' => 1, 'kind' => 'permanent']);
    app(Context::class)->forget();
    $migration = require dirname(__DIR__, 2).'/packages/commerce/database/migrations/0001_01_01_000211_add_access_offer_terms.php';
    expect(fn () => $migration->down())->toThrow(RuntimeException::class)
        ->and(Schema::hasColumn('commerce_sellables', 'offer_terms'))->toBeTrue();
});

it('also refuses dropping offer configuration while an immutable order retains known terms', function (): void {
    OrderFixture::plant($this->site, lineOverrides: ['offer_terms' => '{"version":1,"kind":"permanent"}']);
    app(Context::class)->forget();
    $migration = require dirname(__DIR__, 2).'/packages/commerce/database/migrations/0001_01_01_000211_add_access_offer_terms.php';
    expect(fn () => $migration->down())->toThrow(RuntimeException::class)
        ->and(Schema::hasColumn('commerce_sellables', 'offer_terms'))->toBeTrue()
        ->and(DB::table('commerce_order_lines')->value('offer_terms'))->not->toBeNull();
});

it('checks primary retained offers before rollback when a replica has no configured terms', function (): void {
    $name = 'offer_terms_rollback_probe';
    config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'probe_']]);
    $connection = DB::connection($name);
    $primary = $connection->getPdo();
    $primary->exec('create table probe_commerce_sellables (id integer primary key, offer_terms text null)');
    $primary->exec('create table probe_commerce_order_lines (id integer primary key, offer_terms text null)');
    $primary->exec('insert into probe_commerce_sellables (offer_terms) values (\'{"version":1,"kind":"permanent"}\')');
    $replica = new PDO('sqlite::memory:');
    $replica->exec('create table probe_commerce_sellables (id integer primary key, offer_terms text null)');
    $replica->exec('create table probe_commerce_order_lines (id integer primary key, offer_terms text null)');
    $connection->setReadPdo($replica);
    $migration = require dirname(__DIR__, 2).'/packages/commerce/database/migrations/0001_01_01_000211_add_access_offer_terms.php';
    (new ReflectionProperty($migration, 'connection'))->setValue($migration, $name);
    try {
        expect($connection->transactionLevel())->toBe(0)
            ->and($connection->table('commerce_sellables')->whereNotNull('offer_terms')->exists())->toBeFalse()
            ->and(fn () => $migration->down())->toThrow(RuntimeException::class)
            ->and($primary->query('pragma table_info(probe_commerce_sellables)')->fetchAll(PDO::FETCH_COLUMN, 1))->toContain('offer_terms');
    } finally {
        DB::purge($name);
        config(['database.connections.'.$name => null]);
    }
});

it('can remove and reapply only empty access offer configuration without inventing terms', function (): void {
    if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('DDL commits the wrapper on MySQL and MariaDB; native lifecycle exercises teardown.');
    }
    $product = $this->writer->createSellable('LEGACY', '2000', null, 'USD', 'course.legacy');
    $migration = require dirname(__DIR__, 2).'/packages/commerce/database/migrations/0001_01_01_000211_add_access_offer_terms.php';
    $migration->down();
    expect(Schema::hasColumn('commerce_sellables', 'offer_terms'))->toBeFalse();
    $migration->up();
    expect($product->fresh()->offer_terms)->toBeNull()->and($product->fresh()->entitlement_name)->toBe('course.legacy');
});
