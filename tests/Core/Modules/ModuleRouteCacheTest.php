<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Event;
use Kitsune\Core\Events\Modules\ModuleDisabled;
use Kitsune\Core\Events\Modules\ModuleEnabled;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Modules\ModuleLifecycle;
use Symfony\Component\Process\Process;

/** @return array<string, mixed> */
function moduleCachedBoot(string $temporary, string $mode): array
{
    $root = dirname(__DIR__, 3);
    $probe = new Process([PHP_BINARY, __DIR__.'/../Fixtures/ModuleRouteCacheProbe.php', $root, $temporary, $mode], $root);
    $probe->setTimeout(30);
    $probe->mustRun();

    return json_decode($probe->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    $this->cacheDirectory = sys_get_temp_dir().'/kitsune-module-cache-'.bin2hex(random_bytes(8));
    mkdir($this->cacheDirectory, 0700);
    $this->originalBootstrap = app()->bootstrapPath();
    app()->useBootstrapPath($this->cacheDirectory);
    mkdir($this->cacheDirectory.'/cache', 0700);
});

afterEach(function (): void {
    app()->useBootstrapPath($this->originalBootstrap);
    (new Filesystem)->deleteDirectory($this->cacheDirectory);
});

it('refreshes real cached routes across enable and disable on fresh applications', function (): void {
    $temporary = $this->cacheDirectory.'/host';
    mkdir($temporary, 0700);
    $disabled = moduleCachedBoot($temporary, 'seed-disabled');
    expect($disabled['cache_command'])->toBe(0)->and($disabled['cache_file'])->toBeTrue()
        ->and($disabled['currency_route'])->toBeNull();

    $enabled = moduleCachedBoot($temporary, 'enable');
    expect($enabled['enabled'])->toBeTrue()->and($enabled['cache_file'])->toBeFalse()->and($enabled['cache_memo'])->toBeFalse();
    $fresh = moduleCachedBoot($temporary, 'read');
    expect($fresh['currency_route'])->toEndWith('/storefront/currency')
        ->and($fresh['currency_url'])->toEndWith('/admin/golfdom/storefront/currency')->and($fresh['dashboard_route'])->not->toBeNull();

    $cached = moduleCachedBoot($temporary, 'cache');
    expect($cached['cache_command'])->toBe(0)->and($cached['cache_file'])->toBeTrue()
        ->and($cached['currency_route'])->toEndWith('/storefront/currency');
    $disabled = moduleCachedBoot($temporary, 'disable');
    expect($disabled['enabled'])->toBeFalse()->and($disabled['cache_file'])->toBeFalse()->and($disabled['cache_memo'])->toBeFalse();
    $fresh = moduleCachedBoot($temporary, 'read');
    expect($fresh['currency_route'])->toBeNull()->and($fresh['dashboard_route'])->not->toBeNull();
});

it('does not register stale enabled module code merely because an old cached URI exists', function (): void {
    $temporary = $this->cacheDirectory.'/host';
    mkdir($temporary, 0700);
    $cached = moduleCachedBoot($temporary, 'seed-enabled');
    expect($cached['cache_file'])->toBeTrue()->and($cached['currency_route'])->toEndWith('/storefront/currency');
    moduleCachedBoot($temporary, 'stale');
    $fresh = moduleCachedBoot($temporary, 'read');
    expect($fresh['enabled'])->toBeTrue()->and($fresh['cache_file'])->toBeTrue()
        ->and($fresh['currency_route'])->toEndWith('/storefront/currency')->and($fresh['provider_registered'])->toBeFalse();
});

it('leaves a cancelled switch unchanged with an uncached application safe to boot', function (): void {
    $receipt = Module::create(['handle' => 'kitsune/fixture-module', 'version' => ModuleDiscovery::version('kitsune/fixture-module'), 'is_enabled' => false, 'installed_at' => now()]);
    file_put_contents(app()->getCachedRoutesPath(), 'old module routes');
    Event::fake([ModuleEnabled::class]);
    Module::updating(static fn () => false);

    expect(fn () => ModuleLifecycle::enable(app(), 'kitsune/fixture-module'))->toThrow(RuntimeException::class, 'switch could not be saved')
        ->and($receipt->fresh()->is_enabled)->toBeFalse()->and(is_file(app()->getCachedRoutesPath()))->toBeFalse();
    Event::assertNotDispatched(ModuleEnabled::class);
});

it('leaves the switch and events unchanged if cache deletion fails', function (bool $enabled, string $failure): void {
    $receipt = Module::create(['handle' => 'kitsune/fixture-module', 'version' => ModuleDiscovery::version('kitsune/fixture-module'), 'is_enabled' => $enabled, 'installed_at' => now()]);
    $path = app()->getCachedRoutesPath();
    file_put_contents($path, 'cached routes retained');
    Event::fake([ModuleEnabled::class, ModuleDisabled::class]);
    $files = Mockery::mock(Filesystem::class)->makePartial();

    if ($failure === 'throw') {
        $files->shouldReceive('delete')->with($path)->once()->andThrow(new RuntimeException('Fixture filesystem failure.'));
    } elseif ($failure === 'false') {
        $files->shouldReceive('delete')->with($path)->once()->andReturnFalse();
    } else {
        $files->shouldReceive('delete')->with($path)->once()->andReturnTrue();
    }

    app()->instance('files', $files);
    expect(fn () => $enabled ? ModuleLifecycle::disable(app(), 'kitsune/fixture-module') : ModuleLifecycle::enable(app(), 'kitsune/fixture-module'))
        ->toThrow(RuntimeException::class, 'route cache could not be cleared');
    expect($receipt->fresh()->is_enabled)->toBe($enabled)->and(file_get_contents($path))->toBe('cached routes retained');
    Event::assertNotDispatched(ModuleEnabled::class);
    Event::assertNotDispatched(ModuleDisabled::class);
})->with([false, true])->with(['false', 'throw', 'still present']);

it('checks the cache file directly instead of a stale cached boolean and repairs idempotent switches', function (bool $enabled): void {
    Module::create(['handle' => 'kitsune/fixture-module', 'version' => ModuleDiscovery::version('kitsune/fixture-module'), 'is_enabled' => $enabled, 'installed_at' => now()]);
    app()->instance('routes.cached', false);
    $path = app()->getCachedRoutesPath();
    file_put_contents($path, 'cache appeared after the memo was set');
    Event::fake([ModuleEnabled::class, ModuleDisabled::class]);

    $message = $enabled ? ModuleLifecycle::enable(app(), 'kitsune/fixture-module') : ModuleLifecycle::disable(app(), 'kitsune/fixture-module');
    expect($message)->toContain('already')->and(is_file($path))->toBeFalse()->and(app()->routesAreCached())->toBeFalse();
    Event::assertNotDispatched(ModuleEnabled::class);
    Event::assertNotDispatched(ModuleDisabled::class);
})->with([false, true]);

it('clears a stale true memo when the cache file is already absent', function (): void {
    Module::create(['handle' => 'kitsune/fixture-module', 'version' => ModuleDiscovery::version('kitsune/fixture-module'), 'is_enabled' => false, 'installed_at' => now()]);
    app()->instance('routes.cached', true);
    ModuleLifecycle::enable(app(), 'kitsune/fixture-module');
    expect(app()->routesAreCached())->toBeFalse();
});

it('invalidates an enabled upgrades route cache and refuses its receipt change if clearing fails', function (bool $canDelete): void {
    $receipt = Module::create(['handle' => 'kitsune/fixture-module', 'version' => 'old-version', 'is_enabled' => true, 'installed_at' => now()]);
    $path = app()->getCachedRoutesPath();
    file_put_contents($path, 'old module routes');
    $migrator = Mockery::mock(Migrator::class);
    $migrator->shouldReceive('repositoryExists')->once()->andReturnTrue();
    $migrator->shouldReceive('run')->once()->andReturn([]);
    app()->instance('migrator', $migrator);

    if (! $canDelete) {
        $files = Mockery::mock(Filesystem::class)->makePartial();
        $files->shouldReceive('delete')->with($path)->once()->andReturnFalse();
        app()->instance('files', $files);
        expect(fn () => ModuleLifecycle::upgrade(app(), 'kitsune/fixture-module'))->toThrow(RuntimeException::class, 'route cache could not be cleared');
        expect($receipt->fresh()->version)->toBe('old-version')->and(is_file($path))->toBeTrue();
    } else {
        expect(ModuleLifecycle::upgrade(app(), 'kitsune/fixture-module'))->toContain('Upgraded')
            ->and($receipt->fresh()->version)->toBe(ModuleDiscovery::version('kitsune/fixture-module'))->and(is_file($path))->toBeFalse();
    }

    expect($receipt->fresh()->is_enabled)->toBeTrue();
})->with([false, true]);

it('clears cached routes on an idempotent upgrade too', function (): void {
    Module::create(['handle' => 'kitsune/fixture-module', 'version' => ModuleDiscovery::version('kitsune/fixture-module'), 'is_enabled' => true, 'installed_at' => now()]);
    $path = app()->getCachedRoutesPath();
    file_put_contents($path, 'old module routes');
    expect(ModuleLifecycle::upgrade(app(), 'kitsune/fixture-module'))->toContain('already at')
        ->and(is_file($path))->toBeFalse();
});
