<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Modules;

use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * The base every module's service provider extends — ADR-038.
 *
 * ⚠️ `register()` AND `boot()` ARE FINAL, AND THAT IS THE WHOLE DESIGN. `register()` asks the kernel whether
 * it is the one registering this provider, and throws otherwise. So a module that arrives any other way — a
 * host calling `$app->register()` by hand, a package Composer discovered, anything that skipped the receipt
 * and the scoping check — fails loudly at boot instead of running unrecorded. A module fills `registerModule()`
 * and `bootModule()` instead, which is the same surface minus the door.
 *
 * ⚠️ THE FLAG CANNOT BE ARMED BY A MODULE. `ModuleKernel::isRegistering()` reads a private static set that only
 * `ModuleKernel`'s own private registration path writes, inside a `try`/`finally` around one `$app->register()`
 * call. There is no setter, and the opener is not a method a module can reach — the same shape as `Role`'s
 * write proof and `FieldStorage::shapeGuardedFor()`. A boolean any caller could set would be the forgeable
 * proof this project has already shipped and fixed twice (GHSA-7433-7mg3-722g).
 *
 * `migrationPath()` keeps a module's migrations declared beside the migrations rather than in the manifest, and
 * the kernel is the only thing that runs them: `php artisan migrate` deliberately does not see them, because a
 * module's schema arrives through install and leaves through uninstall.
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    final public function register(): void
    {
        if (! ModuleKernel::isRegistering(static::class)) {
            throw new RuntimeException(sprintf(
                '%s was registered outside the module kernel. A module is registered by the kernel, which has '
                .'checked that it is installed, enabled and that its models scope the way its manifest says — '
                .'or it is not registered at all. Run `kitsune:module install` rather than registering it by '
                .'hand or through `extra.laravel.providers`.',
                static::class,
            ));
        }

        $this->registerModule();
    }

    final public function boot(): void
    {
        $this->bootModule();
    }

    /** Bindings. Runs inside the kernel's registration, before anything has booted. */
    protected function registerModule(): void {}

    /** Everything else. Runs when Laravel boots the provider. */
    protected function bootModule(): void {}

    /** Where this module's migrations live, or null when it has none. */
    public function migrationPath(): ?string
    {
        return null;
    }
}
