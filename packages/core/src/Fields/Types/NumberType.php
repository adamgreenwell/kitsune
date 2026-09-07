<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Fields\Types;

use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Schema\SchemaDriver;

final class NumberType extends BaseFieldType
{
    public static function handle(): string
    {
        return 'number';
    }

    public static function label(): string
    {
        return 'Number';
    }

    public static function icon(): string
    {
        return 'heroicon-o-hashtag';
    }

    public function isIndexable(): bool
    {
        return true;
    }

    public function generatedColumnType(SchemaDriver $driver): string
    {
        return $driver->sqlType('decimal');
    }

    public function toStorage(mixed $input, FieldConfig $config): mixed
    {
        if ($input === null || $input === '') {
            return null;
        }

        return $config->setting('format') === 'integer' ? (int) $input : (float) $input;
    }

    /** @return array<string, mixed> */
    public function apiSchema(FieldConfig $config): array
    {
        return ['type' => $config->setting('format') === 'integer' ? 'integer' : 'number'];
    }

    /** @return array<int, mixed> */
    public function validationRules(FieldConfig $config): array
    {
        $rules = [...parent::validationRules($config), 'numeric'];

        if (($min = $config->setting('min')) !== null) {
            $rules[] = 'min:'.$min;
        }

        if (($max = $config->setting('max')) !== null) {
            $rules[] = 'max:'.$max;
        }

        return $rules;
    }

    /** @return array<string, mixed> */
    public function settingsSchema(): array
    {
        return [
            'format' => ['type' => 'enum', 'options' => ['integer', 'decimal'], 'default' => 'decimal'],
            'min' => ['type' => 'number', 'nullable' => true],
            'max' => ['type' => 'number', 'nullable' => true],
            'step' => ['type' => 'number', 'nullable' => true],
        ];
    }
}
