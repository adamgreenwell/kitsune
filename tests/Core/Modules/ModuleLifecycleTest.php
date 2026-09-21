<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Kitsune\Core\Events\Modules\ModuleDisabled;
use Kitsune\Core\Events\Modules\ModuleEnabled;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Modules\ModuleLifecycle;
use Kitsune\Fixture\Module\FixtureModuleServiceProvider;

/** The real installed fixture package — see ModuleKernelTest for why that matters. */
const PKG = 'kitsune/fixture-module';

beforeEach(function (): void {
    FixtureModuleServiceProvider::$calls = [];
    ModuleKernel::flush();
});

/**
 * ⚠️ INSTALL LEAVES THE SWITCH OFF, and that is the whole shape of the lifecycle. Install puts schema in
 * place; enabling is a separate, deliberate act — so an interrupted install cannot leave code running.
 */
it('installs a module without enabling it', function (): void {
    $message = ModuleLifecycle::install(app(), PKG);

    expect($message)->toContain('not enabled yet');

    $receipt = Module::query()->where('handle', PKG)->firstOrFail();

    expect($receipt->is_enabled)->toBeFalse()
        ->and($receipt->version)->toBe(ModuleDiscovery::version(PKG))
        ->and(Module::isEnabled(PKG))->toBeFalse();

    /*
      * Install runs the module's `install()` hook and nothing else: the provider is never registered and
      * never booted, so none of the module's runtime code is live until the switch is thrown.
      */
    expect(FixtureModuleServiceProvider::$calls)->toBe(['install'])
        ->and(FixtureModuleServiceProvider::$calls)->not->toContain('register')
        ->and(FixtureModuleServiceProvider::$calls)->not->toContain('boot');
});

it('refuses to install the same module twice', function (): void {
    ModuleLifecycle::install(app(), PKG);

    expect(fn () => ModuleLifecycle::install(app(), PKG))
        ->toThrow(RuntimeException::class, 'already installed');
});

it('refuses to install a package Composer does not have', function (): void {
    expect(fn () => ModuleLifecycle::install(app(), 'kitsune/not-a-real-package'))
        ->toThrow(RuntimeException::class, 'not installed by Composer');
});

it('enables an installed module, and the kernel then registers it', function (): void {
    Event::fake([ModuleEnabled::class]);

    ModuleLifecycle::install(app(), PKG);
    ModuleLifecycle::enable(app(), PKG);

    expect(Module::isEnabled(PKG))->toBeTrue();

    Event::assertDispatched(ModuleEnabled::class, fn (ModuleEnabled $e): bool => $e->handle === PKG);

    /* The switch is only meaningful if the kernel acts on it. */
    ModuleKernel::boot(app());

    expect(FixtureModuleServiceProvider::$calls)->toBe(['install', 'register', 'boot']);
});

it('disables a module, and the kernel then registers nothing', function (): void {
    Event::fake([ModuleDisabled::class]);

    ModuleLifecycle::install(app(), PKG);
    ModuleLifecycle::enable(app(), PKG);
    $message = ModuleLifecycle::disable(app(), PKG);

    expect($message)->toContain('schema and data are untouched');

    Event::assertDispatched(ModuleDisabled::class);

    ModuleKernel::boot(app());

    /*
     * Only the install hook ever ran. Enabling writes the switch and dispatches an event; it does not
     * register anything itself — the kernel does that at boot, and by then the module is off again.
     */
    expect(FixtureModuleServiceProvider::$calls)->toBe(['install']);
});

it('refuses to enable or disable something that was never installed', function (): void {
    expect(fn () => ModuleLifecycle::enable(app(), PKG))
        ->toThrow(RuntimeException::class, 'is not installed');

    expect(fn () => ModuleLifecycle::disable(app(), PKG))
        ->toThrow(RuntimeException::class, 'is not installed');
});

it('is idempotent about enabling and disabling', function (): void {
    ModuleLifecycle::install(app(), PKG);
    ModuleLifecycle::enable(app(), PKG);

    expect(ModuleLifecycle::enable(app(), PKG))->toContain('already enabled');

    ModuleLifecycle::disable(app(), PKG);

    expect(ModuleLifecycle::disable(app(), PKG))->toContain('already disabled');
});

/** ⚠️ A running module is not uninstalled out from under itself. */
it('refuses to uninstall a module that is still enabled', function (): void {
    ModuleLifecycle::install(app(), PKG);
    ModuleLifecycle::enable(app(), PKG);

    expect(fn () => ModuleLifecycle::uninstall(app(), PKG))
        ->toThrow(RuntimeException::class, 'Run `kitsune:module disable');

    expect(Module::query()->where('handle', PKG)->exists())->toBeTrue();
});

it('uninstalls a disabled module and removes its receipt', function (): void {
    ModuleLifecycle::install(app(), PKG);

    expect(ModuleLifecycle::uninstall(app(), PKG))->toContain('Uninstalled');

    expect(Module::query()->where('handle', PKG)->exists())->toBeFalse()
        ->and(Module::isEnabled(PKG))->toBeFalse();
});

it('reports status for what is installed', function (): void {
    expect(ModuleLifecycle::status())->toBe([]);

    ModuleLifecycle::install(app(), PKG);

    $status = ModuleLifecycle::status();

    expect($status)->toHaveCount(1)
        ->and($status[0]['handle'])->toBe(PKG)
        ->and($status[0]['enabled'])->toBeFalse()
        ->and($status[0]['status'])->toBe('ok');
});

/** A receipt recording a version Composer no longer reports is called stale rather than ok. */
it('reports a stale receipt as stale', function (): void {
    ModuleLifecycle::install(app(), PKG);

    $receipt = Module::query()->where('handle', PKG)->firstOrFail();
    $receipt->version = '0.0.1-stale';
    $receipt->save();

    expect(ModuleLifecycle::status()[0]['status'])->toContain('stale');

    /* And enabling is refused until the upgrade is run, rather than quietly running new code. */
    expect(fn () => ModuleLifecycle::enable(app(), PKG))
        ->toThrow(RuntimeException::class, 'kitsune:module upgrade');
});

it('upgrades a stale receipt to what Composer reports', function (): void {
    ModuleLifecycle::install(app(), PKG);

    $receipt = Module::query()->where('handle', PKG)->firstOrFail();
    $receipt->version = '0.0.1-stale';
    $receipt->save();

    expect(ModuleLifecycle::upgrade(app(), PKG))->toContain('Upgraded');

    expect(Module::query()->where('handle', PKG)->value('version'))
        ->toBe(ModuleDiscovery::version(PKG));

    expect(ModuleLifecycle::upgrade(app(), PKG))->toContain('already at');
});

/** The command is the surface an operator touches, so it is driven rather than assumed. */
it('drives the whole lifecycle through the console command', function (): void {
    $this->artisan('kitsune:module status')->expectsOutputToContain('No modules are installed.')->assertSuccessful();

    $this->artisan('kitsune:module install '.PKG)->assertSuccessful();
    $this->artisan('kitsune:module enable '.PKG)->assertSuccessful();

    expect(Module::isEnabled(PKG))->toBeTrue();

    $this->artisan('kitsune:module disable '.PKG)->assertSuccessful();
    $this->artisan('kitsune:module uninstall '.PKG)->assertSuccessful();

    expect(Module::query()->where('handle', PKG)->exists())->toBeFalse();
});

it('fails the command with the reason rather than a bare failure', function (): void {
    $this->artisan('kitsune:module enable '.PKG)
        ->expectsOutputToContain('is not installed')
        ->assertFailed();

    $this->artisan('kitsune:module wobble '.PKG)
        ->expectsOutputToContain('is not a module action')
        ->assertFailed();

    $this->artisan('kitsune:module install')
        ->expectsOutputToContain('needs a package')
        ->assertFailed();
});

/**
 * ⚠️ A MODULE'S SCHEMA ARRIVES THROUGH INSTALL AND LEAVES THROUGH UNINSTALL, not through `artisan migrate`.
 * These two assertions are the reason the fixture package ships a real migration: the `Migrator` calls are
 * the part of this lifecycle most likely to be wrong, and a fixture without migrations would have let them
 * ship untested.
 */
it('runs the module migrations on install and rolls them back on uninstall', function (): void {
    expect(Schema::hasTable('fixture_things'))->toBeFalse();

    ModuleLifecycle::install(app(), PKG);

    expect(Schema::hasTable('fixture_things'))->toBeTrue();

    ModuleLifecycle::uninstall(app(), PKG);

    expect(Schema::hasTable('fixture_things'))->toBeFalse();
});

/**
 * ⚠️ THE MODULE REFUSES, AND NOTHING IS DESTROYED FIRST. Core cannot know what a module's content is, so
 * uninstall asks and the module throws — before a single migration is rolled back. The table surviving is
 * the assertion that matters: a refusal that arrived after the rollback would be worthless.
 */
it('lets a module refuse to be uninstalled while it holds content', function (): void {
    ModuleLifecycle::install(app(), PKG);

    DB::table('fixture_things')->insert(['name' => 'still here']);

    expect(fn () => ModuleLifecycle::uninstall(app(), PKG))
        ->toThrow(RuntimeException::class, 'still holds things');

    expect(Schema::hasTable('fixture_things'))->toBeTrue()
        ->and(DB::table('fixture_things')->count())->toBe(1)
        ->and(Module::query()->where('handle', PKG)->exists())->toBeTrue();

    /* And once the content is gone, the same uninstall succeeds. */
    DB::table('fixture_things')->delete();

    expect(ModuleLifecycle::uninstall(app(), PKG))->toContain('Uninstalled');
    expect(Schema::hasTable('fixture_things'))->toBeFalse();
});
