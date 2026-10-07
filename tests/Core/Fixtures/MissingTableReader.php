<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Kitsune\Core\Tenancy\Attributes\OrgScoped;

/**
 * A reader model whose table does not exist, so its first query fails on every engine with no DDL inside a test —
 * the database error a door must map without the reader's identifier in it.
 */
#[OrgScoped]
class MissingTableReader extends TestReader
{
    protected $table = 'no_such_readers';
}
