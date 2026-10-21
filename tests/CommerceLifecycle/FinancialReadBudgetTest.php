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
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Payments\CheckoutRefused;
use Kitsune\Commerce\Payments\FinancialReconciler;
use Kitsune\Commerce\Payments\FinancialState;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Commerce\Payments\StripeAmounts;
use Kitsune\Commerce\Payments\StripeCheckout;
use Kitsune\Commerce\Payments\StripeFinancialGraph;
use Kitsune\Commerce\Payments\StripeReadBudget;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Psr\Http\Message\RequestInterface;

require_once __DIR__.'/../Core/Fixtures/RefundRecoveryFixture.php';

afterEach(function (): void {
    Fx::tearDown();
    CarbonImmutable::setTestNow();
    ModuleKernel::flush();
});

function financialBudgetProvider(array $seed): object
{
    $control = (object) ['calls' => [], 'hook' => null];
    $http = static function (RequestInterface $request, array $options) use ($seed, $control): Response {
        $path = $request->getUri()->getPath();
        $control->calls[] = ['path' => $path, 'method' => $request->getMethod(),
            'level' => DB::connection()->transactionLevel(), 'pdo' => DB::connection()->getPdo()->inTransaction(),
            'timeout' => $options['timeout']];
        if ($control->hook !== null) {
            ($control->hook)();
        }
        $r = $seed['receipt'];
        $amount = StripeAmounts::toWire($r->amount_minor, $r->currency);
        $body = match ($path) {
            '/v1/account' => ['object' => 'account', 'id' => $r->account_id],
            '/v1/charges/'.$r->charge_id => ['object' => 'charge', 'id' => $r->charge_id,
                'payment_intent' => $r->payment_intent_id, 'currency' => strtolower($r->currency), 'livemode' => false,
                'amount' => $amount, 'amount_captured' => $amount, 'amount_refunded' => 0,
                'paid' => true, 'captured' => true, 'disputed' => false, 'status' => 'succeeded'],
            '/v1/refunds', '/v1/disputes' => ['object' => 'list', 'url' => $path, 'has_more' => false, 'data' => []],
            default => throw new RuntimeException('Unexpected budget fixture request.'),
        };

        return new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    };
    app()->instance(StripeCheckout::class, new StripeCheckout(new Client(['handler' => HandlerStack::create(new MockHandler(array_fill(0, 20, $http)))])));
    app()->forgetInstance(StripeFinancialGraph::class);
    app()->forgetInstance(FinancialReconciler::class);

    return $control;
}

it('bounds the complete financial status read and retries with a fresh allowance without repeating financial effects', function (): void {
    $seed = refundRecoverySeed();
    $provider = financialBudgetProvider($seed);
    $clock = (object) ['now' => 0.0];
    app()->bind(StripeReadBudget::class, static fn () => new StripeReadBudget(static fn (): float => $clock->now));
    $tables = ['commerce_orders', 'commerce_order_lines', PaidOrders::RECEIPTS, PaidOrders::ALLOCATIONS, PaidOrders::OBLIGATIONS, PaidOrders::ATTEMPTS];
    $before = array_map(static fn (string $table): array => DB::table($table)->orderBy('id')->get()->toArray(), $tables);
    $provider->hook = static function () use ($clock): void {
        $clock->now += 9;
    };
    expect(fn () => app(FinancialReconciler::class)->reconcile($seed['order']->reference))->toThrow(CheckoutRefused::class);
    expect(array_column($provider->calls, 'path'))->toBe(['/v1/account', '/v1/charges/'.$seed['receipt']->charge_id, '/v1/refunds'])
        ->and(array_column($provider->calls, 'timeout'))->toBe([10, 10, 2.0])
        ->and(DB::table(FinancialState::WORK)->value('lease_until'))->toBeNull()
        ->and(DB::table(FinancialState::WORK)->value('last_observed_at'))->toBeNull()
        ->and(DB::table(FinancialState::OBSERVATIONS)->count())->toBe(0)
        ->and(array_map(static fn (string $table): array => DB::table($table)->orderBy('id')->get()->toArray(), $tables))->toEqual($before);
    foreach ($provider->calls as $call) {
        expect($call['level'])->toBe(0)->and($call['pdo'])->toBeFalse()->and($call['method'])->toBe('GET');
    }
    $provider->calls = [];
    $provider->hook = null;
    expect(app(FinancialReconciler::class)->reconcile($seed['order']->reference)['state'])->toBe('processed')
        ->and(count($provider->calls))->toBe(5)
        ->and(DB::table(FinancialState::WORK)->value('lease_until'))->toBeNull()
        ->and(array_map(static fn (string $table): array => DB::table($table)->orderBy('id')->get()->toArray(), $tables))->toEqual($before);
});

it('rolls back a financial observation whose final host callback exhausts the status allowance', function (): void {
    $seed = refundRecoverySeed();
    financialBudgetProvider($seed);
    $clock = (object) ['now' => 0.0];
    app()->bind(StripeReadBudget::class, static fn () => new StripeReadBudget(static fn (): float => $clock->now));
    AuditLog::created(static function (AuditLog $audit) use ($clock): void {
        if ($audit->action === 'commerce.financial_observed') {
            $clock->now = 20.0;
        }
    });
    expect(fn () => app(FinancialReconciler::class)->reconcile($seed['order']->reference))->toThrow(CheckoutRefused::class);
    expect(DB::table(FinancialState::WORK)->value('last_observed_at'))->toBeNull()
        ->and(DB::table(FinancialState::WORK)->value('lease_until'))->toBeNull()
        ->and(DB::table(FinancialState::OBSERVATIONS)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'commerce.financial_observed')->count())->toBe(0);
});
