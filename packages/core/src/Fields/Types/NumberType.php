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
        return self::clampedPrecision($config->setting('precision', 12));
    }

    /**
     * ⚠️ Shared with `validateSettings()`, which sees raw settings rather than a
     * FieldConfig. Duplicating the clamp would let the authoring check and the
     * runtime projection disagree about what the field actually accepts, which is
     * the drift invariant 14 is about.
     */
    private static function clampedPrecision(mixed $precision): int
    {
        return max(1, min(self::MAX_PRECISION, (int) $precision));
    }

    private static function clampedScale(mixed $scale, int $precision): int
    {
        return max(0, min($precision - 1, (int) $scale));
    }

    private function scale(FieldConfig $config): int
    {
        return self::clampedScale($config->setting('scale', 2), $this->precision($config));
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

        if (! is_numeric($min) || ! is_numeric($max)) {
            return null;
        }

        if ((float) $min > (float) $max) {
            return sprintf(
                'The minimum (%s) is above the maximum (%s), so no value could ever be stored in '
                .'this field. Swap them, or clear one.',
                (string) $min,
                (string) $max,
            );
        }

        // ⚠️ ORDERED is not the same as INHABITED, and this check has been wrong
        // three times: first it compared only the ordering, then it tested the
        // scale grid alone with a float epsilon.
        //
        // `scalarValidationRules()` emits SEVERAL constraints together, and a
        // value has to satisfy all of them. An `integer` field with min 0.1 and
        // max 0.9 accepts nothing; so does a decimal field whose range is
        // narrower than its own scale; so does one whose range sits outside the
        // bound the DECIMAL projection imposes; and so does one whose step grid
        // never lands on its scale grid.
        //
        // ⚠️ Computed in INTEGER units of the field's own quantum, which is what
        // makes it answerable. The previous version used an absolute `1e-9`
        // tolerance, and at scale 14 that fudge is larger than every value being
        // compared — it accepted min = max = 5e-15 on a 1e-14 grid. Scaling the
        // tolerance would have been another fudge; there is no float comparison
        // left to tolerate.
        return $this->uninhabitedReason($settings);
    }

    /**
     * Why no value can satisfy this configuration, or null if one can.
     *
     * Everything is expressed as a multiple of the quantum — 1 for an integer
     * field, 10^-scale for a decimal one — so the question becomes whether an
     * INTEGER exists in an integer interval, which needs no tolerance at all.
     *
     * @param  array<string, mixed>  $settings
     */
    private function uninhabitedReason(array $settings): ?string
    {
        $integer = ($settings['format'] ?? 'decimal') === 'integer';
        $precision = self::clampedPrecision($settings['precision'] ?? 12);
        $scale = $integer ? 0 : self::clampedScale($settings['scale'] ?? 2, $precision);

        // Units of the quantum: v = units * 10^-scale.
        $min = self::unitsAtLeast($settings['min'], $scale);
        $max = self::unitsAtMost($settings['max'], $scale);

        // ⚠️ The PROJECTION's bound, which the rules also emit. A decimal field
        // is validated `lt:10^(precision-scale)`, so precision 2 / scale 1 admits
        // nothing at or above 10 — and min = max = 10 was accepted because that
        // constraint was not part of the interval being tested.
        if (! $integer) {
            $bound = 10 ** $precision - 1;
            $max = $max === null ? $bound : min($max, $bound);
            $min = $min === null ? -$bound : max($min, -$bound);
        }

        if ($min === null || $max === null) {
            // An open side cannot be empty on its own: there is always a value
            // beyond it on the grid.
            return null;
        }

        if ($min > $max) {
            return $this->emptyRangeReason($settings, $scale, $min, $max);
        }

        // ⚠️ The STEP grid as well, and it is offset from `min` rather than from
        // zero — so its candidates are min, min + step, … and each must ALSO land
        // on the scale grid.
        $step = $settings['step'] ?? null;

        if (! is_numeric($step) || (float) $step <= 0) {
            return null;
        }

        $stepUnits = (float) $step * (10 ** $scale);

        // ⚠️ A step finer than the quantum is left alone deliberately. Whether any
        // of its candidates lands on the grid depends on the step's own fraction —
        // 0.005 on a two-decimal field hits every second candidate — and deciding
        // that exactly needs rational arithmetic this check will not carry. It
        // fails OPEN rather than refusing a configuration that may well work: a
        // false refusal blocks an author, a miss leaves an unusual field that the
        // value rules still police.
        if (abs($stepUnits - round($stepUnits)) > 0.0) {
            return null;
        }

        $stepUnits = (int) round($stepUnits);

        // ⚠️ The offset has to be ON the grid, and converting it with `ceil()`
        // hid that. `scale 2` with `min = 0.001` offers 0.001, 0.011, 0.021 … and
        // none has two decimals — but rounding the offset up to 0.01 invented a
        // candidate the runtime rule would never accept. With an integral step,
        // every candidate carries the offset's fraction, so an off-grid offset
        // means nothing is ever representable.
        if (! self::isOnGrid($settings['min'] ?? 0, $scale)) {
            return $this->emptyStepReason($settings);
        }

        $offset = (int) round((float) ($settings['min'] ?? 0) * (10 ** $scale));

        // The first candidate at or above the minimum.
        $first = $offset + (int) ceil(($min - $offset) / $stepUnits) * $stepUnits;

        return $first <= $max ? null : $this->emptyStepReason($settings);
    }

    /** The value as whole quanta, rounded UP; null when it is not a number. */
    private static function unitsAtLeast(mixed $value, int $scale): ?int
    {
        return is_numeric($value) ? (int) ceil((float) $value * (10 ** $scale)) : null;
    }

    /** The value as whole quanta, rounded DOWN; null when it is not a number. */
    private static function unitsAtMost(mixed $value, int $scale): ?int
    {
        return is_numeric($value) ? (int) floor((float) $value * (10 ** $scale)) : null;
    }

    /** Whether the value is exactly representable at this scale. */
    private static function isOnGrid(mixed $value, int $scale): bool
    {
        if (! is_numeric($value)) {
            return false;
        }

        $units = (float) $value * (10 ** $scale);

        return abs($units - round($units)) === 0.0;
    }

    /** @param  array<string, mixed>  $settings */
    private function emptyRangeReason(array $settings, int $scale, int $min, int $max): string
    {
        return sprintf(
            'No value this field can represent falls between %s and %s: it stores %s, and the '
            .'closest representable values leave nothing in that interval. Widen the range, or '
            .'change the format%s.',
            (string) $settings['min'],
            (string) $settings['max'],
            $scale === 0 ? 'whole numbers' : 'multiples of '.self::quantumLabel($scale),
            ($settings['format'] ?? 'decimal') === 'integer' ? '' : ', precision or scale',
        );
    }

    /** @param  array<string, mixed>  $settings */
    private function emptyStepReason(array $settings): string
    {
        return sprintf(
            'A step of %s counted from %s never lands on a value this field can store, so nothing '
            .'could be saved in it. Align the step with the field\'s scale, or clear it.',
            (string) $settings['step'],
            (string) ($settings['min'] ?? 0),
        );
    }

    private static function quantumLabel(int $scale): string
    {
        return rtrim(rtrim(number_format(10 ** -$scale, max(1, $scale), '.', ''), '0'), '.');
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
