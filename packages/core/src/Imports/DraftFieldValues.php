<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Imports;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Kitsune\Core\Fields\StorageStrategy;
use Kitsune\Core\Http\Api\EntryFieldContract;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;

/** @internal */
final class DraftFieldValues
{
    /**
     * @param  array{title: string, slug: string}  $draft
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function attributes(EntryType $type, ?Entry $entry, array $draft, array $values): array
    {
        $contract = EntryFieldContract::forType($type);
        $definitions = $contract->definitions();
        $rules = $contract->rules($entry, partial: $entry !== null);
        foreach ($definitions as $handle => $config) {
            if ($config->storage->strategy() === StorageStrategy::Relational) {
                if (array_key_exists($handle, $values)) {
                    throw ValidationException::withMessages(['values.'.$handle => 'Provide relation values through the relation planning path.']);
                }
                unset($rules['values.'.$handle], $rules['values.'.$handle.'.*']);
            }
        }
        $validated = Validator::make(['values' => $values], $rules)->validate();
        $stored = $entry->values ?? [];
        $inline = $stored;
        $attributes = $draft;
        $promoted = [...$draft, 'status' => 'draft', 'published_at' => null];

        foreach ($validated['values'] as $handle => $value) {
            $storage = $definitions[$handle]->storage;
            match ($storage->strategy()) {
                StorageStrategy::Inline => $inline[$handle] = $value,
                StorageStrategy::Promoted => self::verifyPromoted($promoted, (string) $storage->promotedColumn(), $handle, $value),
                StorageStrategy::Relational => throw ValidationException::withMessages([
                    'values.'.$handle => 'Provide relation values through the relation planning path.',
                ]),
            };
        }

        if ($inline !== $stored) {
            $attributes['values'] = $inline;
        }

        return $attributes;
    }

    /** @param array<string, mixed> $promoted */
    private static function verifyPromoted(array $promoted, string $column, string $handle, mixed $value): void
    {
        if (! array_key_exists($column, $promoted) || $promoted[$column] !== $value) {
            throw ValidationException::withMessages(['values.'.$handle => 'The promoted value differs from the approved draft metadata.']);
        }
    }
}
