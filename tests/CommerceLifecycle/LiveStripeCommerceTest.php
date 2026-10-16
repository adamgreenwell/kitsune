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
use Kitsune\Commerce\Blueprints\StorefrontBlueprint;
use Kitsune\Commerce\Carts\CartWriter;
use Kitsune\Commerce\Catalogue\ProductWriter;
use Kitsune\Commerce\Checkout\CommerceSetupWriter;
use Kitsune\Commerce\Exports\OrderHistoryExport;
use Kitsune\Commerce\Orders\OrderQuotes;
use Kitsune\Commerce\Orders\OrderWriter;
use Kitsune\Commerce\Payments\AccessAdjustments;
use Kitsune\Commerce\Payments\AccessFulfillment;
use Kitsune\Commerce\Payments\CheckoutAttempts;
use Kitsune\Commerce\Payments\CheckoutReconciler;
use Kitsune\Commerce\Payments\CheckoutRefused;
use Kitsune\Commerce\Payments\CheckoutReview;
use Kitsune\Commerce\Payments\CheckoutWriter;
use Kitsune\Commerce\Payments\FinancialReconciler;
use Kitsune\Commerce\Payments\FinancialSignals;
use Kitsune\Commerce\Payments\FinancialState;
use Kitsune\Commerce\Payments\LiveStripeAdmission;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Commerce\Payments\RefundRecovery;
use Kitsune\Commerce\Payments\StripeCheckout;
use Kitsune\Commerce\Payments\StripeEventInbox;
use Kitsune\Commerce\Payments\StripeFinancialGraph;
use Kitsune\Commerce\Payments\StripeWebhook;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Credentials\CredentialWriter;
use Kitsune\Core\Credentials\Secret;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as Credentials;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\OrderFixture;
use Psr\Http\Message\RequestInterface;

require_once __DIR__.'/../Core/Fixtures/LiveStripeFixture.php';

function liveCommerceSeed(bool $admit = true, string $price = '2000'): array
{
    $seed = liveAdmissionSeed();
    $seed['org']->update(['currency' => 'USD']);
    if ($admit) {
        app(LiveStripeAdmission::class)->admit(null, Credentials::PASSWORD);
    }
    BlueprintApplier::apply(new StorefrontBlueprint);
    $product = app(ProductWriter::class)->createProduct(['sku' => 'LIVE-COURSE', 'price_minor' => $price, 'stock' => '5',
        'entitlement_name' => 'course.purchased', 'offer_terms' => ['version' => 1, 'kind' => 'fixed', 'days' => 30]], 'USD',
        ['title' => 'Live course', 'slug' => 'live-course', 'status' => 'published', 'values' => []], [], true);
    $setup = app(CommerceSetupWriter::class);
    $setup->setSettings(['tax_mode' => 'exclusive', 'shipping_tax_mode' => 'exclusive', 'shipping_tax_class' => 'shipping']);
    $setup->saveTaxRate(null, ['country' => 'US', 'region' => '*', 'tax_class' => 'standard', 'rate_parts' => 50000]);
    $setup->setSalesPolicy($product->getKey(), ['tax_class' => 'standard', 'delivery_kind' => 'digital']);
    Fx::declareReaders();
    Auth::guard('web')->setUser($seed['owner']);
    Permissions::forget();
    $reader = Fx::reader();
    $request = OrderFixture::request();
    Fx::signIn($reader);
    app(CartWriter::class)->add($request, $product->listings()->sole()->getKey(), 2);
    $quote = app(OrderQuotes::class)->quote($request, ['email' => 'live-buyer@example.test', 'billing' => OrderFixture::billing()]);
    $order = app(OrderWriter::class)->prepare($request, $quote['token'], 'none');
    $review = app(CheckoutReview::class)->review($request, $order->reference);
    $provider = (object) ['calls' => [], 'session' => null, 'paid' => false, 'refunds' => [], 'disputes' => [], 'hook' => null,
        'account' => 'acct_LiveFixture', 'mode' => true, 'timeout' => false];
    $http = static function (RequestInterface $outbound) use ($provider): Response {
        $path = $outbound->getUri()->getPath();
        expect(DB::connection()->transactionLevel())->toBe(0)->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
        $provider->calls[] = ['method' => $outbound->getMethod(), 'path' => $path, 'key' => $outbound->getHeaderLine('Idempotency-Key')];
        if ($provider->hook !== null) {
            ($provider->hook)($path);
        }
        if ($path === '/v1/account') {
            $body = ['object' => 'account', 'id' => $provider->account, 'charges_enabled' => true, 'payouts_enabled' => true, 'details_submitted' => true];
        } elseif ($path === '/v1/checkout/sessions') {
            parse_str((string) $outbound->getBody(), $payload);
            $body = ['object' => 'checkout.session', 'id' => 'cs_live_Lifecycle', 'mode' => 'payment', 'livemode' => $provider->mode,
                'status' => 'open', 'payment_status' => 'unpaid', 'currency' => 'usd', 'amount_total' => $payload['line_items'][0]['price_data']['unit_amount'],
                'expires_at' => (int) $payload['expires_at'], 'client_reference_id' => $payload['client_reference_id'], 'metadata' => $payload['metadata'],
                'url' => 'https://checkout.stripe.com/c/pay/LiveFixture', 'payment_intent' => null];
            $provider->session = $body;
            if ($provider->timeout) {
                throw new ConnectException('Private ambiguous provider response', $outbound);
            }
        } elseif (str_starts_with($path, '/v1/checkout/sessions/')) {
            $body = $provider->session;
            if ($provider->paid) {
                $amount = $body['amount_total'];
                $body = [...$body, 'status' => 'complete', 'payment_status' => 'paid', 'url' => null,
                    'payment_intent' => ['object' => 'payment_intent', 'id' => 'pi_Lifecycle', 'livemode' => $provider->mode,
                        'status' => 'succeeded', 'amount' => $amount, 'amount_received' => $amount, 'currency' => 'usd',
                        'latest_charge' => ['object' => 'charge', 'id' => 'ch_Lifecycle', 'livemode' => $provider->mode, 'payment_intent' => 'pi_Lifecycle',
                            'amount' => $amount, 'amount_captured' => $amount, 'amount_refunded' => 0, 'currency' => 'usd',
                            'paid' => true, 'captured' => true, 'status' => 'succeeded', 'disputed' => false]]];
            }
        } elseif (str_starts_with($path, '/v1/charges/')) {
            $total = array_sum(array_column($provider->refunds, 'amount'));
            $amount = $provider->session['amount_total'];
            $body = ['object' => 'charge', 'id' => 'ch_Lifecycle', 'payment_intent' => 'pi_Lifecycle', 'livemode' => $provider->mode,
                'paid' => true, 'captured' => true, 'status' => 'succeeded', 'currency' => 'usd', 'amount' => $amount,
                'amount_captured' => $amount, 'amount_refunded' => $total, 'disputed' => $provider->disputes !== []];
        } elseif ($path === '/v1/refunds' && $outbound->getMethod() === 'POST') {
            parse_str((string) $outbound->getBody(), $payload);
            $body = ['object' => 'refund', 'id' => 're_'.count($provider->refunds), 'charge' => 'ch_Lifecycle', 'payment_intent' => 'pi_Lifecycle',
                'amount' => $payload['amount'], 'currency' => 'usd', 'status' => 'succeeded', 'created' => 1800000000, 'metadata' => $payload['metadata']];
            $provider->refunds[] = $body;
        } elseif ($path === '/v1/refunds') {
            $body = ['object' => 'list', 'url' => '/v1/refunds', 'has_more' => false, 'data' => $provider->refunds];
        } elseif ($path === '/v1/disputes') {
            $body = ['object' => 'list', 'url' => '/v1/disputes', 'has_more' => false, 'data' => $provider->disputes];
        } else {
            $objects = [...$provider->refunds, ...$provider->disputes];
            $body = array_values(array_filter($objects, static fn (array $object): bool => $object['id'] === basename($path)))[0] ?? [];
        }

        return new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    };
    app()->instance(StripeCheckout::class, new StripeCheckout(new Client(['handler' => HandlerStack::create(new MockHandler(array_fill(0, 150, $http)))])));
    foreach ([CheckoutWriter::class, CheckoutReconciler::class, RefundRecovery::class, StripeFinancialGraph::class, FinancialReconciler::class, LiveStripeAdmission::class] as $service) {
        app()->forgetInstance($service);
    }

    return [...$seed, ...compact('product', 'reader', 'request', 'order', 'review', 'provider')];
}

function liveCommerceConfirm(array $seed): void
{
    $seed['provider']->paid = true;
    $body = [...$seed['provider']->session, 'livemode' => true];
    $raw = json_encode(['object' => 'event', 'id' => 'evt_LiveConfirmation', 'type' => 'checkout.session.completed',
        'api_version' => StripeCheckout::API_VERSION, 'livemode' => true, 'data' => ['object' => $body]], JSON_THROW_ON_ERROR);
    $now = CarbonImmutable::now('UTC')->timestamp;
    $signature = 't='.$now.',v1='.hash_hmac('sha256', $now.'.'.$raw, $seed['hook']);
    $event = app(StripeWebhook::class)->verify($raw, $signature, [new Secret($seed['hook'])], $now, 'live');
    $receipt = app(StripeEventInbox::class)->capture((int) $seed['org']->id, $event, $raw, CarbonImmutable::now('UTC'));
    expect(app(StripeEventInbox::class)->process($receipt['id']))->toBe('processed');
    app(AccessFulfillment::class)->reconcile($seed['order']->reference);
}

afterEach(function (): void {
    Fx::tearDown();
    Credentials::tearDown();
    CarbonImmutable::setTestNow();
    ModuleKernel::flush();
});

it('blocks new live sessions without admission or after explicit revocation', function (): void {
    $seed = liveCommerceSeed(false);
    expect(fn () => app(CheckoutWriter::class)->start($seed['request'], $seed['order']->reference, $seed['review']['token']))->toThrow(CheckoutRefused::class)
        ->and($seed['provider']->calls)->toBe([])->and(DB::table(CheckoutAttempts::TABLE)->count())->toBe(0);
    app(LiveStripeAdmission::class)->admit(null, Credentials::PASSWORD);
    app(LiveStripeAdmission::class)->revoke(app(LiveStripeAdmission::class)->statusForOwner()['version'], Credentials::PASSWORD);
    expect(fn () => app(CheckoutWriter::class)->start($seed['request'], $seed['order']->reference, $seed['review']['token']))->toThrow(CheckoutRefused::class)
        ->and(DB::table(CheckoutAttempts::TABLE)->count())->toBe(0);
});

it('completes an admitted live purchase after revocation with stock access refunds and export custody', function (): void {
    $seed = liveCommerceSeed();
    $opened = app(CheckoutWriter::class)->start($seed['request'], $seed['order']->reference, $seed['review']['token']);
    expect($opened['state'])->toBe('open')->and(DB::table(CheckoutAttempts::TABLE)->value('mode'))->toBe('live');
    app(LiveStripeAdmission::class)->revoke(app(LiveStripeAdmission::class)->statusForOwner()['version'], Credentials::PASSWORD);
    DB::table('commerce_sellables')->where('id', $seed['product']->id)->update(['stock' => '1']);
    liveCommerceConfirm($seed);
    expect(DB::table(PaidOrders::RECEIPTS)->value('mode'))->toBe('live')
        ->and(DB::table('commerce_orders')->value('state'))->toBe('paid')
        ->and((string) DB::table('commerce_sellables')->value('stock'))->toBe('0');
    $refund = DB::table(PaidOrders::ATTEMPTS)->sole();
    expect(app(RefundRecovery::class)->reconcile($seed['order']->reference, $refund->reference)['state'])->toBe('succeeded');
    $jsonl = app(OrderHistoryExport::class)->forOwner('jsonl')->body;
    $csv = app(OrderHistoryExport::class)->forOwner('csv')->body;
    expect($jsonl)->toContain('"mode":"live"', 'course.purchased')->not->toContain($seed['key'], $seed['hook'], 'live-buyer@example.test')
        ->and($csv)->toContain('live');
    expect(DB::table(AccessFulfillment::WORK)->value('state'))->toBe('processed');
    Auth::guard(Fx::GUARD)->logout();
    Fx::forget();
    $line = DB::table('commerce_order_lines')->value('id');
    $reference = app(FinancialState::class)->requestAsOwner($seed['order']->reference, [(int) $line => 1], 'Refund remaining delivered unit.', str_repeat('b', 32));
    app(AccessAdjustments::class)->reconcile($seed['order']->reference);
    expect(app(FinancialReconciler::class)->submit($seed['order']->reference, $reference)['state'])->toBe('processed');
    app(AccessAdjustments::class)->reconcile($seed['order']->reference);
    expect((string) DB::table('commerce_sellables')->value('stock'))->toBe('0')
        ->and(DB::table(FinancialState::OBSERVATIONS)->where('mode', 'live')->count())->toBe(2)
        ->and(app(LiveStripeAdmission::class)->statusForOwner()['state'])->toBe('revoked');
});

it('keeps original live account recovery available after current mode changes', function (): void {
    $seed = liveCommerceSeed();
    $opened = app(CheckoutWriter::class)->start($seed['request'], $seed['order']->reference, $seed['review']['token']);
    $seed['provider']->paid = true;
    app(CredentialWriter::class)->switchTo(CredentialMode::Test);
    expect(fn () => app(CheckoutReconciler::class)->reconcile($seed['order']->reference, $opened['reference'], ''))->toThrow(CheckoutRefused::class);
    expect(app(CheckoutReconciler::class)->recoverAsOwner($seed['order']->reference, $opened['reference'], '', new Secret($seed['key']), Credentials::PASSWORD)['state'])->toBe('completed');
    expect(DB::table(PaidOrders::RECEIPTS)->value('mode'))->toBe('live')
        ->and(DB::table('org_credential_modes')->value('mode'))->toBe('test');
});

it('refuses a mismatched account or response mode without confirming any payment', function (string $kind): void {
    $seed = liveCommerceSeed();
    if ($kind === 'account') {
        $seed['provider']->account = 'acct_Foreign';
    } else {
        $seed['provider']->mode = false;
    }
    if ($kind === 'account') {
        expect(fn () => app(CheckoutWriter::class)->start($seed['request'], $seed['order']->reference, $seed['review']['token']))->toThrow(CheckoutRefused::class);
    } else {
        $result = app(CheckoutWriter::class)->start($seed['request'], $seed['order']->reference, $seed['review']['token']);
        expect($result['state'])->toBe('uncertain')->and($result['url'])->toBeNull();
    }
    expect(DB::table(PaidOrders::RECEIPTS)->count())->toBe(0)
        ->and(DB::table('commerce_orders')->value('state'))->toBe('prepared');
})->with(['account', 'mode']);

it('continues to refuse free live completion without writing a checkout attempt', function (): void {
    $seed = liveCommerceSeed(price: '0');
    expect(fn () => app(CheckoutWriter::class)->start($seed['request'], $seed['order']->reference, $seed['review']['token']))->toThrow(CheckoutRefused::class)
        ->and($seed['provider']->calls)->toBe([])->and(DB::table(CheckoutAttempts::TABLE)->count())->toBe(0);
});

it('processes signed live disputes after admission revocation and restores only the original term on a verified win', function (): void {
    $seed = liveCommerceSeed();
    app(CheckoutWriter::class)->start($seed['request'], $seed['order']->reference, $seed['review']['token']);
    liveCommerceConfirm($seed);
    $end = DB::table('entitlements')->value('expires_at');
    expect(DB::table('entitlements')->count())->toBe(1)->and(DB::table('entitlements')->value('revoked_at'))->toBeNull();
    app(LiveStripeAdmission::class)->revoke(app(LiveStripeAdmission::class)->statusForOwner()['version'], Credentials::PASSWORD);
    $seed['provider']->disputes = [['id' => 'du_Lifecycle', 'object' => 'dispute', 'charge' => 'ch_Lifecycle',
        'payment_intent' => 'pi_Lifecycle', 'livemode' => true, 'currency' => 'usd', 'amount' => 1000, 'status' => 'needs_response', 'created' => 1800000000]];
    foreach (['needs_response', 'won'] as $index => $status) {
        $seed['provider']->disputes[0]['status'] = $status;
        $raw = json_encode(['object' => 'event', 'id' => 'evt_LiveDispute'.$index, 'type' => $index === 0 ? 'charge.dispute.created' : 'charge.dispute.closed',
            'api_version' => StripeCheckout::API_VERSION, 'livemode' => true, 'data' => ['object' => $seed['provider']->disputes[0]]], JSON_THROW_ON_ERROR);
        $now = CarbonImmutable::now('UTC')->timestamp;
        $signature = 't='.$now.',v1='.hash_hmac('sha256', $now.'.'.$raw, $seed['hook']);
        $event = app(StripeWebhook::class)->verify($raw, $signature, [new Secret($seed['hook'])], $now, 'live');
        $receipt = app(StripeEventInbox::class)->capture((int) $seed['org']->id, $event, $raw, CarbonImmutable::now('UTC'));
        expect(app(StripeEventInbox::class)->process($receipt['id']))->toBe('processed');
        app(AccessAdjustments::class)->reconcile($seed['order']->reference);
        expect(DB::table('entitlements')->value('revoked_at') === null)->toBe($index === 1)
            ->and(DB::table('entitlements')->value('expires_at'))->toBe($end);
    }
    expect(DB::table(FinancialSignals::TABLE)->where('mode', 'live')->where('state', 'resolved')->count())->toBe(2)
        ->and(DB::table(FinancialState::OBSERVATIONS)->value('mode'))->toBe('live');
});

it('blocks an unknown-outcome live create replay after revocation without making another provider call', function (): void {
    $seed = liveCommerceSeed();
    $seed['provider']->timeout = true;
    $result = app(CheckoutWriter::class)->start($seed['request'], $seed['order']->reference, $seed['review']['token']);
    expect($result['state'])->toBe('uncertain')->and(DB::table(CheckoutAttempts::TABLE)->value('session_id'))->toBeNull();
    app(LiveStripeAdmission::class)->revoke(app(LiveStripeAdmission::class)->statusForOwner()['version'], Credentials::PASSWORD);
    $before = $seed['provider']->calls;
    expect(fn () => app(CheckoutWriter::class)->refresh($seed['request'], $seed['order']->reference, $result['reference']))->toThrow(CheckoutRefused::class)
        ->and($seed['provider']->calls)->toBe($before)->and(DB::table(CheckoutAttempts::TABLE)->count())->toBe(1);
});

it('rechecks admission after account discovery and before every new live create', function (int $accountRead): void {
    $seed = liveCommerceSeed();
    $reads = 0;
    $seed['provider']->hook = static function (string $path) use (&$reads, $accountRead): void {
        if ($path === '/v1/account' && ++$reads === $accountRead) {
            app(LiveStripeAdmission::class)->revoke(app(LiveStripeAdmission::class)->statusForOwner()['version'], Credentials::PASSWORD);
        }
    };
    if ($accountRead === 1) {
        expect(fn () => app(CheckoutWriter::class)->start($seed['request'], $seed['order']->reference, $seed['review']['token']))->toThrow(CheckoutRefused::class);
        expect(DB::table(CheckoutAttempts::TABLE)->count())->toBe(0)
            ->and(array_column($seed['provider']->calls, 'method'))->not->toContain('POST');
    } else {
        $seed['provider']->timeout = true;
        $initial = app(CheckoutWriter::class)->start($seed['request'], $seed['order']->reference, $seed['review']['token']);
        expect($initial['state'])->toBe('uncertain');
        $seed['provider']->timeout = false;
        $retried = app(CheckoutWriter::class)->refresh($seed['request'], $seed['order']->reference, $initial['reference']);
        expect($retried['state'])->toBe('uncertain')->and($retried['url'])->toBeNull()
            ->and(count(array_filter($seed['provider']->calls, static fn (array $call): bool => $call['method'] === 'POST')))->toBe(1)
            ->and(DB::table(CheckoutAttempts::TABLE)->count())->toBe(1);
    }
})->with([1, 2]);

it('records an already-started provider creation after revocation so its original Session remains recoverable', function (): void {
    $seed = liveCommerceSeed();
    $seed['provider']->hook = static function (string $path): void {
        if ($path === '/v1/checkout/sessions') {
            app(LiveStripeAdmission::class)->revoke(app(LiveStripeAdmission::class)->statusForOwner()['version'], Credentials::PASSWORD);
        }
    };
    $result = app(CheckoutWriter::class)->start($seed['request'], $seed['order']->reference, $seed['review']['token']);
    expect($result['state'])->toBe('open')->and(DB::table(CheckoutAttempts::TABLE)->value('session_id'))->toBe('cs_live_Lifecycle')
        ->and(app(LiveStripeAdmission::class)->statusForOwner()['state'])->toBe('revoked');
    $seed['provider']->hook = null;
    liveCommerceConfirm($seed);
    expect(DB::table('commerce_orders')->value('state'))->toBe('paid');
});

it('requires original-account owner recovery after an API key revision without changing current secrets', function (): void {
    $seed = liveCommerceSeed();
    $result = app(CheckoutWriter::class)->start($seed['request'], $seed['order']->reference, $seed['review']['token']);
    $seed['provider']->paid = true;
    app(CredentialWriter::class)->set(StripeCheckout::SLOT, CredentialMode::Live, Credentials::value('sk_live_'), Credentials::PASSWORD);
    $before = DB::table('credentials')->get()->toArray();
    expect(fn () => app(CheckoutReconciler::class)->reconcile($seed['order']->reference, $result['reference'], ''))->toThrow(CheckoutRefused::class);
    app(CheckoutReconciler::class)->recoverAsOwner($seed['order']->reference, $result['reference'], '', new Secret($seed['key']), Credentials::PASSWORD);
    expect(DB::table(PaidOrders::RECEIPTS)->value('account_id'))->toBe('acct_LiveFixture')
        ->and(DB::table('credentials')->get()->toArray())->toEqual($before)
        ->and(app(LiveStripeAdmission::class)->statusForOwner()['state'])->toBe('stale');
});

it('requires configured HTTPS return origins before recording a new live checkout attempt', function (): void {
    $seed = liveCommerceSeed();
    $seed['site']->update(['base_url' => 'http://live.example.test']);
    expect(fn () => app(CheckoutWriter::class)->start($seed['request'], $seed['order']->reference, $seed['review']['token']))->toThrow(CheckoutRefused::class)
        ->and(DB::table(CheckoutAttempts::TABLE)->count())->toBe(0)
        ->and(array_column($seed['provider']->calls, 'method'))->not->toContain('POST');
});
