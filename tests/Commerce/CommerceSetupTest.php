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
use Illuminate\Support\Facades\Schema;
use Kitsune\Commerce\Catalogue\CatalogueWriter;
use Kitsune\Commerce\Checkout\CommerceSetupWriter;
use Kitsune\Commerce\Models\CommerceSettings;
use Kitsune\Commerce\Models\SalesPolicy;
use Kitsune\Commerce\Models\TaxRate;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Readers;
use Kitsune\Core\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    Readers::boot();
    config(['auth.providers.users.model' => TestUser::class]);
    $this->org = Org::create(['slug' => 'commerce-setup', 'name' => 'Setup', 'currency' => 'USD']);
    app(Context::class)->setOrg($this->org);
    Module::create(['handle' => 'kitsune/commerce', 'version' => 'dev-main', 'is_enabled' => true, 'installed_at' => now()]);
    $this->sellable = app(CatalogueWriter::class)->createSellable('SETUP-COURSE', '2000', null, 'USD');
    $this->writer = app(CommerceSetupWriter::class);
});

afterEach(function (): void {
    Readers::tearDown();
});

/** @return array<string, mixed> */
function commerceSetupSettings(bool $origin = false): array
{
    return [
        'tax_mode' => 'exclusive', 'shipping_tax_mode' => 'inclusive', 'shipping_tax_class' => 'shipping',
        'ship_from_name' => $origin ? 'Private Shipping Origin' : null,
        'ship_from_address' => $origin ? ['line1' => '42 Private Origin Lane', 'city' => 'Honolulu', 'region' => 'HI', 'postal_code' => '96801', 'country' => 'US'] : null,
    ];
}

/** @return array<string, mixed> */
function commerceSetupRate(): array
{
    return ['country' => 'US', 'region' => '*', 'tax_class' => 'standard', 'rate_parts' => 73125];
}

/** @return array<string, mixed> */
function commerceSetupPolicy(bool $physical = false): array
{
    return $physical ? [
        'tax_class' => 'standard', 'delivery_kind' => 'physical', 'weight_grams' => 500, 'length_mm' => 300,
        'width_mm' => 200, 'height_mm' => 100, 'tare_grams' => 0, 'units_per_parcel' => 4,
    ] : ['tax_class' => 'standard', 'delivery_kind' => 'digital'];
}

/** @return list<string> */
function commerceSetupActions(): array
{
    return AuditLog::query()->whereIn('action', [
        'commerce.settings_saved', 'commerce.tax_rate_saved', 'commerce.tax_rate_removed',
        'commerce.sales_policy_saved', 'commerce.sales_policy_removed',
    ])->orderBy('id')->pluck('action')->all();
}

/** Restore listeners without disturbing the models' scope and raw-value guards. */
function commerceSetupObserver(string $class, string $event, Closure $listener, Closure $work): void
{
    $name = 'eloquent.'.$event.': '.$class;
    $events = $class::getEventDispatcher();
    $original = $events->getRawListeners()[$name] ?? [];
    $class::{$event}($listener);

    try {
        $work();
    } finally {
        $events->forget($name);
        foreach ($original as $registered) {
            $events->listen($name, $registered);
        }
    }
}

it('declares and enforces all org setup scopes without requiring a site', function (): void {
    foreach ([CommerceSettings::class, TaxRate::class, SalesPolicy::class] as $class) {
        expect((new ReflectionClass($class))->getAttributes(OrgScoped::class))->toHaveCount(1)
            ->and(class_uses_recursive($class))->toContain(EnforcesScope::class);
    }
    expect(app(Context::class)->siteId())->toBeNull();
    $settings = $this->writer->setSettings(commerceSetupSettings());
    $rate = $this->writer->saveTaxRate(null, commerceSetupRate());
    $policy = $this->writer->setSalesPolicy($this->sellable->getKey(), commerceSetupPolicy());
    foreach ([$settings, $rate, $policy] as $row) {
        expect($row->org_id)->toBe($this->org->getKey())->and($row->version)->toBe(1);
    }
});

it('requires independent explicit tax modes without inventing shipping or zero tax configuration', function (): void {
    $settings = $this->writer->setSettings(commerceSetupSettings());
    expect($settings->tax_mode)->toBe('exclusive')->and($settings->shipping_tax_mode)->toBe('inclusive')
        ->and($settings->ship_from_name)->toBeNull()->and($settings->ship_from_address)->toBeNull()
        ->and(TaxRate::query()->count())->toBe(0)->and(SalesPolicy::query()->count())->toBe(0);
});

it('requires exact current versions even for repeated no-op writes', function (): void {
    $row = $this->writer->setSettings(commerceSetupSettings());
    expect(fn () => $this->writer->setSettings(commerceSetupSettings()))->toThrow(RuntimeException::class, 'current version');
    $same = $this->writer->setSettings(commerceSetupSettings(), $row->version);
    expect($same->version)->toBe(1)->and(commerceSetupActions())->toBe(['commerce.settings_saved']);
    $changed = $this->writer->setSettings([...commerceSetupSettings(), 'tax_mode' => 'inclusive'], '1');
    expect($changed->version)->toBe(2)->and($changed->tax_mode)->toBe('inclusive')
        ->and(fn () => $this->writer->setSettings(commerceSetupSettings(), 1))->toThrow(RuntimeException::class)
        ->and(CommerceSettings::query()->firstOrFail()->version)->toBe(2)
        ->and(commerceSetupActions())->toBe(['commerce.settings_saved', 'commerce.settings_saved']);
});

it('refuses expected versions for an absent row and noncanonical or zero versions', function (mixed $version, string $exception): void {
    expect(fn () => $this->writer->setSettings(commerceSetupSettings(), $version))->toThrow($exception)
        ->and(CommerceSettings::query()->count())->toBe(0);
})->with([
    [1, RuntimeException::class], [0, InvalidArgumentException::class], ['01', InvalidArgumentException::class],
    [1.0, InvalidArgumentException::class], [true, InvalidArgumentException::class], ['4294967296', InvalidArgumentException::class],
]);

it('refuses settings fields that are missing unknown or outside their canonical grammar', function (string $field, mixed $value): void {
    $values = [...commerceSetupSettings(), $field => $value];
    expect(fn () => $this->writer->setSettings($values))->toThrow(InvalidArgumentException::class)
        ->and(CommerceSettings::query()->count())->toBe(0)->and(commerceSetupActions())->toBe([]);
})->with([
    ['tax_mode', null], ['tax_mode', 'Exclusive'], ['tax_mode', true], ['shipping_tax_mode', null], ['shipping_tax_mode', 'inherit'],
    ['shipping_tax_class', 'Standard'], ['shipping_tax_class', '1standard'], ['shipping_tax_class', str_repeat('a', 65)],
    ['ship_from_name', "Private\nOrigin"], ['ship_from_name', str_repeat('x', 201)], ['ship_from_name', 123],
    ['ship_from_address', ['line1' => 'Road', 'country' => 'us']], ['ship_from_address', ['line1' => 'Road', 'country' => 'US', 'extra' => 'private']],
    ['org_id', 99], ['version', 5], ['shippo_token', 'never-store-this'],
]);

it('keeps zero and exact-region tax rules explicit and allows each org its own same rule', function (): void {
    $zero = $this->writer->saveTaxRate(null, [...commerceSetupRate(), 'country' => 'ZZ', 'rate_parts' => 0]);
    $region = $this->writer->saveTaxRate(null, [...commerceSetupRate(), 'region' => 'US-CA']);
    expect($zero->rate_parts)->toBe(0)->and($zero->country)->toBe('ZZ')->and($region->region)->toBe('US-CA');
    expect(fn () => $this->writer->saveTaxRate(null, [...commerceSetupRate(), 'region' => 'US-CA']))->toThrow(QueryException::class);
    $other = Org::create(['slug' => 'setup-rival', 'name' => 'Rival']);
    app(Context::class)->setOrg($other);
    expect($this->writer->saveTaxRate(null, [...commerceSetupRate(), 'region' => 'US-CA'])->org_id)->toBe($other->getKey());
});

it('updates and removes only the reviewed tax rule version', function (): void {
    $rate = $this->writer->saveTaxRate(null, commerceSetupRate());
    $updated = $this->writer->saveTaxRate($rate->getKey(), [...commerceSetupRate(), 'rate_parts' => 0], 1);
    expect($updated->rate_parts)->toBe(0)->and($updated->version)->toBe(2)
        ->and(fn () => $this->writer->removeTaxRate($rate->getKey(), 1))->toThrow(RuntimeException::class)
        ->and(TaxRate::query()->count())->toBe(1);
    $this->writer->removeTaxRate($rate->getKey(), 2);
    expect(TaxRate::query()->count())->toBe(0)->and(commerceSetupActions())->toBe([
        'commerce.tax_rate_saved', 'commerce.tax_rate_saved', 'commerce.tax_rate_removed',
    ]);
});

it('rejects malformed local tax table values before storage', function (string $field, mixed $value): void {
    expect(fn () => $this->writer->saveTaxRate(null, [...commerceSetupRate(), $field => $value]))->toThrow(InvalidArgumentException::class)
        ->and(TaxRate::query()->count())->toBe(0);
})->with([
    ['country', 'us'], ['country', 'USA'], ['country', 'ＵＳ'], ['region', 'ca'], ['region', ' CA'], ['region', str_repeat('A', 33)],
    ['tax_class', 'Standard'], ['rate_parts', -1], ['rate_parts', 1000001], ['rate_parts', 1.0], ['rate_parts', '01'], ['rate_parts', true],
    ['rate_parts', []], ['rate_parts', '1e2'], ['extra', 'unknown'],
]);

it('shares one policy across sites and requires a complete origin before physical delivery', function (): void {
    $policy = $this->writer->setSalesPolicy($this->sellable->getKey(), commerceSetupPolicy());
    $one = Site::create(['handle' => 'one', 'slug' => 'setup-one', 'name' => 'One', 'locale' => 'en']);
    $two = Site::create(['handle' => 'two', 'slug' => 'setup-two', 'name' => 'Two', 'locale' => 'en']);
    app(Context::class)->setSite($one);
    expect(SalesPolicy::query()->firstOrFail()->getKey())->toBe($policy->getKey());
    app(Context::class)->setSite($two);
    expect(SalesPolicy::query()->firstOrFail()->getKey())->toBe($policy->getKey())
        ->and(fn () => $this->writer->setSalesPolicy($this->sellable->getKey(), commerceSetupPolicy(true), 1))->toThrow(RuntimeException::class, 'shipping origin');
    $settings = $this->writer->setSettings(commerceSetupSettings(true));
    $physical = $this->writer->setSalesPolicy($this->sellable->getKey(), commerceSetupPolicy(true), 1);
    expect($physical->version)->toBe(2)->and($physical->weight_grams)->toBe(500)->and($physical->tare_grams)->toBe(0)
        ->and($physical->units_per_parcel)->toBe(4)->and($settings->ship_from_address['line2'])->toBe('')
        ->and(fn () => $this->writer->setSettings(commerceSetupSettings(), 1))->toThrow(RuntimeException::class, 'cannot be cleared');
    $digital = $this->writer->setSalesPolicy($this->sellable->getKey(), commerceSetupPolicy(), 2);
    foreach (SalesPolicy::PACKING_FIELDS as $field) {
        expect($digital->getAttribute($field))->toBeNull();
    }
    expect($this->writer->setSettings(commerceSetupSettings(), 1)->version)->toBe(2);
});

it('rejects missing excessive fractional or nonphysical packing values', function (string $field, mixed $value): void {
    $this->writer->setSettings(commerceSetupSettings(true));
    expect(fn () => $this->writer->setSalesPolicy($this->sellable->getKey(), [...commerceSetupPolicy(true), $field => $value]))->toThrow(InvalidArgumentException::class)
        ->and(SalesPolicy::query()->count())->toBe(0);
})->with([
    ['weight_grams', 0], ['weight_grams', 1000001], ['weight_grams', 1.0], ['length_mm', 0], ['length_mm', 100001],
    ['width_mm', null], ['height_mm', '01'], ['tare_grams', -1], ['tare_grams', 1000001], ['units_per_parcel', 0],
    ['units_per_parcel', 100], ['units_per_parcel', true], ['delivery_kind', 'download'], ['tax_class', 'Standard'], ['extra', 'unknown'],
]);

it('refuses a physical packing payload on a digital policy', function (): void {
    expect(fn () => $this->writer->setSalesPolicy($this->sellable->getKey(), [...commerceSetupPolicy(), 'weight_grams' => 1]))
        ->toThrow(InvalidArgumentException::class);
});

it('retains the configured product until its policy is explicitly removed', function (): void {
    $policy = $this->writer->setSalesPolicy($this->sellable->getKey(), commerceSetupPolicy());
    expect(fn () => app(CatalogueWriter::class)->removeSellable($this->sellable->getKey()))->toThrow(QueryException::class)
        ->and(fn () => $this->writer->removeSalesPolicy($this->sellable->getKey(), 2))->toThrow(RuntimeException::class)
        ->and(SalesPolicy::query()->count())->toBe(1);
    $this->writer->removeSalesPolicy($this->sellable->getKey(), $policy->version);
    app(CatalogueWriter::class)->removeSellable($this->sellable->getKey());
    expect(SalesPolicy::query()->count())->toBe(0)->and(commerceSetupActions())->toBe([
        'commerce.sales_policy_saved', 'commerce.sales_policy_removed',
    ]);
});

it('fences loaded setup rows product references and writer IDs from another org', function (): void {
    $settings = $this->writer->setSettings(commerceSetupSettings());
    $rate = $this->writer->saveTaxRate(null, commerceSetupRate());
    $policy = $this->writer->setSalesPolicy($this->sellable->getKey(), commerceSetupPolicy());
    $other = Org::create(['slug' => 'setup-rival', 'name' => 'Rival']);
    app(Context::class)->setOrg($other);
    foreach ([$settings, $rate, $policy] as $row) {
        expect($row::query()->count())->toBe(0)
            ->and($row::withoutScopeBecause('custom setup coherence survives the ordinary scope hatch', fn ($query) => $query->count()))->toBe(0);
        $row->version = 2;
        expect(fn () => $row->save())->toThrow(RuntimeException::class);
    }
    expect(fn () => $this->writer->saveTaxRate($rate->getKey(), commerceSetupRate(), 1))->toThrow(RuntimeException::class)
        ->and(fn () => $this->writer->removeTaxRate($rate->getKey(), 1))->toThrow(RuntimeException::class)
        ->and(fn () => $this->writer->setSalesPolicy($this->sellable->getKey(), commerceSetupPolicy()))->toThrow(RuntimeException::class)
        ->and(fn () => $this->writer->removeSalesPolicy($this->sellable->getKey(), 1))->toThrow(RuntimeException::class);
});

it('fails closed without context and hides a planted policy for another orgs SKU', function (): void {
    $this->writer->setSalesPolicy($this->sellable->getKey(), commerceSetupPolicy());
    $other = Org::create(['slug' => 'setup-rival', 'name' => 'Rival']);
    DB::table('commerce_sales_policies')->update(['org_id' => $other->getKey()]);
    expect(SalesPolicy::query()->count())->toBe(0);
    app(Context::class)->forget();
    foreach ([CommerceSettings::class, TaxRate::class, SalesPolicy::class] as $class) {
        expect($class::query()->count())->toBe(0);
    }
    expect(fn () => $this->writer->setSettings(commerceSetupSettings()))->toThrow(RuntimeException::class);
});

it('refuses staff nonowners and readers acting as the system while accepting the org owner', function (): void {
    CredentialFixture::member(owner: false, email: 'setup-staff@example.test');
    expect(fn () => $this->writer->setSettings(commerceSetupSettings()))->toThrow(RuntimeException::class, 'owner');
    Auth::guard('web')->logout();
    Readers::declareReaders();
    Readers::signIn(Readers::reader());
    expect(fn () => $this->writer->setSettings(commerceSetupSettings()))->toThrow(RuntimeException::class, 'owner');
    CredentialFixture::member(owner: true, email: 'setup-owner@example.test');
    Permissions::forget();
    expect($this->writer->setSettings(commerceSetupSettings())->version)->toBe(1);
});

it('requires enabled commerce and a live org before writing', function (): void {
    DB::table('modules')->where('handle', 'kitsune/commerce')->update(['is_enabled' => false]);
    expect(fn () => $this->writer->setSettings(commerceSetupSettings()))->toThrow(RuntimeException::class, 'Enable commerce');
    DB::table('modules')->where('handle', 'kitsune/commerce')->update(['is_enabled' => true]);
    $this->org->delete();
    expect(fn () => $this->writer->setSettings(commerceSetupSettings()))->toThrow(RuntimeException::class, 'no longer available');
});

it('refuses raw shape aliases and JSON null instead of silently clearing a physical origin', function (string $field, mixed $value): void {
    $settings = $this->writer->setSettings(commerceSetupSettings(true));
    $settings->setRawAttributes([...$settings->getAttributes(), $field => $value]);
    expect(fn () => $settings->save())->toThrow(InvalidArgumentException::class);
})->with([
    ['shipping_tax_mode', []], ['ship_from_address', 'null'], ['ship_from_address', 'not json'],
    ['ship_from_address', json_encode(['line1' => 'Road', 'country' => 'US', 'region' => 5])],
    ['version', 1.0], ['version', '01'], ['version', 0],
]);

it('refuses immutable ownership product relinking and primary-key changes after original attributes are forged', function (string $column): void {
    $policy = $this->writer->setSalesPolicy($this->sellable->getKey(), commerceSetupPolicy());
    $other = app(CatalogueWriter::class)->createSellable('OTHER', 1, null, 'USD');
    $changed = match ($column) {
        'sellable_id' => $other->getKey(), default => $policy->getAttributes()[$column] + 1,
    };
    $policy->setRawAttributes([...$policy->getAttributes(), $column => $changed], true);
    $policy->version = 2;
    expect(fn () => $policy->save())->toThrow(RuntimeException::class);
})->with(['id', 'org_id', 'sellable_id']);

it('refuses quiet bulk detached and arithmetic writes inside and outside the scope hatch', function (bool $hatch): void {
    $this->writer->setSettings(commerceSetupSettings());
    $this->writer->saveTaxRate(null, commerceSetupRate());
    $this->writer->setSalesPolicy($this->sellable->getKey(), commerceSetupPolicy());
    foreach ([CommerceSettings::class, TaxRate::class, SalesPolicy::class] as $class) {
        foreach ([
            fn ($query) => $query->update(['version' => 2]), fn ($query) => $query->insert([]), fn ($query) => $query->insertGetId([]),
            fn ($query) => $query->upsert([], ['id']), fn ($query) => $query->increment('version'), fn ($query) => $query->delete(),
            fn ($query) => $query->forceDelete(), fn ($query) => $query->truncate(), fn ($query) => $query->touch(),
        ] as $write) {
            expect(fn () => $hatch ? $class::withoutScopeBecause('final calculator guards remain active in the hatch', $write) : $write($class::query()))->toThrow(RuntimeException::class);
        }
        $row = $class::query()->firstOrFail();
        $row->version = 2;
        expect(fn () => $row->saveQuietly())->toThrow(RuntimeException::class)
            ->and(fn () => $row->deleteQuietly())->toThrow(RuntimeException::class);
    }
})->with([false, true]);

it('rejects a valid setting substitution after the creating guard and rolls back the save', function (): void {
    commerceSetupObserver(CommerceSettings::class, 'creating', static function (CommerceSettings $row): void {
        $row->shipping_tax_mode = 'exclusive';
    }, function (): void {
        expect(fn () => $this->writer->setSettings(commerceSetupSettings()))->toThrow(RuntimeException::class);
    });
    expect(CommerceSettings::query()->count())->toBe(0)->and(commerceSetupActions())->toBe([]);
});

it('rejects a post-save raw substitution instead of auditing values the operator did not review', function (): void {
    commerceSetupObserver(CommerceSettings::class, 'created', static function (CommerceSettings $row): void {
        DB::table('commerce_settings')->where('id', $row->getKey())->update(['shipping_tax_mode' => 'exclusive']);
    }, function (): void {
        expect(fn () => $this->writer->setSettings(commerceSetupSettings()))->toThrow(RuntimeException::class, 'differs');
    });
    expect(CommerceSettings::query()->count())->toBe(0)->and(commerceSetupActions())->toBe([]);
});

it('detects a cancelled configuration save', function (): void {
    commerceSetupObserver(TaxRate::class, 'creating', static fn () => false, function (): void {
        expect(fn () => $this->writer->saveTaxRate(null, commerceSetupRate()))->toThrow(RuntimeException::class, 'cancelled');
    });
    expect(TaxRate::query()->count())->toBe(0)->and(commerceSetupActions())->toBe([]);
});

it('rolls back configuration and deletion if audit insertion throws or is cancelled', function (bool $cancel): void {
    $rate = $this->writer->saveTaxRate(null, commerceSetupRate());
    commerceSetupObserver(AuditLog::class, 'creating', static function (AuditLog $row) use ($cancel): ?bool {
        if (in_array($row->action, ['commerce.settings_saved', 'commerce.tax_rate_removed'], true)) {
            if ($cancel) {
                return false;
            }
            throw new RuntimeException('fixture audit failure');
        }

        return null;
    }, function () use ($rate): void {
        expect(fn () => $this->writer->setSettings(commerceSetupSettings()))->toThrow(RuntimeException::class)
            ->and(fn () => $this->writer->removeTaxRate($rate->getKey(), 1))->toThrow(RuntimeException::class);
    });
    expect(CommerceSettings::query()->count())->toBe(0)->and(TaxRate::query()->count())->toBe(1)
        ->and(commerceSetupActions())->toBe(['commerce.tax_rate_saved']);
})->with([false, true]);

it('detects a cancelled policy removal and keeps its product retained', function (): void {
    $this->writer->setSalesPolicy($this->sellable->getKey(), commerceSetupPolicy());
    commerceSetupObserver(SalesPolicy::class, 'deleting', static fn () => false, function (): void {
        expect(fn () => $this->writer->removeSalesPolicy($this->sellable->getKey(), 1))->toThrow(RuntimeException::class, 'cancelled');
    });
    expect(SalesPolicy::query()->count())->toBe(1)->and(commerceSetupActions())->toBe(['commerce.sales_policy_saved']);
});

it('never copies the shipping origin or configuration values into payload-free audit rows', function (): void {
    $this->writer->setSettings(commerceSetupSettings(true));
    $audit = AuditLog::query()->where('action', 'commerce.settings_saved')->sole();
    $encoded = json_encode($audit->getAttributes(), JSON_THROW_ON_ERROR);
    expect($encoded)->not->toContain('Private Shipping Origin')->not->toContain('Private Origin Lane')->not->toContain('96801')
        ->and($audit->actor_id)->toBeNull()->and($audit->target_type)->toBe((new CommerceSettings)->getMorphClass());
});

it('refuses version exhaustion without changing the reviewed configuration', function (): void {
    $settings = $this->writer->setSettings(commerceSetupSettings());
    DB::table('commerce_settings')->where('id', $settings->getKey())->update(['version' => '4294967295']);
    expect(fn () => $this->writer->setSettings([...commerceSetupSettings(), 'tax_mode' => 'inclusive'], '4294967295'))
        ->toThrow(RuntimeException::class, 'exhausted')
        ->and(CommerceSettings::query()->firstOrFail()->tax_mode)->toBe('exclusive');
});

it('refuses retained calculator data before rollback DDL without org or module context', function (string $table): void {
    match ($table) {
        'commerce_settings' => $this->writer->setSettings(commerceSetupSettings()),
        'commerce_tax_rates' => $this->writer->saveTaxRate(null, commerceSetupRate()),
        'commerce_sales_policies' => $this->writer->setSalesPolicy($this->sellable->getKey(), commerceSetupPolicy()),
    };
    app(Context::class)->forget();
    DB::table('modules')->where('handle', 'kitsune/commerce')->update(['is_enabled' => false]);
    $migration = require dirname(__DIR__, 2).'/packages/commerce/database/migrations/0001_01_01_000204_create_commerce_setup.php';
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'retained commerce')
        ->and(DB::table($table)->count())->toBe(1)
        ->and(Schema::hasTable('commerce_settings'))->toBeTrue()->and(Schema::hasTable('commerce_tax_rates'))->toBeTrue()
        ->and(Schema::hasTable('commerce_sales_policies'))->toBeTrue();
})->with(['commerce_settings', 'commerce_tax_rates', 'commerce_sales_policies']);

it('checks each authoritative calculator table before rollback even with an empty replica', function (string $retained): void {
    $name = 'commerce_setup_rollback_probe';
    config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'probe_']]);
    $connection = DB::connection($name);
    $primary = $connection->getPdo();
    $replica = new PDO('sqlite::memory:');
    foreach (['commerce_settings', 'commerce_tax_rates', 'commerce_sales_policies'] as $table) {
        $primary->exec('create table probe_'.$table.' (id integer primary key)');
        $replica->exec('create table probe_'.$table.' (id integer primary key)');
    }
    $primary->exec('insert into probe_'.$retained.' (id) values (1)');
    $connection->setReadPdo($replica);
    $migration = require dirname(__DIR__, 2).'/packages/commerce/database/migrations/0001_01_01_000204_create_commerce_setup.php';
    (new ReflectionProperty($migration, 'connection'))->setValue($migration, $name);
    try {
        expect($connection->transactionLevel())->toBe(0)->and($connection->table($retained)->exists())->toBeFalse()
            ->and(fn () => $migration->down())->toThrow(RuntimeException::class, 'retained commerce')
            ->and($primary->query('select count(*) from probe_'.$retained)->fetchColumn())->toBe(1);
    } finally {
        DB::purge($name);
        config(['database.connections.'.$name => null]);
    }
})->with(['commerce_settings', 'commerce_tax_rates', 'commerce_sales_policies']);
