<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use App\Models\Reader;
use App\Providers\AppServiceProvider;
use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Kitsune\Commerce\Blueprints\StorefrontBlueprint;
use Kitsune\Commerce\Carts\CartOwner;
use Kitsune\Commerce\Catalogue\ProductWriter;
use Kitsune\Commerce\Http\Controllers\CheckoutController;
use Kitsune\Commerce\Inventory\StockRefused;
use Kitsune\Commerce\Inventory\StockWriter;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Commerce\Payments\CheckoutAttempts;
use Kitsune\Commerce\Payments\CheckoutReview;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Commerce\Payments\StripeCheckout;
use Kitsune\Commerce\Payments\StripeEventInbox;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\KitsuneServiceProvider;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Readers\ReaderSessions;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture;
use Kitsune\Core\Tests\Fixtures\OrderFixture;
use Kitsune\Core\Tests\Fixtures\StripeInboxFixture;
use Kitsune\Core\Tests\Fixtures\TestUser;
use Orchestra\Testbench\Foundation\Application as TestbenchApplication;

[$script, $root, $directory, $mode, $role, $scenario] = $argv;
require $root.'/vendor/autoload.php';

/** Native independent connections use the CI-selected engine and an exclusively owned table prefix. */
final class PaidStockRaceApplication extends TestbenchApplication
{
    public array $database = [];

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'paid_stock_race');
        $app['config']->set('database.connections.paid_stock_race', $this->database);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('r', 32)));
        $app['config']->set('auth.providers.users.model', TestUser::class);
        $app['config']->set('hashing.bcrypt.rounds', 4);
    }
}

function paidStockRaceOutput(string $path, array $data): void
{
    file_put_contents($path.'.tmp', json_encode($data, JSON_THROW_ON_ERROR));
    rename($path.'.tmp', $path);
}

function paidStockRaceWait(string $path): void
{
    $deadline = microtime(true) + 20;
    while (! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Paid stock race barrier timed out: '.basename($path));
        }
        usleep(5000);
    }
}

$selected = getenv('DB_CONNECTION') ?: 'testing';
$driver = in_array($selected, ['pgsql', 'mysql', 'mariadb'], true) ? $selected : 'sqlite';
$isolation = 'p'.substr(hash('sha256', $directory), 0, 5).'_';
$prefix = $driver === 'pgsql' ? '' : $isolation;
$database = $driver === 'sqlite'
    ? ['driver' => 'sqlite', 'database' => $directory.'/race.sqlite', 'foreign_key_constraints' => true, 'busy_timeout' => 5000]
    : ['driver' => $driver, 'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: ($driver === 'pgsql' ? '5432' : '3306'),
        'database' => getenv('DB_DATABASE') ?: 'kitsune', 'username' => getenv('DB_USERNAME') ?: 'kitsune',
        'password' => getenv('DB_PASSWORD') ?: 'kitsune', 'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4'];
if ($driver === 'pgsql') {
    // Core's older explicitly named indexes are schema-global on PostgreSQL. A private schema isolates them.
    $database['search_path'] = $isolation;
}
$application = new PaidStockRaceApplication($directory.'/app-'.$role);
$application->database = [...$database, 'prefix' => $prefix, 'prefix_indexes' => true];
$app = $application->configure(['extra' => ['providers' => [BladeIconsServiceProvider::class, BladeHeroiconsServiceProvider::class, KitsuneServiceProvider::class], 'dont-discover' => ['*']]])->createApplication();
if (str_starts_with($scenario, 'checkout-')) {
    config(AppServiceProvider::readerConfig());
}
$connection = DB::connection();
if ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
    throw new RuntimeException('The paid stock race must begin outside a transaction.');
}

if ($mode === 'cleanup') {
    if ($driver === 'pgsql') {
        $connection->statement('DROP SCHEMA IF EXISTS "'.$isolation.'" CASCADE');
        exit;
    }
    $schema = $connection->getSchemaBuilder();
    $tables = array_filter($schema->getTableListing(null, false), static fn (string $table): bool => str_starts_with($table, $prefix));
    $schema->disableForeignKeyConstraints();
    try {
        foreach (array_reverse(array_values($tables)) as $table) {
            // Schema grammar adds the prefix itself; strip only this probe's exact verified prefix.
            $name = substr($table, strlen($prefix));
            $schema->drop($name);
        }
    } finally {
        $schema->enableForeignKeyConstraints();
    }
    exit;
}

if ($mode === 'init') {
    if ($driver === 'pgsql') {
        $connection->statement('CREATE SCHEMA "'.$isolation.'"');
    }
    $code = Artisan::call('migrate', ['--path' => [$root.'/packages/core/database/migrations', $root.'/tests/Commerce/migrations',
        ...(str_starts_with($scenario, 'checkout-') ? [$root.'/skeleton/database/migrations/0001_01_01_000020_create_readers_table.php'] : [])], '--realpath' => true, '--force' => true]);
    if ($code !== 0) {
        throw new RuntimeException('Disposable paid stock schema migration failed.');
    }
    StripeInboxFixture::enable();
    $org = Org::create(['name' => 'Paid stock race', 'slug' => 'paid-stock-race', 'currency' => 'USD']);
    app(Context::class)->setOrg($org);
    $sites = [];
    foreach (['A', 'B'] as $label) {
        $sites[$label] = Site::create(['handle' => strtolower($label), 'slug' => 'paid-stock-'.strtolower($label), 'name' => 'Site '.$label,
            'base_url' => 'https://paid-stock-'.strtolower($label).'.example.test']);
    }
    app(Context::class)->setSite($sites['A']);
    BlueprintApplier::apply(new StorefrontBlueprint);
    $products = [];
    foreach (['FINITE' => '1', 'UNTRACKED' => null] as $sku => $stock) {
        $products[$sku] = app(ProductWriter::class)->createProduct(['sku' => $sku, 'price_minor' => '2000', 'stock' => $stock], 'USD', [
            'title' => $sku, 'slug' => strtolower($sku), 'status' => 'published', 'values' => [],
        ], [], true);
    }
    $reader = str_contains($scenario, '-reader-')
        ? Reader::createReader('read-race@example.test', Hash::make('disposable local fixture'), CarbonImmutable::now('UTC')) : null;
    $attemptIds = [];
    $sessions = [];
    foreach ($sites as $label => $site) {
        app(Context::class)->setSite($site);
        if ($label === 'B') {
            foreach ($products as $sku => $product) {
                app(ProductWriter::class)->updateProduct($product->getKey(), ['sku' => $sku, 'price_minor' => '2000', 'stock' => $product->stock], 'USD', [
                    'title' => $sku.' B', 'slug' => strtolower($sku), 'status' => 'published', 'values' => [],
                ], [], true);
            }
        }
        $lines = [];
        foreach ($products as $sku => $product) {
            $listing = $product->listings()->where('site_id', $site->id)->firstOrFail();
            $lines[] = ['sellable_ref' => $product->getKey(), 'listing_ref' => $listing->id, 'entry_ref' => $listing->entry_id,
                'sku' => $sku, 'entitlement_name' => null];
        }
        $order = OrderFixture::plant($site, reader: $reader === null ? null : (string) $reader->getKey(),
            ownerKey: str_starts_with($scenario, 'checkout-guest-') ? CartOwner::anonymousKey(str_repeat(strtolower($label), 64)) : null, overrides: ['quoted_item_net_minor' => '4000', 'quoted_tax_minor' => '200', 'quoted_total_minor' => '4200'], lineOverrides: $lines[0]);
        $firstLine = (array) $connection->table('commerce_order_lines')->where('order_id', $order->id)->first();
        unset($firstLine['id']);
        $connection->table('commerce_order_lines')->insert([...$firstLine, ...$lines[1]]);
        $record = app(OrderReaderData::class)->record($connection, $order);
        $reference = bin2hex(random_bytes(16));
        $now = time();
        $payload = CheckoutAttempts::payload($record, $reference, $now + 1860, $site->base_url);
        $attempt = ['org_id' => $org->id, 'site_id' => $site->id, 'order_id' => $order->id, 'reference' => $reference,
            'active_order_id' => null, 'mode' => 'test', 'currency' => 'USD', 'account_id' => 'acct_PaidStockRace', 'api_version' => StripeCheckout::API_VERSION,
            'credential_witness' => str_repeat('a', 64), 'state' => 'completed', 'request_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'request_hash' => CheckoutAttempts::digest($payload), 'order_hash' => CheckoutReview::orderHash($record),
            'session_id' => 'cs_test_Race'.$label, 'checkout_url' => null, 'reviewed_at' => gmdate('Y-m-d H:i:s', $now),
            'expires_at' => gmdate('Y-m-d H:i:s', $now + 1860), 'lease_token' => null, 'lease_until' => null,
            'created_at' => gmdate('Y-m-d H:i:s', $now), 'updated_at' => gmdate('Y-m-d H:i:s', $now)];
        $attempt['id'] = $connection->table('commerce_checkout_attempts')->insertGetId($attempt);
        DB::transaction(fn () => app(StripeEventInbox::class)->registerAttempt($connection, (object) $attempt));
        $attemptIds[$label] = $attempt['id'];
        $sessions[$label] = ['id' => $attempt['session_id'], 'test' => true, 'currency' => 'USD', 'amountTotal' => '4200',
            'clientReference' => $order->reference, 'attemptReference' => $reference, 'orderReference' => $order->reference,
            'expiresAt' => $now + 1860, 'paymentStatus' => 'paid', 'status' => 'complete', 'paymentIntent' => 'pi_Race'.$label, 'url' => null,
            'payment' => ['test' => true, 'intentId' => 'pi_Race'.$label, 'chargeId' => 'ch_Race'.$label, 'amount' => '4200',
                'received' => '4200', 'captured' => '4200', 'currency' => 'USD', 'refunded' => '0', 'disputed' => false]];
    }
    $owner = CredentialFixture::member();
    Auth::guard('web')->logout();
    paidStockRaceOutput($directory.'/initialized.json', ['driver' => $connection->getDriverName(), 'org' => $org->id, 'owner' => $owner->getKey(),
        'sites' => array_map(static fn (Site $site): int => $site->id, $sites), 'attempts' => $attemptIds, 'sessions' => $sessions,
        'reader' => $reader?->getKey(), 'finite' => $products['FINITE']->getKey(), 'untracked' => $products['UNTRACKED']->getKey(),
        'level' => $connection->transactionLevel(), 'pdoTransaction' => $connection->getPdo()->inTransaction()]);
    exit;
}

$initialized = json_decode((string) file_get_contents($directory.'/initialized.json'), true, flags: JSON_THROW_ON_ERROR);
app(Context::class)->setOrg(Org::findOrFail($initialized['org']));
app(Context::class)->setSite(Site::findOrFail($initialized['sites'][$role === 'B' ? 'B' : 'A']));
// Independent reader and payment processes share only this disposable database and barrier directory.
if (str_starts_with($mode, 'checkout-')) {
    if ($mode === 'checkout-writer') {
        $attempted = false;
        $connection->beforeExecuting(static function (string $query, array $bindings, $db) use ($directory, &$attempted): void {
            if (! $attempted && $db->transactionLevel() > 0 && str_contains($query, 'orgs')
                && ($db->getDriverName() === 'sqlite' ? str_starts_with(strtolower($query), 'update') : str_contains(strtolower($query), 'for update'))) {
                $attempted = true;
                paidStockRaceOutput($directory.'/checkout-writer-attempted.json', ['level' => $db->transactionLevel()]);
            }
        });
        app(OrderLocks::class)->run(function () use ($connection, $initialized): void {
            $attempt = $connection->table('commerce_checkout_attempts')->where('id', $initialized['attempts']['A'])->lockForUpdate()->sole();
            $order = $connection->table('commerce_orders')->where('id', $attempt->order_id)->lockForUpdate()->sole();
            app(PaidOrders::class)->confirm($connection, $order, $attempt, $initialized['sessions']['A']);
        });
        paidStockRaceOutput($directory.'/checkout-writer-result.json', ['level' => $connection->transactionLevel(), 'pdo' => $connection->getPdo()->inTransaction()]);
        exit;
    }
    $attempt = $connection->table('commerce_checkout_attempts')->where('id', $initialized['attempts']['A'])->sole();
    $reference = $connection->table('commerce_orders')->where('id', $attempt->order_id)->value('reference');
    $request = OrderFixture::request(app('session.store'));
    if ($initialized['reader'] !== null) {
        Auth::guard('readers')->login(Reader::query()->findOrFail($initialized['reader']));
    } else {
        $request->session()->put(ReaderSessions::EPHEMERAL.'.cart_seed', str_repeat('c', 64));
        $request->session()->put(CartOwner::sessionKey($initialized['org'], $initialized['sites']['A']), str_repeat('a', 64));
    }
    if ($mode === 'checkout-missing-reader') {
        $connection->table('readers')->where('id', $initialized['reader'])->delete();
    } elseif ($mode === 'checkout-withdrawn-guest') {
        $request->session()->forget(ReaderSessions::EPHEMERAL);
    } elseif ($mode === 'checkout-corrupt') {
        $connection->table(PaidOrders::RECEIPTS)->where('order_id', $attempt->order_id)->update(['amount_minor' => '4199']);
    }
    $route = new Route('GET', '/cart/orders/{reference}/payment/return', []);
    $route->bind(Request::create('/cart/orders/'.$reference.'/payment/return'));
    $request->setRouteResolver(static fn () => $route);
    $calls = 0;
    $app->instance(StripeCheckout::class, new StripeCheckout(new Client(['handler' => HandlerStack::create(new MockHandler([
        static function () use (&$calls): never {
            $calls++;
            throw new RuntimeException('A checkout GET must not call a provider.');
        },
    ]))])));
    $witness = static function () use ($connection): string {
        $rows = [];
        foreach (['commerce_orders', 'commerce_checkout_attempts', PaidOrders::RECEIPTS, PaidOrders::ALLOCATIONS,
            PaidOrders::OBLIGATIONS, 'commerce_stock_movements', 'commerce_sellables', 'entitlements', 'commerce_order_claims'] as $table) {
            $rows[$table] = $connection->table($table)->orderBy('id')->get()->all();
        }

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    };
    $before = $witness();
    $paused = false;
    $connection->listen(static function (QueryExecuted $query) use ($directory, $mode, &$paused): void {
        if ($mode === 'checkout-read' && ! $paused && str_starts_with(strtolower($query->sql), 'select * from') && str_contains($query->sql, 'commerce_orders')) {
            $paused = true;
            paidStockRaceOutput($directory.'/checkout-read-paused.json', ['level' => $query->connection->transactionLevel()]);
            paidStockRaceWait($directory.'/checkout-release-read');
        }
    });
    $action = substr($scenario, strrpos($scenario, '-') + 1);
    $response = app(CheckoutController::class)->$action($request);
    $after = $witness();
    paidStockRaceOutput($directory.'/'.$mode.'-result.json', ['status' => $response->getStatusCode(),
        'confirmed' => str_contains($response->getContent(), 'Test payment confirmed'),
        'pending' => str_contains($response->getContent(), 'Awaiting verified payment confirmation'),
        'orderLink' => str_contains($response->getContent(), '/cart/orders/'.$reference.'"'),
        'safeFallback' => str_contains($response->getContent(), 'href="/cart/orders"'),
        'privateBuyerVisible' => str_contains($response->getContent(), 'Historical Buyer'),
        'readOnly' => $before === $after, 'calls' => $calls,
        'payments' => $connection->table(PaidOrders::RECEIPTS)->count(),
        'allocations' => $connection->table(PaidOrders::ALLOCATIONS)->count(),
        'stock' => (string) $connection->table('commerce_sellables')->where('id', $initialized['finite'])->value('stock'),
        'level' => $connection->transactionLevel(), 'pdo' => $connection->getPdo()->inTransaction()]);
    if ($mode === 'checkout-corrupt') {
        $connection->table(PaidOrders::RECEIPTS)->where('order_id', $attempt->order_id)->update(['amount_minor' => '4200']);
    }
    exit;
}

if ($mode === 'read') {
    paidStockRaceOutput($directory.'/readback.json', ['driver' => $connection->getDriverName(),
        'stock' => (string) $connection->table('commerce_sellables')->where('id', $initialized['finite'])->value('stock'),
        'untracked' => $connection->table('commerce_sellables')->where('id', $initialized['untracked'])->value('stock'),
        'payments' => $connection->table(PaidOrders::RECEIPTS)->count(), 'allocations' => $connection->table(PaidOrders::ALLOCATIONS)->count(),
        'awarded' => (int) $connection->table(PaidOrders::ALLOCATIONS)->sum('awarded'), 'unavailable' => (int) $connection->table(PaidOrders::ALLOCATIONS)->sum('unavailable'),
        'refunds' => $connection->table(PaidOrders::OBLIGATIONS)->count(), 'owed' => (string) $connection->table(PaidOrders::OBLIGATIONS)->sum('amount_minor'),
        'movements' => $connection->table('commerce_stock_movements')->count(),
        'paidAudits' => $connection->table('audit_log')->where('action', 'commerce.order_paid')->count(),
        'grants' => $connection->table('entitlements')->count(), 'level' => $connection->transactionLevel(), 'pdoTransaction' => $connection->getPdo()->inTransaction()]);
    exit;
}

$paused = false;
$attempted = false;
$connection->listen(static function (QueryExecuted $query) use ($role, $directory, &$paused): void {
    if ($role === 'A' && ! $paused && str_starts_with(strtolower($query->sql), 'insert into') && str_contains($query->sql, 'commerce_stock_movements')) {
        $paused = true;
        paidStockRaceOutput($directory.'/A-paused.json', ['level' => $query->connection->transactionLevel(), 'pdoTransaction' => $query->connection->getPdo()->inTransaction()]);
        paidStockRaceWait($directory.'/release-A');
    }
});
$connection->beforeExecuting(static function (string $query, array $bindings, $db) use ($role, $directory, &$attempted): void {
    if ($role === 'B' && ! $attempted && $db->transactionLevel() > 0 && str_contains($query, 'orgs')
        && ($db->getDriverName() === 'sqlite' ? str_starts_with(strtolower($query), 'update') : str_contains(strtolower($query), 'for update'))) {
        $attempted = true;
        paidStockRaceOutput($directory.'/B-attempted.json', ['level' => $db->transactionLevel(), 'driver' => $db->getDriverName()]);
    }
});
$refused = false;
if ($role === 'B' && $scenario === 'manual') {
    Auth::guard('web')->setUser(TestUser::query()->findOrFail($initialized['owner']));
    try {
        app(StockWriter::class)->adjust((string) $initialized['finite'], '1', '5', 'Restocking from an old screen.');
    } catch (StockRefused) {
        $refused = true;
    }
} else {
    app(OrderLocks::class)->run(function () use ($connection, $initialized, $role): void {
        $attempt = $connection->table('commerce_checkout_attempts')->where('id', $initialized['attempts'][$role])->lockForUpdate()->first();
        $order = $connection->table('commerce_orders')->where('id', $attempt->order_id)->lockForUpdate()->first();
        app(PaidOrders::class)->confirm($connection, $order, $attempt, $initialized['sessions'][$role]);
    });
}
paidStockRaceOutput($directory.'/'.$role.'-result.json', ['refused' => $refused, 'level' => $connection->transactionLevel(), 'pdoTransaction' => $connection->getPdo()->inTransaction()]);
