<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Schema;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Tenancy\Concerns\ResolvesWrittenColumns;
use Kitsune\Core\Tenancy\Concerns\TouchesThroughUpdate;
use RuntimeException;

/**
 * Every ADR-006 and ADR-020 guarantee on FieldStorage, on the paths a model
 * event cannot see.
 *
 * ⚠️ `FieldStorage` had no builder at all, while `Entry` and `AuditLog` were
 * both given one for exactly this reason. So the guards that say a locked
 * field's shape "cannot change" and that an unclassified field "does not
 * save" were true of the row-at-a-time path and of nothing else:
 *
 *   FieldStorage::query()->whereKey($id)->update(['handle' => 'cost'])
 *
 * renamed a LOCKED field's JSON key outright, leaving every stored value under
 * the old key where nothing reads it — the exact outcome SHAPE_ATTRIBUTES
 * documents and, in its own words, "there is no error to notice". Verified by
 * probe, along with `createQuietly()` persisting `pii_class` NULL.
 *
 * The bulk idiom is not exotic in this codebase either. `Entry::lockStorage
 * HoldingData()` and `EntryRelation::armLock()` both write this model that
 * way, *because* it skips the listener — which is what made the hole easy to
 * reach and easy to miss.
 *
 * ⚠️ A COLUMN IS WHAT THE DATABASE WRITES, NOT WHAT THE CALLER TYPED. This class compared each written name
 * exactly against its own list, by a private copy of the rule `ScopedBuilder` had already corrected, while SQLite,
 * MySQL and MariaDB match column names without regard to case — so every refusal below had a second spelling that
 * walked past it. Measured on all three before the fix: `update(['HANDLE' => 'cost'])` renamed a locked field,
 * `update(['IS_LOCKED' => false])` cleared its lock, `increment('CARDINALITY')` resized it, and `update(['ORG_ID'
 * => $rival])` moved it into another org. A JSON path never matched at all, spelled any way: `update(['settings->
 * format' => 'integer'])` moved a locked field's projection. And a genuine save walked past the model's own hook,
 * which reads each attribute by its name: `$storage->update(['IS_LOCKED' => false])` passed `guardShape()` on the
 * untouched `is_locked` and the engine cleared the lock. The comparison is `ResolvesWrittenColumns` now, shared
 * with every guarded builder, so it cannot drift from theirs again.
 *
 * @extends Builder<FieldStorage>
 */
class GuardedStorageBuilder extends Builder
{
    use ResolvesWrittenColumns {
        bareColumn as private;
        refuseAmbiguousColumns as private;
        refuseMisnamedGuardedColumn as private;
    }
    use TouchesThroughUpdate;

    /**
     * Columns whose guards are PER-ROW and so cannot be evaluated in bulk.
     *
     * A bulk update sees one set of values and any number of rows, each with
     * its own lock state, type and projection. There is no correct answer to
     * give it, so it is refused rather than approximated.
     */
    private const PER_ROW = ['type', 'cardinality', 'handle', 'settings', 'pii_class', 'org_id'];

    /**
     * Every column `FieldStorage::guardShape()` reads by name: the per-row columns and the lock it compares them
     * against. A write that stands behind that method writes each of these under exactly its own name.
     */
    private const READ_BY_THE_GUARDS = [...self::PER_ROW, 'is_locked'];

    private const NO_BULK_CREATE =
        'Field storage cannot be created in bulk: `pii_class` fails closed per row and these paths '
        .'dispatch nothing, so an unclassified field would persist — which ADR-020 says cannot '
        .'exist. Use create().';

    /** @param  array<string, mixed>  $values */
    public function update(array $values)
    {
        $this->refuseAmbiguousColumns($values);

        // ⚠️ An instance save arrives here too — `Model::performUpdate()`
        // writes through the builder — so the proof is what separates a save
        // whose guards have already run from a bulk write that dispatched
        // nothing and never could. It names THIS builder, so a model armed by
        // hand and handed to a query of its own presents nothing.
        if ($this->getModel()->shapeGuardedFor($this)) {
            // ⚠️ Under the names they read, or not at all: `IS_LOCKED` beside an untouched `is_locked`
            // passed `guardShape()` and cleared the lock.
            $this->refuseMisnamedColumns($values);

            return parent::update($values);
        }

        $this->refuseGuardedColumns($values);

        return parent::update($values);
    }

    /**
     * ⚠️ Where a quiet create is caught. `createQuietly()` and anything inside
     * `withoutEvents()` suppress the `saving` listener while still inserting
     * through here, so an unclassified row persisted.
     *
     * @param  array<string, mixed>  $values
     * @param  string|null  $sequence
     * @return int
     */
    public function insertGetId(array $values, $sequence = null)
    {
        // ⚠️ The model below is built from these names verbatim, and `guardShape()` reads it by attribute:
        // `SETTINGS` left `settings` empty, the check passed on nothing, and the engine stored the settings
        // a text field refuses. Measured through `create()`, `createQuietly()` and a hand-rolled insert alike.
        $this->refuseAmbiguousColumns($values);
        $this->refuseMisnamedColumns($values);

        // ⚠️ setRawAttributes, NOT newModelInstance($values).
        //
        // These values are already database-ready: a JSON-cast attribute arrives
        // ENCODED, and passing it through `fill()` runs `setAttribute()` again and
        // encodes it a second time. The guard then reads a JSON string where it
        // expects an array — so `FieldStorage::guardProjectionSettings()` was
        // comparing `(array) '{"...}"'` against a real array on every insert and
        // silently agreeing with itself. Nothing failed, because nothing read the
        // value as an array until a settings check did.
        //
        // `setRawAttributes()` stores them as given and leaves `exists` false,
        // which is what the guards branch on — `newFromBuilder()` would fix the
        // casts and break that instead.
        $model = $this->newModelInstance();
        $model->setRawAttributes($values);
        $model->guardShape();

        return parent::insertGetId($values, $sequence);
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
            'updateFrom() writes through a join, so the per-row guards cannot be evaluated at all '
            .'(ADR-006). Save the model instead.'
        );
    }

    /**
     * ⚠️ AND `truncate()`, WHICH THIS CLASS WAS SAID NOT TO NEED because it guards creation and truncating creates
     * nothing. What a truncate REMOVES was the question nobody asked. `field_storage` is unscoped, and `fields`
     * references it with `ON DELETE CASCADE`: from one org's context, `FieldStorage::query()->truncate()` emptied
     * every org's field storage and every org's fields on SQLite. On PostgreSQL Laravel compiles it as `TRUNCATE …
     * RESTART IDENTITY CASCADE`, which follows every foreign key into the table rather than the cascading ones, and
     * it emptied every org's entry types, entries and revisions as well — with no audit row. Measured on both. MySQL
     * and MariaDB refuse it themselves (error 1701), after committing the caller's open transaction.
     *
     * Refused outright, as `ScopedBuilder`, `AuditedBuilder`, `AppendOnlyBuilder` and `GuardedRelationBuilder`
     * refuse it. A predicate delete names the rows it removes, and stays the way to remove one.
     */
    public function truncate(): void
    {
        throw new RuntimeException(
            'Truncating field_storage would remove every org\'s fields at once — the table is shared and unscoped — '
            .'and on PostgreSQL the truncate cascades into every table that references it, entries included '
            .'(ADR-006, ADR-021). Delete the rows you mean through a predicate.'
        );
    }

    /**
     * ⚠️ The increments can move `cardinality`, which is a shape attribute,
     * and they never reach update() where that is checked — but they must
     * still ADD.
     *
     * Routing them through `update()` was wrong: that assigns, so incrementing
     * a value of 10 by 2 produced 2 rather than 12. Guarded columns are
     * refused and everything else delegates to the parent arithmetic.
     *
     * @param  string|Expression  $column
     * @param  array<string, mixed>  $extra
     */
    public function increment($column, $amount = 1, array $extra = [])
    {
        $this->refuseGuardedColumns($extra, [(string) $column => $amount]);

        return parent::increment($column, $amount, $extra);
    }

    /**
     * @param  string|Expression  $column
     * @param  array<string, mixed>  $extra
     */
    public function decrement($column, $amount = 1, array $extra = [])
    {
        $this->refuseGuardedColumns($extra, [(string) $column => $amount]);

        return parent::decrement($column, $amount, $extra);
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function incrementEach(array $columns, array $extra = [])
    {
        $this->refuseGuardedColumns($extra, $columns);

        return parent::incrementEach($columns, $extra);
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function decrementEach(array $columns, array $extra = [])
    {
        $this->refuseGuardedColumns($extra, $columns);

        return parent::decrementEach($columns, $extra);
    }

    /**
     * Refuse any column whose guard is per-row, and any bulk write to the lock but arming it.
     *
     * @param  array<string, mixed>  $assigned  Columns the write sets to a value.
     * @param  array<string, mixed>  $added  Columns the arithmetic doors add an AMOUNT to — not a value.
     */
    private function refuseGuardedColumns(array $assigned, array $added = []): void
    {
        $this->refuseAmbiguousColumns([...$added, ...$assigned]);

        foreach ($added as $column => $amount) {
            $bare = $this->bareColumn((string) $column);

            // ⚠️ AN AMOUNT IS NOT A DESTINATION, so there is nothing to judge: `decrement('is_locked')` cleared a lock
            // on SQLite, MySQL and MariaDB while the value check below never ran. `ScopedBuilder` refuses arithmetic
            // on a scope key for the same reason.
            if ($bare === 'is_locked') {
                throw new RuntimeException(
                    'A lock cannot be incremented or decremented: an amount is not a value, and no amount added to '
                    .'the record that data exists is one this builder can vouch arms it rather than clears it '
                    .'(ADR-006). Arm it with update([\'is_locked\' => true]).'
                );
            }

            $this->refusePerRowColumn($bare);
        }

        foreach ($assigned as $column => $value) {
            $bare = $this->bareColumn((string) $column);

            // ⚠️ The ONE bulk write that is both needed and safe: arming the
            // lock. `lockStorageHoldingData()` and `armLock()` do exactly this
            // and nothing else, and setting it true cannot invalidate content.
            // Clearing it in bulk is the thing that made every guard optional.
            //
            // ⚠️ ARMING IS A VALUE THAT CAN ONLY MEAN TRUE, NOT ONE PHP READS AS TRUE. The check was `(bool) $value
            // === false`, and an `Expression`, `'00'`, `'0.0'`, `' 0'`, `'-0'` and `'0e0'` are all true to PHP while
            // SQLite, MySQL and MariaDB store them as 0; PostgreSQL stores `'false'`, `'off'`, `'no'` and `'f'` as
            // false. Each cleared a locked field's lock — measured, and an ordinary save then renamed the field. So
            // `true`, `1` and `'1'` arm it, and everything else is refused, whatever it would have stored.
            if ($bare === 'is_locked') {
                if ($value !== true && $value !== 1 && $value !== '1') {
                    throw new RuntimeException(
                        'A lock cannot be cleared in bulk, and a bulk write may only arm it — with true, 1 or \'1\', '
                        .'the values no engine stores as false. It is the record that data exists, not a preference, '
                        .'and clearing it here would skip every shape guard behind it (ADR-006).'
                    );
                }

                continue;
            }

            $this->refusePerRowColumn($bare);
        }
    }

    private function refusePerRowColumn(string $bare): void
    {
        if (in_array($bare, self::PER_ROW, true)) {
            throw new RuntimeException(sprintf(
                'Field storage [%s] cannot be written in bulk: its guards depend on the row — '
                .'the lock state, the type, and the projection the settings produce. A bulk '
                .'write sees one set of values and any number of rows (ADR-006). Save the model '
                .'instead.',
                $bare,
            ));
        }
    }

    /**
     * Refuse a column the guards read by name, written under any other.
     *
     * @param  array<string, mixed>  $values
     */
    private function refuseMisnamedColumns(array $values): void
    {
        foreach (array_keys($values) as $written) {
            $column = $this->bareColumn((string) $written);

            if (in_array($column, self::READ_BY_THE_GUARDS, true)) {
                $this->refuseMisnamedGuardedColumn((string) $written, $column);
            }
        }
    }
}
