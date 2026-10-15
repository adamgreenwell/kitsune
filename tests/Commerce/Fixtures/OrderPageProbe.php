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
use Kitsune\Commerce\CommerceServiceProvider;
use Kitsune\Commerce\Orders\OrderQuotes;
use Kitsune\Commerce\Shipping\ShippoRates;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Credentials\CredentialConsoleAuthority;
use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Credentials\CredentialWriter;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Modules\ModuleLifecycle;
use Kitsune\Core\Readers\ReaderMail;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;

define('KITSUNE_CATALOGUE_PROBE_CLASSES_ONLY', true);
require __DIR__.'/PublicCataloguePageProbe.php';

/** Native host and HTTP kernel, including deferred mail and a real, cacheable route collection. @internal */
final class OrderProbeApplication
{
    public static function make(string $root, string $temporary, string $scenario, bool $seed = false): Application
    {
        $app = PublicCatalogueProbeApplication::make($root, $temporary, $scenario, $seed);
        $app->beforeBootstrapping(RegisterProviders::class, static function (Application $app): void {
            $app->make('config')->set('mail.default', 'array');
            $app->make('config')->set('mail.mailers.array', ['transport' => 'array']);
            $app->make('config')->set('database.connections.catalogue_page_probe.busy_timeout', 5000);
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
    file_put_contents($temporary.'/bootstrap/app.php', '<?php return OrderProbeApplication::make('.var_export($root, true).', '.var_export($temporary, true).', '.var_export($scenario, true).');');
}

if ($step === 'request' && $scenario === 'provider-absent-outage') {
    $database = new PDO('sqlite:'.$temporary.'/catalogue.sqlite');
    $database->exec("update modules set is_enabled = 0 where handle = 'kitsune/commerce'");
    unset($database);
}

$app = OrderProbeApplication::make($root, $temporary, $scenario, $step === 'seed');
$console = $app->make(ConsoleKernel::class);
$console->bootstrap();

if ($step === 'seed') {
    $exit = $console->call('migrate', ['--path' => [$root.'/packages/core/database/migrations', $root.'/tests/Commerce/migrations', $root.'/skeleton/database/migrations/0001_01_01_000020_create_readers_table.php'], '--realpath' => true, '--force' => true]);
    if ($exit !== 0) {
        throw new RuntimeException($console->output());
    }
    $org = Org::create(['slug' => 'order-page', 'name' => 'Order org', 'currency' => 'USD']);
    app(Context::class)->setOrg($org);
    $site = Site::create(['handle' => 'main', 'slug' => 'order-main', 'name' => 'Brand & <site>', 'locale' => $scenario === 'arabic' ? 'ar' : 'en', 'base_url' => $scenario === 'nested-flow' ? '/news/fr/shop' : '/brand']);
    Site::create(['handle' => 'sibling', 'slug' => 'order-sibling', 'name' => 'Sibling', 'locale' => 'en', 'base_url' => '/sibling']);
    app(Context::class)->setSite($site);
    ModuleLifecycle::install($app, 'kitsune/commerce');
    ModuleLifecycle::enable($app, 'kitsune/commerce');
    ModuleKernel::boot($app);
    BlueprintApplier::apply(new StorefrontBlueprint);
    $product = app(ProductWriter::class)->createProduct(['sku' => 'SURF', 'price_minor' => '2000', 'stock' => '5', 'entitlement_name' => 'course.surf',
        'offer_terms' => ['version' => 1, 'kind' => 'permanent']], 'USD', [
            'title' => 'Surf course', 'slug' => 'surf', 'status' => 'published', 'values' => [],
        ], [], true);
    $setup = app(CommerceSetupWriter::class);
    $physical = str_starts_with($scenario, 'physical');
    $setup->setSettings(['tax_mode' => 'exclusive', 'shipping_tax_mode' => $scenario === 'physical-inclusive' ? 'inclusive' : 'exclusive', 'shipping_tax_class' => 'shipping',
        'ship_from_name' => 'Private Origin', 'ship_from_address' => ['line1' => '10 Private Origin Lane', 'city' => 'Honolulu', 'region' => 'HI', 'postal_code' => '96801', 'country' => 'US']]);
    $setup->saveTaxRate(null, ['country' => 'US', 'region' => '*', 'tax_class' => 'standard', 'rate_parts' => 200000]);
    $setup->saveTaxRate(null, ['country' => 'US', 'region' => '*', 'tax_class' => 'shipping', 'rate_parts' => 200000]);
    $setup->setSalesPolicy($product->getKey(), $physical ? ['tax_class' => 'standard', 'delivery_kind' => 'physical', 'weight_grams' => 500, 'length_mm' => 300, 'width_mm' => 200, 'height_mm' => 100, 'tare_grams' => 50, 'units_per_parcel' => 1] : ['tax_class' => 'standard', 'delivery_kind' => 'digital']);
    if ($physical) {
        CredentialConsoleAuthority::run(app(Context::class), 'set', OrderQuotes::SHIPPO_SLOT, CredentialMode::Test, null,
            static fn (CredentialConsoleAuthority $authority) => app(CredentialWriter::class)->set(OrderQuotes::SHIPPO_SLOT, CredentialMode::Test, 'shippo_test_private_probe_token', console: $authority));
    }
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
$evidence = [];
$jar = [];
$responses = [];
$token = '';
$listing = (string) DB::table('commerce_listings')->orderBy('id')->value('id');
$send = static function (string $method, string $path, array $body = [], array $server = []) use ($app, &$jar, &$responses, &$token, $scenario): array {
    ReaderFixture::forget();
    $app->make('session.store')->flush();
    foreach (Cookie::getQueuedCookies() as $queued) {
        Cookie::unqueue($queued->getName(), $queued->getPath());
    }
    if ($scenario === 'nested-flow') {
        $path = preg_replace('~^/brand(?=/|$)~', '/news/fr/shop', $path);
    }
    $request = Request::create('http://localhost'.$path, $method, $body, $jar, [], $server);
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
    if ($request->hasSession()) {
        $token = (string) $request->session()->token();
    }
    $headers = $response->headers->all();
    unset($headers['set-cookie']);
    $result = ['status' => $response->getStatusCode(), 'body' => $response->getContent(), 'headers' => $headers,
        'query' => $request->query->all(), 'input' => $request->request->all(), 'rawBody' => $request->getContent(),
        'previous' => $request->hasSession() ? $request->session()->get('_previous.url') : null,
        'oldInput' => $request->hasSession() ? $request->session()->get('_old_input') : null];
    $responses[] = $result;

    return $result;
};
$body = ['email' => 'private-order-buyer@example.test', 'billing_name' => 'Private Invoice Buyer',
    'billing_line1' => '42 Private Invoice Lane', 'billing_city' => 'Honolulu', 'billing_region' => 'HI', 'billing_postal_code' => '96801', 'billing_country' => 'US'];
if ($scenario === 'provider-absent-outage') {
    $evidence['providerAbsent'] = $app->getProvider(CommerceServiceProvider::class) === null;
    DB::connection()->beforeExecuting(static function (string $query): void {
        if (str_starts_with(strtolower($query), 'select') && str_contains($query, 'sites')) {
            throw new PDOException('private-provider-absent-bootstrap-fault');
        }
    });
    $send('GET', '/brand/cart/checkout?private=private-query-secret');
} else {
    $send('GET', '/brand/cart');
    $send('POST', '/brand/cart/add', ['_token' => $token, 'listing' => $listing, 'quantity' => '2']);
}

if ($scenario === 'provider-absent-outage') {
    // The checkout request above is sufficient; the commerce view namespace never booted.
} elseif ($scenario === 'unknown') {
    foreach (['/unknown/cart/checkout', '/unknown/cart/orders/'.str_repeat('a', 32), '/sibling/cart/orders/'.str_repeat('a', 32)] as $path) {
        $send('GET', $path.'?private=private-query-secret');
    }
} elseif ($scenario === 'csrf') {
    $send('GET', '/brand/cart/checkout');
    $send('POST', '/brand/cart/quote?_token='.$token, $body, ['HTTP_X_CSRF_TOKEN' => $token]);
    $send('POST', '/brand/cart/orders', ['quote' => str_repeat('a', 64), 'rate' => 'none']);
} elseif ($scenario === 'invalid') {
    $send('GET', '/brand/cart/checkout');
    foreach ([['email' => 'private-invalid-email'], ['billing_name' => ['private-nested-name']], ['billing_country' => 'us'], ['billing_region' => 'Hawaii state']] as $invalid) {
        $send('POST', '/brand/cart/quote?secret=private-query-secret', ['_token' => $token, ...array_replace($body, $invalid), 'unknown' => 'private-body-secret']);
    }
} else {
    $send('GET', '/brand/cart/checkout?secret=private-query-secret');
    if (str_starts_with($scenario, 'physical')) {
        $handler = new MockHandler([static function ($request, $options) use (&$evidence, $scenario): ProviderResponse {
            $evidence['providerLevel'] = DB::connection()->transactionLevel();
            $evidence['providerPdoTransaction'] = DB::connection()->getPdo()->inTransaction();
            $evidence['providerRequest'] = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
            if ($scenario === 'physical-changed') {
                DB::table('commerce_settings')->update(['version' => 2]);
            }
            if ($scenario === 'physical-private-error') {
                throw new RuntimeException('shippo_test_private_probe_token Private Invoice Lane private-order-buyer@example.test');
            }

            return new ProviderResponse(201, ['Content-Type' => 'application/json'], json_encode(['object_id' => 'shipment-1', 'status' => 'SUCCESS', 'test' => true,
                'rates' => [['object_id' => 'rate-1', 'shipment' => 'shipment-1', 'test' => true, 'currency' => 'USD', 'amount' => '6.00', 'provider' => 'USPS', 'servicelevel' => ['name' => 'Priority Mail']]]], JSON_THROW_ON_ERROR));
        }]);
        $app->instance(ShippoRates::class, new ShippoRates(new Client(['handler' => HandlerStack::create($handler)])));
    }
    $quoted = $send('POST', '/brand/cart/quote', ['_token' => $token, ...$body]);
    preg_match('/name="quote" value="([a-f0-9]{64})"/', $quoted['body'], $matches);
    $quote = $matches[1] ?? '';
    if ($quote !== '') {
        if ($scenario === 'stale') {
            DB::table('commerce_sellables')->update(['price_minor' => '2100']);
        }
        $prepared = $send('POST', '/brand/cart/orders', ['_token' => $token, 'quote' => $quote, 'rate' => str_starts_with($scenario, 'physical') ? 'rate-1' : 'none']);
        $orderPath = $prepared['headers']['location'][0] ?? '';
        if ($orderPath !== '') {
            if ($scenario === 'order-outage') {
                DB::connection()->beforeExecuting(static function (string $query): void {
                    if (str_starts_with(strtolower($query), 'select') && str_contains($query, 'commerce_order_lines')) {
                        throw new PDOException('Private Invoice Lane private-order-buyer@example.test invoice-store failure');
                    }
                });
            }
            $send('GET', $orderPath);
            if (in_array($scenario, ['guest-flow', 'nested-flow'], true)) {
                $send('GET', '/brand/cart/orders');
                $send('POST', $orderPath.'/export', ['_token' => $token]);
                $send('POST', $orderPath.'/cancel', ['_token' => $token]);
                $send('GET', $orderPath);
                $send('POST', $orderPath.'/erase', ['_token' => $token]);
                $send('GET', $orderPath);
            } elseif ($scenario === 'access') {
                $mail = Notification::sentNotifications();
                $secret = '';
                // Notification objects hide their body in JSON; inspect the explicit ReaderMail projection instead.
                foreach ($mail as $classes) {
                    foreach ($classes as $notifications) {
                        foreach ($notifications as $deliveries) {
                            foreach ($deliveries as $delivery) {
                                $notification = $delivery['notification'] ?? null;
                                if ($notification instanceof ReaderMail && preg_match('/\?token=([a-f0-9]{64})/', $notification->body, $secretMatches)) {
                                    $secret = $secretMatches[1];
                                }
                            }
                        }
                    }
                }
                $evidence['mailCount'] = DB::table('commerce_order_access_tokens')->count();
                $evidence['mailedSecretFound'] = $secret !== '';
                $jar = [];
                $send('GET', $orderPath);
                $send('GET', $orderPath.'/access?token='.$secret.'&private=private-query-secret');
                $evidence['consumedAfterGet'] = DB::table('commerce_order_access_tokens')->whereNotNull('consumed_at')->count();
                $send('GET', $orderPath.'/access');
                $send('POST', $orderPath.'/access', ['_token' => $token]);
                $send('GET', $orderPath);
                $jar = [];
                $send('GET', $orderPath.'/access?token='.$secret);
                $send('POST', $orderPath.'/access', ['_token' => $token]);
                $send('GET', $orderPath);
                $evidence['consumedAfterPost'] = DB::table('commerce_order_access_tokens')->whereNotNull('consumed_at')->count();
            }
        }
    }
}

$evidence['stock'] = (string) DB::table('commerce_sellables')->value('stock');
$evidence['grants'] = DB::table('entitlements')->count();
$evidence['transactionLevel'] = DB::connection()->transactionLevel();
$evidence['pdoTransaction'] = DB::connection()->getPdo()->inTransaction();
echo json_encode(['responses' => $responses, 'evidence' => $evidence, 'cached' => $app->routesAreCached(),
    'orders' => DB::table('commerce_orders')->get()->all(), 'lines' => $scenario === 'order-outage' ? [] : DB::table('commerce_order_lines')->get()->all(),
    'log' => is_file($temporary.'/storage/logs/probe.log') ? file_get_contents($temporary.'/storage/logs/probe.log') : ''], JSON_THROW_ON_ERROR);
