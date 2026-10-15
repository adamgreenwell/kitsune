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
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Carts\CartOwner;
use Kitsune\Commerce\Orders\OrderClaims;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Orders\OrderRefused;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Core\KitsuneServiceProvider;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Readers\ReaderSessions;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\OrderFixture;
use Kitsune\Core\Tests\Fixtures\PaidOrderFixture;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;
use Kitsune\Core\Tests\Fixtures\TestUser;
use Orchestra\Testbench\Foundation\Application as TestbenchApplication;

[$script, $root, $directory, $mode, $role] = $argv;
require $root.'/vendor/autoload.php';

/** Native independent connections use the CI-selected engine and an exclusively owned table prefix. */
final class OrderClaimRaceApplication extends TestbenchApplication
{
    public array $database = [];

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'order_claim_race');
        $app['config']->set('database.connections.order_claim_race', $this->database);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('r', 32)));
        $app['config']->set('auth.providers.users.model', TestUser::class);
        $app['config']->set('hashing.bcrypt.rounds', 4);
        $app['config']->set(AppServiceProvider::readerConfig());
    }
}

function orderClaimRaceOutput(string $path, array $data): void
{
    file_put_contents($path.'.tmp', json_encode($data, JSON_THROW_ON_ERROR));
    rename($path.'.tmp', $path);
}

function orderClaimRaceWait(string $path): void
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
$application = new OrderClaimRaceApplication($directory.'/app-'.$role);
$application->database = [...$database, 'prefix' => $prefix, 'prefix_indexes' => true];
$app = $application->configure(['extra' => ['providers' => [BladeIconsServiceProvider::class, BladeHeroiconsServiceProvider::class, KitsuneServiceProvider::class], 'dont-discover' => ['*']]])->createApplication();
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
        $root.'/skeleton/database/migrations/0001_01_01_000020_create_readers_table.php'], '--realpath' => true, '--force' => true]);
    if ($code !== 0) {
        throw new RuntimeException('Disposable order claim schema migration failed.');
    }
    $org = Org::create(['name' => 'Order claim race', 'slug' => 'order-claim-race', 'currency' => 'USD']);
    app(Context::class)->setOrg($org);
    $site = Site::create(['handle' => 'main', 'slug' => 'order-claim-main', 'name' => 'Main', 'base_url' => 'https://order-claim.example.test']);
    ReaderFixture::mode($org, 'sign-in');
    $readers = [];
    foreach (['A', 'B'] as $label) {
        $readers[$label] = ReaderFixture::reader($org, strtolower($label).'@claim.example.test')->getKey();
    }
    app(Context::class)->setSite($site);
    $attempt = PaidOrderFixture::attempt($site);
    $seed = bin2hex(random_bytes(32));
    $secret = hash_hmac('sha256', "kitsune.cart.site\0".$org->getKey()."\0".$site->getKey(), $seed);
    $connection->table('commerce_orders')->where('id', $attempt->order_id)->update(['owner_key' => CartOwner::anonymousKey($secret)]);
    app(OrderLocks::class)->run(fn () => app(PaidOrders::class)->confirm($connection,
        $connection->table('commerce_orders')->where('id', $attempt->order_id)->sole(), $attempt,
        PaidOrderFixture::session($attempt)));
    $order = $connection->table('commerce_orders')->where('id', $attempt->order_id)->sole();
    OrderFixture::token($order, str_repeat('a', 64));
    orderClaimRaceOutput($directory.'/initialized.json', ['driver' => $connection->getDriverName(), 'org' => $org->getKey(),
        'site' => $site->getKey(), 'order' => $order->id, 'reference' => $order->reference, 'readers' => $readers,
        'secret' => $secret, 'seed' => $seed, 'level' => $connection->transactionLevel(),
        'pdoTransaction' => $connection->getPdo()->inTransaction()]);
    exit;
}

$initialized = json_decode((string) file_get_contents($directory.'/initialized.json'), true, flags: JSON_THROW_ON_ERROR);
app(Context::class)->setOrg(Org::findOrFail($initialized['org']))->setSite(Site::findOrFail($initialized['site']));
if ($mode === 'read') {
    $claim = $connection->table(OrderClaims::TABLE)->sole();
    orderClaimRaceOutput($directory.'/readback.json', ['driver' => $connection->getDriverName(),
        'claims' => $connection->table(OrderClaims::TABLE)->count(), 'reader' => (string) $claim->reader_id,
        'audits' => $connection->table('audit_log')->where('action', 'commerce.order_claimed')->count(),
        'tokens' => $connection->table('commerce_order_access_tokens')->count(), 'grants' => $connection->table('entitlements')->count(),
        'level' => $connection->transactionLevel(), 'pdoTransaction' => $connection->getPdo()->inTransaction()]);
    exit;
}
$request = OrderFixture::request(app('session.store'));
$request->session()->put(ReaderSessions::EPHEMERAL.'.cart_seed', $initialized['seed']);
$request->session()->put(CartOwner::sessionKey($initialized['org'], $initialized['site']), $initialized['secret']);
Auth::guard(ReaderFixture::GUARD)->login(Reader::query()->findOrFail($initialized['readers'][$role]));
// Login can adopt carts and take the Org lock itself. Stage both complete sign-ins before either claim starts.
orderClaimRaceOutput($directory.'/'.$role.'-ready.json', ['level' => $connection->transactionLevel(), 'pdoTransaction' => $connection->getPdo()->inTransaction()]);
orderClaimRaceWait($directory.'/go-'.$role);
$paused = false;
$attempted = false;
$connection->listen(static function (QueryExecuted $query) use ($role, $directory, &$paused): void {
    if ($role === 'A' && ! $paused && str_starts_with(strtolower($query->sql), 'insert into') && str_contains($query->sql, 'commerce_order_claims')) {
        $paused = true;
        orderClaimRaceOutput($directory.'/A-paused.json', ['level' => $query->connection->transactionLevel(), 'pdoTransaction' => $query->connection->getPdo()->inTransaction()]);
        orderClaimRaceWait($directory.'/release-A');
    }
});
$connection->beforeExecuting(static function (string $query, array $bindings, $db) use ($role, $directory, &$attempted): void {
    if ($role === 'B' && ! $attempted && $db->transactionLevel() > 0 && str_contains($query, 'orgs')
        && ($db->getDriverName() === 'sqlite' ? str_starts_with(strtolower($query), 'update') : str_contains(strtolower($query), 'for update'))) {
        $attempted = true;
        orderClaimRaceOutput($directory.'/B-attempted.json', ['level' => $db->transactionLevel(), 'driver' => $db->getDriverName()]);
    }
});
$refused = false;
try {
    app(OrderClaims::class)->claim($request, $initialized['reference']);
} catch (OrderRefused) {
    $refused = true;
}
orderClaimRaceOutput($directory.'/'.$role.'-result.json', ['refused' => $refused, 'level' => $connection->transactionLevel(), 'pdoTransaction' => $connection->getPdo()->inTransaction()]);
