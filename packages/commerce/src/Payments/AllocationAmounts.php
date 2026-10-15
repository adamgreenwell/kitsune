<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Payments;

use Kitsune\Commerce\Carts\CartAmounts;
use Kitsune\Commerce\Money\MinorUnits;

/** Exact largest-remainder allocations, stable input order for ties; no platform-sized money arithmetic. @internal */
final class AllocationAmounts
{
    /** @param list<string> $weights
     * @return list<string>
     */
    public static function split(string $amount, array $weights): array
    {
        $amount = MinorUnits::integer($amount);
        if ($weights === [] || count($weights) > 1980) {
            throw new CheckoutRefused;
        }
        $sum = '0';
        foreach ($weights as $weight) {
            $sum = CartAmounts::add($sum, MinorUnits::integer($weight));
        }
        if ($sum === '0') {
            if ($amount !== '0') {
                throw new CheckoutRefused;
            }

            return array_fill(0, count($weights), '0');
        }
        $parts = $remainders = [];
        $used = '0';
        foreach ($weights as $i => $weight) {
            [$parts[$i], $remainders[$i]] = self::divide(self::product($amount, $weight), $sum);
            $used = CartAmounts::add($used, $parts[$i]);
        }
        $remaining = self::subtract($amount, $used);
        $indices = array_keys($weights);
        usort($indices, static fn (int $a, int $b): int => self::compare($remainders[$b], $remainders[$a]) ?: $a <=> $b);
        foreach ($indices as $i) {
            if ($remaining === '0') {
                break;
            }
            $parts[$i] = CartAmounts::add($parts[$i], '1');
            $remaining = self::subtract($remaining, '1');
        }
        if ($remaining !== '0') {
            throw new CheckoutRefused;
        }

        return array_values($parts);
    }

    public static function subtract(string $left, string $right): string
    {
        if (self::compare($left, $right) < 0) {
            throw new CheckoutRefused;
        }
        $right = strrev($right);
        $borrow = 0;
        $result = '';
        for ($i = 0, $j = strlen($left) - 1; $j >= 0; $i++, $j--) {
            $value = (int) $left[$j] - (int) ($right[$i] ?? '0') - $borrow;
            $borrow = $value < 0 ? 1 : 0;
            $result = (string) ($value < 0 ? $value + 10 : $value).$result;
        }

        return ltrim($result, '0') ?: '0';
    }

    public static function compare(string $a, string $b): int
    {
        return strlen($a) <=> strlen($b) ?: strcmp($a, $b);
    }

    /** Intermediate products may exceed signed BIGINT; only final allocations use MinorUnits. */
    private static function product(string $a, string $b): string
    {
        $result = '0';
        foreach (str_split($b) as $digit) {
            $carry = 0;
            $part = '';
            for ($i = strlen($a) - 1; $i >= 0; $i--) {
                $value = (int) $a[$i] * (int) $digit + $carry;
                $part = (string) ($value % 10).$part;
                $carry = intdiv($value, 10);
            }
            $part = ($carry ? (string) $carry : '').$part;
            $result = self::addDigits($result.'0', $part);
        }

        return ltrim($result, '0') ?: '0';
    }

    private static function addDigits(string $a, string $b): string
    {
        $a = strrev($a);
        $b = strrev($b);
        $carry = 0;
        $result = '';
        for ($i = 0; $i < max(strlen($a), strlen($b)); $i++) {
            $value = (int) ($a[$i] ?? '0') + (int) ($b[$i] ?? '0') + $carry;
            $result = (string) ($value % 10).$result;
            $carry = intdiv($value, 10);
        }

        return ltrim(($carry ? '1' : '').$result, '0') ?: '0';
    }

    /** @return array{string,string} */
    private static function divide(string $numerator, string $denominator): array
    {
        $result = '';
        $remainder = '0';
        foreach (str_split($numerator) as $digit) {
            $remainder = ltrim($remainder.$digit, '0') ?: '0';
            $quotient = 0;
            while (self::compare($remainder, $denominator) >= 0) {
                $remainder = self::subtract($remainder, $denominator);
                $quotient++;
            }
            $result .= (string) $quotient;
        }

        return [MinorUnits::integer(ltrim($result, '0') ?: '0'), $remainder];
    }
}
