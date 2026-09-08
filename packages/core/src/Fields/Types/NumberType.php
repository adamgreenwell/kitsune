<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Fields\Types;

use Closure;
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

    /**
     * Total digits, capped at what the STORAGE can actually distinguish.
     *
     * ⚠️ 15, not 38. A decimal is stored as a JSON number, JSON numbers are
     * IEEE doubles, and a double carries about 15-17 significant digits — so
     * at precision 20, `123456789012345678.12` and `...78.13` both become
     * `1.2345678901234568e+17` before they reach JSON at all. Validating and
     * projecting at a precision the conversion cannot hold would advertise
     * an exactness that does not exist.
     *
     * A field genuinely needing more digits wants a `text` field and its own
     * arithmetic, which is an honest answer rather than a silent one.
     */
    public const MAX_PRECISION = 15;

    private function precision(FieldConfig $config): int
    {
        return max(1, min(self::MAX_PRECISION, (int) $config->setting('precision', 12)));
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

    /**
     * ⚠️ The bounds are PUBLISHED, not only enforced (AGENTS.md invariant 14).
     *
     * Validation rejects values outside `min`/`max`, and a decimal field also
     * has bounds derived from its projection — a DECIMAL(12,2) cannot hold
     * `10000000000`, and the rules say so. Publishing only `{"type": "number"}`
     * meant a generated client accepted every one of those.
     *
     * `exclusiveMinimum`/`exclusiveMaximum` for the projection bound because
     * the rules are `lt`/`gt`, and `multipleOf` for `step`. What JSON Schema
     * cannot express is the SCALE — `decimal:0,2` bounds the number of decimal
     * places, and `multipleOf` only reaches it when a step is configured — so
     * that one is stated here rather than left implied.
     *
     * @return array<string, mixed>
     */
    protected function scalarApiSchema(FieldConfig $config): array
    {
        $integer = $config->setting('format') === 'integer';

        $schema = ['type' => $integer ? 'integer' : 'number'];

        if (! $integer) {
            $bound = 10 ** ($this->precision($config) - $this->scale($config));

            $schema['exclusiveMinimum'] = -$bound;
            $schema['exclusiveMaximum'] = $bound;
        }

        if (($min = $config->setting('min')) !== null && is_numeric($min)) {
            $schema['minimum'] = $min + 0;
        }

        if (($max = $config->setting('max')) !== null && is_numeric($max)) {
            $schema['maximum'] = $max + 0;
        }

        // ⚠️ `multipleOf` measures from ZERO; validation measures the step
        // from `min`, which is what a form input does. So they describe the
        // same set only when `min` is itself a multiple of the step.
        //
        // With `min: 0.1, step: 0.5` validation accepts 0.1 and 0.6 while
        // `multipleOf: 0.5` rejects both — a generated client would refuse
        // server-valid values and offer ones the server refuses. JSON Schema
        // cannot express the offset, so it is omitted rather than published
        // wrongly (AGENTS.md invariant 14: say so instead).
        if (($step = $config->setting('step')) !== null && is_numeric($step) && (float) $step > 0) {
            $offset = (float) ($config->setting('min') ?? 0);
            $steps = $offset / (float) $step;

            if (abs($steps - round($steps)) < 1e-9) {
                $schema['multipleOf'] = $step + 0;
            }
        }

        return $schema;
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

        // ⚠️ The step was CONFIGURABLE and unenforced, so it constrained a
        // form widget and nothing else — an API client or a crafted request
        // submitted any value it liked. A setting the server does not check
        // is a suggestion, and this one looks like a rule.
        if (($step = $config->setting('step')) !== null && is_numeric($step) && (float) $step > 0) {
            $offset = (float) ($config->setting('min') ?? 0);

            $rules[] = function (string $attribute, mixed $value, Closure $fail) use ($step, $offset): void {
                if (! is_numeric($value)) {
                    return;
                }

                // Compared in integers scaled by the step, because
                // fmod(0.3, 0.1) is not 0 in binary floating point and would
                // reject the values it exists to accept.
                $steps = ((float) $value - $offset) / (float) $step;

                if (abs($steps - round($steps)) > 1e-9) {
                    $fail("The {$attribute} field must be a multiple of {$step}.");
                }
            };
        }

        return $rules;
    }

    /** @return array<string, mixed> */
    /**
     * ⚠️ Bounds that cannot both be satisfied make the field unusable.
     *
     * `scalarValidationRules()` emits `min` and `max` together, so a minimum
     * above a maximum leaves NO value that can be stored — the same outcome as an
     * uncompilable pattern, reached by a different route. Neither control is
     * individually wrong, which is why this cannot be a per-setting rule.
     *
     * `precision`, `scale` and `step` are deliberately not checked here: each is
     * already CLAMPED to a usable range where it is read, so a contradictory
     * value is corrected rather than fatal. The bar is "no value can satisfy
     * this", not "this looks odd".
     *
     * @param  array<string, mixed>  $settings
     */
    public function validateSettings(array $settings): ?string
    {
        $min = $settings['min'] ?? null;
        $max = $settings['max'] ?? null;

        if (! is_numeric($min) || ! is_numeric($max) || (float) $min <= (float) $max) {
            return null;
        }

        return sprintf(
            'The minimum (%s) is above the maximum (%s), so no value could ever be stored in this '
            .'field. Swap them, or clear one.',
            (string) $min,
            (string) $max,
        );
    }

    public function settingsSchema(): array
    {
        return [
            'format' => ['type' => 'enum', 'options' => ['integer', 'decimal'], 'default' => 'decimal'],
            // Both feed the projection AND the validation bounds, so what the
            // field accepts and what the indexed column can hold cannot drift.
            'precision' => ['type' => 'integer', 'default' => 12, 'label' => 'Total digits',
                'help' => 'Including decimal places. Bounds what this field will accept. '
                    .'Capped at '.self::MAX_PRECISION.' — beyond that a JSON number cannot tell two values apart.'],
            'scale' => ['type' => 'integer', 'default' => 2, 'label' => 'Decimal places'],
            'min' => ['type' => 'number', 'nullable' => true],
            'max' => ['type' => 'number', 'nullable' => true],
            'step' => ['type' => 'number', 'nullable' => true],
        ];
    }
}
