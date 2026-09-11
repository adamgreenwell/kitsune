<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tenancy\Concerns;

use Illuminate\Database\Eloquent\Builder;

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
     * ⚠️ AND VALUES ARE NOT ENOUGH ON THEIR OWN, which the next round of review established: validity
     * can change while every snapshotted value stays identical, because a rival claiming the same
     * hostname changes the WORLD rather than the row. So the snapshot answers "do these columns still
     * hold what was derived", and `performInsert()`/`performUpdate()` answer "and was that derivation
     * part of THIS attempt" — two questions, and neither mechanism can ask the other's.
     *
     * @var array<string, mixed>|null
     */
    private ?array $guardedColumnsDerived = null;

    /**
     * Whether this instance is inside its own `performInsert()`/`performUpdate()` right now.
     *
     * ⚠️ THE ONLY DISCRIMINATOR HERE A CALLER CANNOT ARRANGE, which review established after two
     * others turned out to be arrangeable. `exists` and `getIncrementing()` are both reachable through
     * the public `Builder::getModel()`, so a hand-rolled write could present either — see the
     * interface's docblock for the two measured attacks. This is private, has no setter, and is true
     * only while a real save is on the stack.
     */
    private bool $insideModelSave = false;

    public static function bootDerivesGuardedColumns(): void
    {
        // ⚠️ Still cleared after a completed write, so a second save has to earn its own proof rather
        // than inheriting one that happens to still describe the same values.
        static::saved(function (self $model): void {
            $model->guardedColumnsDerived = null;
        });
    }

    /**
     * ⚠️ THE PROOF IS CONSUMED BY THE ATTEMPT, HOWEVER THE ATTEMPT ENDS, and review found that value
     * equality could not express this: validity can change while every snapshotted value stays
     * identical, because what changed is the WORLD rather than the row.
     *
     * Measured. A `Site` create names another org, passes `refuseOverlappingClaim()` because no rival
     * holds the host yet, arms the proof in its `saving` listener, and is then refused by
     * `EnforcesScope`'s `creating` guard. A rival now claims the same host at `/`. Retrying THE SAME
     * INSTANCE through `saveQuietly()` runs no listener at all — so neither the overlap check nor the
     * scope guard is consulted — and the proof still describes these exact values, so the builder
     * accepted the write and both `/` and `/news` landed on one hostname.
     *
     * ⚠️ `performInsert()` AND `performUpdate()` RATHER THAN `save()`, and the difference is what makes
     * this hold. `fireModelEvent('creating')` is inside `performInsert()`, so a guard that throws there
     * throws inside this `try`. And a trait's `save()` would be SILENTLY shadowed by a model that
     * defines its own — `Site` does, on another branch — which is the kind of collision this codebase
     * has spent rounds on. These two are not overridden by any model, and `Site::save()`'s
     * `parent::save()` still routes through them.
     *
     * ⚠️ IT ALSO COVERS THE ORDINARY CASE, which makes the `saved` listener above redundant rather than
     * wrong: a completed write clears here too. The listener stays because a save with nothing dirty
     * never reaches either method and still fires `saved`.
     *
     * @param  Builder<static>  $query
     */
    protected function performInsert(Builder $query)
    {
        $this->insideModelSave = true;

        /*
         * ⚠️ CLEARED ON ENTRY AS WELL AS ON EXIT, which is what makes a proof belong to ONE attempt.
         * Review found the exit half insufficient: `saving` fires BEFORE this method, so an observer
         * returning false or throwing there aborts with neither the `finally` below nor `saved` having
         * run. Clearing here means a proof armed by an attempt that never started cannot survive into
         * the next one — and the arming listeners fire on `creating`/`updating`, which are inside this
         * call, so the legitimate proof is armed after this line rather than before it.
         */
        $this->guardedColumnsDerived = null;

        try {
            return parent::performInsert($query);
        } finally {
            $this->insideModelSave = false;
            $this->guardedColumnsDerived = null;
        }
    }

    /**
     * See `performInsert()`: the proof belongs to one attempt, and an update's guards can abort too.
     *
     * @param  Builder<static>  $query
     */
    protected function performUpdate(Builder $query)
    {
        $this->insideModelSave = true;

        /*
         * ⚠️ CLEARED ON ENTRY AS WELL AS ON EXIT, which is what makes a proof belong to ONE attempt.
         * Review found the exit half insufficient: `saving` fires BEFORE this method, so an observer
         * returning false or throwing there aborts with neither the `finally` below nor `saved` having
         * run. Clearing here means a proof armed by an attempt that never started cannot survive into
         * the next one — and the arming listeners fire on `creating`/`updating`, which are inside this
         * call, so the legitimate proof is armed after this line rather than before it.
         */
        $this->guardedColumnsDerived = null;

        try {
            return parent::performUpdate($query);
        } finally {
            $this->insideModelSave = false;
            $this->guardedColumnsDerived = null;
        }
    }

    public function isPerformingModelSave(): bool
    {
        return $this->insideModelSave;
    }

    public function guardedColumnsAreDerived(): bool
    {
        if ($this->guardedColumnsDerived === null) {
            return false;
        }

        foreach (static::columnsRequiringModelSave() as $column => $ignored) {
            if (! self::sameGuardedValue($this->guardedColumnsDerived[$column] ?? null, $this->getAttribute($column))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a guarded column still holds the value it was derived to.
     *
     * ⚠️ `==` WAS A BYPASS, which review found. PHP considers two NUMERIC-LOOKING STRINGS loosely equal
     * when they name the same number, so `'0e1' == '0e2'` is TRUE — and a save armed for
     * `https://0e1/news` accepted a quiet retry that had changed `canonical_host` to `0e2`. A proof about
     * values cannot use a comparison that calls two different values the same.
     *
     * ⚠️ AND `===` ALONE IS TOO STRICT, which is why this is a function rather than an operator. A value
     * arrives as an int on the model and as a numeric string from a form, and a proof that failed on
     * `255` versus `'255'` would send an author looking for a bug that is not there. Casting to string
     * answers both: `(string) 255 === '255'` holds, and `'0e1'` and `'0e2'` stay different.
     *
     * ⚠️ AND NOT EVERY GUARDED COLUMN IS A SCALAR — `Entry`'s `values` is a JSON-cast ARRAY, and a string
     * cast on one threw `Array to string conversion` in 150 tests. Arrays are compared member by member
     * with the same rule, with keys sorted first: nothing in Kitsune may depend on JSON key order, which
     * `Entry::versionedState()` learned from the engine matrix, so this comparison must not either.
     */
    private static function sameGuardedValue(mixed $derived, mixed $now): bool
    {
        if (($derived === null) !== ($now === null)) {
            return false;
        }

        if ($derived === null) {
            return true;
        }

        if (is_array($derived) || is_array($now)) {
            if (! is_array($derived) || ! is_array($now) || count($derived) !== count($now)) {
                return false;
            }

            ksort($derived);
            ksort($now);

            if (array_keys($derived) !== array_keys($now)) {
                return false;
            }

            foreach ($derived as $key => $value) {
                if (! self::sameGuardedValue($value, $now[$key])) {
                    return false;
                }
            }

            return true;
        }

        if (is_bool($derived) || is_bool($now)) {
            return $derived === $now;
        }

        return is_scalar($derived) && is_scalar($now) && (string) $derived === (string) $now;
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
