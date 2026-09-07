<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Kitsune\Core\Tenancy\Attributes\Unscoped;

/**
 * The customer. Billing and user boundary.
 *
 * Unscoped by necessity: an Org cannot be scoped to itself, and resolving the
 * current context requires reading one before any context exists. Access
 * control for this model is authorisation's job, not the scope layer's.
 */
/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property array<string, mixed>|null $settings
 */
#[Unscoped]
class Org extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = ['settings' => 'array'];

    /** @return HasMany<SiteGroup, $this> */
    public function siteGroups(): HasMany
    {
        return $this->hasMany(SiteGroup::class);
    }

    /** @return HasMany<Site, $this> */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }
}
