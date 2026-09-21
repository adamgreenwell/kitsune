<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures\Modules\TwoInOne;

use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Attributes\Unscoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;

/** The class PSR-4 names. Honest. */
#[Unscoped]
class Pair extends Model
{
    use EnforcesScope;

    protected $table = 'pairs';
}

/**
 * The stowaway. PSR-4 names no file for it, so a path-derived sweep never asks about it — and the sweep's own
 * `class_exists()` on `Pair` is what used to define it.
 */
#[OrgScoped]
class Stowaway extends Model
{
    protected $table = 'stowaways';
}
