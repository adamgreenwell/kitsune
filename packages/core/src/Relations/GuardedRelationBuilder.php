<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Relations;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRelation;
use RuntimeException;

/**
 * The pivot's safety story, on the paths a model event cannot see.
 *
 * ⚠️ `guardStorageOwnership()`, `guardEndpointsVisible()`,
 * `guardCardinality()`, `guardTargetType()` and `armLock()` all hang off
 * `creating` / `updating` / `updated`. `EntryRelation::query()->insert()` and
 * `->update()` compile straight to SQL and dispatch nothing, so on those paths
 * there were no guards at all:
 *
 *   EntryRelation::query()->insert([... 'field_storage_id' => $nominated ...])
 *
 * put a SECOND target on a cardinality-one nominated subject field, of a type
 * that field forbids — the two-subject disclosure ADR-020's cardinality check
 * exists to prevent, reached with one ordinary Eloquent statement. The update
 * form repointed two existing rows onto that field just as easily.
 *
 * This is the third model here to need a builder for the same reason.
 * `AuditLog` got `AppendOnlyBuilder` and `Entry` got `AuditedBuilder`, both
 * for "bulk writes fire no events"; `FieldStorage` and this one went without,
 * while core itself already writes this table in bulk —
 * `Entry::redactField()` deletes through it.
 *
 * @extends Builder<EntryRelation>
 */
class GuardedRelationBuilder extends Builder
{
    use RecordsRelationRevisions;

    /**
     * Columns whose guards are PER-ROW, so a bulk write cannot evaluate them.
     *
     * A bulk update sees one set of values and any number of rows, each with
     * its own source, target, field and lock consequences.
     */
    private const PER_ROW = ['source_entry_id', 'target_entry_id', 'field_storage_id', 'org_id'];

    private const NO_BULK_CREATE =
        'Relation rows cannot be created in bulk: cardinality, target type, endpoint visibility '
        .'and storage ownership are all checked per row, and these paths dispatch nothing — so a '
        .'second target could land on a nominated single-valued field, of a type it forbids '
        .'(ADR-020). Attach through the relationship.';

    /** @param  array<string, mixed>  $values */
    public function update(array $values)
    {
        // ⚠️ Versioned, because this path CHANGES an entry's relations.
        //
        // `ordering` is explicitly permitted here, and order is part of what a
        // revision records — restoring the right targets in the wrong sequence has
        // still lost the version. Nothing else recorded it: the revision recorder
        // lived only on `GuardedBelongsToMany`, so the ordinary Eloquent surface
        // changed relational content and left the newest revision stale.
        // ⚠️ Both ENDS of a move. Assigning a new `source_entry_id` on a loaded
        // `EntryRelation` and saving it sets `guardsRan`, so the update is
        // permitted — and the frozen rows report only the row's OLD source. The
        // destination was therefore neither locked nor versioned: its newest
        // revision stayed stale, and its cardinality was counted before any lock
        // on it, so concurrent moves could both land on a single-valued relation.
        // ⚠️ Resolved BEFORE the sources callable runs, and handed INTO the freeze
        // rather than appended after it.
        //
        // Appending it was still a deterministic deadlock: `freezingRows()` locks
        // the old source and then the pivot, so a move A→B held A and its pivot
        // before anything asked for B. The reciprocal move B→A held B and its
        // pivot and then asked for A — each waiting on the entry the other already
        // had. The previous fix put the destination in the lock SET without putting
        // it in the lock ORDER, which is the half that mattered.
        //
        // Inside `freezingRows()` it joins the sorted entry lock taken before any
        // pivot, so both moves acquire {A, B} in the same order and one waits for
        // the other instead of deadlocking. Lock order is a property of the whole
        // system, not of one method.
        $destination = isset($values['source_entry_id']) ? [$values['source_entry_id']] : [];

        return $this->versioned(
            fn (): array => $this->freezingRows($destination),
            function () use ($values) {
                // An instance save arrives here too, with its guards already run.
                if ($this->getModel()->guardsRan) {
                    // ⚠️ But cardinality is RE-CHECKED here, under the lock.
                    //
                    // `EntryRelation::updating` counted before `save()` reached
                    // this builder, and the destination lock is taken by the frame
                    // around this closure — so two concurrent moves onto the same
                    // cardinality-one (source, field) both counted zero, then
                    // serialised on the lock, and both wrote. A count taken before
                    // a lock is a count of the past.
                    $this->getModel()->guardCardinality();

                    return parent::update($values);
                }

                $this->refuseGuardedColumns($values);

                return parent::update($values);
            },
        );
    }

    /**
     * ⚠️ `delete()` too, and it was not overridden at all.
     *
     * It needed no guard — removing a relation can only relax a cardinality — but
     * it very much changes the entry, and `Entry::redactField()` erases through
     * exactly this path.
     *
     * @return int
     */
    public function delete()
    {
        return $this->versioned($this->freezingRows(...), fn () => parent::delete());
    }

    /**
     * Freeze the rows this statement matches, and report their source entries.
     *
     * ⚠️ Called INSIDE the versioning transaction, and it constrains the
     * statement to the rows it saw.
     *
     * Reading the sources beforehand left a window: `parent::update()` reruns the
     * original predicate, so a concurrent attach could create a matching pivot
     * under a source that had been neither locked nor included in the recording —
     * and the statement wrote it anyway. Capturing the ids under a lock and
     * writing against THOSE ids is the pattern `AuditedBuilder` already uses for
     * entries, and for the same reason: the set audited and the set written have
     * to be the same set.
     *
     * ⚠️ ENTRY locks are taken before the pivot rows, and doing it the other way
     * round deadlocked. `restoreRevision()` and `GuardedBelongsToMany` both lock
     * the source entry first and then reach its pivots; this locked the pivot and
     * then waited for the entry, so two interleaved operations each held what the
     * other needed and one was aborted — and neither retries. Lock ORDER is a
     * property of the whole system, not of one method, so this one conforms.
     *
     * The discovery read is deliberately unlocked. It only has to name candidate
     * sources; a pivot inserted after it belongs to a transaction that locked its
     * own source first, so it is outside the frozen set and left untouched —
     * which is exactly the guarantee constraining the write to frozen ids gives.
     *
     * @return list<mixed>
     */
    /**
     * Lock every entry this statement can touch, then its pivots, and report which
     * entries need a revision.
     *
     * @param  list<int|string>  $also  Entries the statement will touch that the
     *                                  CURRENT rows do not name — a move's
     *                                  destination. They join the sorted lock
     *                                  below rather than being locked after it,
     *                                  because a lock set without a lock order is
     *                                  what deadlocks.
     * @return list<int|string>
     */
    private function freezingRows(array $also = []): array
    {
        // Unlocked discovery: which entries might this statement touch?
        $sources = (clone $this)->toBase()
            ->distinct()
            ->pluck('entry_relations.source_entry_id')
            ->filter()
            ->merge($also)
            ->unique()
            // Sorted, so concurrent writers over the same pair take the locks in
            // the same order and cannot deadlock against each other either.
            ->sort()
            ->values()
            ->all();

        if ($sources !== []) {
            Entry::withoutScopeBecause(
                'locking the source entries before their pivots, so every relation path takes the '
                .'locks in one order',
                fn ($query) => $query->whereKey($sources)->lockForUpdate()->get(),
            );
        }

        // Now the pivots, under the entry locks that were just taken.
        $rows = $this->toBase()->lockForUpdate()->get(['id', 'source_entry_id']);

        // whereKey qualifies the column, so this stays unambiguous even when the
        // caller joined another table.
        $this->whereKey($rows->pluck('id')->all());

        // ⚠️ And the PAGINATION goes, because it has already been spent.
        //
        // `orderBy('id')->offset(1)->limit(1)->delete()` froze the second row and
        // then reapplied offset 1 to that singleton, so the statement touched
        // nothing while the version was recorded anyway. `AuditedBuilder` learned
        // this on the entry side; the set frozen and the set written have to be
        // the same set, and a limit that already chose the rows must not choose
        // among them again.
        $base = $this->getQuery();
        $base->offset = null;
        $base->limit = null;

        // ⚠️ The destination is reported as well as locked. It is not among the
        // frozen rows' current sources — that is the whole point of a move — and a
        // revision it never appears in leaves its newest version stale.
        return $rows->pluck('source_entry_id')->merge($also)->unique()->filter()->values()->all();
    }

    /**
     * ⚠️ Where a quiet create is caught, and where `attach()` lands.
     *
     * @param  array<string, mixed>  $values
     * @param  string|null  $sequence
     * @return int
     */
    public function insertGetId(array $values, $sequence = null)
    {
        // ⚠️ setRawAttributes, for the reason spelled out in
        // `GuardedStorageBuilder::insertGetId()`: these values are already
        // database-ready, and `fill()` would re-encode any JSON-cast attribute.
        // `entry_relations` has none today, which is exactly why this would have
        // been a silent trap the first time one was added.
        $row = $this->newModelInstance();
        $row->setRawAttributes($values);

        // ⚠️ SERIALISED on the source entry, exactly as `attach()` is.
        //
        // `guardCardinality()` counts and then inserts, which is two
        // statements: two concurrent `EntryRelation::create()` calls both
        // observed zero and both inserted into a cardinality-one nominated
        // field. `GuardedBelongsToMany` locks the source for `attach()`, and
        // adding this builder path re-opened the same race beside it — a fix
        // that created the hole it was modelled on.
        return $this->versioned(fn (): array => [$row->source_entry_id], fn () => DB::transaction(function () use ($row, $values, $sequence) {
            Entry::withoutScopeBecause(
                'locking the source entry so the cardinality count cannot interleave',
                fn ($query) => $query->whereKey($row->source_entry_id)->lockForUpdate()->get(),
            );

            $row->guardCreate();

            $id = parent::insertGetId($values, $sequence);

            // The `created` event arms the lock on the ordinary path; this
            // covers a quiet create, which suppresses it while still inserting.
            $row->forceFill([$row->getKeyName() => $id])->armLockNow();

            return $id;
        }));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return bool
     */
    public function insert(array $values)
    {
        throw new RuntimeException(self::NO_BULK_CREATE);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return int
     */
    public function insertOrIgnore(array $values)
    {
        throw new RuntimeException(self::NO_BULK_CREATE);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<int, string>  $returning
     * @param  array<int, string>|string|null  $uniqueBy
     * @return array<int, mixed>
     */
    public function insertOrIgnoreReturning(array $values, array $returning = ['*'], array|string|null $uniqueBy = null)
    {
        throw new RuntimeException(self::NO_BULK_CREATE);
    }

    /**
     * @param  \Closure|\Illuminate\Database\Query\Builder|Builder<*>|string  $query
     * @param  array<int, string>  $columns
     * @return int
     */
    public function insertUsing(array $columns, $query)
    {
        throw new RuntimeException(self::NO_BULK_CREATE);
    }

    /**
     * @param  \Closure|\Illuminate\Database\Query\Builder|Builder<*>|string  $query
     * @param  array<int, string>  $columns
     * @return int
     */
    public function insertOrIgnoreUsing(array $columns, $query)
    {
        throw new RuntimeException(self::NO_BULK_CREATE);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int, string>|null  $update
     * @return int
     */
    public function upsert(array $values, $uniqueBy, $update = null)
    {
        throw new RuntimeException(self::NO_BULK_CREATE);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $values
     * @return bool
     */
    public function updateOrInsert(array $attributes, array|callable $values = [])
    {
        throw new RuntimeException(self::NO_BULK_CREATE);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return int
     */
    public function updateFrom(array $values)
    {
        throw new RuntimeException(
            'updateFrom() writes through a join, so the per-row guards cannot be evaluated at all. '
            .'Move the row through updateExistingPivot().'
        );
    }

    /**
     * ⚠️ The increments reach the query builder directly, so they could move a
     * guarded column without passing update() — but they must still ADD.
     *
     * Routing them through `update()` was wrong: that assigns, so incrementing
     * an `ordering` of 10 by 2 produced 2 rather than 12, and `decrement()`
     * assigned a positive amount. Guarded columns are refused and everything
     * else delegates to the parent arithmetic.
     *
     * @param  string|Expression  $column
     * @param  array<string, mixed>  $extra
     */
    /**
     * ⚠️ Versioned like every other write here, and these four were not.
     *
     * `ordering` is the sequence `relationState()` snapshots, so
     * `EntryRelation::query()->increment('ordering')` reorders an entry's
     * relations — changing what a revision would record — while taking no source
     * lock and filing no version. Restoring the latest revision then silently
     * undid the reorder.
     */
    public function increment($column, $amount = 1, array $extra = [])
    {
        $this->refuseGuardedColumns([(string) $column => $amount, ...$extra]);

        return $this->versioned($this->freezingRows(...), fn () => parent::increment($column, $amount, $extra));
    }

    /**
     * @param  string|Expression  $column
     * @param  array<string, mixed>  $extra
     */
    public function decrement($column, $amount = 1, array $extra = [])
    {
        $this->refuseGuardedColumns([(string) $column => $amount, ...$extra]);

        return $this->versioned($this->freezingRows(...), fn () => parent::decrement($column, $amount, $extra));
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function incrementEach(array $columns, array $extra = [])
    {
        $this->refuseGuardedColumns([...$columns, ...$extra]);

        return $this->versioned($this->freezingRows(...), fn () => parent::incrementEach($columns, $extra));
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function decrementEach(array $columns, array $extra = [])
    {
        $this->refuseGuardedColumns([...$columns, ...$extra]);

        return $this->versioned($this->freezingRows(...), fn () => parent::decrementEach($columns, $extra));
    }

    /**
     * Deletion is deliberately ALLOWED in bulk.
     *
     * Removing a relation cannot violate a cardinality bound or a target-type
     * constraint — it can only relax them — and `Entry::redactField()` deletes
     * through this builder because erasure has to reach a row whatever org
     * stamped it.
     */
    public function truncate(): void
    {
        throw new RuntimeException(
            'Truncating entry_relations would detach every relation in every org at once. Delete '
            .'through a predicate instead.'
        );
    }

    /**
     * Refuse any column whose guard is per-row.
     *
     * @param  array<string, mixed>  $values
     */
    private function refuseGuardedColumns(array $values): void
    {
        foreach (array_keys($values) as $column) {
            $bare = $this->bareColumn((string) $column);

            if (in_array($bare, self::PER_ROW, true)) {
                throw new RuntimeException(sprintf(
                    'Relation column [%s] cannot be written in bulk: moving a row between sources, '
                    .'targets or fields is checked per row against cardinality, target type and '
                    .'visibility, and a bulk write dispatches none of that (ADR-020). Move the row '
                    .'through updateExistingPivot().',
                    $bare,
                ));
            }
        }
    }

    /** Strip table qualification and quoting, so `er`.`org_id` is `org_id`. */
    private function bareColumn(string $column): string
    {
        $bare = str_contains($column, '.')
            ? substr($column, (int) strrpos($column, '.') + 1)
            : $column;

        return trim($bare, '`"[]');
    }
}
