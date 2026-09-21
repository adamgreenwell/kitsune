<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures\Modules\AttributeOnly;

use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Tenancy\Attributes\Unscoped;

/**
 * Declares and does not enforce — the shape AGENTS.md §2 records `User` holding for two phases.
 * A gate reading only the attribute passes this, which is why the verifier asks twice.
 */
#[Unscoped]
class HalfDeclared extends Model
{
    protected $table = 'half_declared';
}
