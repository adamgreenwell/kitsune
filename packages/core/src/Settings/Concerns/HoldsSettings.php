<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Settings\Concerns;

use Kitsune\Core\Settings\SettingsGuard;

/**
 * An org, site group or site: a level of ADR-022's hierarchy, holding its overrides in a `settings` column.
 *
 * **Validation**, on `saving`, for every path that saves the model — the settings writer included. `SettingsGuard`
 * refuses a map that is not one and a timezone PHP does not list, so the write fails before it reaches the row.
 *
 * ⚠️ THE `saving` HOOK IS THE ONLY DOOR THROUGH ELOQUENT, NOT THE ONLY DOOR. The model's
 * `columnsRequiringModelSave()` names `settings`, so `ScopedBuilder` refuses the bulk update, the JSON-path update,
 * the arithmetic extras, the hand-rolled insert and the quiet save that would skip this hook, under any spelling
 * of the column the database would accept. Three paths are not refused: below Eloquent — `toBase()`,
 * `DB::table()`, raw SQL — where no model-layer guard can stand, and a bulk write inside `withoutScopeBecause()`,
 * which stands the builder's per-row refusals down for every guarded column.
 *
 * ⚠️ THE WHOLE MAP IS CHECKED ON EVERY SAVE, not only when `settings` is dirty. So a row holding a value the guard
 * refuses — written by one of those three paths, or stored before this check existed — refuses every save of that
 * row, a rename included, until the value is replaced or reverted (`SettingsWriter::revert()` removes it, and the
 * map it leaves passes). A check that ran only on a dirty column would let the row be re-saved around a value
 * that breaks every page formatting a date.
 *
 * **Invalidation** is not here. It was, on `saved` and `deleted`, and those fire for an evented save or delete of
 * one instance and nothing else — see `ScopedBuilder::forgettingResolvedSettings()`, which runs after every write
 * through Eloquent's builder, evented or not.
 */
trait HoldsSettings
{
    public static function bootHoldsSettings(): void
    {
        static::saving(static function (self $model): void {
            SettingsGuard::check($model->getAttribute('settings'), class_basename($model).' '.($model->getKey() ?? '(new)'));
        });
    }
}
