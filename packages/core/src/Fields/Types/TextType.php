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

final class TextType extends BaseFieldType
{
    public static function handle(): string
    {
        return 'text';
    }

    public static function label(): string
    {
        return 'Text';
    }

    public static function icon(): string
    {
        return 'heroicon-o-bars-3-bottom-left';
    }

    public function isIndexable(): bool
    {
        return true;
    }

    public function generatedColumnType(SchemaDriver $driver): string
    {
        return $driver->sqlType('string', $this->length());
    }

    public function toStorage(mixed $input, FieldConfig $config): mixed
    {
        return $input === null ? null : (string) $input;
    }

    /** @return array<int, mixed> */
    public function validationRules(FieldConfig $config): array
    {
        $rules = parent::validationRules($config);
        $rules[] = 'string';
        $rules[] = 'max:'.$config->setting('maxLength', 255);

        if (($pattern = $config->setting('pattern')) !== null) {
            $rules[] = 'regex:'.$pattern;
        }

        return $rules;
    }

    /** @return array<string, mixed> */
    public function settingsSchema(): array
    {
        return [
            'maxLength' => ['type' => 'integer', 'default' => 255, 'label' => 'Maximum length'],
            'pattern' => ['type' => 'string', 'nullable' => true, 'label' => 'Pattern (regex)'],
        ];
    }

    private function length(): int
    {
        return 255;
    }
}
