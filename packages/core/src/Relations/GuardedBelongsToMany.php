<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Relations;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Schema\RevisionWrites;

/**
 * `attach()` and `updateExistingPivot()`, made atomic.
 *
 * ⚠️ `EntryRelation` checks a field's cardinality by counting existing rows
 * and then inserting, which is two statements. Two requests attaching to the
 * same single-valued relation can both count zero and both insert —
 * restoring exactly the two-subject state the check exists to prevent, under
 * ordinary concurrent load rather than any misuse (ADR-020).
 *
 * So the write runs in a transaction that first takes a row lock on the
 * SOURCE entry. Concurrent attaches to the same entry serialise on that row;
 * attaches to different entries do not contend, which is the granularity that
 * matters — the invariant is per (source, field), never global.
 *
 * ⚠️ The source is NOT always the relation's parent. `referencedBy()` hangs
 * off the target, so locking the parent there locked the wrong row entirely:
 * two concurrent calls on different targets attaching the same source took
 * different locks, both passed the count, and left that source with two
 * subject targets. The source end is found from the pivot column, not
 * assumed.
 *
 * SQLite serialises writers anyway, so `lockForUpdate()` is a no-op there and
 * the guarantee holds for a different reason. PostgreSQL and MySQL need it.
 *
 * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
 * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends BelongsToMany<TRelatedModel, TDeclaringModel, Pivot>
 */
class GuardedBelongsToMany extends BelongsToMany
{
    /**
     * The pivot column naming the entry a cardinality is counted against.
     *
     * Named concretely, like the invariant it protects: this class exists for
     * `entry_relations` and its docblock is about subject identifiers. A
     * second pivot with a different shape can generalise it then.
     */
    private const SOURCE_COLUMN = 'source_entry_id';

    /**
     * How deep we are inside a relation write on this instance.
     *
     * ⚠️ `sync()` calls `attach()` and `detach()` on this same object, so
     * recording a revision in each would file two or three versions for one
     * sync. Only the outermost write records.
     */
    private int $depth = 0;

    /** @param  array<string, mixed>  $attributes */
    public function attach($id, array $attributes = [], $touch = true)
    {
        $this->versioned(
            $this->sourceKeys($id, $attributes),
            fn () => $this->serialised($id, $attributes, fn () => parent::attach($id, $attributes, $touch)),
        );
    }

    /** @param  array<string, mixed>  $attributes */
    public function updateExistingPivot($id, array $attributes, $touch = true)
    {
        return $this->versioned(
            $this->sourceKeys($id, $attributes),
            fn () => $this->serialised($id, $attributes, fn () => parent::updateExistingPivot($id, $attributes, $touch)),
        );
    }

    /**
     * ⚠️ Overridden ONLY to own the version, and it has to be.
     *
     * `sync()` calls `detach()` and then `attach()` on this same instance. Both
     * are overridden, so without this each filed its own revision and one sync
     * produced two or three versions — measured, before this existed. Opening
     * the depth here makes the inner calls pass through and the single
     * comparison at the end decide.
     *
     * @param  mixed  $ids
     * @param  bool  $detaching
     * @return array<string, list<mixed>>
     */
    public function sync($ids, $detaching = true)
    {
        return $this->versioned(
            // Both directions: a sync attaches and detaches, so the sources it
            // could touch are the union of what each would.
            array_values(array_unique([...$this->detachSourceKeys(null), ...$this->sourceKeys($ids, [])])),
            fn () => parent::sync($ids, $detaching),
        );
    }

    /**
     * ⚠️ Overridden for VERSIONING, not for locking.
     *
     * Detaching cannot exceed a cardinality, so it never needed the serialising
     * lock — but it very much changes the entry, and a revision that misses a
     * removed relation is as wrong as one that misses an added one.
     *
     * @param  mixed  $ids
     * @param  bool  $touch
     * @return int
     */
    public function detach($ids = null, $touch = true)
    {
        return $this->versioned(
            $this->detachSourceKeys($ids),
            fn () => parent::detach($ids, $touch),
        );
    }

    /**
     * Every source entry a detach could touch.
     *
     * Over-approximates on purpose. The comparison in `versioned()` decides
     * whether anything actually changed, so naming an extra entry costs a query
     * and never files a spurious version — whereas missing one loses history.
     *
     * @return list<mixed>
     */
    private function detachSourceKeys(mixed $ids): array
    {
        // Outgoing: the parent IS the source, whatever is being detached.
        if ($this->getForeignPivotKeyName() === self::SOURCE_COLUMN) {
            return [$this->getParent()->getKey()];
        }

        // Incoming, with no ids: every entry currently pointing at the parent.
        if ($ids === null) {
            return $this->pluck($this->getRelated()->getQualifiedKeyName())->all();
        }

        return $this->sourceKeys($ids, []);
    }

    /**
     * Run a relation write and file a revision for whatever it changed.
     *
     * ⚠️ A pivot write fires NO `Entry` event, so a relation change filed no
     * version at all — and the newest revision then no longer described the
     * entry, which makes "restore the latest version" silently revert it. The
     * same shape as the bulk-update gap, one storage strategy along, and the
     * seventh time in this project that a guard on a model event turned out to
     * be a guard on one path.
     *
     * CHANGED, not merely attempted: the before and after states are compared,
     * so a detach that matched nothing files nothing.
     *
     * @param  list<mixed>  $sources
     * @param  callable(): mixed  $write
     */
    private function versioned(array $sources, callable $write): mixed
    {
        if ($this->depth > 0 || RevisionWrites::suspended()) {
            // Already inside a relation write on this instance — `sync()` is
            // the case — or recording is stood down.
            return $write();
        }

        // ⚠️ ONE transaction, and the LOCK is taken before the before-state is
        // read.
        //
        // `serialised()` opened its own transaction, so it committed and released
        // the source lock before the after-state was read — leaving a window in
        // which a second writer could commit. The first operation's revision then
        // snapshotted both operations, so one version went missing and another
        // was recorded twice. The lock has to span read-write-read, not just the
        // write, and taking it here means the inner `serialised()` re-locks rows
        // this transaction already holds, which is free.
        return DB::transaction(function () use ($sources, $write): mixed {
            if ($sources !== []) {
                // withoutGlobalScopes: this is a lock, not a read that reaches a
                // caller. A source in another scope must still serialise, and a
                // scoped query that matched nothing would take no lock at all.
                Entry::query()->withoutGlobalScopes()
                    ->whereKey($sources)
                    ->lockForUpdate()
                    ->get();
            }

            $entries = Entry::query()->withoutGlobalScopes()->whereKey($sources)->get();
            $before = $entries->mapWithKeys(
                fn (Entry $entry): array => [$entry->getKey() => $entry->relationState()],
            )->all();

            $this->depth++;

            try {
                $result = $write();
            } finally {
                $this->depth--;
            }

            foreach ($entries as $entry) {
                $entry->recordRevisionForRelationChange($before[$entry->getKey()] ?? []);
            }

            return $result;
        });
    }

    /**
     * Every source entry this write could land a row on.
     *
     * The parent when the relation hangs off the source; the ids being
     * attached when it hangs off the target. An explicit `source_entry_id` in
     * the attributes is a MOVE, so its destination is included too — that is
     * the row the count will be taken against.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<mixed>
     */
    private function sourceKeys(mixed $id, array $attributes): array
    {
        // ⚠️ `attach()` also takes an ID-to-ATTRIBUTES map, and each entry in
        // it can carry its own `source_entry_id`. Reading only the common
        // $attributes meant an outgoing attach could write the pivot against
        // an overridden source while just the parent was locked — so two
        // calls through different parents targeted the same cardinality-one
        // source, both passed the count, and both inserted.
        $parsed = $this->parseIds($id);

        $keys = [];

        if ($this->getForeignPivotKeyName() === self::SOURCE_COLUMN) {
            $keys[] = $this->getParent()->getKey();
        } else {
            // In the map form the related id is the KEY and the value is its
            // attributes; in the plain form the value is the id itself.
            foreach ($parsed as $key => $value) {
                $keys[] = is_array($value) ? $key : $value;
            }
        }

        // A per-ID override names a destination whichever way the relation
        // runs, so this is read in both directions.
        foreach ($parsed as $value) {
            if (is_array($value) && isset($value[self::SOURCE_COLUMN])) {
                $keys[] = $value[self::SOURCE_COLUMN];
            }
        }

        if (isset($attributes[self::SOURCE_COLUMN])) {
            $keys[] = $attributes[self::SOURCE_COLUMN];
        }

        // Sorted so concurrent writers touching the same pair take the locks
        // in the same order, which is what keeps them from deadlocking.
        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    /**
     * Run the write behind a lock on every source entry it could touch.
     *
     * Reuses an outer transaction when there is one, so a caller that already
     * wrapped several attaches gets one lock rather than a lock per row.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function serialised(mixed $id, array $attributes, callable $write): mixed
    {
        $keys = $this->sourceKeys($id, $attributes);

        return DB::transaction(function () use ($keys, $write): mixed {
            if ($keys !== []) {
                // withoutGlobalScopes: this is a lock, not a read of anything
                // that reaches the caller. A source in another scope must
                // still serialise, and a scoped query that matched nothing
                // would take no lock at all.
                $this->getParent()->newQueryWithoutScopes()
                    ->whereKey($keys)
                    ->lockForUpdate()
                    ->get();
            }

            return $write();
        });
    }
}
