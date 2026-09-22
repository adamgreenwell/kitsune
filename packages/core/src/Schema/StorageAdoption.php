<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Schema;

use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use RuntimeException;

/**
 * Adopt an existing `field_storage` row, or create one — the rule, in one place.
 *
 * @internal
 *
 * `field_storage` is reused across entry types by design (ADR-006), so a handle already defined in an org is
 * ADOPTED rather than duplicated: creating a second row is impossible anyway — `UNIQUE (org_id, handle)` — and
 * quietly forking the shape is what the split exists to prevent.
 *
 * ⚠️ ADOPTION KEEPS THE EXISTING DEFINITION; IT DOES NOT REWRITE IT. The shared row is shared, and one caller
 * cannot speak for all of it. Keeping the old type and cardinality while overwriting `pii_class`, `settings`
 * and `is_indexed` was measured to be the worst of both: every other field sharing that storage changed
 * behaviour, and the field just created was not even the type its author selected.
 *
 * ⚠️ SO A DIVERGENT REQUEST IS REFUSED RATHER THAN SILENTLY ADOPTED, on all five attributes and not just the
 * shape. Selecting `personal` for a handle whose stored row says `none` reported success and attached `none`,
 * so an erasure would never reach that field; choosing `decimal` for a handle stored as `integer` gave a field
 * that truncates. Classification and settings define observable behaviour exactly as much as type and
 * cardinality do, so they get the same treatment.
 *
 * ⚠️ EXTRACTED FROM `FieldsRelationManager`, WHICH IS NOW A CALLER. It lived there, coupled to Filament
 * form-state keys, so the blueprint applier could only have re-implemented it — and this project has already
 * found this exact rule drifting in three other places. Two callers, one encoding. The admin keeps its own
 * presentation concerns (turning a refusal into a notification rather than a 500); what moved here is the
 * decision.
 */
final class StorageAdoption
{
    /**
     * Return the storage row for `$desired` in `$orgId`, creating it if this org has no such handle.
     *
     * @param  EntryType|null  $forType  when given, refuses a second field on that type using the same storage
     *
     * @throws RuntimeException when an existing row cannot be adopted, naming what diverges
     */
    public static function resolve(?int $orgId, DesiredStorage $desired, ?EntryType $forType = null): FieldStorage
    {
        $existing = FieldStorage::query()
            ->where('org_id', $orgId)
            ->where('handle', $desired->handle)
            ->first();

        if ($existing === null) {
            return self::create($orgId, $desired);
        }

        self::refuseIncompatibleShape($existing, $desired);
        self::refuseDivergentDefinition($existing, $desired);

        if ($forType !== null) {
            self::refuseSecondFieldOnType($existing, $forType);
        }

        return $existing;
    }

    /** Whether this org already holds the handle, for a caller that wants to report adoption rather than cause it. */
    public static function exists(?int $orgId, string $handle): bool
    {
        return FieldStorage::query()->where('org_id', $orgId)->where('handle', $handle)->exists();
    }

    private static function create(?int $orgId, DesiredStorage $desired): FieldStorage
    {
        /*
         * Attribute by attribute through one `save()`, because every bulk creation path on this table is
         * refused unconditionally: `pii_class` fails closed per row and those paths dispatch nothing, so an
         * unclassified field would persist — which ADR-020 says cannot exist.
         */
        $storage = new FieldStorage(['org_id' => $orgId, 'handle' => $desired->handle]);

        $storage->type = $desired->type;
        $storage->cardinality = $desired->cardinality;
        $storage->pii_class = $desired->piiClass;
        $storage->is_indexed = $desired->isIndexed;
        $storage->setAttribute('settings', $desired->settings);
        $storage->save();

        return $storage;
    }

    private static function refuseIncompatibleShape(FieldStorage $existing, DesiredStorage $desired): void
    {
        if ($existing->type === $desired->type && (int) $existing->cardinality === $desired->cardinality) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Handle [%s] already describes a %s field holding %s in this organisation, and storage is shared '
            .'across entity types (ADR-006) — so reusing it here would give you that field, not the %s asked '
            .'for. Choose a different handle, or take the existing field as it is.',
            $existing->handle,
            $existing->type,
            (int) $existing->cardinality === 1 ? 'one value' : 'many values',
            $desired->type,
        ));
    }

    private static function refuseDivergentDefinition(FieldStorage $existing, DesiredStorage $desired): void
    {
        $differences = [];

        /* Both sides are one of three known strings. */
        if ((string) $existing->pii_class !== $desired->piiClass) {
            $differences[] = 'privacy classification';
        }

        if ((bool) $existing->is_indexed !== $desired->isIndexed) {
            $differences[] = 'indexing';
        }

        /*
         * ⚠️ Loose comparison, and only here. It is what lets `'320'` equal `320` while still catching a real
         * edit — PHP 8 compares a non-numeric string AS a string, so it does not collapse distinct values the
         * way PHP 7's would have.
         */
        if ($desired->settings != ($existing->settings ?? [])) {
            $differences[] = 'settings';
        }

        if ($differences === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Handle [%s] already describes a field in this organisation, and storage is shared across entity '
            .'types (ADR-006) — so using it here ADOPTS that definition rather than creating a second one. Its '
            .'%s %s from what was asked for, and adopting it would give you the stored behaviour without '
            .'saying so. Match the existing definition, or choose a different handle.',
            $existing->handle,
            implode(' and ', $differences),
            count($differences) === 1 ? 'differs' : 'differ',
        ));
    }

    private static function refuseSecondFieldOnType(FieldStorage $storage, EntryType $type): void
    {
        $existing = Field::query()
            ->where('entry_type_id', $type->getKey())
            ->where('field_storage_id', $storage->getKey())
            ->first();

        if ($existing === null) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Entry type [%s] already has a field using storage [%s] — it is labelled "%s". Storage is shared '
            .'across entity types by design (ADR-006), but a type can only use a given definition once.',
            $type->handle,
            $storage->handle,
            $existing->label,
        ));
    }
}
