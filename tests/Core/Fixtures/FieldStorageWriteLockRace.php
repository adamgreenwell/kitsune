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
use Kitsune\Core\KitsuneServiceProvider;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRelation;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Orchestra\Testbench\Foundation\Application as TestbenchApplication;

[$script, $root, $directory, $mode, $role, $scenario] = $argv;
require $root.'/vendor/autoload.php';

/** Separate production transaction manager and PDO connection for each participant. */
final class FieldStorageWriteLockApplication extends TestbenchApplication
{
    public array $database = [];

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'field_write_race');
        $app['config']->set('database.connections.field_write_race', $this->database);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('w', 32)));
    }
}

function fieldWriteRaceOutput(string $path, array $data): void
{
    file_put_contents($path.'.tmp', json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    rename($path.'.tmp', $path);
}

function fieldWriteRaceWait(string $path): void
{
    $deadline = microtime(true) + 20;
    while (! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Storage write barrier timed out: '.basename($path));
        }
        usleep(5000);
    }
}

$selected = getenv('DB_CONNECTION') ?: 'testing';
$driver = in_array($selected, ['pgsql', 'mysql', 'mariadb'], true) ? $selected : 'sqlite';
$database = $driver === 'sqlite'
    ? ['driver' => 'sqlite', 'database' => $directory.'/race.sqlite', 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 1000]
    : ['driver' => $driver, 'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: ($driver === 'pgsql' ? '5432' : '3306'),
        'database' => getenv('DB_DATABASE') ?: 'kitsune', 'username' => getenv('DB_USERNAME') ?: 'kitsune',
        'password' => getenv('DB_PASSWORD') ?: 'kitsune', 'prefix' => '', 'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4'];
$builder = new FieldStorageWriteLockApplication($directory.'/app-'.$role);
$builder->database = $database;
$app = $builder->configure(['extra' => [
    'providers' => [BladeIconsServiceProvider::class, BladeHeroiconsServiceProvider::class, KitsuneServiceProvider::class],
    'dont-discover' => ['*'],
]])->createApplication();
set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, $error::class.': '.$error->getMessage().' on line '.$error->getLine().PHP_EOL);
    exit(1);
});
$db = DB::connection();
if ($db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
    throw new RuntimeException('The storage race must begin at actual transaction level zero.');
}
if ($driver === 'pgsql') {
    $db->statement("SET lock_timeout = '5s'");
} elseif ($driver !== 'sqlite') {
    $db->statement('SET SESSION innodb_lock_wait_timeout = 5');
}

$relation = str_starts_with($scenario, 'relation');
$promoted = str_starts_with($scenario, 'promoted');
$shapeFirst = str_ends_with($scenario, 'shape-first');
$slug = 'fwl-race-'.substr(hash('sha256', $directory), 0, 16);

if ($mode === 'init') {
    if ($driver === 'sqlite') {
        $exit = Artisan::call('migrate', ['--database' => 'field_write_race',
            '--path' => [$root.'/packages/core/database/migrations', $root.'/tests/Core/migrations'],
            '--realpath' => true, '--force' => true]);
        if ($exit !== 0) {
            throw new RuntimeException('Disposable storage race schema migration failed.');
        }
    }
    $org = Org::create(['name' => 'Storage race', 'slug' => $slug]);
    app(Context::class)->setOrg($org);
    $site = Site::create(['org_id' => $org->id, 'handle' => 'main', 'slug' => $slug.'-main', 'name' => 'Main']);
    app(Context::class)->setSite($site);
    $type = EntryType::create(['org_id' => $org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);
    $targetType = EntryType::create(['org_id' => $org->id, 'handle' => 'page', 'name' => 'Page', 'plural_name' => 'Pages']);
    $storage = FieldStorage::create(['org_id' => $org->id, 'handle' => 'amount',
        'type' => $relation ? 'relation' : ($promoted ? 'slug' : 'number'), 'pii_class' => 'none',
        'settings' => $relation || $promoted ? [] : ['format' => 'decimal']]);
    Field::create(['entry_type_id' => $type->id, 'field_storage_id' => $storage->id, 'label' => 'Amount']);
    $source = Entry::create(['entry_type_id' => $type->id, 'title' => 'Source']);
    $target = Entry::create(['entry_type_id' => $targetType->id, 'title' => 'Target']);
    fieldWriteRaceOutput($directory.'/initialized.json', ['driver' => $driver, 'org' => $org->id, 'site' => $site->id,
        'type' => $type->id, 'storage' => $storage->id, 'source' => $source->id, 'target' => $target->id,
        'level' => 0, 'pdoTransaction' => false]);
    exit(0);
}

if ($mode === 'cleanup') {
    $orgIds = $db->table('orgs')->where('slug', $slug)->pluck('id')->all();
    $entryIds = $db->table('entries')->whereIn('org_id', $orgIds)->pluck('id')->all();
    $db->table('orgs')->whereIn('id', $orgIds)->delete();
    foreach (['orgs', 'sites', 'entry_types', 'field_storage', 'entries', 'entry_relations', 'entry_revisions', 'audit_log'] as $table) {
        $key = match ($table) {
            'orgs' => 'id',
            'entry_revisions' => 'entry_id',
            default => 'org_id',
        };
        if ($db->table($table)->whereIn($key, $table === 'entry_revisions' ? $entryIds : $orgIds)->exists()) {
            throw new RuntimeException('Storage race cleanup left owned rows in '.$table);
        }
    }
    fieldWriteRaceOutput($directory.'/cleanup.json', ['ownedRows' => 0]);
    exit(0);
}

$fixture = json_decode((string) file_get_contents($directory.'/initialized.json'), true, flags: JSON_THROW_ON_ERROR);
app(Context::class)->setOrg(Org::findOrFail($fixture['org']));
app(Context::class)->setSite(Site::findOrFail($fixture['site']));

if ($mode === 'read') {
    $source = Entry::findOrFail($fixture['source']);
    $storage = FieldStorage::findOrFail($fixture['storage']);
    fieldWriteRaceOutput($directory.'/read.json', ['driver' => $driver, 'type' => $storage->type,
        'settings' => $storage->settings, 'locked' => $storage->is_locked,
        'values' => $source->values, 'slug' => $source->slug,
        'relations' => EntryRelation::query()->where('source_entry_id', $source->id)->count(),
        'audits' => $db->table('audit_log')->where('org_id', $fixture['org'])->count(),
        'revisions' => $db->table('entry_revisions')->where('entry_id', $source->id)->count(),
        'level' => $db->transactionLevel(), 'pdoTransaction' => $db->getPdo()->inTransaction()]);
    exit(0);
}

// B deliberately loads the unlocked shape before A starts. A winning writer must invalidate that old permission.
$storage = FieldStorage::findOrFail($fixture['storage']);
$source = Entry::findOrFail($fixture['source']);
$operation = ($role === 'A') === $shapeFirst ? 'shape' : 'data';
$paused = false;
$attempted = false;
$db->listen(function (QueryExecuted $query) use ($role, $operation, $directory, $relation, &$paused): void {
    $table = $operation === 'shape' ? 'field_storage' : ($relation ? 'entry_relations' : 'entries');
    $verb = $operation === 'data' && $relation ? 'insert' : 'update';
    if ($role === 'A' && ! $paused && preg_match('/\A'.$verb.'\s+(?:into\s+)?["`]?'.$table.'["`]?\s/i', $query->sql) === 1) {
        $paused = true;
        fieldWriteRaceOutput($directory.'/A-paused.json', ['operation' => $operation,
            'level' => $query->connection->transactionLevel(), 'pdoTransaction' => $query->connection->getPdo()->inTransaction()]);
        fieldWriteRaceWait($directory.'/release-A');
    }
});
$db->beforeExecuting(function (string $query) use ($role, $directory, &$attempted): void {
    if ($role === 'B' && ! $attempted && preg_match('/\A(?:select|update)\b.*\b["`]?field_storage["`]?\b/i', $query) === 1) {
        $attempted = true;
        fieldWriteRaceOutput($directory.'/B-attempted.json', ['attempted' => true]);
    }
});
fieldWriteRaceOutput($directory.'/'.$role.'-ready.json', ['level' => 0, 'pdoTransaction' => false]);
fieldWriteRaceWait($directory.'/go-'.$role);

$invoke = static function () use ($operation, $relation, $promoted, $fixture, $storage, $source, $scenario): array {
    try {
        if ($operation === 'shape') {
            if (str_starts_with($scenario, 'relation-strategy')) {
                $storage->type = 'text';
                $storage->settings = [];
            } elseif ($relation) {
                $storage->settings = ['targetTypes' => ['article']];
            } elseif ($promoted) {
                $storage->type = 'text';
            } else {
                $storage->settings = ['format' => 'integer'];
            }
            $storage->save();
        } elseif ($relation) {
            $source->related()->attach($fixture['target'], ['field_storage_id' => $fixture['storage']]);
        } else {
            $source->forceFill($promoted ? ['slug' => 'First Value'] : ['values' => ['amount' => 1.5]])->save();
        }

        return ['success' => true, 'exception' => null, 'busy' => false];
    } catch (Throwable $error) {
        return ['success' => false, 'exception' => get_class($error),
            'busy' => $error instanceof QueryException && (str_contains(strtolower($error->getMessage()), 'locked')
                || str_contains(strtolower($error->getMessage()), 'lock timeout') || str_contains(strtolower($error->getMessage()), 'lock wait timeout'))];
    }
};
$result = $invoke();
fieldWriteRaceOutput($directory.'/'.$role.'-result.json', $result + ['level' => $db->transactionLevel(), 'pdoTransaction' => $db->getPdo()->inTransaction()]);

// SQLite may refuse a read-to-write snapshot upgrade. A fresh attempt must use the winning shape as well.
if ($role === 'B' && $shapeFirst && $result['busy']) {
    fieldWriteRaceWait($directory.'/retry-B');
    $retry = $invoke();
    fieldWriteRaceOutput($directory.'/B-retry.json', $retry + ['level' => $db->transactionLevel(), 'pdoTransaction' => $db->getPdo()->inTransaction()]);
}
exit(0);
