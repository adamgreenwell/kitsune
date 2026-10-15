<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as ProviderResponse;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Kitsune\Commerce\Blueprints\StorefrontBlueprint;
use Kitsune\Commerce\Catalogue\ProductWriter;
use Kitsune\Commerce\Checkout\CommerceSetupWriter;
use Kitsune\Commerce\Filament\OrderList;
use Kitsune\Commerce\Payments\StripeCheckout;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Credentials\CredentialConsoleAuthority;
use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Credentials\CredentialWriter;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Modules\ModuleLifecycle;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;
use Kitsune\Core\Tests\Fixtures\TestUser;

define('KITSUNE_CATALOGUE_PROBE_CLASSES_ONLY', true);
require __DIR__.'/PublicCataloguePageProbe.php';

final class StripeCheckoutProbeApplication
{
    public static function make(string $root, string $temporary, string $scenario, bool $seed = false): Application
    {
        $app = PublicCatalogueProbeApplication::make($root, $temporary, $scenario, $seed);
        $app->beforeBootstrapping(RegisterProviders::class, static function (Application $app): void {
            $app->make('config')->set('mail.default', 'array');
            $app->make('config')->set('mail.mailers.array', ['transport' => 'array']);
        });

        return $app;
    }
}

if ($step === 'seed') {
    foreach (['bootstrap/cache', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs'] as $path) {
        mkdir($temporary.'/'.$path, 0700, true);
    }
    symlink($root.'/vendor', $temporary.'/vendor');
    symlink($root.'/skeleton/app', $temporary.'/app');
    file_put_contents($temporary.'/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]], JSON_THROW_ON_ERROR));
    touch($temporary.'/catalogue.sqlite');
    file_put_contents($temporary.'/app-key', 'base64:'.base64_encode(random_bytes(32)));
    file_put_contents($temporary.'/bootstrap/app.php', '<?php return StripeCheckoutProbeApplication::make('.var_export($root, true).', '.var_export($temporary, true).', '.var_export($scenario, true).');');
}

$app = StripeCheckoutProbeApplication::make($root, $temporary, $scenario, $step === 'seed');
$console = $app->make(ConsoleKernel::class);
$console->bootstrap();
$prefix = $scenario === 'root' ? '' : ($scenario === 'nested' ? '/news/fr/shop' : '/brand');
$origin = $scenario === 'root' ? 'http://checkout.test' : 'http://localhost';
if ($step === 'seed') {
    if ($console->call('migrate', ['--path' => [$root.'/packages/core/database/migrations', $root.'/tests/Commerce/migrations', $root.'/skeleton/database/migrations/0001_01_01_000020_create_readers_table.php'], '--realpath' => true, '--force' => true]) !== 0) {
        throw new RuntimeException($console->output());
    }
    $org = Org::create(['slug' => 'stripe-page', 'name' => 'Stripe test org', 'currency' => 'USD']);
    app(Context::class)->setOrg($org);
    $site = Site::create(['handle' => 'main', 'slug' => 'stripe-main', 'name' => 'Brand & <site>', 'locale' => $scenario === 'arabic' ? 'ar' : 'en',
        'base_url' => $scenario === 'root' ? $origin : $prefix]);
    Site::create(['handle' => 'sibling', 'slug' => 'stripe-sibling', 'name' => 'Sibling', 'locale' => 'en', 'base_url' => '/sibling']);
    app(Context::class)->setSite($site);
    ModuleLifecycle::install($app, 'kitsune/commerce');
    ModuleLifecycle::enable($app, 'kitsune/commerce');
    ModuleKernel::boot($app);
    BlueprintApplier::apply(new StorefrontBlueprint);
    $product = app(ProductWriter::class)->createProduct(['sku' => 'SURF', 'price_minor' => '2000', 'stock' => '5'], 'USD', [
        'title' => 'Surf & <course>', 'slug' => 'surf', 'status' => 'published', 'values' => [],
    ], [], true);
    $setup = app(CommerceSetupWriter::class);
    $setup->setSettings(['tax_mode' => 'exclusive', 'shipping_tax_mode' => 'exclusive', 'shipping_tax_class' => 'shipping']);
    $setup->saveTaxRate(null, ['country' => 'US', 'region' => '*', 'tax_class' => 'standard', 'rate_parts' => 200000]);
    $setup->setSalesPolicy($product->getKey(), ['tax_class' => 'standard', 'delivery_kind' => 'digital']);
    CredentialConsoleAuthority::run(app(Context::class), 'set', StripeCheckout::SLOT, CredentialMode::Test, null,
        static fn (CredentialConsoleAuthority $authority) => app(CredentialWriter::class)->set(StripeCheckout::SLOT, CredentialMode::Test, 'sk_test_'.str_repeat('x', 32), console: $authority));
    echo json_encode(['seeded' => true], JSON_THROW_ON_ERROR);
    exit;
}
if ($step === 'cache') {
    if ($console->call('route:cache') !== 0 || ! $app->routesAreCached()) {
        throw new RuntimeException($console->output());
    }
    echo json_encode(['cached' => true], JSON_THROW_ON_ERROR);
    exit;
}

Notification::fake();
$calls = [];
$payload = null;
$providerState = 'open';
$provider = static function ($request, $options) use (&$calls, &$payload, &$providerState): ProviderResponse {
    $calls[] = ['method' => $request->getMethod(), 'path' => $request->getUri()->getPath(), 'level' => DB::connection()->transactionLevel(),
        'pdo' => DB::connection()->getPdo()->inTransaction()];
    if ($request->getUri()->getPath() === '/v1/account') {
        $data = ['object' => 'account', 'id' => 'acct_fixtureCheckout'];
    } else {
        if ($request->getUri()->getPath() === '/v1/checkout/sessions') {
            parse_str((string) $request->getBody(), $payload);
        }
        if (str_ends_with($request->getUri()->getPath(), '/expire')) {
            $providerState = 'expired';
        }
        if (! is_array($payload)) {
            throw new RuntimeException('Unexpected fixture request.');
        }
        $session = 'cs_test_'.$payload['metadata']['kitsune_attempt'];
        $data = ['object' => 'checkout.session', 'id' => $session, 'mode' => 'payment', 'livemode' => false,
            'status' => $providerState, 'payment_status' => $providerState === 'complete' ? 'paid' : 'unpaid',
            'amount_total' => 2400, 'currency' => 'usd', 'expires_at' => (int) $payload['expires_at'],
            'client_reference_id' => $payload['client_reference_id'], 'metadata' => $payload['metadata'],
            'url' => $providerState === 'open' ? 'https://checkout.stripe.com/c/pay/'.$session.'#private-provider-fragment' : null,
            'payment_intent' => $providerState === 'complete' ? 'pi_fixturePaid' : null];
    }

    return new ProviderResponse(200, ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
};
$http = new Client(['handler' => HandlerStack::create(new MockHandler(array_fill(0, 40, $provider)))]);
$app->instance(StripeCheckout::class, new StripeCheckout($http));
$jar = [];
$responses = [];
$token = '';
$send = static function (string $method, string $path, array $body = [], array $server = []) use ($app, $origin, &$jar, &$responses, &$token): array {
    ReaderFixture::forget();
    $app->make('session.store')->flush();
    foreach (Cookie::getQueuedCookies() as $queued) {
        Cookie::unqueue($queued->getName(), $queued->getPath());
    }
    $request = Request::create($origin.$path, $method, $body, $jar, [], $server);
    $http = $app->make(HttpKernel::class);
    $response = $http->handle($request);
    $http->terminate($request, $response);
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getExpiresTime() !== 0 && $cookie->getExpiresTime() <= time()) {
            unset($jar[$cookie->getName()]);
        } else {
            $jar[$cookie->getName()] = $cookie->getValue();
        }
    }
    $token = $request->hasSession() ? (string) $request->session()->token() : '';
    $headers = $response->headers->all();
    unset($headers['set-cookie']);
    $result = ['status' => $response->getStatusCode(), 'body' => $response->getContent(), 'headers' => $headers,
        'query' => $request->query->all(), 'input' => $request->request->all(), 'rawBody' => $request->getContent(),
        'previous' => $request->hasSession() ? $request->session()->get('_previous.url') : null,
        'oldInput' => $request->hasSession() ? $request->session()->get('_old_input') : null];
    $responses[] = $result;

    return $result;
};
$cart = $prefix.'/cart';
$send('GET', $cart);
$send('POST', $cart.'/add', ['_token' => $token, 'listing' => (string) DB::table('commerce_listings')->value('id'), 'quantity' => '1']);
$quote = $send('POST', $cart.'/quote', ['_token' => $token, 'email' => 'private-native-checkout@example.test', 'billing_name' => 'Private Checkout Buyer',
    'billing_line1' => '42 Private Checkout Lane', 'billing_city' => 'Honolulu', 'billing_region' => 'HI', 'billing_postal_code' => '96801', 'billing_country' => 'US']);
preg_match('/name="quote" value="([a-f0-9]{64})"/', $quote['body'], $match);
$prepared = $send('POST', $cart.'/orders', ['_token' => $token, 'quote' => $match[1] ?? '', 'rate' => 'none']);
$path = $prepared['headers']['location'][0] ?? throw new RuntimeException('Order preparation failed.');
$payment = $path.'/payment';
$send('GET', $payment.'?review=private-query-review&session_id=private-query-session');
if ($scenario === 'csrf') {
    $send('POST', $payment.'/review?_token='.$token, [], ['HTTP_X_CSRF_TOKEN' => $token]);
    foreach (['start', 'refresh', 'expire'] as $action) {
        $send('POST', $payment.'/'.$action.'?_token='.$token, ['review' => str_repeat('a', 64), 'attempt' => str_repeat('b', 32)], ['HTTP_X_CSRF_TOKEN' => $token]);
    }
} elseif ($scenario === 'scope') {
    foreach (['/sibling/cart/orders/'.basename($path).'/payment', $cart.'/orders/'.str_repeat('A', 32).'/payment', $cart.'/orders/'.str_repeat('a', 31).'/payment'] as $foreign) {
        $send('GET', $foreign);
    }
} else {
    $review = $send('POST', $payment.'/review', ['_token' => $token]);
    preg_match('/name="review" value="([a-f0-9]{64})"/', $review['body'], $match);
    $send('POST', $payment.'/start', ['_token' => $token, 'review' => $match[1] ?? '']);
    $attempt = DB::table('commerce_checkout_attempts')->value('reference');
    $callsBeforeGet = count($calls);
    $send('GET', $payment.'/return?session_id=private-forged-session&token=private-query-token');
    $send('GET', $payment.'/cancel?session_id=private-forged-session');
    $getReadOnly = $callsBeforeGet === count($calls) && DB::table('commerce_checkout_attempts')->value('state') === 'open';
    $send('POST', $path.'/cancel', ['_token' => $token]);
    $send('POST', $path.'/erase', ['_token' => $token]);
    if ($scenario === 'owner-expire') {
        config(['auth.providers.users.model' => TestUser::class]);
        CredentialFixture::member();
        $page = app(OrderList::class);
        $page->mount(basename($path));
        DB::table('commerce_checkout_attempts')->update(['state' => 'uncertain', 'session_id' => null, 'checkout_url' => null]);
        $page->recoverySession = 'cs_test_'.$attempt;
        $page->recoveryExpire = true;
        $page->recoverAttempt($attempt);
        $ownerRecovered = $page->getErrorBag()->isEmpty() && $page->recoverySession === '' && ! $page->recoveryExpire
            && DB::table('commerce_checkout_attempts')->value('state') === 'expired';
    } elseif ($scenario !== 'expire') {
        $providerState = 'complete';
    }
    $send('POST', $payment.'/'.(in_array($scenario, ['expire', 'owner-expire'], true) ? 'expire' : 'refresh'), ['_token' => $token, 'attempt' => $attempt]);
    $send('GET', $payment);
    $send('POST', $path.'/export', ['_token' => $token]);
    $send('POST', $path.'/cancel', ['_token' => $token]);
    $send('POST', $path.'/erase', ['_token' => $token]);
}
echo json_encode(['responses' => $responses, 'calls' => $calls, 'payload' => $payload, 'getReadOnly' => $getReadOnly ?? true,
    'ownerRecovered' => $ownerRecovered ?? null,
    'orders' => DB::table('commerce_orders')->count(), 'attempts' => DB::table('commerce_checkout_attempts')->count(),
    'stock' => (string) DB::table('commerce_sellables')->value('stock'), 'grants' => DB::table('entitlements')->count(),
    'level' => DB::connection()->transactionLevel(), 'pdo' => DB::connection()->getPdo()->inTransaction(), 'cached' => $app->routesAreCached(),
    'log' => is_file($temporary.'/storage/logs/probe.log') ? file_get_contents($temporary.'/storage/logs/probe.log') : ''], JSON_THROW_ON_ERROR);
