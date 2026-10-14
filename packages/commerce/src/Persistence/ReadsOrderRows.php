<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Persistence;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Kitsune\Core\Tenancy\Context;
use RuntimeException;

/** Order models are projections only. Audited workflow services own all raw, checked writes. @internal */
trait ReadsOrderRows
{
    protected static function bootReadsOrderRows(): void
    {
        static::addGlobalScope('order_coherence', static function (Builder $query): void {
            $table = $query->getModel()->getTable();
            $context = app(Context::class);
            if ($context->orgId() === null || $context->siteId() === null) {
                $query->whereRaw('1 = 0');

                return;
            }
            $query->where($table.'.org_id', $context->orgId())->where($table.'.site_id', $context->siteId())
                ->whereExists(static fn (QueryBuilder $site) => $site->selectRaw('1')->from('sites')
                    ->whereColumn('sites.id', $table.'.site_id')->whereColumn('sites.org_id', $table.'.org_id'));
            if ($table !== 'commerce_orders') {
                $query->whereExists(static fn (QueryBuilder $order) => $order->selectRaw('1')->from('commerce_orders')
                    ->whereColumn('commerce_orders.id', $table.'.order_id')
                    ->whereColumn('commerce_orders.org_id', $table.'.org_id')->whereColumn('commerce_orders.site_id', $table.'.site_id'));
            }
        });
    }

    public function save(array $options = [])
    {
        throw new RuntimeException('Orders are written only by their authoritative workflow.');
    }

    public function delete()
    {
        throw new RuntimeException('Orders are removed only by their checked privacy workflow.');
    }

    /** @param QueryBuilder $query
     * @return ReadOnlyOrderBuilder<$this>
     */
    public function newEloquentBuilder($query): ReadOnlyOrderBuilder
    {
        return new ReadOnlyOrderBuilder($query, $this);
    }
}
