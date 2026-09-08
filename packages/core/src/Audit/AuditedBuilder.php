<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Audit;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Kitsune\Core\Models\Entry;

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
    /** @param  array<string, mixed>  $values */
    public function update(array $values)
    {
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
     * Read the affected keys BEFORE the write.
     *
     * After it they cannot be found: a deleted row has no id left to look up
     * and an updated one may no longer match the predicate. One extra query
     * buys a complete trail, and an audit log with a silent gap at "bulk" is
     * not evidence of anything (ADR-020).
     */
    private function auditing(string $action, callable $write): mixed
    {
        $model = $this->getModel();
        $keys = $this->toBase()->pluck($model->getQualifiedKeyName())->all();

        $result = $write();

        // `entry.updated`, not `entries.updated` — an action names the thing
        // acted on, and the rest of the trail is written in those terms.
        $auditor = app(Auditor::class);
        $action = Str::snake(class_basename($model)).'.'.$action;

        foreach ($keys as $key) {
            $target = $model->newInstance([], true);
            $target->forceFill([$model->getKeyName() => $key]);

            $auditor->record($action, $target);
        }

        return $result;
    }
}
