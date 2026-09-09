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

        // ⚠️ EITHER bound is enough to reach the check, and requiring both let a
        // one-sided empty range through: a decimal field is validated
        // `lt:10^(precision-scale)`, so precision 2 / scale 1 with `min = 10` and
        // no maximum admits nothing. The projection supplies the other side.
        if (! is_numeric($min) && ! is_numeric($max)) {
            return null;
        }

        // ⚠️ Exactly, not in floats. `min = -9223372036854775807` with
        // `max = -9223372036854775808` is a reversed range between two legitimate
        // signed BIGINT bounds, and neither float nor int conversion can tell the
        // endpoints apart — the float rounds them together and the int saturates.
        if (self::compareDecimals($min, $max) === 1) {
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
        $min = self::unitsAtLeast($settings['min'] ?? null, $scale);
        $max = self::unitsAtMost($settings['max'] ?? null, $scale);

        // ⚠️ The PROJECTION's bound, which the rules also emit. A decimal field
        // is validated `lt:10^(precision-scale)`, so precision 2 / scale 1 admits
        // nothing at or above 10 — and min = max = 10 was accepted because that
        // constraint was not part of the interval being tested.
        if ($integer) {
            // ⚠️ An integer field HAS a projection bound, and the note here used to
            // say it did not. The indexed projection is a signed BIGINT and
            // Laravel's `integer` rule is limited to platform integers, so the
            // interval is [PHP_INT_MIN, PHP_INT_MAX] — asymmetric, because two's
            // complement is.
            //
            // ⚠️ Tested on the TEXT and before the clamp, because clamping destroys
            // exactly the information the test needs: `min = 1e100` saturates to
            // PHP_INT_MAX, which is a value a field CAN store, so a clamped
            // comparison would call an unsatisfiable field satisfiable.
            if (self::compareDecimals($settings['min'] ?? null, PHP_INT_MAX) === 1
                || self::compareDecimals($settings['max'] ?? null, PHP_INT_MIN) === -1) {
                return $this->outsideIntegerProjectionReason($settings);
            }

            $max ??= PHP_INT_MAX;
            $min ??= PHP_INT_MIN;
        } else {
            $bound = 10 ** $precision - 1;
            $max = $max === null ? $bound : min($max, $bound);
            $min = $min === null ? -$bound : max($min, -$bound);
        }

        // ⚠️ A ONE-SIDED range can be empty, and this used to return early when
        // either bound was absent — missing it. A decimal field is validated
        // `lt:10^(precision-scale)`, so precision 2 / scale 1 with `min = 10` and
        // no maximum admits nothing; the projection supplies the other side.
        //
        // There is no early return left, because BOTH sides are now always
        // supplied: the decimal branch above substitutes its projection bound and
        // the integer branch substitutes the BIGINT limits. An absent bound is a
        // bound at the edge of what the field can hold, which is what makes the
        // interval below answerable in every case.
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

        // ⚠️ A step finer than the quantum is left alone deliberately. Whether any
        // of its candidates lands on the grid depends on the step's own fraction —
        // 0.005 on a two-decimal field hits every second candidate — and deciding
        // that exactly needs rational arithmetic this check will not carry. It
        // fails OPEN rather than refusing a configuration that may well work: a
        // false refusal blocks an author, a miss leaves an unusual field that the
        // value rules still police.
        //
        // ⚠️ Asked through `isOnGrid()`, and the float multiply this replaces was
        // the SAME defect the endpoint conversion had — left behind here while that
        // one was fixed, which is the more useful half of the lesson. `0.29 * 100`
        // is `28.999999999999996`, so an integral step read as sub-quantum and took
        // the fail-open path: with precision 3, scale 2, max -9.9 and step 0.29 the
        // neighbouring multiples are -10.15 and -9.86, neither inside the projection
        // interval, so the field admitted nothing and was accepted anyway.
        //
        // Failing open is only defensible for a step that is GENUINELY sub-quantum.
        // Float noise deciding which steps those are turns a deliberate gap into an
        // arbitrary one.
        if (! self::isOnGrid($step, $scale)) {
            return null;
        }

        $stepUnits = (int) self::unitsAtLeast($step, $scale);

        // ⚠️ The offset has to be ON the grid, and converting it with `ceil()`
        // hid that. `scale 2` with `min = 0.001` offers 0.001, 0.011, 0.021 … and
        // none has two decimals — but rounding the offset up to 0.01 invented a
        // candidate the runtime rule would never accept. With an integral step,
        // every candidate carries the offset's fraction, so an off-grid offset
        // means nothing is ever representable.
        if (! self::isOnGrid($settings['min'] ?? 0, $scale)) {
            return $this->emptyStepReason($settings);
        }

        $offset = (int) self::unitsAtLeast($settings['min'] ?? 0, $scale);

        // The first candidate at or above the minimum.
        $first = $offset + (int) ceil(($min - $offset) / $stepUnits) * $stepUnits;

        return $first <= $max ? null : $this->emptyStepReason($settings);
    }

    /** The value as whole quanta, rounded UP; null when it is not a number. */
    private static function unitsAtLeast(mixed $value, int $scale): ?int
    {
        return self::units($value, $scale, up: true);
    }

    /** The value as whole quanta, rounded DOWN; null when it is not a number. */
    private static function unitsAtMost(mixed $value, int $scale): ?int
    {
        return self::units($value, $scale, up: false);
    }

    /** Whether the value is exactly representable at this scale. */
    private static function isOnGrid(mixed $value, int $scale): bool
    {
        return is_numeric($value) && self::units($value, $scale, up: true) === self::units($value, $scale, up: false);
    }

    /**
     * The value in whole quanta, rounded up or down, without a float multiply.
     *
     * ⚠️ Parsed from the DECIMAL TEXT, because `0.29 * 100` is
     * `28.999999999999996` — so the first version took `ceil()` to 29 and
     * `floor()` to 28 for the same number, decided 29 > 28, and refused a
     * singleton range at 0.29 that the runtime rules plainly accept. Moving to
     * integer arithmetic fixed the comparisons and left the CONVERSION in floats,
     * which is where the imprecision actually was.
     *
     * ⚠️ And the fix after that one still truncated. `sprintf('%.4F', ...)` at
     * scale 2 renders `0.2900001` as `0.2900`, so two guard digits decided there
     * was nothing below the quantum when there were five digits of it: `min = max
     * = 0.2900001` read as the inhabited singleton 29 units, when no value on the
     * scale-2 grid equals it and the range is in fact empty. Two guard digits
     * answer the question for numbers with at most two digits below the grid,
     * which is not the question — the number decides how many digits it has, so
     * the text has to carry all of them.
     */
    private static function units(mixed $value, int $scale, bool $up): ?int
    {
        $text = self::decimalText($value);

        if ($text === null) {
            return null;
        }

        $negative = str_starts_with($text, '-');
        [$whole, $fraction] = explode('.', ltrim($text, '+-').'.');

        // Padded so the grid digits are always present: `29` at scale 2 is 2900
        // quanta, and without this it would read as 29.
        $fraction = str_pad($fraction, $scale, '0');

        // ⚠️ The SIGN goes into the cast, rather than being applied after it.
        //
        // `(int) '9223372036854775808'` saturates to `PHP_INT_MAX` and negating
        // that gives `-9223372036854775807` — one short of `PHP_INT_MIN`, which is
        // a legitimate signed BIGINT bound. `(int) '-9223372036854775808'` is
        // exact, so the same digits convert correctly when the cast can see they
        // are negative. The asymmetry of two's complement is the whole reason: the
        // negative range is one wider than the positive one, so a magnitude that
        // overflows on the way out can still be representable on the way in.
        $units = (int) (($negative ? '-' : '').$whole.substr($fraction, 0, $scale));
        $below = rtrim(substr($fraction, $scale), '0') !== '';

        // Rounding away from zero happens on the MAGNITUDE, so the direction
        // swaps for a negative value: ceil(-29.5) is -29, floor(-29.5) is -30.
        $awayFromZero = $negative ? ! $up : $up;

        if ($below && $awayFromZero) {
            // ⚠️ NOT PAST THE PLATFORM LIMIT, and stepping past it was a 500.
            //
            // `(int)` has already saturated at PHP_INT_MAX / PHP_INT_MIN for a
            // magnitude that does not fit, and incrementing either one promotes the
            // result to FLOAT — which this method's `?int` return type rejects with a
            // TypeError. So an authored `min = 9223372036854775807.1` crashed the
            // request before `uninhabitedReason()` could say what was wrong with it,
            // turning a validation message into a 500.
            //
            // Clamping is the right answer and not merely the safe one. It applies
            // equally when the cast did NOT saturate — 9223372036854775807.1 rounds
            // away from zero to 9223372036854775808, which no signed BIGINT holds — so
            // in both cases the true quantum count is outside what the field can
            // represent, and the caller refuses the setting from the DECIMAL TEXT
            // rather than from these units. Losing a distinction between "at the limit"
            // and "past it" costs nothing, because neither is storable.
            //
            // Guarded HERE rather than at the caller that reported it, because every
            // path into rounding arrives through this method — `unitsAtLeast()`,
            // `unitsAtMost()`, `isOnGrid()`, and the step and offset conversions. A
            // guard on one caller is a guard on one path.
            if ($units !== PHP_INT_MAX && $units !== PHP_INT_MIN) {
                // Away from zero is DOWN for a negative value, and `$units` already
                // carries the sign now.
                $units += $negative ? -1 : 1;
            }
        }

        return $units;
    }

    /**
     * Compare two numeric settings exactly, or null when either is not a number.
     *
     * ⚠️ On the DECIMAL TEXT, because both floats and ints lose the answer here.
     * `(float) '-9223372036854775807' > (float) '-9223372036854775808'` is false —
     * neither endpoint survives the conversion distinctly — so a reversed range
     * between two legitimate signed BIGINT bounds compared equal and was accepted.
     * Casting to int cannot help either: that is what saturates.
     *
     * Returns -1, 0 or 1. No arbitrary-precision arithmetic is needed for a
     * comparison — digits and a sign are enough, which is why this does not reach
     * for bcmath (not guaranteed present) or float (not exact).
     */
    private static function compareDecimals(mixed $left, mixed $right): ?int
    {
        $a = self::decimalText($left);
        $b = self::decimalText($right);

        if ($a === null || $b === null) {
            return null;
        }

        [$aNegative, $aWhole, $aFraction] = self::decimalParts($a);
        [$bNegative, $bWhole, $bFraction] = self::decimalParts($b);

        // ⚠️ Before the signs are compared: -0 and 0 are the same number, and
        // treating the sign as decisive would order them.
        $aZero = $aWhole === '0' && $aFraction === '';
        $bZero = $bWhole === '0' && $bFraction === '';

        if ($aZero && $bZero) {
            return 0;
        }

        if ($aNegative !== $bNegative) {
            return $aNegative ? -1 : 1;
        }

        $magnitude = self::compareMagnitudes($aWhole, $aFraction, $bWhole, $bFraction);

        // Further from zero is SMALLER when both are negative.
        return $aNegative ? -$magnitude : $magnitude;
    }

    /**
     * A fixed-point decimal split into sign, normalised whole part and fraction.
     *
     * @return array{bool, string, string}
     */
    private static function decimalParts(string $text): array
    {
        $negative = str_starts_with($text, '-');
        [$whole, $fraction] = explode('.', ltrim($text, '+-').'.');

        // Leading zeros carry no value and would break a length comparison;
        // trailing ones in the fraction would break equality.
        $whole = ltrim($whole, '0');

        return [$negative, $whole === '' ? '0' : $whole, rtrim($fraction, '0')];
    }

    /** Compare two unsigned decimals given as whole and fractional digits. */
    private static function compareMagnitudes(string $aWhole, string $aFraction, string $bWhole, string $bFraction): int
    {
        // More integer digits is a larger number, once leading zeros are gone.
        if (mb_strlen($aWhole) !== mb_strlen($bWhole)) {
            return mb_strlen($aWhole) < mb_strlen($bWhole) ? -1 : 1;
        }

        if ($aWhole !== $bWhole) {
            return strcmp($aWhole, $bWhole) < 0 ? -1 : 1;
        }

        // Same integer part: pad the fractions so position means the same thing in
        // both, which is what makes a plain string comparison correct.
        $width = max(mb_strlen($aFraction), mb_strlen($bFraction));
        $aFraction = str_pad($aFraction, $width, '0');
        $bFraction = str_pad($bFraction, $width, '0');

        return $aFraction === $bFraction ? 0 : (strcmp($aFraction, $bFraction) < 0 ? -1 : 1);
    }

    /**
     * A numeric setting as fixed-point decimal text, carrying every digit it has.
     *
     * ⚠️ `json_encode()` rather than a string cast for a float, because a cast
     * uses `precision` (14 significant digits) while `json_encode()` uses
     * `serialize_precision`, which defaults to -1 and means "the shortest decimal
     * that round-trips". `(string) 0.1` and `json_encode(0.1)` agree; on a value
     * carrying more digits than `precision` shows, the cast is the one that loses
     * them, which is the defect this method exists to remove.
     *
     * ⚠️ A STRING setting is used as authored. Filament submits numeric inputs as
     * strings, so this is the ordinary path, and the author's own text is a more
     * faithful record of what they meant than any float built from it: `0.1` as
     * text is exactly one tenth, and as a float it is not.
     */
    private static function decimalText(mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            // NAN and INF are numeric and have no decimal expansion. Treated as
            // absent rather than as a bound, which is what `null` means here.
            return is_finite($value) ? self::withoutExponent((string) json_encode($value)) : null;
        }

        return self::withoutExponent(trim((string) $value));
    }

    /**
     * The widest decimal this expansion will materialise.
     *
     * ⚠️ A BOUND ON THE ALLOCATION, not a judgement about the number. `1e1000000000`
     * is nine bytes of request body and asked this method for a billion characters
     * — `is_numeric()` accepts the short input, and the expansion happened before
     * any guard could refuse the settings, so one crafted field-configuration
     * request could exhaust the worker's memory. That is amplification rather than
     * a large input, which is what makes it worth a limit: a literal 10MB number
     * costs 10MB and is already bounded by the request size.
     *
     * 64 is far past anything this type can represent — `MAX_PRECISION` is 15, so
     * the projection cannot hold more than 15 significant digits — and the values
     * that hit the cap SATURATE rather than truncate, which is what keeps the
     * verdict right. A number too large to represent becomes a number that is
     * still too large to represent; one too small to reach the quantum stays
     * nonzero and below it. Both are refused for the reasons they should be, and
     * neither allocates.
     */
    private const MAX_EXPANDED_DIGITS = 64;

    /**
     * Exponent notation expanded to a plain decimal.
     *
     * Needed because this is all done by shifting a decimal point through a digit
     * string, and `1.0e-15` has no decimal point to shift. Both a small float and
     * an author who typed `1e-9` arrive here.
     */
    private static function withoutExponent(string $text): string
    {
        if (preg_match('/^([+-]?)([0-9]*)(?:\.([0-9]*))?[eE]([+-]?[0-9]+)$/', $text, $matches) !== 1) {
            return $text;
        }

        // Not `?? ''`: the exponent group always participates, so PHP pads the
        // fraction group to an empty string rather than leaving it unset.
        $digits = $matches[2].$matches[3];

        // Where the point lands in the digit string once the exponent moves it.
        $point = mb_strlen($matches[2]) + (int) $matches[4];

        // ⚠️ ZERO first, because saturating it changed its VALUE.
        //
        // `0e1000000000` is a perfectly ordinary zero written with a large
        // exponent, and the clamp below turned it into 64 nines — so a field with
        // `min = 0` spelled that way was refused as being outside its projection.
        // Saturation is only sound when it preserves the answer, and no exponent
        // moves zero anywhere: an all-zero coefficient is zero at every scale.
        if (rtrim($digits, '0') === '') {
            return $matches[1].'0';
        }

        // ⚠️ Checked BEFORE either `str_repeat()` below, which is the whole point:
        // the arms are what allocate, so a guard after them guards nothing.
        if ($point > self::MAX_EXPANDED_DIGITS || $point < -self::MAX_EXPANDED_DIGITS) {
            return $matches[1].($point > 0
                // Too large for the projection, and saturating keeps it so.
                ? str_repeat('9', self::MAX_EXPANDED_DIGITS)
                // Too small to reach any quantum, and still not zero — which is the
                // property the grid check reads, so it has to survive the clamp.
                : '0.'.str_repeat('0', self::MAX_EXPANDED_DIGITS - 1).'1');
        }

        $expanded = match (true) {
            $point <= 0 => '0.'.str_repeat('0', -$point).$digits,
            $point >= mb_strlen($digits) => $digits.str_repeat('0', $point - mb_strlen($digits)),
            default => mb_substr($digits, 0, $point).'.'.mb_substr($digits, $point),
        };

        return $matches[1].$expanded;
    }

    /**
     * Why an integer field's bounds fall outside what it can store.
     *
     * @param  array<string, mixed>  $settings
     */
    private function outsideIntegerProjectionReason(array $settings): string
    {
        return sprintf(
            'No value this field can store falls between %s and %s. An integer field is projected '
            .'as a signed BIGINT and validated with Laravel\'s integer rule, so it holds values '
            .'from %s to %s — and the range asked for lies outside that entirely. Bring the bounds '
            .'inside it, or use a decimal field with the precision you need.',
            (string) ($settings['min'] ?? '-∞'),
            (string) ($settings['max'] ?? '∞'),
            (string) PHP_INT_MIN,
            (string) PHP_INT_MAX,
        );
    }

    /** @param  array<string, mixed>  $settings */
    private function emptyRangeReason(array $settings, int $scale, int $min, int $max): string
    {
        // A bound the author did not set is reported as the one the PROJECTION
        // imposes, because that is the constraint actually doing the refusing —
        // saying "between 10 and " would leave them looking for a setting that is
        // not there.
        return sprintf(
            'No value this field can represent falls between %s and %s: it stores %s, and the '
            .'closest representable values leave nothing in that interval. Widen the range, or '
            .'change the format%s.',
            (string) ($settings['min'] ?? self::asDecimal($min, $scale)),
            (string) ($settings['max'] ?? self::asDecimal($max, $scale)),
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

    /** Whole quanta back as a decimal string, for a message. */
    private static function asDecimal(int $units, int $scale): string
    {
        return $scale === 0
            ? (string) $units
            : rtrim(rtrim(number_format($units / (10 ** $scale), $scale, '.', ''), '0'), '.');
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
