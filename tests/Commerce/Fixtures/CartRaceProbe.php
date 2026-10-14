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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Blueprints\StorefrontBlueprint;
use Kitsune\Commerce\Carts\CartOwner;
use Kitsune\Commerce\Carts\CartRefused;
use Kitsune\Commerce\Carts\CartSessions;
use Kitsune\Commerce\Carts\CartWriter;
use Kitsune\Commerce\Catalogue\ProductWriter;
use Kitsune\Commerce\Models\Cart;
use Kitsune\Commerce\Models\CartLine;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\KitsuneServiceProvider;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleLifecycle;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\TestReader;
use Orchestra\Testbench\Foundation\Application as TestbenchApplication;

[$script, $root, $directory, $mode, $role, $scenario] = $argv;
require $root.'/vendor/autoload.php';

/** Native process: no RefreshDatabase and no test transaction manager. */
final class CartRaceApplication extends TestbenchApplication
{
    public array $database = [];

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'race');
        $app['config']->set('database.connections.race', $this->database);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('c', 32)));
        $app['config']->set('auth.guards.cart_readers', ['driver' => 'session', 'provider' => 'cart_readers']);
        $app['config']->set('auth.providers.cart_readers', ['driver' => 'eloquent', 'model' => TestReader::class]);
        $app['config']->set(ReaderGuard::CONFIG, 'cart_readers');
    }
}

function cartRaceOutput(string $path, array $data): void
{
    file_put_contents($path.'.tmp', json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    rename($path.'.tmp', $path);
}

function cartRaceWait(string $path): void
{
    $deadline = microtime(true) + 15;
    while (! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Cart race barrier timed out: '.basename($path));
        }
        usleep(5000);
    }
}

$application = new CartRaceApplication($directory.'/app-'.$role);
$application->database = ['driver' => 'sqlite', 'database' => $directory.'/race.sqlite', 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 5000];
$app = $application->configure(['extra' => [
    'providers' => [BladeIconsServiceProvider::class, BladeHeroiconsServiceProvider::class, KitsuneServiceProvider::class],
    'dont-discover' => ['*'],
]])->createApplication();
$db = DB::connection();
if ($db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
    throw new RuntimeException('The cart probe did not start at actual transaction level zero.');
}

if ($mode === 'init') {
    $code = Artisan::call('migrate', ['--database' => 'race', '--path' => [$root.'/packages/core/database/migrations', $root.'/tests/Core/migrations'], '--realpath' => true, '--force' => true]);
    if ($code !== 0) {
        throw new RuntimeException('Disposable cart schema migration failed: '.Artisan::output());
    }
    ModuleLifecycle::install($app, 'kitsune/commerce');
    ModuleLifecycle::enable($app, 'kitsune/commerce');
    $org = Org::create(['name' => 'Cart race', 'slug' => 'cart-race', 'currency' => 'USD']);
    app(Context::class)->setOrg($org);
    $site = Site::create(['handle' => 'main', 'slug' => 'cart-race-main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($site);
    BlueprintApplier::apply(new StorefrontBlueprint);
    $listings = [];
    foreach (['A', 'B'] as $sku) {
        $product = app(ProductWriter::class)->createProduct(['sku' => $sku, 'price_minor' => '2000', 'stock' => null], 'USD', [
            'title' => 'Race product '.$sku, 'slug' => strtolower($sku), 'status' => 'published', 'values' => [],
        ], [], true);
        $listings[] = $product->listings()->firstOrFail()->getKey();
    }
    $reader = TestReader::create(['email' => 'cart-race@example.test']);
    cartRaceOutput($directory.'/initialized.json', ['org' => $org->getKey(), 'site' => $site->getKey(), 'listings' => $listings, 'reader' => $reader->getKey(), 'level' => $db->transactionLevel(), 'pdo_in_transaction' => $db->getPdo()->inTransaction()]);
} else {
    $initialized = json_decode((string) file_get_contents($directory.'/initialized.json'), true, flags: JSON_THROW_ON_ERROR);
    app(Context::class)->setOrg(Org::findOrFail($initialized['org']));
    app(Context::class)->setSite(Site::findOrFail($initialized['site']));
    $listings = $initialized['listings'];
}

$request = Request::create('/cart');
$request->setLaravelSession(app('session.store'));
$request->session()->start();
$request->session()->put(CartOwner::sessionKey(app(Context::class)->orgId(), app(Context::class)->siteId()), str_repeat('c', 64));
$writer = app(CartWriter::class);

if ($mode === 'init') {
    if (in_array($scenario, ['stale-update', 'adopt-rollback'], true)) {
        $writer->add($request, $listings[0], $scenario === 'stale-update' ? 1 : 2);
    }
    exit(0);
}

if ($mode === 'read') {
    cartRaceOutput($directory.'/readback.json', ['cart_count' => Cart::query()->count(), 'version' => Cart::query()->firstOrFail()->version,
        'quantities' => CartLine::query()->orderBy('id')->pluck('quantity')->all(), 'level' => $db->transactionLevel(), 'pdo_in_transaction' => $db->getPdo()->inTransaction()]);
    exit(0);
}

if ($scenario === 'adopt-rollback') {
    $reader = TestReader::findOrFail($initialized['reader']);
    $slot = CartOwner::sessionKey($initialized['org'], $initialized['site']);
    $pending = CartSessions::pendingKey($initialized['org'], $initialized['site']);
    $request->session()->put($pending, CartOwner::readerKey((string) $reader->getKey()));
    $db->beginTransaction();
    $writer->adopt($request->session(), (string) $reader->getKey());
    $state = ['outer_level' => $db->transactionLevel(), 'guest_during_outer' => $request->session()->has($slot), 'pending_during_outer' => $request->session()->has($pending)];
    $db->rollBack();
    $state += ['source_after_rollback' => Cart::query()->whereNull('reader_id')->count(), 'reader_after_rollback' => Cart::query()->whereNotNull('reader_id')->count(),
        'guest_after_rollback' => $request->session()->has($slot), 'pending_after_rollback' => $request->session()->has($pending)];
    Auth::guard('cart_readers')->setUser($reader);
    $state['retry_blocked'] = app(CartSessions::class)->retryPending($request);
    app(CartSessions::class)->retryPending($request);
    $state += ['source_after_retry' => Cart::query()->whereNull('reader_id')->count(), 'reader_after_retry' => Cart::query()->whereNotNull('reader_id')->count(),
        'guest_after_retry' => $request->session()->has($slot), 'pending_after_retry' => $request->session()->has($pending),
        'quantities' => CartLine::query()->orderBy('id')->pluck('quantity')->all(), 'version' => Cart::query()->firstOrFail()->version,
        'level' => $db->transactionLevel(), 'pdo_in_transaction' => $db->getPdo()->inTransaction()];
    cartRaceOutput($directory.'/adoption.json', $state);
    exit(0);
}

$paused = false;
$attempted = false;
$db->listen(function (QueryExecuted $query) use ($role, $directory, &$paused): void {
    if ($role !== 'A' || $paused || preg_match('/\A(?:insert\s+into|update)\s+["`]?commerce_cart_lines["`]?(?:\s|\()/i', $query->sql) !== 1) {
        return;
    }
    $paused = true;
    cartRaceOutput($directory.'/A-paused.json', ['level' => $query->connection->transactionLevel(), 'pdo_in_transaction' => $query->connection->getPdo()->inTransaction()]);
    cartRaceWait($directory.'/release-A');
});
$db->beforeExecuting(function (string $query, array $bindings, $connection) use ($role, $directory, &$attempted): void {
    if ($role === 'B' && ! $attempted && $connection->transactionLevel() === 1 && preg_match('/\Aupdate\s+["`]?orgs["`]?\s/i', $query) === 1) {
        $attempted = true;
        cartRaceOutput($directory.'/B-attempted.json', ['level' => $connection->transactionLevel()]);
    }
});

try {
    if ($scenario === 'stale-update') {
        $writer->update($request, CartLine::query()->firstOrFail()->getKey(), $role === 'A' ? 2 : 3, 1);
    } else {
        $writer->add($request, $role === 'A' || $scenario === 'duplicate-create' ? $listings[0] : $listings[1], $role === 'A' ? 2 : ($scenario === 'duplicate-create' ? 5 : 3));
    }
    $result = ['success' => true, 'reason' => null];
} catch (CartRefused $failure) {
    $result = ['success' => false, 'reason' => $failure->reason];
}
$result += ['level' => $db->transactionLevel(), 'pdo_in_transaction' => $db->getPdo()->inTransaction()];
cartRaceOutput($directory.'/'.$role.'-result.json', $result);
