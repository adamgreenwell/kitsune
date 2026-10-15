<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Kitsune\Commerce\Persistence\ReadsOrderRows;
use Kitsune\Core\Entitlements\UtcInstant;
use Kitsune\Core\Tenancy\Attributes\SiteScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;

/** Immutable, exact-Site proof-backed account attachment; it grants no entitlement itself. @internal */
#[SiteScoped]
final class OrderClaim extends Model
{
    use EnforcesScope;
    use ReadsOrderRows {
        ReadsOrderRows::newEloquentBuilder insteadof EnforcesScope;
    }

    protected $table = 'commerce_order_claims';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['reader_id', 'owner_key', 'snapshot_hash'];

    protected $casts = ['claimed_at' => UtcInstant::class];
}
