<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Fields\Types;

use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Fields\StorageStrategy;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Validation\Rule;

/**
 * Rows in `entry_relations`, never an ID array in JSON (ADR-015).
 *
 * A media picker is this type constrained to media entry types — no new
 * storage strategy, no second permission model, no parallel search index.
 */
final class RelationType extends BaseFieldType
{
    public static function handle(): string
    {
        return 'relation';
    }

    public static function label(): string
    {
        return 'Relation';
    }

    public static function icon(): string
    {
        return 'heroicon-o-link';
    }

    public function strategy(): StorageStrategy
    {
        return StorageStrategy::Relational;
    }

    /** Indexed by the relations table's own indexes, not a generated column. */
    public function isIndexable(): bool
    {
        return false;
    }

    public function toStorage(mixed $input, FieldConfig $config): mixed
    {
        if ($input === null || $input === '') {
            return [];
        }

        return array_values(array_map(intval(...), (array) $input));
    }

    public function fromStorage(mixed $stored, FieldConfig $config): mixed
    {
        return (array) ($stored ?? []);
    }

    /** @return array<string, mixed> */
    public function apiSchema(FieldConfig $config): array
    {
        return ['type' => 'array', 'items' => ['type' => 'integer']];
    }

    /** @return array<int, mixed> */
    public function validationRules(FieldConfig $config): array
    {
        return [...parent::validationRules($config), 'array'];
    }

    /**
     * The cross-org check, applied per id at `handle.*`.
     *
     * ⚠️ This lived in `validationRules()` under an `'array_keys'` key, which
     * is not a Laravel concept. The validator handed the rule the WHOLE array,
     * so `ScopedExists` compared the id column against an array — the check
     * never ran, and every valid submission failed. Verified against a real
     * validator, not read: the rule received `["rel", [5, 6]]`.
     *
     * @return array<int, mixed>
     */
    public function elementValidationRules(FieldConfig $config): array
    {
        /** @var array<int, string> $targets */
        $targets = (array) ($config->setting('targetTypes', []) ?: []);

        return [
            'integer',
            // scopedExists, never Laravel's exists: that rule bypasses
            // Eloquent, so it would accept an entry belonging to another org
            // and the application would then write a reference to it. A
            // cross-org WRITE, not merely a leak.
            Rule::scopedExists(Entry::class, null, function ($query) use ($targets): void {
                if ($targets !== []) {
                    $query->whereIn('type_handle', $targets);
                }
            }),
        ];
    }

    /** @return array<string, mixed> */
    public function settingsSchema(): array
    {
        return [
            'targetTypes' => ['type' => 'multiSelect', 'label' => 'Allowed entry types', 'default' => []],
        ];
    }
}
