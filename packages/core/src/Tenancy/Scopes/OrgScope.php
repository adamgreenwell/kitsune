<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tenancy\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Kitsune\Core\Tenancy\Context;

/**
 * Constrains a query to the current Org.
 *
 * ⚠️ This is the one with no framework safety net. Filament does not model
 * Org, so nothing else applies this constraint — if this class is wrong, a
 * cross-org leak has nothing else to catch it (ADR-021).
 * *
 * @implements Scope<Model>
 */
final class OrgScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(Context::class);

        if (! $context->hasOrg()) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->getTable().'.org_id', $context->orgId());
    }
}
