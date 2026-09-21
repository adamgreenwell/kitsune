<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Fixture\Module;

use Kitsune\Core\Modules\ModuleServiceProvider;

/**
 * A module provider that records being registered and booted, so a test can assert the kernel reached it
 * rather than assert that the kernel thinks it did.
 */
final class FixtureModuleServiceProvider extends ModuleServiceProvider
{
    /** @var list<string> */
    public static array $calls = [];

    protected function registerModule(): void
    {
        self::$calls[] = 'register';
    }

    protected function bootModule(): void
    {
        self::$calls[] = 'boot';
    }
}
