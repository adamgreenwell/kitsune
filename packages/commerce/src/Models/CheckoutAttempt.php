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

/** Read-only checkout custody; only checked workflow projections may leave the server. @internal */
#[SiteScoped]
final class CheckoutAttempt extends Model
{
    use EnforcesScope;
    use ReadsOrderRows {
        ReadsOrderRows::newEloquentBuilder insteadof EnforcesScope;
    }

    protected $table = 'commerce_checkout_attempts';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['account_id', 'api_version', 'credential_witness', 'request_payload', 'request_hash', 'order_hash', 'session_id', 'checkout_url', 'lease_token', 'lease_until'];

    protected $casts = ['request_payload' => 'array', 'reviewed_at' => UtcInstant::class, 'expires_at' => UtcInstant::class,
        'lease_until' => UtcInstant::class, 'created_at' => UtcInstant::class, 'updated_at' => UtcInstant::class];
}
