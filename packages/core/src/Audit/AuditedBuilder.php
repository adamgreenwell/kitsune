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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kitsune\Core\Models\Entry;
use RuntimeException;

/**
 * The single place every write is audited from.
 *
 * ⚠️ Model events are NOT enough. `Model::query()->update()`, `->delete()`
 * and `->forceDelete()` write straight through the query builder and dispatch
 * nothing per row — so a model audited by `updated` / `deleted` listeners is
 * audited for the row-at-a-time path and not for the one-liner that rewrites
 * the whole table. Entries could change or disappear leaving no trace, while
 * the claim was that the API and the console are covered by the same code
 * path as the admin (ADR-020).
 *
 * ⚠️ And model events are not merely insufficient, they are wrong ALONGSIDE
 * this: `$model->save()` and `$model->delete()` both route through here, so
 * listening in both places recorded every single-row write twice. The builder
 * can tell the cases apart on its own anyway — a soft delete IS an update
 * that sets `deleted_at`, and a restore IS one that clears it — so the model
 * keeps only `created`, which an insert never brings through these methods.
 *
 * Bound to Entry rather than made generic, following AppendOnlyBuilder. One
 * model needs this today, and a concrete binding is what lets the analyser
 * see that `deleted_at` exists here at all — a `@template` bounded by Model
 * cannot, so it would have to be suppressed. Generalise when there is a
 * second case to check the design against.
 *
 * @extends Builder<Entry>
 */
class AuditedBuilder extends Builder
{
    /**
     * Set on the builder that performs the write, so the write does not
     * audit itself a second time. Private, and only ever set on an instance
     * this class made — see plainQueryFor().
     */
    private bool $suppressed = false;

    private const NO_BULK_CREATE =
        'Entries cannot be written in bulk, because these paths return a row count rather than '
        .'the keys they wrote — there would be nothing to record as the target, and an entry '
        .'would appear with no audit trail (ADR-020). Use create(), which is audited.';

    /**
     * ⚠️ Creation is audited HERE, not from the `created` model event.
     *
     * `Entry::createQuietly()` and any creation inside
     * `Model::withoutEvents()` suppress that listener while still inserting
     * the row — through this very method, which `Model::performInsert()`
     * uses for an incrementing key. So the entry persisted with no audit row,
     * and the quiet variants are ordinary Eloquent that application code
     * reaches for without thinking about the trail.
     *
     * This is the one insert path that CAN be audited: it returns the id it
     * wrote, so there is a target to name. Every other bulk insert path is
     * refused below for exactly the reason this one works.
     *
     * @param  array<string, mixed>  $values
     * @param  string|null  $sequence
     * @return int
     */
    public function insertGetId(array $values, $sequence = null)
    {
        $model = $this->getModel();

        return DB::transaction(function () use ($values, $sequence, $model) {
            $id = parent::insertGetId($values, $sequence);

            $target = $model->newInstance([], true);
            $target->forceFill([$model->getKeyName() => $id]);

            app(Auditor::class)->recordOrFail(Str::snake(class_basename($model)).'.created', $target);

            return $id;
        });
    }

    /**
     * ⚠️ Creation has a bulk path too, and it is the same hole in reverse.
     *
     * `Entry::query()->insert()` writes rows that dispatch no `created`
     * event, so an entry could APPEAR with no audit row — as untraceable as
     * the bulk update that could change one. Auditing it is not possible
     * here: these methods return a row count, not the keys they wrote, so
     * there is nothing to name as the target.
     *
     * Refused rather than left silently unaudited, and the message names the
     * way through. That the guarantee is "there is no unaudited way to create
     * an entry" is worth more at this stage than a convenient bulk import,
     * which can come back with an ADR and an audited path of its own.
     *
     * @param  array<string, mixed>  $values
     */
    public function insert(array $values): bool
    {
        throw new RuntimeException(self::NO_BULK_CREATE);
    }

    /** @param  array<string, mixed>  $values */
    public function insertOrIgnore(array $values): int
    {
        throw new RuntimeException(self::NO_BULK_CREATE);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int, string>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null)
    {
        throw new RuntimeException(self::NO_BULK_CREATE);
    }

    /**
     * @param  \Closure|\Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder<*>|string  $query
     * @param  array<int, string>  $columns
     * @return int
     */
    public function insertUsing(array $columns, $query)
    {
        throw new RuntimeException(self::NO_BULK_CREATE);
    }

    /**
     * @param  \Closure|\Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder<*>|string  $query
     * @param  array<int, string>  $columns
     * @return int
     */
    public function insertOrIgnoreUsing(array $columns, $query)
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
     * ⚠️ PostgreSQL-only, and refused rather than audited.
     *
     * It updates through a FROM clause, so the rows it touches are defined by
     * a join this builder cannot reproduce — and auditing here works by
     * running the write against the keys it captured, which would silently
     * change what the statement did. Refusing is the honest option, and it is
     * the same rule the insert paths follow: what cannot be audited is not
     * allowed (ADR-020).
     *
     * @param  array<string, mixed>  $values
     * @return int
     */
    public function updateFrom(array $values)
    {
        throw new RuntimeException(
            'updateFrom() updates through a join this builder cannot reproduce, so the write '
            .'could not be audited against the rows it actually touched (ADR-020). Update '
            .'through a predicate on entries instead.'
        );
    }

    /**
     * ⚠️ Eloquent implements bulk touching as `toBase()->update(...)`, which
     * goes straight past the override above. Every matching entry had its
     * `updated_at` moved with no audit row.
     *
     * Routed through update() rather than duplicated, so it inherits the
     * capture-then-write-by-keys behaviour and names the same action.
     *
     * @param  array<int, string>|string|null  $column
     * @return bool|int
     */
    public function touch($column = null)
    {
        $time = $this->model->freshTimestamp();

        if ($column !== null) {
            $columns = [];

            foreach ((array) $column as $name) {
                $columns[$name] = $time;
            }

            return $this->update($columns);
        }

        $column = $this->model->getUpdatedAtColumn();

        if (! $this->model->usesTimestamps() || $column === null) {
            return false;
        }

        return $this->update([$column => $time]);
    }

    /**
     * ⚠️ Forwarded WHOLE to the query builder, so neither these overrides nor
     * the `created` event sees it. Depending on whether the predicate matches
     * it either creates or modifies an entry, and did so untraced either way.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $values
     * @return bool
     */
    public function updateOrInsert(array $attributes, array|callable $values = [])
    {
        throw new RuntimeException(self::NO_BULK_CREATE);
    }

    /**
     * ⚠️ Not an insert, but the same gap: it removes every row at once and
     * dispatches nothing, so the whole table could vanish untraced.
     */
    public function truncate(): void
    {
        throw new RuntimeException(
            'Truncating entries would remove every row with no audit trail, and there would be '
            .'nothing left to say what had been there (ADR-020). Delete through the model.'
        );
    }

    /** @param  array<string, mixed>  $values */
    public function update(array $values)
    {
        if ($this->suppressed) {
            return parent::update($values);
        }

        return $this->auditing($this->actionFor($values), fn (self $query) => $query->update($values));
    }

    // delete() is deliberately NOT overridden. Entry soft-deletes, so both
    // SoftDeletingScope's onDelete callback and runSoftDelete() route a
    // deletion back through update() — where actionFor() reads `deleted_at`
    // and names it. Auditing it here as well would record it twice.

    public function forceDelete()
    {
        if ($this->suppressed) {
            return parent::forceDelete();
        }

        return $this->auditing('force_deleted', fn (self $query) => $query->forceDelete());
    }

    /**
     * ⚠️ Increments are UPDATES that skip update(). Both forward to the query
     * builder, so a counter could be moved on any number of entries with no
     * trail. Audited rather than refused — unlike the insert paths, the rows
     * already exist and have keys to name.
     *
     * @param  string|Expression  $column
     * @param  array<string, mixed>  $extra
     */
    public function increment($column, $amount = 1, array $extra = [])
    {
        if ($this->suppressed) {
            return parent::increment($column, $amount, $extra);
        }

        return $this->auditing('updated', fn (self $query) => $query->increment($column, $amount, $extra));
    }

    /**
     * @param  string|Expression  $column
     * @param  array<string, mixed>  $extra
     */
    public function decrement($column, $amount = 1, array $extra = [])
    {
        if ($this->suppressed) {
            return parent::decrement($column, $amount, $extra);
        }

        return $this->auditing('updated', fn (self $query) => $query->decrement($column, $amount, $extra));
    }

    /**
     * ⚠️ The PLURAL forms too. `incrementEach()` and `decrementEach()` are
     * separate methods on the query builder, so overriding the singular ones
     * left a multi-column increment forwarding straight past every guard
     * here — the same omission, one API call along.
     *
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function incrementEach(array $columns, array $extra = [])
    {
        if ($this->suppressed) {
            return parent::incrementEach($columns, $extra);
        }

        return $this->auditing('updated', fn (self $query) => $query->incrementEach($columns, $extra));
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function decrementEach(array $columns, array $extra = [])
    {
        if ($this->suppressed) {
            return parent::decrementEach($columns, $extra);
        }

        return $this->auditing('updated', fn (self $query) => $query->decrementEach($columns, $extra));
    }

    /**
     * Name the action from the values being written, not from the caller.
     *
     * Eloquent expresses a soft delete and a restore as updates, so reading
     * `deleted_at` is what separates "edited", "deleted" and "restored" —
     * and it separates them identically for one row and for ten thousand.
     *
     * @param  array<string, mixed>  $values
     */
    private function actionFor(array $values): string
    {
        $model = $this->getModel();
        $column = $model->getDeletedAtColumn();

        // Bulk updates qualify their columns; instance saves do not.
        foreach ([$column, $model->getTable().'.'.$column] as $key) {
            if (array_key_exists($key, $values)) {
                return $values[$key] === null ? 'restored' : 'deleted';
            }
        }

        return 'updated';
    }

    /**
     * Capture the affected keys, then write against THOSE KEYS, in one
     * transaction.
     *
     * ⚠️ Auditing the predicate and writing the predicate are two different
     * statements over a set that can move between them. On PostgreSQL a row
     * inserted after the `pluck()` and before the write is modified by the
     * write and absent from the trail; one that stops matching in the same
     * interval gets an audit record for a change it never received. The trail
     * would be quietly wrong in both directions under ordinary load.
     *
     * So the write does not re-run the predicate. It runs against exactly the
     * keys that were audited, on a PLAIN builder — which also avoids
     * recursing back into these overrides. Global scopes are already applied,
     * because the keys came from this query.
     *
     * The keys are read BEFORE the write for the original reason too: after
     * it a deleted row has no id to look up.
     *
     * @param  callable(self): mixed  $write
     */
    private function auditing(string $action, callable $write): mixed
    {
        $model = $this->getModel();

        return DB::transaction(function () use ($action, $write, $model): mixed {
            // ⚠️ DEDUPLICATED. A bulk write over a join — say `entries`
            // joined to `entry_relations`, where several rows point at one
            // entry — yields that entry's key once per matching row. The
            // write touches it once, so recording one row per duplicate would
            // claim a single change happened several times. An audit trail
            // that overstates is not evidence either.
            $keys = $this->toBase()->lockForUpdate()
                ->pluck($model->getQualifiedKeyName())
                ->unique()
                ->values()
                ->all();

            if ($keys === []) {
                return $write($this->plainQueryFor([]));
            }

            $result = $write($this->plainQueryFor($keys));

            // `entry.updated`, not `entries.updated` — an action names the
            // thing acted on, and the rest of the trail is written in those
            // terms.
            $auditor = app(Auditor::class);
            $action = Str::snake(class_basename($model)).'.'.$action;

            foreach ($keys as $key) {
                $target = $model->newInstance([], true);
                $target->forceFill([$model->getKeyName() => $key]);

                $auditor->recordOrFail($action, $target);
            }

            return $result;
        });
    }

    /**
     * A builder over exactly these keys, without the audit overrides.
     *
     * Constructed rather than taken from the model, because `newQuery()`
     * returns another AuditedBuilder and the write would audit itself twice.
     *
     * @param  list<mixed>  $keys
     */
    private function plainQueryFor(array $keys): self
    {
        $query = $this->getModel()->newModelQuery();
        $query->suppressed = true;

        return $query->whereKey($keys);
    }
}
