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
use Kitsune\Core\Fields\LogicalType;
use Kitsune\Core\Fields\Projection;

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

    public function projection(FieldConfig $config): Projection
    {
        // ⚠️ Not a constant 64. An option key longer than that is accepted
        // by `Rule::in()` and preserved by `toStorage()`, and then TRUNCATED
        // in the projection — so two distinct options sharing a prefix
        // compare equal through the index, and SQLite disagrees with the
        // other two engines about which rows match, because it does not
        // enforce declared widths.
        return new Projection(LogicalType::String, $this->width($config));
    }

    /** Wide enough for the widest configured option, never narrower than 64. */
    private function width(FieldConfig $config): int
    {
        /** @var array<string, string> $options */
        $options = (array) ($config->setting('options', []) ?: []);

        $longest = 0;

        foreach (array_keys($options) as $key) {
            $longest = max($longest, mb_strlen((string) $key));
        }

        return max(64, $longest);
    }

    protected function castToStorage(mixed $input, FieldConfig $config): mixed
    {
        return $input === null || $input === '' ? null : (string) $input;
    }

    /** @return array<string, mixed> */
    protected function scalarApiSchema(FieldConfig $config): array
    {
        return ['type' => 'string', 'enum' => $this->optionKeys($config)];
    }

    /** @return array<int, mixed> */
    protected function scalarValidationRules(FieldConfig $config): array
    {
        $options = $this->optionKeys($config);

        // Laravel's `in` rule, not `exists` — these options come from the
        // field's own settings, not from another table, so there is no scope
        // to respect and nothing to leak.
        return [LaravelRule::in($options)];
    }

    /** @return array<string, mixed> */
    public function settingsSchema(): array
    {
        return [
            'options' => ['type' => 'keyValue', 'label' => 'Options', 'default' => []],
        ];
    }

    /**
     * The option keys, as the strings they are stored and compared as.
     *
     * ⚠️ PHP casts a numeric-string array key to an integer, so configuring
     * an option `"1"` yields the int `1` here. Published unstringified, the
     * schema read `{"type": "string", "enum": [1]}` — which NO JSON value can
     * satisfy: `"1"` has the right type and is not equal to `1`. Validation
     * accepted the choice and castToStorage() stored the string, so the field
     * worked while its own published contract called every value invalid.
     *
     * @return list<string>
     */
    private function optionKeys(FieldConfig $config): array
    {
        return array_map(strval(...), array_keys($this->options($config)));
    }

    /** @return array<string, string> */
    private function options(FieldConfig $config): array
    {
        /** @var array<string, string> $options */
        $options = $config->setting('options', []) ?: [];

        return $options;
    }
}
