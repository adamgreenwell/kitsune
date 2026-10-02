<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Kitsune\Core\Tenancy\Attributes\OrgScopedThroughPivot;

/**
 * `FirstOwnerUser` as a host with Kitsune's panel bound sees it: a `FilamentUser` too, which the panel then asks.
 *
 * ⚠️ THE SCOPE ATTRIBUTE IS DECLARED AGAIN, for the reason `FirstOwnerUser` gives; the switches are the trait's, shared.
 */
#[OrgScopedThroughPivot(table: 'org_user', foreignKey: 'user_id')]
class FirstOwnerPanelUser extends FirstOwnerUser implements FilamentUser
{
    public function canAccessPanel(Panel $panel): bool
    {
        return static::$break !== 'panel';
    }
}
