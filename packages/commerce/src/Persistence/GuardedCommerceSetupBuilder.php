<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Persistence;

use Illuminate\Support\Collection;
use Kitsune\Commerce\Models\CommerceSettings;
use Kitsune\Commerce\Models\SalesPolicy;
use Kitsune\Commerce\Models\TaxRate;
use Kitsune\Core\Tenancy\ScopedBuilder;
use RuntimeException;

/**
 * Evented instance saves only, with final raw-value checks after every observer. Neither an escape hatch nor a
 * writer's call stack relaxes this rule. Raw query-builder writes remain below the ORM trust boundary.
 *
 * @internal
 *
 * @template TModel of CommerceSettings|TaxRate|SalesPolicy
 *
 * @extends ScopedBuilder<TModel>
 */
final class GuardedCommerceSetupBuilder extends ScopedBuilder
{
    /** @param array<string, mixed> $values */
    public function update(array $values)
    {
        $this->guardSave($values);

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
        if (! $this->getModel()->isPerformingSetupDelete($this)) {
            $this->refuse('delete');
        }

        $this->getModel()->refuseInvalidSetupRemoval();

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
                throw new RuntimeException('Commerce configuration writes require canonical column names.');
            }
        }

        $model->refuseInvalidSetupValues();

        if (! $model->guardedColumnsAreDerived()) {
            $this->refuse('a bulk or quiet save');
        }
    }

    private function refuse(string $method): never
    {
        throw new RuntimeException("Commerce configuration {$method} is refused; use an evented instance or CommerceSetupWriter.");
    }
}
