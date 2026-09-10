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
     * The guarded columns as they stood when they were derived, or null when nothing has been.
     *
     * ⚠️ A SNAPSHOT RATHER THAN A BOOLEAN, and a boolean was not enough — review found two ways past it.
     * A flag says "some write's guards ran"; it cannot say WHICH write. `saved` clears it and an
     * aborted save never reaches `saved`, so a `Site` update that derived its URL columns and then
     * failed in the later `updating` scope check left the flag standing: catch that, change `base_url`,
     * call `saveQuietly()`, and the builder accepted a write whose derived columns belong to the
     * previous value. And the mutator being reachable let a caller arm the flag on `Site::query()`'s
     * model and then hand-roll the insert.
     *
     * The proof is therefore about VALUES: these columns, derived to these values. Change any of them
     * without deriving again and the proof no longer describes the write, whatever happened to the
     * save that made it.
     *
     * @var array<string, mixed>|null
     */
    private ?array $guardedColumnsDerived = null;

    public static function bootDerivesGuardedColumns(): void
    {
        // ⚠️ Still cleared after a completed write, so a second save has to earn its own proof rather
        // than inheriting one that happens to still describe the same values.
        static::saved(function (self $model): void {
            $model->guardedColumnsDerived = null;
        });
    }

    public function guardedColumnsAreDerived(): bool
    {
        if ($this->guardedColumnsDerived === null) {
            return false;
        }

        /*
         * ⚠️ COMPARED LOOSELY, because a value can arrive as an int on the model and a numeric string
         * from a form, and a proof that fails on `255` versus `'255'` would send an author looking for a
         * bug that is not there. What matters is whether the value CHANGED since it was derived.
         *
         * ⚠️ AND NOT EVERY GUARDED COLUMN IS A SCALAR — `Entry`'s `values` is a JSON-cast ARRAY, and a
         * string cast on it threw `Array to string conversion` in 150 tests. Loose equality answers
         * both kinds: for arrays it compares keys and values, and for scalars it ignores the int/string
         * difference the paragraph above is about.
         */
        foreach (static::columnsRequiringModelSave() as $column => $ignored) {
            $derived = $this->guardedColumnsDerived[$column] ?? null;
            $now = $this->getAttribute($column);

            if (($derived === null) !== ($now === null)) {
                return false;
            }

            if ($derived !== null && $derived != $now) {
                return false;
            }
        }

        return true;
    }

    /**
     * Called by whatever derives and validates this model's guarded columns.
     *
     * ⚠️ PROTECTED, WHICH REVIEW ASKED FOR AND COSTS NOTHING. A public mutator let any caller arm the
     * proof on `Site::query()`'s own model and then hand-roll an insert with columns it authored. The
     * models call this from closures declared inside their own `booted()`, so class scope is all the
     * visibility it ever needed — and the value snapshot above refuses a forged arming anyway, so this
     * is the second lock on a door rather than the only one.
     */
    protected function noteGuardedColumnsDerived(): void
    {
        $snapshot = [];

        foreach (static::columnsRequiringModelSave() as $column => $ignored) {
            $snapshot[$column] = $this->getAttribute($column);
        }

        $this->guardedColumnsDerived = $snapshot;
    }
}
