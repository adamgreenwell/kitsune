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
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tenancy\ScopeWrites;
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
 * ⚠️ WHERE THIS STOPS. `Entry::query()->toBase()` hands back the underlying
 * query builder, and a write through it is not audited. That is not a hole
 * this class can close: `toBase()` is the same door as `DB::table('entries')`
 * and `DB::statement(...)`, and no model-layer guard can stand in front of
 * raw SQL. Overriding it is not an option either — Laravel's own `update()`,
 * `count()` and `pluck()` all go through it, this class included.
 *
 * So the guarantee is about the ELOQUENT layer: no Eloquent path creates,
 * changes or removes an entry without an audit row or a refusal. Reaching
 * past Eloquent is explicit, visible in review as `toBase()` or `DB::`, and
 * would need database triggers to prevent — which is a decision with its own
 * costs and would want its own ADR. ADR-020 says exactly this rather than
 * claiming more.
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

        $this->guardScopeKeys($values);

        return DB::transaction(function () use ($values, $sequence, $model) {
            $id = parent::insertGetId($values, $sequence);

            $target = $model->newInstance([], true);
            $target->forceFill([$model->getKeyName() => $id]);

            app(Auditor::class)->recordOrFail(Str::snake(class_basename($model)).'.created', $target);

            return $id;
        });
    }

    /**
     * ⚠️ The row being written must belong to the org being audited.
     *
     * `createQuietly()` and `withoutEvents()` suppress EnforcesScope's
     * `creating` listener, which is what normally STAMPS these columns — so a
     * caller can supply another org's `org_id` and `site_id` and have them
     * inserted verbatim. The audit row is then written under the CURRENT
     * context, so the other org gains an entry with no audit record while
     * this one gains a trail pointing at a row it does not own. Both halves
     * are wrong, and the trail is wrong in the direction that reads as
     * evidence.
     *
     * The same applies to an UPDATE. `EnforcesScope` stamps these columns on
     * create only, so `Entry::query()->update(['org_id' => $rival])` moved an
     * entry out of the current scope while the audit row was written under
     * the OLD context — leaving the destination org holding an entry whose
     * only trail belongs to somebody else. The existing scope restricts which
     * rows are SELECTED and says nothing about the values written.
     *
     * Refused rather than restamped: a caller who passed an explicit org_id
     * meant something by it, and silently rewriting it would be its own kind
     * of lie.
     *
     * @param  array<string, mixed>  $values
     */
    /**
     * ⚠️ Arithmetic on a scope column is refused outright, never compared.
     *
     * The scope-key guard reads a value; an increment supplies an AMOUNT. So
     * `increment('org_id', 1)` with the current org 1 compared 1 against 1 and
     * PASSED — then added 1, moving the row to org 2. The guard was reading the
     * delta as though it were the destination.
     *
     * There is no amount that is safe to add to a scope key, so none is
     * allowed.
     *
     * @param  array<string, mixed>  $values
     */
    private function refuseScopeArithmetic(array $values): void
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

    /** Strip table qualification and quoting, so `entries`.`org_id` is `org_id`. */
    private function bareColumn(string $column): string
    {
        $bare = str_contains($column, '.')
            ? substr($column, (int) strrpos($column, '.') + 1)
            : $column;

        return trim($bare, '`"[]');
    }

    /**
     * A row this scope writes has to belong to this scope.
     *
     * @param  array<string, mixed>  $values
     */
    private function guardScopeKeys(array $values): void
    {
        // ⚠️ The escape hatch stands this down too.
        //
        // The flag started private to `EnforcesScope`, so each guard that moved
        // to a builder became invisible to it — `withoutScopeBecause()` then
        // suspended some enforcers and not others, and provisioning code that
        // had been explicit about crossing the boundary failed anyway. That
        // teaches callers to stop using the reviewable path, which is the worst
        // outcome available. One flag, read by every enforcer.
        if (ScopeWrites::suspended()) {
            return;
        }

        $context = app(Context::class);

        // ⚠️ Table-QUALIFIED keys count. A joined update writes
        // `entries.status` — this very class has a MySQL regression test doing
        // exactly that — so `update(['entries.org_id' => $rival])` walked past
        // a guard looking for the bare name, and moved entries across orgs
        // while the audit rows stayed under the old context.
        $normalised = [];

        foreach ($values as $column => $value) {
            $bare = str_contains((string) $column, '.')
                ? substr((string) $column, (int) strrpos((string) $column, '.') + 1)
                : (string) $column;

            $normalised[trim($bare, '`"[]')] = $value;
        }

        $values = $normalised;

        foreach (['org_id' => $context->orgId(), 'site_id' => $context->siteId()] as $column => $current) {
            // Absent means the listener will stamp it, or the column does not
            // apply. NULL is legitimate for site_id: org-shared entries.
            if (! array_key_exists($column, $values) || $values[$column] === null) {
                continue;
            }

            // No context to compare against is a different failure, and
            // recordOrFail() reports it better — it names the fix.
            if ($current === null || (int) $values[$column] === (int) $current) {
                continue;
            }

            throw new RuntimeException(
                "Refusing to write [{$column}] outside the current scope. The audit row is written "
                .'under this context, so the other scope would gain an entry whose only trail '
                .'belongs to somebody else (ADR-020). Scoping restricts which rows are selected; '
                .'it does not police the values written.'
            );
        }
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
        $this->guardScopeKeys($values);

        return $this->auditing($this->actionFor($values), fn () => parent::update($values));
    }

    // delete() is deliberately NOT overridden. Entry soft-deletes, so both
    // SoftDeletingScope's onDelete callback and runSoftDelete() route a
    // deletion back through update() — where actionFor() reads `deleted_at`
    // and names it. Auditing it here as well would record it twice.

    public function forceDelete()
    {
        return $this->auditing('force_deleted', fn () => parent::forceDelete());
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
        $this->refuseScopeArithmetic([(string) $column => $amount, ...$extra]);

        return $this->auditing('updated', fn () => parent::increment($column, $amount, $extra));
    }

    /**
     * @param  string|Expression  $column
     * @param  array<string, mixed>  $extra
     */
    public function decrement($column, $amount = 1, array $extra = [])
    {
        $this->refuseScopeArithmetic([(string) $column => $amount, ...$extra]);

        return $this->auditing('updated', fn () => parent::decrement($column, $amount, $extra));
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
        $this->refuseScopeArithmetic([...$columns, ...$extra]);

        return $this->auditing('updated', fn () => parent::incrementEach($columns, $extra));
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function decrementEach(array $columns, array $extra = [])
    {
        $this->refuseScopeArithmetic([...$columns, ...$extra]);

        return $this->auditing('updated', fn () => parent::decrementEach($columns, $extra));
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
     * So the write does not re-run the predicate: this query is CONSTRAINED
     * to exactly the keys that were audited, and then performed.
     *
     * ⚠️ Constrained, not replaced. An earlier version built a fresh
     * key-only builder, which silently dropped any join — so a joined update
     * assigning from the joined table compiled against an alias that was no
     * longer there and failed on an unknown column. Adding a predicate keeps
     * the statement the caller wrote.
     *
     * The keys are read BEFORE the write for the original reason too: after
     * it a deleted row has no id to look up.
     *
     * @param  callable(): mixed  $write
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

            // whereKey qualifies the column, so this is unambiguous even
            // when the caller joined another table.
            $this->whereKey($keys);

            // ⚠️ And the PAGINATION goes, because it has already been spent.
            //
            // `orderBy('id')->offset(1)->limit(1)->update(...)` captured the
            // second row and then reapplied offset 1 to that singleton — so
            // the write touched nothing while the loop below still recorded
            // the action. The audited set and the written set have to be the
            // same set, and a limit that already selected the keys must not
            // select among them again.
            $base = $this->getQuery();
            $base->offset = null;
            $base->limit = null;

            $result = $write();

            if ($keys === []) {
                return $result;
            }

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
}
