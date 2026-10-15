<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kitsune\Commerce\Blueprints\StorefrontBlueprint;
use Kitsune\Commerce\Catalogue\ProductType;
use Kitsune\Commerce\Catalogue\ProductWriter;
use Kitsune\Commerce\Catalogue\PublicCatalogue;
use Kitsune\Commerce\Models\Sellable;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Entitlements\EntitlementUnavailable;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\MediaFile;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\MissingTableReader;

beforeEach(function (): void {
    Fx::boot();
    $this->org = Org::create(['slug' => 'catalogue', 'name' => 'Catalogue', 'currency' => 'USD']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'catalogue-main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);
    Module::create(['handle' => 'kitsune/commerce', 'version' => 'dev-main', 'is_enabled' => true, 'installed_at' => now()]);
    BlueprintApplier::apply(new StorefrontBlueprint);
    $this->type = ProductType::current();
});

afterEach(fn () => Fx::tearDown());

/** A real combined product save; planted changes below writers are reserved for corrupted-read fixtures. */
function publicProduct(string $sku = 'BOARD', array $content = [], ?string $stock = '5', string $price = '2000'): Sellable
{
    return app(ProductWriter::class)->createProduct(['sku' => $sku, 'price_minor' => $price, 'stock' => $stock], 'USD', [
        'title' => 'Product '.$sku, 'slug' => strtolower($sku), 'status' => 'published',
        'values' => ['product_description' => '<p>A &amp; B</p>'], ...$content,
    ], [], true);
}

function publicProductEntry(Sellable $product): Entry
{
    return $product->listings()->firstOrFail()->entry()->firstOrFail();
}

/** @return list<array{sql:string,bindings:array<int,mixed>}> */
function catalogueQueries(Closure $run): array
{
    $queries = [];
    DB::listen(static function (QueryExecuted $query) use (&$queries): void {
        $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
    });
    $run();

    return $queries;
}

function publicImage(string $name, string $visibility = 'public', bool $siteOnly = false): Entry
{
    $type = EntryType::query()->where('org_id', app(Context::class)->orgId())->where('handle', 'product_image')->firstOrFail();
    $entry = Entry::create(['entry_type_id' => $type->getKey(), 'site_id' => $siteOnly ? app(Context::class)->siteId() : null, 'title' => $name]);
    MediaFile::create([
        'entry_id' => $entry->getKey(), 'disk' => $visibility === 'public' ? 'public' : MediaDisks::PRIVATE,
        'path' => 'media/catalogue-'.$name.'.png', 'mime' => 'image/png', 'size_bytes' => 1,
        'checksum' => str_repeat('a', 64), 'visibility' => $visibility,
    ]);

    return $entry;
}

it('returns only an explicit public projection with exact shared money and stock', function (): void {
    $product = publicProduct();
    publicProduct('UNTRACKED', stock: null, price: '9223372036854775807');
    publicProduct('ZERO', stock: '0', price: '0');
    $page = app(PublicCatalogue::class)->page();

    expect($page)->toMatchArray(['page' => 1, 'hasPrevious' => false, 'hasNext' => false])
        ->and($page['products'])->toHaveCount(3)
        ->and($page['products'][0])->toBe([
            'listingId' => (int) $product->listings()->firstOrFail()->getKey(),
            'title' => 'Product BOARD', 'description' => 'A & B', 'currency' => 'USD', 'price' => '20.00',
            'stock' => '5', 'imageUrl' => null, 'held' => null,
        ])->and($page['products'][1]['price'])->toBe('92233720368547758.07')
        ->and($page['products'][1]['stock'])->toBeNull()
        ->and($page['products'][2]['price'])->toBe('0.00')->and($page['products'][2]['stock'])->toBe('0');
});

it('uses fixed stable bounded pages and rejects unbounded page offsets', function (): void {
    foreach (range(25, 1) as $number) {
        publicProduct(sprintf('SKU-%02d', $number));
    }

    $first = app(PublicCatalogue::class)->page();
    $second = app(PublicCatalogue::class)->page(2);
    $empty = app(PublicCatalogue::class)->page(3);

    expect($first['products'])->toHaveCount(24)->and($first['products'][0]['title'])->toBe('Product SKU-01')
        ->and($first['products'][23]['title'])->toBe('Product SKU-24')->and($first['hasNext'])->toBeTrue()
        ->and($second['products'])->toHaveCount(1)->and($second['products'][0]['title'])->toBe('Product SKU-25')
        ->and($second['hasPrevious'])->toBeTrue()->and($second['hasNext'])->toBeFalse()
        ->and($empty['products'])->toBe([])->and($empty['hasPrevious'])->toBeTrue()
        ->and(fn () => app(PublicCatalogue::class)->page(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(PublicCatalogue::class)->page(10001))->toThrow(InvalidArgumentException::class);
});

it('keeps native current schema validation while sparse pages never scan to fill missing slots', function (): void {
    $products = [];

    foreach (range(1, 25) as $number) {
        $products[] = publicProduct(sprintf('SKU-%02d', $number));
    }

    $storage = FieldStorage::create(['org_id' => $this->org->getKey(), 'handle' => 'new_requirement', 'type' => 'text', 'pii_class' => 'none', 'settings' => ['maxLength' => 10, 'pattern' => '^good$']]);
    Field::create(['entry_type_id' => $this->type->getKey(), 'field_storage_id' => $storage->getKey(), 'label' => 'Requirement', 'is_required' => true]);
    publicProductEntry($products[24])->update(['values' => ['product_description' => '<p>Last</p>', 'new_requirement' => 'good']]);
    $first = app(PublicCatalogue::class)->page();
    $second = app(PublicCatalogue::class)->page(2);

    expect($first['products'])->toBe([])->and($first['hasNext'])->toBeTrue()->and($first['hasCandidates'])->toBeTrue()
        ->and($second['products'])->toHaveCount(1)->and($second['products'][0]['title'])->toBe('Product SKU-25');

    publicProductEntry($products[24])->update(['values' => ['new_requirement' => 'bad']]);
    expect(app(PublicCatalogue::class)->page(2)['products'])->toBe([]);
});

it('refuses unavailable unpublished trashed or incoherent endpoints', function (string $failure): void {
    $product = publicProduct();
    $entry = publicProductEntry($product);

    match ($failure) {
        'unavailable' => DB::table('commerce_listings')->where('sellable_id', $product->getKey())->update(['is_available' => false]),
        'draft' => $entry->update(['status' => 'draft']),
        'archived' => $entry->update(['status' => 'archived']),
        'trash' => $entry->delete(),
        'shared' => DB::table('entries')->where('id', $entry->getKey())->update(['site_id' => null]),
        'wrong type' => DB::table('entries')->where('id', $entry->getKey())->update(['entry_type_id' => EntryType::query()->where('handle', 'product_image')->value('id')]),
        'malformed mapping' => DB::table('commerce_sellables')->where('id', $product->getKey())->update(['entitlement_name' => 'course.bad.name']),
        'bad money' => DB::table('commerce_sellables')->where('id', $product->getKey())->update(['price_minor' => -1]),
        'bad stock' => DB::table('commerce_sellables')->where('id', $product->getKey())->update(['stock' => -1]),
    };

    expect(app(PublicCatalogue::class)->page()['products'])->toBe([]);
})->with(['unavailable', 'draft', 'archived', 'trash', 'shared', 'wrong type', 'malformed mapping', 'bad money', 'bad stock']);

it('refuses a disabled module disabled product type or invalid receipt and empty context', function (string $failure): void {
    publicProduct();

    match ($failure) {
        'module' => Module::query()->where('handle', 'kitsune/commerce')->firstOrFail()->update(['is_enabled' => false]),
        'type' => EntryTypeAvailability::create(['entry_type_id' => $this->type->getKey(), 'scope_type' => 'site', 'scope_id' => $this->site->getKey(), 'is_enabled' => false]),
        'receipt' => Blueprint::receiptFor('storefront')->update(['applied_at' => null]),
        'context' => app(Context::class)->forget(),
    };

    expect(app(PublicCatalogue::class)->page()['products'])->toBe([]);
})->with(['module', 'type', 'receipt', 'context']);

it('shows only each sites content while price stock and mapping are shared and access remains per site', function (): void {
    $product = publicProduct();
    DB::table('commerce_sellables')->where('id', $product->getKey())->update(['entitlement_name' => 'course.board']);
    Fx::declareReaders();
    $reader = Fx::reader();
    Fx::signIn($reader);
    Fx::plant($this->site, (string) $reader->getKey(), 'course.board');
    $sibling = Site::create(['handle' => 'sibling', 'slug' => 'catalogue-sibling', 'name' => 'Sibling', 'locale' => 'fr']);
    app(Context::class)->setSite($sibling);
    app(ProductWriter::class)->updateProduct($product->getKey(), ['sku' => 'BOARD', 'price_minor' => '3100', 'stock' => '7'], 'USD', [
        'title' => 'Une planche', 'slug' => 'planche', 'status' => 'published', 'values' => ['product_description' => '<p>French description</p>'],
    ], [], true);
    $second = app(PublicCatalogue::class)->page()['products'];
    app(Context::class)->setSite($this->site);
    $first = app(PublicCatalogue::class)->page()['products'];

    expect($first)->toHaveCount(1)->and($second)->toHaveCount(1)
        ->and($first[0]['title'])->toBe('Product BOARD')->and($first[0]['description'])->toBe('A & B')->and($first[0]['held'])->toBeTrue()
        ->and($second[0]['title'])->toBe('Une planche')->and($second[0]['description'])->toBe('French description')->and($second[0]['held'])->toBeFalse()
        ->and($first[0]['price'])->toBe('31.00')->and($second[0]['price'])->toBe('31.00')
        ->and($first[0]['stock'])->toBe('5')->and($second[0]['stock'])->toBe('5');

    $otherOrg = Org::create(['slug' => 'rival', 'name' => 'Rival', 'currency' => 'USD']);
    app(Context::class)->setOrg($otherOrg);
    $otherSite = Site::create(['handle' => 'rival', 'slug' => 'rival-main', 'name' => 'Rival', 'locale' => 'en']);
    app(Context::class)->setSite($otherSite);
    BlueprintApplier::apply(new StorefrontBlueprint);
    publicProduct('RIVAL');
    $other = app(PublicCatalogue::class)->page()['products'];
    expect(array_column($other, 'title'))->toBe(['Product RIVAL']);
});

it('does not expose arbitrary or personal fields and bounds plain description text', function (): void {
    $storage = FieldStorage::create(['org_id' => $this->org->getKey(), 'handle' => 'internal_note', 'type' => 'text', 'pii_class' => 'personal']);
    Field::create(['entry_type_id' => $this->type->getKey(), 'field_storage_id' => $storage->getKey(), 'label' => 'Internal note']);
    publicProduct(content: ['values' => ['product_description' => '<p>'.str_repeat('é', 300).'</p>', 'internal_note' => 'private internal note']]);
    $shown = app(PublicCatalogue::class)->page()['products'][0];
    expect(mb_strlen($shown['description']))->toBe(240)->and(json_encode($shown))->not->toContain('private internal note', 'values', 'entry_id', 'org_id', 'sellable_id');
    FieldStorage::query()->where('handle', 'product_description')->firstOrFail()->update(['pii_class' => 'personal']);
    expect(app(PublicCatalogue::class)->page()['products'][0]['description'])->toBeNull();
});

it('formats zero and three minor unit currencies without rounding', function (string $currency, string $minor, string $expected): void {
    $this->org->update(['currency' => $currency]);
    app(ProductWriter::class)->createProduct(['sku' => 'LOCAL', 'price_minor' => $minor, 'stock' => null], $currency, [
        'title' => 'Local currency', 'status' => 'published', 'values' => [],
    ], [], true);
    $shown = app(PublicCatalogue::class)->page()['products'][0];
    expect($shown['currency'])->toBe($currency)->and($shown['price'])->toBe($expected);
})->with([['JPY', '1234', '1234'], ['KWD', '1234', '1.234']]);

it('refuses corrupted missing or foreign current schema rather than publishing its values', function (string $failure): void {
    $product = publicProduct();
    $entry = publicProductEntry($product);
    $storage = FieldStorage::query()->where('handle', 'product_description')->firstOrFail();

    if ($failure === 'unknown type') {
        DB::table('field_storage')->where('id', $storage->getKey())->update(['type' => 'not_registered']);
    } elseif ($failure === 'null values') {
        DB::table('entries')->where('id', $entry->getKey())->update(['values' => null]);
    } else {
        $other = Org::create(['slug' => 'foreign-schema', 'name' => 'Foreign schema']);
        DB::table('field_storage')->where('id', $storage->getKey())->update(['org_id' => $other->getKey()]);
    }

    expect(app(PublicCatalogue::class)->page()['products'])->toBe([]);
})->with(['unknown type', 'null values', 'foreign storage']);

it('refuses a cross org listing and target planted beneath model guards from the attacked sites side', function (): void {
    $product = publicProduct();
    $ownEntry = publicProductEntry($product);
    $other = Org::create(['slug' => 'attacker', 'name' => 'Attacker', 'currency' => 'USD']);
    app(Context::class)->setOrg($other);
    $otherSite = Site::create(['handle' => 'attacker', 'slug' => 'attacker-site', 'name' => 'Attacker', 'locale' => 'en']);
    app(Context::class)->setSite($otherSite);
    BlueprintApplier::apply(new StorefrontBlueprint);
    $rival = publicProduct('RIVAL');
    $foreignEntry = publicProductEntry($rival);
    app(Context::class)->setSite($this->site);
    DB::table('commerce_listings')->where('entry_id', $ownEntry->getKey())->update(['sellable_id' => $rival->getKey()]);
    expect(app(PublicCatalogue::class)->page()['products'])->toBe([]);
    DB::table('commerce_listings')->where('entry_id', $ownEntry->getKey())->update(['sellable_id' => $product->getKey(), 'entry_id' => $foreignEntry->getKey()]);
    expect(app(PublicCatalogue::class)->page()['products'])->toBe([]);
});

it('chooses the first eligible public image without private URLs and honors image type availability', function (): void {
    $product = publicProduct();
    $private = publicImage('private', 'private');
    $public = publicImage('public');
    $later = publicImage('later');
    $entry = publicProductEntry($product);
    $storage = FieldStorage::query()->where('org_id', $this->org->getKey())->where('handle', 'product_images')->firstOrFail();
    $entry->syncFieldRelations($storage, [$private->getKey(), $public->getKey(), $later->getKey()]);
    $shown = app(PublicCatalogue::class)->page()['products'][0];

    expect($shown['imageUrl'])->toEndWith('/media/catalogue-public.png')->and($shown['imageUrl'])->not->toContain('/admin/', 'private', 'later');
    EntryTypeAvailability::create(['entry_type_id' => $public->entry_type_id, 'scope_type' => 'site', 'scope_id' => $this->site->getKey(), 'is_enabled' => false]);
    expect(app(PublicCatalogue::class)->page()['products'][0]['imageUrl'])->toBeNull();
});

it('reads eligible public images on a prefixed SQLite host without crossing site or org boundaries', function (): void {
    // A separate in-memory database exercises the full read, without changing the wrapped engine connection or
    // creating duplicate foreign-key names in the server database when this suite runs on MySQL or MariaDB.
    $default = DB::getDefaultConnection();
    $name = 'catalogue_prefixed';
    config(['database.connections.'.$name => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'host_', 'foreign_key_constraints' => true,
    ]]);

    try {
        DB::setDefaultConnection($name);
        Schema::clearResolvedInstance('db.schema');
        app(Context::class)->forget();
        Fx::forget();
        $root = dirname(__DIR__, 2);

        foreach ([...glob($root.'/packages/core/database/migrations/*.php'), ...glob($root.'/packages/commerce/database/migrations/*.php')] as $path) {
            (require $path)->up();
        }

        expect(DB::connection()->getTablePrefix())->toBe('host_')
            ->and(DB::select('PRAGMA foreign_keys')[0]->foreign_keys)->toBe(1);
        $org = Org::create(['slug' => 'prefixed', 'name' => 'Prefixed', 'currency' => 'USD']);
        app(Context::class)->setOrg($org);
        $site = Site::create(['handle' => 'main', 'slug' => 'prefixed-main', 'name' => 'Main', 'locale' => 'en']);
        app(Context::class)->setSite($site);
        Module::create(['handle' => 'kitsune/commerce', 'version' => 'dev-main', 'is_enabled' => true, 'installed_at' => now()]);
        BlueprintApplier::apply(new StorefrontBlueprint);
        $product = publicProduct('PREFIXED');
        $private = publicImage('prefixed-private', 'private');
        $image = publicImage('prefixed-public');
        $storage = FieldStorage::query()->where('handle', 'product_images')->firstOrFail();
        publicProductEntry($product)->syncFieldRelations($storage, [$private->getKey(), $image->getKey()]);

        $sibling = Site::create(['handle' => 'sibling', 'slug' => 'prefixed-sibling', 'name' => 'Sibling', 'locale' => 'en']);
        app(Context::class)->setSite($sibling);
        $second = publicProduct('SIBLING');
        $secondImage = publicImage('prefixed-sibling', siteOnly: true);
        publicProductEntry($second)->syncFieldRelations($storage, [$secondImage->getKey()]);
        $otherOrg = Org::create(['slug' => 'prefixed-rival', 'name' => 'Rival', 'currency' => 'USD']);
        app(Context::class)->setOrg($otherOrg);
        $otherSite = Site::create(['handle' => 'rival', 'slug' => 'prefixed-rival', 'name' => 'Rival', 'locale' => 'en']);
        app(Context::class)->setSite($otherSite);
        BlueprintApplier::apply(new StorefrontBlueprint);
        $rival = publicProduct('RIVAL');
        $rivalImage = publicImage('prefixed-rival');
        $rivalStorage = FieldStorage::query()->where('org_id', $otherOrg->getKey())->where('handle', 'product_images')->firstOrFail();
        publicProductEntry($rival)->syncFieldRelations($rivalStorage, [$rivalImage->getKey()]);

        app(Context::class)->setSite($site);
        $shown = app(PublicCatalogue::class)->page()['products'];
        expect(array_column($shown, 'title'))->toBe(['Product PREFIXED'])
            ->and($shown[0]['imageUrl'])->toEndWith('/media/catalogue-prefixed-public.png')
            ->and(json_encode($shown))->not->toContain('prefixed-private', 'prefixed-sibling', 'prefixed-rival', '/admin/');
        app(Context::class)->setSite($sibling);
        expect(array_column(app(PublicCatalogue::class)->page()['products'], 'title'))->toBe(['Product SIBLING']);
        app(Context::class)->setSite($otherSite);
        expect(array_column(app(PublicCatalogue::class)->page()['products'], 'title'))->toBe(['Product RIVAL']);
    } finally {
        DB::setDefaultConnection($default);
        Schema::clearResolvedInstance('db.schema');
        DB::purge($name);
        app(Context::class)->setSite($this->site);
        Fx::forget();
    }
});

it('checks required relation shape and visibility in page wide counts', function (string $failure): void {
    $product = publicProduct();
    $storage = FieldStorage::query()->where('org_id', $this->org->getKey())->where('handle', 'product_images')->firstOrFail();
    $field = Field::query()->where('entry_type_id', $this->type->getKey())->where('field_storage_id', $storage->getKey())->firstOrFail();
    $field->update(['is_required' => true]);
    $image = publicImage('relation');
    $entry = publicProductEntry($product);

    if ($failure !== 'missing') {
        $entry->syncFieldRelations($storage, [$image->getKey()]);
    }

    if ($failure === 'trashed') {
        $image->delete();
    } elseif ($failure === 'sibling') {
        $sibling = Site::create(['handle' => 'other', 'slug' => 'catalogue-other', 'name' => 'Other', 'locale' => 'en']);
        DB::table('entries')->where('id', $image->getKey())->update(['site_id' => $sibling->getKey()]);
    } elseif ($failure === 'wrong stamp') {
        $otherOrg = Org::create(['slug' => 'wrong', 'name' => 'Wrong']);
        DB::table('entry_relations')->where('source_entry_id', $entry->getKey())->update(['org_id' => $otherOrg->getKey()]);
    } elseif ($failure === 'wrong type') {
        DB::table('entries')->where('id', $image->getKey())->update(['type_handle' => 'article']);
    }

    expect(app(PublicCatalogue::class)->page()['products'])->toBe([]);
})->with(['missing', 'trashed', 'sibling', 'wrong stamp', 'wrong type']);

it('honors a current finite relation cardinality without loading every linked target', function (): void {
    $product = publicProduct();
    $one = publicImage('one');
    $two = publicImage('two');
    $storage = FieldStorage::query()->where('handle', 'product_images')->firstOrFail();
    publicProductEntry($product)->syncFieldRelations($storage, [$one->getKey(), $two->getKey()]);
    DB::table('field_storage')->where('id', $storage->getKey())->update(['cardinality' => 1]);
    expect(app(PublicCatalogue::class)->page()['products'])->toBe([]);
});

it('keeps a personal image field or a public image on a private disk out of the public projection', function (string $failure): void {
    $product = publicProduct();
    $image = publicImage('withheld');
    $storage = FieldStorage::query()->where('handle', 'product_images')->firstOrFail();
    publicProductEntry($product)->syncFieldRelations($storage, [$image->getKey()]);

    if ($failure === 'personal') {
        $storage->update(['pii_class' => 'personal']);
    } else {
        DB::table('media_files')->where('entry_id', $image->getKey())->update(['disk' => MediaDisks::PRIVATE]);
    }

    expect(app(PublicCatalogue::class)->page()['products'][0]['imageUrl'])->toBeNull();
})->with(['personal', 'private disk']);

it('preserves any live source and sees changed access on a second read in the same request', function (): void {
    $product = publicProduct();
    DB::table('commerce_sellables')->where('id', $product->getKey())->update(['entitlement_name' => 'course.board']);
    Fx::declareReaders();
    $reader = Fx::reader();
    Fx::signIn($reader);
    Fx::plant($this->site, (string) $reader->getKey(), 'course.board', 'test.revoked', revoked: CarbonImmutable::now('UTC'));
    Fx::plant($this->site, (string) $reader->getKey(), 'course.board', 'test.lapsed', expires: CarbonImmutable::now('UTC')->subSecond());
    $catalogue = app(PublicCatalogue::class);
    expect($catalogue->page()['products'][0]['held'])->toBeFalse();
    $live = Fx::plant($this->site, (string) $reader->getKey(), 'course.board', 'test.live');
    expect($catalogue->page()['products'][0]['held'])->toBeTrue();
    DB::table('entitlements')->where('id', $live)->update(['revoked_at' => now('UTC')->format('Y-m-d H:i:s')]);
    expect($catalogue->page()['products'][0]['held'])->toBeFalse();
});

it('maps a reader database failure to unavailable rather than an absent access badge', function (): void {
    $product = publicProduct();
    DB::table('commerce_sellables')->where('id', $product->getKey())->update(['entitlement_name' => 'course.board']);
    Fx::declareReaders(MissingTableReader::class);
    $guard = Auth::guard(Fx::GUARD);
    session()->put($guard->getName(), '987654321');
    Fx::forget();

    expect(fn () => DB::transaction(fn () => app(PublicCatalogue::class)->page()))->toThrow(EntitlementUnavailable::class);
});

it('keeps native whole catalogue query count constant from one to twenty four products and checks entitlements once', function (): void {
    $permalink = FieldStorage::create(['org_id' => $this->org->getKey(), 'handle' => 'public_permalink', 'type' => 'slug', 'pii_class' => 'none']);
    Field::create(['entry_type_id' => $this->type->getKey(), 'field_storage_id' => $permalink->getKey(), 'label' => 'Permalink']);
    Fx::declareReaders();
    $reader = Fx::reader();
    Fx::signIn($reader);
    $first = publicProduct('SKU-01');
    DB::table('commerce_sellables')->where('id', $first->getKey())->update(['entitlement_name' => 'course.shared']);
    Fx::plant($this->site, (string) $reader->getKey(), 'course.shared');
    app(PublicCatalogue::class)->page();
    $one = catalogueQueries(fn () => app(PublicCatalogue::class)->page());

    foreach (range(2, 24) as $number) {
        $product = publicProduct(sprintf('SKU-%02d', $number));
        DB::table('commerce_sellables')->where('id', $product->getKey())->update(['entitlement_name' => 'course.shared']);
    }

    $many = catalogueQueries(fn () => app(PublicCatalogue::class)->page());
    $entitlementQueries = array_values(array_filter($many, static fn (array $query): bool => str_contains($query['sql'], 'from "entitlements"') || str_contains($query['sql'], 'from `entitlements`')));
    expect(count($many))->toBe(count($one))->toBeLessThanOrEqual(20)
        ->and($entitlementQueries)->toHaveCount(1)->and($entitlementQueries[0]['bindings'])->toContain('course.shared')
        ->and(app(PublicCatalogue::class)->page()['products'])->toHaveCount(24);
});
