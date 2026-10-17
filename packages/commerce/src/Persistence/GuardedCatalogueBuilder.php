<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Persistence;

use Illuminate\Support\Collection;
use Kitsune\Commerce\Catalogue\CatalogueWriter;
use Kitsune\Commerce\Models\Listing;
use Kitsune\Commerce\Models\Sellable;
use Kitsune\Core\Tenancy\ScopedBuilder;
use RuntimeException;

/**
 * Evented instance saves only, with final raw-value checks after every observer. Neither an escape hatch nor a
 * writer's call stack relaxes this rule. Raw query-builder writes remain below the ORM trust boundary.
 *
 * @internal
 *
 * @template TModel of Sellable|Listing
 *
 * @extends ScopedBuilder<TModel>
 */
final class GuardedCatalogueBuilder extends ScopedBuilder
{
    /** @param array<string, mixed> $values */
    public function update(array $values)
    {
        $this->guardSave($values);

        // Eloquent omits an unchanged same-second timestamp from its dirty attributes. Its builder would
        // then generate a second timestamp after our locking validation, possibly in the next second,
        // leaving the saved model different from SQL. Preserve the timestamp chosen by the instance save.
        $model = $this->getModel();
        $column = $model->getUpdatedAtColumn();
        if ($model->usesTimestamps() && $column !== null && ! array_key_exists($column, $values)) {
            $values[$column] = $model->getAttributes()[$column];
        }

        return parent::update($values);
    }

    /** @param array<string, mixed> $values */
    public function insertGetId(array $values, $sequence = null)
    {
        $this->guardSave($values);

        return parent::insertGetId($values, $sequence);
    }

    /** @param array<string, mixed> $values */
    public function insert(array $values): bool
    {
        $this->refuse('insert');
    }

    /** @param array<string, mixed> $values */
    public function insertOrIgnore(array $values): int
    {
        $this->refuse('insertOrIgnore');
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<int, string>  $returning
     * @param  array<int, string>|string|null  $uniqueBy
     * @return Collection<int, mixed>
     */
    public function insertOrIgnoreReturning(array $values, array $returning = ['*'], array|string|null $uniqueBy = null): Collection
    {
        $this->refuse('insertOrIgnoreReturning');
    }

    /** @param array<int, string> $columns */
    public function insertUsing(array $columns, $query): int
    {
        $this->refuse('insertUsing');
    }

    /** @param array<int, string> $columns */
    public function insertOrIgnoreUsing(array $columns, $query): int
    {
        $this->refuse('insertOrIgnoreUsing');
    }

    /** @param array<int|string, mixed> $values */
    public function upsert(array $values, $uniqueBy, $update = null)
    {
        $this->refuse('upsert');
    }

    public function delete()
    {
        if (! CatalogueWriter::isRemovingThrough($this->getModel(), $this)) {
            $this->refuse('delete');
        }

        return parent::delete();
    }

    public function forceDelete()
    {
        $this->refuse('forceDelete');
    }

    /** @param array<string, mixed> $values */
    protected function guardArithmetic(array $values): void
    {
        $this->refuse('arithmetic');
    }

    /** @param array<string, mixed> $values */
    private function guardSave(array $values): void
    {
        $model = $this->getModel();

        if (! $model->isPerformingModelSave($this)) {
            $this->refuse('a bulk or quiet save');
        }

        foreach ($values as $column => $value) {
            if ($column !== $this->bareColumn($column)) {
                throw new RuntimeException('Catalogue writes require canonical column names.');
            }
        }

        $model->refuseInvalidCatalogueValues();

        if (! $model->guardedColumnsAreDerived()) {
            $this->refuse('a bulk or quiet save');
        }
    }

    private function refuse(string $method): never
    {
        throw new RuntimeException("Catalogue {$method} is refused; save an evented model or use CatalogueWriter.");
    }
}
