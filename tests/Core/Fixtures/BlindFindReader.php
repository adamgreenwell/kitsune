<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use App\Models\Reader;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;

/**
 * The skeleton's reader, with a lookup that misses its first few calls — as a host's would that ran a moment before
 * another request, or an import, wrote the same address — and finds the row once it is there. The insert after a miss
 * then meets the table's unique index, and the look again after it finds the account.
 */
#[OrgScoped]
class BlindFindReader extends Reader
{
    /** How many lookups still miss. */
    public static int $misses = 0;

    public static function findByEmail(#[\SensitiveParameter] string $email): ?static
    {
        if (self::$misses > 0) {
            self::$misses--;

            return null;
        }

        return parent::findByEmail($email);
    }
}
