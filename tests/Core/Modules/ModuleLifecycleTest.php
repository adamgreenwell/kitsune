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
    FixtureModuleServiceProvider::$installFailsAfterWriting = false;
    /* Reset here too: leaked, it would silently switch a later test's migrations off. */
    FixtureModuleServiceProvider::$withoutMigrations = false;
    FixtureModuleServiceProvider::$installIssuesDdl = false;
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

/**
 * ⚠️ THE HALF-DONE INSTALL, which was reachable in v0.2.0 and unrecoverable without hand SQL.
 *
 * `ModuleServiceProvider::install()`'s docblock promised "install's transaction" and `ModuleLifecycle` opened
 * none, so a hook that threw partway left its rows behind with no receipt — after which `install` refused
 * (the module's own idempotency check finds its rows) and `uninstall` refused (no receipt to read), with no
 * way out through the CLI. This asserts the state AFTER the failure, not merely that it threw: a test that
 * stops at the exception passes just as happily without the transaction.
 */
it('leaves nothing behind when a module install hook throws partway', function (): void {
    FixtureModuleServiceProvider::$installFailsAfterWriting = true;

    /*
     * ⚠️ NO DDL IN THIS TEST, and that is what makes the assertion possible on all four engines rather than
     * two. With the module's migrations on, `CREATE TABLE` implicitly commits RefreshDatabase's wrapping
     * transaction on MySQL and MariaDB while Laravel's counter keeps counting it — so `DB::transaction()`
     * emitted `SAVEPOINT trans2` against a connection holding no transaction and the rollback raised
     * SQLSTATE 1305 instead of the module's refusal. Measured: passed on sqlite and pgsql, failed on both.
     *
     * The property under test is engine-independent; only the instrument was broken. That migrations run
     * outside the transaction and survive it is asserted separately, by the migrate/rollback test below,
     * which does not depend on a rollback.
     */
    FixtureModuleServiceProvider::$withoutMigrations = true;

    expect(fn () => ModuleLifecycle::install(app(), PKG))
        ->toThrow(RuntimeException::class, 'could not finish installing');

    expect(DB::table('fixture_module_seeds')->count())->toBe(0)
        ->and(Module::query()->where('handle', PKG)->exists())->toBeFalse();
});

/** The point of rolling the hook back: install is recoverable by running it again, with no hand cleanup. */
it('installs cleanly on a second attempt after a failed one', function (): void {
    FixtureModuleServiceProvider::$installFailsAfterWriting = true;
    FixtureModuleServiceProvider::$withoutMigrations = true;

    /*
     * ⚠️ THE FIRST FAILURE IS ASSERTED, not swallowed — and it was swallowed by a bare
     * `catch (RuntimeException)` for a round.
     *
     * Every early exit in `install()` is a bare `RuntimeException`: the manifest read, the Composer version
     * lookup, the verifier, the migrator. So a catch with no message check made this test green whenever the
     * first install failed for a reason that never reached the hook — the second install would then succeed
     * on a clean database and the test would report that recovery works on a run where no half-done install
     * was ever created. It also swallowed nothing on MySQL, where the real failure arrived as a PDOException
     * and escaped the catch entirely.
     */
    expect(fn () => ModuleLifecycle::install(app(), PKG))
        ->toThrow(RuntimeException::class, 'could not finish installing');

    /* The starting state this test needs: the hook ran, wrote, threw, and left nothing. */
    expect(DB::table('fixture_module_seeds')->count())->toBe(0)
        ->and(Module::query()->where('handle', PKG)->exists())->toBeFalse();

    FixtureModuleServiceProvider::$installFailsAfterWriting = false;

    expect(ModuleLifecycle::install(app(), PKG))->toContain('not enabled yet')
        ->and(Module::query()->where('handle', PKG)->exists())->toBeTrue();
});

/**
 * ⚠️ THE "NO DDL IN install()" RULE IS ASKED OF THE ENGINE, not asserted in a docblock.
 *
 * That rule was published as "a requirement on this hook and not a preference" and enforced by nothing —
 * which is the invariant-14 defect this whole change set exists to fix, committed again one docblock along.
 * A hook issuing DDL implicitly commits install's transaction on MySQL and MariaDB, so the receipt lands and
 * Laravel's `commit()` then throws on a connection holding no transaction: install reports failure for a
 * module that is fully installed, and the retry refuses with "already installed".
 *
 * This runs on every engine and asserts only what is true everywhere — that the refusal names DDL when the
 * transaction did not survive the hook. On SQLite and PostgreSQL DDL is transactional, so the transaction
 * DOES survive and there is nothing to refuse; the check is deliberately silent there, and this test says so
 * rather than pretending the guard is engine-independent.
 */
it('refuses an install whose hook issued DDL, where the engine cannot roll it back', function (): void {
    FixtureModuleServiceProvider::$withoutMigrations = true;
    FixtureModuleServiceProvider::$installIssuesDdl = true;

    $transactionalDdl = in_array(DB::connection()->getDriverName(), ['sqlite', 'pgsql'], true);

    if ($transactionalDdl) {
        /* The hook's DDL is inside the transaction here, so install completes and the receipt is written. */
        expect(ModuleLifecycle::install(app(), PKG))->toContain('not enabled yet');

        return;
    }

    expect(fn () => ModuleLifecycle::install(app(), PKG))
        ->toThrow(RuntimeException::class, 'issued DDL');

    /* And it says so rather than leaving the operator with a commit error from inside the framework. */
    expect(Module::query()->where('handle', PKG)->exists())->toBeTrue();

    /*
     * ⚠️ DROPPED HERE, NOT IN `beforeEach`. A `dropIfExists` in the shared setup is itself DDL, so it
     * committed RefreshDatabase's transaction before EVERY test in this file and took the two rollback
     * assertions down with it on both MySQL engines — the same trap this test is about, one level up.
     * The hook drops before it creates, so a lingering table from a failed run cannot poison the next one.
     */
    Schema::dropIfExists('fixture_module_ddl');
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
