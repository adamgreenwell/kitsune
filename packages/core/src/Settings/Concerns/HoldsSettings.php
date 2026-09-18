<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Settings\Concerns;

use Kitsune\Core\Settings\SettingsGuard;
use Kitsune\Core\Tenancy\ScopeWrites;

/**
 * An org, site group or site: a level of ADR-022's hierarchy, holding its overrides in a `settings` column.
 *
 * **Validation**, on `saving`, for every path that saves the model — the settings writer included. `SettingsGuard`
 * refuses a map that is not one and a timezone PHP does not list, so the write fails before it reaches the row.
 *
 * ⚠️ THIS HOOK IS THE EARLY CHECK, NOT THE LAST ONE. Listeners run in registration order, so a host's `saving`
 * listener registered after the model boots runs after this one and before the write — and one that set a refused
 * timezone was stored (Codex, #127). `ScopedBuilder::checkWrittenSettings()` therefore checks the value it is
 * handed to write, which is what reaches the row. This one stays because it checks the whole map on every save,
 * including one that does not write `settings`, which the builder never sees.
 *
 * The model's `columnsRequiringModelSave()` names `settings`, so `ScopedBuilder` also refuses the bulk update, the
 * JSON-path update, the arithmetic extras, the hand-rolled insert and the quiet save that would skip this hook,
 * under any spelling of the column the database would accept. What reaches the row unchecked: anything below
 * Eloquent — `toBase()`, `DB::table()`, raw SQL — where no model-layer guard can stand, and a JSON-path write inside
 * `withoutScopeBecause()`, which stands the per-row refusals down and leaves no whole map to judge. A whole map
 * written inside the escape hatch is still checked by the builder.
 *
 * ⚠️ THE WHOLE MAP IS CHECKED ON EVERY SAVE, not only when `settings` is dirty. So a row holding a value the guard
 * refuses — written by one of those unchecked paths, or stored before this check existed — refuses every save of that
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
            SettingsGuard::check(self::settingsThisSaveLeaves($model), class_basename($model).' '.($model->getKey() ?? '(new)'));
        });
    }

    /**
     * The map the row will hold once this save is done: the instance's own when it carries the column, the stored
     * one when it was loaded without it.
     *
     * ⚠️ A PROJECTION HID THE STORED VALUE. A model loaded with `select('id', 'name')` has no `settings` attribute,
     * so `getAttribute('settings')` read null and a rename passed — though the row held a refused value, which the
     * docblock above says refuses every save until it is replaced. Codex found it on #127. Such a save writes only
     * its dirty columns and leaves the stored map where it is, so the stored map is the one to judge.
     */
    private static function settingsThisSaveLeaves(self $model): mixed
    {
        if (! $model->exists || array_key_exists('settings', $model->getAttributes())) {
            return $model->getAttribute('settings');
        }

        // ⚠️ FROM THE INSTANCE, ON ITS OWN CONNECTION. `withoutScopeBecause()` is a static call and makes a fresh model
        // on the default connection, so a holder loaded from another one had its key read in the default database —
        // another row, or none, and a refused value on the real row survived the save (Codex, #127). Every scope is
        // removed, soft deletes included: this is the exact row being saved, not a lookup of rows it may see.
        return ScopeWrites::suspend(fn () => $model->newQueryWithoutScopes()
            ->whereKey($model->getKey())
            ->value('settings'));
    }
}
