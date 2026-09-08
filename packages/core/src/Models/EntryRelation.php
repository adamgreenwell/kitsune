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

        // ⚠️ And on UPDATE. `updateExistingPivot()` can move an existing row
        // onto a different field, so a second target from an unlimited
        // relation could be repointed at a nominated cardinality-one field —
        // recreating the two-subject disclosure through the ordinary
        // relationship API, with no row ever being created.
        static::updating(function (self $relation): void {
            // ⚠️ source_entry_id too, not only the field. Cardinality is
            // counted per (source, field), so moving a row from source B onto
            // source A — which `updateExistingPivot()` accepts — lands a
            // second target on a nominated cardinality-one field with no
            // concurrency involved at all.
            if ($relation->isDirty(['field_storage_id', 'source_entry_id'])) {
                $relation->guardCardinality();
            }

            if ($relation->isDirty(['field_storage_id', 'target_entry_id'])) {
                $relation->guardTargetType();
            }
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
            // The row being MOVED does not count against its destination.
            ->when($this->exists, fn ($query) => $query->whereKeyNot($this->getKey()))
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

    /**
     * Whether any existing relation would forbid this target's new type.
     *
     * ⚠️ The guards above run when a PIVOT changes. Nothing ran when the
     * TARGET changed: save an allowed target with a different
     * `entry_type_id` and `Entry::saving()` restamps its `type_handle`, while
     * every relation pointing at it survives with a now-forbidden type — and
     * both `subjectValue()` and the relational `whereSubjectIs()` branch keep
     * treating it as the subject, because neither rechecks `targetTypes`
     * (ADR-020).
     *
     * Refused at the type change rather than filtered at read time: a stored
     * relation that no configuration permits is a state to prevent, not one
     * to keep working around.
     */
    public static function forbidsTypeChange(int $targetId, string $newHandle): ?string
    {
        $relations = static::query()->where('target_entry_id', $targetId)->get();

        foreach ($relations as $relation) {
            $storage = $relation->storage();

            if ($storage === null) {
                continue;
            }

            /** @var array<int, string> $targets */
            $targets = (array) (($storage->settings['targetTypes'] ?? []) ?: []);

            if ($targets !== [] && ! in_array($newHandle, $targets, true)) {
                return $storage->handle;
            }
        }

        return null;
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
