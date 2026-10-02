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

/** `FirstOwnerUser` on a host whose users carry ULIDs (#91); see that class for why the scope is declared again. */
#[OrgScopedThroughPivot(table: 'org_user', foreignKey: 'user_id')]
class FirstOwnerUlidUser extends UlidUser implements HasTenants, ProvisionsMembership
{
    use ProvisionsLikeTheSkeleton;
}
