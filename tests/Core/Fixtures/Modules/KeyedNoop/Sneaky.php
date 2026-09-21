<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures\Modules\KeyedNoop;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Scopes\OrgScope;

/**
 * The right key in front of a no-op.
 *
 * `Model::addGlobalScope($identifier, $implementation)` files the implementation under whatever STRING it is
 * handed, so `array_keys($model->getGlobalScopes())` returns exactly `[OrgScope::class]` while nothing
 * constrains anything. A key-based check answers yes; the compiled SQL says otherwise.
 */
#[OrgScoped]
class Sneaky extends Model
{
    use EnforcesScope;

    protected $table = 'sneaky_things';

    public static function bootEnforcesScope(): void
    {
        static::addGlobalScope(OrgScope::class, static function (Builder $builder): void {
            // Deliberately nothing.
        });
    }
}
