<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Modules;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Event;
use Kitsune\Core\Events\Modules\ModuleDisabled;
use Kitsune\Core\Events\Modules\ModuleEnabled;
use Kitsune\Core\Models\Module;
use RuntimeException;
use Throwable;

/**
 * Install, enable, disable, upgrade and uninstall — ADR-038.
 *
 * @internal
 *
 * Separate from the command so the lifecycle can be tested without a console, and so the console has nothing
 * in it but argument handling. Every refusal is a `RuntimeException` carrying the reason; the command prints
 * it.
 *
 * ⚠️ INSTALL VERIFIES; IT DOES NOT TAKE THE MANIFEST'S WORD. The scoping declaration is checked against the
 * module's actual models here, which is the moment the receipt earns its meaning — the boot path re-checks the
 * manifest and the version cheaply, but it does not re-sweep every model on every request, and the receipt is
 * what stands in for that sweep. A receipt written without verifying would be a proof of nothing.
 *
 * ⚠️ ENABLE IS A SEPARATE ACT FROM INSTALL. Install puts the schema in place and writes a receipt with the
 * switch OFF; enabling is deliberate. That is why `is_enabled` defaults to false, and why an interrupted
 * install cannot leave code running.
 */
final class ModuleLifecycle
{
    public static function install(Application $app, string $handle): string
    {
        if (Module::query()->where('handle', $handle)->exists()) {
            throw new RuntimeException("{$handle} is already installed. `kitsune:module upgrade` moves it to a new version.");
        }

        $manifest = self::manifest($handle);
        $version = self::version($handle);

        self::verify($manifest, $handle);

        $provider = self::provider($app, $manifest);

        self::migrate($app, $provider);

        /*
         * ⚠️ THE HOOK AND THE RECEIPT COMMIT TOGETHER, and until now nothing held them together while
         * `ModuleServiceProvider::install()`'s docblock said otherwise.
         *
         * The sequence was migrate → `install()` → `Module::create()`, unprotected. A hook that threw
         * after creating its first row left the rows behind with NO receipt, and both exits then
         * refused: `install` because the module's own idempotency check finds its type ("A global
         * `person` entry type already exists"), `uninstall` because there is no receipt to read. The
         * only way out was hand SQL. Reachable in v0.2.0, and the promise of a transaction was already
         * published — see AGENTS.md invariant 14 on what a written consequence nothing enforces costs.
         *
         * MIGRATIONS ARE DELIBERATELY OUTSIDE IT. They are DDL, and DDL commits implicitly on MySQL and
         * MariaDB (the same reason `SchemaManager` is not an observer), so a transaction spanning them
         * would be a guarantee on one engine and a fiction on two. Leaving them outside costs nothing
         * that matters: `migrate` records what it ran, so a re-run skips it, and the rolled-back hook
         * re-runs clean against tables that already exist. An interrupted install is therefore repaired
         * by running install again — which was the property it did not have.
         */
        /*
         * ⚠️ THE MODEL'S CONNECTION, NOT THE `DB` FACADE'S. `DB::transaction()` always resolves the DEFAULT
         * connection, while the receipt and everything a module's hook writes go through their models' own —
         * so a module keeping its rows on a second connection would have had them committed while the receipt
         * rolled back, which is exactly the state the docblock promises is impossible. `Site::save()` and
         * `SettingsWriter` were both corrected to `$model->getConnection()->transaction()` for this reason;
         * this is the same correction before the same review round finds it twice.
         */
        $connection = (new Module)->getConnection();

        /*
         * ⚠️ SET INSIDE THE CLOSURE, READ OUTSIDE IT, because the refusal cannot be thrown from within.
         *
         * A hook that issues DDL implicitly commits this transaction on MySQL and MariaDB. Throwing at that
         * point does not reach the caller: Laravel unwinds by rolling back, `PDO::rollBack()` finds no
         * transaction and throws, and that error REPLACES whatever was thrown. Returning normally is no
         * better — `commit()` then calls `PDO::commit()` on the same empty connection and throws there. Either
         * way the operator gets "SAVEPOINT trans2 does not exist" or "There is no active transaction" from
         * inside the framework, with nothing naming the cause. Measured both, on both engines.
         *
         * So the fact is recorded where it can be observed and the refusal is raised where it can be heard,
         * with the framework's error kept as the cause.
         */
        $ddlCommittedTheTransaction = false;

        try {
            self::withinTransaction($connection, function () use ($connection, $provider, $handle, $version, &$ddlCommittedTheTransaction): void {
                /*
                 * ⚠️ BEFORE AND AFTER, NOT JUST AFTER — a bare "is it still open?" blamed the hook for a
                 * transaction that was already closed when it was called, and that is the ordinary case in
                 * the test harness: RefreshDatabase holds the transaction, the module's own migrations run
                 * DDL outside this one and commit it, and the hook then inherits a connection with nothing
                 * open. Measured: the bare check refused all 19 module installs on MySQL and MariaDB.
                 *
                 * A guard may only report what it can distinguish. This one reports a transaction that was
                 * open when the hook was entered and closed when it returned, which is the hook's doing and
                 * nobody else's.
                 */
                $openBeforeTheHook = $connection->getPdo()->inTransaction();

                $provider->install();

                /*
                 * ⚠️ THE ENGINE IS ASKED, rather than the hook being trusted — "no DDL here" was published as
                 * a requirement and enforced by nothing, which is the invariant-14 defect this commit exists
                 * to fix, committed again one docblock along.
                 *
                 * Honest about its reach: SQLite and PostgreSQL have transactional DDL, so a hook doing DDL
                 * there leaves the transaction open and this sees nothing. It fires exactly where the damage
                 * is, which is the standard this project settled on after five rounds of forgeable guard
                 * evidence — ask the database, not the declaration.
                 */
                $ddlCommittedTheTransaction = $openBeforeTheHook && ! $connection->getPdo()->inTransaction();

                Module::create([
                    'handle' => $handle,
                    'version' => $version,
                    /* Off. Install is not "run this code"; `kitsune:module enable` is. */
                    'is_enabled' => false,
                    'installed_at' => now(),
                ]);
            });
        } catch (Throwable $e) {
            if (! $ddlCommittedTheTransaction) {
                throw $e;
            }

            throw new RuntimeException(self::ddlInInstallHook($handle), 0, $e);
        }

        /* Committed without complaint on an engine that tolerated it — still a broken promise, still refused. */
        if ($ddlCommittedTheTransaction) {
            throw new RuntimeException(self::ddlInInstallHook($handle));
        }

        return "Installed {$handle} {$version}. It is not enabled yet — run `kitsune:module enable {$handle}`.";
    }

    /**
     * Run `$work` in a transaction on `$connection`, unless the connection is not actually in one.
     *
     * ⚠️ LARAVEL'S TRANSACTION COUNTER CAN BE WRONG, and a transaction opened while it is wrong is worse
     * than none.
     *
     * DDL implicitly commits the open transaction on MySQL and MariaDB, and Laravel's `$transactions` counter
     * does not know: it keeps counting a transaction the engine has already closed. `transaction()` then takes
     * its nesting branch and emits `SAVEPOINT trans2` against a connection holding nothing — and when the
     * closure throws, `ROLLBACK TO SAVEPOINT trans2` fails with SQLSTATE 1305 and that error REPLACES the
     * exception being unwound. Measured: a module's "still holds things" refusal reached the operator as
     * "SAVEPOINT trans2 does not exist" on both MySQL engines.
     *
     * A module's refusal is the whole contract of `uninstall()`, so losing it is not acceptable, and the state
     * is detectable: Laravel believes it is inside a transaction and the driver says otherwise. There is
     * nothing useful to do about it here — the caller's transaction is already gone, which is not this code's
     * doing and cannot be undone from inside it — so the work runs unprotected, which is exactly what it did
     * before this transaction existed, and the refusal arrives intact.
     *
     * In the ordinary case, and on every engine at the console where transaction level is 0, this opens a real
     * transaction.
     *
     * @param  Closure(): void  $work
     */
    private static function withinTransaction(Connection $connection, Closure $work): void
    {
        $counterIsLying = $connection->transactionLevel() > 0 && ! $connection->getPdo()->inTransaction();

        if ($counterIsLying) {
            $work();

            return;
        }

        $connection->transaction($work);
    }

    /** The one message both DDL exits raise, so the operator reads the same sentence either way. */
    private static function ddlInInstallHook(string $handle): string
    {
        return "{$handle}'s install() hook issued DDL, which implicitly commits install's transaction on "
            .'MySQL and MariaDB. The receipt and whatever the hook wrote are already committed and cannot be '
            .'rolled back, so this installation is half-made: nothing was undone. Schema belongs in '
            .'migrationPath(), which runs before install() and outside the transaction. Inspect the '
            .'installation by hand before retrying.';
    }

    public static function enable(Application $app, string $handle): string
    {
        $receipt = self::receipt($handle);
        $manifest = self::manifest($handle);

        /*
         * Re-verified on the way in, because the switch is the moment the code starts running and the package
         * on disk may have moved since install — a `composer update` does not ask the kernel's permission.
         */
        self::verify($manifest, $handle);

        if ($receipt->version !== self::version($handle)) {
            throw new RuntimeException(sprintf(
                '%s records %s and Composer reports %s. Run `kitsune:module upgrade %s` first.',
                $handle,
                $receipt->version,
                self::version($handle),
                $handle,
            ));
        }

        if ($receipt->is_enabled) {
            return "{$handle} is already enabled.";
        }

        $receipt->is_enabled = true;
        $receipt->save();

        Event::dispatch(new ModuleEnabled($handle));

        return "Enabled {$handle}.";
    }

    public static function disable(Application $app, string $handle): string
    {
        $receipt = self::receipt($handle);

        if (! $receipt->is_enabled) {
            return "{$handle} is already disabled.";
        }

        $receipt->is_enabled = false;
        $receipt->save();

        Event::dispatch(new ModuleDisabled($handle));

        return "Disabled {$handle}. Its schema and data are untouched; `kitsune:module uninstall` removes those.";
    }

    public static function upgrade(Application $app, string $handle): string
    {
        $receipt = self::receipt($handle);
        $manifest = self::manifest($handle);
        $version = self::version($handle);

        if ($receipt->version === $version) {
            return "{$handle} is already at {$version}.";
        }

        /* The new code is what gets checked, not the code the receipt was written against. */
        self::verify($manifest, $handle);

        self::migrate($app, self::provider($app, $manifest));

        $was = $receipt->version;
        $receipt->version = $version;
        $receipt->save();

        return "Upgraded {$handle} from {$was} to {$version}.";
    }

    public static function uninstall(Application $app, string $handle): string
    {
        $receipt = self::receipt($handle);

        /*
         * ⚠️ DISABLE FIRST, DELIBERATELY. Uninstalling a running module would roll its schema out from under
         * code that is registered and serving requests in this very process.
         */
        if ($receipt->is_enabled) {
            throw new RuntimeException("{$handle} is enabled. Run `kitsune:module disable {$handle}` first.");
        }

        $manifest = self::manifest($handle);
        $provider = self::provider($app, $manifest);

        /*
         * ⚠️ THE MODULE'S TEARDOWN AND THE RECEIPT COMMIT TOGETHER, for the reason install's do — and this
         * direction destroys data, so the half-done state is worse here.
         *
         * The sequence was `uninstall()` → schema reset → receipt delete, all unprotected, with the receipt
         * LAST. A throw between the first and the third left the module's content deleted and committed, its
         * schema partly rolled back, and a receipt still claiming an installed module. Re-running uninstall
         * then called the module's hook again against dropped tables — which raises a driver error rather
         * than the `RuntimeException` the console is built to print — while install refused with "already
         * installed". The same CLI dead end install just lost, in the direction that cannot be undone.
         *
         * Concretely reachable: the new `FieldStorage` cascade refusal means `kitsune/person`'s uninstall can
         * throw at its storage delete, after it has already removed its own `fields` rows, whenever another
         * type's field points at one of person's global storage rows — which `EntryType::guardStorageOwnership()`
         * permits by design.
         */
        $connection = (new Module)->getConnection();

        self::withinTransaction($connection, function () use ($provider, $receipt): void {
            /* The module's own refusal, before anything is destroyed. It knows its content; core does not. */
            $provider->uninstall();

            $receipt->delete();
        });

        /*
         * ⚠️ THE SCHEMA GOES LAST AND OUTSIDE, which is the opposite order from before and the only one that
         * is recoverable.
         *
         * `reset()` is DDL, so it cannot join the transaction above without implicitly committing it on MySQL
         * and MariaDB — the same trade install makes in the other direction. Running it after means a failure
         * there leaves tables behind with NO receipt and no data, which `kitsune:module install` can walk
         * back into: the migrator records what it rolled back as it goes, so a re-install re-runs whatever
         * survived. Running it BEFORE the receipt delete, as this did, left the receipt instead — and a
         * receipt for a module whose tables are gone is the state nothing can recover from.
         */
        $path = $provider->migrationPath();

        if ($path !== null) {
            self::migrator($app)->reset([$path]);
        }

        return "Uninstalled {$handle}.";
    }

    /** @return list<array{handle: string, version: string, enabled: bool, status: string}> */
    public static function status(): array
    {
        $rows = [];

        foreach (Module::query()->orderBy('handle')->get() as $receipt) {
            $installed = ModuleDiscovery::version($receipt->handle);

            $rows[] = [
                'handle' => (string) $receipt->handle,
                'version' => (string) $receipt->version,
                'enabled' => (bool) $receipt->is_enabled,
                'status' => match (true) {
                    $installed === null => 'not installed by Composer',
                    $installed !== $receipt->version => "stale: Composer reports {$installed}",
                    default => 'ok',
                },
            ];
        }

        return $rows;
    }

    private static function receipt(string $handle): Module
    {
        $receipt = Module::query()->where('handle', $handle)->first();

        if (! $receipt instanceof Module) {
            throw new RuntimeException("{$handle} is not installed. Run `kitsune:module install {$handle}`.");
        }

        return $receipt;
    }

    private static function manifest(string $handle): ModuleManifest
    {
        $read = ModuleDiscovery::read($handle);

        if (! $read instanceof ModuleManifest) {
            throw new RuntimeException($read);
        }

        return $read;
    }

    private static function version(string $handle): string
    {
        $version = ModuleDiscovery::version($handle);

        if ($version === null) {
            throw new RuntimeException("{$handle} is not installed by Composer.");
        }

        return $version;
    }

    private static function verify(ModuleManifest $manifest, string $handle): void
    {
        $path = ModuleDiscovery::installPath($handle);

        if ($path === null) {
            throw new RuntimeException("{$handle} has no install path.");
        }

        $verification = ModuleVerifier::verify($manifest, $path);

        if (! $verification->passed()) {
            throw new RuntimeException((string) $verification->refusal);
        }
    }

    private static function provider(Application $app, ModuleManifest $manifest): ModuleServiceProvider
    {
        $class = $manifest->provider;

        /*
         * Constructed, never registered. Construction is not the guarded act — `register()` is, and it stays
         * the kernel's alone. The lifecycle needs the provider only to ask it where its migrations are and to
         * run its install and uninstall hooks.
         */
        $provider = new $class($app);

        if (! $provider instanceof ModuleServiceProvider) {
            throw new RuntimeException("{$manifest->package}'s provider is not a ".ModuleServiceProvider::class.'.');
        }

        return $provider;
    }

    private static function migrate(Application $app, ModuleServiceProvider $provider): void
    {
        $path = $provider->migrationPath();

        if ($path === null) {
            return;
        }

        $migrator = self::migrator($app);

        if (! $migrator->repositoryExists()) {
            $migrator->getRepository()->createRepository();
        }

        $migrator->run([$path]);
    }

    private static function migrator(Application $app): Migrator
    {
        return $app->make('migrator');
    }
}
