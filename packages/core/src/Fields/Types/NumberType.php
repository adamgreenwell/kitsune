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
            : new Projection(LogicalType::Decimal, $this->precision($config), $this->scale($config));
    }

    /** Total digits, bounded so the column and the validator agree. */
    private function precision(FieldConfig $config): int
    {
        return max(1, min(38, (int) $config->setting('precision', 12)));
    }

    private function scale(FieldConfig $config): int
    {
        return max(0, min($this->precision($config) - 1, (int) $config->setting('scale', 2)));
    }

    protected function castToStorage(mixed $input, FieldConfig $config): mixed
    {
        if ($input === null || $input === '') {
            return null;
        }

        return $config->setting('format') === 'integer' ? (int) $input : (float) $input;
    }

    /** @return array<string, mixed> */
    protected function scalarApiSchema(FieldConfig $config): array
    {
        return ['type' => $config->setting('format') === 'integer' ? 'integer' : 'number'];
    }

    /** @return array<int, mixed> */
    protected function scalarValidationRules(FieldConfig $config): array
    {
        $rules = ['numeric'];

        // ⚠️ The projection is a promise about the values this field admits,
        // and nothing was keeping that promise. Unbounded, `10000000000`
        // overflowed DECIMAL(12,2) and made the engine refuse the column,
        // while `1.234` was accepted and then ROUNDED in the projection — so
        // two distinct stored values compared equal through the index.
        if ($config->setting('format') !== 'integer') {
            $precision = $this->precision($config);
            $scale = $this->scale($config);

            $bound = 10 ** ($precision - $scale);

            $rules[] = 'decimal:0,'.$scale;
            $rules[] = 'lt:'.$bound;
            $rules[] = 'gt:-'.$bound;
        }

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
            // Both feed the projection AND the validation bounds, so what the
            // field accepts and what the indexed column can hold cannot drift.
            'precision' => ['type' => 'integer', 'default' => 12, 'label' => 'Total digits',
                'help' => 'Including decimal places. Bounds what this field will accept.'],
            'scale' => ['type' => 'integer', 'default' => 2, 'label' => 'Decimal places'],
            'min' => ['type' => 'number', 'nullable' => true],
            'max' => ['type' => 'number', 'nullable' => true],
            'step' => ['type' => 'number', 'nullable' => true],
        ];
    }
}
