<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Payments\AccessAdjustments;
use Kitsune\Commerce\Payments\CheckoutAttempts;
use Kitsune\Commerce\Payments\CheckoutReconciler;
use Kitsune\Commerce\Payments\CheckoutRefused;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Commerce\Payments\PaymentMaintenance;
use Kitsune\Commerce\Payments\RefundRecovery;
use Kitsune\Commerce\Payments\StripeEventInbox;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Modules\ModuleLifecycle;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as MaintenanceFx;
use Kitsune\Core\Tests\Fixtures\PaidOrderFixture as MaintenancePaid;
use Kitsune\Core\Tests\Fixtures\StripeInboxFixture as MaintenanceInbox;

beforeEach(function (): void {
    MaintenanceFx::boot();
    CarbonImmutable::setTestNow('2026-10-15 12:00:00 UTC');
    $this->org = MaintenanceFx::org('payment-maintenance');
    $this->org->update(['currency' => 'USD']);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'payment-maintenance-main', 'name' => 'Main', 'base_url' => 'https://maintenance.test']);
    app(Context::class)->setSite($this->site);
    ModuleKernel::flush();
    ModuleLifecycle::install(app(), 'kitsune/commerce');
    ModuleLifecycle::enable(app(), 'kitsune/commerce');
    $this->calls = new ArrayObject;
    $calls = $this->calls;
    // The scheduling boundary is measured separately from provider/financial correctness. These workers perform no I/O.
    app()->instance(CheckoutReconciler::class, new class($calls)
    {
        public function __construct(private readonly ArrayObject $calls) {}

        public function reconcile(string $order, string $reference, string $session): array
        {
            $this->calls->append(['confirmation', $reference]);
            throw new CheckoutRefused('changed');
        }
    });
    $this->blockedRefund = null;
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    MaintenanceFx::tearDown();
});

/** One real committed out-of-stock allocation produces an owed refund; only the later worker is replaced. */
function maintenanceRefund(Site $site): object
{
    $attempt = MaintenancePaid::attempt($site, '0', 1);
    DB::table(CheckoutAttempts::TABLE)->where('id', $attempt->id)->update(['state' => 'completed', 'active_order_id' => null, 'checkout_url' => null]);
    $attempt = DB::table(CheckoutAttempts::TABLE)->where('id', $attempt->id)->sole();
    $proof = MaintenancePaid::session($attempt);
    app(OrderLocks::class)->run(fn () => app(PaidOrders::class)->confirm(DB::connection(),
        DB::table('commerce_orders')->where('id', $attempt->order_id)->sole(), $attempt, $proof));
    // No units were awarded: source adjustment work is already complete, keeping this fixture about refund scheduling.
    app(AccessAdjustments::class)->reconcile(DB::table('commerce_orders')->where('id', $attempt->order_id)->value('reference'));

    return DB::table(PaidOrders::ATTEMPTS)->where('order_id', $attempt->order_id)->sole();
}

function maintenanceRefundWorker(ArrayObject $calls, ?string $blocked = null): void
{
    app()->instance(RefundRecovery::class, new class($calls, $blocked)
    {
        public function __construct(private readonly ArrayObject $calls, private readonly ?string $blocked) {}

        public function reconcile(string $order, string $reference): array
        {
            $this->calls->append(['refund', $reference]);
            if ($reference === $this->blocked) {
                throw new CheckoutRefused('changed');
            }

            return ['reference' => $reference, 'state' => 'pending'];
        }
    });
}

it('advances from a blocked legacy confirmation to a later owed refund with a one-operation budget', function (): void {
    $legacy = MaintenanceInbox::attempt($this->site, 'completed');
    $refund = maintenanceRefund($this->site);
    maintenanceRefundWorker($this->calls);
    app(Context::class)->forget()->setOrg($this->org);
    $maintenance = app(PaymentMaintenance::class);
    expect(DB::transactionLevel())->toBe(0)->and($maintenance->run(1))->toBe(['processed' => 0, 'blocked' => 1])
        ->and($maintenance->run(1))->toBe(['processed' => 1, 'blocked' => 0])
        ->and($this->calls->getArrayCopy())->toBe([['confirmation', $legacy->reference], ['refund', $refund->reference]])
        ->and((string) DB::table(CheckoutAttempts::TABLE)->where('id', $legacy->id)->value('confirmation_replay_count'))->toBe('1')
        ->and((string) DB::table(PaidOrders::ATTEMPTS)->where('id', $refund->id)->value('replay_count'))->toBe('1')
        ->and(app(Context::class)->orgId())->toBe($this->org->id)->and(app(Context::class)->siteId())->toBeNull();
});

it('advances from an old credential-blocked refund to another refund even before provider admission', function (): void {
    $first = maintenanceRefund($this->site);
    $second = maintenanceRefund($this->site);
    maintenanceRefundWorker($this->calls, $first->reference);
    $maintenance = app(PaymentMaintenance::class);
    expect($maintenance->run(1))->toBe(['processed' => 0, 'blocked' => 1])
        ->and($maintenance->run(1))->toBe(['processed' => 1, 'blocked' => 0])
        ->and($this->calls->getArrayCopy())->toBe([['refund', $first->reference], ['refund', $second->reference]])
        ->and((string) DB::table(PaidOrders::ATTEMPTS)->where('id', $first->id)->value('replay_count'))->toBe('1')
        ->and(app(Context::class)->siteId())->toBe($this->site->id);
});

it('skips active leases of both work types before changing counters or invoking a worker', function (): void {
    $legacy = MaintenanceInbox::attempt($this->site, 'completed');
    $refund = maintenanceRefund($this->site);
    DB::table(CheckoutAttempts::TABLE)->where('id', $legacy->id)->update(['lease_token' => str_repeat('a', 64), 'lease_until' => '2026-10-15 12:00:30']);
    DB::table(PaidOrders::ATTEMPTS)->where('id', $refund->id)->update(['lease_token' => str_repeat('b', 64), 'lease_until' => '2026-10-15 12:01:30']);
    maintenanceRefundWorker($this->calls);
    expect(app(PaymentMaintenance::class)->run(1))->toBe(['processed' => 0, 'blocked' => 0])
        ->and($this->calls->getArrayCopy())->toBe([])
        ->and((string) DB::table(CheckoutAttempts::TABLE)->where('id', $legacy->id)->value('confirmation_replay_count'))->toBe('0')
        ->and((string) DB::table(PaidOrders::ATTEMPTS)->where('id', $refund->id)->value('replay_count'))->toBe('0');
});

it('rechecks an active lease committed after selection before advancing its durable counter', function (): void {
    $legacy = MaintenanceInbox::attempt($this->site, 'completed');
    $refund = maintenanceRefund($this->site);
    maintenanceRefundWorker($this->calls);
    $changed = false;
    DB::listen(static function ($query) use (&$changed, $legacy): void {
        if (! $changed && str_contains($query->sql, ' as "a"') && str_contains($query->sql, 'confirmation_replay_count')) {
            $changed = true;
            DB::table(CheckoutAttempts::TABLE)->where('id', $legacy->id)->update([
                'lease_token' => str_repeat('c', 64), 'lease_until' => '2026-10-15 12:00:30',
            ]);
        }
    });
    expect(app(PaymentMaintenance::class)->run(1))->toBe(['processed' => 1, 'blocked' => 0])
        ->and($changed)->toBeTrue()->and($this->calls->getArrayCopy())->toBe([['refund', $refund->reference]])
        ->and((string) DB::table(CheckoutAttempts::TABLE)->where('id', $legacy->id)->value('confirmation_replay_count'))->toBe('0');
});

it('refuses disabled or stale module execution without advancing any confirmation counter', function (string $denial): void {
    $legacy = MaintenanceInbox::attempt($this->site, 'completed');
    Module::query()->where('handle', 'kitsune/commerce')->firstOrFail()->update($denial === 'disabled' ? ['is_enabled' => false] : ['version' => 'stale']);
    expect(fn () => app(PaymentMaintenance::class)->run(1))->toThrow(CheckoutRefused::class)
        ->and($this->calls->getArrayCopy())->toBe([])
        ->and((string) DB::table(CheckoutAttempts::TABLE)->where('id', $legacy->id)->value('confirmation_replay_count'))->toBe('0');
})->with(['disabled', 'stale']);

it('reads only minimal event routing when continuing a processed checkout after the response', function (): void {
    $attempt = MaintenanceInbox::attempt($this->site);
    $receipt = app(StripeEventInbox::class)->capture($this->org->id, MaintenanceInbox::event($attempt), '{"private":"custody only"}', CarbonImmutable::now('UTC'));
    DB::table(StripeEventInbox::TABLE)->where('id', $receipt['id'])->update(['state' => 'processed', 'processed_at' => '2026-10-15 12:00:00']);
    $queries = new ArrayObject;
    DB::listen(static function ($query) use ($queries): void {
        if (str_contains($query->sql, 'commerce_stripe_events')) {
            $queries->append($query->sql);
        }
    });
    app(PaymentMaintenance::class)->afterEvent($receipt['id']);
    expect($queries)->toHaveCount(1)->and($queries[0])->not->toContain('raw_payload', 'select *')
        ->and($queries[0])->toContain('order_reference', 'refund_reference');
});
