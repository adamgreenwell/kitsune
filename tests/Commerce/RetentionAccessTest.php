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
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Carts\CartOwner;
use Kitsune\Commerce\Orders\OrderAccess;
use Kitsune\Commerce\Orders\OrderClaims;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Commerce\Orders\OrderRefused;
use Kitsune\Commerce\Payments\AccessAdjustments;
use Kitsune\Commerce\Payments\AccessFulfillment;
use Kitsune\Commerce\Payments\CheckoutRefused;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Commerce\Privacy\OrderPrivacy;
use Kitsune\Commerce\Privacy\PrivacyState;
use Kitsune\Commerce\Privacy\RetentionPolicies;
use Kitsune\Commerce\Privacy\RetentionPolicySnapshot;
use Kitsune\Core\Readers\ReaderErasure;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture;
use Kitsune\Core\Tests\Fixtures\OrderFixture;
use Kitsune\Core\Tests\Fixtures\PaidOrderFixture;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;

beforeEach(function (): void {
    EntitlementFixture::boot();
    config(AppServiceProvider::readerConfig());
    config(['hashing.bcrypt.rounds' => 4, 'app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
    CarbonImmutable::setTestNow('2026-10-15 12:00:00 UTC');
    $this->world = ReaderFixture::world();
    DB::table('orgs')->update(['currency' => 'USD']);
    $this->org = $this->world['golfdom'];
    ReaderFixture::mode($this->org, 'sign-in');
    $this->site = $this->world['sites']['golfdom'];
    $this->reader = ReaderFixture::reader($this->org, 'access-privacy@example.test');
    app(Context::class)->setSite($this->site);
    $this->site->update(['base_url' => 'https://retention-access.example.test']);
    $this->owner = CredentialFixture::member(email: 'access-retention-owner@example.test');
    $this->request = OrderFixture::request(app('session.store'));
    $this->policy = app(RetentionPolicies::class)->createAsOwner(['name'], 'Explicit invoice obligation',
        'Bounded billing profile', RetentionPolicySnapshot::CONFIRMATION_DAYS, 1);
    $attempt = PaidOrderFixture::attempt($this->site, stock: null, quantity: 1);
    $key = (string) $this->reader->id;
    $owner = CartOwner::readerKey($key);
    DB::table('commerce_orders')->where('id', $attempt->order_id)->update(['reader_id' => $key, 'owner_key' => $owner,
        'active_cart_key' => hash('sha256', $owner."\0".'1'."\0".'0')]);
    DB::table('commerce_order_lines')->where('order_id', $attempt->order_id)->update(['entitlement_name' => 'course.retention-access',
        'offer_terms' => json_encode(['version' => 1, 'kind' => 'permanent'], JSON_THROW_ON_ERROR)]);
    $attempt = PaidOrderFixture::refresh($attempt);
    app(OrderLocks::class)->run(fn () => app(PaidOrders::class)->confirm(DB::connection(),
        DB::table('commerce_orders')->where('id', $attempt->order_id)->sole(), $attempt, PaidOrderFixture::session($attempt)));
    $this->order = DB::table('commerce_orders')->where('id', $attempt->order_id)->sole();
    app(OrderPrivacy::class)->adoptAsOwner($this->order->reference, $this->policy->version, 'Explicit historical policy adoption', true);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    ReaderFixture::forget();
    EntitlementFixture::tearDown();
});

it('permanently suppresses both fulfillment consumers after erasure while preserving original purchase commitments', function (bool $fulfilled): void {
    if ($fulfilled) {
        app(AccessFulfillment::class)->reconcile($this->order->reference);
        app(AccessAdjustments::class)->reconcile($this->order->reference);
    }
    $manifestHash = DB::table(AccessFulfillment::WORK)->where('order_id', $this->order->id)->value('manifest_hash');
    $payment = (array) DB::table(PaidOrders::RECEIPTS)->where('order_id', $this->order->id)->sole();
    $allocations = DB::table(PaidOrders::ALLOCATIONS)->where('order_id', $this->order->id)->orderBy('id')->get()->toArray();
    app(ReaderErasure::class)->erase((string) $this->reader->id);
    $order = DB::table('commerce_orders')->where('id', $this->order->id)->sole();
    $state = app(PrivacyState::class)->checked(DB::connection(), $order);
    expect($state['subject_erased'])->toBeTrue()
        ->and($state['commitments']['access_manifest_hash'])->toBe($manifestHash)
        ->and(DB::table(AccessFulfillment::WORK)->where('order_id', $order->id)->value('manifest_hash'))->toBe($manifestHash);
    foreach ([AccessFulfillment::class, AccessAdjustments::class] as $consumer) {
        expect(app($consumer)->reconcile($order->reference))->toBe(['state' => 'suppressed', 'category' => 'subject_erased', 'processed' => 0]);
        expect(app($consumer)->reconcile($order->reference)['processed'])->toBe(0);
    }
    expect(DB::table('entitlements')->where('source', 'commerce.order:'.$order->id)->count())->toBe(0)
        ->and((array) DB::table(PaidOrders::RECEIPTS)->where('order_id', $order->id)->sole())->toBe($payment)
        ->and(DB::table(PaidOrders::ALLOCATIONS)->where('order_id', $order->id)->orderBy('id')->get()->toArray())->toEqual($allocations);
    $work = DB::table(AccessFulfillment::WORK)->where('order_id', $order->id)->sole();
    expect($work->claim_binding)->toBeNull()->and($work->claim_reader_id)->toBeNull()->and($work->claim_hash)->toBeNull();
})->with([true, false]);

it('expires invoice fields without withdrawing a living reader permanent purchased source', function (): void {
    app(AccessFulfillment::class)->reconcile($this->order->reference);
    CarbonImmutable::setTestNow('2026-10-17 12:00:00 UTC');
    app(OrderPrivacy::class)->maintainOrder($this->order->reference);
    $order = DB::table('commerce_orders')->where('id', $this->order->id)->sole();
    $state = app(PrivacyState::class)->checked(DB::connection(), $order);
    expect($state['invoice_expired'])->toBeTrue()->and($state['subject_erased'])->toBeFalse()
        ->and($order->billing)->toBeNull()->and($order->contact_email)->toBeNull()
        ->and($order->reader_id)->toBe((string) $this->reader->id)
        ->and(Reader::query()->whereKey($this->reader->id)->exists())->toBeTrue();
    app(AccessAdjustments::class)->reconcile($order->reference);
    expect(app(AccessFulfillment::class)->reconcile($order->reference)['processed'])->toBe(0)
        ->and(DB::table('entitlements')->where('source', 'commerce.order:'.$order->id)->whereNull('revoked_at')->count())->toBe(1);
});

it('refuses old ownership and claim retry for an authenticated replacement account with the same reader key', function (): void {
    $key = (string) $this->reader->id;
    app(ReaderErasure::class)->erase($key);
    DB::table('readers')->insert(['id' => $key, 'org_id' => $this->org->id, 'email' => 'replacement-access@example.test',
        'password' => null, 'email_verified_at' => null, 'created_at' => now(), 'updated_at' => now()]);
    Auth::guard(ReaderFixture::GUARD)->login(Reader::query()->findOrFail($key));
    app(Context::class)->setSite($this->site);
    expect(app(OrderAccess::class)->owned($this->request, $this->order->reference))->toBeNull()
        ->and(app(OrderReaderData::class)->export(DB::connection(), $this->org->id, $key))->toBe(['orders' => []]);
    expect(fn () => app(OrderClaims::class)->claim($this->request, $this->order->reference))->toThrow(OrderRefused::class);
    expect(fn () => app(AccessFulfillment::class)->reconcileReader($this->request, $this->order->reference))->toThrow(CheckoutRefused::class);
    expect(DB::table('commerce_order_claims')->where('order_id', $this->order->id)->count())->toBe(0)
        ->and(DB::table('entitlements')->where('source', 'commerce.order:'.$this->order->id)->count())->toBe(0);
});

it('does not heal a reactivated or contaminated erased source parent', function (string $store, string $column, mixed $value): void {
    app(ReaderErasure::class)->erase((string) $this->reader->id);
    DB::table($store)->where('order_id', $this->order->id)->update([$column => $value]);
    $order = DB::table('commerce_orders')->where('id', $this->order->id)->sole();
    expect(fn () => app(OrderReaderData::class)->record(DB::connection(), $order))->toThrow(RuntimeException::class);
    expect(fn () => app($store === AccessFulfillment::WORK ? AccessFulfillment::class : AccessAdjustments::class)
        ->reconcile($order->reference))->toThrow(CheckoutRefused::class);
    expect(DB::table('entitlements')->where('source', 'commerce.order:'.$order->id)->count())->toBe(0)
        ->and(DB::table($store)->where('order_id', $order->id)->value($column))->toBe($value);
})->with([
    'initial source reactivated' => [AccessFulfillment::WORK, 'state', 'pending'],
    'adjustment source reactivated' => [AccessAdjustments::WORK, 'state', 'pending'],
    'old claim binding reintroduced' => [AccessFulfillment::WORK, 'claim_reader_id', 'old-reader'],
    'old adjustment beneficiary reintroduced' => [AccessAdjustments::WORK, 'beneficiary_hash', str_repeat('a', 64)],
]);
