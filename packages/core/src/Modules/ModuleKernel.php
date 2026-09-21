<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Modules;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Kitsune\Core\Models\Module;

/**
 * Registers the modules this installation has a receipt for — ADR-038.
 *
 * @internal Not an extension point. The kernel is core's own machinery, and Standing Principle #2 freezes the
 * extension API at v1.2 — everything still reachable then is a permanent obligation, so this is reachable by
 * nothing outside core.
 *
 * ⚠️ IT RUNS IN `boot()`, NEVER IN `register()`. Core touches no database in `register()` today, and a registry
 * read there turns a transient connection failure into an admin that has quietly lost every module's features
 * — while telling that apart from a fresh install with no table breaks the install path. ADR-038 records the
 * decision; this is where it is kept.
 *
 * ⚠️ AND THE TWO ABSENCES ARE DIFFERENT. A missing `modules` table means "nothing installed yet" — a fresh
 * checkout, a bare clone, the moment before the first migrate — and is a silent skip. A connection that fails
 * is NOT caught: it is a real failure, and swallowing it would turn a broken database into an application that
 * merely behaves as though no module were enabled, which is the fail-open reading of a fail-closed house.
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
        foreach (self::receipts() as $handle => $version) {
            $manifest = self::manifestFor($handle, $version);

            if ($manifest !== null) {
                self::registerFor($manifest, $app);
            }
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
     * The enabled receipts, handle => recorded version.
     *
     * @return array<string, string>
     */
    private static function receipts(): array
    {
        /* Not a try/catch: a missing table is this question's answer, and a broken connection is not. */
        if (! Schema::hasTable('modules')) {
            return [];
        }

        return Module::query()
            ->where('is_enabled', true)
            ->pluck('version', 'handle')
            ->all();
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
