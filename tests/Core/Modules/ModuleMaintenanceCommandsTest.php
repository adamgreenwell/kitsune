<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Fixture\Module\FixtureModuleServiceProvider;

final class RetainedDataFixtureCommand extends Command
{
    protected $signature = 'kitsune:fixture-retained-data';

    public function handle(): int
    {
        $this->line('Retained data command ran.');

        return self::SUCCESS;
    }
}

abstract class AbstractRetainedDataFixtureCommand extends Command
{
    protected $signature = 'kitsune:fixture-abstract-retained-data';
}

final class RuntimeBoundRetainedDataFixtureCommand extends Command
{
    protected $signature = 'kitsune:fixture-runtime-bound-retained-data';

    public function __construct(string $runtimeOnly)
    {
        parent::__construct();
    }
}

beforeEach(function (): void {
    ConsoleApplication::forgetBootstrappers();
    FixtureModuleServiceProvider::$calls = [];
    FixtureModuleServiceProvider::$maintenanceReads = 0;
    FixtureModuleServiceProvider::$maintenanceClasses = [RetainedDataFixtureCommand::class];
    ModuleKernel::flush();
});

afterEach(function (): void {
    ConsoleApplication::forgetBootstrappers();
    FixtureModuleServiceProvider::$maintenanceClasses = [];
    FixtureModuleServiceProvider::$maintenanceReads = 0;
});

it('executes retained-data commands for disabled and stale receipts without booting runtime', function (bool $enabled, bool $stale): void {
    Module::create(['handle' => 'kitsune/fixture-module',
        'version' => $stale ? 'stale-version' : InstalledVersions::getPrettyVersion('kitsune/fixture-module'),
        'is_enabled' => $enabled, 'installed_at' => now()]);

    ModuleKernel::boot(app());
    $console = new ConsoleApplication(app(), app('events'), 'test');

    expect($console->call('kitsune:fixture-retained-data'))->toBe(Command::SUCCESS)
        ->and($console->output())->toContain('Retained data command ran.')
        ->and(FixtureModuleServiceProvider::$calls)->toBe([])
        ->and(FixtureModuleServiceProvider::$maintenanceReads)->toBe(1);
})->with([[false, false], [true, true], [false, true]]);

it('does not expose a package command without an installed receipt', function (): void {
    ModuleKernel::boot(app());
    $console = new ConsoleApplication(app(), app('events'), 'test');

    expect($console->has('kitsune:fixture-retained-data'))->toBeFalse()
        ->and(FixtureModuleServiceProvider::$maintenanceReads)->toBe(0);
});

it('never declares maintenance commands on a web boot', function (): void {
    Module::create(['handle' => 'kitsune/fixture-module',
        'version' => InstalledVersions::getPrettyVersion('kitsune/fixture-module'),
        'is_enabled' => false, 'installed_at' => now()]);
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('runningInConsole')->once()->andReturnFalse();

    ModuleKernel::boot($application);

    expect(FixtureModuleServiceProvider::$calls)->toBe([])
        ->and(FixtureModuleServiceProvider::$maintenanceReads)->toBe(0);
});

it('declines an invalid command declaration without exposing a partial list', function (string $invalid): void {
    Module::create(['handle' => 'kitsune/fixture-module',
        'version' => InstalledVersions::getPrettyVersion('kitsune/fixture-module'),
        'is_enabled' => false, 'installed_at' => now()]);
    FixtureModuleServiceProvider::$maintenanceClasses = [RetainedDataFixtureCommand::class, $invalid];

    ModuleKernel::boot(app());
    $console = new ConsoleApplication(app(), app('events'), 'test');

    expect($console->has('kitsune:fixture-retained-data'))->toBeFalse()
        ->and(FixtureModuleServiceProvider::$calls)->toBe([]);
})->with([stdClass::class, AbstractRetainedDataFixtureCommand::class, RuntimeBoundRetainedDataFixtureCommand::class]);
