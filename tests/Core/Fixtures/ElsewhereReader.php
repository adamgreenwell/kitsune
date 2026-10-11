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
 * The skeleton's reader on a connection of its own — a host whose readers live in another database. The test that
 * declares it says what that connection is, so it can be the same database or one with no `readers` table at all.
 */
#[OrgScoped]
class ElsewhereReader extends Reader
{
    public const CONNECTION = 'elsewhere_readers';

    protected $connection = self::CONNECTION;
}
