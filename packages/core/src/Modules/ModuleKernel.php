<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Modules;

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

/**
 * Registers the modules this installation has a receipt for — ADR-038.
 *
 * @internal Not an extension point. The kernel is core's own machinery, and Standing Principle #2 freezes the
 * extension API at v1.2 — everything still reachable then is a permanent obligation, so this is reachable by
 * nothing outside core.
 *
 * ⚠️ IT RUNS AT APPLICATION BOOTING, NEVER DURING PROVIDER REGISTRATION. Core's register() schedules the
 * callback without reading the database. When it fires every normal provider has registered its bindings,
 * and enabled modules join the provider list before Laravel starts booting it. Filament consumes module
 * resources during its own boot, so registering them after that walk is too late (ADR-038, amended).
 *
 * ⚠️ AND A DATABASE THAT CANNOT ANSWER IS A DECLINE, NOT A CRASH — corrected by running it. A missing `modules`
 * table means "nothing installed yet" and is a silent skip. An unreachable database was originally NOT caught,
 * on the argument that swallowing it would be the fail-open reading of a fail-closed house; that argument is
 * right about a web request and wrong about the moment that decides whether Kitsune can be installed at all.
 * `composer skeleton:install` runs `artisan package:discover` before it writes `.env`, so the application
 * boots with no database and the uncaught throw made a fresh install fail at the step that discovers Kitsune.
 * It is logged and declined now, and ADR-038 is amended rather than quietly contradicted (AGENTS.md §12).
 *
 * ⚠️ AND A RECEIPT IS NOT A LICENCE TO RUN ANYTHING. Each enabled receipt is re-checked against what is on disk
 * before its provider is registered: the package must still be installed, its manifest must still parse, its
 * recorded version must still match what Composer reports, and its provider must actually be a
 * `ModuleServiceProvider`. A receipt is a record of a decision, not a substitute for the conditions that
 * decision rested on.
 */
final class ModuleKernel
{
    /**
     * Providers the kernel is registering right now.
     *
     * Private, with no setter, written only inside `registerFor()`'s `try`/`finally`. `ModuleServiceProvider`
     * asks this and refuses to register when the answer is no, so a module cannot arrange its own registration
     * — the same unforgeable shape as `Role`'s write proof.
     *
     * @var array<class-string, true>
     */
    private static array $registering = [];

    public static function boot(Application $app): void
    {
        // An enabled development provider may already require the schema that upgrade is about to create.
        // Only the canonical fresh-process lifecycle command is cold; ordinary runtime boot stays unchanged.
        $console = $app->runningInConsole();

        if ($console && self::isLifecycleCommand()) {
            return;
        }

        foreach (self::receipts() as $handle => $receipt) {
            if ($console) {
                self::maintenanceFor($handle, $app);
            }
            if (! $receipt['enabled']) {
                continue;
            }
            $manifest = self::manifestFor($handle, $receipt['version']);

            if ($manifest !== null) {
                self::registerFor($manifest, $app);
            }
        }
    }

    /** Recognize the actual CLI command, never a package/action/option value containing its name. */
    private static function isLifecycleCommand(): bool
    {
        $definition = (new ConsoleApplication)->getDefinition();
        $definition->addOption(new InputOption('--env', null, InputOption::VALUE_OPTIONAL));
        $definition->addArgument(new InputArgument('arguments', InputArgument::IS_ARRAY));

        try {
            $input = new ArgvInput($_SERVER['argv'] ?? [], $definition);

            return $input->getArgument('command') === 'kitsune:module';
        } catch (Throwable) {
            // Malformed global options are not a supported cold entry point. Artisan reports them normally.
            return false;
        }
    }

    public static function isRegistering(string $provider): bool
    {
        return isset(self::$registering[$provider]);
    }

    /** Test seam. A registration in flight is not a thing production ever needs to forget. */
    public static function flush(): void
    {
        self::$registering = [];
    }

    /**
     * Installed receipts, read from the primary. Disablement removes runtime, not retained personal data.
     *
     * @return array<string, array{version:string,enabled:bool}>
     */
    private static function receipts(): array
    {
        try {
            if (! Schema::hasTable('modules')) {
                return [];
            }

            // This installation-wide bootstrap precedes DatabaseServiceProvider::boot(), which attaches
            // Eloquent's connection resolver. The database manager is already bound during registration.
            // Modules are genuinely global; this read introduces no Org/Site scope bypass.
            $receipts = [];
            foreach (DB::table('modules')->useWritePdo()
                ->leftJoin('module_uninstalls', 'module_uninstalls.handle', '=', 'modules.handle')
                ->whereNull('module_uninstalls.handle')
                ->orderBy('modules.handle')
                ->get(['modules.handle', 'modules.version', 'modules.is_enabled']) as $row) {
                if (! is_string($row->handle) || ! is_string($row->version)
                    || ! in_array($row->is_enabled, [true, false, 0, 1, '0', '1'], true)) {
                    throw new \RuntimeException('An installed module receipt is malformed.');
                }
                $receipts[$row->handle] = ['version' => $row->version, 'enabled' => (bool) $row->is_enabled];
            }

            return $receipts;
        } catch (Throwable $e) {
            /*
             * ⚠️ THIS WAS "NOT A TRY/CATCH" UNTIL RUNNING IT PROVED OTHERWISE, and ADR-038 is amended rather
             * than routed around. The reasoning was that a missing table is an answer and a broken connection
             * is a failure that must not be swallowed. It holds for a web request and is wrong about the
             * moment that matters most: `composer skeleton:install` runs `artisan package:discover` BEFORE it
             * writes `.env`, so the application boots with no database at all, `Schema::hasTable()` throws, and
             * a fresh install of Kitsune fails at the step that discovers Kitsune. Measured — the install
             * aborted with this exact frame on the stack.
             *
             * Declining loudly is the right failure here, and costs less than it looks: an installation whose
             * database is genuinely unreachable fails on its first query whatever this method does, so the
             * kernel is not the component that should decide the application is dead. What it must not do is
             * be the reason a working installation cannot be created.
             */
            Log::warning('Kitsune could not read the modules table, so no module was registered: '.$e->getMessage());

            return [];
        }
    }

    /** The manifest of a module that may be registered, or null with the reason logged. */
    private static function manifestFor(string $handle, string $recorded): ?ModuleManifest
    {
        $installed = ModuleDiscovery::version($handle);

        if ($installed === null) {
            return self::decline($handle, 'it is enabled but no longer installed by Composer');
        }

        /*
         * ⚠️ A STALE RECEIPT IS REFUSED RATHER THAN TRUSTED, and this is the check that makes a forgotten
         * upgrade loud instead of letting new code run against a schema its own migrations have not reached.
         */
        if ($installed !== $recorded) {
            return self::decline($handle, sprintf(
                'the receipt records %s and Composer reports %s. Run `kitsune:module upgrade %s`',
                $recorded,
                $installed,
                $handle,
            ));
        }

        $read = ModuleDiscovery::read($handle);

        return $read instanceof ModuleManifest ? $read : self::decline($handle, $read);
    }

    private static function decline(string $handle, string $reason): null
    {
        /*
         * Logged rather than thrown: one unregistrable module must not take the whole application down, and
         * silence would mean a host discovering the loss from a missing feature. ADR-038 gives the enable and
         * disable events as the only other observability — an installation-level act has no org, so
         * `audit_log.org_id` being NOT NULL means `Auditor::record()` would record nothing.
         */
        Log::warning("Kitsune did not register the module [{$handle}]: {$reason}.");

        return null;
    }

    /** Declaring cold command classes never registers or boots the module's runtime provider. */
    private static function maintenanceFor(string $handle, Application $app): void
    {
        try {
            $manifest = ModuleDiscovery::read($handle);
            if (! $manifest instanceof ModuleManifest) {
                return;
            }
            $provider = new $manifest->provider($app);
            $commands = $provider->maintenanceCommands();
            if (! array_is_list($commands)) {
                throw new \RuntimeException;
            }
            foreach ($commands as $command) {
                if (! is_string($command) || ! is_subclass_of($command, Command::class)) {
                    throw new \RuntimeException;
                }
                $definition = new ReflectionClass($command);
                if (! $definition->isInstantiable() || ($definition->getConstructor()?->getNumberOfRequiredParameters() ?? 0) !== 0) {
                    throw new \RuntimeException;
                }
            }
            if ($commands !== []) {
                $provider->commands($commands);
            }
        } catch (Throwable) {
            Log::warning("Kitsune could not declare retained-data commands for module [{$handle}].");
        }
    }

    private static function registerFor(ModuleManifest $manifest, Application $app): void
    {
        $provider = $manifest->provider;

        self::$registering[$provider] = true;

        try {
            $app->register($provider);
        } finally {
            /* Cleared however the registration ended, so a provider that threw cannot leave the door open. */
            unset(self::$registering[$provider]);
        }
    }
}
