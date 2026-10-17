<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Money\MinorUnits;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Commerce\Payments\AccessAdjustments;
use Kitsune\Commerce\Payments\AccessFulfillment;
use Kitsune\Commerce\Payments\FinancialState;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Commerce\Privacy\OrderPrivacy;
use Kitsune\Commerce\Privacy\RetentionPolicies;
use Kitsune\Commerce\Privacy\RetentionPolicySnapshot;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\FinancialAccessFixture as FinancialFx;
use Kitsune\Core\Tests\Fixtures\PaidOrderFixture as PaidFx;

beforeEach(function (): void {
    Fx::boot();
    CarbonImmutable::setTestNow('2026-10-15 12:00:00 UTC');
    $this->org = Org::create(['slug' => 'order-presentation', 'name' => 'Order presentation', 'currency' => 'USD']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'order-presentation-main', 'name' => 'Main', 'base_url' => 'https://presentation.example.test']);
    app(Context::class)->setSite($this->site);
    Fx::declareReaders();
    $this->reader = Fx::reader();
    app('view')->addNamespace('commerce', dirname(__DIR__, 2).'/packages/commerce/resources/views');
    app('translator')->addNamespace('commerce', dirname(__DIR__, 2).'/packages/commerce/resources/lang');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Fx::tearDown();
});

/** Actual checked financial/access/privacy projections, without any provider transport. */
function presentationOrder(string $scenario, Site $site, string $reader): array
{
    if ($scenario === 'no offer') {
        $attempt = PaidFx::attempt($site, null, 1);
        DB::table('commerce_order_lines')->where('order_id', $attempt->order_id)->update(['entitlement_name' => null, 'offer_terms' => null]);
        $attempt = PaidFx::refresh($attempt);
        $order = DB::table('commerce_orders')->where('id', $attempt->order_id)->sole();
        app(OrderLocks::class)->run(fn () => app(PaidOrders::class)->confirm(DB::connection(), $order, $attempt, PaidFx::session($attempt)));
    } else {
        $offers = [['days' => $scenario === 'expired' ? 1 : 30, 'quantity' => 1, 'unknown' => $scenario === 'held']];
        $order = FinancialFx::purchase($site, $scenario === 'awaiting claim' ? null : $reader, $offers);
    }
    app(AccessFulfillment::class)->reconcile($order->reference);
    app(AccessAdjustments::class)->reconcile($order->reference);
    if ($scenario === 'expired') {
        CarbonImmutable::setTestNow('2026-10-17 12:00:00 UTC');
    }
    if (in_array($scenario, ['refunded', 'disputed', 'minimized'], true)) {
        CarbonImmutable::setTestNow('2026-10-15 12:00:01 UTC');
        Fx::owner();
    }
    if ($scenario === 'refunded') {
        $line = (int) DB::table('commerce_order_lines')->where('order_id', $order->id)->value('id');
        $reference = app(FinancialState::class)->requestAsOwner($order->reference, [$line => 1], 'Return original delivered unit', bin2hex(random_bytes(16)));
        FinancialFx::observe($order, [FinancialFx::refund($order, $reference, 'succeeded')]);
    }
    if ($scenario === 'disputed') {
        FinancialFx::observe($order, disputes: [FinancialFx::dispute($order, 'needs_response')]);
    }
    app(AccessAdjustments::class)->reconcile($order->reference);
    if ($scenario === 'minimized') {
        $policy = app(RetentionPolicies::class)->createAsOwner(['name'], 'Explicit invoice obligation', 'Selected presentation fixture policy', RetentionPolicySnapshot::CONFIRMATION_DAYS, 30);
        app(OrderPrivacy::class)->adoptAsOwner($order->reference, $policy->version, 'Adopt this explicit historical policy', true);
        $order = DB::table('commerce_orders')->where('id', $order->id)->sole();
        app(OrderLocks::class)->run(function () use ($order, $reader): void {
            Fx::writer()->forget($reader);
            app(OrderPrivacy::class)->eraseForSubject(DB::connection(), $order, $reader);
        });
    }
    $order = DB::table('commerce_orders')->where('id', $order->id)->sole();

    return app(OrderReaderData::class)->record(DB::connection(), $order);
}

/** Presentation only: callers supply an already checked record; routes retain their existing ownership checks. */
function presentationViewData(array $order, string $namespace = 'orders'): array
{
    return ['order' => $order, 'reference' => $order['reference'], 'isGuest' => false, 'claimPath' => null, 'accessRetryPath' => null,
        'money' => MinorUnits::format(...), 't' => static fn (string $key): string => __('commerce::'.$namespace.'.'.$key, [], 'en'),
        'htmlLang' => 'en', 'htmlDir' => 'ltr', 'copyLang' => 'en', 'copyDir' => 'ltr', 'title' => 'Order', 'mainId' => 'orders',
        'siteName' => 'Main', 'homePath' => '/', 'cartPath' => '/cart', 'accountPath' => null, 'review' => null, 'error' => null, 'notice' => null];
}

it('keeps checkout language consistent with checked access and never mutates the purchase while rendering', function (string $scenario, string $expected): void {
    $order = presentationOrder($scenario, $this->site, (string) $this->reader->id);
    $tables = ['commerce_orders', 'commerce_order_lines', PaidOrders::RECEIPTS, AccessFulfillment::WORK, AccessFulfillment::EFFECTS,
        FinancialState::WORK, FinancialState::REFUNDS, AccessAdjustments::WORK, AccessAdjustments::EFFECTS, 'commerce_stock_movements', 'entitlements', 'audit_log'];
    $snapshot = static fn (): array => array_combine($tables, array_map(static fn (string $table): array => DB::table($table)->get()->all(), $tables));
    $before = $snapshot();
    $summary = view('commerce::orders.access-summary', ['order' => $order, 'operator' => true])->render();
    $history = view('commerce::payments.checkout', presentationViewData($order, 'payments'))->render();
    expect($summary)->toContain($expected)
        ->and($history)->toContain('Checkout completion alone does not confirm payment.', 'Purchased access follows the current access check.', 'No real money moves.')
        ->not->toContain('purchased access is not granted yet', 'You can still download your order data.')
        ->and($snapshot())->toEqual($before);
    if (in_array($scenario, ['refunded', 'disputed'], true)) {
        expect($summary)->not->toContain('Pass retained');
    }
    if ($scenario === 'minimized') {
        expect($order['privacy']['subject_erased'])->toBeTrue()->and($order)->not->toHaveKey('reader_id');
    }
})->with([
    ['awarded', 'Pass retained'], ['awaiting claim', 'Waiting for the order to be claimed'], ['held', 'Access needs review'],
    ['expired', 'Original pass term has ended'], ['refunded', 'Refund succeeded'], ['disputed', 'Access is suspended while a formal dispute remains unresolved or lost.'],
    ['no offer', 'The current order access has been checked.'], ['minimized', 'Order access was withdrawn when the account was erased.'],
]);

it('uses the saved order mode for confirmed payment labels without implying that test payments moved real money', function (string $mode): void {
    $order = presentationOrder('awarded', $this->site, (string) $this->reader->id);
    // A view-only mode matrix. No live credential, admission, financial record or provider call is created.
    $order['mode'] = $mode;
    $html = view('commerce::orders.order', presentationViewData($order))->render();
    $list = view('commerce::orders.index', [...presentationViewData($order), 'orders' => [$order], 'page' => 1, 'hasNext' => false])->render();
    expect($list)->toContain($mode === 'live' ? 'Payment confirmed' : 'Test payment confirmed');
    if ($mode === 'live') {
        expect($list)->not->toContain('Test payment confirmed');
    }
    expect($html)->toContain('Payment and refund history is retained separately from account data.')
        ->not->toContain('You can still download your order data.', 'paid test order');
    if ($mode === 'test') {
        expect($html)->toContain('Test payment confirmed', 'No real money has moved.');
    } else {
        expect($html)->toContain('This payment was confirmed in live mode.')
            ->not->toContain('Test payment confirmed', 'No real money has moved.');
    }
})->with(['test', 'live']);

it('keeps an unverified completed checkout distinct from a confirmed payment and avoids a blanket export promise', function (): void {
    $attempt = PaidFx::attempt($this->site, null, 1);
    DB::table('commerce_checkout_attempts')->where('id', $attempt->id)->update(['state' => 'completed', 'active_order_id' => null, 'checkout_url' => null]);
    $raw = DB::table('commerce_orders')->where('id', $attempt->order_id)->sole();
    $order = app(OrderReaderData::class)->record(DB::connection(), $raw);
    $html = view('commerce::orders.order', presentationViewData($order))->render();
    expect($order['payment'])->toBeNull()
        ->and($html)->toContain('Checkout completion alone does not confirm payment.', 'Payment needs checking before this order can be cancelled or erased from this screen.')
        ->not->toContain('Test payment confirmed', 'You can still download your order data.');
});
