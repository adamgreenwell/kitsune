<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Money;

use InvalidArgumentException;
use Kitsune\Core\Currency\Currencies;

/**
 * Exact, nonnegative money for signed BIGINT columns on every supported database engine.
 *
 * Integers are returned as canonical decimal strings so a 32-bit PHP host has the same storage range as a 64-bit
 * host. Never scale a float, cast an unvalidated numeric string, or use the host's locale to interpret a price.
 *
 * @internal First-party commerce primitive; no extension stability promise before v1.2.
 */
final class MinorUnits
{
    private const MAXIMUM = '9223372036854775807';

    /** Human input is bounded before any regex or string concatenation; leading zeroes still count. */
    private const MAX_DECIMAL_LENGTH = 100;

    /**
     * Parse ASCII major-unit digits with an optional dot and fractional digits; no signs, whitespace or grouping.
     *
     * Whole digits are mandatory. Leading zeroes are accepted; missing fractional places are padded. Excess
     * precision is refused even when the excess digits are zero. A zero-minor-unit currency admits no fraction.
     * Conversion alone does not retain a price quote: pass this same currency to the catalogue writer, which
     * compares it with the stored org currency under the write lock before accepting the resulting minor units.
     */
    public static function fromDecimal(mixed $value, mixed $currency): string
    {
        $places = Currencies::minorUnits($currency);

        if (! is_string($value)
            || strlen($value) > self::MAX_DECIMAL_LENGTH
            || preg_match('/\A([0-9]+)(?:\.([0-9]+))?\z/', $value, $parts) !== 1) {
            throw new InvalidArgumentException('Enter an amount using at most 100 ASCII characters, with digits and an optional decimal point.');
        }

        $fraction = $parts[2] ?? '';

        if (strlen($fraction) > $places) {
            throw new InvalidArgumentException('The amount has more fractional digits than its currency permits.');
        }

        $minor = ltrim($parts[1].str_pad($fraction, $places, '0'), '0');

        return self::integer($minor === '' ? '0' : $minor);
    }

    /**
     * Validate a native integer or canonical ASCII integer string before a model cast or database coercion can hide
     * its original type. The upper bound is the column's signed 64-bit range, independent of PHP's integer width.
     */
    public static function integer(mixed $value): string
    {
        if (is_int($value)) {
            if ($value < 0) {
                throw new InvalidArgumentException('Minor units must be a nonnegative integer.');
            }

            $value = (string) $value;
        }

        if (! is_string($value)
            || strlen($value) > strlen(self::MAXIMUM)
            || preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) !== 1
            || (strlen($value) === strlen(self::MAXIMUM) && strcmp($value, self::MAXIMUM) > 0)) {
            throw new InvalidArgumentException('Minor units must be a canonical integer from 0 through 9223372036854775807.');
        }

        return $value;
    }

    /** Return only the exact major-unit amount; callers display its currency code separately. */
    public static function format(mixed $minor, mixed $currency): string
    {
        $value = self::integer($minor);
        $places = Currencies::minorUnits($currency);

        if ($places === 0) {
            return $value;
        }

        $padded = str_pad($value, $places + 1, '0', STR_PAD_LEFT);

        return substr($padded, 0, -$places).'.'.substr($padded, -$places);
    }
}
