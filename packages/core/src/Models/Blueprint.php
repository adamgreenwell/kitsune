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
 * The receipt that an org has had a blueprint applied to it — ADR-039.
 *
 * ⚠️ `#[OrgScoped]`, WHERE `Module` IS `#[Unscoped]`, AND THE DIFFERENCE IS THE POINT. A module is code, and
 * the same files are on disk for everybody; a blueprint is *configuration for one org*, and the rows it writes
 * are that org's to edit afterwards. `Module`'s own docblock says "code is code" for the same reason in the
 * other direction. ADR-039 settles the axis and forbids a blueprint from creating global rows at all.
 *
 * ⚠️ THE ROW IS WRITTEN BEFORE THE WORK, NOT AFTER IT. `Module`'s receipt is written last, because an install
 * that fails should leave no claim that it succeeded. A blueprint's is written FIRST, because the failure that
 * matters here is different: apply is not one transaction — a blueprint that indexes a field issues DDL, which
 * commits implicitly on MySQL and MariaDB — so a crash mid-apply is a reachable state, and a half-applied
 * blueprint with no receipt is one nothing can find to finish or undo. The receipt is an intent record, and
 * `applied_at` is what distinguishes intended from completed.
 *
 * ⚠️ THE ROW IS A PROOF, SO IT MAY NOT BE WRITTEN IN BULK, on the reasoning `Module` records: a plantable
 * receipt is a claim that schema was installed and checked when it was not. Below Eloquent nothing here
 * stands, exactly as ADR-020 says of the audit log — this is defence in depth behind the applier.
 *
 * @property int $id
 * @property int $org_id
 * @property string $handle the blueprint's own name, e.g. `blog`
 * @property string $version the version that was applied into this org
 * @property array<string, mixed>|null $manifest what was applied, as applied; null while an apply is in flight
 * @property CarbonInterface|null $applied_at null while an apply is in flight
 */
#[OrgScoped]
class Blueprint extends Model implements RequiresModelSave
{
    use DerivesGuardedColumns;
    use EnforcesScope;

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'manifest' => 'array',
        'applied_at' => 'datetime',
    ];

    /**
     * @return array<string, string>
     */
    public static function columnsRequiringModelSave(): array
    {
        return [
            'handle' => 'it names which blueprint this org has, so a bulk write re-points an existing receipt '
                .'— and the manifest recording what that blueprint wrote — at a different one.',
            'version' => 'apply compares it against the blueprint\'s own version to tell an upgrade from a '
                .'re-run, so a bulk write makes a stale apply look current and skips the work an upgrade owes.',
            /*
             * ⚠️ NOT `applied_at`, and the omission is deliberate rather than an oversight.
             *
             * `Module` guards every column it has, and this one cannot: `sameGuardedValue()` ends at
             * `is_scalar()`, and a `datetime` cast makes this a Carbon object — so guarding it refused
             * `Blueprint::create()` outright. `Module::installed_at` is unguarded for exactly this reason and
             * its docblock records it. The column is written only by the applier, on a row it just created.
             */
        ];
    }

    protected static function booted(): void
    {
        /* Armed inside the save attempt and last, for the reasons `EntryType::booted()` records. */
        static::creating(fn (self $blueprint) => $blueprint->noteGuardedColumnsDerived());
        static::updating(fn (self $blueprint) => $blueprint->noteGuardedColumnsDerived());
    }

    /** The receipt for a handle in the current org, or null when this org has never had it applied. */
    public static function receiptFor(string $handle): ?self
    {
        return self::query()->where('handle', $handle)->first();
    }
}
