<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Carts\CartOwner;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Payments\AccessAdjustments;
use Kitsune\Commerce\Payments\AccessFulfillment;
use Kitsune\Commerce\Payments\CheckoutAttempts;
use Kitsune\Commerce\Payments\FinancialReconciler;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Commerce\Payments\PaymentMaintenance;
use Kitsune\Commerce\Payments\StripeCheckout;
use Kitsune\Commerce\Payments\StripeEventInbox;
use Kitsune\Commerce\Payments\StripeWebhook;
use Kitsune\Core\Credentials\Secret;
use Kitsune\Core\Entitlements\EntitlementWriter;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Modules\ModuleLifecycle;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\PaidOrderFixture as PaidFx;

beforeEach(function (): void {
    Fx::boot();
    CarbonImmutable::setTestNow('2026-10-15 12:00:00 UTC');
    ModuleKernel::flush();
    ModuleLifecycle::install(app(), 'kitsune/commerce');
    ModuleLifecycle::enable(app(), 'kitsune/commerce');
    $this->org = Org::create(['name' => 'Access recovery', 'slug' => 'access-recovery', 'currency' => 'USD']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'access-recovery-main', 'name' => 'Main', 'base_url' => 'https://access-recovery.test']);
    app(Context::class)->setSite($this->site);
    Fx::declareReaders();
    $this->reader = Fx::reader();
    // Any unexpected provider request fails immediately. Recovery has no credentials and must need none.
    app()->instance(StripeCheckout::class, new StripeCheckout(new Client(['handler' => HandlerStack::create(new MockHandler)])));
    $this->financialProvider = new class
    {
        public int $calls = 0;

        public function reconcile(mixed ...$arguments): never
        {
            $this->calls++;
            throw new RuntimeException('Access recovery must not request financial provider I/O.');
        }

        public function submit(mixed ...$arguments): never
        {
            $this->calls++;
            throw new RuntimeException('Access recovery must not submit a refund.');
        }
    };
    app()->instance(FinancialReconciler::class, $this->financialProvider);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Fx::tearDown();
    ModuleKernel::flush();
});

function accessRecoveryPurchase(Site $site, string $reader): array
{
    $attempt = PaidFx::attempt($site, null, 1);
    $order = DB::table('commerce_orders')->where('id', $attempt->order_id)->sole();
    $owner = CartOwner::readerKey($reader);
    DB::table('commerce_orders')->where('id', $order->id)->update(['reader_id' => $reader, 'owner_key' => $owner,
        'active_cart_key' => hash('sha256', $owner."\0".$order->cart_ref."\0".$order->cart_version)]);
    DB::table('commerce_order_lines')->where('order_id', $order->id)->update(['entitlement_name' => 'course.recovered',
        'offer_terms' => json_encode(['version' => 1, 'kind' => 'fixed', 'days' => 30], JSON_THROW_ON_ERROR)]);
    $attempt = PaidFx::refresh($attempt);
    DB::table(CheckoutAttempts::TABLE)->where('id', $attempt->id)->update(['state' => 'completed', 'active_order_id' => null, 'checkout_url' => null]);
    $attempt = DB::table(CheckoutAttempts::TABLE)->where('id', $attempt->id)->sole();
    app(OrderLocks::class)->run(fn () => app(PaidOrders::class)->confirm(DB::connection(),
        DB::table('commerce_orders')->where('id', $order->id)->sole(), $attempt, PaidFx::session($attempt)));

    return [DB::table('commerce_orders')->where('id', $order->id)->sole(), $attempt];
}

it('discovers access after an interrupted financial-to-grant handoff without keys or another checkout reconciliation', function (): void {
    [$order] = accessRecoveryPurchase($this->site, (string) $this->reader->id);
    $pdo = new PDO('sqlite:'.DB::connection()->getDatabaseName());
    expect(DB::connection()->transactionLevel())->toBe(0)->and(DB::connection()->getPdo()->inTransaction())->toBeFalse()
        ->and($pdo->query('select state from commerce_access_work')->fetchColumn())->toBe('pending')
        ->and((int) $pdo->query('select count(*) from commerce_payment_receipts')->fetchColumn())->toBe(1)
        ->and((int) $pdo->query('select count(*) from entitlements')->fetchColumn())->toBe(0)
        ->and(DB::table('credentials')->count())->toBe(0);
    CarbonImmutable::setTestNow('2026-10-20 12:00:00 UTC');
    expect(app(PaymentMaintenance::class)->run(1, 1))->toBe(['processed' => 1, 'blocked' => 0])
        ->and($pdo->query('select source from entitlements')->fetchColumn())->toBe('commerce.order:'.$order->id)
        ->and($pdo->query('select expires_at from entitlements')->fetchColumn())->toBe('2026-11-14 12:00:00')
        ->and(DB::connection()->transactionLevel())->toBe(0)->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
    $grant = $pdo->query('select * from entitlements')->fetch(PDO::FETCH_ASSOC);
    // Completed initial fulfillment does not hide the independent financial source-adjustment marker.
    expect(app(PaymentMaintenance::class)->run(1, 1))->toBe(['processed' => 1, 'blocked' => 0])
        ->and($pdo->query('select state from commerce_access_adjustment_work')->fetchColumn())->toBe('processed')
        ->and((int) $pdo->query('select replay_count from commerce_access_work')->fetchColumn())->toBe(1)
        ->and((int) $pdo->query('select replay_count from commerce_access_adjustment_work')->fetchColumn())->toBe(1)
        ->and($pdo->query('select * from entitlements')->fetch(PDO::FETCH_ASSOC))->toBe($grant)
        ->and(app(PaymentMaintenance::class)->run(1, 1))->toBe(['processed' => 0, 'blocked' => 0])
        ->and(DB::table('commerce_stock_movements')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action', EntitlementWriter::GRANTED)->count())->toBe(1)
        ->and(DB::table('credentials')->count())->toBe(0)->and($this->financialProvider->calls)->toBe(0);
});

it('advances fair durable access claims so one missing reader cannot starve another paid order', function (): void {
    [$blocked] = accessRecoveryPurchase($this->site, (string) $this->reader->id);
    $other = Fx::reader(email: 'other@example.test');
    [$ready] = accessRecoveryPurchase($this->site, (string) $other->id);
    DB::table($this->reader->getTable())->where('id', $this->reader->id)->delete();
    // Two orders each have initial access and independent source work. Each bounded claim advances one durable
    // counter, so the missing reader cannot keep either ready work kind behind its earlier order indefinitely.
    $bound = DB::table(AccessFulfillment::WORK)->count() + DB::table(AccessAdjustments::WORK)->count();
    $counts = ['processed' => 0, 'blocked' => 0];
    foreach (range(1, $bound) as $pass) {
        $result = app(PaymentMaintenance::class)->run(1, 1);
        expect(array_sum($result))->toBe(1);
        $counts['processed'] += $result['processed'];
        $counts['blocked'] += $result['blocked'];
    }
    expect($bound)->toBe(4)->and($counts)->toBe(['processed' => 2, 'blocked' => 2])
        ->and(DB::table(AccessFulfillment::WORK)->where('order_id', $blocked->id)->value('category'))->toBe('unknown_reader')
        ->and(DB::table(AccessFulfillment::WORK)->where('order_id', $ready->id)->value('state'))->toBe('processed')
        ->and(DB::table(AccessAdjustments::WORK)->where('order_id', $ready->id)->value('state'))->toBe('processed')
        ->and(DB::table(AccessFulfillment::WORK)->orderBy('order_id')->pluck('replay_count')->all())->toBe([1, 1])
        ->and(DB::table(AccessAdjustments::WORK)->orderBy('order_id')->pluck('replay_count')->all())->toBe([1, 1])
        ->and(DB::table('entitlements')->count())->toBe(1)
        ->and(DB::table('entitlements')->value('reader_id'))->toBe((string) $other->id)
        ->and(DB::table('entitlements')->value('source'))->toBe('commerce.order:'.$ready->id)
        ->and(DB::table('entitlements')->value('expires_at'))->toBe('2026-11-14 12:00:00')
        ->and(DB::table('audit_log')->where('action', EntitlementWriter::GRANTED)->count())->toBe(1)
        ->and(DB::table('commerce_stock_movements')->count())->toBe(2)
        ->and(DB::table('credentials')->count())->toBe(0)->and($this->financialProvider->calls)->toBe(0)
        ->and(DB::connection()->transactionLevel())->toBe(0)->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
});

it('processes two independently signed events for one captured order and grants its durable source only once', function (): void {
    [$order, $attempt] = accessRecoveryPurchase($this->site, (string) $this->reader->id);
    $secret = new Secret('whsec_'.str_repeat('a', 32));
    $inbox = app(StripeEventInbox::class);
    foreach (['checkout.session.completed', 'checkout.session.async_payment_succeeded'] as $index => $type) {
        $event = ['object' => 'event', 'id' => 'evt_Access'.$index, 'type' => $type, 'api_version' => StripeCheckout::API_VERSION, 'livemode' => false,
            'data' => ['object' => ['object' => 'checkout.session', 'id' => $attempt->session_id, 'livemode' => false, 'mode' => 'payment',
                'client_reference_id' => $order->reference, 'metadata' => ['kitsune_order' => $order->reference, 'kitsune_attempt' => $attempt->reference]]]];
        $raw = json_encode($event, JSON_THROW_ON_ERROR);
        $now = CarbonImmutable::now('UTC');
        $signature = 't='.$now->getTimestamp().',v1='.hash_hmac('sha256', $now->getTimestamp().'.'.$raw, $secret->reveal());
        $verified = app(StripeWebhook::class)->verify($raw, $signature, [$secret], $now->getTimestamp());
        $receipt = $inbox->capture($this->org->id, $verified, $raw, $now);
        expect($inbox->process($receipt['id']))->toBe('processed');
        app(PaymentMaintenance::class)->afterEvent($receipt['id']);
    }
    expect(DB::table(StripeEventInbox::TABLE)->where('state', 'processed')->count())->toBe(2)
        ->and(DB::table('entitlements')->count())->toBe(1)->and(DB::table('entitlements')->value('source'))->toBe('commerce.order:'.$order->id)
        ->and(DB::table('audit_log')->where('action', EntitlementWriter::GRANTED)->count())->toBe(1)
        ->and(DB::table('commerce_stock_movements')->count())->toBe(1)
        ->and(DB::table(AccessFulfillment::WORK)->value('state'))->toBe('processed');
});
