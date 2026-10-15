<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Kitsune\Commerce\Carts\CartOwner;
use Kitsune\Commerce\Models\OrderClaim;
use Kitsune\Commerce\Orders\OrderAccess;
use Kitsune\Commerce\Orders\OrderClaims;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Orders\OrderRefused;
use Kitsune\Commerce\Payments\AccessFulfillment;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Readers\ReaderErasure;
use Kitsune\Core\Readers\ReaderExport;
use Kitsune\Core\Readers\ReaderModuleDataUnavailable;
use Kitsune\Core\Readers\ReaderSessions;
use Kitsune\Core\Tenancy\Attributes\SiteScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\OrderFixture as Orders;
use Kitsune\Core\Tests\Fixtures\PaidOrderFixture as PaidFx;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;

beforeEach(function (): void {
    config(AppServiceProvider::readerConfig());
    config(['hashing.bcrypt.rounds' => 4, 'app.key' => 'base64:'.base64_encode(str_repeat('c', 32)), 'cache.default' => 'array',
        'mail.default' => 'array', 'mail.from.address' => 'readers@kitsune.test']);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    Notification::fake();
    Cookie::flushQueuedCookies();
    $this->world = ReaderFixture::world();
    DB::table('orgs')->update(['currency' => 'USD']);
    $this->org = $this->world['golfdom'];
    ReaderFixture::mode($this->org, 'sign-in');
    $this->site = $this->world['sites']['golfdom'];
    $this->reader = ReaderFixture::reader($this->org, 'claimed-account@example.test');
    $this->other = ReaderFixture::reader($this->org, 'other-account@example.test');
    app(Context::class)->setSite($this->site);
    $this->request = Orders::request(app('session.store'));
    $this->owner = CartOwner::current($this->request, true);
    $this->site->base_url = 'https://claim.example.test/golfdom';
    $this->site->save();
    $attempt = PaidFx::attempt($this->site);
    DB::table('commerce_orders')->where('id', $attempt->order_id)->update(['owner_key' => $this->owner->key]);
    app(OrderLocks::class)->run(fn () => app(PaidOrders::class)->confirm(DB::connection(),
        DB::table('commerce_orders')->where('id', $attempt->order_id)->sole(), $attempt, PaidFx::session($attempt)));
    $this->order = DB::table('commerce_orders')->where('id', $attempt->order_id)->sole();
    $this->claims = app(OrderClaims::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Cookie::flushQueuedCookies();
    ReaderFixture::forget();
});

function claimSignIn(object $test, ?object $reader = null): void
{
    Auth::guard(ReaderFixture::GUARD)->login($reader ?? $test->reader);
    app(Context::class)->setSite($test->site);
}

it('claims a paid guest order explicitly to a different-email reader without changing the original invoice or granting inline', function (): void {
    $before = DB::table('commerce_orders')->where('id', $this->order->id)->sole();
    $line = DB::table('commerce_order_lines')->where('order_id', $this->order->id)->sole();
    $secret = str_repeat('a', 64);
    Orders::token($this->order, $secret);
    $this->claims->remember($this->request, $this->order->reference);
    $session = $this->request->session()->getId();
    claimSignIn($this);
    expect($this->request->session()->getId())->not->toBe($session)
        ->and(app(OrderAccess::class)->owned($this->request, $this->order->reference))->toBeNull();
    $claimed = $this->claims->claim($this->request, $this->order->reference);
    $receipt = $this->claims->forOrder(DB::connection(), $this->order);
    expect($claimed->getKey())->toBe($this->order->id)->and($receipt->reader_id)->toBe((string) $this->reader->getKey())
        ->and($receipt->proof_kind)->toBe('browser')->and($receipt->owner_key)->toBe($this->owner->key)
        ->and(DB::table('commerce_orders')->where('id', $this->order->id)->sole())->toEqual($before)
        ->and(DB::table('commerce_order_lines')->where('order_id', $this->order->id)->sole())->toEqual($line)
        ->and(DB::table('commerce_order_access_tokens')->where('order_id', $this->order->id)->count())->toBe(0)
        ->and(DB::table('entitlements')->count())->toBe(0)
        ->and(app(OrderAccess::class)->owned($this->request, $this->order->reference)?->getKey())->toBe($this->order->id);
    $audit = DB::table('audit_log')->where('action', 'commerce.order_claimed')->sole();
    expect($audit->actor_type)->toBeNull()->and($audit->actor_id)->toBeNull()
        ->and($audit->target_type)->toBe(OrderClaim::class)->and((string) $audit->target_id)->toBe((string) $receipt->id);
    $this->claims->claim($this->request, $this->order->reference);
    expect(DB::table(OrderClaims::TABLE)->count())->toBe(1)->and(DB::table('audit_log')->where('action', 'commerce.order_claimed')->count())->toBe(1);
});

it('uses exact consumed email proof after sign-in while withdrawing every old guest proof after claiming', function (): void {
    $request = Orders::request(app('session.store'));
    $request->session()->forget(ReaderSessions::EPHEMERAL);
    Cookie::flushQueuedCookies();
    $secret = str_repeat('b', 64);
    Orders::token($this->order, $secret);
    expect(app(OrderAccess::class)->capture($request, $this->order->reference, $secret))->toBeTrue()
        ->and(app(OrderAccess::class)->consume($request, $this->order->reference))->toBeTrue();
    claimSignIn($this);
    $this->claims->claim($request, $this->order->reference);
    expect($this->claims->forOrder(DB::connection(), $this->order)->proof_kind)->toBe('email');
    Auth::guard(ReaderFixture::GUARD)->logoutCurrentDevice();
    app(Context::class)->setSite($this->site);
    $oldBrowser = Orders::request();
    $oldBrowser->session()->put(ReaderSessions::EPHEMERAL.'.carts.'.$this->site->org_id.'.'.$this->site->getKey(), 'invalid');
    expect(app(OrderAccess::class)->guestProof($oldBrowser, $this->order))->toBeNull()
        ->and(app(OrderAccess::class)->owned($oldBrowser, $this->order->reference))->toBeNull();
    app(OrderAccess::class)->capture($oldBrowser, $this->order->reference, $secret);
    expect(app(OrderAccess::class)->consume($oldBrowser, $this->order->reference))->toBeFalse();
});

it('never attaches a guest order from a matching address or a known reference alone', function (): void {
    DB::table('readers')->where('id', $this->reader->getKey())->update(['email' => $this->order->contact_email]);
    $this->request->session()->forget(ReaderSessions::EPHEMERAL);
    Cookie::flushQueuedCookies();
    claimSignIn($this);
    expect(fn () => $this->claims->claim($this->request, $this->order->reference))->toThrow(OrderRefused::class)
        ->and(DB::table(OrderClaims::TABLE)->count())->toBe(0);
});

it('refuses cached erased changed-password and unbound readers even with exact browser proof', function (string $case): void {
    claimSignIn($this);
    if ($case === 'erased') {
        DB::table('readers')->where('id', $this->reader->getKey())->delete();
    } elseif ($case === 'password') {
        DB::table('readers')->where('id', $this->reader->getKey())->update(['password' => password_hash('changed', PASSWORD_BCRYPT)]);
    } else {
        $this->request->session()->forget(ReaderSessions::BINDING);
    }
    expect(fn () => $this->claims->claim($this->request, $this->order->reference))->toThrow(OrderRefused::class)
        ->and(DB::table(OrderClaims::TABLE)->count())->toBe(0);
})->with(['erased', 'password', 'unbound']);

it('refuses sibling-site and foreign-org claims with a valid source browser and reader', function (string $site): void {
    claimSignIn($this);
    app(Context::class)->setSite($this->world['sites'][$site]);
    expect(fn () => $this->claims->claim($this->request, $this->order->reference))->toThrow(OrderRefused::class)
        ->and(DB::table(OrderClaims::TABLE)->count())->toBe(0);
})->with(['golfdom-fr', 'rival']);

it('never transfers a committed claim to a second reader or accepts the old mail grant', function (): void {
    claimSignIn($this);
    $this->claims->claim($this->request, $this->order->reference);
    claimSignIn($this, $this->other);
    expect(fn () => $this->claims->claim($this->request, $this->order->reference))->toThrow(OrderRefused::class)
        ->and(app(OrderAccess::class)->owned($this->request, $this->order->reference))->toBeNull()
        ->and($this->claims->claimedReader(DB::connection(), $this->order))->toBe((string) $this->reader->getKey());
});

it('rolls back claim token revocation work and audit when a host audit observer corrupts persisted custody', function (string $case): void {
    claimSignIn($this);
    Orders::token($this->order, str_repeat('c', 64));
    AuditLog::created(function (AuditLog $audit) use ($case): void {
        if ($audit->action !== 'commerce.order_claimed') {
            return;
        }
        match ($case) {
            'reader' => DB::table(OrderClaims::TABLE)->where('order_id', $this->order->id)->update(['reader_id' => (string) $this->other->getKey()]),
            'invoice' => DB::table('commerce_orders')->where('id', $this->order->id)->update(['contact_email' => 'rewritten@example.test']),
            'audit' => DB::table('audit_log')->where('id', $audit->getKey())->update(['actor_id' => 'leaked-reader']),
            'work' => DB::table(AccessFulfillment::WORK)->where('order_id', $this->order->id)->update(['replay_count' => 7]),
            'binding' => $this->request->session()->forget(ReaderSessions::BINDING),
        };
    });
    try {
        expect(fn () => $this->claims->claim($this->request, $this->order->reference))->toThrow(OrderRefused::class)
            ->and(DB::table(OrderClaims::TABLE)->count())->toBe(0)
            ->and(DB::table('commerce_order_access_tokens')->where('order_id', $this->order->id)->count())->toBe(1)
            ->and(DB::table('audit_log')->where('action', 'commerce.order_claimed')->count())->toBe(0);
    } finally {
        AuditLog::flushEventListeners();
    }
})->with(['reader', 'invoice', 'audit', 'work', 'binding']);

it('keeps claim custody readonly scoped and inaccessible without a coherent site', function (): void {
    claimSignIn($this);
    $this->claims->claim($this->request, $this->order->reference);
    expect((new ReflectionClass(OrderClaim::class))->getAttributes(SiteScoped::class))->toHaveCount(1)
        ->and(class_uses_recursive(OrderClaim::class))->toContain(EnforcesScope::class);
    $row = OrderClaim::query()->sole();
    expect(fn () => $row->save())->toThrow(RuntimeException::class)->and(fn () => $row->delete())->toThrow(RuntimeException::class)
        ->and(fn () => OrderClaim::query()->update(['reader_id' => 'forged']))->toThrow(RuntimeException::class);
    app(Context::class)->setSite($this->world['sites']['golfdom-fr']);
    expect(OrderClaim::query()->count())->toBe(0);
    app(Context::class)->forget()->setOrg($this->org);
    expect(OrderClaim::query()->count())->toBe(0);
    app(Context::class)->forget();
    expect(OrderClaim::query()->count())->toBe(0);
});

it('retains claims on rollback and validates foreign-parent orphan custody instead of dropping it from export', function (): void {
    claimSignIn($this);
    $this->claims->claim($this->request, $this->order->reference);
    $migration = require __DIR__.'/../../packages/commerce/database/migrations/0001_01_01_000212_create_commerce_order_claims.php';
    expect(fn () => $migration->down())->toThrow(RuntimeException::class)
        ->and($this->claims->forReader(DB::connection(), $this->org->getKey(), (string) $this->reader->getKey()))->toHaveCount(1);
    DB::table(OrderClaims::TABLE)->update(['site_id' => $this->world['sites']['rival']->getKey()]);
    expect(fn () => $this->claims->forReader(DB::connection(), $this->org->getKey(), (string) $this->reader->getKey()))->toThrow(RuntimeException::class);
});

it('includes claimed paid custody in disabled and stale reader exports and blocks account erasure atomically', function (): void {
    claimSignIn($this);
    $this->claims->claim($this->request, $this->order->reference);
    Auth::guard(ReaderFixture::GUARD)->logoutCurrentDevice();
    DB::table('modules')->where('handle', 'kitsune/commerce')->update(['is_enabled' => false, 'version' => '0.0.0-stale']);
    app(Context::class)->setOrg($this->org)->setSite($this->site);
    $export = app(ReaderExport::class)->for((string) $this->reader->getKey());
    expect($export['module_data']['kitsune/commerce']['orders'])->toHaveCount(1)
        ->and($export['module_data']['kitsune/commerce']['orders'][0]['access_claim'])->toBe(['claimed_at' => '2026-10-15T12:00:00Z'])
        ->and(app(ReaderExport::class)->for((string) $this->other->getKey())['module_data']['kitsune/commerce']['orders'])->toBe([])
        ->and(fn () => app(ReaderErasure::class)->erase((string) $this->reader->getKey()))->toThrow(ReaderModuleDataUnavailable::class)
        ->and(DB::table('readers')->where('id', $this->reader->getKey())->exists())->toBeTrue()
        ->and(DB::table(OrderClaims::TABLE)->count())->toBe(1);
});

it('refuses foreign-org and other-connection readers while retaining exact guest proof', function (string $case): void {
    $reader = $case === 'foreign' ? ReaderFixture::reader($this->world['rival'], 'rival-claim@example.test') : $this->reader;
    if ($case === 'connection') {
        config(['database.connections.foreign_claim_reader' => config('database.connections.'.config('database.default'))]);
        $reader->setConnection('foreign_claim_reader');
    }
    claimSignIn($this, $reader);
    expect(fn () => $this->claims->claim($this->request, $this->order->reference))->toThrow(OrderRefused::class)
        ->and(DB::table(OrderClaims::TABLE)->count())->toBe(0);
})->with(['foreign', 'connection']);

it('refuses cached claim actions after module disable version drift or reader sign-in mode is withdrawn', function (string $case): void {
    claimSignIn($this);
    if ($case === 'mode') {
        DB::table('sites')->where('id', $this->site->getKey())->update(['settings' => json_encode(['reader_accounts' => 'off'], JSON_THROW_ON_ERROR)]);
    } else {
        DB::table('modules')->where('handle', 'kitsune/commerce')->update($case === 'disabled' ? ['is_enabled' => false] : ['version' => '0.0.0-stale']);
    }
    expect(fn () => $this->claims->claim($this->request, $this->order->reference))->toThrow(OrderRefused::class)
        ->and(DB::table(OrderClaims::TABLE)->count())->toBe(0);
})->with(['disabled', 'stale', 'mode']);
