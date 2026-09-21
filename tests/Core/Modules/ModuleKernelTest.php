<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Fixture\Module\FixtureModuleServiceProvider;

/**
 * ⚠️ THE FIXTURE IS A REAL COMPOSER PACKAGE, symlinked into vendor through a path repository in the root
 * `composer.json`. That matters: `InstalledVersions::isInstalled()`, `getPrettyVersion()` and
 * `getInstallPath()` all run for real, so these tests exercise the discovery the kernel actually uses rather
 * than a list handed to it. An injected package source is the seam that would make every assertion below
 * vacuous.
 */
const FIXTURE = 'kitsune/fixture-module';

function installedVersion(): string
{
    return (string) InstalledVersions::getPrettyVersion(FIXTURE);
}

function enable(?string $version = null, bool $enabled = true): Module
{
    return Module::create([
        'handle' => FIXTURE,
        'version' => $version ?? installedVersion(),
        'is_enabled' => $enabled,
        'installed_at' => now(),
    ]);
}

beforeEach(function (): void {
    FixtureModuleServiceProvider::$calls = [];
    ModuleKernel::flush();
});

it('registers and boots an enabled module whose receipt matches what is installed', function (): void {
    enable();

    ModuleKernel::boot(app());

    /*
     * Both, and the order matters: `register` comes from the kernel's own registration, `boot` from Laravel
     * booting the provider — which happens because the kernel runs inside `booted()`, when the application
     * boots a provider the moment it is registered.
     */
    expect(FixtureModuleServiceProvider::$calls)->toBe(['register', 'boot']);
});

/** ⚠️ Absent means disabled. Nothing runs for a module nobody recorded installing. */
it('registers nothing when there is no receipt', function (): void {
    ModuleKernel::boot(app());

    expect(FixtureModuleServiceProvider::$calls)->toBe([]);
});

it('registers nothing for a receipt that is switched off', function (): void {
    enable(enabled: false);

    ModuleKernel::boot(app());

    expect(FixtureModuleServiceProvider::$calls)->toBe([]);
});

/**
 * ⚠️ A STALE RECEIPT IS REFUSED, NOT TRUSTED. This is what makes a forgotten upgrade loud instead of letting
 * new code run against a schema its own migrations have not reached.
 */
it('refuses a receipt whose recorded version is not what Composer reports', function (): void {
    Log::spy();

    enable(version: '0.0.1-stale');

    ModuleKernel::boot(app());

    expect(FixtureModuleServiceProvider::$calls)->toBe([]);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, FIXTURE)
            && str_contains($message, '0.0.1-stale')
            && str_contains($message, 'kitsune:module upgrade'))
        ->once();
});

/**
 * ⚠️ THE DOOR, AND IT IS THE POINT OF THE BASE CLASS. A provider registered by anything other than the kernel
 * has skipped the receipt, the version check and the scoping verification, so it refuses to register at all
 * rather than running unrecorded.
 */
it('refuses a module provider registered outside the kernel', function (): void {
    expect(fn () => app()->register(FixtureModuleServiceProvider::class))
        ->toThrow(RuntimeException::class, 'was registered outside the module kernel');

    expect(FixtureModuleServiceProvider::$calls)->toBe([]);
});

/**
 * ⚠️ THE DOOR CLOSES BEHIND THE KERNEL, so one legitimate registration does not leave the class registerable
 * by hand for the rest of the process.
 *
 * ⚠️ AND THE SECOND ATTEMPT HAS TO BE FORCED TO MEAN ANYTHING, which a first draft of this test got wrong.
 * `Application::register()` returns the already-registered instance and does no work, so an unforced second
 * call never reaches the guard and proves nothing — the test passed for the wrong reason until it was made to
 * force. `force: true` is the real attack shape: a host deliberately re-registering a module's provider.
 */
it('closes the door behind a legitimate registration', function (): void {
    expect(ModuleKernel::isRegistering(FixtureModuleServiceProvider::class))->toBeFalse();

    enable();

    ModuleKernel::boot(app());

    expect(FixtureModuleServiceProvider::$calls)->toBe(['register', 'boot'])
        ->and(ModuleKernel::isRegistering(FixtureModuleServiceProvider::class))->toBeFalse();

    expect(fn () => app()->register(FixtureModuleServiceProvider::class, true))
        ->toThrow(RuntimeException::class, 'was registered outside the module kernel');
});

it('reports no registration in flight when nothing is being registered', function (): void {
    expect(ModuleKernel::isRegistering(FixtureModuleServiceProvider::class))->toBeFalse()
        ->and(ModuleKernel::isRegistering('Anything\\At\\All'))->toBeFalse();
});

/**
 * ⚠️ REGRESSION. The kernel originally let a database failure propagate, on the argument that swallowing one
 * is the fail-open reading of a fail-closed house. Running `composer skeleton:install` disproved it: that
 * script runs `artisan package:discover` BEFORE it writes `.env`, so the application boots with no database,
 * `Schema::hasTable()` threw, and a fresh install of Kitsune failed at the step that discovers Kitsune.
 *
 * The assertion is deliberately about the install path rather than about a web request: an installation whose
 * database is genuinely unreachable fails on its first query anyway, so what matters is that the kernel is
 * never the reason a working installation cannot be created.
 */
it('declines rather than throws when the database cannot answer', function (): void {
    Log::spy();

    /*
     * The failure is forced at the read rather than by repointing `database.default`, which was the first
     * attempt: changing the default connection also breaks RefreshDatabase's rollback, so the test failed in
     * teardown with the same exception class and looked like the kernel had let it escape. The contract under
     * test is "whatever the read throws, the kernel declines", and this states exactly that.
     */
    Schema::shouldReceive('hasTable')
        ->with('modules')
        ->andThrow(new InvalidArgumentException('Database connection [pgsql] not configured.'));

    ModuleKernel::boot(app());

    expect(FixtureModuleServiceProvider::$calls)->toBe([]);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'could not read the modules table')
            && str_contains($message, 'not configured'))
        ->once();
});
