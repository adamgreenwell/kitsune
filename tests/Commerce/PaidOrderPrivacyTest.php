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
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Carts\CartOwner;
use Kitsune\Commerce\Filament\OrderAdministration;
use Kitsune\Commerce\Filament\OrderList;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Commerce\Orders\OrderRefused;
use Kitsune\Commerce\Orders\OrderWriter;
use Kitsune\Commerce\Payments\AccessFulfillment;
use Kitsune\Commerce\Payments\CheckoutAttempts;
use Kitsune\Commerce\Payments\CheckoutReview;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Commerce\Payments\StripeCheckout;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Readers\ReaderErasure;
use Kitsune\Core\Readers\ReaderExport;
use Kitsune\Core\Readers\ReaderModuleDataUnavailable;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture;
use Kitsune\Core\Tests\Fixtures\OrderFixture as PaidPrivacyOrders;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;
use Kitsune\Core\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    config(AppServiceProvider::readerConfig());
    config(['hashing.bcrypt.rounds' => 4, 'app.key' => 'base64:'.base64_encode(str_repeat('p', 32))]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    $this->world = ReaderFixture::world();
    DB::table('orgs')->update(['currency' => 'USD']);
    $this->org = $this->world['golfdom'];
    $this->site = $this->world['sites']['golfdom'];
    $this->reader = ReaderFixture::reader($this->org, 'paid-private@example.test');
    app(Context::class)->setOrg($this->org)->setSite($this->site);
    Module::create(['handle' => 'kitsune/commerce', 'version' => 'old-installed-version', 'is_enabled' => false, 'installed_at' => now()]);
    $this->privacy = app(OrderReaderData::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Cookie::flushQueuedCookies();
    ReaderFixture::forget();
});

/** Raw historical custody: exports must work without a live catalogue or module runtime. @return array<string,mixed> */
function paidPrivacyPlant(Site $site, ?string $reader = null, ?string $ownerKey = null, ?string $refundState = null): array
{
    $context = app(Context::class);
    $beforeOrg = $context->org();
    $beforeSite = $context->site();
    $context->setSite($site);
    try {
        $order = PaidPrivacyOrders::plant($site, $reader, $ownerKey);
        $record = app(OrderReaderData::class)->record(DB::connection(), $order);
        $reference = bin2hex(random_bytes(16));
        $session = 'cs_test_Privacy'.bin2hex(random_bytes(8));
        $payload = CheckoutAttempts::payload($record, $reference, CarbonImmutable::now('UTC')->addMinutes(30)->timestamp, 'https://return.test');
        $attempt = DB::table(CheckoutAttempts::TABLE)->insertGetId(['org_id' => $site->org_id, 'site_id' => $site->id,
            'order_id' => $order->id, 'reference' => $reference, 'active_order_id' => null, 'mode' => 'test', 'currency' => 'USD',
            'account_id' => 'acct_Privacy'.bin2hex(random_bytes(6)), 'api_version' => StripeCheckout::API_VERSION,
            'credential_witness' => bin2hex(random_bytes(32)), 'state' => 'completed', 'request_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'request_hash' => CheckoutAttempts::digest($payload), 'order_hash' => CheckoutReview::orderHash($record), 'session_id' => $session,
            'checkout_url' => null, 'reviewed_at' => PaidPrivacyOrders::now(), 'expires_at' => CarbonImmutable::now('UTC')->addMinutes(30)->format('Y-m-d H:i:s'),
            'lease_token' => null, 'lease_until' => null, 'created_at' => PaidPrivacyOrders::now(), 'updated_at' => PaidPrivacyOrders::now()]);
        $attempt = DB::table(CheckoutAttempts::TABLE)->where('id', $attempt)->first();
        $charge = 'ch_Privacy'.bin2hex(random_bytes(8));
        $intent = 'pi_Privacy'.bin2hex(random_bytes(8));
        $payment = DB::table(PaidOrders::RECEIPTS)->insertGetId(['org_id' => $site->org_id, 'site_id' => $site->id, 'order_id' => $order->id,
            'attempt_id' => $attempt->id, 'account_id' => $attempt->account_id, 'mode' => 'test', 'api_version' => StripeCheckout::API_VERSION,
            'session_id' => $session, 'payment_intent_id' => $intent, 'charge_id' => $charge, 'currency' => 'USD', 'amount_minor' => '2100',
            'allocation_state' => 'allocated', 'category' => null, 'verified_at' => PaidPrivacyOrders::now()]);
        $line = DB::table('commerce_order_lines')->where('order_id', $order->id)->first();
        DB::table(PaidOrders::ALLOCATIONS)->insert(['org_id' => $site->org_id, 'site_id' => $site->id, 'order_id' => $order->id,
            'line_id' => $line->id, 'awarded' => $refundState === null ? 1 : 0, 'unavailable' => $refundState === null ? 0 : 1,
            'refund_item_net_minor' => $refundState === null ? '0' : '2000', 'refund_item_tax_minor' => $refundState === null ? '0' : '100',
            'refund_shipping_net_minor' => '0', 'refund_shipping_tax_minor' => '0', 'created_at' => PaidPrivacyOrders::now()]);
        if ($refundState !== null) {
            $obligation = DB::table(PaidOrders::OBLIGATIONS)->insertGetId(['org_id' => $site->org_id, 'site_id' => $site->id,
                'order_id' => $order->id, 'payment_id' => $payment, 'reference' => bin2hex(random_bytes(16)), 'amount_minor' => '2100',
                'reason' => 'unavailable_items', 'created_at' => PaidPrivacyOrders::now()]);
            app(PaidOrders::class)->newRefundAttempt(DB::connection(), $order, (int) $obligation, $charge, '2100');
            DB::table(PaidOrders::ATTEMPTS)->where('order_id', $order->id)->update(['state' => $refundState,
                'refund_id' => $refundState === 'owed' ? null : 're_Private'.bin2hex(random_bytes(8)),
                'first_submitted_at' => $refundState === 'owed' ? null : PaidPrivacyOrders::now()]);
        }
        DB::table('commerce_orders')->where('id', $order->id)->update(['state' => 'paid', 'active_cart_key' => null, 'confirmed_total_minor' => '2100']);
        // This raw pre-#200 paid history reaches the migration's explicit initialization path, not a missing paid hook.
        DB::table(AccessFulfillment::WORK)->where('order_id', $order->id)->update(['state' => 'initializing']);

        return ['order' => DB::table('commerce_orders')->where('id', $order->id)->first(), 'attempt' => $attempt,
            'charge' => $charge, 'intent' => $intent, 'hash' => CheckoutReview::orderHash($record)];
    } finally {
        $context->forget();
        if ($beforeOrg !== null) {
            $context->setOrg($beforeOrg);
        }
        if ($beforeSite !== null) {
            $context->setSite($beforeSite);
        }
    }
}

it('exports checked paid and refund summaries across the readers sites while commerce is disabled and stale', function (): void {
    $reader = (string) $this->reader->getKey();
    $first = paidPrivacyPlant($this->site, $reader, refundState: 'owed');
    paidPrivacyPlant($this->world['sites']['golfdom-fr'], $reader);
    $foreign = paidPrivacyPlant($this->world['sites']['rival'], $reader, refundState: 'failed');
    $orders = app(ReaderExport::class)->for($reader)['module_data']['kitsune/commerce']['orders'];
    $json = json_encode($orders, JSON_THROW_ON_ERROR);
    expect($orders)->toHaveCount(2)->and(array_column($orders, 'site'))->toBe(['golfdom', 'golfdom-fr'])
        ->and($orders[0]['state'])->toBe('paid')->and($orders[0]['confirmed_total_minor'])->toBe('2100')
        ->and($orders[0]['payment']['amount_minor'])->toBe('2100')->and($orders[0]['refund']['owed_minor'])->toBe('2100')
        ->and($orders[0]['allocations'][0]['unavailable'])->toBe(1)
        ->and($orders[0]['access_fulfillment'])->toMatchArray(['state' => 'initializing', 'effects' => []])
        ->and(CheckoutReview::orderHash($orders[0]))->toBe($first['hash'])
        ->and($json)->not->toContain($first['charge'], $first['intent'], $first['attempt']->session_id,
            $first['attempt']->account_id, $first['attempt']->credential_witness, $foreign['order']->reference,
            'request_payload', 'idempotency_key', 'refund_id', 'lease_token');
});

it('retains the entire reader account graph for paid orders including completed refunds', function (?string $refundState): void {
    $reader = (string) $this->reader->getKey();
    PaidPrivacyOrders::plant($this->site, $reader);
    paidPrivacyPlant($this->world['sites']['golfdom-fr'], $reader, refundState: $refundState);
    EntitlementFixture::plant($this->site, $reader, 'course.saved');
    expect(fn () => app(ReaderErasure::class)->erase($reader))->toThrow(ReaderModuleDataUnavailable::class)
        ->and(DB::table('commerce_orders')->count())->toBe(2)->and(DB::table('commerce_order_lines')->count())->toBe(2)
        ->and(DB::table(PaidOrders::RECEIPTS)->count())->toBe(1)->and(Reader::query()->count())->toBe(1)
        ->and(DB::table('entitlements')->count())->toBe(1);
})->with([null, 'owed', 'pending', 'failed', 'succeeded']);

it('keeps exact guest proof for paid export while refusing cancellation and erasure', function (string $refundState): void {
    $request = PaidPrivacyOrders::request();
    $owner = CartOwner::current($request, true);
    $fixture = paidPrivacyPlant($this->site, ownerKey: $owner->key, refundState: $refundState);
    $order = $fixture['order'];
    $export = $this->privacy->exportGuest($request, $order->reference);
    expect($export['refund']['state'])->toBe($refundState)
        ->and($export['access_fulfillment'])->toMatchArray(['state' => 'initializing', 'effects' => []])
        ->and(fn () => $this->privacy->exportGuest(PaidPrivacyOrders::request(), $order->reference))->toThrow(RuntimeException::class)
        ->and(fn () => $this->privacy->eraseGuest($request, $order->reference))->toThrow(RuntimeException::class)
        ->and(DB::table(PaidOrders::RECEIPTS)->count())->toBe(1);

    Module::query()->where('handle', 'kitsune/commerce')->firstOrFail()->update(['version' => ModuleDiscovery::version('kitsune/commerce'), 'is_enabled' => true]);
    ModuleKernel::flush();
    ModuleKernel::boot(app());
    config(['auth.providers.users.model' => TestUser::class]);
    CredentialFixture::member();
    $page = app(OrderList::class);
    $page->mount($order->reference);
    expect($page->detail()['state'])->toBe('paid')
        ->and(fn () => app(OrderWriter::class)->cancel($request, $order->reference))->toThrow(OrderRefused::class)
        ->and(fn () => app(OrderAdministration::class)->cancel($order->reference))->toThrow(OrderRefused::class)
        ->and(fn () => app(OrderAdministration::class)->erase($order->reference))->toThrow(OrderRefused::class)
        ->and(DB::table('commerce_orders')->value('state'))->toBe('paid');
})->with(['owed', 'succeeded']);

it('refuses mismatched paid custody before exporting or erasing a reader graph', function (string $table, string $column): void {
    $reader = (string) $this->reader->getKey();
    $fixture = paidPrivacyPlant($this->site, $reader, refundState: 'owed');
    DB::table($table)->where('order_id', $fixture['order']->id)->update([$column => $column === 'org_id'
        ? $this->world['rival']->id : $this->world['sites']['golfdom-fr']->id]);
    expect(fn () => app(ReaderExport::class)->for($reader))->toThrow(ReaderModuleDataUnavailable::class)
        ->and(fn () => app(ReaderErasure::class)->erase($reader))->toThrow(ReaderModuleDataUnavailable::class)
        ->and(DB::table('commerce_orders')->count())->toBe(1)->and(Reader::query()->count())->toBe(1);
})->with([
    [PaidOrders::RECEIPTS, 'org_id'], [PaidOrders::RECEIPTS, 'site_id'],
    [PaidOrders::ALLOCATIONS, 'org_id'], [PaidOrders::ALLOCATIONS, 'site_id'],
    [PaidOrders::OBLIGATIONS, 'org_id'], [PaidOrders::OBLIGATIONS, 'site_id'],
    [PaidOrders::ATTEMPTS, 'org_id'], [PaidOrders::ATTEMPTS, 'site_id'],
]);
