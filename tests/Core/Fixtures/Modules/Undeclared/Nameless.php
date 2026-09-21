<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures\Modules\Undeclared;

use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;

/** Uses the trait and declares nothing, so `ScopeResolver` fails closed. */
class Nameless extends Model
{
    use EnforcesScope;

    protected $table = 'nameless';
}
