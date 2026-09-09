<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Fields\Types;

use Illuminate\Validation\Rule;
use Kitsune\Core\Fields\Control;
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

    /**
     * Same reasoning as `select`: the labels are authored text.
     */
    public function control(): Control
    {
        return Control::Choices;
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

    /**
     * Already an array, so it is not wrapped again.
     *
     * ⚠️ The choices are PUBLISHED. Element validation accepts only the
     * configured keys while this advertised every string as valid, so a
     * client generated from the schema could not discover the options and
     * would submit values the API then rejected.
     */
    public function apiSchema(FieldConfig $config): array
    {
        $keys = $this->optionKeys($config);

        return [
            'type' => 'array',
            'items' => $keys === [] ? ['type' => 'string'] : ['type' => 'string', 'enum' => $keys],
        ];
    }

    /** @return array<int, mixed> */
    public function validationRules(FieldConfig $config): array
    {
        // Intrinsically multi-valued: supportsCardinality() is false, so the
        // storage row's cardinality says nothing about how many options may
        // be chosen and must not bound the array.
        // ⚠️ `list`, not just `array`. A decoded API object such as
        // `{"primary": "a", "secondary": "b"}` is an associative PHP
        // array, so `array` accepted it and the element rules validated
        // its values — then `toStorage()` called `array_values()` and
        // stored `["a", "b"]`. An object accepted against a published
        // array schema, and its shape changed on the way in.
        return [...($config->isRequired() ? ['required'] : ['nullable']), 'array', 'list'];
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
        $keys = $this->optionKeys($config);

        return $keys === [] ? ['string'] : ['string', Rule::in($keys)];
    }

    /**
     * The option keys, as the strings they are stored and compared as.
     *
     * ⚠️ PHP casts a numeric-string array key to an integer, so an option
     * `"1"` arrives here as int 1 — published unstringified that is an enum
     * no JSON string can satisfy. Same coercion as SelectType, same fix.
     *
     * @return list<string>
     */
    private function optionKeys(FieldConfig $config): array
    {
        /** @var array<string, string> $options */
        $options = (array) ($config->setting('options', []) ?: []);

        return array_map(strval(...), array_keys($options));
    }

    /** @return array<string, mixed> */
    public function settingsSchema(): array
    {
        return ['options' => ['type' => 'keyValue', 'label' => 'Options', 'default' => []]];
    }
}
