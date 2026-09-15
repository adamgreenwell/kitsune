<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Kitsune\Core\Auth\GuardedOrgMembership;
use Kitsune\Core\Auth\RevokesRoleAssignments;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Tenancy\Attributes\OrgScopedThroughPivot;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;

/**
 * `TestUser` on a host whose users carry ULIDs — #91.
 *
 * ⚠️ DECLARED EXACTLY AS `TestUser` IS, THE KEY ASIDE, because a difference anywhere else would let a ULID test pass
 * or fail for a reason that has nothing to do with the key: the same scope declaration, the same deletion observer
 * and the same guarded membership relation. Only `HasUlids` is new, and it lives on the `UlidHostTestCase` schema.
 */
#[ObservedBy(RevokesRoleAssignments::class)]
#[OrgScopedThroughPivot(table: 'org_user', foreignKey: 'user_id')]
class UlidUser extends Authenticatable
{
    use EnforcesScope;
    use HasUlids;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;

    /** Membership, declared the way the skeleton declares it; see `TestUser::orgs()`. */
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
