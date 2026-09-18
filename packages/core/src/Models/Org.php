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
use Kitsune\Core\Settings\Concerns\HoldsSettings;
use Kitsune\Core\Settings\SettingsGuard;
use Kitsune\Core\Tenancy\Attributes\Unscoped;
use Kitsune\Core\Tenancy\Concerns\DerivesGuardedColumns;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Contracts\RequiresModelSave;

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
class Org extends Model implements RequiresModelSave
{
    use DerivesGuardedColumns;
    use EnforcesScope;
    use HoldsSettings;
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = ['settings' => 'array'];

    /**
     * ⚠️ `settings`, which makes this a `RequiresModelSave` model — and so refuses a bulk `insert()` of orgs
     * outright, where this model had no per-row column before and kept that fast path. `HoldsSettings` validates
     * and invalidates on the model's events, and a bulk write dispatches none of them (ADR-022).
     *
     * @return array<string, string>
     */
    public static function columnsRequiringModelSave(): array
    {
        return ['settings' => SettingsGuard::REFUSED_IN_BULK];
    }

    protected static function booted(): void
    {
        // Armed inside the save attempt and last, for the reasons `EntryType::booted()` records.
        static::creating(fn (self $org) => $org->noteGuardedColumnsDerived());
        static::updating(fn (self $org) => $org->noteGuardedColumnsDerived());
    }

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

    /** @return HasMany<Role, $this> */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }
}
