<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Kitsune\Commerce\Persistence\ReadOnlyOrderBuilder;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Context;
use RuntimeException;

/** Immutable org-owned stock history; its product and order references are historical. @internal */
#[OrgScoped]
final class StockMovement extends Model
{
    use EnforcesScope;

    protected $table = 'commerce_stock_movements';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['actor_type', 'actor_id', 'order_reference', 'line_reference'];

    protected static function booted(): void
    {
        self::addGlobalScope('stock_custody', static function (Builder $query): void {
            $org = app(Context::class)->orgId();
            $query->where('commerce_stock_movements.org_id', $org ?? -1)
                ->whereExists(static fn (QueryBuilder $parent) => $parent->selectRaw('1')->from('orgs')
                    ->whereColumn('orgs.id', 'commerce_stock_movements.org_id')->whereNull('orgs.deleted_at'))
                ->whereExists(static fn (QueryBuilder $parent) => $parent->selectRaw('1')->from('sites')
                    ->whereColumn('sites.id', 'commerce_stock_movements.site_id')->whereColumn('sites.org_id', 'commerce_stock_movements.org_id'));
        });
    }

    public function save(array $options = [])
    {
        throw new RuntimeException('Stock history is written only by its checked workflow.');
    }

    public function delete()
    {
        throw new RuntimeException('Stock history is retained by its checked workflow.');
    }

    /** @param QueryBuilder $query
     * @return ReadOnlyOrderBuilder<$this>
     */
    public function newEloquentBuilder($query): ReadOnlyOrderBuilder
    {
        return new ReadOnlyOrderBuilder($query, $this);
    }
}
