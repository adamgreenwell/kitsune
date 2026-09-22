<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tenancy\Concerns;

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Tenancy\Contracts\RefusesCascadingDeletes;

/**
 * Runs `RefusesCascadingDeletes::guardCascade()` for every row a bulk delete would remove.
 *
 * ⚠️ A TRAIT BECAUSE THERE ARE TWO BUILDERS, and while this lived as a private method on `ScopedBuilder`
 * there was only one. `RefusesCascadingDeletes` says the model "states the rule once and both paths enforce
 * it" — true for models on `ScopedBuilder`, and silently false for `FieldStorage`, whose
 * `GuardedStorageBuilder` extends Eloquent's builder directly and had no `delete()` override at all. So a
 * guard implemented on that model would have been dead code: the contract was reachable only through a class
 * it does not use. The rule now lives in one place and every guarded builder pulls it in.
 */
trait GuardsCascadingDeletes
{
    /**
     * Run a deletion with the cascade refusal, atomically.
     *
     * ⚠️ In a TRANSACTION, with the referencing rows locked. The check counted
     * references and the DELETE ran as separate statements, so a child inserted
     * between them was cascaded away permanently despite the refusal — the
     * refusal was advisory under concurrent load, which is the state it exists
     * to prevent.
     *
     * ⚠️ UNCONDITIONAL, including inside `withoutScopeBecause()` — and it stood down there until review
     * found it. Every scope-key refusal in this layer honours that hatch, so this one looked consistent; but
     * `ScopeWrites::suspend()` is a single process-global flag, so ANY storage or type delete nested
     * anywhere inside an unrelated suspension frame cascaded its children away with no refusal and no
     * report. That is the ADR-020 defect the guard exists to close, reachable through the one door marked
     * safe.
     *
     * Whether a delete strands data is not a scope question, which is the rule `ResolvesWrittenColumns`
     * already states in exactly those words for the same reason. Measured before adopting it: the full suite
     * passes unchanged, and no production path deletes a guarded model inside a suspension frame — the
     * frames in this codebase are settings reads and writes and relation reads.
     */
    protected function guardingCascade(callable $delete): mixed
    {
        $model = $this->getModel();

        if (! $model instanceof RefusesCascadingDeletes) {
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
}
