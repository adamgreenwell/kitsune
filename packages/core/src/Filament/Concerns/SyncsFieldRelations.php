<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Concerns;

use Kitsune\Core\Fields\Control;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Fields\FieldTypeRegistry;
use Kitsune\Core\Filament\Schemas\FieldValueRenderer;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;

/**
 * Carries relation fields between the form and `entry_relations`.
 *
 * ⚠️ SEPARATE FROM THE ATTRIBUTE WRITE, AND IT HAS TO BE. Every other field value is an
 * attribute on the entry, so it is saved by the entry. A relation is a row in another
 * table that references the entry's id — which does not exist yet while a new entry is
 * being created — so it cannot be part of the same write and has to happen after it.
 *
 * ⚠️ AND IT MUST NOT GO THROUGH `values`. `FieldValueRenderer` gives a relation control a
 * state path under `relations.{handle}` and marks it `dehydrated(false)` precisely so the
 * value-conversion pipeline never sees it: writing entry IDs into the `values` JSON column
 * is what ADR-015 forbids, because JSON cannot answer "what references this?" without a
 * full scan and cascade-on-delete becomes application code that is eventually wrong.
 *
 * Shared by the create and edit pages rather than written twice, because two copies of a
 * lifecycle hook is one copy that gets fixed.
 */
trait SyncsFieldRelations
{
    /**
     * Fills the form's relation state from the entry's existing relations.
     *
     * ⚠️ Ordered by `relatedIdsForField()`, because an author's arrangement is content. A
     * picker hydrated from an unordered read reshuffles on every page load, and the author
     * cannot tell whether their last save took.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        if (! $record instanceof Entry) {
            return $data;
        }

        foreach ($this->relationFields() as $field) {
            $ids = $record->relatedIdsForField($field->fieldStorage);

            // ⚠️ A single-value picker wants a scalar, not a one-element array — Filament
            // renders an array into a `multiple()` control and nothing into a single one,
            // so a relation with cardinality 1 would appear empty however it was saved.
            $data[FieldValueRenderer::RELATION_STATE_PREFIX][$field->fieldStorage->handle] =
                (new FieldConfig($field->fieldStorage, $field))->isMultiValue()
                    ? $ids
                    : ($ids[0] ?? null);
        }

        return $data;
    }

    /**
     * Removes relation state before the entry is written.
     *
     * ⚠️ `dehydrated(false)` ON THE CONTROL IS NOT ENOUGH, and assuming it was cost three
     * broken revision tests and two rounds of 500s. It suppresses the leaf value, but the
     * nested state path still leaves its CONTAINER in the form data — the save then tried
     * `update "entries" set … "relations" = []` and died on `no such column: relations`.
     *
     * Any form key that is not a model attribute has to be taken out here. That is not a
     * workaround: the form legitimately carries state the entry does not own, because a
     * relation is a row in another table (ADR-015), and the page is the thing that knows
     * where each half belongs.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->withoutRelationState($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->withoutRelationState($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withoutRelationState(array $data): array
    {
        unset($data[FieldValueRenderer::RELATION_STATE_PREFIX]);

        return $data;
    }

    /**
     * Writes the form's relation state after the entry itself is saved.
     *
     * ⚠️ `afterSave` on edit and `afterCreate` on create, which is why this trait exposes
     * both and they share one body: on create the entry has no id until the write
     * completes, so there is nothing for a relation row to reference before then.
     */
    protected function afterSave(): void
    {
        $this->syncRelationsFromForm();
    }

    protected function afterCreate(): void
    {
        $this->syncRelationsFromForm();
    }

    private function syncRelationsFromForm(): void
    {
        $record = $this->getRecord();

        if (! $record instanceof Entry) {
            return;
        }

        /** @var array<string, mixed> $state */
        $state = (array) data_get($this->form->getRawState(), FieldValueRenderer::RELATION_STATE_PREFIX, []);

        foreach ($this->relationFields() as $field) {
            $handle = $field->fieldStorage->handle;

            /*
             * ⚠️ `array_key_exists`, NOT a truthiness or null check. An empty selection is a
             * legitimate edit — the author cleared the field — and it arrives as `[]` or
             * `null`. Skipping those would make clearing a relation impossible, and the
             * author would watch the value come back after every save.
             */
            if (! array_key_exists($handle, $state)) {
                continue;
            }

            $ids = $state[$handle];
            $ids = $ids === null ? [] : (array) $ids;

            $record->syncFieldRelations($field->fieldStorage, array_values($ids));
        }
    }

    /**
     * The current entry type's relation fields, with storage loaded.
     *
     * Empty when no type is bound — a page can be constructed outside `/c/{type}`, and
     * reaching for `app(EntryType::class)` unguarded is what 500s the dashboard.
     *
     * @return array<int, Field>
     */
    private function relationFields(): array
    {
        if (! app()->bound(EntryType::class)) {
            return [];
        }

        $registry = app(FieldTypeRegistry::class);

        return app(EntryType::class)
            ->fields()
            ->with('fieldStorage')
            ->orderBy('ordering')
            ->get()
            ->filter(fn (Field $field): bool => $field->fieldStorage !== null
                && $registry->get((string) $field->fieldStorage->type)->control() === Control::EntryPicker)
            ->values()
            ->all();
    }
}
