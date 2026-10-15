<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Orders\OrderRefused;
use Kitsune\Commerce\Payments\AccessFulfillment;
use Kitsune\Commerce\Payments\CheckoutRefused;
use Kitsune\Commerce\Payments\FinancialState;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Commerce\Payments\StripeCheckout;
use Kitsune\Core\Entitlements\EntitlementWriter;
use Kitsune\Core\KitsuneServiceProvider;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture;
use Kitsune\Core\Tests\Fixtures\FinancialAccessFixture;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;
use Kitsune\Core\Tests\Fixtures\TestUser;
use Orchestra\Testbench\Foundation\Application as TestbenchApplication;

[$script, $root, $directory, $mode, $role, $scenario] = $argv;
require $root.'/vendor/autoload.php';

/** Native independent connections use the CI-selected engine and an exclusively owned table prefix. */
final class FinancialRaceApplication extends TestbenchApplication
{
    public array $database = [];

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'financial_race');
        $app['config']->set('database.connections.financial_race', $this->database);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('r', 32)));
        $app['config']->set('auth.providers.users.model', TestUser::class);
        $app['config']->set('hashing.bcrypt.rounds', 4);
        $app['config']->set(AppServiceProvider::readerConfig());
    }
}

function financialRaceOutput(string $path, array $data): void
{
    file_put_contents($path.'.tmp', json_encode($data, JSON_THROW_ON_ERROR));
    rename($path.'.tmp', $path);
}

function financialRaceWait(string $path): void
{
    $deadline = microtime(true) + 20;
    while (! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Financial race barrier timed out: '.basename($path));
        }
        usleep(5000);
    }
}

$selected = getenv('DB_CONNECTION') ?: 'testing';
$driver = in_array($selected, ['pgsql', 'mysql', 'mariadb'], true) ? $selected : 'sqlite';
$isolation = 'f'.substr(hash('sha256', $directory), 0, 5).'_';
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
$application = new FinancialRaceApplication($directory.'/app-'.$role);
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

CarbonImmutable::setTestNow('2026-10-15 12:00:00 UTC');
$providerCalls = 0;
app()->instance(StripeCheckout::class, new StripeCheckout(new Client(['handler' => static function () use (&$providerCalls): never {
    $providerCalls++;
    throw new RuntimeException('No provider I/O belongs in this concurrency qualification.');
}])));

if ($mode === 'init') {
    if ($driver === 'pgsql') {
        $connection->statement('CREATE SCHEMA "'.$isolation.'"');
    }
    $code = Artisan::call('migrate', ['--path' => [$root.'/packages/core/database/migrations', $root.'/tests/Commerce/migrations',
        $root.'/skeleton/database/migrations/0001_01_01_000020_create_readers_table.php'], '--realpath' => true, '--force' => true]);
    if ($code !== 0) {
        throw new RuntimeException('Disposable financial race schema migration failed.');
    }
    CredentialFixture::boot();
    $org = Org::create(['name' => 'Financial race', 'slug' => 'financial-race', 'currency' => 'USD']);
    app(Context::class)->setOrg($org);
    $site = Site::create(['handle' => 'main', 'slug' => 'financial-race-main', 'name' => 'Main', 'base_url' => 'https://financial-race.example.test']);
    ReaderFixture::mode($org, 'sign-in');
    $reader = ReaderFixture::reader($org, 'buyer@financial-race.example.test');
    app(Context::class)->setSite($site);
    $owners = [];
    foreach (['A', 'B'] as $label) {
        $owners[$label] = CredentialFixture::member(owner: true, email: strtolower($label).'@financial-race.example.test')->getKey();
    }
    Auth::guard('web')->logout();
    $order = FinancialAccessFixture::purchase($site, (string) $reader->getKey());
    app(AccessFulfillment::class)->reconcile($order->reference);
    $line = (int) $connection->table('commerce_order_lines')->where('order_id', $order->id)->value('id');
    $refundReference = null;
    if ($scenario === 'restoration') {
        Auth::guard('web')->setUser(TestUser::findOrFail($owners['A']));
        $refundReference = app(FinancialState::class)->requestAsOwner($order->reference, [$line => 1], 'Return the original purchased pass', str_repeat('a', 32));
    }
    financialRaceOutput($directory.'/initialized.json', ['driver' => $connection->getDriverName(), 'org' => $org->getKey(),
        'site' => $site->getKey(), 'order' => $order->id, 'reference' => $order->reference, 'line' => $line,
        'reader' => (string) $reader->getKey(), 'owners' => $owners, 'refundReference' => $refundReference,
        'providerCalls' => $providerCalls, 'level' => $connection->transactionLevel(), 'pdoTransaction' => $connection->getPdo()->inTransaction()]);
    exit;
}

$initialized = json_decode((string) file_get_contents($directory.'/initialized.json'), true, flags: JSON_THROW_ON_ERROR);
app(Context::class)->setOrg(Org::findOrFail($initialized['org']))->setSite(Site::findOrFail($initialized['site']));
$order = $connection->table('commerce_orders')->where('id', $initialized['order'])->sole();
if ($mode === 'read' || $mode === 'during') {
    $grant = $connection->table('entitlements')->sole();
    $refund = $connection->table(FinancialState::REFUNDS)->first();
    $allocation = $connection->table(PaidOrders::ALLOCATIONS)->sole();
    financialRaceOutput($directory.'/'.$mode.'.json', ['driver' => $connection->getDriverName(),
        'intents' => $connection->table(FinancialState::REFUNDS)->count(), 'requestAudits' => $connection->table('audit_log')->where('action', 'commerce.refund_requested')->count(),
        'revoked' => $grant->revoked_at !== null, 'source' => $grant->source, 'sourceRevokes' => $connection->table('audit_log')->where('action', EntitlementWriter::REVOKED)->count(),
        'sourceRestorations' => $connection->table('audit_log')->where('action', EntitlementWriter::REINSTATED)->count(),
        'providerIdentity' => $refund?->refund_id, 'firstSubmitted' => $refund?->first_submitted_at,
        'awarded' => (int) $allocation->awarded, 'stockMovements' => $connection->table('commerce_stock_movements')->count(),
        'providerCalls' => $providerCalls, 'level' => $connection->transactionLevel(), 'pdoTransaction' => $connection->getPdo()->inTransaction()]);
    exit;
}
Auth::guard('web')->setUser(TestUser::findOrFail($initialized['owners'][$role]));
financialRaceOutput($directory.'/'.$role.'-ready.json', ['driver' => $connection->getDriverName(), 'level' => $connection->transactionLevel(),
    'pdoTransaction' => $connection->getPdo()->inTransaction()]);
financialRaceWait($directory.'/go-'.$role);
$paused = false;
$attempted = false;
AuditLog::created(static function (AuditLog $audit) use ($role, $directory, $scenario, &$paused, $connection): void {
    $action = $scenario === 'refund' ? 'commerce.refund_requested' : EntitlementWriter::REINSTATED;
    if ($role === 'A' && ! $paused && $audit->action === $action) {
        $paused = true;
        financialRaceOutput($directory.'/A-paused.json', ['driver' => $connection->getDriverName(), 'level' => $connection->transactionLevel(),
            'pdoTransaction' => $connection->getPdo()->inTransaction()]);
        financialRaceWait($directory.'/release-A');
    }
});
$connection->beforeExecuting(static function (string $query, array $bindings, $db) use ($role, $directory, &$attempted): void {
    $sql = strtolower($query);
    $lock = $db->getDriverName() === 'sqlite' ? str_starts_with($sql, 'update')
        : (str_contains($sql, 'for update') || str_contains($sql, 'for share') || str_contains($sql, 'share mode'));
    if ($role === 'B' && ! $attempted && $db->transactionLevel() > 0 && str_contains($sql, 'orgs') && $lock) {
        $attempted = true;
        financialRaceOutput($directory.'/B-attempted.json', ['level' => $db->transactionLevel(), 'driver' => $db->getDriverName()]);
    }
});
$refused = false;
try {
    if ($scenario === 'refund') {
        app(FinancialState::class)->requestAsOwner($initialized['reference'], [$initialized['line'] => 1], 'Return the original purchased pass',
            str_repeat($role === 'A' ? 'a' : 'b', 32));
    } elseif ($role === 'A') {
        FinancialAccessFixture::observe($order, [FinancialAccessFixture::refund($order, $initialized['refundReference'], 'failed')]);
    } else {
        app(EntitlementWriter::class)->revoke($initialized['reader'], 'course.purchased', 'commerce.order:'.$order->id);
    }
} catch (CheckoutRefused|OrderRefused $failure) {
    $refused = true;
}
financialRaceOutput($directory.'/'.$role.'-result.json', ['driver' => $connection->getDriverName(), 'refused' => $refused,
    'providerCalls' => $providerCalls, 'level' => $connection->transactionLevel(), 'pdoTransaction' => $connection->getPdo()->inTransaction()]);
