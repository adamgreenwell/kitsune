<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Http\Api;

use Illuminate\Support\Carbon;
use Kitsune\Core\Tenancy\Attributes\SiteScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @internal
 *
 * @property int $id
 * @property int $site_id
 * @property int $org_id
 * @property int|string $actor_id
 * @property string $actor_model
 * @property string $token
 * @property array<array-key, mixed> $abilities
 * @property Carbon $expires_at
 */
#[SiteScoped]
final class SiteAccessToken extends PersonalAccessToken
{
    use EnforcesScope;

    protected $table = SiteTokenSchema::TABLE;

    protected $casts = [
        'site_id' => 'integer',
        'org_id' => 'integer',
        'abilities' => 'array',
        'expires_at' => 'datetime',
    ];
}
