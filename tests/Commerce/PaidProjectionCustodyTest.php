<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Commerce\Payments\CheckoutRefused;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\PaidOrderFixture as PaidFx;

beforeEach(function (): void {
    Fx::boot();
    $org = Org::create(['slug' => 'projection-custody', 'name' => 'Projection custody', 'currency' => 'USD']);
    app(Context::class)->setOrg($org);
    $site = Site::create(['handle' => 'main', 'slug' => 'projection-main', 'name' => 'Main', 'base_url' => 'https://paid.example.test']);
    $this->attempt = PaidFx::attempt($site);
    $this->confirm = function (array $payment = []): void {
        $session = PaidFx::session($this->attempt);
        $session['payment'] = [...$session['payment'], ...$payment];
        app(OrderLocks::class)->run(function () use ($session): void {
            app(PaidOrders::class)->confirm(DB::connection(), DB::table('commerce_orders')->sole(), $this->attempt, $session);
        });
    };
    $this->project = fn (): array => app(OrderReaderData::class)->record(DB::connection(), DB::table('commerce_orders')->sole());
    $this->custody = fn (): array => app(PaidOrders::class)->projection(DB::connection(), (int) $org->id, DB::table('commerce_orders')->sole());
});

afterEach(fn () => Fx::tearDown());

it('refuses provider refund states without a retained provider identifier before exposing paid summaries', function (string $state): void {
    ($this->confirm)();
    DB::table(PaidOrders::ATTEMPTS)->update(['state' => $state, 'first_submitted_at' => now()]);

    expect($this->custody)->toThrow(CheckoutRefused::class)
        ->and($this->project)->toThrow(RuntimeException::class, 'The retained order data is inconsistent or unavailable.')
        ->and((string) DB::table(PaidOrders::OBLIGATIONS)->value('amount_minor'))->toBe('2100')
        ->and((string) DB::table('commerce_orders')->value('confirmed_total_minor'))->toBe('6300');
})->with(['pending', 'requires_action', 'succeeded', 'failed', 'canceled']);

it('projects each provider refund state only with its retained identifier', function (string $state): void {
    ($this->confirm)();
    DB::table(PaidOrders::ATTEMPTS)->update(['state' => $state, 'refund_id' => 're_ProjectionFixture', 'first_submitted_at' => now()]);
    $projection = ($this->project)();

    expect($projection['refund']['state'])->toBe($state)
        ->and($projection['refund']['owed_minor'])->toBe($state === 'succeeded' ? '0' : '2100');
})->with(['pending', 'requires_action', 'succeeded', 'failed', 'canceled']);

it('preserves held and unknown recovery summaries with or without a known refund identifier', function (string $state, ?string $identifier): void {
    ($this->confirm)();
    DB::table(PaidOrders::ATTEMPTS)->update(['state' => $state, 'refund_id' => $identifier, 'category' => 'unavailable', 'first_submitted_at' => now()]);
    $projection = ($this->project)();

    expect($projection['refund'])->toMatchArray(['state' => $state, 'owed_minor' => '2100', 'category' => 'unavailable']);
})->with([
    ['held', null], ['held', 're_ProjectionFixture'], ['unknown', null], ['unknown', 're_ProjectionFixture'],
]);

it('refuses any non-null category on an allocated payment receipt', function (string $category): void {
    ($this->confirm)();
    DB::table(PaidOrders::RECEIPTS)->update(['category' => $category]);

    expect($this->custody)->toThrow(CheckoutRefused::class)
        ->and($this->project)->toThrow(RuntimeException::class, 'The retained order data is inconsistent or unavailable.');
})->with(['arbitrary', '', 'operator', 'refund_failed', 'disputed', 'external_refund']);

it('keeps allocated null categories and the two recognized held categories valid', function (string $state, ?string $category): void {
    ($this->confirm)($category === null ? [] : ($category === 'disputed' ? ['disputed' => true] : ['refunded' => '1']));
    $projection = ($this->project)();

    expect($projection['payment'])->toMatchArray(['allocation_state' => $state, 'category' => $category]);
})->with([['allocated', null], ['held', 'disputed'], ['held', 'external_refund']]);

it('rejects retained SQLite real and text allocation quantities before integer casts can conceal them', function (string $column, mixed $value): void {
    // Typed integer columns on other engines either reject or coerce these writes before the reader sees them.
    // SQLite preserves these exact malformed values in its integer-affinity columns, so exercise that custody seam here.
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite retained type corruption.');
    }
    ($this->confirm)();
    $order = (array) DB::table('commerce_orders')->sole();
    $lines = DB::table('commerce_order_lines')->get()->map(static fn (object $line): array => (array) $line)->all();
    DB::table(PaidOrders::ALLOCATIONS)->update([$column => $value]);

    expect(DB::table(PaidOrders::ALLOCATIONS)->value($column))->toBe($value)
        ->and($this->custody)->toThrow(CheckoutRefused::class)
        ->and($this->project)->toThrow(RuntimeException::class, 'The retained order data is inconsistent or unavailable.')
        ->and((array) DB::table('commerce_orders')->sole())->toBe($order)
        ->and(DB::table('commerce_order_lines')->get()->map(static fn (object $line): array => (array) $line)->all())->toBe($lines)
        ->and((string) DB::table(PaidOrders::RECEIPTS)->value('amount_minor'))->toBe('6300')
        ->and((string) DB::table(PaidOrders::OBLIGATIONS)->value('amount_minor'))->toBe('2100');
})->with([
    ['awarded', 2.5], ['unavailable', 1.5], ['awarded', '2 units'], ['unavailable', '1 unit'],
]);
