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
use Illuminate\Support\Collection;
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
     * ⚠️ THE INSERT FAMILY WAS UNGUARDED, so `columnsRequiringModelSave()` covered half the doors.
     * Measured on `Site` before this: `update(['base_url' => …])` refused, and
     * `insert([… 'base_url' => 'https://x.test' …])` created a row with `canonical_host = NULL` — a
     * site declaring a public URL and reachable at none. `RequiresModelSave`'s docblock claimed the
     * model event became "the only door rather than the first one", which was true of `update()`
     * alone (issue #60).
     *
     * ⚠️ REFUSED BY METHOD, NOT BY `$model->exists`, which is the discriminator the reverted first
     * attempt used and why it refused every ordinary create. `Model::performInsert()` writes through
     * this builder, and during an insert `exists` is false — so a guard keyed on it fires on the
     * legitimate path. `AuditedBuilder` already solved this for `Entry` and the answer is which
     * METHOD was called: `performInsert()` uses `insertGetId()` for an incrementing model, and a bulk
     * caller uses `insert()` or one of the `…Using` forms. Those are refused; `insertGetId()` is not.
     *
     * ⚠️ AND ONLY WHEN THE MODEL INCREMENTS, because that assumption is what makes the method a
     * discriminator at all: a non-incrementing model's `performInsert()` uses `insert()`, so refusing
     * it there would break creates exactly as the reverted attempt did. No `RequiresModelSave` model
     * is non-incrementing today and `PerRowInsertGuardTest` asserts that, so the day one appears the
     * test fails rather than the creates.
     *
     * ⚠️ `->toBase()` AND `DB::table()` REMAIN OUT OF SCOPE by construction. Guards live at the
     * Eloquent layer and nothing there can police a caller who has explicitly stepped below it. That
     * is a boundary rather than an oversight, and it is stated so it is not mistaken for one.
     *
     * @param  array<string, mixed>  $values
     */
    public function insert(array $values): bool
    {
        $this->refuseBulkCreate('insert');

        return parent::insert($values);
    }

    /** @param  array<string, mixed>  $values */
    public function insertOrIgnore(array $values): int
    {
        // ⚠️ Refused whatever the model's key strategy: `performInsert()` never uses this one, so
        // there is no legitimate per-row caller to protect.
        $this->refuseBulkCreate('insertOrIgnore', always: true);

        return parent::insertOrIgnore($values);
    }

    /**
     * @param  array<int, string>  $columns
     * @param  mixed  $query
     */
    public function insertUsing(array $columns, $query): int
    {
        $this->refuseBulkCreate('insertUsing', always: true);

        return parent::insertUsing($columns, $query);
    }

    /**
     * @param  array<int, string>  $columns
     * @param  mixed  $query
     */
    public function insertOrIgnoreUsing(array $columns, $query): int
    {
        $this->refuseBulkCreate('insertOrIgnoreUsing', always: true);

        return parent::insertOrIgnoreUsing($columns, $query);
    }

    /**
     * ⚠️ `insertGetId()` IS PUBLICLY CALLABLE, which the first version of this guard treated as if it
     * were `performInsert()`'s private door. `Site::query()->insertGetId([… 'base_url' => …])`
     * therefore still wrote a row with whatever `canonical_host` the caller chose, or none — the same
     * cross-org claim hole the change was meant to close, reached one method along. Found by review,
     * and the test that claimed to enumerate every creation path did not cover it.
     *
     * ⚠️ THE DISCRIMINATOR IS THE MODEL BEHIND THE BUILDER, not the method. `Model::performInsert()`
     * builds its query from `newModelQuery()`, so `getModel()` IS the instance being saved and every
     * guarded value in `$values` came off its own attributes — the `saving` hooks having already put
     * them there. `Site::query()` builds one from a fresh, empty instance, so a guarded column in
     * `$values` has nothing on the model to match. That is a general test rather than a per-model one,
     * which matters because the four guarded models guard different KINDS of column: `Site`'s are
     * derived, `EntryType`'s and `Field`'s are validated, and a create legitimately names those.
     *
     * @param  array<string, mixed>  $values
     * @param  string|null  $sequence
     * @return int
     */
    public function insertGetId(array $values, $sequence = null)
    {
        $this->refuseDetachedInsert('insertGetId', $values);

        return parent::insertGetId($values, $sequence);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  non-empty-array<non-empty-string>  $returning
     * @param  non-empty-string|non-empty-array<non-empty-string>|null  $uniqueBy
     * @return Collection<int, mixed>
     */
    public function insertOrIgnoreReturning(array $values, array $returning = ['*'], array|string|null $uniqueBy = null): Collection
    {
        // `performInsert()` never uses this one, so there is no per-row caller to protect.
        $this->refuseBulkCreate('insertOrIgnoreReturning', always: true);

        /*
         * ⚠️ Forwarded through `toBase()` rather than `parent::`, because Eloquent's builder does not
         * declare this method — it reaches the QUERY builder through `__call`, which static analysis
         * cannot follow. Naming the real receiver is clearer than annotating around the magic.
         */
        return $this->toBase()->insertOrIgnoreReturning($values, $returning, $uniqueBy);
    }

    /**
     * Refuse an insert that names a guarded column the model behind it never set.
     *
     * ⚠️ THE COMPARISON IS AGAINST THE BUILDER'S OWN MODEL, and that is what separates a save from a
     * hand-rolled insert without needing to know what any column means. On a save the values came
     * off that instance, so they match; on `Model::query()->insertGetId([...])` the instance is empty
     * and they cannot.
     *
     * ⚠️ ABSENT IS NOT A MISMATCH. A row that names no guarded column has nothing this can check and
     * nothing it needs to: the columns' correctness is the thing being protected, and a row that does
     * not touch them cannot get them wrong.
     *
     * @param  array<string, mixed>  $values
     */
    private function refuseDetachedInsert(string $method, array $values): void
    {
        $model = $this->getModel();

        if (ScopeWrites::suspended() || ! $model instanceof RequiresModelSave) {
            return;
        }

        foreach ($model::columnsRequiringModelSave() as $column => $reason) {
            if (! array_key_exists($column, $values)) {
                continue;
            }

            /*
             * ⚠️ PRESENCE PROVED NOTHING, WHICH IS WHAT REVIEW FOUND. This asked whether the guarded
             * column existed on the model behind the builder, reasoning that a saving model has it and
             * the empty instance `Model::query()` makes does not. `createQuietly()`, `saveQuietly()`
             * and anything inside `withoutEvents()` populate attributes while suppressing the `saving`
             * callback that derives them — so `Site::createQuietly(['base_url' => …])` wrote
             * `canonical_host = NULL` for a site declaring a public URL, and a quiet create naming the
             * derived columns itself STOLE AN OVERLAPPING CROSS-ORG CLAIM. Measured: `steal.test/` held
             * by one org, `steal.test/news` written under another, which is the ADR-021 theft this
             * guard exists to prevent.
             *
             * An attribute can be supplied by any caller. The flag is set by the code that derives, so
             * a path that skipped the deriving cannot present it — and equality, the version before
             * presence, was never available: `AuditedBuilder::insertGetId()` transforms an `Entry`'s
             * `values` before delegating here, so it no longer equals the attribute it came from and
             * comparing them refused every audited create.
             */
            if ($model->guardedColumnsAreDerived()) {
                continue;
            }

            throw new RuntimeException(sprintf(
                '[%s] cannot be written by %s() on %s: %s The model behind this query never set that '
                .'value, so the checks that derive and validate it did not run. Save the model '
                .'instead.',
                $column,
                $method,
                $model::class,
                $reason,
            ));
        }
    }

    /**
     * Refuse a bulk creation path on a model whose columns need a per-row guard.
     *
     * ⚠️ THE MESSAGE NAMES THE COLUMNS AND THE REASON, because a refusal an importer cannot act on
     * is a wall rather than a guard. Every column in `columnsRequiringModelSave()` is listed with
     * the sentence the model gave for it.
     */
    private function refuseBulkCreate(string $method, bool $always = false): void
    {
        $model = $this->getModel();

        if (ScopeWrites::suspended() || ! $model instanceof RequiresModelSave) {
            return;
        }

        // See the note on `insert()`: the method is a discriminator only where `performInsert()`
        // does not use it.
        if (! $always && ! $model->getIncrementing()) {
            return;
        }

        $guarded = $model::columnsRequiringModelSave();

        if ($guarded === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            '%s cannot be created in bulk with %s(): %s A bulk insert dispatches no model events, so '
            .'the checks that derive and validate those columns never run — and the row is written '
            .'with them empty, which for a URL claim means a site declaring an address it can never '
            .'be reached at. Save the model instead.',
            $model::class,
            $method,
            implode(' ', array_map(
                static fn (string $column, string $reason): string => "[{$column}] {$reason}",
                array_keys($guarded),
                $guarded,
            )),
        ));
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
            // ⚠️ The ROWS, not their keys — and keys was a silent hole.
            //
            // A guard was handed `newInstance([], true)` carrying nothing but the
            // primary key, which worked for `EntryType::guardCascade()` only
            // because it counts entries BY that key. `Field::guardCascade()` has
            // to read `field_storage_id` and `entry_type_id` to know what data to
            // look for, found both null on a key-only instance, and returned
            // early — so the bulk and quiet delete paths passed a guard that
            // never ran. A guard cannot judge a row it has not been given.
            // ⚠️ The COMPLETE row, because `get()` inherits the caller's
            // projection. `Field::query()->select('id')->delete()` handed the
            // guard a model with no `field_storage_id` again — and the DELETE
            // ignores a SELECT list, so the row went and its data stranded. The
            // projection is reset rather than trusted.
            foreach ((clone $this)->select($model->getTable().'.*')->lockForUpdate()->get() as $row) {
                // Narrowed per row: this builder is generic over its model, so
                // the contract check above constrains the prototype rather than
                // what the query returns.
                if ($row instanceof RefusesCascadingDeletes) {
                    $row->guardCascade();
                }
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
        // ⚠️ AND `exists` ALONE WAS THE WRONG TEST, for the reason the insert
        // guard records at length: this stood aside because "the guards it
        // stands aside for have already run in `saving`", and a quiet save
        // suppresses `saving` while still being an instance save. Measured:
        // `$site->saveQuietly()` moved `base_url` with `canonical_host` left on
        // the old address. Both halves are needed — `exists` says it is an
        // instance write rather than a bulk one, and the flag says the guards
        // for that write actually ran.
        if ($model->exists && $model->guardedColumnsAreDerived()) {
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

        // ⚠️ And the JSON PATH is rooted at its column, which this did not do.
        //
        // Laravel accepts `update(['values->body' => ...])`. That returned
        // `values->body`, which never matched the guarded key `values` — so a bulk
        // JSON-path write skipped the per-row refusal entirely, and with it the
        // value-conversion pipeline that sanitises rich text (issue #42).
        //
        // `AuditedBuilder` had exactly this defect for exactly this reason and was
        // fixed; the same wrong assumption was sitting in the guard beside it. Two
        // places that must agree about what a column is, and only one of them had
        // been told.
        $bare = explode('->', $bare)[0];

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
