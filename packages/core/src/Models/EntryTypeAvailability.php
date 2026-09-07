<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Tenancy\Attributes\Unscoped;

/**
 * Per-site entry type availability, on the same sparse inheritance as
 * settings (ADR-022).
 *
 * Sparse means a row exists only where someone made a decision. Absent at
 * every level is enabled — the same rule settings use, rather than a second
 * inheritance system to learn and debug.
 *
 * Unscoped by declaration: rows are reached through an EntryType and a Site,
 * both of which are already constrained, and the resolution below walks the
 * hierarchy explicitly rather than relying on a global scope.
 *
 * @property int $id
 * @property int $entry_type_id
 * @property string $scope_type
 * @property int $scope_id
 * @property bool $is_enabled
 */
#[Unscoped]
class EntryTypeAvailability extends Model
{
    public const TABLE = 'entry_type_availability';

    protected $table = self::TABLE;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['is_enabled' => 'boolean'];

    /**
     * Most specific decision wins: site, then site group, then org.
     *
     * With no site there is nothing to disable against, so the type is
     * available — this path is reached by console commands and the API
     * before any site is established.
     */
    public static function isEnabledFor(EntryType $type, ?Site $site): bool
    {
        if ($site === null) {
            return true;
        }

        $rows = static::query()
            ->where('entry_type_id', $type->getKey())
            ->get()
            ->keyBy(fn (self $row): string => $row->scope_type.':'.$row->scope_id);

        foreach ([
            'site:'.$site->getKey(),
            'site_group:'.$site->site_group_id,
            'org:'.$site->org_id,
        ] as $key) {
            if ($rows->has($key)) {
                return $rows->get($key)->is_enabled;
            }
        }

        // Absent at every level = enabled (ADR-022).
        return true;
    }
}
