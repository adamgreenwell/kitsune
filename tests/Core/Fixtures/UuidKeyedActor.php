<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * An actor whose identifier is not an integer — a UUID-keyed users table, or an LDAP subject.
 *
 * ⚠️ NAMED RATHER THAN ANONYMOUS, AND THAT IS THE WHOLE REASON THIS CLASS EXISTS. The test that needs it used
 * `new class extends AuthUser`, and PHP builds an anonymous class's name from the DEFINING FILE PATH with a NUL
 * byte in front of it: `Illuminate\Foundation\Auth\User@anonymous\0/long/path/to/Test.php:271$1b8`. The audit
 * log records `actor_type` as that name, in a `varchar(255)`, and the three engines then disagree:
 *
 * - **MySQL and MariaDB** send the whole string and fail the INSERT with `SQLSTATE[22001] … Data too long`,
 *   but only when the checkout path is long enough to push it past 255. Measured at a 179-character worktree
 *   path: 262 bytes, refused. On CI's 33-character path the same test passes.
 * - **PostgreSQL** truncates at the NUL byte, storing `Illuminate\Foundation\Auth\User@anonymous` — 41
 *   characters, and no record of WHICH anonymous class it was.
 * - **SQLite** stores the whole thing.
 *
 * So the anonymous class made a test that passes or fails depending on where somebody checked the repository
 * out, and recorded a different thing on each engine while doing it. A named actor is the same test without
 * the path in it.
 */
class UuidKeyedActor extends Authenticatable
{
    public const IDENTIFIER = '018f2b7c-1d6a-7e3f-9a0b-5c8d4e2f1a33';

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;

    public function getAuthIdentifier(): string
    {
        return self::IDENTIFIER;
    }
}
