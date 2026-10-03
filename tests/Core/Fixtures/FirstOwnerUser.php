<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Filament\Models\Contracts\HasTenants;
use Kitsune\Core\Auth\Contracts\ProvisionsMembership;
use Kitsune\Core\Tenancy\Attributes\OrgScopedThroughPivot;

/**
 * A host user model the first owner can be created in — `TestUser`, with `ProvisionsMembership` and the panel's tenancy.
 *
 * ⚠️ THE SCOPE ATTRIBUTE IS DECLARED AGAIN, AND THE OBSERVER IS NOT. `ScopeResolver` reads only a class's own
 * attributes, so without it this model would be unscoped and every membership answer "yes"; Laravel merges a parent's
 * `#[ObservedBy]` for a subclass, so repeating it would register `RevokesRoleAssignments` twice.
 */
#[OrgScopedThroughPivot(table: 'org_user', foreignKey: 'user_id')]
class FirstOwnerUser extends TestUser implements HasTenants, ProvisionsMembership
{
    use ProvisionsLikeTheSkeleton;
}
