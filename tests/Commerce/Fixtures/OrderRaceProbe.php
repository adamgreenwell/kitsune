<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Blueprints\StorefrontBlueprint;
use Kitsune\Commerce\Carts\CartWriter;
use Kitsune\Commerce\Catalogue\ProductWriter;
use Kitsune\Commerce\Checkout\CommerceSetupWriter;
use Kitsune\Commerce\Orders\OrderQuotes;
use Kitsune\Commerce\Orders\OrderRefused;
use Kitsune\Commerce\Orders\OrderWriter;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\KitsuneServiceProvider;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleLifecycle;
use Kitsune\Core\Tenancy\Context;
use Orchestra\Testbench\Foundation\Application as TestbenchApplication;

[$script, $root, $directory, $mode, $role, $scenario] = $argv;
require $root.'/vendor/autoload.php';

/** Separate process and connection, without RefreshDatabase or a fake transaction manager. @internal */
final class OrderRaceApplication extends TestbenchApplication
{
    public string $database;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'order_race');
        $app['config']->set('database.connections.order_race', ['driver' => 'sqlite', 'database' => $this->database, 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 5000]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('o', 32)));
    }
}

function orderRaceOutput(string $path, array $data): void
{
    file_put_contents($path.'.tmp', json_encode($data, JSON_THROW_ON_ERROR));
    rename($path.'.tmp', $path);
}

function orderRaceWait(string $path): void
{
    $deadline = microtime(true) + 15;
    while (! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Order race barrier timed out: '.basename($path));
        }
        usleep(5000);
    }
}

$application = new OrderRaceApplication($directory.'/app-'.$role);
$application->database = $directory.'/race.sqlite';
$app = $application->configure(['extra' => ['providers' => [BladeIconsServiceProvider::class, BladeHeroiconsServiceProvider::class, KitsuneServiceProvider::class], 'dont-discover' => ['*']]])->createApplication();
$db = DB::connection();
if ($db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
    throw new RuntimeException('The order race did not begin outside a transaction.');
}

$request = Request::create('https://orders.example.test/cart');
$request->setLaravelSession(app('session.store'));
$request->session()->start();
$writer = app(OrderWriter::class);

if ($mode === 'init') {
    $code = Artisan::call('migrate', ['--path' => [$root.'/packages/core/database/migrations', $root.'/tests/Commerce/migrations'], '--realpath' => true, '--force' => true]);
    if ($code !== 0) {
        throw new RuntimeException(Artisan::output());
    }
    ModuleLifecycle::install($app, 'kitsune/commerce');
    ModuleLifecycle::enable($app, 'kitsune/commerce');
    $org = Org::create(['name' => 'Order race', 'slug' => 'order-race', 'currency' => 'USD']);
    app(Context::class)->setOrg($org);
    $site = Site::create(['handle' => 'main', 'slug' => 'order-race-main', 'name' => 'Main', 'locale' => 'en', 'base_url' => 'https://orders.example.test']);
    app(Context::class)->setSite($site);
    BlueprintApplier::apply(new StorefrontBlueprint);
    $product = app(ProductWriter::class)->createProduct(['sku' => 'ORDER-RACE', 'price_minor' => '2000', 'stock' => '5'], 'USD', [
        'title' => 'Race course', 'slug' => 'race-course', 'status' => 'published', 'values' => [],
    ], [], true);
    $setup = app(CommerceSetupWriter::class);
    $setup->setSettings(['tax_mode' => 'exclusive', 'shipping_tax_mode' => 'exclusive', 'shipping_tax_class' => 'shipping']);
    $setup->saveTaxRate(null, ['country' => 'US', 'region' => '*', 'tax_class' => 'standard', 'rate_parts' => 0]);
    $setup->setSalesPolicy($product->getKey(), ['tax_class' => 'standard', 'delivery_kind' => 'digital']);
    app(CartWriter::class)->add($request, $product->listings()->firstOrFail()->getKey(), 2);
    $quotes = app(OrderQuotes::class);
    $details = ['email' => 'race-buyer@example.test', 'billing' => ['name' => 'Race Buyer', 'address' => ['line1' => '1 Private Race Lane', 'country' => 'US']]];
    $a = $quotes->quote($request, $details);
    $b = $scenario === 'same-request' ? $a : $quotes->quote($request, $details);
    orderRaceOutput($directory.'/initialized.json', ['org' => $org->getKey(), 'site' => $site->getKey(), 'tokens' => ['A' => $a['token'], 'B' => $b['token']],
        'session' => $request->session()->all(), 'level' => $db->transactionLevel(), 'pdoTransaction' => $db->getPdo()->inTransaction()]);
    exit;
}

$initialized = json_decode((string) file_get_contents($directory.'/initialized.json'), true, flags: JSON_THROW_ON_ERROR);
app(Context::class)->setOrg(Org::findOrFail($initialized['org']));
app(Context::class)->setSite(Site::findOrFail($initialized['site']));
$request->session()->replace($initialized['session']);

if ($mode === 'read') {
    orderRaceOutput($directory.'/readback.json', ['orders' => DB::table('commerce_orders')->count(), 'lines' => DB::table('commerce_order_lines')->count(),
        'audits' => DB::table('audit_log')->where('action', 'commerce.order_prepared')->count(), 'stock' => (string) DB::table('commerce_sellables')->value('stock'),
        'grants' => DB::table('entitlements')->count(), 'level' => $db->transactionLevel(), 'pdoTransaction' => $db->getPdo()->inTransaction()]);
    exit;
}

if ($scenario === 'outer-rollback') {
    $db->beginTransaction();
    $rolled = $writer->prepare($request, $initialized['tokens']['A'], 'none');
    $during = DB::table('commerce_orders')->count();
    $db->rollBack();
    $after = DB::table('commerce_orders')->count();
    $retried = $writer->prepare($request, $initialized['tokens']['A'], 'none');
    orderRaceOutput($directory.'/rollback.json', ['during' => $during, 'after' => $after, 'retry' => $retried->state,
        'orders' => DB::table('commerce_orders')->count(), 'lines' => DB::table('commerce_order_lines')->count(),
        'audits' => DB::table('audit_log')->where('action', 'commerce.order_prepared')->count(),
        'level' => $db->transactionLevel(), 'pdoTransaction' => $db->getPdo()->inTransaction()]);
    exit;
}

$paused = false;
$attempted = false;
$db->listen(static function (QueryExecuted $query) use ($role, $directory, &$paused): void {
    if ($role !== 'A' || $paused || preg_match('/\Ainsert\s+into\s+["`]?commerce_orders["`]?(?:\s|\()/i', $query->sql) !== 1) {
        return;
    }
    $paused = true;
    orderRaceOutput($directory.'/A-paused.json', ['level' => $query->connection->transactionLevel(), 'pdoTransaction' => $query->connection->getPdo()->inTransaction()]);
    orderRaceWait($directory.'/release-A');
});
$db->beforeExecuting(static function (string $query, array $bindings, $connection) use ($role, $directory, &$attempted): void {
    if ($role === 'B' && ! $attempted && $connection->transactionLevel() === 1 && preg_match('/\Aupdate\s+["`]?orgs["`]?\s/i', $query) === 1) {
        $attempted = true;
        orderRaceOutput($directory.'/B-attempted.json', ['level' => $connection->transactionLevel()]);
    }
});
try {
    $order = $writer->prepare($request, $initialized['tokens'][$role], 'none');
    $result = ['success' => true, 'reason' => null, 'id' => $order->getKey()];
} catch (OrderRefused $failure) {
    $result = ['success' => false, 'reason' => $failure->reason, 'id' => null];
}
orderRaceOutput($directory.'/'.$role.'-result.json', [...$result, 'level' => $db->transactionLevel(), 'pdoTransaction' => $db->getPdo()->inTransaction()]);
