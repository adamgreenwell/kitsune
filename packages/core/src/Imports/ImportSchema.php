<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Imports;

use DomainException;
use Illuminate\Support\Collection;
use Kitsune\Core\Http\Api\EntryFieldContract;
use Kitsune\Core\Models\EntryType;

/** @internal */
final class ImportSchema
{
    /** @param Collection<int, EntryType> $types */
    public static function digest(Collection $types): string
    {
        $schema = [];

        foreach ($types->sortBy('id') as $type) {
            // Do not reuse the entry type's already-loaded field definitions across operator actions.
            $type = $type->fresh(['fields.fieldStorage'])
                ?? throw new DomainException('A destination entry type is no longer available.');
            $contract = EntryFieldContract::forType($type);
            $fields = [];
            foreach ($contract->definitions() as $handle => $field) {
                $storage = $field->storage;
                $fields[$handle] = [
                    'storage_id' => $storage->getKey(),
                    'type' => $storage->type,
                    'cardinality' => $field->cardinality(),
                    'strategy' => $storage->strategy()->value,
                    'column' => $storage->promotedColumn(),
                    'required' => $field->isRequired(),
                    'settings' => $storage->getAttribute('settings'),
                ];
            }

            $schema[$type->id] = [
                'handle' => $type->handle,
                'media' => $type->is_media,
                'subject_field_id' => $type->subject_field_id,
                'fields' => $fields,
                'validation_shape' => $contract->schema(write: true),
            ];
        }

        return hash('sha256', "kitsune.import-schema.v1\0".json_encode(self::canonical($schema), JSON_THROW_ON_ERROR));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }
}
