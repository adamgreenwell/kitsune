<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;

/** A reader whose keys are strings — ULIDs, or whatever a host chooses: an SSO subject, `auth0|abc123`. */
#[OrgScoped]
class TestUlidReader extends Authenticatable
{
    use EnforcesScope;
    use HasUlids;

    protected $table = 'test_ulid_readers';

    protected $guarded = [];

    public $timestamps = false;
}
