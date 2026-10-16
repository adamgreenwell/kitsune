<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Kitsune\Commerce\Privacy\ReadsRetentionRows;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;

/** Immutable operator policy; checked services own writes and explicit owner-only output. @internal */
#[OrgScoped]
final class RetentionPolicy extends Model
{
    use EnforcesScope;
    use ReadsRetentionRows {
        ReadsRetentionRows::newEloquentBuilder insteadof EnforcesScope;
    }

    protected $table = 'commerce_retention_policies';

    public $timestamps = false;

    protected $guarded = ['*'];
}
