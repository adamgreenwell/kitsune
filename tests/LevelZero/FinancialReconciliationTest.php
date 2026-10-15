<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Carts\CartAmounts;
use Kitsune\Commerce\Models\FinancialSignal;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Payments\CheckoutRefused;
use Kitsune\Commerce\Payments\FinancialReconciler;
use Kitsune\Commerce\Payments\FinancialSignals;
use Kitsune\Commerce\Payments\FinancialState;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Commerce\Payments\RefundRecovery;
use Kitsune\Commerce\Payments\StripeAmounts;
use Kitsune\Commerce\Payments\StripeCheckout;
use Kitsune\Commerce\Payments\StripeEventInbox;
use Kitsune\Commerce\Payments\StripeFinancialGraph;
use Kitsune\Commerce\Payments\StripeWebhook;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Credentials\Secret;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as Credentials;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\PaidOrderFixture;
use Psr\Http\Message\RequestInterface;

require_once __DIR__.'/../Core/Fixtures/RefundRecoveryFixture.php';

function financialTestSignal(array $seed, string $type = 'refund.updated', string $eventId = 'evt_ExternalRefund', ?string $chargeId = null, ?array $refundMetadata = null, ?string $subjectId = null): array
{
    $refund = str_starts_with($type, 'refund.');
    $dispute = str_starts_with($type, 'charge.dispute.');
    $charge = $chargeId ?? $seed['receipt']->charge_id;
    $object = ['object' => $refund ? 'refund' : ($dispute ? 'dispute' : 'charge'),
        'id' => $subjectId ?? ($refund ? 're_External' : ($dispute ? 'du_External' : $charge)), 'charge' => $charge,
        'livemode' => false, 'metadata' => $refundMetadata ?? (object) [], 'status' => 'private-untrusted-snapshot'];
    $event = ['object' => 'event', 'id' => $eventId, 'type' => $type, 'api_version' => StripeCheckout::API_VERSION,
        'livemode' => false, 'data' => ['object' => $object]];
    $raw = json_encode($event, JSON_THROW_ON_ERROR);
    $now = CarbonImmutable::now('UTC')->getTimestamp();
    $secret = new Secret('whsec_'.str_repeat('s', 32));
    $signature = 't='.$now.',v1='.hash_hmac('sha256', $now.'.'.$raw, $secret->reveal());

    return ['raw' => $raw, 'event' => app(StripeWebhook::class)->verify($raw, $signature, [$secret], $now)];
}

function financialTestProvider(array $seed): object
{
    $control = (object) ['calls' => [], 'refunds' => [], 'disputes' => [], 'status' => 'succeeded', 'account' => $seed['receipt']->account_id,
        'timeout' => false, 'hook' => null, 'objects' => []];
    $http = static function (RequestInterface $request) use ($seed, $control): Response {
        $path = $request->getUri()->getPath();
        $control->calls[] = ['method' => $request->getMethod(), 'path' => $path,
            'level' => DB::connection()->transactionLevel(), 'pdo' => DB::connection()->getPdo()->inTransaction(),
            'key' => $request->getHeaderLine('Idempotency-Key')];
        if ($control->hook !== null) {
            ($control->hook)($path, $request);
        }
        if ($path === '/v1/account') {
            $body = ['object' => 'account', 'id' => $control->account];
        } elseif (str_starts_with($path, '/v1/charges/')) {
            $amount = '0';
            foreach ($control->refunds as $refund) {
                if (! in_array($refund['status'], ['failed', 'canceled'], true)) {
                    $amount = CartAmounts::add($amount, (string) $refund['amount']);
                }
            }
            $wire = StripeAmounts::toWire($seed['receipt']->amount_minor, $seed['receipt']->currency);
            $body = ['object' => 'charge', 'id' => $seed['receipt']->charge_id, 'payment_intent' => $seed['receipt']->payment_intent_id,
                'currency' => strtolower($seed['receipt']->currency), 'livemode' => false, 'amount' => $wire, 'amount_captured' => $wire,
                'amount_refunded' => $amount, 'paid' => true, 'captured' => true, 'disputed' => $control->disputes !== [], 'status' => 'succeeded'];
        } elseif ($path === '/v1/refunds' && $request->getMethod() === 'POST') {
            parse_str((string) $request->getBody(), $payload);
            $saved = DB::table(FinancialState::REFUNDS)->where('reference', $payload['metadata']['kitsune_refund'])->first();
            $selected = $saved !== null;
            $saved ??= DB::table(PaidOrders::ATTEMPTS)->where('reference', $payload['metadata']['kitsune_refund'])->sole();
            expect($saved->first_submitted_at)->not->toBeNull()->and($saved->state)->toBe('submitting')
                ->and($saved->idempotency_key)->toBe($request->getHeaderLine('Idempotency-Key'));
            $body = ['object' => 'refund', 'id' => $selected ? 're_Selected' : 're_Unavailable', 'charge' => $seed['receipt']->charge_id,
                'payment_intent' => $seed['receipt']->payment_intent_id, 'amount' => $payload['amount'],
                'currency' => strtolower($seed['receipt']->currency), 'status' => $control->status, 'created' => 1800000000,
                'metadata' => $payload['metadata']];
            $control->refunds = [...array_values(array_filter($control->refunds, static fn (array $old): bool => $old['id'] !== $body['id'])), $body];
            $control->objects[$body['id']] = $body;
            if ($control->timeout) {
                throw new ConnectException('private ambiguous response', $request);
            }
        } elseif ($path === '/v1/refunds') {
            $body = ['object' => 'list', 'url' => '/v1/refunds', 'has_more' => false, 'data' => $control->refunds];
        } elseif ($path === '/v1/disputes') {
            $body = ['object' => 'list', 'url' => '/v1/disputes', 'has_more' => false, 'data' => $control->disputes];
        } else {
            $objects = [...$control->refunds, ...$control->disputes, ...array_values($control->objects)];
            $body = array_values(array_filter($objects, static fn (array $object): bool => $object['id'] === basename($path)))[0] ?? [];
        }

        return new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    };
    app()->instance(StripeCheckout::class, new StripeCheckout(new Client(['handler' => HandlerStack::create(new MockHandler(array_fill(0, 150, $http)))])));
    app()->forgetInstance(StripeFinancialGraph::class);
    app()->forgetInstance(FinancialReconciler::class);

    return $control;
}

afterEach(function (): void {
    Fx::tearDown();
    CarbonImmutable::setTestNow();
    ModuleKernel::flush();
});

it('retains a minimal unresolved financial signal before any original receipt relation exists', function (): void {
    $seed = refundRecoverySeed();
    $signed = financialTestSignal($seed, chargeId: 'ch_BeforeConfirmation');
    $result = app(StripeEventInbox::class)->capture((int) $seed['org']->id, $signed['event'], $signed['raw'], CarbonImmutable::now('UTC'));
    expect($result)->toBe(['id' => 0, 'duplicate' => false, 'state' => 'unresolved'])
        ->and(DB::table(StripeEventInbox::TABLE)->count())->toBe(0);
    $row = DB::table(FinancialSignals::TABLE)->sole();
    expect($row->charge_id)->toBe('ch_BeforeConfirmation')->and($row->state)->toBe('unresolved')
        ->and((array) $row)->not->toHaveKeys(['raw_payload', 'account_id', 'site_id', 'order_id']);
    $again = app(StripeEventInbox::class)->capture((int) $seed['org']->id, $signed['event'], $signed['raw'], CarbonImmutable::now('UTC'));
    expect($again['duplicate'])->toBeTrue();
    expect(fn () => app(StripeEventInbox::class)->capture((int) $seed['org']->id, $signed['event'], $signed['raw'].' ', CarbonImmutable::now('UTC')))
        ->toThrow(CheckoutRefused::class);
    expect(FinancialSignal::query()->count())->toBe(1);
    expect(fn () => FinancialSignal::query()->sole()->delete())->toThrow(RuntimeException::class);
    $foreign = Org::create(['name' => 'Foreign', 'slug' => 'foreign-signal']);
    app(Context::class)->setOrg($foreign);
    expect(FinancialSignal::query()->count())->toBe(0);
    app(Context::class)->forget();
    expect(FinancialSignal::query()->count())->toBe(0);
});

it('routes financial hints by the original Charge and retains unresolved subject evidence after raw pruning', function (): void {
    $seed = refundRecoverySeed();
    $provider = financialTestProvider($seed);
    $signed = financialTestSignal($seed);
    $inbox = app(StripeEventInbox::class);
    $result = $inbox->capture((int) $seed['org']->id, $signed['event'], $signed['raw'], CarbonImmutable::now('UTC'));
    expect($result['state'])->toBe('pending')->and($result['id'])->toBeGreaterThan(0);
    expect($inbox->process($result['id']))->toBe('blocked');
    expect(DB::table(FinancialSignals::TABLE)->value('state'))->toBe('unresolved');
    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addDays(8));
    expect($inbox->prune())->toBe(1)->and(DB::table(StripeEventInbox::TABLE)->value('raw_payload'))->toBeNull();
    $provider->refunds = [['object' => 'refund', 'id' => 're_External', 'charge' => $seed['receipt']->charge_id,
        'payment_intent' => $seed['receipt']->payment_intent_id, 'currency' => 'usd', 'amount' => 100,
        'status' => 'succeeded', 'created' => 1800000000, 'metadata' => (object) []]];
    expect($inbox->process($result['id']))->toBe('processed');
    expect(DB::table(FinancialSignals::TABLE)->value('state'))->toBe('resolved')
        ->and(DB::table(FinancialState::OBSERVATIONS)->value('origin'))->toBe('external');
    foreach ($provider->calls as $call) {
        expect($call['level'])->toBe(0)->and($call['pdo'])->toBeFalse()->and($call['method'])->toBe('GET');
    }
});

it('submits one frozen owner request and recovers an ambiguous POST after its safe retry window without creating again', function (): void {
    $seed = refundRecoverySeed();
    $provider = financialTestProvider($seed);
    Auth::guard('web')->setUser($seed['owner']);
    Permissions::forget();
    $line = DB::table('commerce_order_lines')->where('order_id', $seed['order']->id)->value('id');
    $reference = app(FinancialState::class)->requestAsOwner($seed['order']->reference, [(int) $line => 1], 'Return one delivered course.', str_repeat('a', 32));
    Fx::nobody();
    $provider->timeout = true;
    expect(fn () => app(FinancialReconciler::class)->submit($seed['order']->reference, $reference))->toThrow(CheckoutRefused::class);
    expect(DB::table(FinancialState::REFUNDS)->value('state'))->toBe('unknown');
    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHours(24));
    $provider->timeout = false;
    $provider->calls = [];
    expect(app(FinancialReconciler::class)->submit($seed['order']->reference, $reference)['state'])->toBe('processed')
        ->and(DB::table(FinancialState::REFUNDS)->value('refund_id'))->toBe('re_Selected')
        ->and(DB::table(FinancialState::REFUNDS)->count())->toBe(1);
    foreach ($provider->calls as $call) {
        expect($call['method'])->toBe('GET')->and($call['level'])->toBe(0)->and($call['pdo'])->toBeFalse();
    }
});

it('stops before another provider read when a new verified signal changes an in-flight graph claim', function (): void {
    $seed = refundRecoverySeed();
    $provider = financialTestProvider($seed);
    $signed = financialTestSignal($seed, 'charge.refunded');
    $provider->hook = static function (string $path) use ($seed, $signed): void {
        if ($path === '/v1/account') {
            app(StripeEventInbox::class)->capture((int) $seed['org']->id, $signed['event'], $signed['raw'], CarbonImmutable::now('UTC'));
        }
    };
    expect(fn () => app(FinancialReconciler::class)->reconcile($seed['order']->reference))->toThrow(CheckoutRefused::class);
    expect(array_column($provider->calls, 'path'))->toBe(['/v1/account'])
        ->and(DB::table(FinancialSignals::TABLE)->value('state'))->toBe('unresolved');
});

it('keeps a financial signal that raced stale initial payment proof as a durable veto after confirmation', function (): void {
    $seed = refundRecoverySeed();
    $attempt = PaidOrderFixture::attempt($seed['site']);
    $session = PaidOrderFixture::session($attempt);
    $charge = $session['payment']['chargeId'];
    $signed = financialTestSignal($seed, 'charge.dispute.created', 'evt_BeforePaidCommit', $charge);
    $captured = app(StripeEventInbox::class)->capture((int) $seed['org']->id, $signed['event'], $signed['raw'], CarbonImmutable::now('UTC'));
    expect($captured['state'])->toBe('unresolved');
    $order = DB::table('commerce_orders')->where('id', $attempt->order_id)->sole();
    // Its earlier provider GET showed no dispute. The signal has committed before this paid transaction.
    app(OrderLocks::class)->run(fn () => app(PaidOrders::class)->confirm(DB::connection(), $order, $attempt, $session));
    $paid = DB::table('commerce_orders')->where('id', $order->id)->sole();
    $plan = app(FinancialState::class)->accessPlan(DB::connection(), $paid);
    expect($plan['blocked'])->toBeTrue()->and($plan['category'])->toBe('financial_signal')
        ->and(DB::table(FinancialSignals::TABLE)->value('state'))->toBe('unresolved');
});

it('treats unknown local-looking metadata as an external Charge-bound signal without adopting a refund request', function (): void {
    $seed = refundRecoverySeed();
    $signed = financialTestSignal($seed);
    $signed['event'] += ['refundId' => 're_External', 'orderReference' => $seed['order']->reference,
        'refundReference' => str_repeat('f', 32), 'requestDigest' => str_repeat('b', 64)];
    $result = app(StripeEventInbox::class)->capture((int) $seed['org']->id, $signed['event'], $signed['raw'], CarbonImmutable::now('UTC'));
    expect($result['state'])->toBe('pending')->and(DB::table(FinancialState::REFUNDS)->count())->toBe(0)
        ->and(DB::table(StripeEventInbox::TABLE)->value('refund_reference'))->toBeNull()
        ->and(DB::table(FinancialSignals::TABLE)->value('state'))->toBe('unresolved');
});

it('refreshes late refund failure without changing the immutable original payment or availability decision', function (): void {
    $seed = refundRecoverySeed();
    $provider = financialTestProvider($seed);
    auth('web')->setUser($seed['owner']);
    Fx::forget();
    $line = (int) DB::table('commerce_order_lines')->where('order_id', $seed['order']->id)->value('id');
    $before = [(array) DB::table(PaidOrders::RECEIPTS)->sole(), DB::table(PaidOrders::ALLOCATIONS)->get()->toArray()];
    $reference = app(FinancialState::class)->requestAsOwner($seed['order']->reference, [$line => 1], 'Refund delivered unit.', str_repeat('b', 32));
    Fx::nobody();
    app(FinancialReconciler::class)->submit($seed['order']->reference, $reference);
    expect(app(FinancialState::class)->accessPlan(DB::connection(), $seed['order'])['removed_units'])->toBe([$line => 1]);
    $provider->refunds[0]['status'] = 'failed';
    app(FinancialReconciler::class)->reconcile($seed['order']->reference);
    expect(DB::table(FinancialState::REFUNDS)->value('state'))->toBe('failed')
        ->and(app(FinancialState::class)->accessPlan(DB::connection(), $seed['order'])['removed_units'])->toBe([])
        ->and([(array) DB::table(PaidOrders::RECEIPTS)->sole(), DB::table(PaidOrders::ALLOCATIONS)->get()->toArray()])->toEqual($before);
});

it('uses current multiple-dispute outcomes and allows a lost dispute to become won without restarting terms', function (): void {
    $seed = refundRecoverySeed();
    $provider = financialTestProvider($seed);
    $provider->disputes = [['id' => 'du_One', 'object' => 'dispute', 'charge' => $seed['receipt']->charge_id,
        'payment_intent' => $seed['receipt']->payment_intent_id, 'livemode' => false, 'currency' => 'usd',
        'amount' => 9000, 'status' => 'lost', 'created' => 1800000000]];
    expect(app(FinancialReconciler::class)->reconcile($seed['order']->reference)['state'])->toBe('held');
    $provider->disputes[0]['status'] = 'won';
    $provider->disputes[] = array_replace($provider->disputes[0], ['id' => 'du_Two', 'status' => 'under_review']);
    expect(app(FinancialReconciler::class)->reconcile($seed['order']->reference)['state'])->toBe('held');
    $provider->disputes[1]['status'] = 'won';
    expect(app(FinancialReconciler::class)->reconcile($seed['order']->reference)['state'])->toBe('processed')
        ->and(DB::table(FinancialState::OBSERVATIONS)->orderBy('object_id')->pluck('state')->all())->toBe(['won', 'won']);
});

it('refuses a fresh POST after the unknown identity window and retains the original request', function (): void {
    $seed = refundRecoverySeed();
    $provider = financialTestProvider($seed);
    auth('web')->setUser($seed['owner']);
    Fx::forget();
    $line = (int) DB::table('commerce_order_lines')->where('order_id', $seed['order']->id)->value('id');
    $reference = app(FinancialState::class)->requestAsOwner($seed['order']->reference, [$line => 1], 'Refund one unit.', str_repeat('c', 32));
    Fx::nobody();
    $provider->timeout = true;
    expect(fn () => app(FinancialReconciler::class)->submit($seed['order']->reference, $reference))->toThrow(CheckoutRefused::class);
    $key = DB::table(FinancialState::REFUNDS)->value('idempotency_key');
    $provider->refunds = [];
    $provider->timeout = false;
    $provider->calls = [];
    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHours(23));
    expect(fn () => app(FinancialReconciler::class)->submit($seed['order']->reference, $reference))->toThrow(CheckoutRefused::class);
    expect(array_unique(array_column($provider->calls, 'method')))->toBe(['GET'])
        ->and(DB::table(FinancialState::REFUNDS)->count())->toBe(1)
        ->and(DB::table(FinancialState::REFUNDS)->value('idempotency_key'))->toBe($key);
});

it('requires explicit fresh owner recovery after mode or original witness changes and keeps current configuration intact', function (string $scenario): void {
    $seed = refundRecoverySeed();
    $provider = financialTestProvider($seed);
    if ($scenario === 'mode') {
        DB::table('org_credential_modes')->updateOrInsert(['org_id' => $seed['org']->id], ['mode' => 'live', 'changed_at' => '2026-10-15 12:00:00']);
    } else {
        DB::table('credentials')->where('slot', StripeCheckout::SLOT)->update(['changed_at' => '2026-10-15 12:00:01']);
    }
    $before = [(array) DB::table('credentials')->where('slot', StripeCheckout::SLOT)->sole(), DB::table('org_credential_modes')->get()->toArray()];
    expect(fn () => app(FinancialReconciler::class)->reconcile($seed['order']->reference))->toThrow(CheckoutRefused::class);
    expect($provider->calls)->toBe([]);
    auth('web')->setUser($seed['owner']);
    Fx::forget();
    expect(app(FinancialReconciler::class)->recoverAsOwner($seed['order']->reference, new Secret('sk_test_'.str_repeat('b', 32)), Credentials::PASSWORD)['state'])->toBe('processed');
    expect([(array) DB::table('credentials')->where('slot', StripeCheckout::SLOT)->sole(), DB::table('org_credential_modes')->get()->toArray()])->toEqual($before);
})->with(['mode', 'witness']);

it('keeps unavailable-unit refund recovery alongside exactly recognized selected-unit refunds without double stock effects', function (): void {
    $seed = refundRecoverySeed();
    $provider = financialTestProvider($seed);
    auth('web')->setUser($seed['owner']);
    Fx::forget();
    $line = (int) DB::table('commerce_order_lines')->where('order_id', $seed['order']->id)->value('id');
    $reference = app(FinancialState::class)->requestAsOwner($seed['order']->reference, [$line => 1], 'Return one delivered unit.', str_repeat('d', 32));
    Fx::nobody();
    $reconciler = app(FinancialReconciler::class);
    $reconciler->submit($seed['order']->reference, $reference);
    $before = [(array) DB::table(PaidOrders::RECEIPTS)->sole(), DB::table(PaidOrders::ALLOCATIONS)->get()->toArray(),
        DB::table('commerce_sellables')->pluck('stock')->all()];
    expect(app(RefundRecovery::class)->reconcile($seed['order']->reference, $seed['row']->reference)['state'])->toBe('succeeded');
    expect($reconciler->reconcile($seed['order']->reference)['state'])->toBe('processed')
        ->and(app(FinancialState::class)->accessPlan(DB::connection(), $seed['order'])['removed_units'])->toBe([$line => 1]);
    expect(DB::table(FinancialState::OBSERVATIONS)->orderBy('origin')->pluck('origin')->all())->toBe(['operator', 'unavailable'])
        ->and([(array) DB::table(PaidOrders::RECEIPTS)->sole(), DB::table(PaidOrders::ALLOCATIONS)->get()->toArray(),
            DB::table('commerce_sellables')->pluck('stock')->all()])->toEqual($before);
    $posts = array_values(array_filter($provider->calls, static fn (array $call): bool => $call['method'] === 'POST'));
    expect($posts)->toHaveCount(2)->and($posts[0]['key'])->not->toBe($posts[1]['key']);
    foreach ($provider->calls as $call) {
        expect($call['level'])->toBe(0)->and($call['pdo'])->toBeFalse();
    }
});

it('rechecks configured financial authority after the final ledger audit callback before committing', function (string $phase, string $mutation): void {
    $seed = refundRecoverySeed();
    $provider = financialTestProvider($seed);
    $reference = null;
    if ($phase !== 'graph') {
        auth('web')->setUser($seed['owner']);
        Fx::forget();
        $line = (int) DB::table('commerce_order_lines')->where('order_id', $seed['order']->id)->value('id');
        $reference = app(FinancialState::class)->requestAsOwner($seed['order']->reference, [$line => 1], 'Audit callback regression.', str_repeat('e', 32));
        Fx::nobody();
    }
    AuditLog::created(static function (AuditLog $audit) use ($seed, $phase, $mutation): void {
        if ($audit->action !== ($phase === 'refund' ? 'commerce.financial_refund_observed' : 'commerce.financial_observed')) {
            return;
        }
        if ($mutation === 'mode') {
            DB::table('org_credential_modes')->updateOrInsert(['org_id' => $seed['org']->id], ['mode' => 'live', 'changed_at' => '2026-10-15 12:00:00']);
        } else {
            DB::table('credentials')->where('slot', StripeCheckout::SLOT)->update(['changed_at' => '2026-10-15 12:00:01']);
        }
    });
    expect(fn () => $reference === null
        ? app(FinancialReconciler::class)->reconcile($seed['order']->reference)
        : app(FinancialReconciler::class)->submit($seed['order']->reference, $reference))->toThrow(CheckoutRefused::class);
    if ($phase === 'refund') {
        expect(DB::table(FinancialState::REFUNDS)->value('refund_id'))->toBeNull()
            ->and(DB::table(FinancialState::REFUNDS)->value('state'))->toBe($mutation === 'credential' ? 'held' : 'unknown');
    } elseif ($phase === 'before_refund') {
        expect(DB::table(FinancialState::WORK)->value('last_observed_at'))->toBeNull()
            ->and(DB::table(FinancialState::REFUNDS)->value('state'))->toBe('owed')
            ->and(array_unique(array_column($provider->calls, 'method')))->toBe(['GET']);
    } else {
        expect(DB::table(FinancialState::WORK)->value('last_observed_at'))->toBeNull();
    }
})->with([['graph', 'mode'], ['graph', 'credential'], ['refund', 'mode'], ['refund', 'credential'],
    ['before_refund', 'mode'], ['before_refund', 'credential']]);

it('never POSTs a verified selected refund identity omitted from a list and recovers its minimal hint after raw expiry', function (): void {
    $seed = refundRecoverySeed();
    $provider = financialTestProvider($seed);
    auth('web')->setUser($seed['owner']);
    Fx::forget();
    $line = (int) DB::table('commerce_order_lines')->where('order_id', $seed['order']->id)->value('id');
    $reference = app(FinancialState::class)->requestAsOwner($seed['order']->reference, [$line => 1], 'Recover exact refund identity.', str_repeat('f', 32));
    Fx::nobody();
    $provider->timeout = true;
    expect(fn () => app(FinancialReconciler::class)->submit($seed['order']->reference, $reference))->toThrow(CheckoutRefused::class);
    $local = DB::table(FinancialState::REFUNDS)->sole();
    $request = json_decode($local->request_payload, true, flags: JSON_THROW_ON_ERROR);
    $signed = financialTestSignal($seed, 'refund.created', 'evt_SelectedKnown', refundMetadata: $request['metadata'], subjectId: 're_Selected');
    $event = app(StripeEventInbox::class)->capture((int) $seed['org']->id, $signed['event'], $signed['raw'], CarbonImmutable::now('UTC'));
    $provider->timeout = false;
    $provider->refunds = [];
    $provider->calls = [];
    expect(fn () => app(FinancialReconciler::class)->submit($seed['order']->reference, $reference))->toThrow(CheckoutRefused::class);
    expect(array_column($provider->calls, 'path'))->toContain('/v1/refunds/re_Selected')
        ->and(array_unique(array_column($provider->calls, 'method')))->toBe(['GET'])
        ->and(DB::table(FinancialState::REFUNDS)->value('idempotency_key'))->toBe($local->idempotency_key);
    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addDays(8));
    expect(app(StripeEventInbox::class)->prune())->toBe(1);
    $provider->refunds = array_values($provider->objects);
    $provider->calls = [];
    expect(app(FinancialReconciler::class)->submit($seed['order']->reference, $reference)['state'])->toBe('processed')
        ->and(DB::table(FinancialState::REFUNDS)->value('refund_id'))->toBe('re_Selected')
        ->and(DB::table(StripeEventInbox::TABLE)->where('id', $event['id'])->value('raw_payload'))->toBeNull();
    expect(array_unique(array_column($provider->calls, 'method')))->toBe(['GET']);
});
