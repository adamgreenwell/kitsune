<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests;

/** The optional module's real schema, migrated before RefreshDatabase opens its engine transaction. */
abstract class CommerceTestCase extends TestCase
{
    protected static function fixtureMigrations(): string
    {
        return __DIR__.'/../Commerce/migrations';
    }
}
