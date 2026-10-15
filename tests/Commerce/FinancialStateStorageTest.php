<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Models\FinancialRefund;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Payments\CheckoutAttempts;
use Kitsune\Commerce\Payments\CheckoutRefused;
use Kitsune\Commerce\Payments\FinancialState;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\PaidOrderFixture as PaidFx;

beforeEach(function (): void {
    Fx::boot();
    CarbonImmutable::setTestNow('2026-10-15 12:00:00 UTC');
    $this->org = Org::create(['slug' => 'financial-storage', 'name' => 'Financial storage', 'currency' => 'USD']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'financial-storage-main', 'name' => 'Main', 'base_url' => 'https://finance-storage.example.test']);
    app(Context::class)->setSite($this->site);
    $attempt = PaidFx::attempt($this->site, null, 3);
    $order = DB::table('commerce_orders')->where('id', $attempt->order_id)->sole();
    app(OrderLocks::class)->run(fn () => app(PaidOrders::class)->confirm(DB::connection(), $order, $attempt, PaidFx::session($attempt)));
    $this->order = DB::table('commerce_orders')->where('id', $order->id)->sole();
    $this->line = (int) DB::table('commerce_order_lines')->where('order_id', $order->id)->value('id');
    $this->ledger = app(FinancialState::class);
    Fx::owner();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Fx::tearDown();
});

it('round trips selected original units and semantic JSON on the actual selected database engine', function (): void {
    $reference = $this->ledger->requestAsOwner($this->order->reference, [$this->line => 2], 'Return two original units', str_repeat('a', 32));
    $row = DB::table(FinancialState::REFUNDS)->where('reference', $reference)->sole();
    $selection = json_decode($row->selection, true, flags: JSON_THROW_ON_ERROR);
    ksort($selection[0]);
    DB::table(FinancialState::REFUNDS)->where('id', $row->id)->update(['selection' => json_encode($selection, JSON_THROW_ON_ERROR)]);
    expect((string) $row->amount_minor)->toBe('4200')->and($selection[0]['units'])->toBe([1, 2])
        ->and($this->ledger->accessPlan(DB::connection(), $this->order)['removed_units'])->toBe([$this->line => 2])
        ->and(DB::table(PaidOrders::ALLOCATIONS)->where('order_id', $this->order->id)->value('awarded'))->toBe(3);
});

it('refuses a genuine persisted JSON type change without coercing a unit identity', function (): void {
    $reference = $this->ledger->requestAsOwner($this->order->reference, [$this->line => 1], 'Return one original unit', str_repeat('b', 32));
    $row = DB::table(FinancialState::REFUNDS)->where('reference', $reference)->sole();
    $selection = json_decode($row->selection, true, flags: JSON_THROW_ON_ERROR);
    $selection[0]['units'] = ['1'];
    DB::table(FinancialState::REFUNDS)->where('id', $row->id)->update(['selection' => json_encode($selection, JSON_THROW_ON_ERROR),
        'selection_hash' => CheckoutAttempts::digest(['selection' => $selection])]);
    expect(fn () => $this->ledger->checked(DB::connection(), $this->order))->toThrow(CheckoutRefused::class);
});

it('refuses missing mandatory custody and foreign planted refund ownership', function (string $mutation): void {
    $reference = $this->ledger->requestAsOwner($this->order->reference, [$this->line => 1], 'Return one original unit', str_repeat('c', 32));
    if ($mutation === 'parent') {
        DB::table(FinancialState::REFUNDS)->where('reference', $reference)->delete();
        DB::table(FinancialState::WORK)->where('order_id', $this->order->id)->delete();
    } else {
        $foreign = Org::create(['slug' => 'financial-storage-foreign', 'name' => 'Foreign']);
        DB::table(FinancialState::REFUNDS)->where('reference', $reference)->update(['org_id' => $foreign->id]);
    }
    expect(fn () => $this->ledger->checked(DB::connection(), $this->order))->toThrow(CheckoutRefused::class);
})->with(['parent', 'foreign']);

it('hides another sites financial rows from ordinary model reads and owner request routes', function (): void {
    $this->ledger->requestAsOwner($this->order->reference, [$this->line => 1], 'Return one original unit', str_repeat('d', 32));
    $sibling = Site::create(['handle' => 'sibling', 'slug' => 'financial-storage-sibling', 'name' => 'Sibling', 'base_url' => 'https://sibling-finance.example.test']);
    app(Context::class)->setSite($sibling);
    expect(FinancialRefund::query()->count())->toBe(0)
        ->and(fn () => $this->ledger->requestAsOwner($this->order->reference, [$this->line => 1], 'Try another Site order', str_repeat('e', 32)))
        ->toThrow(CheckoutRefused::class);
});
