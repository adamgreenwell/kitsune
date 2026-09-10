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
use Kitsune\Core\Schema\RevisionWrites;

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
    /** The newest revision id before this save wrote, or null when there was none. */
    private ?int $revisionIdBeforeWrite = null;

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
        $this->rememberRevisionBeforeWrite();

        return $this->withoutRelationState($this->mutateEntryDataBeforeSave($data));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->rememberRevisionBeforeWrite();

        return $this->withoutRelationState($this->mutateEntryDataBeforeCreate($data));
    }

    /**
     * A page's own payload shaping, before the entry is updated.
     *
     * ⚠️ OVERRIDE THIS, NOT FILAMENT'S HOOK, and the distinction is the fix for a defect
     * review found here — a defect that was completely silent.
     *
     * `CreateEntry` already declared `mutateFormDataBeforeCreate()` to stamp `entry_type_id`.
     * PHP resolves a method defined in the CLASS ahead of one supplied by a trait, with no
     * error, no warning and no deprecation, so this trait's copy simply never ran. Creating
     * an entry of any type with a relation field sent `relations` to the insert and died on
     * `no such column: relations`, while the edit path worked perfectly — and the browser
     * suite covered edit only, so nothing caught it.
     *
     * The trait therefore owns Filament's hooks, and a page shapes its payload here, where it
     * cannot displace the relation cleanup by accident.
     *
     * ⚠️ Only the two WRITE hooks get a delegation pair, because only they can break a save.
     * `mutateFormDataBeforeFill()`, `afterSave()` and `afterCreate()` remain trait-owned and
     * remain overridable in the same silent way — `RelationHookOwnershipTest` fails if any
     * page declares one, and the helpers here are `protected` so an override that genuinely
     * has to exist can still call them. Two more delegation hooks nobody uses would be
     * machinery imitating a guarantee; the test is the guarantee.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateEntryDataBeforeSave(array $data): array
    {
        return $data;
    }

    /**
     * A page's own payload shaping, before the entry is created. Override this, not
     * `mutateFormDataBeforeCreate()` — see `mutateEntryDataBeforeSave()` for why.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateEntryDataBeforeCreate(array $data): array
    {
        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function withoutRelationState(array $data): array
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

    protected function syncRelationsFromForm(): void
    {
        $record = $this->getRecord();

        if (! $record instanceof Entry) {
            return;
        }

        /** @var array<string, mixed> $state */
        $state = (array) data_get($this->form->getRawState(), FieldValueRenderer::RELATION_STATE_PREFIX, []);

        $relationsBefore = $record->relationState();

        /*
         * ⚠️ SUSPENDED ACROSS EVERY FIELD, then ONE revision reconciled after. A form save was
         * filing 1 + N revisions, one per relation field — measured `created=1
         * afterRelationSync=2 afterSecondField=3` (issue #59). Each write path files one
         * legitimately; what is new is that a form save performs both, because relation state is
         * written after the entry exists (ADR-015). The result was phantom history — an
         * intermediate snapshot the author never saved — and a bounded 50-version budget spent at
         * twice the rate or worse.
         */
        RevisionWrites::suspend(function () use ($record, $state): void {
            $this->syncEachRelationField($record, $state);
        });

        /*
         * ⚠️ OUTSIDE the suspension, and it needs the revision id from BEFORE the entry write to
         * tell the two cases apart: a save that filed its own revision (complete it in place,
         * because its snapshot predates the relations) from one that filed none because relations
         * were the only change (record one now). Suppressing both unconditionally loses that
         * second case entirely.
         */
        $record->reconcileRevisionAfterRelationSync($this->revisionIdBeforeWrite, $relationsBefore);
    }

    /**
     * The id of the newest revision before this save wrote anything.
     *
     * ⚠️ Captured in the `mutateFormDataBefore*` hooks because those are the last point that runs
     * BEFORE the entry write. Reading it afterwards cannot distinguish a revision this save filed
     * from one that was already there.
     */
    private function rememberRevisionBeforeWrite(): void
    {
        $record = $this->getRecord();

        $this->revisionIdBeforeWrite = $record instanceof Entry && $record->exists
            ? $record->revisions()->max('id')
            : null;
    }

    /** @param  array<string, mixed>  $state */
    private function syncEachRelationField(Entry $record, array $state): void
    {
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
