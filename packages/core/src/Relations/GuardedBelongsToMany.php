<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Relations;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;

/**
 * `attach()` and `updateExistingPivot()`, made atomic.
 *
 * ⚠️ `EntryRelation` checks a field's cardinality by counting existing rows
 * and then inserting, which is two statements. Two requests attaching to the
 * same single-valued relation can both count zero and both insert —
 * restoring exactly the two-subject state the check exists to prevent, under
 * ordinary concurrent load rather than any misuse (ADR-020).
 *
 * So the write runs in a transaction that first takes a row lock on the
 * SOURCE entry. Concurrent attaches to the same entry serialise on that row;
 * attaches to different entries do not contend, which is the granularity that
 * matters — the invariant is per (source, field), never global.
 *
 * SQLite serialises writers anyway, so `lockForUpdate()` is a no-op there and
 * the guarantee holds for a different reason. PostgreSQL and MySQL need it.
 *
 * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
 * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends BelongsToMany<TRelatedModel, TDeclaringModel, Pivot>
 */
class GuardedBelongsToMany extends BelongsToMany
{
    /** @param  array<string, mixed>  $attributes */
    public function attach($id, array $attributes = [], $touch = true)
    {
        $this->serialised(fn () => parent::attach($id, $attributes, $touch));
    }

    /** @param  array<string, mixed>  $attributes */
    public function updateExistingPivot($id, array $attributes, $touch = true)
    {
        return $this->serialised(fn () => parent::updateExistingPivot($id, $attributes, $touch));
    }

    /**
     * Run the write behind a lock on the parent row.
     *
     * Reuses an outer transaction when there is one, so a caller that already
     * wrapped several attaches gets one lock rather than a lock per row.
     */
    private function serialised(callable $write): mixed
    {
        return DB::transaction(function () use ($write): mixed {
            $this->getParent()->newQuery()
                ->whereKey($this->getParent()->getKey())
                ->lockForUpdate()
                ->first();

            return $write();
        });
    }
}
