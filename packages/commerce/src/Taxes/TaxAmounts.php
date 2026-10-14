<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Taxes;

use InvalidArgumentException;
use Kitsune\Commerce\Carts\CartAmounts;
use Kitsune\Commerce\Money\MinorUnits;

/**
 * Exact tax for one complete line amount, in the currency's existing minor units. Multiply unit price by quantity
 * before calling: rounding each unit's tax and then multiplying would charge a different amount. A rate is an integer
 * fraction of one million: 7.3125% is 73125. Both paths round the tax half-up once, then derive the other amount.
 *
 * Decimal-digit money arithmetic needs no floats, BCMath or platform-sized money integers. The largest native
 * intermediate is below twenty million, including long division, so a 32-bit host retains the full storage range.
 *
 * @internal First-party commerce primitive; no extension stability promise before v1.2.
 */
final class TaxAmounts
{
    private const SCALE = 1000000;

    /**
     * Plain ASCII percentage, 0 through 100, with up to four fractional digits. Native whole integers are accepted;
     * floats, signs, leading zeroes, whitespace, grouping and exponents are refused before any numeric coercion.
     */
    public static function rate(mixed $percentage): int
    {
        if (is_int($percentage)) {
            $percentage = (string) $percentage;
        }

        if (! is_string($percentage) || strlen($percentage) > 8
            || preg_match('/\A(0|[1-9][0-9]{0,2})(?:\.([0-9]{1,4}))?\z/D', $percentage, $parts) !== 1) {
            throw new InvalidArgumentException('A tax percentage must be plain decimal digits from 0 through 100 with at most four fractional places.');
        }

        return self::millionths(((int) $parts[1]) * 10000 + (int) str_pad($parts[2] ?? '', 4, '0'));
    }

    /** @return array{net:string,tax:string,gross:string} */
    public static function exclusive(mixed $netMinor, mixed $rateMillionths): array
    {
        $net = MinorUnits::integer($netMinor);
        $rate = self::millionths($rateMillionths);
        $tax = self::roundedTax($net, $rate, self::SCALE);

        return ['net' => $net, 'tax' => $tax, 'gross' => CartAmounts::add($net, $tax)];
    }

    /**
     * Extract rounded tax as gross × rate / (one + rate), then subtract it from gross. At an exact half minor unit
     * the tax rounds upward: gross 1 at 100% becomes net 0, tax 1. Net is the remainder, never independently rounded.
     *
     * @return array{net:string,tax:string,gross:string}
     */
    public static function inclusive(mixed $grossMinor, mixed $rateMillionths): array
    {
        $gross = MinorUnits::integer($grossMinor);
        $rate = self::millionths($rateMillionths);
        $tax = self::roundedTax($gross, $rate, self::SCALE + $rate);

        return ['net' => self::subtract($gross, $tax), 'tax' => $tax, 'gross' => $gross];
    }

    /** Validate before casting: the millionths bound fits even a 32-bit integer. */
    private static function millionths(mixed $value): int
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || strlen($value) > 7 || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1
            || (strlen($value) === 7 && strcmp($value, (string) self::SCALE) > 0)) {
            throw new InvalidArgumentException('A tax rate must be a canonical integer from 0 through 1000000 millionths.');
        }

        return (int) $value;
    }

    /** The numerator may exceed a money column's range; keep it as digits until division has reduced it. */
    private static function roundedTax(string $minor, int $rate, int $denominator): string
    {
        if ($rate === 0 || $minor === '0') {
            return '0';
        }

        $product = '';
        $carry = 0;

        for ($i = strlen($minor) - 1; $i >= 0; $i--) {
            $value = ((int) $minor[$i]) * $rate + $carry;
            $product = (string) ($value % 10).$product;
            $carry = intdiv($value, 10);
        }

        $product = ($carry === 0 ? '' : (string) $carry).$product;
        $quotient = '';
        $remainder = 0;

        for ($i = 0; $i < strlen($product); $i++) {
            $value = $remainder * 10 + (int) $product[$i];
            $quotient .= (string) intdiv($value, $denominator);
            $remainder = $value % $denominator;
        }

        $tax = MinorUnits::integer(ltrim($quotient, '0') ?: '0');

        return $remainder * 2 >= $denominator ? CartAmounts::add($tax, '1') : $tax;
    }

    /** Subtract bounded canonical integers without casting either complete money amount. */
    private static function subtract(string $left, string $right): string
    {
        $left = strrev($left);
        $right = strrev($right);
        $borrow = 0;
        $result = '';

        for ($i = 0; $i < strlen($left); $i++) {
            $digit = (int) $left[$i] - (int) ($right[$i] ?? '0') - $borrow;
            $borrow = $digit < 0 ? 1 : 0;
            $result = (string) ($digit < 0 ? $digit + 10 : $digit).$result;
        }

        if ($borrow !== 0) {
            throw new InvalidArgumentException('Tax cannot exceed the inclusive gross amount.');
        }

        return MinorUnits::integer(ltrim($result, '0') ?: '0');
    }
}
