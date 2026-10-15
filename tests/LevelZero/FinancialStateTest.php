<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Models\FinancialObservation;
use Kitsune\Commerce\Models\FinancialRefund;
use Kitsune\Commerce\Models\FinancialWork;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Payments\AccessFulfillment;
use Kitsune\Commerce\Payments\CheckoutRefused;
use Kitsune\Commerce\Payments\FinancialSignals;
use Kitsune\Commerce\Payments\FinancialState;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Models\AuditLog;
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
    ModuleKernel::boot(app());
    $this->org = Org::create(['slug' => 'financial-state', 'name' => 'Financial state', 'currency' => 'USD']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'financial-state-main', 'name' => 'Main', 'base_url' => 'https://financial.example.test']);
    app(Context::class)->setSite($this->site);
    $this->attempt = PaidFx::attempt($this->site, null, 3);
    $order = DB::table('commerce_orders')->where('id', $this->attempt->order_id)->sole();
    if (! DB::table(FinancialState::WORK)->where('order_id', $order->id)->exists()) {
        app(OrderLocks::class)->run(fn () => app(FinancialState::class)->prepare(DB::connection(), $order));
    }
    app(OrderLocks::class)->run(fn () => app(PaidOrders::class)->confirm(DB::connection(), $order, $this->attempt, PaidFx::session($this->attempt)));
    $this->order = DB::table('commerce_orders')->where('id', $order->id)->sole();
    $this->line = (int) DB::table('commerce_order_lines')->where('order_id', $order->id)->value('id');
    $this->ledger = app(FinancialState::class);
    $this->owner = Fx::owner();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Fx::tearDown();
    ModuleKernel::flush();
});

function financeGraph(object $order, array $refunds = [], array $disputes = []): array
{
    $receipt = app(FinancialState::class)->checked(DB::connection(), $order)['basis']['receipt'];
    $refunded = 0;
    foreach ($refunds as $refund) {
        if (! in_array($refund['status'], ['failed', 'canceled'], true)) {
            $refunded += (int) $refund['amount'];
        }
    }

    return ['charge' => ['id' => $receipt['charge_id'], 'paymentIntent' => $receipt['payment_intent_id'], 'currency' => 'USD',
        'amount' => '6300', 'captured' => '6300', 'refunded' => (string) $refunded, 'paid' => true, 'capturedFully' => true,
        'status' => 'succeeded', 'disputed' => $disputes !== [], 'test' => true], 'refunds' => $refunds, 'disputes' => $disputes];
}

function financeRefund(object $order, string $reference, string $status = 'succeeded', string $id = 're_Financial'): array
{
    $row = DB::table(FinancialState::REFUNDS)->where('reference', $reference)->sole();
    $request = json_decode($row->request_payload, true, flags: JSON_THROW_ON_ERROR);
    $receipt = DB::table(PaidOrders::RECEIPTS)->where('order_id', $order->id)->sole();

    return ['id' => $id, 'chargeId' => $receipt->charge_id, 'paymentIntent' => $receipt->payment_intent_id,
        'currency' => 'USD', 'amount' => $request['amount'], 'status' => $status, 'created' => 1792065600, 'metadata' => $request['metadata']];
}

function financeObserve(object $order, array $graph): void
{
    app(OrderLocks::class)->run(fn () => app(FinancialState::class)->observe(DB::connection(), $order, $graph));
}

function financeOriginal(object $order): array
{
    $original = [];
    foreach (['commerce_orders', 'commerce_order_lines', PaidOrders::RECEIPTS, PaidOrders::ALLOCATIONS, AccessFulfillment::WORK, AccessFulfillment::EFFECTS,
        'commerce_stock_movements'] as $table) {
        $query = DB::table($table)->orderBy('id');
        $original[$table] = $query->get()->map(fn ($row) => (array) $row)->all();
    }

    return $original;
}

function financeHeld(Site $site): object
{
    $attempt = PaidFx::attempt($site, null, 3);
    $order = DB::table('commerce_orders')->where('id', $attempt->order_id)->sole();
    $session = PaidFx::session($attempt);
    $session['payment']['refunded'] = '2100';
    app(OrderLocks::class)->run(fn () => app(PaidOrders::class)->confirm(DB::connection(), $order, $attempt, $session));

    return DB::table('commerce_orders')->where('id', $order->id)->sole();
}

function financeExternal(object $order, string $status = 'succeeded'): array
{
    $receipt = DB::table(PaidOrders::RECEIPTS)->where('order_id', $order->id)->sole();

    return ['id' => 're_HeldExternal', 'chargeId' => $receipt->charge_id, 'paymentIntent' => $receipt->payment_intent_id,
        'currency' => 'USD', 'amount' => '2100', 'status' => $status, 'created' => 1792065600, 'metadata' => null];
}

it('records an immediate exact unit suspension without changing any original custody or stock', function (): void {
    $before = financeOriginal($this->order);
    $reference = $this->ledger->requestAsOwner($this->order->reference, [$this->line => 1], 'Buyer returned one unit', str_repeat('a', 32));
    $row = DB::table(FinancialState::REFUNDS)->sole();
    $plan = $this->ledger->accessPlan(DB::connection(), $this->order);
    expect($row->reference)->toBe($reference)->and($row->state)->toBe('owed')->and((string) $row->amount_minor)->toBe('2100')
        ->and($row->first_submitted_at)->toBeNull()->and($plan)->toMatchArray(['blocked' => false, 'removed_units' => [$this->line => 1], 'revision' => 1])
        ->and(financeOriginal($this->order))->toBe($before)->and(DB::table('audit_log')->where('action', 'commerce.refund_requested')->count())->toBe(1);
});

it('uses one request key as exact replay identity and refuses changed consent', function (): void {
    $key = str_repeat('b', 32);
    $reference = $this->ledger->requestAsOwner($this->order->reference, [$this->line => 2], 'Two units returned', $key);
    expect($this->ledger->requestAsOwner($this->order->reference, [$this->line => 2], 'Two units returned', $key))->toBe($reference)
        ->and(fn () => $this->ledger->requestAsOwner($this->order->reference, [$this->line => 1], 'Two units returned', $key))->toThrow(CheckoutRefused::class)
        ->and(DB::table(FinancialState::REFUNDS)->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action', 'commerce.refund_requested')->count())->toBe(1);
});

it('requires current ownership and refuses reader anonymous and editor initiation', function (string $actor): void {
    if ($actor === 'anonymous') {
        Fx::nobody();
    } elseif ($actor === 'member') {
        Fx::member();
    } else {
        Fx::nobody();
        Fx::declareReaders();
        Fx::signIn(Fx::reader());
    }
    expect(fn () => $this->ledger->requestAsOwner($this->order->reference, [$this->line => 1], 'One unit returned', str_repeat('c', 32)))
        ->toThrow(CheckoutRefused::class)->and(DB::table(FinancialState::REFUNDS)->count())->toBe(0);
})->with(['anonymous', 'member', 'reader']);

it('refuses coercible quantities and malformed reasons before recording consent', function (mixed $quantity, string $reason): void {
    expect(fn () => $this->ledger->requestAsOwner($this->order->reference, [$this->line => $quantity], $reason, str_repeat('d', 32)))
        ->toThrow(CheckoutRefused::class)->and(DB::table(FinancialState::REFUNDS)->count())->toBe(0);
})->with([[1.0, 'Valid reason'], ['1', 'Valid reason'], [true, 'Valid reason'], [0, 'Valid reason'], [100, 'Valid reason'], [1, ''], [1, "Bad\nreason"]]);

it('keeps uncertain units reserved and frees them only after a freshly proved failure', function (string $failed): void {
    $first = $this->ledger->requestAsOwner($this->order->reference, [$this->line => 3], 'All units returned', str_repeat('e', 32));
    expect(fn () => $this->ledger->requestAsOwner($this->order->reference, [$this->line => 1], 'Another unit request', str_repeat('f', 32)))
        ->toThrow(CheckoutRefused::class);
    financeObserve($this->order, financeGraph($this->order, [financeRefund($this->order, $first, $failed)]));
    expect($this->ledger->accessPlan(DB::connection(), $this->order)['removed_units'])->toBe([]);
    $next = $this->ledger->requestAsOwner($this->order->reference, [$this->line => 1], 'Explicit fresh request', str_repeat('f', 32));
    expect($next)->not->toBe($first)->and(DB::table(FinancialState::REFUNDS)->count())->toBe(2);
})->with(['failed', 'canceled']);

it('holds a late old success overlapping newly reserved units and blocks POST without losing that proof', function (): void {
    $first = $this->ledger->requestAsOwner($this->order->reference, [$this->line => 1], 'First unit request', str_repeat('1', 32));
    financeObserve($this->order, financeGraph($this->order, [financeRefund($this->order, $first, 'failed')]));
    $second = $this->ledger->requestAsOwner($this->order->reference, [$this->line => 1], 'Explicit later request', str_repeat('2', 32));
    $captured = $this->ledger->claimRefund($this->order->reference, $second);
    $graph = financeGraph($this->order, [financeRefund($this->order, $first)]);
    $captured = app(OrderLocks::class)->run(fn () => $this->ledger->observeForRefund(DB::connection(), $this->order, $captured, $graph));
    expect($this->ledger->accessPlan(DB::connection(), $this->order))->toMatchArray(['blocked' => true, 'category' => 'capacity_conflict'])
        ->and(fn () => app(OrderLocks::class)->run(fn () => $this->ledger->submitting(DB::connection(), $this->order, $captured, str_repeat('a', 64))))
        ->toThrow(CheckoutRefused::class)->and(DB::table(FinancialState::REFUNDS)->where('reference', $first)->value('state'))->toBe('succeeded')
        ->and(DB::table(FinancialState::REFUNDS)->where('reference', $second)->value('first_submitted_at'))->toBeNull();
});

it('holds an external refund until an owner maps exactly the original units and amount', function (): void {
    $receipt = DB::table(PaidOrders::RECEIPTS)->sole();
    $external = ['id' => 're_External', 'chargeId' => $receipt->charge_id, 'paymentIntent' => $receipt->payment_intent_id,
        'currency' => 'USD', 'amount' => '2100', 'status' => 'succeeded', 'created' => 1792065600, 'metadata' => null];
    financeObserve($this->order, financeGraph($this->order, [$external]));
    expect($this->ledger->accessPlan(DB::connection(), $this->order))->toMatchArray(['blocked' => true, 'category' => 'external_refund'])
        ->and(fn () => $this->ledger->attributeAsOwner($this->order->reference, 're_External', [$this->line => 2], 'Map external refund'))
        ->toThrow(CheckoutRefused::class);
    $reference = $this->ledger->attributeAsOwner($this->order->reference, 're_External', [$this->line => 1], 'Map external refund');
    expect($this->ledger->accessPlan(DB::connection(), $this->order))->toMatchArray(['blocked' => false, 'removed_units' => [$this->line => 1]])
        ->and(DB::table(FinancialState::REFUNDS)->where('reference', $reference)->value('request_payload'))->toBeNull();
});

it('combines independent refund and formal dispute blockers and releases only a freshly won dispute', function (): void {
    $reference = $this->ledger->requestAsOwner($this->order->reference, [$this->line => 1], 'One unit returned', str_repeat('3', 32));
    $receipt = DB::table(PaidOrders::RECEIPTS)->sole();
    $dispute = ['id' => 'du_Formal', 'chargeId' => $receipt->charge_id, 'paymentIntent' => $receipt->payment_intent_id,
        'currency' => 'USD', 'amount' => '6300', 'status' => 'needs_response', 'created' => 1792065600, 'test' => true];
    financeObserve($this->order, financeGraph($this->order, [], [$dispute]));
    expect($this->ledger->accessPlan(DB::connection(), $this->order))->toMatchArray(['blocked' => true, 'category' => 'dispute']);
    $dispute['status'] = 'won';
    financeObserve($this->order, financeGraph($this->order, [], [$dispute]));
    expect($this->ledger->accessPlan(DB::connection(), $this->order))->toMatchArray(['blocked' => false, 'removed_units' => [$this->line => 1]])
        ->and(DB::table(FinancialState::REFUNDS)->where('reference', $reference)->value('state'))->toBe('owed');
});

it('keeps inquiry warning statuses informational', function (string $status): void {
    $receipt = DB::table(PaidOrders::RECEIPTS)->sole();
    $dispute = ['id' => 'du_Inquiry', 'chargeId' => $receipt->charge_id, 'paymentIntent' => $receipt->payment_intent_id,
        'currency' => 'USD', 'amount' => '6300', 'status' => $status, 'created' => 1792065600, 'test' => true];
    $graph = financeGraph($this->order, [], [$dispute]);
    $graph['charge']['disputed'] = false;
    financeObserve($this->order, $graph);
    expect($this->ledger->accessPlan(DB::connection(), $this->order)['blocked'])->toBeFalse();
})->with(['warning_needs_response', 'warning_under_review', 'warning_closed']);

it('requires a fresh same-lease whole graph and stores the deadline before the first POST', function (): void {
    $reference = $this->ledger->requestAsOwner($this->order->reference, [$this->line => 1], 'One unit returned', str_repeat('4', 32));
    $captured = $this->ledger->claimRefund($this->order->reference, $reference);
    expect(fn () => app(OrderLocks::class)->run(fn () => $this->ledger->submitting(DB::connection(), $this->order, $captured, str_repeat('a', 64))))
        ->toThrow(CheckoutRefused::class);
    $captured = app(OrderLocks::class)->run(fn () => $this->ledger->observeForRefund(DB::connection(), $this->order, $captured, financeGraph($this->order)));
    $captured = app(OrderLocks::class)->run(fn () => $this->ledger->submitting(DB::connection(), $this->order, $captured, str_repeat('a', 64)));
    $row = DB::table(FinancialState::REFUNDS)->sole();
    expect($row->first_submitted_at)->toBe('2026-10-15 12:00:00')->and($row->state)->toBe('submitting')->and($row->credential_witness)->toBe(str_repeat('a', 64));
    app(OrderLocks::class)->run(fn () => $this->ledger->markUnknown(DB::connection(), $this->order, $captured));
    $again = $this->ledger->claimRefund($this->order->reference, $reference);
    expect($again['request'])->toBe($captured['request'])->and($again['idempotency_key'])->toBe($captured['idempotency_key'])
        ->and(DB::table(FinancialState::REFUNDS)->value('state'))->toBe('unknown');
});

it('vetoes a verified financial signal and invalidates an older in-flight graph claim', function (): void {
    $captured = $this->ledger->claimGraph($this->order->reference);
    $receipt = DB::table(PaidOrders::RECEIPTS)->sole();
    DB::table(FinancialSignals::TABLE)->insert(['org_id' => $this->org->id, 'mode' => 'test', 'event_id' => 'evt_PreConfirmation',
        'event_type' => 'charge.refunded', 'charge_id' => $receipt->charge_id, 'subject_id' => $receipt->charge_id,
        'payload_hash' => str_repeat('a', 64), 'state' => 'unresolved', 'verified_at' => '2026-10-15 12:00:00',
        'received_at' => '2026-10-15 12:00:00', 'resolved_at' => null]);
    expect($this->ledger->accessPlan(DB::connection(), $this->order))->toMatchArray(['blocked' => true, 'category' => 'financial_signal'])
        ->and(fn () => app(OrderLocks::class)->run(fn () => $this->ledger->finishGraph(DB::connection(), $this->order, $captured, financeGraph($this->order))))
        ->toThrow(CheckoutRefused::class);
    CarbonImmutable::setTestNow('2026-10-15 12:03:00 UTC');
    $fresh = $this->ledger->claimGraph($this->order->reference);
    app(OrderLocks::class)->run(fn () => $this->ledger->finishGraph(DB::connection(), $this->order, $fresh, financeGraph($this->order)));
    expect($this->ledger->accessPlan(DB::connection(), $this->order)['blocked'])->toBeFalse()
        ->and(DB::table(FinancialSignals::TABLE)->value('state'))->toBe('resolved');
});

it('never resolves a signed subject missing from the complete account graph', function (): void {
    $receipt = DB::table(PaidOrders::RECEIPTS)->sole();
    DB::table(FinancialSignals::TABLE)->insert(['org_id' => $this->org->id, 'mode' => 'test', 'event_id' => 'evt_UnknownSubject',
        'event_type' => 'refund.created', 'charge_id' => $receipt->charge_id, 'subject_id' => 're_Missing',
        'payload_hash' => str_repeat('b', 64), 'state' => 'unresolved', 'verified_at' => '2026-10-15 12:00:00',
        'received_at' => '2026-10-15 12:00:00', 'resolved_at' => null]);
    expect(fn () => financeObserve($this->order, financeGraph($this->order)))->toThrow(CheckoutRefused::class)
        ->and(DB::table(FinancialSignals::TABLE)->value('state'))->toBe('unresolved');
});

it('fails closed for a missing parent or a foreign planted ledger child', function (string $mutation): void {
    $reference = $this->ledger->requestAsOwner($this->order->reference, [$this->line => 1], 'One unit returned', str_repeat('5', 32));
    if ($mutation === 'parent') {
        DB::table(FinancialState::REFUNDS)->delete();
        DB::table(FinancialState::WORK)->delete();
    } else {
        $foreign = Org::create(['slug' => 'financial-foreign', 'name' => 'Foreign']);
        DB::table(FinancialState::REFUNDS)->where('reference', $reference)->update(['org_id' => $foreign->id]);
    }
    expect(fn () => $this->ledger->accessPlan(DB::connection(), $this->order))->toThrow(CheckoutRefused::class);
})->with(['parent', 'foreign']);

it('rolls back consent when a persisted audit or coherent frozen graph is rewritten by an observer', function (string $mutation): void {
    $dispatcher = AuditLog::getEventDispatcher();
    AuditLog::setEventDispatcher(clone $dispatcher);
    AuditLog::created(function ($audit) use ($mutation): void {
        if ($audit->action !== 'commerce.refund_requested') {
            return;
        }
        if ($mutation === 'audit') {
            DB::table('audit_log')->where('id', $audit->id)->update(['action' => 'commerce.spoofed']);
        } else {
            DB::table(FinancialState::REFUNDS)->update(['reason' => 'Rewritten approved reason']);
        }
    });
    try {
        expect(fn () => $this->ledger->requestAsOwner($this->order->reference, [$this->line => 1], 'One unit returned', str_repeat('6', 32)))
            ->toThrow(CheckoutRefused::class)->and(DB::table(FinancialState::REFUNDS)->count())->toBe(0)
            ->and(DB::table(FinancialState::WORK)->value('revision'))->toBe(0);
    } finally {
        AuditLog::setEventDispatcher($dispatcher);
    }
})->with(['audit', 'graph']);

it('reads JSON semantically and keeps every finance model private scoped and read only', function (): void {
    $reference = $this->ledger->requestAsOwner($this->order->reference, [$this->line => 1], 'One unit returned', str_repeat('7', 32));
    $row = DB::table(FinancialState::REFUNDS)->where('reference', $reference)->sole();
    $selection = json_decode($row->selection, true, flags: JSON_THROW_ON_ERROR);
    ksort($selection[0]);
    DB::table(FinancialState::REFUNDS)->where('id', $row->id)->update(['selection' => json_encode($selection, JSON_THROW_ON_ERROR)]);
    expect($this->ledger->accessPlan(DB::connection(), $this->order)['removed_units'])->toBe([$this->line => 1]);
    foreach ([FinancialWork::class, FinancialRefund::class, FinancialObservation::class] as $class) {
        $model = new $class;
        expect($model->toArray())->toBe([])->and(fn () => $model->save())->toThrow(RuntimeException::class)
            ->and(fn () => $class::query()->update(['id' => 1]))->toThrow(RuntimeException::class);
    }
    app(Context::class)->forget();
    expect(FinancialRefund::query()->count())->toBe(0);
});

it('resolves an originally held payment only by an explicit exact remaining-balance refund without awarding units', function (): void {
    $order = financeHeld($this->site);
    $external = financeExternal($order);
    financeObserve($order, financeGraph($order, [$external]));
    $before = financeOriginal($order);
    $reference = $this->ledger->requestBalanceRefundAsOwner($order->reference, 'Refund the remaining held balance', str_repeat('8', 32));
    $row = DB::table(FinancialState::REFUNDS)->where('reference', $reference)->sole();
    expect($row->purpose)->toBe('held_balance')->and((string) $row->amount_minor)->toBe('4200')
        ->and(json_decode($row->selection, true))->toBe([])
        ->and($this->ledger->requestBalanceRefundAsOwner($order->reference, 'Refund the remaining held balance', str_repeat('8', 32)))->toBe($reference)
        ->and($this->ledger->accessPlan(DB::connection(), $order))->toMatchArray(['blocked' => true, 'category' => 'original_financial_hold', 'removed_units' => []])
        ->and(financeOriginal($order))->toBe($before);
    $captured = $this->ledger->claimRefund($order->reference, $reference);
    $captured = app(OrderLocks::class)->run(fn () => $this->ledger->observeForRefund(DB::connection(), $order, $captured, financeGraph($order, [$external])));
    $captured = app(OrderLocks::class)->run(fn () => $this->ledger->submitting(DB::connection(), $order, $captured, str_repeat('b', 64)));
    app(OrderLocks::class)->run(fn () => $this->ledger->finishRefund(DB::connection(), $order, $captured, financeRefund($order, $reference, id: 're_HeldBalance')));
    expect($this->ledger->accessPlan(DB::connection(), $order)['blocked'])->toBeTrue()
        ->and(DB::table(PaidOrders::ALLOCATIONS)->where('order_id', $order->id)->count())->toBe(0)
        ->and(financeOriginal($order))->toBe($before);
});

it('refuses stale pending disputed or already uncertain balance resolution', function (string $blocker): void {
    $order = financeHeld($this->site);
    $external = financeExternal($order, $blocker === 'pending' ? 'pending' : 'succeeded');
    $graph = financeGraph($order, [$external]);
    if ($blocker === 'dispute') {
        $receipt = DB::table(PaidOrders::RECEIPTS)->where('order_id', $order->id)->sole();
        $graph['disputes'] = [['id' => 'du_HeldFormal', 'chargeId' => $receipt->charge_id, 'paymentIntent' => $receipt->payment_intent_id,
            'currency' => 'USD', 'amount' => '6300', 'status' => 'lost', 'created' => 1792065600, 'test' => true]];
        $graph['charge']['disputed'] = true;
    }
    financeObserve($order, $graph);
    if ($blocker === 'stale') {
        CarbonImmutable::setTestNow('2026-10-15 12:02:00 UTC');
    } elseif ($blocker === 'uncertain') {
        $this->ledger->requestBalanceRefundAsOwner($order->reference, 'First balance resolution', str_repeat('9', 32));
    }
    expect(fn () => $this->ledger->requestBalanceRefundAsOwner($order->reference, 'Another balance resolution', str_repeat('a', 32)))
        ->toThrow(CheckoutRefused::class);
})->with(['pending', 'dispute', 'stale', 'uncertain']);

it('refuses a balance refund for allocated orders and requires an owner for held ones', function (): void {
    financeObserve($this->order, financeGraph($this->order));
    expect(fn () => $this->ledger->requestBalanceRefundAsOwner($this->order->reference, 'Wrong workflow requested', str_repeat('b', 32)))
        ->toThrow(CheckoutRefused::class);
    $order = financeHeld($this->site);
    financeObserve($order, financeGraph($order, [financeExternal($order)]));
    Fx::nobody();
    expect(fn () => $this->ledger->requestBalanceRefundAsOwner($order->reference, 'Unauthorized resolution', str_repeat('c', 32)))
        ->toThrow(CheckoutRefused::class);
});

it('preserves freshly bound provider lifecycle when a later refresh is unavailable', function (string $state): void {
    $reference = $this->ledger->requestAsOwner($this->order->reference, [$this->line => 1], 'One unit returned', str_repeat('d', 32));
    financeObserve($this->order, financeGraph($this->order, [financeRefund($this->order, $reference, $state)]));
    $captured = $this->ledger->claimRefund($this->order->reference, $reference);
    app(OrderLocks::class)->run(fn () => $this->ledger->markUnknown(DB::connection(), $this->order, $captured));
    expect(DB::table(FinancialState::REFUNDS)->value('state'))->toBe($state)
        ->and(DB::table(FinancialState::REFUNDS)->value('category'))->toBe('unavailable')
        ->and($this->ledger->accessPlan(DB::connection(), $this->order)['removed_units'])->toBe($state === 'succeeded' ? [$this->line => 1] : []);
})->with(['succeeded', 'failed', 'canceled']);

it('does not call a never-submitted request an ambiguous money operation after a failed read', function (): void {
    $reference = $this->ledger->requestAsOwner($this->order->reference, [$this->line => 1], 'One unit returned', str_repeat('e', 32));
    $captured = $this->ledger->claimRefund($this->order->reference, $reference);
    app(OrderLocks::class)->run(fn () => $this->ledger->markUnknown(DB::connection(), $this->order, $captured));
    expect(DB::table(FinancialState::REFUNDS)->value('state'))->toBe('owed')
        ->and(DB::table(FinancialState::REFUNDS)->value('first_submitted_at'))->toBeNull();
});

it('serializes new financial graph claims against an active original unavailable-item refund lease', function (): void {
    $attempt = PaidFx::attempt($this->site, '2', 3);
    $order = DB::table('commerce_orders')->where('id', $attempt->order_id)->sole();
    app(OrderLocks::class)->run(fn () => app(PaidOrders::class)->confirm(DB::connection(), $order, $attempt, PaidFx::session($attempt)));
    $order = DB::table('commerce_orders')->where('id', $order->id)->sole();
    DB::table(PaidOrders::ATTEMPTS)->where('order_id', $order->id)->update(['lease_token' => str_repeat('a', 64), 'lease_until' => '2026-10-15 12:01:00']);
    expect(fn () => $this->ledger->claimGraph($order->reference))->toThrow(CheckoutRefused::class)
        ->and(fn () => $this->ledger->requestBalanceRefundAsOwner($order->reference, 'Do not replace old refunds', str_repeat('f', 32)))
        ->toThrow(CheckoutRefused::class)
        ->and(DB::table(FinancialState::WORK)->where('order_id', $order->id)->value('lease_token'))->toBeNull();
});

it('retains an extra same-request provider refund as an external hold without replacing the original binding', function (): void {
    $reference = $this->ledger->requestAsOwner($this->order->reference, [$this->line => 1], 'One unit returned', str_repeat('f', 32));
    $first = financeRefund($this->order, $reference, id: 're_OneIdentity');
    $extra = financeRefund($this->order, $reference, id: 're_UnexpectedSecond');
    financeObserve($this->order, financeGraph($this->order, [$first, $extra]));
    expect(DB::table(FinancialState::OBSERVATIONS)->where('kind', 'refund')->count())->toBe(2)
        ->and(DB::table(FinancialState::REFUNDS)->value('refund_id'))->toBe('re_OneIdentity')
        ->and($this->ledger->accessPlan(DB::connection(), $this->order))->toMatchArray(['blocked' => true, 'category' => 'external_refund']);
    // Provider list ordering is not binding authority after the first exact identity was recorded.
    financeObserve($this->order, financeGraph($this->order, [$extra, $first]));
    expect(DB::table(FinancialState::REFUNDS)->value('refund_id'))->toBe('re_OneIdentity');
});

it('refuses new financial consent when an owner and reader are both signed in without clearing either guard', function (string $action): void {
    $order = $this->order;
    if ($action === 'balance') {
        $order = financeHeld($this->site);
        financeObserve($order, financeGraph($order, [financeExternal($order)]));
    } elseif ($action === 'attribute') {
        financeObserve($order, financeGraph($order, [financeExternal($order)]));
    }
    Fx::declareReaders();
    $reader = Fx::reader();
    auth('web')->setUser($this->owner);
    Fx::signIn($reader);
    expect(Permissions::isOwner($this->owner))->toBeTrue();
    $work = match ($action) {
        'balance' => fn () => $this->ledger->requestBalanceRefundAsOwner($order->reference, 'Dual guard balance request', str_repeat('0', 32)),
        'attribute' => fn () => $this->ledger->attributeAsOwner($order->reference, 're_HeldExternal', [$this->line => 1], 'Dual guard attribution'),
        default => fn () => $this->ledger->requestAsOwner($order->reference, [$this->line => 1], 'Dual guard unit request', str_repeat('0', 32)),
    };
    expect($work)->toThrow(CheckoutRefused::class)->and(DB::table(FinancialState::REFUNDS)->count())->toBe(0)
        ->and(auth('web')->user()?->getAuthIdentifier())->toBe($this->owner->getAuthIdentifier())
        ->and(auth(Fx::GUARD)->user()?->getAuthIdentifier())->toBe($reader->getAuthIdentifier());
})->with(['units', 'attribute', 'balance']);

it('holds aggregate recognized unavailable refunds above their one original obligation', function (): void {
    $attempt = PaidFx::attempt($this->site, '2', 3);
    $order = DB::table('commerce_orders')->where('id', $attempt->order_id)->sole();
    app(OrderLocks::class)->run(fn () => app(PaidOrders::class)->confirm(DB::connection(), $order, $attempt, PaidFx::session($attempt)));
    $order = DB::table('commerce_orders')->where('id', $order->id)->sole();
    $obligation = DB::table(PaidOrders::OBLIGATIONS)->where('order_id', $order->id)->sole();
    $receipt = DB::table(PaidOrders::RECEIPTS)->where('order_id', $order->id)->sole();
    DB::table(PaidOrders::ATTEMPTS)->where('order_id', $order->id)->update(['state' => 'failed', 'refund_id' => 're_AutoOld']);
    app(OrderLocks::class)->run(fn () => app(PaidOrders::class)->newRefundAttempt(DB::connection(), $order, (int) $obligation->id,
        $receipt->charge_id, (string) $obligation->amount_minor));
    $refunds = [];
    foreach (DB::table(PaidOrders::ATTEMPTS)->where('order_id', $order->id)->orderBy('id')->get() as $index => $row) {
        $request = app(PaidOrders::class)->refundRequest($row);
        $refunds[] = ['id' => $index === 0 ? 're_AutoOld' : 're_AutoNew', 'chargeId' => $receipt->charge_id,
            'paymentIntent' => $receipt->payment_intent_id, 'currency' => 'USD', 'amount' => $request['amount'],
            'status' => 'succeeded', 'created' => 1792065600, 'metadata' => $request['metadata']];
    }
    financeObserve($order, financeGraph($order, $refunds));
    expect($this->ledger->accessPlan(DB::connection(), $order))->toMatchArray(['blocked' => true, 'category' => 'capacity_conflict'])
        ->and(DB::table(FinancialState::OBSERVATIONS)->where('order_id', $order->id)->count())->toBe(2);
});
