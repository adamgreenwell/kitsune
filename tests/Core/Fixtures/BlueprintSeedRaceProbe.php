<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Blueprints\BlueprintSeeder;
use Kitsune\Core\Blueprints\Declarations\SeedEntryDeclaration;
use Kitsune\Core\KitsuneServiceProvider;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\BlueprintSeed;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\SeedBlueprint;
use Orchestra\Testbench\Foundation\Application as TestbenchApplication;

[$script, $root, $directory, $mode, $role, $ordering] = $argv;
require $root.'/vendor/autoload.php';

/** Isolated processes on one owned namespace, using the production transaction manager. */
final class BlueprintSeedRaceApplication extends TestbenchApplication
{
    public array $database = [];

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'seed_race');
        $app['config']->set('database.connections.seed_race', $this->database);
        $app['config']->set('database.connections.seed_observer', $this->database);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('s', 32)));
    }
}

function seedRaceOutput(string $path, array $data): void
{
    file_put_contents($path.'.tmp', json_encode($data, JSON_THROW_ON_ERROR));
    rename($path.'.tmp', $path);
}

function seedRaceWait(string $path): void
{
    $until = microtime(true) + 15;
    while (! is_file($path)) {
        if (microtime(true) > $until) {
            throw new RuntimeException('Seed race barrier timed out: '.basename($path));
        }
        usleep(5000);
    }
}

$base = $directory.'/app-'.$role;
foreach (['bootstrap/cache', 'storage/framework/views', 'storage/framework/cache', 'storage/logs'] as $relative) {
    mkdir($base.'/'.$relative, 0700, true);
}
$application = new BlueprintSeedRaceApplication($base);
$configuration = json_decode((string) file_get_contents($directory.'/database.json'), true, flags: JSON_THROW_ON_ERROR);
$driver = $configuration['driver'];
if (! in_array($driver, ['sqlite', 'pgsql', 'mysql', 'mariadb'], true)
    || ($driver === 'sqlite' && $configuration['database'] !== $directory.'/race.sqlite')
    || ($driver === 'pgsql' && preg_match('/^seed_[a-f0-9]{12}$/D', $configuration['search_path'] ?? '') !== 1)
    || (in_array($driver, ['mysql', 'mariadb'], true) && preg_match('/^s[a-f0-9]{5}_$/D', $configuration['prefix'] ?? '') !== 1)) {
    throw new RuntimeException('The seed probe requires its own disposable namespace.');
}
$application->database = $configuration;
$app = $application->configure(['extra' => ['providers' => [BladeIconsServiceProvider::class,
    BladeHeroiconsServiceProvider::class, KitsuneServiceProvider::class], 'dont-discover' => ['*']]])->createApplication();
$environmentDefault = DB::getDefaultConnection();
// Bind the explicit probe alias after environment bootstrap; its cloned configuration
// must not retain the parent connection's name.
DB::setDefaultConnection('seed_race');
$db = DB::connection();
seedRaceOutput($directory.'/'.$role.'-connection-binding.json', ['environmentDefault' => $environmentDefault, 'default' => $db->getName(),
    'schema' => Schema::getConnection()->getName(), 'sameWriter' => Schema::getConnection()->getPdo() === $db->getPdo()]);
// Testbench can resolve Schema while booting against its environment-selected connection.
// Every fixture migration must use this probe's isolated writer, including facade calls.
Schema::swap($db->getSchemaBuilder());
if ($db->getName() !== 'seed_race' || Schema::getConnection()->getPdo() !== $db->getPdo()) {
    throw new RuntimeException('Probe models and migrations must use the isolated writer.');
}
$state = static fn (): array => ['level' => $db->transactionLevel(), 'pdo' => $db->getPdo()->inTransaction()];
if ($db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
    throw new RuntimeException('Seed race did not start at actual level zero.');
}
$seeds = [new SeedEntryDeclaration('first', 'seed_article', 'First', 'first', ['seed_body' => 'First'], ['seed_related' => ['second']]),
    new SeedEntryDeclaration('second', 'seed_article', 'Second', 'second', relations: ['seed_related' => ['first']])];
$definition = new SeedBlueprint($seeds);
$tables = static function () use ($db, $driver, $configuration): array {
    $all = $db->getSchemaBuilder()->getTableListing($driver === 'pgsql' ? $configuration['search_path'] : null, schemaQualified: false);
    $owned = $driver === 'pgsql' || $driver === 'sqlite' ? $all
        : array_values(array_filter($all, static fn (string $name): bool => str_starts_with($name, $configuration['prefix'])));
    sort($owned);

    return $owned;
};
$counts = static function ($connection): array {
    $seen = [];
    foreach (['blueprints', 'blueprint_seeds', 'entries', 'entry_relations', 'entry_revisions', 'audit_log'] as $table) {
        $seen[$table] = $connection->table($table)->count();
    }
    $seen['locks'] = $connection->table('field_storage')->where('is_locked', true)->count();

    return $seen;
};
$migrationOptions = ['--database' => 'seed_race', '--path' => [$root.'/packages/core/database/migrations', $root.'/tests/Core/migrations'],
    '--realpath' => true, '--force' => true];
if ($mode === 'init') {
    if ($tables() !== []) {
        throw new RuntimeException('The disposable seed namespace already contains tables.');
    }
    if (Artisan::call('migrate', $migrationOptions) !== 0) {
        throw new RuntimeException('Disposable seed-race migration failed: '.Artisan::output());
    }
    $org = Org::create(['name' => 'Seed race', 'slug' => 'seed-race']);
    app(Context::class)->setOrg($org);
    Site::create(['org_id' => $org->id, 'handle' => 'main', 'slug' => 'seed-race', 'name' => 'Main', 'locale' => 'en']);
    BlueprintApplier::apply($definition);
    seedRaceOutput($directory.'/initialized.json', [...$state(), 'tables' => $tables()]);
    exit(0);
}
if ($mode === 'cleanup') {
    $initialized = json_decode((string) file_get_contents($directory.'/initialized.json'), true, flags: JSON_THROW_ON_ERROR);
    if ($tables() !== $initialized['tables'] || $db->table('orgs')->count() !== 1
        || $db->table('orgs')->where('slug', 'seed-race')->count() !== 1) {
        throw new RuntimeException('Seed namespace ownership changed; its data and private plan were retained.');
    }
    $audited = $counts($db);
    if (Artisan::call('migrate:reset', $migrationOptions) !== 0 || $tables() !== [($configuration['prefix'] ?? '').'migrations']) {
        throw new RuntimeException('Owned migration reset did not remove exactly its recorded tables.');
    }
    $db->getSchemaBuilder()->drop('migrations');
    if ($tables() !== []) {
        throw new RuntimeException('Owned seed tables remain after cleanup.');
    }
    seedRaceOutput($directory.'/cleanup.json', ['tablesRemoved' => count($initialized['tables']), 'audited' => $audited, 'remaining' => $tables()]);
    exit(0);
}
$org = Org::query()->where('slug', 'seed-race')->sole();
app(Context::class)->setOrg($org);
$site = Site::query()->where('handle', 'main')->sole();
app(Context::class)->setSite($site);
if ($mode === 'read') {
    seedRaceOutput($directory.'/'.$role.'-readback.json', [...$state(), 'counts' => $counts($db),
        'version' => $db->table('blueprints')->value('version'), 'locks' => $db->table('field_storage')->where('is_locked', true)->count()]);
    exit(0);
}
if ($mode === 'atomic') {
    $observer = DB::connection('seed_observer');
    if ($observer->getPdo() === $db->getPdo()) {
        throw new RuntimeException('The commit observer must use an independent PDO.');
    }
    $before = $counts($observer);
    $phase = 'before';
    $seen = [];
    BlueprintSeed::saved(function () use (&$phase, &$seen, $counts, $observer): void {
        $seen[$phase] = $counts($observer);
        if ($phase === 'before') {
            throw new RuntimeException('seed interrupted before commit');
        }
        if ($phase === 'after') {
            DB::afterCommit(static function (): never {
                throw new RuntimeException('seed interrupted after commit');
            });
        }
    });
    $failures = [];
    foreach (['before', 'after'] as $phase) {
        try {
            BlueprintSeeder::seed($definition, $site, 'en');
            throw new LogicException('Expected interruption did not occur.');
        } catch (RuntimeException $e) {
            if ($e->getMessage() !== 'seed interrupted '.$phase.' commit') {
                throw $e;
            }
            $failures[$phase] = [...$state(), 'counts' => $counts($observer)];
        }
    }
    $committed = $counts($observer);
    $retry = BlueprintSeeder::seed($definition, $site, 'en');
    seedRaceOutput($directory.'/atomic.json', ['before' => $before, 'seenInside' => $seen, 'interruptions' => $failures,
        'committed' => $committed, 'retryCounts' => $counts($observer), 'retry' => $retry, ...$state()]);
    exit(0);
}
// A real lock-wait refusal is observed before A is released, rather than inferred from a pause.
if ($driver === 'pgsql') {
    $db->statement("SET lock_timeout TO '250ms'");
} elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
    $db->statement('SET SESSION innodb_lock_wait_timeout = 1');
}
if ($role === 'A') {
    $pause = function () use ($directory, $state): void {
        seedRaceOutput($directory.'/A-paused.json', $state());
        seedRaceWait($directory.'/release-A');
    };
    if ($ordering === 'reverse-first') {
        Blueprint::deleting($pause);
    } else {
        BlueprintSeed::saved($pause);
    }
}
$operation = $role === 'A' ? ($ordering === 'reverse-first' ? 'reverse' : 'seed') : match ($ordering) {
    'seed-vs-merge' => 'merge', 'seed-vs-reverse' => 'reverse', default => 'seed',
};
$run = function () use ($operation, $definition, $seeds, $site): void {
    match ($operation) {
        'seed' => BlueprintSeeder::seed($definition, $site, 'en'),
        'merge' => BlueprintApplier::apply(new SeedBlueprint($seeds, '1.1.0')),
        'reverse' => BlueprintApplier::reverse('seed_test'),
    };
};
$attempt = function () use ($run, $state): array {
    try {
        $run();

        return [...$state(), 'success' => true, 'busy' => false, 'refusal' => false];
    } catch (Throwable $e) {
        $busy = false;
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            $busy = $busy || ($cause instanceof QueryException && (str_contains(strtolower($cause->getMessage()), 'locked')
                || ($cause->errorInfo[0] ?? null) === '55P03' || ($cause->errorInfo[1] ?? null) === 1205));
        }

        return [...$state(), 'success' => false, 'busy' => $busy,
            'refusal' => str_contains($e->getMessage(), 'Cannot reverse') || str_contains($e->getMessage(), 'schema before')];
    }
};
seedRaceOutput($directory.'/'.$role.'-attempted.json', $state());
seedRaceOutput($directory.'/'.$role.'-first.json', $attempt());
if ($role === 'B') {
    seedRaceWait($directory.'/retry-B');
    seedRaceOutput($directory.'/B-retry.json', $attempt());
}
