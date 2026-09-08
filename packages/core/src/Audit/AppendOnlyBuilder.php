<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Audit;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Kitsune\Core\Models\AuditLog;
use RuntimeException;

/**
 * Refuses bulk rewrites, which model events never see.
 *
 * ⚠️ `updating` and `deleting` guards on the model cover instance mutations
 * only. `AuditLog::query()->update([...])` and `->delete()` compile straight
 * to SQL and fire nothing — so "append-only, enforced" was true of the path a
 * test exercises and false of the one-liner that rewrites the whole table.
 *
 * A claim that holds for the tested path and not the easy path is the failure
 * mode this project exists to avoid, so the guard moved to where the query is
 * built.
 *
 * @extends Builder<AuditLog>
 */
class AppendOnlyBuilder extends Builder
{
    private const APPEND_ONLY =
        'Audit rows are append-only (ADR-020). Record a new action instead of rewriting the trail.';

    /** @param  array<string, mixed>  $values */
    public function update(array $values)
    {
        throw new RuntimeException(
            'Audit rows are append-only, and a bulk update reaches more of them than a single '
            .'rewrite ever could. Record a new action instead (ADR-020).'
        );
    }

    public function delete()
    {
        throw new RuntimeException(
            'Audit rows are append-only and cannot be deleted. Retention is an operator policy '
            .'applied to the table, not something application code decides (ADR-020).'
        );
    }

    /**
     * ⚠️ Separately, because it does not go through `delete()`.
     *
     * Eloquent's `forceDelete()` calls the UNDERLYING query builder, so
     * neither the override above nor the model's `deleting` listener sees it
     * — a one-liner that erases audit evidence past two guards that both look
     * like they cover deletion.
     */
    public function forceDelete()
    {
        throw new RuntimeException(
            'Audit rows are append-only and cannot be force-deleted either. Retention is an '
            .'operator policy applied to the table (ADR-020).'
        );
    }

    /**
     * ⚠️ Every remaining mutator the builder exposes, refused together.
     *
     * `update()`, `delete()` and `forceDelete()` were overridden one at a
     * time as each was found, which is how `truncate()` survived three
     * rounds: Eloquent forwards it to the query builder, so it erased the
     * entire table with no override and no model event. `upsert()` rewrites
     * an existing row by key, and the increments move a value in place.
     *
     * Enumerated rather than left to the next review, because "append-only"
     * is a claim about EVERY path, and the ones nobody thought of are the
     * ones that make it false.
     */
    public function truncate(): void
    {
        throw new RuntimeException(self::APPEND_ONLY);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int, string>|null  $update
     * @return int
     */
    public function upsert(array $values, $uniqueBy, $update = null)
    {
        throw new RuntimeException(self::APPEND_ONLY);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $values
     * @return bool
     */
    public function updateOrInsert(array $attributes, array|callable $values = [])
    {
        throw new RuntimeException(self::APPEND_ONLY);
    }

    /**
     * @param  string|Expression  $column
     * @param  array<string, mixed>  $extra
     */
    public function increment($column, $amount = 1, array $extra = [])
    {
        throw new RuntimeException(self::APPEND_ONLY);
    }

    /**
     * @param  string|Expression  $column
     * @param  array<string, mixed>  $extra
     */
    public function decrement($column, $amount = 1, array $extra = [])
    {
        throw new RuntimeException(self::APPEND_ONLY);
    }

    /**
     * ⚠️ The plural forms are separate methods on the query builder, so
     * refusing the singular ones left the multi-column variants forwarding
     * straight through — the same omission as truncate(), one API along.
     *
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function incrementEach(array $columns, array $extra = [])
    {
        throw new RuntimeException(self::APPEND_ONLY);
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function decrementEach(array $columns, array $extra = [])
    {
        throw new RuntimeException(self::APPEND_ONLY);
    }

    /**
     * ⚠️ PostgreSQL exposes this as a separate mutation, so neither builder
     * saw it: `AuditLog::query()->updateFrom([...])` could rewrite the
     * evidence through a join.
     *
     * @param  array<string, mixed>  $values
     * @return int
     */
    public function updateFrom(array $values)
    {
        throw new RuntimeException(self::APPEND_ONLY);
    }

    /**
     * @param  array<int, string>|string|null  $column
     * @return bool|int
     */
    public function touch($column = null)
    {
        throw new RuntimeException(self::APPEND_ONLY);
    }
}
