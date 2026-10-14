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
use Kitsune\Core\Tenancy\Attributes\SiteScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;

/**
 * Read-only exact-site order projection; public output uses an explicit allowlist.
 *
 * @internal
 *
 * @property int $id
 * @property int $org_id
 * @property int $site_id
 * @property int $order_id
 */
#[SiteScoped]
final class OrderLine extends Model
{
    use EnforcesScope;
    use ReadsOrderRows {
        ReadsOrderRows::newEloquentBuilder insteadof EnforcesScope;
    }

    protected $table = 'commerce_order_lines';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['owner_key', 'reader_id', 'contact_email', 'billing', 'delivery', 'request_key', 'request_digest', 'snapshot_hash', 'active_cart_key', 'token_hash'];

    protected $casts = ['quantity' => 'integer', 'tax_rate_parts' => 'integer', 'offer_terms' => 'array'];
}
