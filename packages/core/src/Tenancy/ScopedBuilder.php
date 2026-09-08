<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tenancy;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Tenancy\Contracts\RefusesCascadingDeletes;
use Kitsune\Core\Tenancy\Contracts\RequiresModelSave;
use RuntimeException;

/**
 * The write half of the scope, on the paths a model event cannot see.
 *
 * ⚠️ `EnforcesScope` enforces the scope keys from `creating` and `updating`.
 * A mass update instantiates no models and dispatches nothing:
 *
 *   Entry::query()->update(['org_id' => $rival, 'site_id' => $rivalSite]);
 *   Site::query()->update(['org_id' => $rival]);
 *
 * The global scope limits which rows are SELECTED and says nothing about the
 * values assigned, so this transferred the current scope's rows into another
 * org — where the scope then showed them to their new owner and hid them from
 * their author — without going near `withoutScopeBecause()`.
 *
 * The same shape has now been found on four models in this project. It is not
 * a property of any of them: a guard in a model event is a guard on one path.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class ScopedBuilder extends Builder
{
    /**
     * Takes the model, so the type parameter is known at construction.
     *
     * ⚠️ Not for convenience. `new ScopedBuilder($query)` gives an analyser no
     * way to resolve TModel, so it falls back to `Model` — and every scoped
     * model's queries then lose their own type, turning `Site::create()` into
     * `Model` and making `Entry::ofType()` undefined. Passing the model
     * resolves it, and the trait passes `$this`.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  TModel  $model
     */
    public function __construct($query, Model $model)
    {
        parent::__construct($query);

        $this->setModel($model);
    }

    /** @param  array<string, mixed>  $values */
    public function update(array $values)
    {
        $this->guardScopeKeys($values);
        $this->refusePerRowColumns($values);

        return parent::update($values);
    }

    /**
     * ⚠️ Deletion is guarded HERE as well as in the model event.
     *
     * `Site::query()->delete()`, `deleteQuietly()` and anything inside
     * `withoutEvents()` dispatch no `deleting` callback, so a refusal written
     * as a model event covered one path — and the `ON DELETE CASCADE` on
     * `entries.site_id` and `entries.entry_type_id` then hard-deleted every
     * referenced entry with no audit row and no soft delete.
     */
    public function delete()
    {
        return $this->guardingCascade(fn () => parent::delete());
    }

    /**
     * ⚠️ `forceDelete()` too. Eloquent sends it straight to the query builder
     * rather than through `delete()`, so the cascade refusal did not see it —
     * and on a soft-deleting model it is the one that actually removes rows.
     */
    public function forceDelete()
    {
        return $this->guardingCascade(fn () => parent::forceDelete());
    }

    /**
     * Run a deletion with the cascade refusal, atomically.
     *
     * ⚠️ In a TRANSACTION, with the referencing rows locked. The check counted
     * references and the DELETE ran as separate statements, so a child inserted
     * between them was cascaded away permanently despite the refusal — the
     * refusal was advisory under concurrent load, which is the state it exists
     * to prevent.
     */
    private function guardingCascade(callable $delete): mixed
    {
        $model = $this->getModel();

        if (ScopeWrites::suspended() || ! $model instanceof RefusesCascadingDeletes) {
            return $delete();
        }

        return DB::transaction(function () use ($model, $delete) {
            foreach ($this->toBase()->lockForUpdate()->pluck($model->getQualifiedKeyName()) as $key) {
                $model->newInstance([], true)
                    ->forceFill([$model->getKeyName() => $key])
                    ->guardCascade();
            }

            return $delete();
        });
    }

    /**
     * ⚠️ Arithmetic on a scope column is refused outright, never compared.
     *
     * The scope-key guard reads a value; an increment supplies an AMOUNT. So
     * `increment('org_id', 1)` with the current org 1 compared 1 against 1 and
     * PASSED — then added 1, moving the row to org 2. The guard was reading the
     * delta as though it were the destination, and no amount added to a scope
     * key lands somewhere this context can vouch for.
     *
     * Protected, because AuditedBuilder extends this and needs it: Entry has
     * one builder and both sets of guards.
     *
     * @param  array<string, mixed>  $values
     */
    protected function refuseScopeArithmetic(array $values): void
    {
        if (ScopeWrites::suspended()) {
            return;
        }

        foreach (array_keys($values) as $column) {
            $bare = $this->bareColumn((string) $column);

            if ($bare === 'org_id' || $bare === 'site_id') {
                throw new RuntimeException(sprintf(
                    'Refusing to increment or decrement [%s]: it is a scope key, and no amount added '
                    .'to one lands somewhere this context can vouch for (ADR-021). Set the value '
                    .'through a save if the move is deliberate.',
                    $bare,
                ));
            }
        }
    }

    /**
     * Refuse a bulk write to a column whose guard can only run per row.
     *
     * ⚠️ Six guards in this project have now been found bypassed by a bulk
     * write. A model names the columns whose correctness depends on the row and
     * this refuses them, so the model event becomes the only door rather than
     * the first one.
     *
     * @param  array<string, mixed>  $values
     */
    protected function refusePerRowColumns(array $values): void
    {
        $model = $this->getModel();

        if (ScopeWrites::suspended() || ! $model instanceof RequiresModelSave) {
            return;
        }

        // ⚠️ An INSTANCE save reaches this method too, because
        // `Model::performUpdate()` writes through the builder — so refusing
        // every bulk-shaped write would refuse `$model->update(...)` as well.
        //
        // A loaded model is what separates them: `performUpdate()` roots its
        // query in the instance being saved, while `Model::query()` builds one
        // from a fresh, non-existent instance. That is the same discriminator
        // Laravel uses for `setKeysForSaveQuery()`, and the guards it stands
        // aside for have already run in `saving`.
        if ($model->exists) {
            return;
        }

        $guarded = $model::columnsRequiringModelSave();

        foreach (array_keys($values) as $column) {
            $bare = $this->bareColumn((string) $column);

            if (isset($guarded[$bare])) {
                throw new RuntimeException(sprintf(
                    '[%s] cannot be written in bulk on %s: %s A bulk update dispatches no model '
                    .'events, so the check that would refuse this never runs. Save the model '
                    .'instead.',
                    $bare,
                    $model::class,
                    $guarded[$bare],
                ));
            }
        }
    }

    /**
     * @param  string|Expression  $column
     * @param  array<string, mixed>  $extra
     */
    public function increment($column, $amount = 1, array $extra = [])
    {
        $this->refuseScopeArithmetic([(string) $column => $amount, ...$extra]);

        return parent::increment($column, $amount, $extra);
    }

    /**
     * @param  string|Expression  $column
     * @param  array<string, mixed>  $extra
     */
    public function decrement($column, $amount = 1, array $extra = [])
    {
        $this->refuseScopeArithmetic([(string) $column => $amount, ...$extra]);

        return parent::decrement($column, $amount, $extra);
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function incrementEach(array $columns, array $extra = [])
    {
        $this->refuseScopeArithmetic([...$columns, ...$extra]);

        return parent::incrementEach($columns, $extra);
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function decrementEach(array $columns, array $extra = [])
    {
        $this->refuseScopeArithmetic([...$columns, ...$extra]);

        return parent::decrementEach($columns, $extra);
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int, string>|null  $update
     * @return int
     */
    public function upsert(array $values, $uniqueBy, $update = null)
    {
        // ⚠️ REFUSED, not guarded. The conflict target is not constrained by
        // the global scope, so validating the proposed values is not enough:
        // from org A, upserting a row carrying org A's `org_id` and org B's
        // primary key passes every check and then UPDATES org B's row. The row
        // being overwritten is never named in the values, so there is nothing
        // here that validation could inspect to make it safe.
        if (! ScopeWrites::suspended()) {
            throw new RuntimeException(sprintf(
                'Refusing to upsert %s: the conflict target is not constrained by the scope, so a '
                .'row belonging to another org or site could be overwritten by an insert that looks '
                .'entirely valid (ADR-021). Save the model, or use withoutScopeBecause() if this is '
                .'deliberate.',
                $this->getModel()::class,
            ));
        }

        return parent::upsert($values, $uniqueBy, $update);
    }

    /**
     * ⚠️ Refused rather than guarded. It writes through a join, so the values
     * assigned are not visible here at all.
     *
     * @param  array<string, mixed>  $values
     * @return int
     */
    public function updateFrom(array $values)
    {
        throw new RuntimeException(
            'updateFrom() assigns through a join, so the scope keys it writes cannot be checked '
            .'(ADR-021). Update through a predicate on the table instead.'
        );
    }

    /**
     * A row this scope writes has to belong to this scope.
     *
     * Silent with no context, matching EnforcesScope: console commands,
     * migrations and the installer legitimately run without one. NULL
     * `site_id` is org-shared and legitimate.
     *
     * @param  array<string, mixed>  $values
     */
    /** Strip table qualification and quoting, so `entries`.`org_id` is `org_id`. */
    protected function bareColumn(string $column): string
    {
        $bare = str_contains($column, '.')
            ? substr($column, (int) strrpos($column, '.') + 1)
            : $column;

        return trim($bare, '`"[]');
    }

    /**
     * A row this scope writes has to belong to this scope.
     *
     * Silent with no context, matching EnforcesScope: console commands,
     * migrations and the installer legitimately run without one. NULL
     * `site_id` is org-shared and legitimate.
     *
     * @param  array<string, mixed>  $values
     */
    protected function guardScopeKeys(array $values): void
    {
        // The reviewable escape hatch stands BOTH enforcers down, not one.
        if (ScopeWrites::suspended()) {
            return;
        }

        $context = app(Context::class);
        $normalised = [];

        foreach ($values as $column => $value) {
            $normalised[$this->bareColumn((string) $column)] = $value;
        }

        foreach (['org_id' => $context->orgId(), 'site_id' => $context->siteId()] as $column => $current) {
            if (! array_key_exists($column, $normalised)) {
                continue;
            }

            $value = $normalised[$column];

            if ($value === null || $current === null || (int) $value === $current) {
                continue;
            }

            throw new RuntimeException(sprintf(
                'Refusing to write %s with [%s] = %s from a context scoped to %s. A scope that only '
                .'filters SELECTs still lets a caller move a row to somebody else, and a mass '
                .'update dispatches no model events at all (ADR-021). Use withoutScopeBecause() if '
                .'this is deliberate.',
                $this->getModel()::class,
                $column,
                (string) $value,
                (string) $current,
            ));
        }
    }
}
