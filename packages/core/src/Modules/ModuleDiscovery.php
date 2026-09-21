<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Modules;

use Composer\InstalledVersions;

/**
 * What Composer says about a package, read as a module — ADR-038.
 *
 * @internal
 *
 * Discovery is "whatever Composer already installed" (Standing Principle #5: no catalogue, no index, no
 * install-from-URL). This is the one place that turns a package name into a manifest, so the kernel's boot
 * path and the lifecycle command cannot disagree about what a module is — the same argument
 * `ModuleManifest::read()` makes about the grammar, applied one layer out.
 */
final class ModuleDiscovery
{
    /** The manifest for an installed package, or the reason there is not one. */
    public static function read(string $handle): ModuleManifest|string
    {
        if (! InstalledVersions::isInstalled($handle)) {
            return "{$handle} is not installed by Composer. A module is a package: `composer require {$handle}`.";
        }

        $path = self::installPath($handle);
        $composer = $path === null ? false : @file_get_contents($path.'/composer.json');
        $decoded = is_string($composer) ? json_decode($composer, true) : null;

        if (! is_array($decoded)) {
            return "{$handle}'s composer.json could not be read.";
        }

        $refusal = ModuleManifest::refusalFor($decoded, $handle);

        if ($refusal !== null) {
            return $refusal;
        }

        $manifest = ModuleManifest::from($decoded, $handle);

        /*
         * The grammar can say a provider LOOKS like a class name; only the runtime can say it is one, and that
         * it is a module's provider rather than an arbitrary service provider that would never pass the door.
         */
        if (! class_exists($manifest->provider)) {
            return "{$handle}'s provider `{$manifest->provider}` does not exist.";
        }

        if (! is_subclass_of($manifest->provider, ModuleServiceProvider::class)) {
            return "{$handle}'s provider `{$manifest->provider}` is not a ".ModuleServiceProvider::class.'.';
        }

        return $manifest;
    }

    public static function installPath(string $handle): ?string
    {
        if (! InstalledVersions::isInstalled($handle)) {
            return null;
        }

        $path = InstalledVersions::getInstallPath($handle);

        return is_string($path) ? rtrim($path, '/') : null;
    }

    /** What Composer reports as this package's version right now. */
    public static function version(string $handle): ?string
    {
        if (! InstalledVersions::isInstalled($handle)) {
            return null;
        }

        $version = InstalledVersions::getPrettyVersion($handle);

        return is_string($version) ? $version : null;
    }
}
