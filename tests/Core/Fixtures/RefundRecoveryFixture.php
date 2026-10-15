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
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Payments\CheckoutAttempts;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Commerce\Payments\RefundRecovery;
use Kitsune\Commerce\Payments\StripeAmounts;
use Kitsune\Commerce\Payments\StripeCheckout;
use Kitsune\Core\Credentials\CredentialConsoleAuthority;
use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Credentials\CredentialWriter;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Modules\ModuleLifecycle;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as Credentials;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\PaidOrderFixture;
use Psr\Http\Message\RequestInterface;

/** Real primary commits and real parser/network boundary on disposable SQLite, with only outbound HTTP replaced. */
function refundRecoverySeed(string $currency = 'USD'): array
{
    Credentials::boot();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    ModuleKernel::flush();
    ModuleLifecycle::install(app(), 'kitsune/commerce');
    ModuleLifecycle::enable(app(), 'kitsune/commerce');
    ModuleKernel::boot(app());
    $org = Org::create(['name' => 'Refund recovery', 'slug' => 'refund-recovery', 'currency' => $currency]);
    app(Context::class)->setOrg($org);
    $site = Site::create(['handle' => 'main', 'slug' => 'refund-recovery-main', 'name' => 'Main', 'locale' => 'en', 'base_url' => 'https://refund.example.test']);
    app(Context::class)->setSite($site);
    $owner = Fx::owner();
    Fx::nobody();
    CredentialConsoleAuthority::run(app(Context::class), 'set', StripeCheckout::SLOT, CredentialMode::Test, null,
        static fn (CredentialConsoleAuthority $authority) => app(CredentialWriter::class)->set(StripeCheckout::SLOT, CredentialMode::Test,
            'sk_test_'.str_repeat('a', 32), console: $authority));
    $attempt = PaidOrderFixture::attempt($site);
    $credential = DB::table('credentials')->where('slot', StripeCheckout::SLOT)->sole();
    $witness = hash('sha256', implode("\0", [(string) $credential->id, (string) $credential->org_id, $credential->slot,
        $credential->mode, $credential->ciphertext, $credential->key_id, $credential->changed_at]));
    DB::table(CheckoutAttempts::TABLE)->where('id', $attempt->id)->update(['credential_witness' => $witness]);
    $attempt = DB::table(CheckoutAttempts::TABLE)->where('id', $attempt->id)->sole();
    $order = DB::table('commerce_orders')->where('id', $attempt->order_id)->sole();
    app(OrderLocks::class)->run(fn () => app(PaidOrders::class)->confirm(DB::connection(), $order, $attempt, PaidOrderFixture::session($attempt)));
    $order = DB::table('commerce_orders')->where('id', $attempt->order_id)->sole();
    $row = DB::table(PaidOrders::ATTEMPTS)->where('order_id', $order->id)->sole();
    $receipt = DB::table(PaidOrders::RECEIPTS)->where('order_id', $order->id)->sole();

    return compact('org', 'site', 'owner', 'attempt', 'order', 'row', 'receipt');
}

function refundRecoveryProvider(array $seed): object
{
    $control = (object) ['calls' => [], 'refunds' => [], 'status' => 'succeeded', 'chargeRefunded' => 0,
        'account' => $seed['receipt']->account_id, 'more' => false, 'timeout' => false, 'hook' => null];
    $http = static function (RequestInterface $request) use ($seed, $control): Response {
        $path = $request->getUri()->getPath();
        $control->calls[] = ['method' => $request->getMethod(), 'path' => $path, 'level' => DB::connection()->transactionLevel(),
            'pdo' => DB::connection()->getPdo()->inTransaction(), 'version' => $request->getHeaderLine('Stripe-Version'),
            'key' => $request->getHeaderLine('Idempotency-Key')];
        if ($control->hook !== null) {
            ($control->hook)($path, $request);
        }
        if ($path === '/v1/account') {
            $body = ['object' => 'account', 'id' => $control->account];
        } elseif (str_starts_with($path, '/v1/charges/')) {
            $body = ['object' => 'charge', 'id' => $seed['receipt']->charge_id, 'payment_intent' => $seed['receipt']->payment_intent_id,
                'livemode' => false, 'paid' => true, 'captured' => true, 'status' => 'succeeded', 'currency' => strtolower($seed['receipt']->currency),
                'amount' => StripeAmounts::toWire($seed['receipt']->amount_minor, $seed['receipt']->currency),
                'amount_captured' => StripeAmounts::toWire($seed['receipt']->amount_minor, $seed['receipt']->currency),
                'amount_refunded' => $control->chargeRefunded, 'disputed' => false];
        } elseif ($path === '/v1/refunds' && $request->getMethod() === 'GET') {
            $body = ['object' => 'list', 'url' => '/v1/refunds', 'has_more' => $control->more, 'data' => $control->refunds];
        } elseif ($path === '/v1/refunds') {
            parse_str((string) $request->getBody(), $payload);
            $saved = DB::table(PaidOrders::ATTEMPTS)->where('reference', $payload['metadata']['kitsune_refund'])->sole();
            expect($saved->first_submitted_at)->not->toBeNull()->and($saved->state)->toBe('submitting')
                ->and($saved->idempotency_key)->toBe($request->getHeaderLine('Idempotency-Key'));
            $body = refundRecoveryObject($seed, $saved, $control->status);
            $control->refunds = [$body];
            if ($control->timeout) {
                throw new ConnectException('private provider timeout', $request);
            }
        } else {
            $current = DB::table(PaidOrders::ATTEMPTS)->where('refund_id', basename($path))->first() ?? $seed['row'];
            $body = refundRecoveryObject($seed, $current, $control->status, basename($path));
        }

        return new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    };
    app()->instance(StripeCheckout::class, new StripeCheckout(new Client(['handler' => HandlerStack::create(new MockHandler(array_fill(0, 100, $http)))])));
    app()->forgetInstance(RefundRecovery::class);

    return $control;
}

function refundRecoveryObject(array $seed, object $attempt, string $status = 'succeeded', string $id = 're_Recovery'): array
{
    $payload = app(PaidOrders::class)->refundRequest($attempt);

    return ['object' => 'refund', 'id' => $attempt->refund_id ?? $id, 'charge' => $seed['receipt']->charge_id,
        'payment_intent' => $seed['receipt']->payment_intent_id, 'currency' => strtolower($seed['receipt']->currency), 'amount' => $payload['amount'],
        'status' => $status, 'created' => CarbonImmutable::now('UTC')->getTimestamp(), 'metadata' => $payload['metadata']];
}

function refundRecoveryAssertOutside(object $provider): void
{
    foreach ($provider->calls as $call) {
        expect($call['level'])->toBe(0)->and($call['pdo'])->toBeFalse()->and($call['version'])->toBe(StripeCheckout::API_VERSION);
    }
}
