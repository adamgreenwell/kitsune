<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Modules;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Event;
use Kitsune\Core\Events\Modules\ModuleDisabled;
use Kitsune\Core\Events\Modules\ModuleEnabled;
use Kitsune\Core\Models\Module;
use RuntimeException;

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
        $provider->install();

        Module::create([
            'handle' => $handle,
            'version' => $version,
            /* Off. Install is not "run this code"; `kitsune:module enable` is. */
            'is_enabled' => false,
            'installed_at' => now(),
        ]);

        return "Installed {$handle} {$version}. It is not enabled yet — run `kitsune:module enable {$handle}`.";
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

        /* The module's own refusal, before anything is destroyed. It knows what its content is; core does not. */
        $provider->uninstall();

        $path = $provider->migrationPath();

        if ($path !== null) {
            self::migrator($app)->reset([$path]);
        }

        $receipt->delete();

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
