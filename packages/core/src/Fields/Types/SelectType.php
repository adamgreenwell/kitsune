<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Fields\Types;

use Illuminate\Validation\Rule as LaravelRule;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Schema\SchemaDriver;

final class SelectType extends BaseFieldType
{
    public static function handle(): string
    {
        return 'select';
    }

    public static function label(): string
    {
        return 'Select';
    }

    public static function icon(): string
    {
        return 'heroicon-o-chevron-up-down';
    }

    public function isIndexable(): bool
    {
        return true;
    }

    public function supportsCardinality(): bool
    {
        return false;
    }

    public function generatedColumnType(SchemaDriver $driver): string
    {
        return $driver->sqlType('string', 64);
    }

    public function toStorage(mixed $input, FieldConfig $config): mixed
    {
        return $input === null || $input === '' ? null : (string) $input;
    }

    /** @return array<string, mixed> */
    public function apiSchema(FieldConfig $config): array
    {
        return ['type' => 'string', 'enum' => array_keys($this->options($config))];
    }

    /** @return array<int, mixed> */
    public function validationRules(FieldConfig $config): array
    {
        $options = array_keys($this->options($config));

        // Laravel's `in` rule, not `exists` — these options come from the
        // field's own settings, not from another table, so there is no scope
        // to respect and nothing to leak.
        return [...parent::validationRules($config), LaravelRule::in($options)];
    }

    /** @return array<string, mixed> */
    public function settingsSchema(): array
    {
        return [
            'options' => ['type' => 'keyValue', 'label' => 'Options', 'default' => []],
        ];
    }

    /** @return array<string, string> */
    private function options(FieldConfig $config): array
    {
        /** @var array<string, string> $options */
        $options = $config->setting('options', []) ?: [];

        return $options;
    }
}
