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

    public function projection(FieldConfig $config): Projection
    {
        // ⚠️ Projecting an integer-formatted field through DECIMAL(12,2) is
        // not merely imprecise: `10000000000` is a valid PHP integer that
        // both the validator and toStorage() accept, and PostgreSQL then
        // refuses the column outright with `numeric field overflow`. BIGINT
        // covers the range the field actually admits.
        return $config->setting('format') === 'integer'
            ? new Projection(LogicalType::Integer)
            : new Projection(LogicalType::Decimal);
    }

    protected function castToStorage(mixed $input, FieldConfig $config): mixed
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

        // Without this, 12.9 passes `numeric` and toStorage() truncates it to
        // 12 — valid-looking input silently becoming different data. Reject
        // rather than round, because neither rounding direction is obviously
        // what the submitter meant.
        if ($config->setting('format') === 'integer') {
            $rules[] = 'integer';
        }

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
