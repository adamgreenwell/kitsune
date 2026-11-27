<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Concerns;

use Closure;
use Kitsune\Core\Fields\Control;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Fields\FieldTypeRegistry;
use Kitsune\Core\Filament\Schemas\FieldValueRenderer;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;

/** Fills relation picker state without attaching write hooks to the page. */
trait FillsFieldRelations
{
    /**
     * @param  array<string, mixed>  $data
     * @param  Closure(Entry, list<FieldStorage>): array<int, list<string>>  $values
     * @return array<string, mixed>
     */
    protected function fillFieldRelations(array $data, Closure $values): array
    {
        $record = $this->getRecord();

        if (! $record instanceof Entry) {
            return $data;
        }

        $fields = $this->relationFields();

        if ($fields === []) {
            return $data;
        }

        $storages = array_map(static fn (Field $field): FieldStorage => $field->fieldStorage, $fields);
        $byStorage = $values($record, $storages);

        foreach ($fields as $index => $field) {
            $storage = $storages[$index];
            $ids = $byStorage[$storage->id] ?? [];

            $data[FieldValueRenderer::RELATION_STATE_PREFIX][$storage->handle] =
                (new FieldConfig($storage, $field))->isMultiValue() ? $ids : ($ids[0] ?? null);
        }

        return $data;
    }

    /**
     * The current entry type's relation fields, with storage loaded.
     *
     * Empty when no type is bound — a page can be constructed outside `/c/{type}`, and
     * reaching for `app(EntryType::class)` unguarded is what 500s the dashboard.
     *
     * @return list<Field>
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
