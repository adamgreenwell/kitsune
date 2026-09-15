<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests;

/**
 * A host whose users carry ULIDs — #91.
 *
 * ⚠️ THE SAME APPLICATION WITH A DIFFERENT HOST UNDER IT, which is the whole of what #91 changes. Core's migrations
 * are the same; the host-side tables are not — `users` keyed by ULID, and `org_user` and `role_user` referencing it
 * with `foreignUlid` — so the suite in `tests/UlidHost` runs every assignment path against a schema where casting an
 * identifier to `int` is not a harmless no-op. The base test case rebuilds the database when this set takes over.
 *
 * In `tests/Core` beside `TestCase` so the existing autoload mapping reaches it; its tests live in `tests/UlidHost`,
 * because Pest refuses two test cases on overlapping directories.
 */
abstract class UlidHostTestCase extends TestCase
{
    protected static function fixtureMigrations(): string
    {
        return __DIR__.'/../UlidHost/migrations';
    }
}
