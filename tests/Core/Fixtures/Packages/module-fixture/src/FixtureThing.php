<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Fixture\Module;

use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Tenancy\Attributes\Unscoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;

/** Declares and enforces, matching the package's `scoping` declaration. */
#[Unscoped]
class FixtureThing extends Model
{
    use EnforcesScope;

    protected $table = 'fixture_things';
}
