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

    /**
     * Always an array, whatever the cardinality — so it manages its own,
     * rather than going through the base class's per-element wrapper.
     */
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

    /** Already an array at every cardinality, so it is not wrapped again. */
    public function apiSchema(FieldConfig $config): array
    {
        $schema = ['type' => 'array', 'items' => ['type' => 'integer']];

        // ⚠️ The bound is PUBLISHED, matching the `max:{cardinality}` the
        // validation path adds. An unbounded schema let a generated client
        // submit three targets to a two-target relation and be rejected by
        // the API that advertised it. -1 is the explicit unlimited.
        if ($config->cardinality() > 0) {
            $schema['maxItems'] = $config->cardinality();
        }

        return $schema;
    }

    /** @return array<int, mixed> */
    public function validationRules(FieldConfig $config): array
    {
        // Always an array, so the outer rules never depend on cardinality for
        // their SHAPE — but they still have to honour its SIZE. Overriding
        // this method bypassed the bound the base class applies, so a
        // relation limited to one target accepted five.
        // ⚠️ `list`, not just `array`. A decoded API object such as
        // `{"primary": "a", "secondary": "b"}` is an associative PHP
        // array, so `array` accepted it and the element rules validated
        // its values — then `toStorage()` called `array_values()` and
        // stored `["a", "b"]`. An object accepted against a published
        // array schema, and its shape changed on the way in.
        $rules = [...($config->isRequired() ? ['required'] : ['nullable']), 'array', 'list'];

        if ($config->cardinality() > 0) {
            $rules[] = 'max:'.$config->cardinality();
        }

        return $rules;
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
            // ⚠️ `optionsFrom`, not a static `options` list. The permitted
            // targets are the org's own entry types, which core cannot know at
            // declaration time — and rendering the control with an EMPTY list
            // made the documented constraint unconfigurable through the
            // builder, while an empty value means unrestricted. So the field
            // silently could not be narrowed at all.
            //
            // A named source rather than a closure, because `settingsSchema()`
            // returns DATA so core stays headless-capable (ADR-002): a closure
            // here would only be callable from Filament.
            'targetTypes' => [
                'type' => 'multiSelect',
                'label' => 'Allowed entry types',
                'default' => [],
                'optionsFrom' => 'entryTypes',
                'help' => 'Leave empty to accept any type.',
            ],
        ];
    }
}
