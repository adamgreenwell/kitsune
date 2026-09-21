<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures\Modules\LateRemoval;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Scopes\OrgScope;

/**
 * A genuine scope, genuinely registered, stripped a layer further out.
 *
 * The trait's boot is untouched, so `getGlobalScopes()` holds a real `OrgScope` under its real key and an
 * `instanceof` check on the values passes. Registration is not application: every query this model builds has
 * the scope removed again.
 */
#[OrgScoped]
class Escapee extends Model
{
    use EnforcesScope;

    protected $table = 'escapees';

    public function newQuery(): Builder
    {
        return parent::newQuery()->withoutGlobalScope(OrgScope::class);
    }
}
