<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;

/**
 * A reader as a host declares one — ADR-037: its own guard and table, `#[OrgScoped]` on its own `org_id`, integer keys.
 *
 * ⚠️ "READER", NEVER "USER": a user is staff, and a reader is never a panel user. Named `TestReader` rather than
 * `Reader`, which is the skeleton's own (`App\Models\Reader`).
 */
#[OrgScoped]
class TestReader extends Authenticatable
{
    use EnforcesScope;

    protected $table = 'test_readers';

    protected $guarded = [];

    public $timestamps = false;
}
