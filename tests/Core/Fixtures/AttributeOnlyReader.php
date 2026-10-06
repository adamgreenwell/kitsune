<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;

/**
 * Declares the org scope and never applies it — the attribute without `use EnforcesScope`, as `AttributeOnlyUser` is.
 *
 * ⚠️ HALF-CONFIGURED ON PURPOSE: its queries are unscoped, so a reader guard that looked for the attribute would load
 * another org's reader through it. It tells "a scope is registered" apart from "one was declared".
 */
#[OrgScoped]
class AttributeOnlyReader extends Authenticatable
{
    protected $table = 'test_readers';

    protected $guarded = [];

    public $timestamps = false;
}
