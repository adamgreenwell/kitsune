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
use Kitsune\Core\Tenancy\Concerns\DerivesGuardedColumns;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Contracts\RequiresModelSave;

/**
 * The kernel's receipt that a module is installed, and whether it is on — ADR-038.
 *
 * ⚠️ `#[Unscoped]` BECAUSE CODE IS CODE. A module is installed for the installation, not for an org: the same
 * files are on disk for everybody, and `Unscoped`'s own docblock names modules first among the genuinely
 * global cases. Per-org enablement is deferred (ADR-038), and when it arrives it is a scoped table of its own
 * rather than a column here.
 *
 * ⚠️ THE ROW IS A PROOF, SO IT MAY NOT BE WRITTEN IN BULK. Every column on this table is guarded through
 * `RequiresModelSave`, which is the mechanism `Site`, `Org` and five other models already use and
 * `ScopedBuilder` already enforces — a new builder for this would be a second implementation of a rule that
 * has been through review. What it buys here is specific: `Module::query()->update(['is_enabled' => true])`
 * turns code on without anything having checked that the code is installed, verified, or even present, and
 * `insert()` plants a receipt for a module nobody installed. The kernel reads this table at boot and registers
 * what it finds, so a plantable row is a plantable service provider.
 *
 * ⚠️ AND THAT IS A GUARD, NOT A GUARANTEE. Below Eloquent — `DB::table('modules')`, raw SQL — nothing here
 * stands, exactly as ADR-020 says of the audit log and ADR-009 of the scopes. The receipt is defence in depth
 * behind the lifecycle, not a substitute for it.
 *
 * No audit row accompanies a change to this table: `audit_log.org_id` is `NOT NULL` and an installation-level
 * act has no org to file under, so `Auditor::record()` would record nothing. ADR-038 states that plainly and
 * gives the two kernel events as the only observability a host gets.
 */
#[Unscoped]
class Module extends Model implements RequiresModelSave
{
    use DerivesGuardedColumns;
    use EnforcesScope;

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'is_enabled' => 'boolean',
        'installed_at' => 'datetime',
    ];

    /**
     * The columns a bulk write must not touch.
     *
     * ⚠️ `installed_at` IS DELIBERATELY ABSENT, AND THE REASON IS A LIMIT OF THE SHARED MECHANISM RATHER THAN
     * A JUDGEMENT THAT THE COLUMN DOES NOT MATTER. `DerivesGuardedColumns::sameGuardedValue()` compares nulls,
     * bools, arrays and scalars, ending at `is_scalar($derived) && is_scalar($now)`. A `datetime` cast makes
     * this column a Carbon instance — an object — so the comparison is false for a value that never changed,
     * `guardedColumnsAreDerived()` is then false for every save, and the model refuses its own legitimate
     * writes. Measured: listing it here refused `Module::create()` outright.
     *
     * Leaving it out is safe rather than merely convenient. Nothing decides anything from it — the lifecycle
     * compares `version` and the switch is `is_enabled` — and a bulk write that also names a guarded column is
     * still refused whole, so what a caller gains is the ability to rewrite a timestamp nothing reads.
     *
     * The underlying gap deserves closing for every model rather than here: a guarded column whose value is an
     * object cannot currently be guarded at all, and nothing says so at the mechanism.
     *
     * @return array<string, string>
     */
    public static function columnsRequiringModelSave(): array
    {
        return [
            'is_enabled' => 'it is the switch that decides whether a module\'s service provider is registered '
                .'at boot, so a bulk write turns code on with nothing having checked that the code is '
                .'installed, verified, or present.',
            'handle' => 'it names which package the receipt is for, and a bulk write re-points an existing '
                .'receipt — including its enabled state and its recorded version — at different code.',
            'version' => 'the lifecycle compares it against what Composer reports now to decide whether a '
                .'module needs upgrading, so a bulk write makes a stale install look current and lets code '
                .'run against a schema its migrations have not reached.',
        ];
    }

    protected static function booted(): void
    {
        /* Armed inside the save attempt and last, for the reasons `EntryType::booted()` records. */
        static::creating(fn (self $module) => $module->noteGuardedColumnsDerived());
        static::updating(fn (self $module) => $module->noteGuardedColumnsDerived());
    }

    /**
     * Whether `$handle` is installed and on.
     *
     * ⚠️ ABSENT IS DISABLED, and this method exists so that reading is never `Module::where(...)->first()->is_enabled`
     * on a row that may not be there. The fail-closed answer and the missing-row answer are the same answer,
     * and making that one call means no caller has to remember which.
     */
    public static function isEnabled(string $handle): bool
    {
        return self::query()
            ->where('handle', $handle)
            ->where('is_enabled', true)
            ->exists();
    }
}
