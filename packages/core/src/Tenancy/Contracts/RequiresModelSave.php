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
 * ⚠️ IT NO LONGER ASSUMES EVERY IMPLEMENTOR INCREMENTS, and that assumption is gone because it rested
 * on `getIncrementing()` — which a caller can change through `getModel()`. The guards ask
 * `isPerformingModelSave()` instead, which is true for a non-incrementing model's `insert()` and false
 * for a hand-rolled one, so the key strategy stopped being part of the decision.
 */
interface RequiresModelSave
{
    /**
     * Columns a bulk write must not touch, with the reason it cannot.
     *
     * @return array<string, string>
     */
    public static function columnsRequiringModelSave(): array;

    /**
     * Whether the columns above have been derived and validated for the write in flight.
     *
     * ⚠️ THE DISCRIMINATOR USED TO BE "THE ATTRIBUTE IS PRESENT ON THE MODEL", and review showed
     * that proves nothing: `createQuietly()`, `saveQuietly()` and `withoutEvents()` populate
     * attributes while suppressing the `saving` callback that derives them. A caller can supply an
     * attribute; only the code that derives can set this. `DerivesGuardedColumns` implements it.
     */
    public function guardedColumnsAreDerived(): bool;

    /**
     * Whether this instance is inside its OWN save attempt right now.
     *
     * ⚠️ BECAUSE EVERY OTHER DISCRIMINATOR WAS CALLER-MUTABLE, which review established twice over.
     * `Builder::getModel()` and `setModel()` are public, so `$model->exists` and
     * `$model->getIncrementing()` are both things a caller can arrange:
     *
     *   $query = Entry::query(); $query->setModel($loaded); $query->update([…]);
     *       every matching row converted against ONE entry's schema — measured, 2 rows
     *   $query = Site::query(); $query->getModel()->setIncrementing(false); $query->insert([…]);
     *       a hand-rolled bulk insert classed as a non-incrementing model save, landing an
     *       overlapping cross-org claim past refuseOverlappingClaim() — measured, 2 rows on one host
     *
     * This is set by `performInsert()`/`performUpdate()` and cleared in their `finally`, so it is true
     * only during the dynamic extent of a real save. There is no setter, and a model handed to
     * `setModel()` is not inside a save, so it cannot be manufactured.
     */
    public function isPerformingModelSave(): bool;
}
