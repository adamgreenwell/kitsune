<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tenancy\Contracts;

/**
 * A model that refuses some rows at creation, on every path the builder offers — not only the `creating` listener.
 *
 * ⚠️ A LISTENER IS NOT A DOOR. A quiet save, `createQuietly()`, and the builder's `insert()`, `insertGetId()` and
 * `insertOrIgnore()` all create a row without `creating` running (measured, ADR-041 review). `ScopedBuilder` asks this
 * of every row those paths carry and of an upsert's insert half, keyed by column as the database writes it, and refuses
 * `insertUsing()` and `insertOrIgnoreUsing()` outright, whose rows it cannot read — so a model states its refusal once
 * and every Eloquent door enforces it. `->toBase()` and `DB::table()` stay out of reach, the boundary `ScopedBuilder`
 * already states.
 *
 * The first is `MediaFile`: no row is created offered to readers (ADR-041 decisions 1 and 2).
 */
interface RefusesInsertedRows
{
    /**
     * Refuse one row about to be inserted, by throwing; return to let it through.
     *
     * @param  array<string, mixed>  $row
     */
    public static function refuseInsertedRow(array $row): void;
}
