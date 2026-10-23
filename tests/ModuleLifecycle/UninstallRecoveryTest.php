<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\ModuleUninstall;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Modules\ModuleLifecycle;
use Kitsune\Fixture\Module\FixtureModuleServiceProvider;

const UNINSTALL_PACKAGE = 'kitsune/fixture-module';
const UNINSTALL_MIGRATION = '2026_10_23_000001_interrupted_uninstall';

beforeEach(function (): void {
    $this->uninstallDirectory = sys_get_temp_dir().'/kitsune-uninstall-'.bin2hex(random_bytes(8));
    mkdir($this->uninstallDirectory, 0700);
    FixtureModuleServiceProvider::$calls = [];
    FixtureModuleServiceProvider::$withoutMigrations = false;
    FixtureModuleServiceProvider::$installFailsAfterWriting = false;
    FixtureModuleServiceProvider::$installIssuesDdl = false;
    FixtureModuleServiceProvider::$migrationDirectory = $this->uninstallDirectory;
    ModuleKernel::flush();

    file_put_contents($this->uninstallDirectory.'/'.UNINSTALL_MIGRATION.'.php', <<<'PHP'
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
        foreach (['fixture_things', 'uninstall_early', 'uninstall_late'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->string('name')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::drop('uninstall_early');
        if (is_file(__DIR__.'/fail-down')) {
            Schema::drop('uninstall_deliberately_missing');
        }
        Schema::drop('uninstall_late');
        Schema::drop('fixture_things');
    }
};
PHP);
});

afterEach(function (): void {
    FixtureModuleServiceProvider::$migrationDirectory = null;
    FixtureModuleServiceProvider::$withoutMigrations = false;
    (new Filesystem)->deleteDirectory($this->uninstallDirectory);
});

it('records failed reset after committing receipt removal and blocks every lifecycle retry', function (): void {
    expect(DB::connection()->transactionLevel())->toBe(0);
    ModuleLifecycle::install(app(), UNINSTALL_PACKAGE);
    DB::table('uninstall_early')->insert(['name' => 'Synthetic early row']);
    DB::table('uninstall_late')->insert(['name' => 'Synthetic late row']);
    file_put_contents($this->uninstallDirectory.'/fail-down', 'owned failure switch');

    expect(fn () => ModuleLifecycle::uninstall(app(), UNINSTALL_PACKAGE))
        ->toThrow(RuntimeException::class, 'uninstall is incomplete');

    expect(Module::query()->where('handle', UNINSTALL_PACKAGE)->exists())->toBeFalse()
        ->and(ModuleUninstall::query()->where('handle', UNINSTALL_PACKAGE)->value('version'))->toBe(ModuleDiscovery::version(UNINSTALL_PACKAGE))
        ->and(DB::table('migrations')->where('migration', UNINSTALL_MIGRATION)->count())->toBe(1)
        ->and(Schema::hasTable('uninstall_early'))->toBe(DB::connection()->getSchemaGrammar()->supportsSchemaTransactions())
        ->and(DB::table('uninstall_late')->value('name'))->toBe('Synthetic late row');

    $calls = FixtureModuleServiceProvider::$calls;
    foreach (['install', 'enable', 'disable', 'upgrade', 'uninstall'] as $action) {
        expect(fn () => ModuleLifecycle::$action(app(), UNINSTALL_PACKAGE))
            ->toThrow(RuntimeException::class, 'uninstall is incomplete');
    }
    expect(FixtureModuleServiceProvider::$calls)->toBe($calls)
        ->and(Module::query()->where('handle', UNINSTALL_PACKAGE)->exists())->toBeFalse();
    $rows = ModuleLifecycle::status();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['enabled'])->toBeFalse()
        ->and($rows[0]['status'])->toStartWith('uninstall incomplete');
    $this->artisan('kitsune:module status')->expectsOutputToContain('uninstall incomplete')->assertFailed();
});

it('cannot run an enabled receipt planted over an incomplete uninstall marker', function (): void {
    ModuleUninstall::create(['handle' => UNINSTALL_PACKAGE, 'version' => ModuleDiscovery::version(UNINSTALL_PACKAGE), 'started_at' => now()]);
    Module::create(['handle' => UNINSTALL_PACKAGE, 'version' => ModuleDiscovery::version(UNINSTALL_PACKAGE), 'installed_at' => now(), 'is_enabled' => true]);
    FixtureModuleServiceProvider::$calls = [];
    FixtureModuleServiceProvider::$maintenanceReads = 0;

    ModuleKernel::boot(app());

    expect(Module::isEnabled(UNINSTALL_PACKAGE))->toBeFalse()
        ->and(FixtureModuleServiceProvider::$calls)->toBe([])
        ->and(FixtureModuleServiceProvider::$maintenanceReads)->toBe(0)
        ->and(ModuleLifecycle::status()[0]['enabled'])->toBeFalse()
        ->and(ModuleLifecycle::status()[0]['status'])->toStartWith('uninstall incomplete');
});

it('clears the marker only after a successful real reset and permits a fresh install', function (): void {
    ModuleLifecycle::install(app(), UNINSTALL_PACKAGE);
    expect(ModuleLifecycle::uninstall(app(), UNINSTALL_PACKAGE))->toContain('Uninstalled');
    expect(ModuleUninstall::query()->count())->toBe(0)
        ->and(Module::query()->count())->toBe(0)
        ->and(Schema::hasTable('uninstall_early'))->toBeFalse()
        ->and(DB::table('migrations')->where('migration', UNINSTALL_MIGRATION)->count())->toBe(0);
    expect(ModuleLifecycle::install(app(), UNINSTALL_PACKAGE))->toContain('not enabled yet')
        ->and(Schema::hasTable('uninstall_early'))->toBeTrue();
});

it('lets a module refuse content teardown and retry after removal with real outer commits', function (): void {
    // Moved from the wrapped ModuleLifecycleTest; keep its table/data/receipt and successful-retry assertions.
    expect(DB::connection()->transactionLevel())->toBe(0);
    ModuleLifecycle::install(app(), UNINSTALL_PACKAGE);
    DB::table('fixture_things')->insert(['name' => 'Retained fixture content']);

    expect(fn () => ModuleLifecycle::uninstall(app(), UNINSTALL_PACKAGE))
        ->toThrow(RuntimeException::class, 'still holds things');
    expect(ModuleUninstall::query()->count())->toBe(0)
        ->and(Module::query()->where('handle', UNINSTALL_PACKAGE)->exists())->toBeTrue()
        ->and(DB::table('fixture_things')->value('name'))->toBe('Retained fixture content')
        ->and(DB::table('fixture_things')->count())->toBe(1)
        ->and(Schema::hasTable('fixture_things'))->toBeTrue()
        ->and(Schema::hasTable('uninstall_early'))->toBeTrue();

    DB::table('fixture_things')->delete();
    expect(ModuleLifecycle::uninstall(app(), UNINSTALL_PACKAGE))->toContain('Uninstalled');
    expect(Schema::hasTable('fixture_things'))->toBeFalse()
        ->and(ModuleUninstall::query()->count())->toBe(0)
        ->and(Module::query()->count())->toBe(0);
});

it('refuses teardown if its marker cannot be written', function (): void {
    ModuleLifecycle::install(app(), UNINSTALL_PACKAGE);
    $refuse = true;
    DB::connection()->beforeExecuting(static function (string $sql) use (&$refuse): void {
        if ($refuse && str_starts_with($sql, 'insert into') && str_contains($sql, 'module_uninstalls')) {
            throw new RuntimeException('Owned marker write failure');
        }
    });
    try {
        expect(fn () => ModuleLifecycle::uninstall(app(), UNINSTALL_PACKAGE))
            ->toThrow(RuntimeException::class, 'Owned marker write failure');
    } finally {
        $refuse = false;
    }
    expect(FixtureModuleServiceProvider::$calls)->toBe(['install'])
        ->and(Module::query()->where('handle', UNINSTALL_PACKAGE)->exists())->toBeTrue()
        ->and(ModuleUninstall::query()->count())->toBe(0)
        ->and(Schema::hasTable('uninstall_early'))->toBeTrue();
});

it('keeps the incomplete marker if completion cannot remove it', function (): void {
    ModuleLifecycle::install(app(), UNINSTALL_PACKAGE);
    $refuse = true;
    DB::connection()->beforeExecuting(static function (string $sql) use (&$refuse): void {
        if ($refuse && str_starts_with($sql, 'delete from') && str_contains($sql, 'module_uninstalls')) {
            throw new RuntimeException('Owned marker completion failure');
        }
    });
    try {
        expect(fn () => ModuleLifecycle::uninstall(app(), UNINSTALL_PACKAGE))
            ->toThrow(RuntimeException::class, 'uninstall is incomplete');
    } finally {
        $refuse = false;
    }
    expect(Module::query()->count())->toBe(0)
        ->and(ModuleUninstall::query()->count())->toBe(1)
        ->and(Schema::hasTable('uninstall_early'))->toBeFalse();
    expect(fn () => ModuleLifecycle::install(app(), UNINSTALL_PACKAGE))
        ->toThrow(RuntimeException::class, 'uninstall is incomplete');
});

it('does not admit runtime or mutate lifecycle state when the marker query fails', function (): void {
    ModuleLifecycle::install(app(), UNINSTALL_PACKAGE);
    ModuleLifecycle::enable(app(), UNINSTALL_PACKAGE);
    FixtureModuleServiceProvider::$calls = [];
    FixtureModuleServiceProvider::$maintenanceReads = 0;
    Log::spy();
    $refuse = true;
    DB::connection()->beforeExecuting(static function (string $sql) use (&$refuse): void {
        if ($refuse && str_contains($sql, 'module_uninstalls')) {
            throw new RuntimeException('Owned marker query failure');
        }
    });
    try {
        ModuleKernel::boot(app());
        expect(FixtureModuleServiceProvider::$calls)->toBe([])
            ->and(FixtureModuleServiceProvider::$maintenanceReads)->toBe(0);
        foreach (['install', 'enable', 'disable', 'upgrade', 'uninstall'] as $action) {
            expect(fn () => ModuleLifecycle::$action(app(), UNINSTALL_PACKAGE))
                ->toThrow(RuntimeException::class, 'Owned marker query failure');
        }
        expect(fn () => Module::isEnabled(UNINSTALL_PACKAGE))->toThrow(RuntimeException::class, 'Owned marker query failure');
        expect(fn () => ModuleLifecycle::status())->toThrow(RuntimeException::class, 'Owned marker query failure');
    } finally {
        $refuse = false;
    }
    expect(Module::query()->where('handle', UNINSTALL_PACKAGE)->value('is_enabled'))->toBeTrue()
        ->and(Schema::hasTable('uninstall_early'))->toBeTrue()
        ->and(ModuleUninstall::query()->count())->toBe(0);
    Log::shouldHaveReceived('warning')->once();
});

it('does not destroy data when a model event cancels marker creation', function (): void {
    ModuleLifecycle::install(app(), UNINSTALL_PACKAGE);
    ModuleUninstall::creating(static fn (): bool => false);

    expect(fn () => ModuleLifecycle::uninstall(app(), UNINSTALL_PACKAGE))
        ->toThrow(RuntimeException::class, 'marker could not be saved');
    expect(FixtureModuleServiceProvider::$calls)->toBe(['install'])
        ->and(Module::query()->count())->toBe(1)
        ->and(ModuleUninstall::query()->count())->toBe(0)
        ->and(Schema::hasTable('uninstall_early'))->toBeTrue();
});

it('rolls the marker back if a model event cancels receipt removal', function (): void {
    ModuleLifecycle::install(app(), UNINSTALL_PACKAGE);
    Module::deleting(static fn (): bool => false);

    expect(fn () => ModuleLifecycle::uninstall(app(), UNINSTALL_PACKAGE))
        ->toThrow(RuntimeException::class, 'receipt could not be removed');
    expect(FixtureModuleServiceProvider::$calls)->toBe(['install', 'uninstall'])
        ->and(Module::query()->count())->toBe(1)
        ->and(ModuleUninstall::query()->count())->toBe(0)
        ->and(Schema::hasTable('uninstall_early'))->toBeTrue();
});

it('does not clear its marker when reset leaves this module migration recorded', function (): void {
    ModuleLifecycle::install(app(), UNINSTALL_PACKAGE);
    // Controlled failure of reset's postcondition; the other cases exercise the actual migrator and DDL.
    $migrator = Mockery::mock(Migrator::class, [app('migration.repository'), app('db'), app('files'), app('events')])->makePartial();
    $migrator->shouldReceive('reset')->once()->andReturn([]);
    app()->instance('migrator', $migrator);

    expect(fn () => ModuleLifecycle::uninstall(app(), UNINSTALL_PACKAGE))
        ->toThrow(RuntimeException::class, 'uninstall is incomplete');
    expect(Module::query()->count())->toBe(0)
        ->and(ModuleUninstall::query()->count())->toBe(1)
        ->and(DB::table('migrations')->where('migration', UNINSTALL_MIGRATION)->count())->toBe(1)
        ->and(Schema::hasTable('uninstall_early'))->toBeTrue();
});
