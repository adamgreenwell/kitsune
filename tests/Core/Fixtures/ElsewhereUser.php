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
use Kitsune\Core\Tenancy\Attributes\OrgScopedThroughPivot;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;

/**
 * A user model on the SAME table name and a DIFFERENT connection.
 *
 * ⚠️ THE ONE DIFFERENCE IS THE CONNECTION, which is what makes it a fixture rather than a decoration:
 * `role_user` and the foreign key that gives its `user_id` meaning live on the default connection, so a model
 * reading `users` from an identity database somewhere else is not what those assignments are about — however
 * much the table names agree. Review found the table comparison alone accepting it.
 */
#[OrgScopedThroughPivot(table: 'org_user', foreignKey: 'user_id')]
class ElsewhereUser extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use EnforcesScope;

    protected $connection = 'identity';

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}
