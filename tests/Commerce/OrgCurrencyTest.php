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
use Kitsune\Commerce\Catalogue\OrgCurrency;
use Kitsune\Commerce\CommerceServiceProvider;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as Fx;
use Kitsune\Core\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    config(['auth.providers.users.model' => TestUser::class]);
    $this->org = Fx::org('acme');
    $this->owner = Fx::member();
    $this->receipt = Module::create([
        'handle' => 'kitsune/commerce', 'version' => 'test', 'is_enabled' => true, 'installed_at' => now(),
    ]);
});

it('selects explicitly, audits one change, and returns the stored org', function (): void {
    $selected = app(OrgCurrency::class)->set('JPY');
    expect($selected->getKey())->toBe($this->org->getKey())
        ->and($selected->currency)->toBe('JPY')
        ->and($this->org->fresh()->currency)->toBe('JPY');

    $audit = AuditLog::query()->where('action', OrgCurrency::CHANGED)->sole();
    expect($audit->actor_id)->toBe((string) $this->owner->getKey())
        ->and($audit->target_id)->toBe((string) $this->org->getKey())
        ->and($audit->target_type)->toBe($this->org->getMorphClass());

    // The context's old model still says null: repeats must reload the row rather than trusting that copy.
    expect(app(OrgCurrency::class)->set('JPY')->currency)->toBe('JPY')
        ->and(AuditLog::query()->where('action', OrgCurrency::CHANGED)->count())->toBe(1);

    expect(app(OrgCurrency::class)->set(null)->currency)->toBeNull()
        ->and(AuditLog::query()->where('action', OrgCurrency::CHANGED)->count())->toBe(2);
});

it('refuses unsupported selections before changing or recording anything', function (mixed $currency): void {
    expect(fn () => app(OrgCurrency::class)->set($currency))->toThrow(RuntimeException::class, 'supported');
    expect($this->org->fresh()->currency)->toBeNull()
        ->and(AuditLog::query()->where('action', OrgCurrency::CHANGED)->count())->toBe(0);
})->with(['unknown' => ['ZZZ'], 'lowercase' => ['usd'], 'number' => [840], 'boolean' => [true]]);

it('refuses a signed-in non-owner of the current org', function (): void {
    Fx::member(owner: false, email: 'editor@kitsune.test');

    expect(fn () => app(OrgCurrency::class)->set('USD'))->toThrow(RuntimeException::class, 'owner');
    expect($this->org->fresh()->currency)->toBeNull()
        ->and(AuditLog::query()->where('action', OrgCurrency::CHANGED)->count())->toBe(0);
});

it('does not apply an owner role from another org', function (): void {
    $other = Org::create(['name' => 'Other', 'slug' => 'other']);
    app(Context::class)->setOrg($other);
    Permissions::forget();

    expect(fn () => app(OrgCurrency::class)->set('USD'))->toThrow(RuntimeException::class, 'owner');
    expect($other->fresh()->currency)->toBeNull()
        ->and($this->org->fresh()->currency)->toBeNull();
});

it('allows the trusted system actor and records that attribution honestly', function (): void {
    Auth::guard('web')->logout();

    expect(app(OrgCurrency::class)->set('USD')->currency)->toBe('USD')
        ->and(AuditLog::query()->where('action', OrgCurrency::CHANGED)->sole()->actor_id)->toBeNull();
});

it('requires an org context and an enabled commerce receipt', function (): void {
    app(Context::class)->forget();
    expect(fn () => app(OrgCurrency::class)->set('USD'))->toThrow(RuntimeException::class, 'organisation');

    app(Context::class)->setOrg($this->org);
    $this->receipt->is_enabled = false;
    $this->receipt->save();
    expect(fn () => app(OrgCurrency::class)->set('USD'))->toThrow(RuntimeException::class, 'Enable commerce');
    expect($this->org->fresh()->currency)->toBeNull();
});

it('refuses a deleted current org rather than restoring or changing it', function (): void {
    $this->org->delete();

    expect(fn () => app(OrgCurrency::class)->set('USD'))->toThrow(RuntimeException::class, 'no longer exists');
    expect(Org::withTrashed()->findOrFail($this->org->getKey())->currency)->toBeNull();
});

it('does not record a cancelled save', function (): void {
    Org::updating(static fn (): bool => false);

    expect(fn () => app(OrgCurrency::class)->set('USD'))->toThrow(RuntimeException::class, 'cancelled');
    expect($this->org->fresh()->currency)->toBeNull()
        ->and(AuditLog::query()->where('action', OrgCurrency::CHANGED)->count())->toBe(0);
});

it('rolls back a supported currency substituted by a later listener', function (): void {
    Org::updating(static function (Org $org): void {
        $org->currency = 'EUR';
    });

    expect(fn () => app(OrgCurrency::class)->set('USD'))->toThrow(RuntimeException::class, 'stored value differs');
    expect($this->org->fresh()->currency)->toBeNull()
        ->and(AuditLog::query()->where('action', OrgCurrency::CHANGED)->count())->toBe(0);
});

it('rolls back a currency selection that cannot be audited', function (): void {
    AuditLog::creating(static function (AuditLog $row): void {
        if ($row->action === OrgCurrency::CHANGED) {
            throw new RuntimeException('fixture audit failure');
        }
    });

    expect(fn () => app(OrgCurrency::class)->set('USD'))->toThrow(RuntimeException::class, 'fixture audit failure');
    expect($this->org->fresh()->currency)->toBeNull();
});

it('rolls back a currency selection when an audit listener cancels the insert', function (): void {
    AuditLog::creating(static fn (AuditLog $row): bool => $row->action !== OrgCurrency::CHANGED);

    expect(fn () => app(OrgCurrency::class)->set('USD'))->toThrow(RuntimeException::class, 'cancelled its audit');
    expect($this->org->fresh()->currency)->toBeNull()
        ->and(AuditLog::query()->where('action', OrgCurrency::CHANGED)->count())->toBe(0);
});

it('holds currency with the real sellable foreign key and no registered commerce provider', function (): void {
    app(OrgCurrency::class)->set('USD');
    $id = DB::table('commerce_sellables')->insertGetId([
        'org_id' => $this->org->getKey(), 'currency' => 'USD', 'sku' => 'SHARED', 'price_minor' => 100, 'stock' => null,
    ]);
    expect(app()->getProviders(CommerceServiceProvider::class))->toBe([]);

    expect(fn () => app(OrgCurrency::class)->set('EUR'))->toThrow(RuntimeException::class, 'hold the organisation currency');
    expect(fn () => app(OrgCurrency::class)->set(null))->toThrow(RuntimeException::class, 'hold the organisation currency');
    expect($this->org->fresh()->currency)->toBe('USD')
        ->and(AuditLog::query()->where('action', OrgCurrency::CHANGED)->count())->toBe(1);

    $this->receipt->is_enabled = false;
    $this->receipt->save();
    $org = $this->org;
    $attempts = [
        static fn () => $org->fresh()->update(['currency' => 'EUR']),
        static fn () => $org->fresh()->updateQuietly(['currency' => 'EUR']),
        static fn () => Org::query()->whereKey($org->getKey())->update(['currency' => 'EUR']),
        static fn () => Org::withoutScopeBecause('retained currency test', static fn ($query) => $query->whereKey($org->getKey())->update(['currency' => 'EUR'])),
        static fn () => Org::query()->whereKey($org->getKey())->increment('id', 0, ['currency' => 'EUR']),
        static fn () => DB::table('orgs')->where('id', $org->getKey())->update(['currency' => 'EUR']),
    ];

    foreach ($attempts as $attempt) {
        // A real engine's failed statement is rolled back to its savepoint before the next assertion.
        expect(fn () => DB::transaction($attempt))->toThrow(QueryException::class);
        expect($this->org->fresh()->currency)->toBe('USD');
    }

    DB::table('commerce_sellables')->where('id', $id)->delete();
    Org::query()->whereKey($this->org->getKey())->update(['currency' => 'EUR']);
    expect($this->org->fresh()->currency)->toBe('EUR');

    // A stale first-product witness cannot refreeze the org to a different currency.
    expect(fn () => DB::transaction(static fn () => DB::table('commerce_sellables')->insert([
        'org_id' => $org->getKey(), 'currency' => 'USD', 'sku' => 'STALE', 'price_minor' => 100, 'stock' => null,
    ])))->toThrow(QueryException::class);
    expect(DB::table('commerce_sellables')->count())->toBe(0);
});
