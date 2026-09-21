<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures\Modules\Smuggler\stubs;

use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;

/** Inside the package, under no PSR-4 root, so nothing names it. Declared org-scoped and enforcing nothing. */
#[OrgScoped]
class Smuggled extends Model
{
    protected $table = 'smuggled_things';
}
