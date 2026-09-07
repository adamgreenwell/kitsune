<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Kitsune\Core\Tenancy\Attributes\Unscoped;
use RuntimeException;

/**
 * A row in `entry_relations`, with the field's own rules enforced on write.
 *
 * ⚠️ This exists because `cardinality` and `targetTypes` were METADATA that
 * nothing checked when a row was written. `RelationType` enforces both in
 * validation, and `attach()` goes nowhere near validation — so two ordinary
 * `attach()` calls gave a single-valued relation two targets, and a pivot
 * could point at an entry of a type the field forbids.
 *
 * That mattered most for a nominated subject field: `subjectValue()` returned
 * both ids and `whereSubjectIs()` returned the shared record for either
 * person, which is the disclosure the nomination guard was written to prevent
 * — reachable through the ordinary API rather than by any misuse (ADR-020).
 *
 * A pivot model rather than a database constraint because the rule is
 * conditional: `UNIQUE (source_entry_id, field_storage_id)` would be right
 * for cardinality one and wrong for every multi-value relation, and no engine
 * expresses "unique when a column in another table says so".
 *
 * Unscoped because it is reached only through `Entry::related()`, which
 * constrains `org_id` through `withPivotValue()` — the same reasoning as
 * `EntryRevision`, which is reached only through its Entry.
 *
 * @property int $source_entry_id
 * @property int $target_entry_id
 * @property int|null $field_storage_id
 * @property int $org_id
 */
#[Unscoped]
class EntryRelation extends Pivot
{
    public $incrementing = true;

    protected $table = 'entry_relations';

    protected static function booted(): void
    {
        static::creating(function (self $relation): void {
            $relation->guardCardinality();
            $relation->guardTargetType();
        });
    }

    private function guardCardinality(): void
    {
        $storage = $this->storage();

        if ($storage === null || $storage->cardinality < 1) {
            // -1 is the explicit unlimited.
            return;
        }

        $existing = static::query()
            ->where('source_entry_id', $this->source_entry_id)
            ->where('field_storage_id', $this->field_storage_id)
            ->count();

        if ($existing >= $storage->cardinality) {
            throw new RuntimeException(sprintf(
                'Field [%s] holds %d target(s) and already has that many on this entry. '
                .'Detach one before attaching another — silently exceeding it is how a field '
                .'nominated as a subject identifier comes to name two people (ADR-020).',
                $storage->handle,
                $storage->cardinality,
            ));
        }
    }

    private function guardTargetType(): void
    {
        $storage = $this->storage();

        if ($storage === null) {
            return;
        }

        /** @var array<int, string> $targets */
        $targets = (array) (($storage->settings['targetTypes'] ?? []) ?: []);

        if ($targets === []) {
            return;
        }

        // Unscoped on purpose: the SCOPE of the target is checked elsewhere,
        // and reading its type here must not depend on the caller's context.
        $handle = Entry::withoutScopeBecause(
            'reading the target type to enforce a constraint, not to expose the row',
            fn ($query) => $query->whereKey($this->target_entry_id)->value('type_handle'),
        );

        if ($handle !== null && ! in_array($handle, $targets, true)) {
            throw new RuntimeException(sprintf(
                'Field [%s] accepts only [%s], and entry %d is a [%s]. `attach()` bypasses '
                .'validation, so the constraint has to hold here too.',
                $storage->handle,
                implode(', ', $targets),
                (int) $this->target_entry_id,
                $handle,
            ));
        }
    }

    private function storage(): ?FieldStorage
    {
        return $this->field_storage_id === null
            ? null
            : FieldStorage::query()->find($this->field_storage_id);
    }
}
