<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Kitsune\Core\Tenancy\Attributes\OrgScopedThroughPivot;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;

/**
 * The same `users` table as `TestUser`, deliberately WITHOUT `RevokesRoleAssignments`.
 *
 * ⚠️ A HOST THAT HAS NOT ATTACHED THE OBSERVER IS THE CASE THE FOREIGN KEY EXISTS FOR, and it needs a model
 * to be that host with. Deleting one of these while it holds a role has to fail on the constraint rather than
 * quietly removing the assignment — that is the backstop, and without this fixture nothing asserts it.
 */
#[OrgScopedThroughPivot(table: 'org_user', foreignKey: 'user_id')]
class UnobservedUser extends Authenticatable
{
    use EnforcesScope;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}
