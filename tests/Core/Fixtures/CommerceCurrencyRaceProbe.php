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
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Catalogue\CatalogueWriter;
use Kitsune\Commerce\Catalogue\OrgCurrency;
use Kitsune\Core\KitsuneServiceProvider;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Modules\ModuleLifecycle;
use Kitsune\Core\Tenancy\Context;
use Orchestra\Testbench\Foundation\Application as TestbenchApplication;

[$script, $root, $directory, $mode, $role, $ordering] = $argv;
require $root.'/vendor/autoload.php';

/** Standalone child application: never RefreshDatabase or a testing transaction manager. */
final class CommerceCurrencyRaceApplication extends TestbenchApplication
{
    public array $database = [];

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'race');
        $app['config']->set('database.connections.race', $this->database);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('r', 32)));
    }
}

function currencyRaceOutputFile(string $path, array $data): void
{
    file_put_contents($path.'.tmp', json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    rename($path.'.tmp', $path);
}

function currencyRaceWaitFor(string $path, int $seconds = 15): void
{
    $until = microtime(true) + $seconds;
    while (! is_file($path)) {
        if (microtime(true) > $until) {
            throw new RuntimeException('Barrier timed out: '.basename($path));
        }
        usleep(5000);
    }
}

$app = new CommerceCurrencyRaceApplication(
    $directory.'/app-'.$role,
);
$app->database = [
    'driver' => 'sqlite', 'database' => $directory.'/race.sqlite', 'prefix' => '',
    'foreign_key_constraints' => true, 'busy_timeout' => 5000,
];
$app = $app->configure(['extra' => [
    'providers' => [BladeIconsServiceProvider::class, BladeHeroiconsServiceProvider::class, KitsuneServiceProvider::class],
    'dont-discover' => ['*'],
]])->createApplication();
$db = DB::connection();
if ($db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
    throw new RuntimeException('Probe did not start at actual transaction level zero.');
}

if ($mode === 'init') {
    $code = Artisan::call('migrate', [
        '--database' => 'race',
        '--path' => [$root.'/packages/core/database/migrations', $root.'/tests/Core/migrations'],
        '--realpath' => true, '--force' => true,
    ]);
    if ($code !== 0) {
        throw new RuntimeException('Fresh disposable schema migration failed: '.Artisan::output());
    }
    ModuleLifecycle::install($app, 'kitsune/commerce');
    ModuleLifecycle::enable($app, 'kitsune/commerce');
    $org = Org::create(['name' => 'Currency race', 'slug' => 'currency-race']);
    app(Context::class)->setOrg($org);
    app(OrgCurrency::class)->set('USD');
    currencyRaceOutputFile($directory.'/initialized.json', ['org_id' => $org->id, 'level' => $db->transactionLevel(), 'pdo_in_transaction' => $db->getPdo()->inTransaction()]);
    exit(0);
}

$orgId = json_decode((string) file_get_contents($directory.'/initialized.json'), true, flags: JSON_THROW_ON_ERROR)['org_id'];
app(Context::class)->setOrg(Org::findOrFail($orgId));

if ($mode === 'read') {
    currencyRaceOutputFile($directory.'/readback.json', [
        'org_currency' => $db->table('orgs')->where('id', $orgId)->value('currency'),
        'sellables' => $db->table('commerce_sellables')->select('org_id', 'currency', 'sku', 'price_minor', 'stock')->get()->all(),
        'audit_actions' => $db->table('audit_log')->orderBy('id')->pluck('action')->all(),
        'level' => $db->transactionLevel(), 'pdo_in_transaction' => $db->getPdo()->inTransaction(),
    ]);
    exit(0);
}

$operation = $role === 'A'
    ? ($ordering === 'price-first' ? 'price' : 'currency')
    : ($ordering === 'price-first' ? 'currency' : 'price');
$paused = false;
$attempted = false;
$db->listen(function (QueryExecuted $query) use ($role, $operation, $directory, &$paused): void {
    if ($role !== 'A' || $paused) {
        return;
    }
    $matches = $operation === 'price'
        ? preg_match('/\Ainsert\s+into\s+["`]?commerce_sellables["`]?(?:\s|\()/i', $query->sql)
        : preg_match('/\Aupdate\s+["`]?orgs["`]?\s/i', $query->sql);
    if ($matches !== 1) {
        return;
    }
    $paused = true;
    currencyRaceOutputFile($directory.'/A-paused.json', [
        'operation' => $operation, 'time' => microtime(true), 'level' => $query->connection->transactionLevel(),
        'pdo_in_transaction' => $query->connection->getPdo()->inTransaction(),
    ]);
    currencyRaceWaitFor($directory.'/release-A');
});
$db->beforeExecuting(function (string $query, array $bindings, $connection) use ($role, $directory, &$attempted): void {
    if ($role === 'B' && ! $attempted && $connection->transactionLevel() > 0
        && preg_match('/\Aselect\b.*\bfrom\s+["`]?orgs["`]?\s/i', $query) === 1) {
        $attempted = true;
        currencyRaceOutputFile($directory.'/B-attempted.json', [
            'time' => microtime(true), 'level' => $connection->transactionLevel(),
        ]);
    }
});

$invoke = static function (string $operation, string $quote = 'USD'): array {
    try {
        if ($operation === 'currency') {
            $org = app(OrgCurrency::class)->set('KWD');

            return ['success' => true, 'currency' => $org->currency];
        }
        $sellable = app(CatalogueWriter::class)->createSellable('RACE-BOOK', '123', null, $quote);

        return ['success' => true, 'currency' => $sellable->currency, 'price_minor' => $sellable->price_minor];
    } catch (Throwable $error) {
        return [
            'success' => false, 'exception' => get_class($error),
            // Only class/category; no SQL, bindings, DSN or credentials go to evidence.
            'busy' => str_contains(strtolower($error->getMessage()), 'database is locked'),
            'stale_quote' => str_contains(strtolower($error->getMessage()), 'currency changed'),
            'held_currency' => str_contains(strtolower($error->getMessage()), 'existing commerce records'),
            'sqlstate' => $error instanceof QueryException ? ($error->errorInfo[0] ?? null) : null,
        ];
    }
};
$started = microtime(true);
$result = $invoke($operation);
$result += [
    'operation' => $operation, 'started' => $started, 'finished' => microtime(true),
    'level' => $db->transactionLevel(), 'pdo_in_transaction' => $db->getPdo()->inTransaction(),
];
currencyRaceOutputFile($directory.'/'.$role.'-first-result.json', $result);
if ($role === 'B') {
    currencyRaceWaitFor($directory.'/retry-B');
    if ($ordering === 'currency-first') {
        $stale = $invoke($operation, 'USD');
        $stale += ['level' => $db->transactionLevel(), 'pdo_in_transaction' => $db->getPdo()->inTransaction()];
        currencyRaceOutputFile($directory.'/B-stale-replay.json', $stale);
    }
    $retry = $invoke($operation, $ordering === 'currency-first' ? 'KWD' : 'USD');
    $retry += ['level' => $db->transactionLevel(), 'pdo_in_transaction' => $db->getPdo()->inTransaction()];
    currencyRaceOutputFile($directory.'/B-retry-result.json', $retry);
}
exit(0);
