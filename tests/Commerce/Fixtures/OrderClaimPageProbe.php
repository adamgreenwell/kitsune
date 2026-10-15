<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
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
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Orders\OrderQuotes;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Commerce\Payments\CheckoutAttempts;
use Kitsune\Commerce\Payments\CheckoutReview;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Commerce\Payments\StripeCheckout;
use Kitsune\Commerce\Payments\StripeEventInbox;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Credentials\CredentialConsoleAuthority;
use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Credentials\CredentialWriter;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Modules\ModuleLifecycle;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;

define('KITSUNE_CATALOGUE_PROBE_CLASSES_ONLY', true);
require __DIR__.'/PublicCataloguePageProbe.php';

/** Native host and HTTP kernel, including deferred mail and a real, cacheable route collection. @internal */
final class OrderClaimPageApplication
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
    file_put_contents($temporary.'/bootstrap/app.php', '<?php return OrderClaimPageApplication::make('.var_export($root, true).', '.var_export($temporary, true).', '.var_export($scenario, true).');');
}

if ($step === 'request' && $scenario === 'provider-absent-outage') {
    $database = new PDO('sqlite:'.$temporary.'/catalogue.sqlite');
    $database->exec("update modules set is_enabled = 0 where handle = 'kitsune/commerce'");
    unset($database);
}

$app = OrderClaimPageApplication::make($root, $temporary, $scenario, $step === 'seed');
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
    ReaderFixture::mode($org, 'open');
    ReaderFixture::reader($org, 'claim-reader@example.test');
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
$send('GET', '/brand/cart');
$send('POST', '/brand/cart/add', ['_token' => $token, 'listing' => $listing, 'quantity' => '1']);
$send('GET', '/brand/cart/checkout');
$quoted = $send('POST', '/brand/cart/quote', ['_token' => $token, ...$body]);
preg_match('/name="quote" value="([a-f0-9]{64})"/', $quoted['body'], $matches);
$prepared = $send('POST', '/brand/cart/orders', ['_token' => $token, 'quote' => $matches[1] ?? '', 'rate' => 'none']);
$orderPath = $prepared['headers']['location'][0] ?? throw new RuntimeException('The native guest order was not prepared.');
$site = Site::withoutScopeBecause('native claim fixture bootstrap', fn ($query) => $query->where('slug', 'order-main')->firstOrFail());
app(Context::class)->setSite($site);
$order = DB::table('commerce_orders')->sole();
$now = CarbonImmutable::now('UTC')->startOfSecond();
$expires = $now->addSeconds(1860);
$reference = bin2hex(random_bytes(16));
$record = app(OrderReaderData::class)->record(DB::connection(), $order);
$payload = CheckoutAttempts::payload($record, $reference, $expires->timestamp, 'https://application.example.test'.($site->path_prefix ?? ''));
$attempt = ['org_id' => $order->org_id, 'site_id' => $order->site_id, 'order_id' => $order->id, 'reference' => $reference, 'active_order_id' => null,
    'mode' => 'test', 'currency' => 'USD', 'account_id' => 'acct_ClaimPageFixture', 'api_version' => StripeCheckout::API_VERSION,
    'credential_witness' => str_repeat('a', 64), 'state' => 'completed', 'request_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
    'request_hash' => CheckoutAttempts::digest($payload), 'order_hash' => CheckoutReview::orderHash($record),
    'session_id' => 'cs_test_Claim'.$reference, 'checkout_url' => null, 'reviewed_at' => $now->format('Y-m-d H:i:s'), 'expires_at' => $expires->format('Y-m-d H:i:s'),
    'lease_token' => null, 'lease_until' => null, 'created_at' => $now->format('Y-m-d H:i:s'), 'updated_at' => $now->format('Y-m-d H:i:s')];
$attempt['id'] = DB::table('commerce_checkout_attempts')->insertGetId($attempt);
DB::transaction(fn () => app(StripeEventInbox::class)->registerAttempt(DB::connection(), (object) $attempt));
$amount = $order->quoted_total_minor;
$session = ['id' => $attempt['session_id'], 'test' => true, 'currency' => 'USD', 'amountTotal' => (string) $amount,
    'clientReference' => $order->reference, 'attemptReference' => $reference, 'orderReference' => $order->reference,
    'expiresAt' => $expires->timestamp, 'paymentStatus' => 'paid', 'status' => 'complete', 'url' => null, 'paymentIntent' => 'pi_'.$reference,
    'payment' => ['test' => true, 'intentId' => 'pi_'.$reference, 'chargeId' => 'ch_'.$reference, 'amount' => (string) $amount, 'received' => (string) $amount,
        'captured' => (string) $amount, 'currency' => 'USD', 'refunded' => '0', 'disputed' => false]];
app(OrderLocks::class)->run(fn () => app(PaidOrders::class)->confirm(DB::connection(), $order, (object) $attempt, $session));
$original = DB::table('commerce_orders')->sole();
$originalLines = DB::table('commerce_order_lines')->get()->all();
$evidence['grantsBeforeClaim'] = DB::table('entitlements')->count();
$claimPath = ($site->path_prefix ?? '').'/account/order-claims/'.$order->reference;
$signIn = ($site->path_prefix ?? '').'/account/sign-in';
$send('GET', $orderPath);
$send('GET', $claimPath.'?unknown=private-claim-query');
$send('HEAD', $claimPath);
$evidence['claimsAfterGets'] = DB::table('commerce_order_claims')->count();
$send('POST', $claimPath);
$send('GET', $signIn);
$send('POST', $signIn, ['_token' => $token, 'email' => 'claim-reader@example.test', 'password' => ReaderFixture::PASSWORD]);
$send('GET', $claimPath);
$send('POST', $claimPath.'?_token='.$token.'&unknown=private-claim-query', ['reader' => 'another-reader', 'unknown' => 'private-claim-body'], ['HTTP_X_CSRF_TOKEN' => $token]);
$send('POST', $claimPath, ['_token' => $token, 'reader' => 'ignored-reader', 'unknown' => 'private-claim-body']);
$send('GET', $orderPath);
$send('GET', ($site->path_prefix ?? '').'/cart/orders');
$send('POST', $claimPath, ['_token' => $token]);
$send('GET', '/sibling/account/order-claims/'.$order->reference);
$send('POST', ($site->path_prefix ?? '').'/account/sign-out', ['_token' => $token]);
$send('GET', $orderPath);
$evidence['originalUnchanged'] = (array) $original === (array) DB::table('commerce_orders')->sole();
$evidence['linesUnchanged'] = $originalLines == DB::table('commerce_order_lines')->get()->all();
$evidence['grants'] = DB::table('entitlements')->count();
$evidence['claimAudits'] = DB::table('audit_log')->where('action', 'commerce.order_claimed')->count();
$evidence['claims'] = DB::table('commerce_order_claims')->count();
$evidence['work'] = DB::table('commerce_access_work')->sole()->state;
$evidence['tokens'] = DB::table('commerce_order_access_tokens')->count();
$evidence['transactionLevel'] = DB::connection()->transactionLevel();
$evidence['pdoTransaction'] = DB::connection()->getPdo()->inTransaction();
echo json_encode(['responses' => $responses, 'evidence' => $evidence, 'orderPath' => $orderPath, 'claimPath' => $claimPath,
    'cached' => $app->routesAreCached(), 'log' => is_file($temporary.'/storage/logs/probe.log') ? file_get_contents($temporary.'/storage/logs/probe.log') : ''], JSON_THROW_ON_ERROR);
