<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Carts\CartOwner;
use Kitsune\Commerce\Catalogue\CatalogueWriter;
use Kitsune\Commerce\Models\Cart;
use Kitsune\Commerce\Models\CartLine;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Attributes\SiteScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Context;

beforeEach(function (): void {
    $this->org = Org::create(['slug' => 'cart-persistence', 'name' => 'Carts', 'currency' => 'USD']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'cart-persistence-main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);
    $this->type = EntryType::create(['handle' => 'product', 'name' => 'Product', 'plural_name' => 'Products']);
    $this->entry = Entry::create(['entry_type_id' => $this->type->getKey(), 'slug' => 'course', 'title' => 'Course']);
    Module::create(['handle' => 'kitsune/commerce', 'version' => 'dev-main', 'is_enabled' => true, 'installed_at' => now()]);
    $writer = app(CatalogueWriter::class);
    $this->sellable = $writer->createSellable('COURSE', 2000, null, 'USD');
    $this->listing = $writer->createListing($this->sellable->getKey(), $this->entry->getKey(), true);
    $this->cart = Cart::create(cartPersistenceAttributes());
    $this->line = CartLine::create(['cart_id' => $this->cart->getKey(), 'listing_id' => $this->listing->getKey(), 'quantity' => 2]);
});

afterEach(function (): void {
    app(Context::class)->forget();
});

/** @return array<string, mixed> */
function cartPersistenceAttributes(?string $reader = null): array
{
    return [
        'reader_id' => $reader,
        'owner_key' => $reader === null ? CartOwner::anonymousKey(bin2hex(random_bytes(32))) : CartOwner::readerKey($reader),
        'expires_at' => CarbonImmutable::now('UTC')->addDays(30),
    ];
}

it('declares site scopes and stamps exact non-shared cart ownership', function (): void {
    foreach ([Cart::class, CartLine::class] as $class) {
        expect((new ReflectionClass($class))->getAttributes(SiteScoped::class))->toHaveCount(1)
            ->and(class_uses_recursive($class))->toContain(EnforcesScope::class);
    }

    expect($this->cart->org_id)->toBe($this->org->getKey())->and($this->cart->site_id)->toBe($this->site->getKey())
        ->and($this->line->org_id)->toBe($this->org->getKey())->and($this->line->site_id)->toBe($this->site->getKey())
        ->and($this->cart->version)->toBe(0)->and($this->cart->lines()->first()->getKey())->toBe($this->line->getKey());

    expect(fn () => Cart::create([...cartPersistenceAttributes(), 'site_id' => null]))->toThrow(RuntimeException::class);
    expect(fn () => CartLine::create(['cart_id' => $this->cart->getKey(), 'listing_id' => null, 'quantity' => 1, 'site_id' => null]))->toThrow(RuntimeException::class);
});

it('stores reader keys as distinct bytes without case or trailing-space aliasing', function (): void {
    $expected = ['Ab', 'ab', '0e1', '0e2', str_repeat('X', 255)];

    foreach ($expected as $reader) {
        Cart::create(cartPersistenceAttributes($reader));
    }

    // Canonicalizing comparisons sort numeric-looking keys loosely: '0e1' and '0e2' compare equal in PHP.
    // Sort explicitly as bytes, then require the exact same strings regardless of the database's row order.
    $stored = Cart::query()->whereNotNull('reader_id')->pluck('reader_id')->all();
    sort($expected, SORT_STRING);
    sort($stored, SORT_STRING);

    expect($stored)->toBe($expected)
        ->and(Cart::query()->where('reader_id', 'Ab')->count())->toBe(1)
        ->and(Cart::query()->where('reader_id', 'Ab ')->count())->toBe(0)
        ->and(Cart::query()->where('reader_id', '0e1')->count())->toBe(1)
        ->and(Cart::query()->where('reader_id', '0e2')->count())->toBe(1);
});

it('accepts checked cart and line updates within the same timestamp second', function (): void {
    CarbonImmutable::setTestNow($this->cart->created_at);

    try {
        $this->cart->version = 1;
        $this->cart->saveOrFail();
        $this->line->quantity = 3;
        $this->line->saveOrFail();
        expect($this->cart->fresh()->version)->toBe(1)->and($this->line->fresh()->quantity)->toBe(3);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('keeps expiry and activity timestamps in UTC when the application uses another zone', function (): void {
    config(['app.timezone' => 'Pacific/Honolulu']);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));

    try {
        $cart = Cart::create([...cartPersistenceAttributes(), 'expires_at' => CarbonImmutable::parse('2026-11-14 14:00:00', 'Europe/Paris')]);
        $line = CartLine::create(['cart_id' => $cart->getKey(), 'listing_id' => null, 'quantity' => 1]);
        expect($cart->fresh()->expires_at->format('Y-m-d H:i:s P'))->toBe('2026-11-14 13:00:00 +00:00');

        foreach ([$cart, $line] as $row) {
            expect($row->fresh()->created_at->format('Y-m-d H:i:s P'))->toBe('2026-10-14 12:00:00 +00:00')
                ->and($row->fresh()->updated_at->format('Y-m-d H:i:s P'))->toBe('2026-10-14 12:00:00 +00:00');
        }
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('fences loaded carts and lines from sibling sites and other orgs', function (bool $crossOrg): void {
    if ($crossOrg) {
        $other = Org::create(['slug' => 'cart-rival', 'name' => 'Rival', 'currency' => 'USD']);
        app(Context::class)->setOrg($other);
    }

    $site = Site::create(['handle' => 'other', 'slug' => 'cart-other', 'name' => 'Other', 'locale' => 'en']);
    app(Context::class)->setSite($site);

    expect(Cart::query()->count())->toBe(0)->and(CartLine::query()->count())->toBe(0)
        ->and(Cart::withoutScopeBecause('the coherence fence survives the scope hatch', fn ($query) => $query->count()))->toBe(0)
        ->and(CartLine::withoutScopeBecause('the coherence fence survives the scope hatch', fn ($query) => $query->count()))->toBe(0);

    $this->cart->version = 1;
    $this->line->quantity = 3;
    expect(fn () => $this->cart->save())->toThrow(RuntimeException::class)
        ->and(fn () => $this->line->save())->toThrow(RuntimeException::class)
        ->and(fn () => $this->cart->delete())->toThrow(RuntimeException::class)
        ->and(fn () => $this->line->delete())->toThrow(RuntimeException::class)
        ->and(fn () => CartLine::create(['cart_id' => $this->cart->getKey(), 'listing_id' => null, 'quantity' => 1]))->toThrow(RuntimeException::class);
})->with([false, true]);

it('fails closed with no scope or with only an org', function (bool $orgOnly): void {
    app(Context::class)->forget();

    if ($orgOnly) {
        app(Context::class)->setOrg($this->org);
    }

    expect(Cart::query()->count())->toBe(0)->and(CartLine::query()->count())->toBe(0)
        ->and(fn () => $this->cart->delete())->toThrow(RuntimeException::class)
        ->and(fn () => Cart::create(cartPersistenceAttributes()))->toThrow(RuntimeException::class);
})->with([false, true]);

it('hides planted wrong-org and wrong-parent lines without hiding orphan lines', function (string $fault): void {
    $site = Site::create(['handle' => 'other', 'slug' => 'cart-other', 'name' => 'Other', 'locale' => 'en']);
    $org = Org::create(['slug' => 'cart-rival', 'name' => 'Rival', 'currency' => 'USD']);
    $table = $fault === 'cart org' ? 'commerce_carts' : 'commerce_cart_lines';
    $values = match ($fault) {
        'cart org', 'line org' => ['org_id' => $org->getKey()],
        'line site' => ['site_id' => $site->getKey()],
        'listing org' => ['org_id' => $org->getKey()],
        'listing site' => ['site_id' => $site->getKey()],
    };

    if (str_starts_with($fault, 'listing')) {
        DB::table('commerce_listings')->where('id', $this->listing->getKey())->update($values);
    } else {
        DB::table($table)->where('id', $fault === 'cart org' ? $this->cart->getKey() : $this->line->getKey())->update($values);
    }

    expect(CartLine::query()->count())->toBe(0);

    if ($fault === 'cart org') {
        expect(Cart::query()->count())->toBe(0);
    } else {
        $orphan = CartLine::create(['cart_id' => $this->cart->getKey(), 'listing_id' => null, 'quantity' => 1]);
        expect(CartLine::query()->pluck('id')->all())->toBe([$orphan->getKey()]);
    }
})->with(['cart org', 'line org', 'line site', 'listing org', 'listing site']);

it('rejects raw noncanonical cart values before database coercion', function (string $column, mixed $invalid): void {
    $before = DB::table('commerce_carts')->get()->all();
    $cart = $this->cart->fresh();
    $cart->setRawAttributes([...$cart->getAttributes(), $column => $invalid]);

    expect(fn () => $cart->save())->toThrow(RuntimeException::class)
        ->and(DB::table('commerce_carts')->get()->all())->toEqual($before);
})->with([
    ['reader_id', 5], ['reader_id', ''], ['reader_id', 'Ab '], ['reader_id', "a\0b"], ['reader_id', 'Å'], ['reader_id', str_repeat('x', 256)],
    ['owner_key', ''], ['owner_key', str_repeat('A', 64)], ['owner_key', str_repeat('a', 63)], ['owner_key', []],
    ['version', -1], ['version', '01'], ['version', 1.0], ['version', true], ['version', '4294967296'], ['version', []],
    ['expires_at', null], ['expires_at', '2026-02-30 12:00:00'], ['expires_at', '2026-10-14T12:00:00Z'], ['expires_at', "2026-10-14 12:00:00\0"],
]);

it('refuses a reader cart digest that names a different reader', function (): void {
    expect(fn () => Cart::create([...cartPersistenceAttributes('Ab'), 'owner_key' => CartOwner::readerKey('ab')]))->toThrow(RuntimeException::class);
});

it('rejects noncanonical line quantities and references', function (string $column, mixed $invalid): void {
    $before = DB::table('commerce_cart_lines')->get()->all();
    $line = $this->line->fresh();
    $line->setRawAttributes([...$line->getAttributes(), $column => $invalid]);

    expect(fn () => $line->save())->toThrow(RuntimeException::class)
        ->and(DB::table('commerce_cart_lines')->get()->all())->toEqual($before);
})->with([
    ['quantity', 0], ['quantity', 100], ['quantity', -1], ['quantity', 1.0], ['quantity', true], ['quantity', '01'], ['quantity', '1e0'], ['quantity', []],
    ['cart_id', null], ['cart_id', 1.5], ['cart_id', '01'], ['listing_id', 1.5], ['listing_id', '1e0'],
]);

it('refuses quiet and bulk Eloquent mutation even inside the scope hatch', function (bool $hatch): void {
    foreach ([Cart::class, CartLine::class] as $class) {
        $column = $class === Cart::class ? 'version' : 'quantity';
        foreach ([
            fn ($query) => $query->update([$column => 3]), fn ($query) => $query->insert([]),
            fn ($query) => $query->insertGetId([]), fn ($query) => $query->insertOrIgnore([]),
            fn ($query) => $query->upsert([], ['id']), fn ($query) => $query->updateOrInsert(['id' => 0], [$column => 3]),
            fn ($query) => $query->increment($column), fn ($query) => $query->delete(), fn ($query) => $query->forceDelete(),
            fn ($query) => $query->truncate(), fn ($query) => $query->touch(), fn ($query) => $query->updateFrom([$column => 3]),
        ] as $write) {
            expect(fn () => $hatch ? $class::withoutScopeBecause('guarded cart writes remain checked in the hatch', $write) : $write($class::query()))
                ->toThrow(RuntimeException::class);
        }

        $row = $class::query()->firstOrFail();
        $row->setAttribute($column, 3);
        expect(fn () => $row->saveQuietly())->toThrow(RuntimeException::class)
            ->and(fn () => $row->deleteQuietly())->toThrow(RuntimeException::class);
    }
})->with([false, true]);

it('keeps cart and line identity immutable including a changed primary key', function (string $column): void {
    $isLine = in_array($column, ['cart_id', 'listing_id'], true);
    $row = $isLine ? $this->line->fresh() : $this->cart->fresh();
    $row->setRawAttributes([...$row->getAttributes(), $column => match ($column) {
        'reader_id' => 'changed', 'owner_key' => CartOwner::anonymousKey('changed'), 'listing_id' => null,
        default => $row->getAttributes()[$column] + 1,
    }]);

    expect(fn () => $row->save())->toThrow(RuntimeException::class)
        ->and(fn () => $row->delete())->toThrow(RuntimeException::class);
})->with(['id', 'org_id', 'site_id', 'reader_id', 'owner_key', 'cart_id', 'listing_id']);

it('compares a constructed models claimed identity with the authoritative row', function (bool $isLine): void {
    $row = $isLine ? new CartLine : new Cart;
    $stored = $isLine ? $this->line : $this->cart;
    $column = $isLine ? 'listing_id' : 'owner_key';
    $row->setRawAttributes([...$stored->getAttributes(), $column => $isLine ? null : CartOwner::anonymousKey('changed')], true);
    $row->exists = true;
    $row->setAttribute($isLine ? 'quantity' : 'version', 3);

    expect(fn () => $row->save())->toThrow(RuntimeException::class)->and(fn () => $row->delete())->toThrow(RuntimeException::class);
})->with([false, true]);

it('rechecks values and proof after a host creating or updating observer', function (bool $creating, mixed $replacement): void {
    $event = $creating ? 'creating' : 'updating';
    $name = 'eloquent.'.$event.': '.CartLine::class;
    $events = CartLine::getEventDispatcher();
    $original = $events->getRawListeners()[$name] ?? [];
    CartLine::{$event}(static fn (CartLine $line) => $line->setRawAttributes([...$line->getAttributes(), 'quantity' => $replacement]));

    try {
        $line = $creating ? new CartLine(['cart_id' => $this->cart->getKey(), 'listing_id' => null, 'quantity' => 1]) : $this->line->fresh();
        $line->quantity = 3;
        expect(fn () => $line->save())->toThrow(RuntimeException::class);
    } finally {
        $events->forget($name);
        foreach ($original as $listener) {
            $events->listen($name, $listener);
        }
    }

    expect(DB::table('commerce_cart_lines')->where('id', $this->line->getKey())->value('quantity'))->toBe(2);
})->with([[false, '1e0'], [true, '1e0'], [false, 4], [true, 4]]);

it('rechecks ownership after a host deleting observer and clears proof after a cancelled delete', function (): void {
    $name = 'eloquent.deleting: '.CartLine::class;
    $events = CartLine::getEventDispatcher();
    $original = $events->getRawListeners()[$name] ?? [];
    CartLine::deleting(static fn (CartLine $line) => $line->setRawAttributes([...$line->getAttributes(), 'id' => $line->getKey() + 1]));

    try {
        expect(fn () => $this->line->delete())->toThrow(RuntimeException::class);
    } finally {
        $events->forget($name);
        foreach ($original as $listener) {
            $events->listen($name, $listener);
        }
    }

    expect(fn () => $this->line->fresh()->deleteQuietly())->toThrow(RuntimeException::class)
        ->and(CartLine::query()->count())->toBe(1);
});

it('retains removable orphan lines on listing deletion and cascades them only with their cart', function (): void {
    app(CatalogueWriter::class)->removeListing($this->listing->getKey());
    $line = $this->line->fresh();
    expect($line->listing_id)->toBeNull()->and($line->listing)->toBeNull()->and(CartLine::query()->count())->toBe(1);
    $line->deleteOrFail();
    expect(CartLine::query()->count())->toBe(0);
    CartLine::create(['cart_id' => $this->cart->getKey(), 'listing_id' => null, 'quantity' => 1]);
    CartLine::create(['cart_id' => $this->cart->getKey(), 'listing_id' => null, 'quantity' => 2]);
    $this->cart->deleteOrFail();
    expect(Cart::query()->count())->toBe(0)->and(CartLine::query()->count())->toBe(0);
});

it('enforces actual database ownership retention and one listing per cart', function (): void {
    expect(fn () => Cart::create([...cartPersistenceAttributes(), 'owner_key' => $this->cart->owner_key]))->toThrow(QueryException::class)
        ->and(fn () => CartLine::create(['cart_id' => $this->cart->getKey(), 'listing_id' => $this->listing->getKey(), 'quantity' => 1]))->toThrow(QueryException::class);

    // No products or other commerce references can make these two ownership checks pass by accident.
    $org = Org::create(['slug' => 'cart-only', 'name' => 'Cart only', 'currency' => 'USD']);
    app(Context::class)->setOrg($org);
    $site = Site::create(['handle' => 'cart-only', 'slug' => 'cart-only', 'name' => 'Cart only', 'locale' => 'en']);
    app(Context::class)->setSite($site);
    Cart::create(cartPersistenceAttributes());

    // Each failed raw constraint has its own savepoint so PostgreSQL can exercise the second FK independently.
    expect(fn () => DB::transaction(fn () => DB::table('sites')->where('id', $site->getKey())->delete()))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('orgs')->where('id', $org->getKey())->delete()))->toThrow(QueryException::class);
});

it('refuses destructive cart rollback without org or module context', function (): void {
    app(Context::class)->forget();
    DB::table('modules')->where('handle', 'kitsune/commerce')->update(['is_enabled' => false]);
    $migration = require dirname(__DIR__, 2).'/packages/commerce/database/migrations/0001_01_01_000203_create_commerce_carts.php';

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Remove every retained cart and cart line explicitly')
        ->and(DB::table('commerce_carts')->count())->toBe(1)->and(DB::table('commerce_cart_lines')->count())->toBe(1);
});

it('checks primary cart retention even when a lagging read connection is empty', function (string $table): void {
    $name = 'cart_retention_rollback_probe';
    config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'probe_']]);
    $connection = DB::connection($name);
    $primary = $connection->getPdo();
    $replica = new PDO('sqlite::memory:');

    foreach (['commerce_carts', 'commerce_cart_lines'] as $probeTable) {
        $primary->exec('create table probe_'.$probeTable.' (id integer primary key)');
        $replica->exec('create table probe_'.$probeTable.' (id integer primary key)');
    }

    $primary->exec('insert into probe_'.$table.' (id) values (1)');
    $connection->setReadPdo($replica);
    $migration = require dirname(__DIR__, 2).'/packages/commerce/database/migrations/0001_01_01_000203_create_commerce_carts.php';
    (new ReflectionProperty($migration, 'connection'))->setValue($migration, $name);

    try {
        expect($connection->transactionLevel())->toBe(0)
            ->and($connection->table($table)->exists())->toBeFalse()
            ->and($connection->table($table)->useWritePdo()->exists())->toBeTrue()
            ->and(fn () => $migration->down())->toThrow(RuntimeException::class, 'Remove every retained cart and cart line explicitly')
            ->and($primary->query('select count(*) from probe_'.$table)->fetchColumn())->toBe(1);
    } finally {
        DB::purge($name);
        config(['database.connections.'.$name => null]);
    }
})->with(['commerce_carts', 'commerce_cart_lines']);
