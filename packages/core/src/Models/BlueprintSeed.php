<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Concerns\DerivesGuardedColumns;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Contracts\RequiresModelSave;

/**
 * Committed content facts, separate from the schema intent receipt — ADR-039. @internal
 *
 * Written with the content in one row transaction. No "started" state, content snapshot,
 * ownership of operator entries, or permission to replay a missing entry.
 *
 * @property int $id
 * @property int $org_id
 * @property int $blueprint_id
 * @property int|null $site_id
 * @property int $original_site_id
 * @property string $locale
 * @property string $version
 * @property array<string, mixed> $manifest
 * @property CarbonInterface $seeded_at
 */
#[OrgScoped]
class BlueprintSeed extends Model implements RequiresModelSave
{
    use DerivesGuardedColumns;
    use EnforcesScope;

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['manifest' => 'array', 'seeded_at' => 'datetime'];

    /** @return array<string, string> */
    public static function columnsRequiringModelSave(): array
    {
        return array_fill_keys(['blueprint_id', 'site_id', 'original_site_id', 'locale', 'version'],
            'seed receipt identity and version must be written through the checked model path.');
    }

    protected static function booted(): void
    {
        static::creating(fn (self $receipt) => $receipt->noteGuardedColumnsDerived());
        static::updating(fn (self $receipt) => $receipt->noteGuardedColumnsDerived());
    }
}
