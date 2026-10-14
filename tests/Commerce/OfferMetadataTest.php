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
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture;
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
