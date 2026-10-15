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
use Kitsune\Commerce\Persistence\ReadsStripeCustody;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use RuntimeException;

/** Private org-owned routing/custody; checked workflows provide explicit projections. @internal */
#[OrgScoped]
final class CheckoutRoutingReceipt extends Model
{
    use EnforcesScope;
    use ReadsStripeCustody;

    protected $table = 'commerce_checkout_routing';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['account_id', 'order_id', 'attempt_id', 'order_reference', 'attempt_reference'];

    public function save(array $options = [])
    {
        throw new RuntimeException('Stripe custody is written only by its checked workflow.');
    }

    public function delete()
    {
        throw new RuntimeException('Stripe custody is removed only by its checked workflow.');
    }

    /** @param QueryBuilder $query
     * @return ReadOnlyOrderBuilder<$this>
     */
    public function newEloquentBuilder($query): ReadOnlyOrderBuilder
    {
        return new ReadOnlyOrderBuilder($query, $this);
    }
}
