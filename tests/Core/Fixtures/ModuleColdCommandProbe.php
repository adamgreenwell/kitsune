<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

// Each invocation is a real fresh application and an independent database connection.
[$script, $root, $temporary, $mode] = array_slice($argv, 0, 4);
$tokens = array_slice($argv, 4);
require $root.'/vendor/autoload.php';

use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kitsune\Core\KitsuneServiceProvider;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Modules\ModuleLifecycle;
use Kitsune\Fixture\Module\FixtureModuleServiceProvider;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

$prefix = 'cold_'.substr(hash('sha256', $temporary), 0, 12).'_';
$selected = getenv('DB_CONNECTION') ?: 'testing';
$driver = in_array($selected, ['pgsql', 'mysql', 'mariadb'], true) ? $selected : 'sqlite';
$config = $driver === 'sqlite'
    ? ['driver' => 'sqlite', 'database' => $temporary.'/cold.sqlite', 'prefix' => $prefix, 'foreign_key_constraints' => true]
    : ['driver' => $driver, 'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: ($driver === 'pgsql' ? '5432' : '3306'),
        'database' => getenv('DB_DATABASE') ?: 'kitsune', 'username' => getenv('DB_USERNAME') ?: 'kitsune',
        'password' => getenv('DB_PASSWORD') ?: 'kitsune', 'prefix' => $prefix, 'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4'];
if ($mode === 'cleanup') {
    $pdo = $driver === 'sqlite' ? new PDO('sqlite:'.$config['database'])
        : new PDO(($driver === 'pgsql' ? 'pgsql' : 'mysql').':host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'], $config['username'], $config['password']);
    foreach (['fixture_dev_upgrade_rows', 'fixture_module_seeds', 'migrations', 'modules'] as $table) {
        $pdo->exec('DROP TABLE IF EXISTS '.$prefix.$table);
    }
    echo json_encode(['cleaned' => true], JSON_THROW_ON_ERROR);
    exit(0);
}

if ($mode === 'seed') {
    foreach (['bootstrap/cache', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs', 'migrations', 'installation/composer'] as $path) {
        mkdir($temporary.'/'.$path, 0700, true);
    }
    touch($temporary.'/cold.sqlite');
    copy($root.'/skeleton/composer.json', $temporary.'/composer.json');
    copy($temporary.'/../migrations/2026_10_22_000001_development_module_initial.php', $temporary.'/migrations/2026_10_22_000001_development_module_initial.php');
    symlink($root.'/vendor', $temporary.'/vendor');
    (new Filesystem)->copyDirectory($root.'/tests/Core/Fixtures/Packages/module-fixture', $temporary.'/package');
    $provider = $temporary.'/package/src/FixtureModuleServiceProvider.php';
    $source = file_get_contents($provider);
    file_put_contents($provider, str_replace("self::\$calls[] = 'boot';", "self::\$calls[] = 'boot';\n        DB::select('select development_note from '.DB::getTablePrefix().'fixture_dev_upgrade_rows');", $source));
    $installed = InstalledVersions::getAllRawData()[0];
    $installed['versions'] = ['kitsune/fixture-module' => array_replace($installed['versions']['kitsune/fixture-module'], ['install_path' => $temporary.'/package'])];
    file_put_contents($temporary.'/installation/composer/installed.php', "<?php\n\n/*\n * This Source Code Form is subject to the terms of the Mozilla Public\n * License, v. 2.0. If a copy of the MPL was not distributed with this\n * file, You can obtain one at https://mozilla.org/MPL/2.0/.\n */\n\nreturn ".var_export($installed, true).";\n");
}
$loader = new ClassLoader($temporary.'/installation');
$loader->addPsr4('Kitsune\\Fixture\\Module\\', $temporary.'/package/src');
$loader->register(prepend: true);
FixtureModuleServiceProvider::$migrationDirectory = $temporary.'/migrations';

// The actual Artisan entry point receives these same tokens, including options before the command.
$_SERVER['argv'] = ['artisan', ...$tokens];
if ($mode === 'web') {
    putenv('APP_RUNNING_IN_CONSOLE=false');
}
$app = Application::configure(basePath: $temporary)
    ->withProviders([KitsuneServiceProvider::class])
    ->withRouting(using: static function (): void {})
    ->withExceptions()->create();
$app->useConfigPath($root.'/skeleton/config');
$app->beforeBootstrapping(RegisterProviders::class, static function (Application $app) use ($config): void {
    $app->make('config')->set('database.default', 'module_cold');
    $app->make('config')->set('database.connections.module_cold', $config);
    $app->make('config')->set('session.driver', 'array');
    $app->make('config')->set('cache.default', 'array');
});
$kernel = $app->make(Kernel::class);
$output = new BufferedOutput;

try {
    if ($mode === 'seed') {
        $kernel->bootstrap();
        Schema::create('modules', static function (Blueprint $table): void {
            $table->id();
            $table->string('handle');
            $table->string('version');
            $table->boolean('is_enabled')->default(false);
            $table->timestamp('installed_at')->nullable();
        });
        Schema::create('fixture_module_seeds', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        ModuleLifecycle::install($app, 'kitsune/fixture-module');
        ModuleLifecycle::enable($app, 'kitsune/fixture-module');
        DB::table('fixture_dev_upgrade_rows')->insert(['name' => 'retained cold content']);
        $status = 0;
    } elseif ($mode === 'web') {
        $kernel->bootstrap();
        $status = 0;
    } else {
        $status = $kernel->handle(new ArgvInput($_SERVER['argv']), $output);
    }
    $result = ['status' => $status, 'output' => $output->fetch(), 'calls' => FixtureModuleServiceProvider::$calls,
        'maintenanceReads' => FixtureModuleServiceProvider::$maintenanceReads, 'driver' => $driver, 'column' => Schema::hasColumn('fixture_dev_upgrade_rows', 'development_note'),
        'receipt' => Module::query()->where('handle', 'kitsune/fixture-module')->first()?->getRawOriginal(),
        'rows' => DB::table('fixture_dev_upgrade_rows')->get()->all(),
        'pendingRecorded' => DB::table('migrations')->where('migration', '2026_10_22_000002_development_module_pending')->count()];
} catch (Throwable $error) {
    $result = ['status' => 1, 'exception' => get_class($error), 'output' => $error->getMessage(), 'calls' => FixtureModuleServiceProvider::$calls];
}
echo json_encode($result, JSON_THROW_ON_ERROR);
