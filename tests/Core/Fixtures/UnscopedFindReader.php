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
 * The skeleton's reader, with a host's careless `findByEmail()`: it stands the org scope down, so it finds an address
 * in any org — what core's own fence on the row's `org_id` is there for.
 */
#[OrgScoped]
class UnscopedFindReader extends Reader
{
    public static function findByEmail(#[\SensitiveParameter] string $email): ?static
    {
        return static::withoutScopeBecause('a careless host lookup, for the fence test', static fn ($query) => $query->where('email', $email)->first());
    }
}
