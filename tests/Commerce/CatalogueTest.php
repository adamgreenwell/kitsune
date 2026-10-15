<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Catalogue\CatalogueWriter;
use Kitsune\Commerce\Catalogue\OrgCurrency;
use Kitsune\Commerce\Models\Listing;
use Kitsune\Commerce\Models\Sellable;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Attributes\SiteScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture;
use Kitsune\Core\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    config(['auth.providers.users.model' => TestUser::class]);
    $this->org = Org::create(['slug' => 'catalogue', 'name' => 'Catalogue', 'currency' => 'USD']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'catalogue-main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);
    $this->type = EntryType::create(['handle' => 'product', 'name' => 'Product', 'plural_name' => 'Products']);
    $this->entry = Entry::create(['entry_type_id' => $this->type->getKey(), 'slug' => 'board', 'title' => 'A board']);
    Module::create(['handle' => 'kitsune/commerce', 'version' => 'dev-main', 'is_enabled' => true, 'installed_at' => now()]);
    $this->writer = app(CatalogueWriter::class);
});

afterEach(function (): void {
    Auth::guard('web')->logout();
    app(Context::class)->forget();
    Permissions::forget();
});

/** @return list<string> */
function catalogueAuditActions(): array
{
    return AuditLog::query()->where('action', 'like', 'commerce.%')->orderBy('id')->pluck('action')->all();
}

it('declares and enforces both model scopes', function (): void {
    expect((new ReflectionClass(Sellable::class))->getAttributes(OrgScoped::class))->toHaveCount(1)
        ->and((new ReflectionClass(Listing::class))->getAttributes(SiteScoped::class))->toHaveCount(1)
        ->and(class_uses_recursive(Sellable::class))->toContain(EnforcesScope::class)
        ->and(class_uses_recursive(Listing::class))->toContain(EnforcesScope::class);
});

it('stores exact prices and finite or untracked stock without a direct content reference', function (): void {
    $row = $this->writer->createSellable('BOARD-01', '9223372036854775807', '9223372036854775807', 'USD')->fresh();

    expect($row->price_minor)->toBe('9223372036854775807')->and($row->stock)->toBe('9223372036854775807')
        ->and($row->currency)->toBe('USD')->and(array_key_exists('entry_id', $row->getAttributes()))->toBeFalse();

    $row = $this->writer->updateSellable($row->getKey(), 'BOARD-01', 0, $row->stock, 'USD')->fresh();
    expect($row->price_minor)->toBe('0')->and($row->stock)->toBe('9223372036854775807')
        ->and(catalogueAuditActions())->toBe(['commerce.sellable_created', 'commerce.sellable_updated']);
});

it('shares one price and stock balance while each site has its own entry and availability', function (): void {
    $sellable = $this->writer->createSellable('BOARD', 12500, 8, 'USD');
    $main = $this->writer->createListing($sellable->getKey(), $this->entry->getKey());
    $other = Site::create(['handle' => 'fr', 'slug' => 'catalogue-fr', 'name' => 'French', 'locale' => 'fr']);
    app(Context::class)->setSite($other);
    $entry = Entry::create(['entry_type_id' => $this->type->getKey(), 'slug' => 'planche', 'title' => 'Une planche']);
    $french = $this->writer->createListing($sellable->getKey(), $entry->getKey(), true);

    expect(Listing::query()->pluck('id')->all())->toBe([$french->getKey()])
        ->and($french->entry->title)->toBe('Une planche')->and($french->sellable->stock)->toBe('8');

    $this->writer->updateSellable($sellable->getKey(), 'BOARD', 13000, 8, 'USD');
    app(Context::class)->setSite($this->site);
    $main = $main->fresh();
    expect($main->is_available)->toBeFalse()->and($main->entry->title)->toBe('A board')
        ->and($main->sellable->price_minor)->toBe('13000')->and($main->sellable->stock)->toBe('8');
});

it('accepts checked direct evented creates and updates', function (): void {
    $sellable = Sellable::create(['sku' => 'DIRECT', 'price_minor' => 10, 'stock' => null]);
    $sellable->update(['price_minor' => '20']);
    $listing = Listing::create(['sellable_id' => $sellable->getKey(), 'entry_id' => $this->entry->getKey()]);
    $listing->update(['is_available' => true]);

    expect($sellable->fresh()->price_minor)->toBe('20')->and($sellable->fresh()->stock)->toBeNull()
        ->and($listing->fresh()->is_available)->toBeTrue()->and(catalogueAuditActions())->toBe([]);
});

it('refuses noninteger price or stock at writer and direct model doors', function (mixed $invalid): void {
    $sellable = $this->writer->createSellable('KEPT', 10, 5, 'USD');
    $before = DB::table('commerce_sellables')->get()->all();
    $actions = catalogueAuditActions();

    foreach (['price_minor', 'stock'] as $column) {
        foreach ([
            fn () => $this->writer->updateSellable($sellable->getKey(), 'KEPT', $column === 'price_minor' ? $invalid : 10, $column === 'stock' ? $invalid : 5, 'USD'),
            fn () => Sellable::create(['sku' => 'BAD', 'price_minor' => $column === 'price_minor' ? $invalid : 10, 'stock' => $column === 'stock' ? $invalid : 5]),
            function () use ($sellable, $column, $invalid): void {
                $row = $sellable->fresh();
                $row->setAttribute($column, $invalid);
                $row->save();
            },
        ] as $write) {
            expect($write)->toThrow(InvalidArgumentException::class);
        }
    }

    expect(DB::table('commerce_sellables')->get()->all())->toEqual($before)->and(catalogueAuditActions())->toBe($actions);
})->with([
    'float' => 1.5, 'whole float' => 1.0, 'true' => true, 'false' => false, 'negative int' => -1,
    'negative string' => '-1', 'fraction string' => '1.5', 'scientific' => '1e2', 'leading zero' => '01',
    'whitespace' => ' 1', 'plus' => '+1', 'overflow' => '9223372036854775808', 'array' => [[]],
]);

it('refuses null price while null stock remains untracked', function (): void {
    expect(fn () => $this->writer->createSellable('NO-PRICE', null, null, 'USD'))->toThrow(InvalidArgumentException::class)
        ->and($this->writer->createSellable('UNTRACKED', 0, null, 'USD')->stock)->toBeNull();
});

it('refuses a float assigned after the model guards by a host observer', function (): void {
    $sellable = $this->writer->createSellable('KEPT', 10, null, 'USD');
    Sellable::updating(static fn (Sellable $row) => $row->price_minor = 11.0);

    expect(fn () => $this->writer->updateSellable($sellable->getKey(), 'KEPT', 11, null, 'USD'))->toThrow(InvalidArgumentException::class)
        ->and($sellable->fresh()->price_minor)->toBe('10')->and(catalogueAuditActions())->toBe(['commerce.sellable_created']);
});

it('refuses every detached bulk and quiet write even inside the scope escape hatch', function (bool $hatch): void {
    $sellable = $this->writer->createSellable('KEPT', 10, 5, 'USD');
    $listing = $this->writer->createListing($sellable->getKey(), $this->entry->getKey());
    $before = [DB::table('commerce_sellables')->get()->all(), DB::table('commerce_listings')->get()->all()];

    foreach ([Sellable::class => ['price_minor', 'sku'], Listing::class => ['is_available', 'entry_id']] as $class => [$column, $unique]) {
        $row = $class === Sellable::class
            ? ['org_id' => $this->org->getKey(), 'currency' => 'USD', 'sku' => 'BULK', 'price_minor' => 1.5, 'stock' => null]
            : ['org_id' => $this->org->getKey(), 'site_id' => $this->site->getKey(), 'sellable_id' => $sellable->getKey(), 'entry_id' => $this->entry->getKey(), 'is_available' => true];
        $writes = [
            fn ($q) => $q->update([$column => 1.5]), fn ($q) => $q->insert($row), fn ($q) => $q->insertGetId($row),
            fn ($q) => $q->insertOrIgnore($row), fn ($q) => $q->insertOrIgnoreReturning($row),
            fn ($q) => $q->insertUsing(['org_id'], $class::query()->select('org_id')),
            fn ($q) => $q->insertOrIgnoreUsing(['org_id'], $class::query()->select('org_id')),
            fn ($q) => $q->upsert([$row], [$unique]), fn ($q) => $q->updateOrInsert(['id' => 0], $row),
            fn ($q) => $q->increment($column, 0.5), fn ($q) => $q->decrement($column, 0.5),
            fn ($q) => $q->incrementEach([$column => 1]), fn ($q) => $q->decrementEach([$column => 1]),
            fn ($q) => $q->delete(), fn ($q) => $q->forceDelete(), fn ($q) => $q->truncate(),
            fn ($q) => $q->touch(), fn ($q) => $q->updateFrom([$column => 1]),
        ];

        foreach ($writes as $write) {
            try {
                $hatch ? $class::withoutScopeBecause('a test checks the invariant inside the hatch', $write) : $write($class::query());
                $this->fail('A detached write was accepted.');
            } catch (RuntimeException $e) {
                expect($e)->not->toBeInstanceOf(QueryException::class);
            }
        }

        $loaded = $class::query()->firstOrFail();
        $loaded->setAttribute($column, $class === Sellable::class ? 11 : true);
        $quiet = fn () => $loaded->saveQuietly();
        expect(fn () => $hatch ? $class::withoutScopeBecause('quiet saves also retain their guard inside the hatch', $quiet) : $quiet())
            ->toThrow(RuntimeException::class);
    }

    expect([DB::table('commerce_sellables')->get()->all(), DB::table('commerce_listings')->get()->all()])->toEqual($before);
})->with([false, true]);

it('uses an org-unique canonical SKU and lets another org use the same spelling', function (): void {
    $this->writer->createSellable('BOARD._-09', 10, null, 'USD');
    expect(fn () => $this->writer->createSellable('BOARD._-09', 20, null, 'USD'))->toThrow(QueryException::class);
    $other = Org::create(['slug' => 'rival', 'name' => 'Rival', 'currency' => 'EUR']);
    app(Context::class)->setOrg($other);
    expect($this->writer->createSellable('BOARD._-09', 20, null, 'EUR')->currency)->toBe('EUR');
});

it('refuses noncanonical SKU spelling', function (mixed $sku): void {
    expect(fn () => $this->writer->createSellable($sku, 10, null, 'USD'))->toThrow(RuntimeException::class)
        ->and(DB::table('commerce_sellables')->count())->toBe(0);
})->with(['lowercase' => 'board', 'space' => 'BOARD ', 'leading punctuation' => '.BOARD', 'unicode' => 'BÖARD', 'empty' => '', 'too long' => str_repeat('A', 65), 'number' => 123]);

it('rejects sibling-site and shared content references without changing either side', function (): void {
    $sellable = $this->writer->createSellable('BOARD', 10, null, 'USD');
    $other = Site::create(['handle' => 'other', 'slug' => 'catalogue-other', 'name' => 'Other', 'locale' => 'en']);
    app(Context::class)->setSite($other);
    $foreign = Entry::create(['entry_type_id' => $this->type->getKey(), 'slug' => 'other', 'title' => 'Other']);
    $shared = Entry::create(['entry_type_id' => $this->type->getKey(), 'site_id' => null, 'title' => 'Shared']);
    app(Context::class)->setSite($this->site);

    foreach ([$foreign, $shared] as $entry) {
        expect(fn () => $this->writer->createListing($sellable->getKey(), $entry->getKey()))->toThrow(RuntimeException::class);
    }

    expect(DB::table('commerce_listings')->count())->toBe(0);
});

it('refuses another orgs sellables entries sites and loaded models', function (): void {
    $sellable = $this->writer->createSellable('OWN', 10, null, 'USD');
    $other = Org::create(['slug' => 'rival', 'name' => 'Rival', 'currency' => 'EUR']);
    app(Context::class)->setOrg($other);
    $site = Site::create(['handle' => 'main', 'slug' => 'rival-main', 'name' => 'Rival', 'locale' => 'en']);
    app(Context::class)->setSite($site);
    $type = EntryType::create(['handle' => 'product', 'name' => 'Product', 'plural_name' => 'Products']);
    $entry = Entry::create(['entry_type_id' => $type->getKey(), 'slug' => 'rival', 'title' => 'Rival']);
    $rival = $this->writer->createSellable('RIVAL', 20, null, 'EUR');
    app(Context::class)->setSite($this->site);

    expect(Sellable::query()->pluck('id')->all())->toBe([$sellable->getKey()])
        ->and(fn () => $this->writer->createListing($rival->getKey(), $this->entry->getKey()))->toThrow(RuntimeException::class)
        ->and(fn () => $this->writer->createListing($sellable->getKey(), $entry->getKey()))->toThrow(RuntimeException::class)
        ->and(fn () => Listing::create(['site_id' => $site->getKey(), 'sellable_id' => $sellable->getKey(), 'entry_id' => $this->entry->getKey()]))->toThrow(RuntimeException::class);

    $rival->price_minor = 30;
    expect(fn () => $rival->save())->toThrow(RuntimeException::class)->and($rival->getConnection()->table('commerce_sellables')->where('id', $rival->getKey())->value('price_minor'))->toBe(20);
});

it('hides planted inconsistent listing references and shared entries from ordinary reads', function (string $fault): void {
    $sellable = $this->writer->createSellable('OWN', 10, null, 'USD');
    $otherSite = Site::create(['handle' => 'other', 'slug' => 'catalogue-other', 'name' => 'Other', 'locale' => 'en']);
    $otherOrg = Org::create(['slug' => 'rival', 'name' => 'Rival', 'currency' => 'EUR']);
    app(Context::class)->setOrg($otherOrg);
    $foreign = $this->writer->createSellable('RIVAL', 20, null, 'EUR');
    app(Context::class)->setSite($this->site);
    $row = ['org_id' => $this->org->getKey(), 'site_id' => $this->site->getKey(), 'sellable_id' => $sellable->getKey(), 'entry_id' => $this->entry->getKey(), 'is_available' => true];

    match ($fault) {
        'org' => $row['org_id'] = $otherOrg->getKey(),
        'sellable' => $row['sellable_id'] = $foreign->getKey(),
        'site' => $row['site_id'] = $otherSite->getKey(),
        'shared entry' => DB::table('entries')->where('id', $this->entry->getKey())->update(['site_id' => null]),
        'moved entry' => DB::table('entries')->where('id', $this->entry->getKey())->update(['site_id' => $otherSite->getKey()]),
        'foreign entry' => DB::table('entries')->where('id', $this->entry->getKey())->update(['org_id' => $otherOrg->getKey()]),
        'coordinated foreign' => $row = array_replace($row, ['org_id' => $otherOrg->getKey(), 'sellable_id' => $foreign->getKey()]),
    };

    if ($fault === 'coordinated foreign') {
        DB::table('entries')->where('id', $this->entry->getKey())->update(['org_id' => $otherOrg->getKey()]);
    }

    DB::table('commerce_listings')->insert($row);

    expect(Listing::query()->count())->toBe(0)
        ->and(Listing::withoutScopeBecause('the coherence fence remains inside the normal scope hatch', fn ($q) => $q->count()))->toBe(0);
})->with(['org', 'sellable', 'site', 'shared entry', 'moved entry', 'foreign entry', 'coordinated foreign']);

it('refuses relinking ownership or primary keys even inside an escape hatch', function (string $column): void {
    $sellable = $this->writer->createSellable('OWN', 10, null, 'USD');
    $listing = $this->writer->createListing($sellable->getKey(), $this->entry->getKey());
    $listing->setAttribute($column, 99999);

    expect(fn () => Listing::withoutScopeBecause('immutable references are independent of scope suspension', fn () => $listing->save()))->toThrow(RuntimeException::class)
        ->and(DB::table('commerce_listings')->where('id', $listing->getRawOriginal('id'))->count())->toBe(1);
})->with(['id', 'org_id', 'site_id', 'sellable_id', 'entry_id']);

it('keeps one listing for a sellable and an entry on each site', function (): void {
    $sellable = $this->writer->createSellable('ONE', 10, null, 'USD');
    $this->writer->createListing($sellable->getKey(), $this->entry->getKey());
    $entry = Entry::create(['entry_type_id' => $this->type->getKey(), 'slug' => 'two', 'title' => 'Two']);
    $second = $this->writer->createSellable('TWO', 20, null, 'USD');

    expect(fn () => $this->writer->createListing($sellable->getKey(), $entry->getKey()))->toThrow(QueryException::class)
        ->and(fn () => $this->writer->createListing($second->getKey(), $this->entry->getKey()))->toThrow(QueryException::class)
        ->and(DB::table('commerce_listings')->count())->toBe(1);
});

it('removes listings explicitly before their sellable and permits removing a trashed contents retained listing', function (): void {
    $sellable = $this->writer->createSellable('ONE', 10, null, 'USD');
    $listing = $this->writer->createListing($sellable->getKey(), $this->entry->getKey());
    expect(fn () => $this->writer->removeSellable($sellable->getKey()))->toThrow(RuntimeException::class);
    $this->entry->delete();
    expect(Listing::query()->count())->toBe(0);
    $this->writer->removeListing($listing->getKey());
    $this->writer->removeSellable($sellable->getKey());

    expect(DB::table('commerce_listings')->count())->toBe(0)->and(DB::table('commerce_sellables')->count())->toBe(0)
        ->and(catalogueAuditActions())->toBe(['commerce.sellable_created', 'commerce.listing_created', 'commerce.listing_removed', 'commerce.sellable_removed']);
});

it('refuses writes without context with disabled commerce and from a signed in nonowner', function (): void {
    $context = app(Context::class);
    $context->forget();
    expect(fn () => $this->writer->createSellable('ONE', 10, null, 'USD'))->toThrow(RuntimeException::class);
    $context->setSite($this->site);
    $module = Module::query()->where('handle', 'kitsune/commerce')->firstOrFail();
    $module->update(['is_enabled' => false]);
    expect(fn () => $this->writer->createSellable('ONE', 10, null, 'USD'))->toThrow(RuntimeException::class);
    $module->update(['is_enabled' => true]);
    CredentialFixture::member(owner: false, email: 'member@kitsune.test');
    expect(fn () => $this->writer->createSellable('ONE', 10, null, 'USD'))->toThrow(RuntimeException::class)
        ->and(DB::table('commerce_sellables')->count())->toBe(0)->and(catalogueAuditActions())->toBe([]);
});

it('records actual changes once and rolls back a cancelled save', function (): void {
    $row = $this->writer->createSellable('ONE', 10, null, 'USD');
    $this->writer->updateSellable($row->getKey(), 'ONE', 10, null, 'USD');
    expect(catalogueAuditActions())->toBe(['commerce.sellable_created']);

    Sellable::updating(static fn () => false);
    expect(fn () => $this->writer->updateSellable($row->getKey(), 'ONE', 11, null, 'USD'))->toThrow(RuntimeException::class)
        ->and($row->fresh()->price_minor)->toBe('10')->and(catalogueAuditActions())->toBe(['commerce.sellable_created']);
});

it('rolls the domain write back when its audit insert fails', function (): void {
    AuditLog::creating(static function (): void {
        throw new RuntimeException('The fixture audit store is unavailable.');
    });

    expect(fn () => $this->writer->createSellable('ONE', 10, null, 'USD'))->toThrow(RuntimeException::class, 'audit store is unavailable')
        ->and(DB::table('commerce_sellables')->count())->toBe(0)->and(catalogueAuditActions())->toBe([]);
});

it('rolls the domain write back when its audit insert is silently cancelled', function (): void {
    AuditLog::creating(static fn () => false);

    expect(fn () => $this->writer->createSellable('ONE', 10, null, 'USD'))->toThrow(RuntimeException::class, 'audit save was cancelled')
        ->and(DB::table('commerce_sellables')->count())->toBe(0)->and(catalogueAuditActions())->toBe([]);
});

it('refuses final reference changes made by a later model observer', function (): void {
    $sellable = $this->writer->createSellable('ONE', 10, null, 'USD');
    $other = Site::create(['handle' => 'other', 'slug' => 'catalogue-other', 'name' => 'Other', 'locale' => 'en']);
    app(Context::class)->setSite($other);
    $foreign = Entry::create(['entry_type_id' => $this->type->getKey(), 'slug' => 'other', 'title' => 'Other']);
    app(Context::class)->setSite($this->site);
    Listing::creating(static fn (Listing $row) => $row->entry_id = $foreign->getKey());

    expect(fn () => $this->writer->createListing($sellable->getKey(), $this->entry->getKey()))->toThrow(RuntimeException::class)
        ->and(DB::table('commerce_listings')->count())->toBe(0)->and(catalogueAuditActions())->toBe(['commerce.sellable_created']);
});

it('cannot lend an instance save proof to a nested detached write', function (): void {
    Sellable::creating(function (Sellable $row): void {
        $row->newQuery()->insert(['org_id' => $this->org->getKey(), 'currency' => 'USD', 'sku' => 'NESTED', 'price_minor' => 1.5]);
    });

    expect(fn () => $this->writer->createSellable('ONE', 10, null, 'USD'))->toThrow(RuntimeException::class)
        ->and(DB::table('commerce_sellables')->count())->toBe(0)->and(catalogueAuditActions())->toBe([]);
});

it('refuses a stale loaded row after its stored ownership changes', function (): void {
    $row = $this->writer->createSellable('ONE', 10, null, 'USD');
    $other = Org::create(['slug' => 'rival', 'name' => 'Rival', 'currency' => 'USD']);
    DB::table('commerce_sellables')->where('id', $row->getKey())->update(['org_id' => $other->getKey()]);
    $row->price_minor = 20;

    expect(fn () => $row->save())->toThrow(RuntimeException::class)
        ->and((string) DB::table('commerce_sellables')->where('id', $row->getKey())->value('price_minor'))->toBe('10');
});

it('refuses coerced reference keys before lookup', function (mixed $key): void {
    $sellable = $this->writer->createSellable('ONE', 10, null, 'USD');

    expect(fn () => $this->writer->createListing($key, $this->entry->getKey()))->toThrow(RuntimeException::class)
        ->and(fn () => $this->writer->createListing($sellable->getKey(), $key))->toThrow(RuntimeException::class)
        ->and(fn () => Listing::create(['sellable_id' => $key, 'entry_id' => $this->entry->getKey()]))->toThrow(RuntimeException::class)
        ->and(DB::table('commerce_listings')->count())->toBe(0);
})->with(['fraction' => 1.5, 'whole float' => 1.0, 'fraction string' => '1.5', 'exponent' => '1e0', 'leading zero' => '01', 'bool' => true, 'null' => null]);

it('keeps linked parents until an owner explicitly removes the listing', function (): void {
    $sellable = $this->writer->createSellable('ONE', 10, null, 'USD');
    $listing = $this->writer->createListing($sellable->getKey(), $this->entry->getKey());

    expect(fn () => $this->entry->forceDelete())->toThrow(QueryException::class)
        ->and(Entry::query()->whereKey($this->entry->getKey())->exists())->toBeTrue();
    $this->writer->removeListing($listing->getKey());
    $this->entry->fresh()->forceDelete();
    expect(Entry::query()->withTrashed()->whereKey($this->entry->getKey())->exists())->toBeFalse();
});

it('refuses a stale currency quote before the first price and accepts an explicitly reviewed current quote', function (): void {
    app(OrgCurrency::class)->set('KWD');
    $before = catalogueAuditActions();

    expect(fn () => $this->writer->createSellable('STALE', 123, null, 'USD'))->toThrow(RuntimeException::class, 'org currency changed')
        ->and(DB::table('commerce_sellables')->count())->toBe(0)->and(catalogueAuditActions())->toBe($before);
    $row = $this->writer->createSellable('CURRENT', 123, null, 'KWD');
    expect($row->currency)->toBe('KWD')->and($row->price_minor)->toBe('123')
        ->and(fn () => $this->writer->updateSellable($row->getKey(), 'CURRENT', 123, null, 'USD'))->toThrow(RuntimeException::class, 'org currency changed')
        ->and($row->fresh()->price_minor)->toBe('123');
});

it('refuses relinking to otherwise valid unused entries and sellables', function (string $column): void {
    $sellable = $this->writer->createSellable('ONE', 10, null, 'USD');
    $second = $this->writer->createSellable('TWO', 20, null, 'USD');
    $entry = Entry::create(['entry_type_id' => $this->type->getKey(), 'slug' => 'two', 'title' => 'Two']);
    $listing = $this->writer->createListing($sellable->getKey(), $this->entry->getKey());
    $listing->setAttribute($column, $column === 'entry_id' ? $entry->getKey() : $second->getKey());

    expect(fn () => $listing->save())->toThrow(RuntimeException::class, 'references cannot be changed')
        ->and((string) DB::table('commerce_listings')->where('id', $listing->getKey())->value($column))
        ->toBe((string) ($column === 'entry_id' ? $this->entry->getKey() : $sellable->getKey()));
})->with(['entry_id', 'sellable_id']);

it('hides a listing if its sites stored org disagrees with the stale context', function (): void {
    $sellable = $this->writer->createSellable('ONE', 10, null, 'USD');
    $this->writer->createListing($sellable->getKey(), $this->entry->getKey());
    $other = Org::create(['slug' => 'rival', 'name' => 'Rival', 'currency' => 'USD']);
    DB::table('sites')->where('id', $this->site->getKey())->update(['org_id' => $other->getKey()]);

    expect(app(Context::class)->orgId())->toBe($this->org->getKey())->and(Listing::query()->count())->toBe(0);
});
