<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tenancy\Contracts;

/**
 * A model with columns that are written when a row is created and never again.
 *
 * ⚠️ NOT `RequiresModelSave`, WHOSE REFUSALS STAND DOWN INSIDE `withoutScopeBecause()`. That list asks which PATH may
 * write a column, and the escape hatch is a path the caller has vouched for. A column fixed at creation has no path
 * that may change it, so `ScopedBuilder` refuses it on every update — the instance's, a quiet save's, a bulk write,
 * an arithmetic write's extra columns, an upsert's update half — inside the hatch as well as out. The escape hatch
 * decides which path may write a column, not what the column may hold (ADR-022's amendment, of `settings`).
 *
 * The first is `entry_types.is_media` (ADR-042 decision 1), which Codex found flippable inside the hatch on #150.
 */
interface FixesColumnsAtCreation
{
    /**
     * Columns no update may write, with the reason it may not.
     *
     * @return array<string, string>
     */
    public static function columnsFixedAtCreation(): array;
}
