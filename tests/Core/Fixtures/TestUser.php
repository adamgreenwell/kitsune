<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Kitsune\Core\Auth\GuardedOrgMembership;
use Kitsune\Core\Auth\RevokesRoleAssignments;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Tenancy\Attributes\OrgScopedThroughPivot;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;

/**
 * Stands in for the host application's user model — ADR-033.
 *
 * Core does not own a user model and must not, so the RBAC tests need something authenticatable to assign
 * roles to. `Permissions` takes the `Authenticatable` contract rather than a class for the same reason.
 *
 * ⚠️ IT CARRIES THE SKELETON'S SCOPE DECLARATION, VERBATIM, and that is what makes the isolation tests
 * mean anything. `Permissions` asks membership by querying the user model's OWN scoped query, so a fixture
 * with no scope would answer "member" for every org and the cross-org assertions would pass against
 * nothing. Membership is many-to-many, which is the one shape `OrgScope` cannot express (issue #21).
 *
 * ⚠️ AND THE SKELETON'S DELETION BEHAVIOUR TOO, for the same reason: `role_user` cascades from the host's
 * users table, so a fixture without the `RevokesRoleAssignments` observer would let a deletion remove
 * assignments silently and the test asserting otherwise would be asserting about a model nothing ships.
 */
#[ObservedBy(RevokesRoleAssignments::class)]
#[OrgScopedThroughPivot(table: 'org_user', foreignKey: 'user_id')]
class TestUser extends Authenticatable
{
    use EnforcesScope;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;

    /**
     * Membership, declared the way the skeleton declares it.
     *
     * ⚠️ GUARDED, AND THAT IS THE POINT OF HAVING IT HERE. Removing the last owner's membership locks an
     * organisation out exactly as removing their role does, and a plain `detach()` fires no event — so a
     * fixture with an unguarded relation would let the test assert about a model nothing ships.
     */
    public function orgs(): BelongsToMany
    {
        return new GuardedOrgMembership(
            Org::query(),
            $this,
            'org_user',
            'user_id',
            'org_id',
            $this->getKeyName(),
            (new Org)->getKeyName(),
            __FUNCTION__,
        );
    }
}
