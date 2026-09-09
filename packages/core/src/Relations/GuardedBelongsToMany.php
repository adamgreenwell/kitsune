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
    use RecordsRelationRevisions;

    /**
     * The pivot column naming the entry a cardinality is counted against.
     *
     * Named concretely, like the invariant it protects: this class exists for
     * `entry_relations` and its docblock is about subject identifiers. A
     * second pivot with a different shape can generalise it then.
     */
    private const SOURCE_COLUMN = 'source_entry_id';

    /** @param  array<string, mixed>  $attributes */
    public function attach($id, array $attributes = [], $touch = true)
    {
        $this->versioned(
            fn (): array => $this->sourceKeys($id, $attributes),
            fn () => $this->serialised($id, $attributes, fn () => parent::attach($id, $attributes, $touch)),
        );
    }

    /** @param  array<string, mixed>  $attributes */
    public function updateExistingPivot($id, array $attributes, $touch = true)
    {
        return $this->versioned(
            fn (): array => $this->sourceKeys($id, $attributes),
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
            fn (): array => array_values(array_unique([...$this->detachSourceKeys(null), ...$this->sourceKeys($ids, [])])),
            fn () => parent::sync($ids, $detaching),
        );
    }

    /**
     * ⚠️ `toggle()` owns its version too, for the reason `sync()` does.
     *
     * Laravel's implementation reaches the overridden `attach()` and `detach()`
     * directly, so without an outer frame it recorded the intermediate detached
     * state and then the attached one — two or more versions for one API call,
     * and one of them a state the entry never meaningfully had. `sync()` was
     * wrapped for exactly this and `toggle()` was missed beside it.
     *
     * @param  mixed  $ids
     * @param  bool  $touch
     * @return array<string, list<mixed>>
     */
    public function toggle($ids, $touch = true)
    {
        return $this->versioned(
            // Both directions: toggling attaches and detaches, so the sources it
            // could touch are the union of what each would.
            fn (): array => array_values(array_unique([
                ...$this->detachSourceKeys(null),
                ...$this->sourceKeys($ids, []),
            ])),
            fn () => parent::toggle($ids, $touch),
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
            fn (): array => $this->detachSourceKeys($ids),
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
            return $this->freezingIncomingPivots();
        }

        return $this->sourceKeys($ids, []);
    }

    /**
     * Lock the pivots an unqualified incoming detach will remove, and constrain the
     * detach to exactly those rows.
     *
     * ⚠️ A SNAPSHOT was not enough, and this is the third time this project has
     * found the shape.
     *
     * `referencedBy()->detach()` named its sources with an unlocked `pluck()`, and
     * the inherited `detach()` then reran its own predicate — every pivot pointing
     * at the parent, evaluated when the DELETE ran. An attach from a NEW source
     * committing between those two statements was deleted by the detach, while that
     * source was never locked, never in `$before`, and never in a revision. Its
     * newest version claimed a relation the database no longer had.
     *
     * So the rows are frozen and the delete is pinned to the frozen ids. Anything
     * that arrives afterwards is simply not this statement's business — which is
     * the same contract `GuardedRelationBuilder::freezingRows()` provides, and the
     * reason that one pins `whereKey()` rather than trusting its predicate twice.
     *
     * ⚠️ Entries first and pivots second, in that order, because
     * `freezingRows()` does. The two paths reach the same rows, and if one took
     * pivots before entries they would deadlock against each other rather than
     * queue — which is the failure the previous lock-order fix was for.
     *
     * @return list<mixed>
     */
    private function freezingIncomingPivots(): array
    {
        $pivots = $this->getRelated()->getConnection()
            ->table($this->getTable())
            ->where($this->getForeignPivotKeyName(), $this->getParent()->getKey());

        // Unlocked discovery, then the entry locks in a deterministic order.
        $sources = (clone $pivots)->distinct()
            ->pluck(self::SOURCE_COLUMN)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($sources !== []) {
            Entry::withoutScopeBecause(
                'locking the source entries of an incoming detach before their pivots, so every '
                .'relation path takes the locks in one order',
                fn ($query) => $query->whereKey($sources)->lockForUpdate()->get(),
            );
        }

        // Now the pivots, under the entry locks just taken.
        $frozen = $pivots->lockForUpdate()->get(['id', self::SOURCE_COLUMN]);

        // ⚠️ Pin the delete to the frozen ids. `wherePivotIn` reaches
        // `newPivotQuery()`, which is what the inherited `detach()` builds from —
        // so the set frozen and the set deleted are the same set.
        //
        // An empty freeze still has to constrain: without this the inherited
        // predicate would run unpinned and delete whatever had arrived since.
        $this->wherePivotIn('id', $frozen->pluck('id')->all());

        return $frozen->pluck(self::SOURCE_COLUMN)->unique()->filter()->values()->all();
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
