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
use Kitsune\Core\Tenancy\Attributes\OrgScopedThroughPivot;
use Kitsune\Core\Tenancy\Context;

/**
 * Constrains a query to rows belonging to the current org THROUGH A PIVOT.
 *
 * ⚠️ Same warning as `OrgScope`, and it applies harder here. Filament does
 * not model Org, so nothing else applies this constraint — and the model this
 * exists for is `User`, where getting it wrong means enumerating every
 * account on the installation (ADR-021).
 *
 * Fails closed with no org context, exactly like `OrgScope`. That is what
 * makes the authentication carve-out necessary and visible: a user is
 * resolved BEFORE any org exists, so the login path must stand the scope down
 * explicitly rather than working by accident.
 *
 * @implements Scope<Model>
 */
final class OrgMembershipScope implements Scope
{
    public function __construct(private readonly OrgScopedThroughPivot $through) {}

    public function apply(Builder $builder, Model $model): void
    {
        $context = app(Context::class);

        if (! $context->hasOrg()) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $table = $model->getTable();
        $key = $model->getKeyName();

        // whereExists rather than a join: a join would multiply rows for a
        // user in several orgs and quietly change every count() in the admin.
        $builder->whereExists(function ($query) use ($context, $table, $key): void {
            $query->selectRaw('1')
                ->from($this->through->table)
                ->whereColumn($this->through->table.'.'.$this->through->foreignKey, $table.'.'.$key)
                ->where($this->through->table.'.'.$this->through->orgKey, $context->orgId());
        });
    }
}
