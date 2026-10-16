<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use App\Models\Reader;
use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Carts\CartOwner;
use Kitsune\Commerce\Models\Cart;
use Kitsune\Commerce\Models\CartLine;
use Kitsune\Commerce\Orders\OrderAccess;
use Kitsune\Commerce\Orders\OrderClaims;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Commerce\Payments\AccessAdjustments;
use Kitsune\Commerce\Payments\AccessFulfillment;
use Kitsune\Commerce\Payments\CheckoutAttempts;
use Kitsune\Commerce\Payments\CheckoutReview;
use Kitsune\Commerce\Payments\FinancialState;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Commerce\Privacy\OrderPrivacy;
use Kitsune\Commerce\Privacy\PrivacyState;
use Kitsune\Commerce\Privacy\RetentionPolicies;
use Kitsune\Commerce\Privacy\RetentionPolicySnapshot;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Readers\ReaderErasure;
use Kitsune\Core\Readers\ReaderExport;
use Kitsune\Core\Readers\ReaderModuleDataUnavailable;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture;
use Kitsune\Core\Tests\Fixtures\FinancialAccessFixture;
use Kitsune\Core\Tests\Fixtures\OrderFixture;
use Kitsune\Core\Tests\Fixtures\PaidOrderFixture;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;

beforeEach(function (): void {
    EntitlementFixture::boot();
    config(AppServiceProvider::readerConfig());
    config(['hashing.bcrypt.rounds' => 4, 'app.key' => 'base64:'.base64_encode(str_repeat('e', 32))]);
    CarbonImmutable::setTestNow('2026-10-16 12:00:00 UTC');
    $this->world = ReaderFixture::world();
    DB::table('orgs')->update(['currency' => 'USD']);
    $this->org = $this->world['golfdom'];
    ReaderFixture::mode($this->org, 'sign-in');
    foreach ($this->world['sites'] as $site) {
        app(Context::class)->setSite($site);
        $site->update(['base_url' => 'https://'.$site->handle.'.reader-export.example.test']);
    }
    $this->site = $this->world['sites']['golfdom'];
    $this->reader = ReaderFixture::reader($this->org, 'reader-export@example.test');
    $this->other = ReaderFixture::reader($this->org, 'other-export@example.test');
    app(Context::class)->setSite($this->site);
    $this->owner = CredentialFixture::member(email: 'reader-export-owner@example.test');
    $this->policy = app(RetentionPolicies::class)->createAsOwner(['name', 'address.country'], 'Explicit invoice obligation',
        'Selected reader-export fixture profile', RetentionPolicySnapshot::CONFIRMATION_DAYS, 1);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Cookie::flushQueuedCookies();
    ReaderFixture::forget();
    EntitlementFixture::tearDown();
});

it('includes checked paid refund and access histories through the real installed-module reader export', function (string $receipt, string $authority): void {
    $key = (string) $this->reader->id;
    $first = FinancialAccessFixture::purchase($this->site, $key, [['days' => null, 'quantity' => 2]]);
    app(OrderPrivacy::class)->adoptAsOwner($first->reference, $this->policy->version, 'Explicit historical adoption', true);
    app(AccessFulfillment::class)->reconcile($first->reference);
    $line = DB::table('commerce_order_lines')->where('order_id', $first->id)->sole();
    $refund = app(FinancialState::class)->requestAsOwner($first->reference, [$line->id => 1], 'Private operator reason', str_repeat('e', 32));
    FinancialAccessFixture::observe($first, [FinancialAccessFixture::refund($first, $refund, 'pending')],
        [FinancialAccessFixture::dispute($first, 'under_review')]);

    $sibling = FinancialAccessFixture::purchase($this->world['sites']['golfdom-fr'], $key);
    app(OrderPrivacy::class)->adoptAsOwner($sibling->reference, $this->policy->version, 'Explicit sibling adoption', true);
    app(AccessFulfillment::class)->reconcile($sibling->reference);
    $foreign = OrderFixture::plant($this->world['sites']['rival'], $key);
    $unrelated = OrderFixture::plant($this->site, (string) $this->other->id);
    app(Context::class)->setSite($this->site);
    $cart = Cart::create(['reader_id' => $key, 'owner_key' => CartOwner::readerKey($key), 'expires_at' => now()->addDay()]);
    CartLine::create(['cart_id' => $cart->id, 'listing_id' => null, 'quantity' => 1]);
    $attempt = DB::table(CheckoutAttempts::TABLE)->where('order_id', $first->id)->sole();
    $payment = DB::table(PaidOrders::RECEIPTS)->where('order_id', $first->id)->sole();
    DB::table('modules')->where('handle', 'kitsune/commerce')->update($receipt === 'disabled'
        ? ['is_enabled' => false] : ['version' => 'old-export-version']);
    if ($authority === 'system') {
        Auth::guard('web')->logout();
        Permissions::forget();
    }

    $export = app(ReaderExport::class)->for($key);
    $commerce = $export['module_data']['kitsune/commerce'];
    $record = $commerce['orders'][0];
    expect(array_keys($commerce))->toBe(['carts', 'orders'])
        ->and($commerce['carts'])->toHaveCount(1)
        ->and(array_column($commerce['orders'], 'site'))->toBe(['golfdom', 'golfdom-fr'])
        ->and($record['reference'])->toBe($first->reference)
        ->and($record['payment'])->toMatchArray(['amount_minor' => '4200', 'allocation_state' => 'allocated'])
        ->and($record['allocations'][0])->toMatchArray(['awarded' => 2, 'unavailable' => 0])
        // The shared paid fixture confirms the receipt directly; it deliberately retains its original open attempt.
        ->and($record['checkout_attempts'][0])->toMatchArray(['reference' => $attempt->reference, 'state' => 'open', 'total_minor' => '4200'])
        ->and($record['financial_state']['refunds'][0])->toMatchArray(['reference' => $refund, 'origin' => 'operator', 'state' => 'pending',
            'amount_minor' => '2100', 'selection' => [['title' => $line->title, 'quantity' => 1]]])
        ->and(array_column($record['financial_state']['observations'], 'kind'))->toContain('refund', 'dispute')
        ->and($record['access_fulfillment']['effects'][0]['state'])->toBe('granted')
        ->and($record['financial_access']['effects'][0]['state'])->toBe('revoked')
        ->and($record['privacy'])->toMatchArray(['subject_erased' => false, 'invoice_expired' => false, 'policy_version' => $this->policy->version])
        ->and(CheckoutReview::orderHash($record))->toBe($attempt->order_hash);
    $serialized = json_encode($commerce, JSON_THROW_ON_ERROR);
    foreach ([$foreign->reference, $unrelated->reference, $attempt->session_id, $attempt->account_id, $attempt->credential_witness,
        $payment->charge_id, $payment->payment_intent_id, 'Private operator reason', 'owner_key', 'reader_id', 'request_payload',
        'idempotency_key', 'revocation_witness', 'checkout_url'] as $private) {
        expect($serialized)->not->toContain($private);
    }
})->with(['disabled', 'stale'])->with(['owner', 'system']);

it('includes the original unavailable-unit refund obligation without guessing historical access terms', function (): void {
    $key = (string) $this->reader->id;
    $attempt = PaidOrderFixture::attempt($this->site, stock: '2', quantity: 3);
    $order = DB::table('commerce_orders')->where('id', $attempt->order_id)->sole();
    $owner = CartOwner::readerKey($key);
    DB::table('commerce_orders')->where('id', $order->id)->update(['reader_id' => $key, 'owner_key' => $owner,
        'active_cart_key' => hash('sha256', $owner."\0".$order->cart_ref."\0".$order->cart_version)]);
    $attempt = PaidOrderFixture::refresh($attempt);
    app(OrderLocks::class)->run(fn () => app(PaidOrders::class)->confirm(DB::connection(),
        DB::table('commerce_orders')->where('id', $order->id)->sole(), $attempt, PaidOrderFixture::session($attempt)));
    app(AccessFulfillment::class)->reconcile($order->reference);
    DB::table('modules')->where('handle', 'kitsune/commerce')->update(['is_enabled' => false, 'version' => 'old-export-version']);

    $record = app(ReaderExport::class)->for($key)['module_data']['kitsune/commerce']['orders'][0];
    expect($record['payment']['amount_minor'])->toBe('6300')
        ->and($record['allocations'][0])->toMatchArray(['awarded' => 2, 'unavailable' => 1])
        ->and($record['refund'])->toMatchArray(['amount_minor' => '2100', 'owed_minor' => '2100', 'reason' => 'unavailable_items', 'state' => 'owed'])
        ->and($record['refund']['attempts'])->toHaveCount(1)
        ->and($record['lines'][0]['offer_terms'])->toBeNull()
        ->and($record['access_fulfillment'])->toMatchArray(['state' => 'held', 'category' => 'unknown_terms'])
        ->and(DB::table('entitlements')->where('source', 'commerce.order:'.$order->id)->count())->toBe(0);
});

it('exports only current exact guest proof and then the real claimant without preserving old guest authority', function (): void {
    $order = FinancialAccessFixture::purchase($this->site, null, [['days' => null, 'quantity' => 1]]);
    $request = OrderFixture::request(app('session.store'));
    $secret = str_repeat('d', 64);
    OrderFixture::token($order, $secret);
    expect(app(OrderAccess::class)->capture($request, $order->reference, $secret))->toBeTrue()
        ->and(app(OrderAccess::class)->consume($request, $order->reference))->toBeTrue();
    $privacy = app(OrderReaderData::class);
    expect($privacy->exportGuest($request, $order->reference)['payment']['amount_minor'])->toBe('2100')
        ->and(fn () => $privacy->exportGuest(OrderFixture::request(), $order->reference))->toThrow(RuntimeException::class);
    foreach (['golfdom-fr', 'rival'] as $site) {
        app(Context::class)->setSite($this->world['sites'][$site]);
        expect(fn () => $privacy->exportGuest($request, $order->reference))->toThrow(RuntimeException::class);
    }
    app(Context::class)->setSite($this->site);
    app()->instance('request', $request);
    Auth::guard(ReaderFixture::GUARD)->login($this->reader);
    app(Context::class)->setSite($this->site);
    app(OrderClaims::class)->claim($request, $order->reference);
    expect($privacy->exportGuest($request, $order->reference)['access_claim'])->toBe(['claimed_at' => '2026-10-16T12:00:00Z']);
    Auth::guard(ReaderFixture::GUARD)->login($this->other);
    app(Context::class)->setSite($this->site);
    expect(fn () => $privacy->exportGuest($request, $order->reference))->toThrow(RuntimeException::class);
    Auth::guard(ReaderFixture::GUARD)->logoutCurrentDevice();
    app(Context::class)->setSite($this->site);
    expect(fn () => $privacy->exportGuest($request, $order->reference))->toThrow(RuntimeException::class)
        ->and(app(ReaderExport::class)->for((string) $this->reader->id)['module_data']['kitsune/commerce']['orders'][0]['access_claim'])
        ->toBe(['claimed_at' => '2026-10-16T12:00:00Z'])
        ->and(app(ReaderExport::class)->for((string) $this->other->id)['module_data']['kitsune/commerce']['orders'])->toBe([]);
});

it('keeps an expired invoice discoverable only by its living owner without canceling the original permanent pass', function (): void {
    $key = (string) $this->reader->id;
    $order = FinancialAccessFixture::purchase($this->site, $key, [['days' => null, 'quantity' => 1]]);
    app(OrderPrivacy::class)->adoptAsOwner($order->reference, $this->policy->version, 'Explicit invoice adoption', true);
    app(AccessFulfillment::class)->reconcile($order->reference);
    $hash = DB::table(CheckoutAttempts::TABLE)->where('order_id', $order->id)->value('order_hash');
    CarbonImmutable::setTestNow('2026-10-18 12:00:00 UTC');
    app(OrderPrivacy::class)->maintainOrder($order->reference);

    $record = app(ReaderExport::class)->for($key)['module_data']['kitsune/commerce']['orders'][0];
    expect($record['reference'])->toBe($order->reference)->and($record['billing'])->toBeNull()
        ->and($record['contact_email'])->toBeNull()->and($record['delivery'])->toBeNull()
        ->and($record['privacy'])->toMatchArray(['invoice_expired' => true, 'subject_erased' => false])
        ->and(CheckoutReview::orderHash($record))->toBe($hash)
        ->and(DB::table('entitlements')->where('source', 'commerce.order:'.$order->id)->whereNull('revoked_at')->whereNull('expires_at')->count())->toBe(1);
    $request = OrderFixture::request(app('session.store'));
    Auth::guard(ReaderFixture::GUARD)->login($this->reader);
    app(Context::class)->setSite($this->site);
    expect(app(OrderReaderData::class)->exportGuest($request, $order->reference)['privacy']['invoice_expired'])->toBeTrue();
});

it('does not rediscover an erased purchase through a replacement account with the same reader identifier', function (): void {
    $key = (string) $this->reader->id;
    $order = FinancialAccessFixture::purchase($this->site, $key, [['days' => null, 'quantity' => 1]]);
    app(OrderPrivacy::class)->adoptAsOwner($order->reference, $this->policy->version, 'Explicit erasure fixture adoption', true);
    app(AccessFulfillment::class)->reconcile($order->reference);
    expect(app(ReaderErasure::class)->erase($key)['account'])->toBeTrue();
    DB::table('readers')->insert(['id' => $key, 'org_id' => $this->org->id, 'email' => 'replacement-export@example.test',
        'password' => null, 'email_verified_at' => null, 'created_at' => now(), 'updated_at' => now()]);
    $export = app(ReaderExport::class)->for($key);
    expect($export['module_data']['kitsune/commerce'])->toBe(['carts' => [], 'orders' => []])
        ->and($export['entitlements'])->toBe([])
        ->and(DB::table('commerce_orders')->where('id', $order->id)->exists())->toBeTrue()
        ->and(DB::table(PrivacyState::TABLE)->where('order_id', $order->id)->value('subject_erased_at'))->not->toBeNull();
    $request = OrderFixture::request(app('session.store'));
    Auth::guard(ReaderFixture::GUARD)->login(Reader::query()->findOrFail($key));
    app(Context::class)->setSite($this->site);
    expect(fn () => app(OrderReaderData::class)->exportGuest($request, $order->reference))->toThrow(RuntimeException::class)
        ->and(DB::table('entitlements')->where('source', 'commerce.order:'.$order->id)->count())->toBe(0);
});

it('refuses the complete reader export and singular owned export when mandatory purchase custody is missing or corrupted', function (string $corruption): void {
    $key = (string) $this->reader->id;
    $order = FinancialAccessFixture::purchase($this->site, $key);
    match ($corruption) {
        'privacy parent' => DB::table(PrivacyState::TABLE)->where('order_id', $order->id)->delete(),
        'financial parent' => DB::table(FinancialState::WORK)->where('order_id', $order->id)->delete(),
        'access parent' => (function () use ($order): void {
            DB::table(AccessFulfillment::EFFECTS)->where('order_id', $order->id)->delete();
            DB::table(AccessFulfillment::WORK)->where('order_id', $order->id)->delete();
        })(),
        'adjustment parent' => DB::table(AccessAdjustments::WORK)->where('order_id', $order->id)->delete(),
        'foreign allocation' => DB::table(PaidOrders::ALLOCATIONS)->where('order_id', $order->id)->update(['org_id' => $this->world['rival']->id]),
        'rewritten money' => DB::table('commerce_orders')->where('id', $order->id)->update(['confirmed_total_minor' => '1']),
        'malformed terms' => DB::table('commerce_order_lines')->where('order_id', $order->id)->update(['offer_terms' => json_encode(['version' => 1, 'kind' => 'fixed', 'days' => '30'], JSON_THROW_ON_ERROR)]),
    };
    expect(fn () => app(ReaderExport::class)->for($key))->toThrow(ReaderModuleDataUnavailable::class);
    $request = OrderFixture::request(app('session.store'));
    Auth::guard(ReaderFixture::GUARD)->login($this->reader);
    app(Context::class)->setSite($this->site);
    expect(fn () => app(OrderReaderData::class)->exportGuest($request, $order->reference))->toThrow(RuntimeException::class)
        ->and(DB::table('commerce_orders')->where('id', $order->id)->count())->toBe(1);
})->with(['privacy parent', 'financial parent', 'access parent', 'adjustment parent', 'foreign allocation', 'rewritten money', 'malformed terms']);
