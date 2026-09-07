<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Fields\Types;

use Illuminate\Validation\Rule;
use Kitsune\Core\Fields\FieldConfig;

/**
 * Always an array, so cardinality is intrinsic rather than configurable.
 *
 * Not indexable: a JSON array cannot project to a scalar column. A
 * multi-value field that needs querying wants `relation` instead, which is a
 * real table with a real index — field-types.md §11 keeps that as an open
 * question worth revisiting with faceting requirements.
 */
final class MultiSelectType extends BaseFieldType
{
    public static function handle(): string
    {
        return 'multi_select';
    }

    public static function label(): string
    {
        return 'Multi-select';
    }

    public static function icon(): string
    {
        return 'heroicon-o-list-bullet';
    }

    public function supportsCardinality(): bool
    {
        return false;
    }

    public function toStorage(mixed $input, FieldConfig $config): mixed
    {
        if ($input === null || $input === '') {
            return [];
        }

        return array_values(array_map(strval(...), (array) $input));
    }

    public function fromStorage(mixed $stored, FieldConfig $config): mixed
    {
        return (array) ($stored ?? []);
    }

    /** @return array<string, mixed> */
    public function apiSchema(FieldConfig $config): array
    {
        return ['type' => 'array', 'items' => ['type' => 'string']];
    }

    /** @return array<int, mixed> */
    public function validationRules(FieldConfig $config): array
    {
        return [...parent::validationRules($config), 'array'];
    }

    /**
     * Each chosen value must BE one of the options.
     *
     * `SelectType` applies `in` to the same keys; the multi-value sibling
     * checked only that the outer value was an array, so any string at all —
     * including an option since removed — went straight into storage.
     *
     * @return array<int, mixed>
     */
    public function elementValidationRules(FieldConfig $config): array
    {
        /** @var array<string, string> $options */
        $options = (array) ($config->setting('options', []) ?: []);

        return $options === [] ? ['string'] : ['string', Rule::in(array_keys($options))];
    }

    /** @return array<string, mixed> */
    public function settingsSchema(): array
    {
        return ['options' => ['type' => 'keyValue', 'label' => 'Options', 'default' => []]];
    }
}
