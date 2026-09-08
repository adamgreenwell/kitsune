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
use Kitsune\Core\Tenancy\Context;
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
    private const NO_SUBQUERY_APPEND =
        'Audit rows cannot be appended from a subquery: the values are never seen here, so the org '
        .'they claim cannot be checked, and a trail an outsider can write to is worse than no '
        .'trail (ADR-020).';

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

    /**
     * ⚠️ EVERY insert path, not just `insert()`.
     *
     * `insertOrIgnore()`, `insertUsing()` and their siblings are forwarded
     * straight to the query builder, so guarding `insert()` alone left them
     * open — and a non-conflicting row carrying a rival `org_id` appended
     * forged evidence to that org's trail. Being appendable is not the same as
     * being unguarded, and "inserts are allowed" was doing the work of both.
     *
     * The two `Using` forms take a SUBQUERY rather than values, so there are no
     * scope keys to check here at all — they are refused instead. Appending
     * rows selected by a query nobody validated is not something this log has
     * a use for.
     *
     * @param  array<string, mixed>  $values
     * @return int
     */
    public function insertOrIgnore(array $values)
    {
        $this->guardScopeKeys($values);

        return parent::insertOrIgnore($values);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<int, string>  $returning
     * @param  array<int, string>|string|null  $uniqueBy
     * @return mixed
     */
    public function insertOrIgnoreReturning(array $values, array $returning = ['*'], array|string|null $uniqueBy = null)
    {
        $this->guardScopeKeys($values);

        return parent::insertOrIgnoreReturning($values, $returning, $uniqueBy);
    }

    /**
     * @param  \Closure|\Illuminate\Database\Query\Builder|Builder<*>|string  $query
     * @param  array<int, string>  $columns
     * @return int
     */
    public function insertUsing(array $columns, $query)
    {
        throw new RuntimeException(self::NO_SUBQUERY_APPEND);
    }

    /**
     * @param  \Closure|\Illuminate\Database\Query\Builder|Builder<*>|string  $query
     * @param  array<int, string>  $columns
     * @return int
     */
    public function insertOrIgnoreUsing(array $columns, $query)
    {
        throw new RuntimeException(self::NO_SUBQUERY_APPEND);
    }

    /**
     * ⚠️ Append-only allows INSERT, and that is the path nobody guarded.
     *
     * `AuditLog::create(['org_id' => $rival, ...])` wrote an immutable,
     * apparently authoritative record into another org's trail — and a bulk
     * `insert()` skipped the model's `creating` hook entirely, so it did not
     * even get the stamping this relies on. An audit log an outsider can
     * append to is worse than no audit log, because it is believed.
     *
     * @param  array<int|string, mixed>  $values
     * @return bool
     */
    public function insert(array $values)
    {
        $rows = array_is_list($values) ? $values : [$values];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $this->guardScopeKeys($row);
            }
        }

        return parent::insert($values);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  string|null  $sequence
     * @return int
     */
    public function insertGetId(array $values, $sequence = null)
    {
        $this->guardScopeKeys($values);

        return parent::insertGetId($values, $sequence);
    }

    /**
     * A row appended to this org's trail has to belong to this org.
     *
     * Silent with no context, matching EnforcesScope: console commands,
     * migrations and the installer legitimately run without one.
     *
     * @param  array<string, mixed>  $values
     */
    private function guardScopeKeys(array $values): void
    {
        $context = app(Context::class);

        foreach (['org_id' => $context->orgId(), 'site_id' => $context->siteId()] as $column => $current) {
            if (! array_key_exists($column, $values) || $values[$column] === null || $current === null) {
                continue;
            }

            if ((int) $values[$column] !== (int) $current) {
                throw new RuntimeException(
                    "Refusing to append an audit row with [{$column}] outside the current scope. "
                    .'A trail an outsider can write to is worse than no trail, because it is '
                    .'believed (ADR-020).'
                );
            }
        }
    }
}
