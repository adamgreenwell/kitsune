<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Modules\ModuleLifecycle;
use Kitsune\Fixture\Module\FixtureModuleServiceProvider;
use Symfony\Component\Process\Process;

/** The fixture is installed by Composer; only the temporary schema supplied by its provider changes. */
const DEVELOPMENT_MODULE = 'kitsune/fixture-module';

/** @return array<string, mixed> */
function developmentReceipt(): array
{
    return Module::query()->where('handle', DEVELOPMENT_MODULE)->firstOrFail()->getRawOriginal();
}

/** A separately registered Composer installation takes precedence without editing the real vendor tree. */
function developmentComposerRecord(string $directory, array $overrides): ClassLoader
{
    $installed = InstalledVersions::getAllRawData()[0];
    $installed['versions'] = [DEVELOPMENT_MODULE => array_replace($installed['versions'][DEVELOPMENT_MODULE], $overrides)];
    $vendor = $directory.'/composer-installation';
    mkdir($vendor, 0700);
    mkdir($vendor.'/composer', 0700);
    file_put_contents($vendor.'/composer/installed.php', "<?php\n\n/*\n * This Source Code Form is subject to the terms of the Mozilla Public\n * License, v. 2.0. If a copy of the MPL was not distributed with this\n * file, You can obtain one at https://mozilla.org/MPL/2.0/.\n */\n\nreturn ".var_export($installed, true).";\n");
    $loader = new ClassLoader($vendor);
    $loader->register(prepend: true);

    return $loader;
}

function developmentInitialMigration(string $directory): void
{
    file_put_contents($directory.'/2026_10_22_000001_development_module_initial.php', <<<'PHP'
<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixture_dev_upgrade_rows', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('generation')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixture_dev_upgrade_rows');
    }
};
PHP);
}

function developmentPendingMigration(string $directory, bool $failing = false): void
{
    file_put_contents($directory.'/2026_10_22_000002_development_module_pending.php', <<<'PHP'
<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Deliberately retryable on engines whose DDL survives the exception below.
        if (! Schema::hasColumn('fixture_dev_upgrade_rows', 'development_note')) {
            Schema::table('fixture_dev_upgrade_rows', function (Blueprint $table): void {
                $table->string('development_note')->nullable();
            });
        }

        if (is_file(__DIR__.'/fail-after-ddl')) {
            throw new RuntimeException('Development fixture stopped after DDL.');
        }

        DB::table('fixture_dev_upgrade_rows')->increment('generation');
    }

    public function down(): void
    {
        Schema::table('fixture_dev_upgrade_rows', function (Blueprint $table): void {
            $table->dropColumn('development_note');
        });
    }
};
PHP);

    if ($failing) {
        file_put_contents($directory.'/fail-after-ddl', 'owned failure switch');
    }
}

beforeEach(function (): void {
    $this->developmentDirectory = sys_get_temp_dir().'/kitsune-module-development-'.getmypid().'-'.bin2hex(random_bytes(8));
    mkdir($this->developmentDirectory, 0700);
    mkdir($this->developmentDirectory.'/migrations', 0700);
    mkdir($this->developmentDirectory.'/bootstrap', 0700);
    mkdir($this->developmentDirectory.'/bootstrap/cache', 0700);
    $this->developmentBootstrap = app()->bootstrapPath();
    app()->useBootstrapPath($this->developmentDirectory.'/bootstrap');
    $this->developmentVersion = ModuleDiscovery::version(DEVELOPMENT_MODULE);
    $this->developmentPath = ModuleDiscovery::installPath(DEVELOPMENT_MODULE);
    $this->developmentLoader = null;
    FixtureModuleServiceProvider::$calls = [];
    FixtureModuleServiceProvider::$installFailsAfterWriting = false;
    FixtureModuleServiceProvider::$installIssuesDdl = false;
    FixtureModuleServiceProvider::$withoutMigrations = false;
    FixtureModuleServiceProvider::$migrationDirectory = $this->developmentDirectory.'/migrations';
    ModuleKernel::flush();
    developmentInitialMigration(FixtureModuleServiceProvider::$migrationDirectory);

    // DDL may commit the suite's wrapper on MySQL/MariaDB. Remove exactly this fixture's tables and ledger.
    if (DB::connection()->getDriverName() !== 'sqlite') {
        config(['database.connections.module-development-cleanup' => DB::connection()->getConfig()]);
        $this->afterRollback(static function (): void {
            $sweep = DB::connection('module-development-cleanup');
            $sweep->getSchemaBuilder()->dropIfExists('fixture_dev_upgrade_rows');
            $sweep->table('migrations')->whereIn('migration', [
                '2026_10_22_000001_development_module_initial',
                '2026_10_22_000002_development_module_pending',
            ])->delete();
            $sweep->table('modules')->where('handle', DEVELOPMENT_MODULE)->delete();
            DB::purge('module-development-cleanup');
        });
    }
});

afterEach(function (): void {
    $this->developmentLoader?->unregister();
    expect(ModuleDiscovery::version(DEVELOPMENT_MODULE))->toBe($this->developmentVersion)
        ->and(ModuleDiscovery::installPath(DEVELOPMENT_MODULE))->toBe($this->developmentPath);
    FixtureModuleServiceProvider::$migrationDirectory = null;
    FixtureModuleServiceProvider::$withoutMigrations = false;
    FixtureModuleServiceProvider::$installFailsAfterWriting = false;
    FixtureModuleServiceProvider::$installIssuesDdl = false;
    app()->useBootstrapPath($this->developmentBootstrap);
    (new Filesystem)->deleteDirectory($this->developmentDirectory);
});

it('applies a newly added migration to the real installed development package without changing its receipt', function (bool $enabled): void {
    $version = ModuleDiscovery::version(DEVELOPMENT_MODULE);
    expect($version)->toStartWith('dev-');
    ModuleLifecycle::install(app(), DEVELOPMENT_MODULE);

    if ($enabled) {
        ModuleLifecycle::enable(app(), DEVELOPMENT_MODULE);
    }

    DB::table('fixture_dev_upgrade_rows')->insert(['name' => 'retained development content']);
    $receipt = developmentReceipt();
    $this->travel(5)->minutes();
    FixtureModuleServiceProvider::$calls = [];
    Module::saving(static function (Module $module): void {
        if ($module->handle === DEVELOPMENT_MODULE) {
            throw new RuntimeException('The equal development receipt must not be saved again.');
        }
    });
    developmentPendingMigration(FixtureModuleServiceProvider::$migrationDirectory);
    expect(Schema::hasColumn('fixture_dev_upgrade_rows', 'development_note'))->toBeFalse();

    ModuleLifecycle::upgrade(app(), DEVELOPMENT_MODULE);

    expect(Schema::hasColumn('fixture_dev_upgrade_rows', 'development_note'))->toBeTrue()
        ->and(DB::table('fixture_dev_upgrade_rows')->sole()->name)->toBe('retained development content')
        ->and(DB::table('fixture_dev_upgrade_rows')->sole()->generation)->toBe(1)
        ->and(developmentReceipt())->toBe($receipt)
        ->and(ModuleDiscovery::version(DEVELOPMENT_MODULE))->toBe($version)
        ->and(FixtureModuleServiceProvider::$calls)->toBe([]);

    file_put_contents(app()->getCachedRoutesPath(), 'stale development routes');
    app()->instance('routes.cached', true);
    expect(ModuleLifecycle::upgrade(app(), DEVELOPMENT_MODULE))->toContain('already at')
        ->and(DB::table('fixture_dev_upgrade_rows')->sole()->generation)->toBe(1)
        ->and(DB::table('migrations')->where('migration', '2026_10_22_000002_development_module_pending')->count())->toBe(1)
        ->and(developmentReceipt())->toBe($receipt)
        ->and(is_file(app()->getCachedRoutesPath()))->toBeFalse()
        ->and(app()->routesAreCached())->toBeFalse()
        ->and(FixtureModuleServiceProvider::$calls)->toBe([]);
})->with([false, true]);

/** An owned Composer installation supplies each version, then unregisters; package discovery is not mocked. */
it('checks equal development spellings while leaving equal stable and prerelease versions alone', function (string $version, bool $development): void {
    $this->developmentLoader = developmentComposerRecord($this->developmentDirectory, ['pretty_version' => $version, 'version' => $version]);
    expect(ModuleDiscovery::version(DEVELOPMENT_MODULE))->toBe($version);
    ModuleLifecycle::install(app(), DEVELOPMENT_MODULE);
    DB::table('fixture_dev_upgrade_rows')->insert(['name' => 'retained version control']);
    $receipt = developmentReceipt();
    $this->travel(5)->minutes();
    FixtureModuleServiceProvider::$calls = [];
    developmentPendingMigration(FixtureModuleServiceProvider::$migrationDirectory);
    file_put_contents(app()->getCachedRoutesPath(), 'old routes');

    expect(ModuleLifecycle::upgrade(app(), DEVELOPMENT_MODULE))->toContain('already at '.$version)
        ->and(Schema::hasColumn('fixture_dev_upgrade_rows', 'development_note'))->toBe($development)
        ->and(DB::table('fixture_dev_upgrade_rows')->sole()->generation)->toBe($development ? 1 : 0)
        ->and(DB::table('migrations')->where('migration', '2026_10_22_000002_development_module_pending')->count())->toBe($development ? 1 : 0)
        ->and(developmentReceipt())->toBe($receipt)
        ->and(is_file(app()->getCachedRoutesPath()))->toBeFalse()
        ->and(FixtureModuleServiceProvider::$calls)->toBe([]);
})->with([
    ['dev-main', true],
    ['dev-feature/checkout', true],
    ['1.4.x-dev', true],
    ['1.4.0.0-dev', true],
    ['1.4.0', false],
    ['1.4.0-beta.1', false],
    ['1.4.0-RC1', false],
    ['1.4.0-p1', false],
]);

it('reverifies changed development metadata before asking its provider to migrate and can retry corrected metadata', function (): void {
    ModuleLifecycle::install(app(), DEVELOPMENT_MODULE);
    DB::table('fixture_dev_upgrade_rows')->insert(['name' => 'still private']);
    $receipt = developmentReceipt();
    $this->travel(5)->minutes();
    FixtureModuleServiceProvider::$calls = [];
    developmentPendingMigration(FixtureModuleServiceProvider::$migrationDirectory);
    file_put_contents(app()->getCachedRoutesPath(), 'old routes retained');

    // This changed installed package has a well-formed manifest but no classes for its declared model scope.
    $package = $this->developmentDirectory.'/invalid-package';
    mkdir($package, 0700);
    mkdir($package.'/src', 0700);
    copy(ModuleDiscovery::installPath(DEVELOPMENT_MODULE).'/composer.json', $package.'/composer.json');
    $this->developmentLoader = developmentComposerRecord($this->developmentDirectory, ['install_path' => $package]);
    expect(ModuleDiscovery::installPath(DEVELOPMENT_MODULE))->toBe($package);

    expect(fn () => ModuleLifecycle::upgrade(app(), DEVELOPMENT_MODULE))
        ->toThrow(RuntimeException::class, 'sweep examined no classes at all');
    expect(Schema::hasColumn('fixture_dev_upgrade_rows', 'development_note'))->toBeFalse()
        ->and(DB::table('fixture_dev_upgrade_rows')->sole()->generation)->toBe(0)
        ->and(DB::table('migrations')->where('migration', '2026_10_22_000002_development_module_pending')->exists())->toBeFalse()
        ->and(developmentReceipt())->toBe($receipt)
        ->and(file_get_contents(app()->getCachedRoutesPath()))->toBe('old routes retained')
        ->and(FixtureModuleServiceProvider::$calls)->toBe([]);

    $this->developmentLoader->unregister();
    expect(ModuleDiscovery::installPath(DEVELOPMENT_MODULE))->toBe($this->developmentPath);
    ModuleLifecycle::upgrade(app(), DEVELOPMENT_MODULE);
    expect(DB::table('fixture_dev_upgrade_rows')->sole()->generation)->toBe(1)
        ->and(developmentReceipt())->toBe($receipt)
        ->and(is_file(app()->getCachedRoutesPath()))->toBeFalse()
        ->and(FixtureModuleServiceProvider::$calls)->toBe([]);
});

it('retains its receipt and reports the engines real DDL boundary when a pending development migration fails', function (): void {
    ModuleLifecycle::install(app(), DEVELOPMENT_MODULE);
    ModuleLifecycle::enable(app(), DEVELOPMENT_MODULE);
    DB::table('fixture_dev_upgrade_rows')->insert(['name' => 'survives migration failure']);
    $receipt = developmentReceipt();
    $this->travel(5)->minutes();
    FixtureModuleServiceProvider::$calls = [];
    developmentPendingMigration(FixtureModuleServiceProvider::$migrationDirectory, failing: true);
    file_put_contents(app()->getCachedRoutesPath(), 'routes before failure');

    expect(fn () => ModuleLifecycle::upgrade(app(), DEVELOPMENT_MODULE))
        ->toThrow(RuntimeException::class, 'Development fixture stopped after DDL.');
    // Laravel's SQLite grammar does not open a migration transaction either, despite SQLite's DDL capability.
    $nontransactionalDdl = ! DB::connection()->getSchemaGrammar()->supportsSchemaTransactions();
    expect(Schema::hasColumn('fixture_dev_upgrade_rows', 'development_note'))->toBe($nontransactionalDdl)
        ->and(DB::table('fixture_dev_upgrade_rows')->sole()->name)->toBe('survives migration failure')
        ->and(DB::table('fixture_dev_upgrade_rows')->sole()->generation)->toBe(0)
        ->and(DB::table('migrations')->where('migration', '2026_10_22_000001_development_module_initial')->count())->toBe(1)
        ->and(DB::table('migrations')->where('migration', '2026_10_22_000002_development_module_pending')->exists())->toBeFalse()
        ->and(developmentReceipt())->toBe($receipt)
        ->and(file_get_contents(app()->getCachedRoutesPath()))->toBe('routes before failure')
        ->and(FixtureModuleServiceProvider::$calls)->toBe([]);

    // This fixture migration explicitly tolerates its own partial column. Arbitrary migrations need inspection.
    unlink(FixtureModuleServiceProvider::$migrationDirectory.'/fail-after-ddl');
    ModuleLifecycle::upgrade(app(), DEVELOPMENT_MODULE);
    expect(Schema::hasColumn('fixture_dev_upgrade_rows', 'development_note'))->toBeTrue()
        ->and(DB::table('fixture_dev_upgrade_rows')->sole()->generation)->toBe(1)
        ->and(developmentReceipt())->toBe($receipt)
        ->and(is_file(app()->getCachedRoutesPath()))->toBeFalse();
});

it('keeps applied development migrations and its receipt when route cache clearing fails and safely retries', function (): void {
    ModuleLifecycle::install(app(), DEVELOPMENT_MODULE);
    ModuleLifecycle::enable(app(), DEVELOPMENT_MODULE);
    DB::table('fixture_dev_upgrade_rows')->insert(['name' => 'survives cache failure']);
    $receipt = developmentReceipt();
    $this->travel(5)->minutes();
    FixtureModuleServiceProvider::$calls = [];
    developmentPendingMigration(FixtureModuleServiceProvider::$migrationDirectory);
    $path = app()->getCachedRoutesPath();
    file_put_contents($path, 'cache cannot be deleted');
    $original = app('files');
    $files = Mockery::mock(Filesystem::class)->makePartial();
    $files->shouldReceive('delete')->with($path)->once()->andReturnFalse();
    app()->instance('files', $files);

    expect(fn () => ModuleLifecycle::upgrade(app(), DEVELOPMENT_MODULE))
        ->toThrow(RuntimeException::class, 'route cache could not be cleared');
    expect(Schema::hasColumn('fixture_dev_upgrade_rows', 'development_note'))->toBeTrue()
        ->and(DB::table('fixture_dev_upgrade_rows')->sole()->name)->toBe('survives cache failure')
        ->and(DB::table('fixture_dev_upgrade_rows')->sole()->generation)->toBe(1)
        ->and(developmentReceipt())->toBe($receipt)
        ->and(file_get_contents($path))->toBe('cache cannot be deleted');

    app()->instance('files', $original);
    ModuleLifecycle::upgrade(app(), DEVELOPMENT_MODULE);
    expect(DB::table('fixture_dev_upgrade_rows')->sole()->generation)->toBe(1)
        ->and(DB::table('migrations')->where('migration', '2026_10_22_000002_development_module_pending')->count())->toBe(1)
        ->and(developmentReceipt())->toBe($receipt)
        ->and(is_file($path))->toBeFalse()
        ->and(FixtureModuleServiceProvider::$calls)->toBe([]);
});

it('refuses a receipt changed during development migration without overwriting the new version', function (): void {
    ModuleLifecycle::install(app(), DEVELOPMENT_MODULE);
    DB::table('fixture_dev_upgrade_rows')->insert(['name' => 'survives a receipt change']);
    developmentPendingMigration(FixtureModuleServiceProvider::$migrationDirectory);
    file_put_contents(app()->getCachedRoutesPath(), 'cache kept for inspection');
    Event::listen(MigrationEnded::class, static function (MigrationEnded $event): void {
        if ($event->method === 'up') {
            $receipt = Module::query()->where('handle', DEVELOPMENT_MODULE)->firstOrFail();
            $receipt->version = 'changed-by-another-operation';
            $receipt->save();
        }
    });

    expect(fn () => ModuleLifecycle::upgrade(app(), DEVELOPMENT_MODULE))
        ->toThrow(RuntimeException::class, 'receipt changed during its development upgrade');
    expect(developmentReceipt()['version'])->toBe('changed-by-another-operation')
        ->and(DB::table('fixture_dev_upgrade_rows')->sole()->generation)->toBe(1)
        ->and(file_get_contents(app()->getCachedRoutesPath()))->toBe('cache kept for inspection');
});

it('checks a schema-free development package without replaying its install hook or enabling it', function (): void {
    FixtureModuleServiceProvider::$withoutMigrations = true;
    ModuleLifecycle::install(app(), DEVELOPMENT_MODULE);
    $receipt = developmentReceipt();
    $this->travel(5)->minutes();
    FixtureModuleServiceProvider::$calls = [];
    file_put_contents(app()->getCachedRoutesPath(), 'schema-free cache');

    expect(ModuleLifecycle::upgrade(app(), DEVELOPMENT_MODULE))->toContain('Pending development migrations were checked')
        ->and(developmentReceipt())->toBe($receipt)
        ->and(Schema::hasTable('fixture_dev_upgrade_rows'))->toBeFalse()
        ->and(is_file(app()->getCachedRoutesPath()))->toBeFalse()
        ->and(FixtureModuleServiceProvider::$calls)->toBe([]);
});

/** @param list<string> $tokens @return array<string, mixed> */
function developmentColdProbe(string $temporary, string $mode, array $tokens = []): array
{
    $root = dirname(__DIR__, 3);
    $process = new Process([PHP_BINARY, $root.'/tests/Core/Fixtures/ModuleColdCommandProbe.php', $root, $temporary, $mode, ...$tokens], $root);
    $process->setTimeout(30);
    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('upgrades an enabled development module before runtime boot on an actual fresh Artisan process', function (array $options): void {
    $temporary = $this->developmentDirectory.'/cold';
    mkdir($temporary, 0700);
    try {
        $seed = developmentColdProbe($temporary, 'seed');
        expect($seed['status'])->toBe(0)->and($seed['receipt']['is_enabled'])->toBeIn([true, 1, '1']);
        developmentPendingMigration($temporary.'/migrations');
        $normal = developmentColdProbe($temporary, 'command', ['list']);
        expect($normal['status'])->toBe(1)->and($normal['calls'])->toBe(['register', 'boot'])
            ->and($normal['output'])->toContain('development_note');

        $tokens = [...$options, 'kitsune:module', 'upgrade', DEVELOPMENT_MODULE];
        $upgrade = developmentColdProbe($temporary, 'command', $tokens);
        expect($upgrade['status'])->toBe(0, $upgrade['output'])->and($upgrade['calls'])->toBe([])
            ->and($upgrade['maintenanceReads'])->toBe(0)->and($upgrade['column'])->toBeTrue()
            ->and($upgrade['receipt'])->toBe($seed['receipt'])->and($upgrade['rows'][0]['name'])->toBe('retained cold content')
            ->and($upgrade['rows'][0]['generation'])->toBe(1)->and($upgrade['pendingRecorded'])->toBe(1);
        $repeat = developmentColdProbe($temporary, 'command', $tokens);
        expect($repeat['status'])->toBe(0)->and($repeat['receipt'])->toBe($seed['receipt'])
            ->and($repeat['rows'][0]['generation'])->toBe(1)->and($repeat['pendingRecorded'])->toBe(1);
        $normal = developmentColdProbe($temporary, 'command', ['list']);
        expect($normal['status'])->toBe(0, $normal['output'])->and($normal['calls'])->toBe(['register', 'boot']);
    } finally {
        expect(developmentColdProbe($temporary, 'cleanup'))->toBe(['cleaned' => true]);
    }
})->with([[[]], [['--env', 'testing', '--no-interaction']], [['--quiet', '--env=testing']]]);

it('keeps every canonical lifecycle action cold while core still handles its arguments', function (array $tokens, int $status, bool $migrated, bool $enabled): void {
    $temporary = $this->developmentDirectory.'/cold';
    mkdir($temporary, 0700);
    try {
        $seed = developmentColdProbe($temporary, 'seed');
        expect($seed['status'])->toBe(0);
        developmentPendingMigration($temporary.'/migrations');
        $result = developmentColdProbe($temporary, 'command', $tokens);
        expect($result['status'])->toBe($status, $result['output'])->and($result['calls'])->toBe([])
            ->and($result['maintenanceReads'])->toBe(0)->and($result['column'])->toBe($migrated)
            ->and((bool) $result['receipt']['is_enabled'])->toBe($enabled)
            ->and($result['receipt']['version'])->toBe($seed['receipt']['version'])
            ->and($result['rows'][0]['name'])->toBe('retained cold content');
    } finally {
        expect(developmentColdProbe($temporary, 'cleanup'))->toBe(['cleaned' => true]);
    }
})->with([
    'default status' => [['kitsune:module'], 0, false, true],
    'status' => [['kitsune:module', 'status'], 0, false, true],
    'install' => [['kitsune:module', 'install', DEVELOPMENT_MODULE], 1, false, true],
    'enable' => [['kitsune:module', 'enable', DEVELOPMENT_MODULE], 0, false, true],
    'disable' => [['kitsune:module', 'disable', DEVELOPMENT_MODULE], 0, false, false],
    'uninstall refusal' => [['kitsune:module', 'uninstall', DEVELOPMENT_MODULE], 1, false, true],
    'unknown action' => [['kitsune:module', 'nonsense'], 1, false, true],
    'missing package' => [['kitsune:module', 'upgrade'], 1, false, true],
    'trailing globals' => [['kitsune:module', '--env', 'testing', 'upgrade', DEVELOPMENT_MODULE, '--no-ansi'], 0, true, true],
    'end of options' => [['--', 'kitsune:module', 'upgrade', DEVELOPMENT_MODULE], 0, true, true],
]);

it('does not infer a cold lifecycle operation from unrelated console input or a web boot', function (string $mode, array $tokens): void {
    $temporary = $this->developmentDirectory.'/cold';
    mkdir($temporary, 0700);
    try {
        $seed = developmentColdProbe($temporary, 'seed');
        expect($seed['status'])->toBe(0);
        developmentPendingMigration($temporary.'/migrations');
        $result = developmentColdProbe($temporary, $mode, $tokens);
        expect($result['status'])->toBe(1)->and($result['calls'])->toBe(['register', 'boot'])
            ->and($result['output'])->toContain('development_note');
    } finally {
        expect(developmentColdProbe($temporary, 'cleanup'))->toBe(['cleaned' => true]);
    }
})->with([
    'ordinary console' => ['command', ['list']],
    'no console command' => ['command', []],
    'argument value' => ['command', ['list', 'kitsune:module']],
    'separate option value' => ['command', ['--env', 'kitsune:module', 'list']],
    'joined option value' => ['command', ['--env=kitsune:module', 'list']],
    'unsupported abbreviation' => ['command', ['kitsune:mod', 'upgrade', DEVELOPMENT_MODULE]],
    'malformed global option' => ['command', ['--unknown-global', 'kitsune:module']],
    'web ignores console argv' => ['web', ['kitsune:module', 'upgrade', DEVELOPMENT_MODULE]],
]);

it('verifies a cold enabled development package before migration and recovers without booting its runtime', function (): void {
    $temporary = $this->developmentDirectory.'/cold';
    mkdir($temporary, 0700);
    try {
        $seed = developmentColdProbe($temporary, 'seed');
        expect($seed['status'])->toBe(0);
        developmentPendingMigration($temporary.'/migrations');
        $path = $temporary.'/package/composer.json';
        $original = file_get_contents($path);
        $manifest = json_decode($original, true, flags: JSON_THROW_ON_ERROR);
        $manifest['extra']['kitsune']['scoping'] = ['site'];
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));
        $tokens = ['kitsune:module', 'upgrade', DEVELOPMENT_MODULE];
        $refused = developmentColdProbe($temporary, 'command', $tokens);
        expect($refused['status'])->toBe(1)->and($refused['calls'])->toBe([])
            ->and($refused['maintenanceReads'])->toBe(0)->and($refused['column'])->toBeFalse()
            ->and($refused['pendingRecorded'])->toBe(0)->and($refused['receipt'])->toBe($seed['receipt'])
            ->and($refused['output'])->toContain('Unscoped');
        file_put_contents($path, $original);
        $retry = developmentColdProbe($temporary, 'command', $tokens);
        expect($retry['status'])->toBe(0, $retry['output'])->and($retry['calls'])->toBe([])
            ->and($retry['column'])->toBeTrue()->and($retry['pendingRecorded'])->toBe(1)
            ->and($retry['receipt'])->toBe($seed['receipt']);
    } finally {
        expect(developmentColdProbe($temporary, 'cleanup'))->toBe(['cleaned' => true]);
    }
});
