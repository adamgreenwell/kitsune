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
 * @extends Builder<FieldStorage>
 */
class GuardedStorageBuilder extends Builder
{
    /**
     * Columns whose guards are PER-ROW and so cannot be evaluated in bulk.
     *
     * A bulk update sees one set of values and any number of rows, each with
     * its own lock state, type and projection. There is no correct answer to
     * give it, so it is refused rather than approximated.
     */
    private const PER_ROW = ['type', 'cardinality', 'handle', 'settings', 'pii_class', 'org_id'];

    private const NO_BULK_CREATE =
        'Field storage cannot be created in bulk: `pii_class` fails closed per row and these paths '
        .'dispatch nothing, so an unclassified field would persist — which ADR-020 says cannot '
        .'exist. Use create().';

    /** @param  array<string, mixed>  $values */
    public function update(array $values)
    {
        // ⚠️ An instance save arrives here too — `Model::performUpdate()`
        // writes through the builder — so the flag is what separates a save
        // whose guards have already run from a bulk write that dispatched
        // nothing and never could.
        if ($this->getModel()->shapeGuarded) {
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
     * Refuse any column whose guard is per-row.
     *
     * @param  array<string, mixed>  $values
     */
    private function refuseGuardedColumns(array $values): void
    {
        foreach ($values as $column => $value) {
            $bare = $this->bareColumn((string) $column);

            // ⚠️ The ONE bulk write that is both needed and safe: arming the
            // lock. `lockStorageHoldingData()` and `armLock()` do exactly this
            // and nothing else, and setting it true cannot invalidate content.
            // Clearing it in bulk is the thing that made every guard optional.
            if ($bare === 'is_locked') {
                if ((bool) $value === false) {
                    throw new RuntimeException(
                        'A lock cannot be cleared in bulk. It is the record that data exists, not a '
                        .'preference, and clearing it here would skip every shape guard behind it '
                        .'(ADR-006).'
                    );
                }

                continue;
            }

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
    }

    /** Strip any table qualification and quoting, so `fs`.`handle` is `handle`. */
    private function bareColumn(string $column): string
    {
        $bare = str_contains($column, '.')
            ? substr($column, (int) strrpos($column, '.') + 1)
            : $column;

        return trim($bare, '`"[]');
    }
}
