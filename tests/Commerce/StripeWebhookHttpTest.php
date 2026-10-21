<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Http\Controllers\StripeWebhookController;
use Kitsune\Commerce\Payments\StripeCheckout;
use Kitsune\Commerce\Payments\StripeWebhook;
use Kitsune\Core\Credentials\CredentialConsoleAuthority;
use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Credentials\CredentialWriter;
use Kitsune\Core\Http\BoundedWebhookRequest;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\StripeInboxFixture as Inbox;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

beforeEach(function (): void {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('h', 32))]);
    Fx::boot();
    CarbonImmutable::setTestNow('2026-10-15 12:00:00 UTC');
    $this->org = Fx::org('webhook-http');
    $this->org->update(['currency' => 'USD']);
    $this->ingress = Site::create(['handle' => 'ingress', 'slug' => 'webhook-ingress', 'name' => 'Ingress', 'base_url' => 'https://inbox.example.test']);
    $this->purchaseSite = Site::create(['handle' => 'purchases', 'slug' => 'webhook-purchases', 'name' => 'Purchases', 'base_url' => 'https://shop.example.test']);
    app(Context::class)->setSite($this->purchaseSite);
    Inbox::enable();
    ModuleKernel::boot($this->app);
    $this->attempt = Inbox::attempt($this->purchaseSite);
    $this->key = 'whsec_http_private_fixture';
    foreach ([StripeWebhook::SLOT, StripeWebhook::PREVIOUS_SLOT] as $slot) {
        CredentialConsoleAuthority::run(app(Context::class), 'set', $slot, CredentialMode::Test, null,
            fn (CredentialConsoleAuthority $authority) => app(CredentialWriter::class)->set($slot, CredentialMode::Test, $this->key, console: $authority));
    }
    $event = Inbox::event($this->attempt);
    $this->event = ['object' => 'event', 'id' => $event['id'], 'type' => $event['type'], 'api_version' => StripeCheckout::API_VERSION,
        'livemode' => false, 'data' => ['object' => ['object' => 'checkout.session', 'id' => $event['sessionId'], 'livemode' => false, 'mode' => 'payment',
            'client_reference_id' => $event['orderReference'], 'metadata' => ['kitsune_order' => $event['orderReference'], 'kitsune_attempt' => $event['attemptReference']],
            'customer_details' => ['email' => 'private-webhook@example.test']]]];
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Fx::tearDown();
});

/** The actual capture guard and production controller, with signature bytes untouched by Laravel capture. */
function webhookHttp(array $event, string $key, string $url = 'https://inbox.example.test/cart/stripe/webhook', bool $bounded = true, bool $forgetContext = true): array
{
    $raw = "\n".json_encode($event, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    $time = CarbonImmutable::now('UTC')->timestamp;
    $signature = 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$raw, $key);
    $base = SymfonyRequest::create($url, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $raw);
    $request = $bounded ? BoundedWebhookRequest::fromBase($base, '/cart/stripe/webhook', 'Stripe-Signature')
        : Request::createFromBase($base);
    expect($request)->toBeInstanceOf(Request::class);
    $route = new Route(['POST'], 'cart/stripe/webhook', StripeWebhookController::class);
    $route->bind($request);
    $request->setRouteResolver(static fn (): Route => $route);
    if ($forgetContext) {
        app(Context::class)->forget();
    }
    $response = app(StripeWebhookController::class)($request);

    return [$response, $request, $raw];
}

it('commits a verified org receipt for the recorded sibling Site before acknowledging and leaves CLI processing pending', function (): void {
    [$response, $request, $raw] = webhookHttp($this->event, $this->key);
    expect($response->getStatusCode())->toBe(200)->and($response->getContent())->toBe('')
        ->and($response->headers->has('Set-Cookie'))->toBeFalse()->and($request->getContent())->toBe('')
        ->and($request->json()->all())->toBe([])->and($request->headers->has('Stripe-Signature'))->toBeFalse();
    $row = DB::table('commerce_stripe_events')->sole();
    expect((int) $row->org_id)->toBe($this->org->id)->and((int) $row->site_id)->toBe($this->purchaseSite->id)
        ->and($row->raw_payload)->toBe($raw)->and($row->state)->toBe('pending');
    expect(DB::table('commerce_checkout_attempts')->value('state'))->toBe('open');
    [$again] = webhookHttp($this->event, $this->key);
    expect($again->getStatusCode())->toBe(200)->and(DB::table('commerce_stripe_events')->count())->toBe(1);
});

it('accepts explicit previous signing-secret overlap', function (): void {
    app(Context::class)->setSite($this->ingress);
    CredentialConsoleAuthority::run(app(Context::class), 'set', StripeWebhook::SLOT, CredentialMode::Test, null,
        fn (CredentialConsoleAuthority $authority) => app(CredentialWriter::class)->set(StripeWebhook::SLOT, CredentialMode::Test, 'whsec_replacement_http_fixture', console: $authority));
    [$response] = webhookHttp($this->event, $this->key);
    expect($response->getStatusCode())->toBe(200)->and(DB::table('commerce_stripe_events')->count())->toBe(1);
});

it('refuses impossible signature envelopes before Site, credential or order-lock queries', function (string $header, string $raw): void {
    $base = SymfonyRequest::create('https://inbox.example.test/cart/stripe/webhook', 'POST', [], [], [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $header], $raw);
    $request = BoundedWebhookRequest::fromBase($base, '/cart/stripe/webhook', 'Stripe-Signature');
    expect($request)->toBeInstanceOf(Request::class);
    app(Context::class)->setSite($this->purchaseSite);
    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $response = app(StripeWebhookController::class)($request);

    expect($response->getStatusCode())->toBe(400)->and($queries)->toBe([])
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->has('Set-Cookie'))->toBeFalse()
        ->and($request->getContent())->toBe('')->and($request->headers->has('Stripe-Signature'))->toBeFalse()
        ->and(app(Context::class)->hasOrg())->toBeFalse();
})->with([
    'missing signature' => ['', '{}'],
    'unparseable signature' => ['private-invalid-header', '{}'],
    'missing v1' => ['t=1792065600,v0=ignored', '{}'],
    'ambiguous timestamp' => ['t=1792065600,t=1792065600,v1='.str_repeat('0', 64), '{}'],
    'expired timestamp' => ['t=1792065299,v1='.str_repeat('0', 64), '{}'],
    'future timestamp' => ['t=1792065901,v1='.str_repeat('0', 64), '{}'],
    'empty body' => ['t=1792065600,v1='.str_repeat('0', 64), ''],
]);

it('derives no org from stale host context when the webhook hostname has no matching Site', function (): void {
    app(Context::class)->setSite($this->ingress);
    [$response] = webhookHttp($this->event, $this->key, 'https://unclaimed.example.test/cart/stripe/webhook', forgetContext: false);
    expect($response->getStatusCode())->toBe(400)->and(DB::table('commerce_stripe_events')->count())->toBe(0)
        ->and(app(Context::class)->hasOrg())->toBeFalse();
});

it('refuses unverifiable unbounded foreign and wrong-mode deliveries without raw custody', function (string $scenario): void {
    $event = $this->event;
    $key = $this->key;
    $url = 'https://inbox.example.test/cart/stripe/webhook';
    if ($scenario === 'signature') {
        $key = 'whsec_wrong_http_fixture';
    } elseif ($scenario === 'live') {
        $event['livemode'] = true;
    } elseif ($scenario === 'mode') {
        DB::table('org_credential_modes')->updateOrInsert(['org_id' => $this->org->id], ['mode' => 'live', 'changed_at' => now()->format('Y-m-d H:i:s')]);
    } elseif ($scenario === 'foreign') {
        Fx::org('webhook-http-foreign');
        Site::create(['handle' => 'foreign', 'slug' => 'webhook-http-foreign-site', 'name' => 'Foreign', 'base_url' => 'https://foreign-inbox.example.test']);
        $url = 'https://foreign-inbox.example.test/cart/stripe/webhook';
    } elseif ($scenario === 'query') {
        $url .= '?unexpected=private';
    } elseif ($scenario === 'disabled') {
        DB::table('modules')->where('handle', 'kitsune/commerce')->update(['is_enabled' => false]);
    }
    [$response, $request] = webhookHttp($event, $key, $url, $scenario !== 'guard');
    expect($response->getStatusCode())->toBe(400)->and(DB::table('commerce_stripe_events')->count())->toBe(0)
        ->and($request->getContent())->toBe('')->and($request->query->all())->toBe([]);
})->with(['signature', 'live', 'mode', 'foreign', 'query', 'disabled', 'guard']);

it('accepts signed live financial hints without new-sale admission and partitions minimal custody by mode', function (): void {
    app(Context::class)->setSite($this->ingress);
    $key = 'whsec_'.str_repeat('l', 32);
    CredentialConsoleAuthority::run(app(Context::class), 'set', StripeWebhook::SLOT, CredentialMode::Live, null,
        fn (CredentialConsoleAuthority $authority) => app(CredentialWriter::class)->set(StripeWebhook::SLOT, CredentialMode::Live, $key, console: $authority));
    CredentialConsoleAuthority::run(app(Context::class), 'switch', null, CredentialMode::Live, null,
        fn (CredentialConsoleAuthority $authority) => app(CredentialWriter::class)->switchTo(CredentialMode::Live, console: $authority));
    $event = ['object' => 'event', 'id' => 'evt_LiveHttpFinancial', 'type' => 'charge.dispute.created',
        'api_version' => StripeCheckout::API_VERSION, 'livemode' => true,
        'data' => ['object' => ['object' => 'dispute', 'id' => 'du_LiveHttp', 'charge' => 'ch_LiveHttp', 'livemode' => true,
            'customer_details' => ['email' => 'private-live@example.test']]]];
    [$response, $request] = webhookHttp($event, $key);
    expect($response->getStatusCode())->toBe(200)->and($request->getContent())->toBe('')
        ->and(DB::table('commerce_stripe_admissions')->count())->toBe(0)
        ->and(DB::table('commerce_financial_signals')->value('mode'))->toBe('live')
        ->and(DB::table('commerce_financial_signals')->value('state'))->toBe('unresolved');
    expect(json_encode(DB::table('commerce_financial_signals')->sole(), JSON_THROW_ON_ERROR))->not->toContain('private-live@example.test', $key);
    [$duplicate] = webhookHttp($event, $key);
    expect($duplicate->getStatusCode())->toBe(200)->and(DB::table('commerce_financial_signals')->count())->toBe(1);
});

it('refuses live-mode ingress signed with a test key or carrying a test snapshot before storing bytes', function (string $kind): void {
    app(Context::class)->setSite($this->ingress);
    $liveKey = 'whsec_'.str_repeat('l', 32);
    CredentialConsoleAuthority::run(app(Context::class), 'set', StripeWebhook::SLOT, CredentialMode::Live, null,
        fn (CredentialConsoleAuthority $authority) => app(CredentialWriter::class)->set(StripeWebhook::SLOT, CredentialMode::Live, $liveKey, console: $authority));
    CredentialConsoleAuthority::run(app(Context::class), 'switch', null, CredentialMode::Live, null,
        fn (CredentialConsoleAuthority $authority) => app(CredentialWriter::class)->switchTo(CredentialMode::Live, console: $authority));
    $event = $this->event;
    if ($kind === 'key') {
        $event['livemode'] = true;
        $event['data']['object']['livemode'] = true;
        $event['data']['object']['id'] = 'cs_live_Http';
    }
    [$response, $request] = webhookHttp($event, $kind === 'key' ? $this->key : $liveKey);
    expect($response->getStatusCode())->toBe(400)->and($request->getContent())->toBe('')
        ->and(DB::table('commerce_stripe_events')->count())->toBe(0)
        ->and(DB::table('commerce_financial_signals')->count())->toBe(0);
})->with(['key', 'snapshot']);
