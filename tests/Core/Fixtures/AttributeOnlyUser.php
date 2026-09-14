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

/**
 * Declares the membership scope and never applies it — the attribute without `use EnforcesScope`.
 *
 * ⚠️ HALF-CONFIGURED ON PURPOSE. The attribute is inert until the trait reads it, so a guard that looked for the
 * attribute would pass this model while its query stayed unscoped. It is the fixture that tells "asks whether a
 * scope is registered" apart from "asks whether one was declared".
 */
#[OrgScopedThroughPivot(table: 'org_user', foreignKey: 'user_id')]
class AttributeOnlyUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}
