<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Kitsune\Core\Tenancy\Attributes\OrgScopedThroughPivot;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;

/**
 * A SECOND authenticatable model, on its own table, for the one question `TestUser` cannot ask.
 *
 * ⚠️ THIS EXISTS BECAUSE A NUMERIC ID IS NOT AN IDENTITY. A host running two panels authenticates through
 * two providers, which means two user models on two tables with two independent sequences — so both have a
 * user 1, and `role_user` can only be about one of them. Review found `Permissions` resolving assignments by
 * `user_id` alone, so the second model's user 1 was handed the first's roles.
 *
 * It reuses `pivot_scoped_things` and its org pivot rather than adding a migration, because what the test
 * needs is a model whose TABLE is not the one `role_user` references — the rest of the shape is incidental.
 * Membership is declared the same way the skeleton's `User` declares it, so the guard sees a real member
 * rather than a stranger it would have refused anyway.
 */
#[OrgScopedThroughPivot(table: 'pivot_scoped_thing_org', foreignKey: 'pivot_scoped_thing_id')]
class TestImpostor extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use Authorizable;
    use EnforcesScope;

    protected $table = 'pivot_scoped_things';

    protected $guarded = [];

    public $timestamps = false;
}
