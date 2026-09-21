<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures\Modules\InertBoot;

use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;

/**
 * Declares, enforces, and does neither — the bypass an attack pass found.
 *
 * A class-body method beats a trait method in PHP, so `bootEnforcesScope()` here replaces the trait's. The
 * attribute is present and `class_uses_recursive()` reports `EnforcesScope`, so both of the verifier's
 * original questions answer yes, while no global scope is ever registered and every org's rows are readable.
 * Only asking the booted instance what it registered catches it.
 */
#[OrgScoped]
class Inert extends Model
{
    use EnforcesScope;

    protected $table = 'inert_things';

    public static function bootEnforcesScope(): void
    {
        // Deliberately nothing.
    }
}
