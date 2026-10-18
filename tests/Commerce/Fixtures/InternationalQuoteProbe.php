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
use Kitsune\Commerce\Exports\ExportFormat;
use Kitsune\Commerce\Exports\OrderExportRecords;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Orders\OrderQuotes;
use Kitsune\Commerce\Orders\OrderReaderData;
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
final class InternationalQuoteApplication extends TestbenchApplication
{
    public string $database;

    protected function defineEnvironment($app): void
    {
        $app->detectEnvironment(static fn (): string => 'testing');
        $app['config']->set('database.default', 'international_quote');
        $app['config']->set('database.connections.international_quote', ['driver' => 'sqlite', 'database' => $this->database, 'prefix' => '', 'foreign_key_constraints' => true]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('mail.default', 'array');
        $app['config']->set('mail.mailers.array', ['transport' => 'array']);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('r', 32)));
    }
}

$application = new InternationalQuoteApplication($directory.'/app');
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
$settings = $setup->setSettings(['tax_mode' => $scenario === 'inclusive' ? 'inclusive' : 'exclusive', 'shipping_tax_mode' => 'exclusive', 'shipping_tax_class' => 'shipping',
    'international_shipping' => ['destinations' => ['CA'], 'carrier_accounts' => ['test' => [str_repeat('a', 32)], 'live' => []], 'certify_signer' => 'Explicit Certifier', 'certified' => true],
    'ship_from_name' => 'Warehouse', 'ship_from_address' => ['line1' => '1 Warehouse Street', 'country' => 'US']]);
$setup->saveTaxRate(null, ['country' => 'CA', 'region' => '*', 'tax_class' => 'standard', 'rate_parts' => 200000]);
$setup->saveTaxRate(null, ['country' => 'CA', 'region' => '*', 'tax_class' => 'shipping', 'rate_parts' => 200000]);
$policy = $setup->setSalesPolicy($product->getKey(), ['tax_class' => 'standard', 'delivery_kind' => 'physical', 'weight_grams' => 500,
    'length_mm' => 300, 'width_mm' => 200, 'height_mm' => 100, 'tare_grams' => 50, 'units_per_parcel' => 1, 'customs_description' => 'Printed book', 'customs_origin_country' => 'GB', 'customs_tariff_number' => '490199']);
CredentialConsoleAuthority::run(app(Context::class), 'set', OrderQuotes::SHIPPO_SLOT, CredentialMode::Test, null,
    static fn (CredentialConsoleAuthority $authority) => app(CredentialWriter::class)->set(OrderQuotes::SHIPPO_SLOT, CredentialMode::Test, 'shippo_test_review_token', console: $authority));

$mutate = static function () use ($scenario, $org, $policy, $settings): void {
    if (str_contains($scenario, 'customs')) {
        DB::table('commerce_sales_policies')->where('id', $policy->id)->update(['customs_description' => 'Changed customs description']);
    } elseif (str_contains($scenario, 'accounts') || str_contains($scenario, 'destination') || str_contains($scenario, 'certifier')) {
        $profile = $settings->international_shipping;
        if (str_contains($scenario, 'accounts')) {
            $profile['carrier_accounts']['test'] = [str_repeat('c', 32)];
        } elseif (str_contains($scenario, 'destination')) {
            $profile['destinations'] = ['GB'];
        } else {
            $profile['certify_signer'] = 'Changed Certifier';
        }
        DB::table('commerce_settings')->where('id', $settings->id)->update(['international_shipping' => json_encode($profile, JSON_THROW_ON_ERROR)]);
    } elseif (str_contains($scenario, 'mode')) {
        DB::table('org_credential_modes')->updateOrInsert(['org_id' => $org->id], ['mode' => 'live', 'changed_at' => now()]);
    } elseif (str_contains($scenario, 'credential')) {
        DB::table('credentials')->where('org_id', $org->id)->update(['ciphertext' => 'changed']);
    } else {
        DB::table('commerce_settings')->where('org_id', $org->id)->increment('version');
    }
};
$network = [];
$handler = new MockHandler(array_fill(0, 3, static function ($request) use (&$network, $scenario, $mutate): Response {
    $network[] = ['level' => DB::connection()->transactionLevel(), 'pdo' => DB::connection()->getPdo()->inTransaction(),
        'payload' => json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR)];
    if ((count($network) === 1 && str_starts_with($scenario, 'quote-change')) || (count($network) === 2 && str_starts_with($scenario, 'review-change'))) {
        $mutate();
    }

    return new Response(201, ['Content-Type' => 'application/json'], json_encode(['object_id' => 'shipment-1', 'status' => 'SUCCESS', 'test' => true,
        'rates' => [['object_id' => 'rate-1', 'shipment' => 'shipment-1', 'amount' => '5.00', 'currency' => 'USD', 'test' => true,
            'carrier_account' => str_repeat('a', 32), 'provider' => 'USPS', 'servicelevel' => ['name' => 'Priority']]]], JSON_THROW_ON_ERROR));
}));
$app->instance(ShippoRates::class, new ShippoRates(new Client(['handler' => HandlerStack::create($handler)])));
$request = OrderFixture::request();
app(CartWriter::class)->add($request, $product->listings()->sole()->getKey(), 2);
$details = ['email' => 'physical@example.test', 'billing' => OrderFixture::billing(),
    'delivery' => ['name' => 'Canadian Buyer', 'address' => ['line1' => '1 Destination', 'country' => 'CA']]];
if ($scenario === 'incomplete') {
    DB::table('commerce_sales_policies')->where('id', $policy->id)->update(['customs_tariff_number' => null]);
} elseif ($scenario === 'zero') {
    DB::table('commerce_sellables')->where('id', $product->id)->update(['price_minor' => 0]);
} elseif ($scenario === 'destination') {
    $details['delivery']['address']['country'] = 'GB';
    $setup->saveTaxRate(null, ['country' => 'GB', 'region' => '*', 'tax_class' => 'standard', 'rate_parts' => 0]);
} elseif ($scenario === 'no-accounts') {
    $profile = $settings->international_shipping;
    $profile['carrier_accounts'] = ['test' => [], 'live' => [str_repeat('b', 32)]];
    DB::table('commerce_settings')->where('id', $settings->id)->update(['international_shipping' => json_encode($profile, JSON_THROW_ON_ERROR)]);
}
$attackSite = static function () use ($scenario): void {
    if (str_contains($scenario, 'org')) {
        app(Context::class)->setOrg(Org::create(['name' => 'Rival', 'slug' => 'international-rival', 'currency' => 'USD']));
    }
    $site = Site::create(['handle' => 'rival', 'slug' => 'international-sibling', 'name' => 'Rival', 'locale' => 'en', 'base_url' => 'https://rival.example.test']);
    app(Context::class)->setSite($site);
};
$stage = 'quote';
$accepted = false;
$record = null;
$shippingExport = null;
$replay = false;
try {
    $quote = app(OrderQuotes::class)->quote($request, $details);
    $stage = 'prepare';
    if (str_starts_with($scenario, 'prepare-attack')) {
        $attackSite();
    }
    if (str_starts_with($scenario, 'prepare-change')) {
        $mutate();
    }
    $order = app(OrderWriter::class)->prepare($request, $quote['token'], 'rate-1');
    $record = app(OrderReaderData::class)->record(DB::connection(), (object) $order->getAttributes());
    $shippingExport = app(OrderLocks::class)->run(function () use ($order, $org): ?array {
        foreach (app(OrderExportRecords::class)->forOrder(DB::connection(), (object) $order->getAttributes(), $org->slug, false) as $record) {
            foreach (['jsonl', 'csv'] as $format) {
                (new ExportFormat($format))->encode($record);
            }
            if ($record['type'] === 'shipping') {
                return $record;
            }
        }

        return null;
    });
    $replay = app(OrderWriter::class)->prepare($request, $quote['token'], 'rate-1')->id === $order->id;
    $stage = 'review';
    if (str_starts_with($scenario, 'review-attack')) {
        $attackSite();
    }
    $review = app(CheckoutReview::class)->review($request, $order->reference);
    $stage = 'approve';
    if (str_starts_with($scenario, 'approve-change')) {
        $mutate();
    }
    app(OrderLocks::class)->run(fn () => app(CheckoutReview::class)->approve($request, $order->reference, $review['token']));
    $accepted = true;
} catch (OrderRefused) {
    // Only expected refusal. Provider fixture errors and assertion failures must fail the probe.
}
echo json_encode(['accepted' => $accepted, 'stage' => $stage, 'network' => $network, 'record' => $record, 'replay' => $replay,
    'shippingExport' => $shippingExport, 'orders' => DB::table('commerce_orders')->count(), 'attempts' => DB::table('commerce_checkout_attempts')->count(),
    'stock' => (string) DB::table('commerce_sellables')->where('id', $product->id)->value('stock')], JSON_THROW_ON_ERROR);
