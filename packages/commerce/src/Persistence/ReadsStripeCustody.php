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

/** Org-owned cross-site routing still requires a coherent independent parent graph. @internal */
trait ReadsStripeCustody
{
    protected static function bootReadsStripeCustody(): void
    {
        static::addGlobalScope('stripe_custody', static function (Builder $query): void {
            $table = $query->getModel()->getTable();
            $org = app(Context::class)->orgId();
            if ($org === null) {
                $query->whereRaw('1 = 0');

                return;
            }
            $query->where($table.'.org_id', $org)->whereExists(static fn (QueryBuilder $orgQuery) => $orgQuery->selectRaw('1')->from('orgs')
                ->whereColumn('orgs.id', $table.'.org_id')->whereNull('orgs.deleted_at'));
            $live = static function (Builder $rows) use ($table): void {
                $rows->whereExists(static fn (QueryBuilder $site) => $site->selectRaw('1')->from('sites')
                    ->whereColumn('sites.id', $table.'.site_id')->whereColumn('sites.org_id', $table.'.org_id'))
                    ->whereExists(static fn (QueryBuilder $order) => $order->selectRaw('1')->from('commerce_orders')
                        ->whereColumn('commerce_orders.id', $table.'.order_id')->whereColumn('commerce_orders.org_id', $table.'.org_id')
                        ->whereColumn('commerce_orders.site_id', $table.'.site_id')->whereColumn('commerce_orders.reference', $table.'.order_reference'))
                    ->whereExists(static fn (QueryBuilder $attempt) => $attempt->selectRaw('1')->from('commerce_checkout_attempts')
                        ->whereColumn('commerce_checkout_attempts.id', $table.'.attempt_id')->whereColumn('commerce_checkout_attempts.org_id', $table.'.org_id')
                        ->whereColumn('commerce_checkout_attempts.site_id', $table.'.site_id')->whereColumn('commerce_checkout_attempts.order_id', $table.'.order_id')
                        ->whereColumn('commerce_checkout_attempts.reference', $table.'.attempt_reference')->whereColumn('commerce_checkout_attempts.account_id', $table.'.account_id')
                        ->whereColumn('commerce_checkout_attempts.mode', $table.'.mode'));
            };
            if ($table === 'commerce_stripe_events') {
                $query->where(static function (Builder $rows) use ($table, $live): void {
                    $rows->where(static function (Builder $erased) use ($table): void {
                        $erased->where($table.'.state', 'erased');
                        foreach (['site_id', 'order_id', 'attempt_id', 'order_reference', 'attempt_reference', 'session_id', 'raw_payload', 'lease_token', 'lease_until'] as $column) {
                            $erased->whereNull($table.'.'.$column);
                        }
                    })->orWhere(static function (Builder $current) use ($table, $live): void {
                        $current->where($table.'.state', '!=', 'erased');
                        $live($current);
                    });
                });
            } else {
                $live($query);
            }
        });
    }
}
