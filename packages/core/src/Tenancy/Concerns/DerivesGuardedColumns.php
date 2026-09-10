<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tenancy\Concerns;

/**
 * Lets a `RequiresModelSave` model tell the builder its guarded columns have been derived.
 *
 * ⚠️ BECAUSE AN ATTRIBUTE BEING PRESENT PROVES NOTHING, which is what review found. `ScopedBuilder`
 * decided a write was a genuine model save by checking whether the guarded column existed on the
 * model behind it — and `createQuietly()`, `saveQuietly()` and anything inside `withoutEvents()`
 * populate attributes while suppressing the `saving` callback that derives and validates them. So a
 * quiet create wrote `canonical_host = NULL` for a site declaring a public URL, and a quiet create
 * naming the derived columns itself **stole an overlapping cross-org claim** — measured, `steal.test/`
 * held by one org and `steal.test/news` written under another, which is the ADR-021 theft the guard
 * exists to prevent.
 *
 * ⚠️ THE FLAG IS THE PROOF BECAUSE ONLY THE GUARD CAN SET IT. An attribute can be supplied by any
 * caller; this is set by the code that does the deriving, so a path that skipped the deriving cannot
 * present it. `FieldStorage::$shapeGuarded` and `GuardedStorageBuilder` are the same mechanism, and
 * this generalises them to the four models `RequiresModelSave` covers rather than copying them a
 * fifth time.
 *
 * ⚠️ AND IT SAYS "DERIVED", NOT "THE HOOK RAN", because those are different claims and the narrower
 * one is what the builder needs. `Entry`'s guarded columns are made correct in
 * `convertFieldValuesForWrite()` — at the builder, where §6 says a guard belongs — so a quiet entry
 * create is genuinely safe and stays allowed. A flag meaning "the `saving` event fired" would have
 * refused it for no reason.
 */
trait DerivesGuardedColumns
{
    /**
     * True only while THIS instance's guarded columns have been derived for the write in flight.
     *
     * ⚠️ Cleared after the write, so the next one has to earn it again — the same discipline
     * `FieldStorage` uses, and the reason a flag is not a permanent grant.
     */
    private bool $guardedColumnsDerived = false;

    public static function bootDerivesGuardedColumns(): void
    {
        static::saved(function (self $model): void {
            $model->guardedColumnsDerived = false;
        });
    }

    public function guardedColumnsAreDerived(): bool
    {
        return $this->guardedColumnsDerived;
    }

    /** Called by whatever derives and validates this model's guarded columns. */
    public function noteGuardedColumnsDerived(): void
    {
        $this->guardedColumnsDerived = true;
    }
}
