<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Fields\Types;

use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Fields\LogicalType;
use Kitsune\Core\Fields\Projection;

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

    public function projection(FieldConfig $config): Projection
    {
        // ⚠️ The configured width, not a constant 255. A field validated to
        // accept 1,000 characters and projected through VARCHAR(255) is
        // silently truncated in the index, so two distinct values compare
        // equal and an exact filter returns the wrong rows — and SQLite,
        // which does not enforce declared widths, disagrees with the other
        // two engines about which rows those are.
        return new Projection(LogicalType::String, $this->length($config));
    }

    protected function castToStorage(mixed $input, FieldConfig $config): mixed
    {
        return $input === null ? null : (string) $input;
    }

    /** @return array<int, mixed> */
    /**
     * ⚠️ The constraints are PUBLISHED, not only enforced.
     *
     * The inherited schema said `{"type": "string"}` while validation
     * rejected anything past 255 characters by default, so a generated client
     * accepted payloads the API refused — and the same gap applied to a
     * configured length or pattern.
     *
     * @return array<string, mixed>
     */
    protected function scalarApiSchema(FieldConfig $config): array
    {
        $schema = ['type' => 'string', 'maxLength' => $this->length($config)];

        if (($pattern = $config->setting('pattern')) !== null) {
            $schema['pattern'] = (string) $pattern;
        }

        return $schema;
    }

    protected function scalarValidationRules(FieldConfig $config): array
    {
        $rules = [];
        $rules[] = 'string';
        $rules[] = 'max:'.$this->length($config);

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

    private function length(FieldConfig $config): int
    {
        return max(1, (int) $config->setting('maxLength', 255));
    }
}
