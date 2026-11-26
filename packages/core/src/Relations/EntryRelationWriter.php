<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Relations;

use Closure;
use Illuminate\Validation\ValidationException;
use Kitsune\Core\Fields\StorageStrategy;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRelation;
use Kitsune\Core\Models\EntryTranslationGroup;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Schema\FieldStorageWrites;
use Kitsune\Core\Tenancy\Context;

/** Writes the editor's ordered relation choices using the existing source-entry and field lock order. */
final class EntryRelationWriter
{
    /**
     * New selections name a readable entry. Existing choices can be retained by their field-local link ID.
     *
     * @param  list<array{entry: int, pin?: bool, follow?: bool}|array{keep: int}>  $choices
     * @param  Closure(Entry): bool  $canEdit
     * @param  Closure(Entry): bool  $canRead
     */
    public function replace(Entry $source, FieldStorage $storage, array $choices, Closure $canEdit, Closure $canRead): void
    {
        $site = app(Context::class);
        $orgId = $site->orgId();
        $key = $source->getKeyForAuthorization();

        if ($orgId === null || $site->siteId() === null || ! $source->exists || $key === null
            || ! $storage->exists || $storage->getConnection() !== $source->getConnection()) {
            $this->refuse('Select a relation field on a readable Site entry.');
        }

        $entries = $source->newQuery()->useWritePdo()->where('org_id', $orgId);
        $record = (clone $entries)->find($key);
        if ($record === null || ! $canEdit($record)) {
            $this->refuse('This entry cannot be edited here.');
        }

        $storageId = (int) $storage->getRawOriginal($storage->getKeyName());
        $record->writeRelationsAndReconcile(function () use ($record, $entries, $storageId, $choices, $canEdit, $canRead, $orgId): void {
            $source = (clone $entries)->whereKey($record->id)->lockForUpdate()->first();
            if ($source === null || ! $canEdit($source)) {
                $this->refuse('This entry cannot be edited here.');
            }

            $connection = $source->getConnectionName();
            FieldStorageWrites::lockRelation($source->getConnection(), $storageId);
            $field = (new Field)->setConnection($connection)->newQuery()->useWritePdo()
                ->where('entry_type_id', $source->entry_type_id)->where('field_storage_id', $storageId)->first();

            $definition = (new FieldStorage)->setConnection($connection)->newQuery()->useWritePdo()->find($storageId);
            if ($field === null || $definition === null || $definition->strategy() !== StorageStrategy::Relational
                || ($definition->org_id !== null && $definition->org_id !== $orgId)) {
                $this->refuse('This entry does not have that relation field.');
            }

            $relations = (new EntryRelation)->setConnection($connection)->newQuery()
                ->where('org_id', $orgId)->where('source_entry_id', $source->id)->where('field_storage_id', $storageId);
            $existing = (clone $relations)->lockForUpdate()->orderBy('ordering')->orderBy('id')->get()->keyBy('id');

            $types = (array) ($definition->settings['targetTypes'] ?? []);
            $subject = (new EntryType)->setConnection($connection)->newQuery()->useWritePdo()
                ->whereKey($source->entry_type_id)->value('subject_field_id');
            $pinOnly = $subject !== null && (int) $subject === $field->id;

            $selected = [];
            foreach ($choices as $choice) {
                if (isset($choice['keep'])) {
                    $link = $existing->get($choice['keep']);
                    if ($link === null) {
                        $this->refuse('A retained relation has changed. Reload the field and try again.');
                    }

                    $target = [$link->target_entry_id, $link->target_group_id];
                } else {
                    $entry = (clone $entries)->find($choice['entry']);
                    if ($entry === null || ! $canRead($entry) || ($types !== [] && ! in_array($entry->type_handle, $types, true))) {
                        $this->refuse('One of the selected entries is unavailable for this field.');
                    }

                    $target = [$entry->id, null];
                    $canFollow = ! $pinOnly && ! ($choice['pin'] ?? false) && $entry->site_id !== null && $entry->translation_group !== null;
                    if (($choice['follow'] ?? false) && ! $canFollow) {
                        $this->refuse('The selected entry can no longer be followed as a translation group.');
                    }

                    if ($canFollow) {
                        $group = (new EntryTranslationGroup)->setConnection($connection)->newQuery()->useWritePdo()
                            ->where('org_id', $orgId)->where('uuid', $entry->translation_group)->value('id');
                        if ($group === null) {
                            $this->refuse('The selected entry’s translation group is unavailable.');
                        }

                        $target = [null, (int) $group];
                    }

                    $link = $existing->first(fn (EntryRelation $row): bool => [
                        $row->target_entry_id === null ? null : (int) $row->target_entry_id,
                        $row->target_group_id === null ? null : (int) $row->target_group_id,
                    ] === $target);
                }

                $identity = $target[1] === null ? 'entry:'.$target[0] : 'group:'.$target[1];
                $selected[$identity] ??= ['link' => $link, 'target' => $target];
            }

            if ($definition->cardinality > 0 && count($selected) > $definition->cardinality) {
                $this->refuse('The selection exceeds this field’s relation limit.');
            }

            $kept = collect($selected)->pluck('link')->filter()->map(fn (EntryRelation $link): int => (int) $link->id)->all();
            $removed = $existing->except($kept)->modelKeys();
            if ($removed !== []) {
                (clone $relations)->whereKey($removed)->delete();
            }

            foreach (array_values($selected) as $position => $selection) {
                $link = $selection['link'];
                if ($link !== null) {
                    $link->update(['ordering' => $position]);

                    continue;
                }

                [$pin, $group] = $selection['target'];
                $relations->create([
                    'org_id' => $orgId,
                    'source_entry_id' => $source->id,
                    'field_storage_id' => $storageId,
                    'target_entry_id' => $pin,
                    'target_group_id' => $group,
                    'ordering' => $position,
                ]);
            }
        });
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['relations' => $message]);
    }
}
