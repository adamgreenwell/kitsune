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
 * ⚠️ The source is NOT always the relation's parent. `referencedBy()` hangs
 * off the target, so locking the parent there locked the wrong row entirely:
 * two concurrent calls on different targets attaching the same source took
 * different locks, both passed the count, and left that source with two
 * subject targets. The source end is found from the pivot column, not
 * assumed.
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
    /**
     * The pivot column naming the entry a cardinality is counted against.
     *
     * Named concretely, like the invariant it protects: this class exists for
     * `entry_relations` and its docblock is about subject identifiers. A
     * second pivot with a different shape can generalise it then.
     */
    private const SOURCE_COLUMN = 'source_entry_id';

    /** @param  array<string, mixed>  $attributes */
    public function attach($id, array $attributes = [], $touch = true)
    {
        $this->serialised($id, $attributes, fn () => parent::attach($id, $attributes, $touch));
    }

    /** @param  array<string, mixed>  $attributes */
    public function updateExistingPivot($id, array $attributes, $touch = true)
    {
        return $this->serialised($id, $attributes, fn () => parent::updateExistingPivot($id, $attributes, $touch));
    }

    /**
     * Every source entry this write could land a row on.
     *
     * The parent when the relation hangs off the source; the ids being
     * attached when it hangs off the target. An explicit `source_entry_id` in
     * the attributes is a MOVE, so its destination is included too — that is
     * the row the count will be taken against.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<mixed>
     */
    private function sourceKeys(mixed $id, array $attributes): array
    {
        $keys = $this->getForeignPivotKeyName() === self::SOURCE_COLUMN
            ? [$this->getParent()->getKey()]
            : $this->parseIds($id);

        if (isset($attributes[self::SOURCE_COLUMN])) {
            $keys[] = $attributes[self::SOURCE_COLUMN];
        }

        // Sorted so concurrent writers touching the same pair take the locks
        // in the same order, which is what keeps them from deadlocking.
        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    /**
     * Run the write behind a lock on every source entry it could touch.
     *
     * Reuses an outer transaction when there is one, so a caller that already
     * wrapped several attaches gets one lock rather than a lock per row.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function serialised(mixed $id, array $attributes, callable $write): mixed
    {
        $keys = $this->sourceKeys($id, $attributes);

        return DB::transaction(function () use ($keys, $write): mixed {
            if ($keys !== []) {
                // withoutGlobalScopes: this is a lock, not a read of anything
                // that reaches the caller. A source in another scope must
                // still serialise, and a scoped query that matched nothing
                // would take no lock at all.
                $this->getParent()->newQueryWithoutScopes()
                    ->whereKey($keys)
                    ->lockForUpdate()
                    ->get();
            }

            return $write();
        });
    }
}
