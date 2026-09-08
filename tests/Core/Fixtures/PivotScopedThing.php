<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Tenancy\Attributes\OrgScopedThroughPivot;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;

/**
 * Stands in for `User`, which lives in the skeleton and cannot be reached
 * from the package suite.
 */
#[OrgScopedThroughPivot(table: 'pivot_scoped_thing_org', foreignKey: 'pivot_scoped_thing_id')]
class PivotScopedThing extends Model
{
    use EnforcesScope;

    protected $table = 'pivot_scoped_things';

    protected $guarded = [];

    public $timestamps = false;
}
