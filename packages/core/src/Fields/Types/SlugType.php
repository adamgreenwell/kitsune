<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Fields\Types;

use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Fields\Projection;
use Kitsune\Core\Fields\StorageStrategy;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Validation\Rule;

/**
 * Promoted to a real column: URL resolution touches it on every public
 * request, and it carries a uniqueness constraint a JSON path cannot.
 */
final class SlugType extends BaseFieldType
{
    public static function handle(): string
    {
        return 'slug';
    }

    public static function label(): string
    {
        return 'Slug';
    }

    public static function icon(): string
    {
        return 'heroicon-o-link';
    }

    public function strategy(): StorageStrategy
    {
        return StorageStrategy::Promoted;
    }

    public function isIndexable(): bool
    {
        return true;
    }

    public function supportsCardinality(): bool
    {
        return false;
    }

    /** Already a real column, so there is nothing to project. */
    public function projection(FieldConfig $config): ?Projection
    {
        return null;
    }

    /** ADR-015: a slug is promoted, so its data is `entries.slug`. */
    public function promotedColumn(): string
    {
        return 'slug';
    }

    protected function castToStorage(mixed $input, FieldConfig $config): mixed
    {
        return $input === null || $input === '' ? null : str((string) $input)->slug()->value();
    }

    /** @return array<int, mixed> */
    protected function scalarValidationRules(FieldConfig $config): array
    {
        return [
            // ⚠️ `bail` FIRST. Laravel keeps evaluating rules after `string`
            // fails, so a slug submitted as an array reached the normaliser
            // below and `castToStorage()` attempted a string cast on it —
            // producing a PHP Error instead of a validation response, from
            // input that had already been rejected.
            'bail',
            'string',
            'max:255',
            // scopedUnique, never Laravel's unique — that rule bypasses
            // Eloquent and would tell one org a slug is taken because
            // another org holds it. Scoped to the entry type as well,
            // matching UNIQUE (site_id, entry_type_id, slug).
            // ⚠️ The entry being edited is EXCLUDED. Passing null here meant
            // an update checked the row against itself, so saving a page
            // without touching its slug reported the slug as already taken.
            Rule::scopedUnique(Entry::class, 'slug', $config->record?->getKey(), function ($query) use ($config): void {
                // Falls back to the record's own type, so the constraint
                // survives a path with no bound type — matching what the
                // static form already did.
                $typeId = app()->bound(EntryType::class)
                    ? app(EntryType::class)->getKey()
                    : $config->record?->entry_type_id;

                if ($typeId !== null) {
                    $query->where('entry_type_id', $typeId);
                }
            }, fn (mixed $value): mixed => $this->castToStorage($value, $config)),
        ];
    }
}
