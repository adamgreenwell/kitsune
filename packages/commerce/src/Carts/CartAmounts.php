<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Carts;

use InvalidArgumentException;
use Kitsune\Commerce\Money\MinorUnits;

/** Decimal digit arithmetic keeps multiplication and totals exact without floats, BCMath or a 64-bit PHP cast. @internal */
final class CartAmounts
{
    public static function multiply(string $minor, int $quantity): string
    {
        if ($quantity < 1 || $quantity > CartWriter::MAX_QUANTITY) {
            throw new InvalidArgumentException('Cart quantities range from 1 through 99.');
        }

        $digits = strrev(MinorUnits::integer($minor));
        $carry = 0;
        $result = '';

        for ($i = 0; $i < strlen($digits); $i++) {
            $value = ((int) $digits[$i]) * $quantity + $carry;
            $result = (string) ($value % 10).$result;
            $carry = intdiv($value, 10);
        }

        $result = ($carry === 0 ? '' : (string) $carry).$result;

        return MinorUnits::integer(ltrim($result, '0') ?: '0');
    }

    public static function add(string $left, string $right): string
    {
        $left = strrev(MinorUnits::integer($left));
        $right = strrev(MinorUnits::integer($right));
        $carry = 0;
        $result = '';

        for ($i = 0; $i < max(strlen($left), strlen($right)); $i++) {
            $value = (int) ($left[$i] ?? '0') + (int) ($right[$i] ?? '0') + $carry;
            $result = (string) ($value % 10).$result;
            $carry = intdiv($value, 10);
        }

        return MinorUnits::integer(($carry === 0 ? '' : '1').$result);
    }
}
