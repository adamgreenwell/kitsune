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
use Kitsune\Core\Tenancy\Contracts\RefusesCascadingDeletes;
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

        return parent::update($values);
    }

    /**
     * ⚠️ Deletion is guarded HERE as well as in the model event.
     *
     * `Site::query()->delete()`, `deleteQuietly()` and anything inside
     * `withoutEvents()` dispatch no `deleting` callback, so a refusal written
     * as a model event covered one path — and the `ON DELETE CASCADE` on
     * `entries.site_id` and `entries.entry_type_id` then hard-deleted every
     * referenced entry with no audit row and no soft delete. That is the exact
     * data-loss path the model-event refusal was added to close.
     *
     * The model supplies the check, because only it knows what cascades from
     * it. Anything else deletes as before.
     */
    public function delete()
    {
        $model = $this->getModel();

        if ($model instanceof RefusesCascadingDeletes) {
            foreach ($this->toBase()->pluck($model->getQualifiedKeyName()) as $key) {
                $model->newInstance([], true)
                    ->forceFill([$model->getKeyName() => $key])
                    ->guardCascade();
            }
        }

        return parent::delete();
    }

    /**
     * @param  string|Expression  $column
     * @param  array<string, mixed>  $extra
     */
    public function increment($column, $amount = 1, array $extra = [])
    {
        $this->guardScopeKeys([(string) $column => $amount, ...$extra]);

        return parent::increment($column, $amount, $extra);
    }

    /**
     * @param  string|Expression  $column
     * @param  array<string, mixed>  $extra
     */
    public function decrement($column, $amount = 1, array $extra = [])
    {
        $this->guardScopeKeys([(string) $column => $amount, ...$extra]);

        return parent::decrement($column, $amount, $extra);
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function incrementEach(array $columns, array $extra = [])
    {
        $this->guardScopeKeys([...$columns, ...$extra]);

        return parent::incrementEach($columns, $extra);
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function decrementEach(array $columns, array $extra = [])
    {
        $this->guardScopeKeys([...$columns, ...$extra]);

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
        /** @var array<int, array<string, mixed>> $rows */
        $rows = array_is_list($values) ? $values : [$values];

        foreach ($rows as $row) {
            $this->guardScopeKeys($row);
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
    private function guardScopeKeys(array $values): void
    {
        $context = app(Context::class);
        $normalised = [];

        foreach ($values as $column => $value) {
            $bare = str_contains((string) $column, '.')
                ? substr((string) $column, (int) strrpos((string) $column, '.') + 1)
                : (string) $column;

            $normalised[trim($bare, '`"[]')] = $value;
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
