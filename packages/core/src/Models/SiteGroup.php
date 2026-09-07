<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;

/**
 * The brand. Exists for settings inheritance (ADR-022), not row ownership —
 * which is why there are three structural levels but only two that scope.
 */
/**
 * @property int $id
 * @property int $org_id
 * @property string $handle
 * @property string $name
 * @property array<string, mixed>|null $settings
 */
#[OrgScoped]
class SiteGroup extends Model
{
    use EnforcesScope;

    protected $guarded = [];

    protected $casts = ['settings' => 'array'];

    /** @return BelongsTo<Org, $this> */
    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class);
    }

    /** @return HasMany<Site, $this> */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }
}
