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
use Kitsune\Core\Settings\Concerns\HoldsSettings;
use Kitsune\Core\Settings\SettingsGuard;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Concerns\DerivesGuardedColumns;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Contracts\RequiresModelSave;

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
class SiteGroup extends Model implements RequiresModelSave
{
    use DerivesGuardedColumns;
    use EnforcesScope;
    use HoldsSettings;

    protected $guarded = [];

    protected $casts = ['settings' => 'array'];

    /**
     * ⚠️ `settings`, for the reason `Org::columnsRequiringModelSave()` gives — and with the same cost: a bulk
     * `insert()` of site groups is refused outright now that this model declares a per-row column.
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
        static::creating(fn (self $group) => $group->noteGuardedColumnsDerived());
        static::updating(fn (self $group) => $group->noteGuardedColumnsDerived());
    }

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
