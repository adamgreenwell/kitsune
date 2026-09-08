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
        // An instance save arrives here too, with its guards already run.
        if ($this->getModel()->guardsRan) {
            return parent::update($values);
        }

        $this->refuseGuardedColumns($values);

        return parent::update($values);
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
        $row = $this->newModelInstance($values);

        // ⚠️ SERIALISED on the source entry, exactly as `attach()` is.
        //
        // `guardCardinality()` counts and then inserts, which is two
        // statements: two concurrent `EntryRelation::create()` calls both
        // observed zero and both inserted into a cardinality-one nominated
        // field. `GuardedBelongsToMany` locks the source for `attach()`, and
        // adding this builder path re-opened the same race beside it — a fix
        // that created the hole it was modelled on.
        return DB::transaction(function () use ($row, $values, $sequence) {
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
        });
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
    public function increment($column, $amount = 1, array $extra = [])
    {
        $this->refuseGuardedColumns([(string) $column => $amount, ...$extra]);

        return parent::increment($column, $amount, $extra);
    }

    /**
     * @param  string|Expression  $column
     * @param  array<string, mixed>  $extra
     */
    public function decrement($column, $amount = 1, array $extra = [])
    {
        $this->refuseGuardedColumns([(string) $column => $amount, ...$extra]);

        return parent::decrement($column, $amount, $extra);
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function incrementEach(array $columns, array $extra = [])
    {
        $this->refuseGuardedColumns([...$columns, ...$extra]);

        return parent::incrementEach($columns, $extra);
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function decrementEach(array $columns, array $extra = [])
    {
        $this->refuseGuardedColumns([...$columns, ...$extra]);

        return parent::decrementEach($columns, $extra);
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
