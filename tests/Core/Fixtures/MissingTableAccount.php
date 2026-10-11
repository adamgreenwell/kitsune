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
 * The skeleton's reader account on a table that does not exist, so its first query fails on every engine with no DDL
 * inside a test — `MissingTableReader`, with the contract core reads an account through.
 */
#[OrgScoped]
class MissingTableAccount extends Reader
{
    protected $table = 'no_such_readers';
}
