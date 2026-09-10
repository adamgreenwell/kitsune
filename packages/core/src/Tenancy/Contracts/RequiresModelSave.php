<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tenancy\Contracts;

/**
 * A model with columns whose guards can only run per row.
 *
 * ⚠️ This exists because the same defect has now been found on six guards in
 * this project: a check written as a model event covers the row-at-a-time path
 * and nothing else. `Model::query()->update([...])` instantiates no models and
 * dispatches nothing, so a bulk write reached forbidden states that every
 * `save()` refuses.
 *
 * Rather than discovering it a seventh time, a model names the columns whose
 * correctness depends on the row — its lock state, its nomination, its
 * denormalised siblings — and `ScopedBuilder` refuses to write them in bulk.
 * The model event stays as the check; this is what makes it the only door.
 *
 * ⚠️ AND FOR A WHILE THAT SENTENCE WAS ONLY TRUE OF `update()`. The INSERT family
 * was unguarded, so `Site::query()->insert([… 'base_url' => 'https://x.test' …])`
 * created a row with `canonical_host = NULL` — a site declaring a public URL and
 * reachable at none (issue #60). Six guards had been found bypassed by a bulk
 * write; this docblock then described the fix as complete while half the doors
 * were open, which is the same failure one level up.
 *
 * ⚠️ THE DISCRIMINATOR IS THE METHOD, NOT `$model->exists`. `performInsert()`
 * writes through the same builder and `exists` is false during an insert, so the
 * obvious guard refuses every ordinary create — measured, reverted, and filed
 * rather than retried. `insertGetId()` is `performInsert()`'s path for an
 * incrementing model and is allowed; `insert()` and the `…Using` forms are
 * refused, which is how `AuditedBuilder` already handled `Entry`.
 *
 * ⚠️ WHICH ASSUMES EVERY IMPLEMENTOR INCREMENTS, because a non-incrementing
 * model's `performInsert()` uses `insert()`. All four do today and
 * `PerRowInsertGuardTest` asserts it, so the day one does not, the test fails
 * rather than its creates.
 */
interface RequiresModelSave
{
    /**
     * Columns a bulk write must not touch, with the reason it cannot.
     *
     * @return array<string, string>
     */
    public static function columnsRequiringModelSave(): array;
}
