<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Kitsune\Commerce\Persistence\ReadOnlyOrderBuilder;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use RuntimeException;

/** Minimal org-owned evidence; it grants no authority over an account, order, Site or reader. @internal */
#[OrgScoped]
final class FinancialSignal extends Model
{
    use EnforcesScope;

    protected $table = 'commerce_financial_signals';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['event_id', 'charge_id', 'subject_id', 'payload_hash'];

    public function save(array $options = [])
    {
        throw new RuntimeException('Financial signals are written only by their checked workflow.');
    }

    public function delete()
    {
        throw new RuntimeException('Financial signals are retained by their checked workflow.');
    }

    /** @param QueryBuilder $query
     * @return ReadOnlyOrderBuilder<$this>
     */
    public function newEloquentBuilder($query): ReadOnlyOrderBuilder
    {
        return new ReadOnlyOrderBuilder($query, $this);
    }
}
