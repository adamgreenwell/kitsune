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
 * Constrains a query to the current Site, and to org-shared rows.
 *
 * `site_id IS NULL` means shared across the org (ADR-021), which is how one
 * media library serves eight brands. Those rows are still fenced by org: the
 * clause is (site_id = current OR (site_id IS NULL AND org_id = current org)),
 * never a bare NULL check, or shared rows would be visible to every customer.
 * *
 * @implements Scope<Model>
 */
final class SiteScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(Context::class);

        // No context means no rows. Failing closed here is what makes a
        // forgotten middleware a visible outage rather than a silent leak.
        if (! $context->hasSite()) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $table = $model->getTable();
        $siteId = $context->siteId();
        $orgId = $context->orgId();

        $builder->where(function (Builder $query) use ($table, $siteId, $orgId): void {
            $query->where("{$table}.site_id", $siteId)
                ->orWhere(function (Builder $shared) use ($table, $orgId): void {
                    $shared->whereNull("{$table}.site_id")
                        ->where("{$table}.org_id", $orgId);
                });
        });
    }
}
