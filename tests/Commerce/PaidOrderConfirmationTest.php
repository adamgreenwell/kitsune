<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Models\OrderAllocation;
use Kitsune\Commerce\Models\PaymentReceipt;
use Kitsune\Commerce\Models\RefundAttempt;
use Kitsune\Commerce\Models\RefundObligation;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Commerce\Payments\AllocationAmounts;
use Kitsune\Commerce\Payments\CheckoutRefused;
use Kitsune\Commerce\Payments\CheckoutReview;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Attributes\SiteScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\PaidOrderFixture as PaidFx;

beforeEach(function (): void {
    Fx::boot();
    $org = Org::create(['slug' => 'paid', 'name' => 'Paid', 'currency' => 'USD']);
    app(Context::class)->setOrg($org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'paid-main', 'name' => 'Main', 'base_url' => 'https://paid.example.test']);
    $this->attempt = PaidFx::attempt($this->site);
    $this->session = PaidFx::session($this->attempt);
    $this->confirm = function (?array $session = null): void {
        app(OrderLocks::class)->run(function () use ($session): void {
            app(PaidOrders::class)->confirm(DB::connection(), DB::table('commerce_orders')->where('id', $this->attempt->order_id)->sole(),
                $this->attempt, $session ?? $this->session);
        });
    };
});

afterEach(fn () => Fx::tearDown());

it('allocates two of three once and freezes a refund for the missing unit including its original tax', function (): void {
    $before = DB::table('commerce_order_lines')->sole();
    ($this->confirm)();
    ($this->confirm)();
    $order = DB::table('commerce_orders')->sole();
    $projection = app(OrderReaderData::class)->record(DB::connection(), $order);
    expect($order->state)->toBe('paid')->and((string) $order->confirmed_total_minor)->toBe('6300')
        ->and((string) DB::table('commerce_sellables')->sole()->stock)->toBe('0')
        ->and(DB::table(PaidOrders::RECEIPTS)->count())->toBe(1)->and(DB::table(PaidOrders::ALLOCATIONS)->count())->toBe(1)
        ->and(DB::table('commerce_stock_movements')->count())->toBe(1)
        ->and($projection['allocations'][0])->toMatchArray(['awarded' => 2, 'unavailable' => 1])
        ->and($projection['refund'])->toMatchArray(['amount_minor' => '2100', 'owed_minor' => '2100', 'state' => 'owed'])
        ->and((array) DB::table('commerce_order_lines')->sole())->toBe((array) $before)
        ->and(CheckoutReview::orderHash($projection))->toBe($this->attempt->order_hash);
});

it('leaves untracked stock null while recording one allocation decision', function (): void {
    DB::table('commerce_sellables')->update(['stock' => null]);
    ($this->confirm)();
    $projection = app(OrderReaderData::class)->record(DB::connection(), DB::table('commerce_orders')->sole());
    expect(DB::table('commerce_sellables')->sole()->stock)->toBeNull()->and($projection['refund'])->toBeNull()
        ->and($projection['allocations'][0])->toMatchArray(['awarded' => 3, 'unavailable' => 0]);
});

it('refunds a removed or unavailable product without recreating or decrementing stock', function (string $case): void {
    if ($case === 'removed') {
        DB::table('commerce_listings')->delete();
        DB::table('commerce_sellables')->delete();
    } elseif ($case === 'listing') {
        DB::table('commerce_listings')->update(['is_available' => false]);
    } else {
        DB::table('entries')->update(['deleted_at' => now()]);
    }
    ($this->confirm)();
    $projection = app(OrderReaderData::class)->record(DB::connection(), DB::table('commerce_orders')->sole());
    expect($projection['refund']['amount_minor'])->toBe('6300')->and($projection['allocations'][0]['awarded'])->toBe(0)
        ->and(DB::table('commerce_stock_movements')->count())->toBe(0);
})->with(['removed', 'listing', 'entry']);

it('records a cancelled legacy captured order as fully owed without allocating available stock', function (): void {
    DB::table('commerce_orders')->update(['state' => 'cancelled', 'active_cart_key' => null, 'cancelled_at' => now()]);
    ($this->confirm)();
    $projection = app(OrderReaderData::class)->record(DB::connection(), DB::table('commerce_orders')->sole());
    expect($projection['refund'])->toMatchArray(['amount_minor' => '6300', 'reason' => 'cancelled_before_confirmation'])
        ->and((string) DB::table('commerce_sellables')->sole()->stock)->toBe('2')
        ->and(CheckoutReview::orderHash($projection))->toBe($this->attempt->order_hash);
});

it('holds captured money with external refunds or a dispute without allocating twice', function (string $case): void {
    $session = $this->session;
    $session['payment'][$case] = $case === 'disputed' ? true : '1';
    ($this->confirm)($session);
    $projection = app(OrderReaderData::class)->record(DB::connection(), DB::table('commerce_orders')->sole());
    expect($projection['payment']['allocation_state'])->toBe('held')->and($projection['allocations'])->toBe([])
        ->and($projection['refund'])->toBeNull()->and((string) DB::table('commerce_sellables')->sole()->stock)->toBe('2');
})->with(['refunded', 'disputed']);

it('rolls back receipt stock and obligations when the enclosing transaction fails', function (): void {
    expect(fn () => app(OrderLocks::class)->run(function (): void {
        ($this->confirm)();
        throw new RuntimeException('Fixture rollback');
    }))->toThrow(RuntimeException::class);
    expect(DB::table(PaidOrders::RECEIPTS)->count())->toBe(0)->and(DB::table(PaidOrders::ALLOCATIONS)->count())->toBe(0)
        ->and(DB::table(PaidOrders::ATTEMPTS)->count())->toBe(0)->and(DB::table('commerce_stock_movements')->count())->toBe(0)
        ->and((string) DB::table('commerce_sellables')->sole()->stock)->toBe('2');
    ($this->confirm)();
    expect(DB::table(PaidOrders::RECEIPTS)->count())->toBe(1);
});

it('refuses mismatched payment graph or a changed existing receipt', function (string $case): void {
    $session = $this->session;
    if ($case === 'receipt') {
        ($this->confirm)();
        $session['payment']['chargeId'] = 'ch_other';
    } else {
        $session['payment'][$case] = $case === 'test' ? false : '1';
    }
    expect(fn () => ($this->confirm)($session))->toThrow(CheckoutRefused::class);
    expect(DB::table(PaidOrders::RECEIPTS)->count())->toBe($case === 'receipt' ? 1 : 0);
})->with(['amount', 'received', 'captured', 'test', 'receipt']);

it('declares enforced scopes and closes every ORM write door on financial custody', function (string $class, string $scope): void {
    ($this->confirm)();
    expect((new ReflectionClass($class))->getAttributes($scope))->toHaveCount(1)
        ->and(class_uses_recursive($class))->toContain(EnforcesScope::class);
    $row = $class::query()->sole();
    expect($row->toArray())->toBe([])->and(fn () => $row->save())->toThrow(RuntimeException::class)
        ->and(fn () => $row->delete())->toThrow(RuntimeException::class)
        ->and(fn () => $class::query()->update(['org_id' => 99]))->toThrow(RuntimeException::class)
        ->and(fn () => $class::query()->insert([]))->toThrow(RuntimeException::class);
    app(Context::class)->forget();
    expect($class::query()->count())->toBe(0);
})->with([[PaymentReceipt::class, OrgScoped::class], [OrderAllocation::class, SiteScoped::class],
    [RefundObligation::class, SiteScoped::class], [RefundAttempt::class, SiteScoped::class]]);

it('refuses planted financial child stamps and malformed lifecycle metadata', function (string $column): void {
    ($this->confirm)();
    DB::table(PaidOrders::ATTEMPTS)->update([$column => match ($column) {
        'state' => 'invented', 'idempotency_key' => 'other', 'category' => 'private provider error', 'reference' => str_repeat('z', 32),
        'lease_token' => 'bad', default => '0',
    }]);
    expect(fn () => app(OrderReaderData::class)->record(DB::connection(), DB::table('commerce_orders')->sole()))->toThrow(RuntimeException::class);
})->with(['state', 'idempotency_key', 'category', 'reference', 'lease_token']);

it('apportions exact large money without floats and keeps stable minor-unit rounding', function (): void {
    expect(AllocationAmounts::split('10', ['1', '1', '1']))->toBe(['4', '3', '3'])
        ->and(AllocationAmounts::split('101', ['2000', '1000', '0']))->toBe(['67', '34', '0'])
        ->and(AllocationAmounts::split('9223372036854775807', ['9223372036854775806', '1']))->toBe(['9223372036854775806', '1']);
});

it('allocates shipping net and tax only by original physical net values and preserves every minor unit', function (): void {
    $first = (array) DB::table('commerce_order_lines')->sole();
    DB::table('commerce_order_lines')->where('id', $first['id'])->update(['delivery_kind' => 'physical']);
    unset($first['id']);
    DB::table('commerce_order_lines')->insert([...$first, 'quantity' => 1, 'delivery_kind' => 'physical', 'net_minor' => '2000', 'tax_minor' => '100', 'gross_minor' => '2100']);
    DB::table('commerce_order_lines')->insert([...$first, 'quantity' => 1, 'delivery_kind' => 'digital', 'net_minor' => '2000', 'tax_minor' => '100', 'gross_minor' => '2100']);
    $shipping = ['id' => 'rate_fixture', 'carrier' => 'Carrier', 'service' => 'Ground', 'amountMinor' => '101', 'currency' => 'USD',
        'shipmentId' => 'shipment_fixture', 'test' => true, 'taxClass' => 'shipping', 'taxMode' => 'exclusive',
        'taxRateParts' => 100000, 'net' => '101', 'tax' => '10', 'gross' => '111'];
    DB::table('commerce_orders')->update(['delivery' => DB::table('commerce_orders')->value('billing'), 'shipping' => json_encode($shipping, JSON_THROW_ON_ERROR),
        'quoted_item_net_minor' => '10000', 'quoted_shipping_net_minor' => '101', 'quoted_tax_minor' => '510', 'quoted_total_minor' => '10611']);
    $this->attempt = PaidFx::refresh($this->attempt);
    $this->session = PaidFx::session($this->attempt);
    ($this->confirm)();
    $projection = app(OrderReaderData::class)->record(DB::connection(), DB::table('commerce_orders')->sole());
    expect($projection['allocations'][0])->toMatchArray(['awarded' => 2, 'unavailable' => 1,
        'refund_components' => ['item_net_minor' => '2000', 'item_tax_minor' => '100', 'shipping_net_minor' => '25', 'shipping_tax_minor' => '2']])
        ->and($projection['allocations'][1]['refund_components'])->toBe(['item_net_minor' => '2000', 'item_tax_minor' => '100', 'shipping_net_minor' => '25', 'shipping_tax_minor' => '2'])
        ->and($projection['allocations'][2]['refund_components']['shipping_net_minor'])->toBe('0')
        ->and($projection['allocations'][2]['refund_components']['shipping_tax_minor'])->toBe('0')
        ->and($projection['refund']['amount_minor'])->toBe('6354')->and($projection['confirmed_total_minor'])->toBe('10611');
});

it('refuses changed allocation components even when an attacker preserves the refund total', function (): void {
    ($this->confirm)();
    DB::table(PaidOrders::ALLOCATIONS)->update(['refund_item_net_minor' => '2001', 'refund_item_tax_minor' => '99']);
    expect(fn () => app(OrderReaderData::class)->record(DB::connection(), DB::table('commerce_orders')->sole()))->toThrow(RuntimeException::class);
});

it('keeps an exact but unrepresentable provider refund visibly owed without inventing or rounding a request', function (): void {
    $org = Org::create(['slug' => 'mga', 'name' => 'MGA', 'currency' => 'MGA']);
    app(Context::class)->setOrg($org);
    $site = Site::create(['handle' => 'mga', 'slug' => 'paid-mga', 'name' => 'MGA', 'base_url' => 'https://mga.example.test']);
    $attempt = PaidFx::attempt($site);
    DB::table('commerce_order_lines')->where('order_id', $attempt->order_id)->update(['unit_price_minor' => '100', 'tax_rate_parts' => 333333,
        'net_minor' => '300', 'tax_minor' => '100', 'gross_minor' => '400']);
    DB::table('commerce_orders')->where('id', $attempt->order_id)->update(['quoted_item_net_minor' => '300', 'quoted_tax_minor' => '100', 'quoted_total_minor' => '400']);
    $attempt = PaidFx::refresh($attempt);
    app(OrderLocks::class)->run(function () use ($attempt): void {
        app(PaidOrders::class)->confirm(DB::connection(), DB::table('commerce_orders')->where('id', $attempt->order_id)->sole(), $attempt, PaidFx::session($attempt));
    });
    $projection = app(OrderReaderData::class)->record(DB::connection(), DB::table('commerce_orders')->where('id', $attempt->order_id)->sole());
    expect($projection['confirmed_total_minor'])->toBe('400')->and($projection['refund'])->toMatchArray([
        'amount_minor' => '133', 'owed_minor' => '133', 'state' => 'held', 'category' => 'currency', 'attempts' => [],
    ])->and($projection['allocations'][0]['awarded'])->toBe(2)
        ->and(DB::table(PaidOrders::ATTEMPTS)->where('order_id', $attempt->order_id)->count())->toBe(0);
});
