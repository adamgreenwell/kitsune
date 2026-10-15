<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\InvokeDeferredCallbacks;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Blueprints\StorefrontBlueprint;
use Kitsune\Commerce\Carts\CartWriter;
use Kitsune\Commerce\Catalogue\ProductWriter;
use Kitsune\Commerce\Checkout\CommerceSetupWriter;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Orders\OrderQuotes;
use Kitsune\Commerce\Orders\OrderRefused;
use Kitsune\Commerce\Orders\OrderWriter;
use Kitsune\Commerce\Payments\CheckoutReview;
use Kitsune\Commerce\Shipping\ShippoRates;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Credentials\CredentialConsoleAuthority;
use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Credentials\CredentialWriter;
use Kitsune\Core\KitsuneServiceProvider;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Modules\ModuleLifecycle;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\OrderFixture;
use Orchestra\Testbench\Foundation\Application as TestbenchApplication;

[$script, $root, $directory, $scenario] = $argv;
require $root.'/vendor/autoload.php';

/** Physical review runs on a disposable level-zero host, with only a mocked HTTP handler. */
final class CheckoutReviewApplication extends TestbenchApplication
{
    public string $database;

    protected function defineEnvironment($app): void
    {
        $app->detectEnvironment(static fn (): string => 'testing');
        $app['config']->set('database.default', 'checkout_review');
        $app['config']->set('database.connections.checkout_review', ['driver' => 'sqlite', 'database' => $this->database, 'prefix' => '', 'foreign_key_constraints' => true]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('mail.default', 'array');
        $app['config']->set('mail.mailers.array', ['transport' => 'array']);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('r', 32)));
    }
}

$application = new CheckoutReviewApplication($directory.'/app');
foreach (['bootstrap/cache', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs'] as $path) {
    mkdir($directory.'/app/'.$path, 0700, true);
}
$application->database = $directory.'/review.sqlite';
$app = $application->configure(['extra' => ['providers' => [BladeIconsServiceProvider::class, BladeHeroiconsServiceProvider::class, KitsuneServiceProvider::class], 'dont-discover' => ['*']]])->createApplication();
$app->make(HttpKernel::class)->pushMiddleware(InvokeDeferredCallbacks::class);
if (Artisan::call('migrate', ['--path' => [$root.'/packages/core/database/migrations', $root.'/tests/Commerce/migrations'], '--realpath' => true, '--force' => true]) !== 0) {
    throw new RuntimeException(Artisan::output());
}
ModuleLifecycle::install($app, 'kitsune/commerce');
ModuleLifecycle::enable($app, 'kitsune/commerce');
ModuleKernel::boot($app);
$org = Org::create(['name' => 'Physical checkout', 'slug' => 'physical-checkout', 'currency' => 'USD']);
app(Context::class)->setOrg($org);
$site = Site::create(['handle' => 'main', 'slug' => 'physical-checkout-main', 'name' => 'Main', 'locale' => 'en', 'base_url' => 'https://physical.example.test']);
app(Context::class)->setSite($site);
BlueprintApplier::apply(new StorefrontBlueprint);
$product = app(ProductWriter::class)->createProduct(['sku' => 'PARCEL', 'price_minor' => '2000', 'stock' => '5'], 'USD', [
    'title' => 'Posted parcel', 'slug' => 'parcel', 'status' => 'published', 'values' => [],
], [], true);
$setup = app(CommerceSetupWriter::class);
$setup->setSettings(['tax_mode' => 'exclusive', 'shipping_tax_mode' => 'exclusive', 'shipping_tax_class' => 'shipping',
    'ship_from_name' => 'Warehouse', 'ship_from_address' => ['line1' => '1 Warehouse Street', 'country' => 'US']]);
$setup->saveTaxRate(null, ['country' => 'US', 'region' => '*', 'tax_class' => 'standard', 'rate_parts' => 200000]);
$setup->saveTaxRate(null, ['country' => 'US', 'region' => '*', 'tax_class' => 'shipping', 'rate_parts' => 200000]);
$setup->setSalesPolicy($product->getKey(), ['tax_class' => 'standard', 'delivery_kind' => 'physical', 'weight_grams' => 500,
    'length_mm' => 300, 'width_mm' => 200, 'height_mm' => 100, 'tare_grams' => 50, 'units_per_parcel' => 1]);
CredentialConsoleAuthority::run(app(Context::class), 'set', OrderQuotes::SHIPPO_SLOT, CredentialMode::Test, null,
    static fn (CredentialConsoleAuthority $authority) => app(CredentialWriter::class)->set(OrderQuotes::SHIPPO_SLOT, CredentialMode::Test, 'shippo_test_review_token', console: $authority));
$networkLevels = [];
$response = static function (string $shipment, string $rate, string $amount, string $carrier, string $service): Response {
    return new Response(201, ['Content-Type' => 'application/json'], json_encode(['object_id' => $shipment, 'status' => 'SUCCESS', 'test' => true,
        'rates' => [['object_id' => $rate, 'shipment' => $shipment, 'amount' => $amount, 'currency' => 'USD', 'test' => true,
            'provider' => $carrier, 'servicelevel' => ['name' => $service]]]], JSON_THROW_ON_ERROR));
};
$handler = new MockHandler([
    static function () use (&$networkLevels, $response): Response {
        $networkLevels[] = DB::connection()->transactionLevel();

        return $response('old_shipment', 'old_rate', '5.00', 'USPS', 'Priority');
    },
    static function () use (&$networkLevels, $response, $scenario, $org): Response {
        $networkLevels[] = DB::connection()->transactionLevel();
        if ($scenario === 'changed') {
            DB::table('commerce_settings')->where('org_id', $org->getKey())->increment('version');
        }

        return $response('fresh_shipment', 'fresh_rate', $scenario === 'amount' ? '5.01' : '5.00',
            $scenario === 'carrier' ? 'UPS' : 'USPS', $scenario === 'service' ? 'Ground' : 'Priority');
    },
]);
$app->instance(ShippoRates::class, new ShippoRates(new Client(['handler' => HandlerStack::create($handler)])));
$request = OrderFixture::request();
app(CartWriter::class)->add($request, $product->listings()->sole()->getKey(), 2);
$quote = app(OrderQuotes::class)->quote($request, ['email' => 'physical@example.test', 'billing' => OrderFixture::billing()]);
$order = app(OrderWriter::class)->prepare($request, $quote['token'], 'old_rate');
$before = (array) DB::table('commerce_orders')->where('id', $order->getKey())->first();
DB::table('commerce_carts')->delete();
$accepted = false;
try {
    $review = app(CheckoutReview::class)->review($request, $order->reference);
    $approved = app(OrderLocks::class)->run(fn () => app(CheckoutReview::class)->approve($request, $order->reference, $review['token']));
    $accepted = $approved['record']['quoted_total_minor'] === '5400';
} catch (OrderRefused) {
    // Expected refusals are reported as data; all other crashes fail the subprocess.
}
echo json_encode(['accepted' => $accepted, 'networkLevels' => $networkLevels, 'level' => DB::connection()->transactionLevel(),
    'pdoTransaction' => DB::connection()->getPdo()->inTransaction(), 'unchanged' => $before === (array) DB::table('commerce_orders')->where('id', $order->getKey())->first(),
    'attempts' => DB::table('commerce_checkout_attempts')->count()], JSON_THROW_ON_ERROR);
