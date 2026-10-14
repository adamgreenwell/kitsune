<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Orders;

use InvalidArgumentException;

/**
 * The explicitly supplied billing name and postal address, never a reader or arbitrary profile snapshot.
 * Delivery addresses and contact email are separate inputs; neither is inferred here.
 *
 * @internal First-party commerce input; no extension stability promise before v1.2.
 */
final class BillingDetails
{
    /** @return array{name:string,address:array{line1:string,line2:string,city:string,region:string,postal_code:string,country:string}} */
    public static function from(#[\SensitiveParameter] mixed $value): array
    {
        if (! is_array($value) || array_diff(array_keys($value), ['name', 'address']) !== []) {
            throw new InvalidArgumentException('Billing details accept only a name and postal address.');
        }

        return ['name' => self::name($value['name'] ?? null), 'address' => PostalAddress::from($value['address'] ?? null)];
    }

    /** The same bounded person/company name for an operator-configured dispatch origin. @internal */
    public static function name(#[\SensitiveParameter] mixed $value): string
    {
        // Match the address's bounded single-line text rules without introducing a shared input framework.
        if (! is_string($value) || strlen($value) > 4096 || ! mb_check_encoding($value, 'UTF-8')
            || preg_match('/[\p{Cc}\x{2028}\x{2029}]/u', $value) === 1) {
            throw new InvalidArgumentException('A billing name must be a bounded, single-line UTF-8 string without controls.');
        }

        $trimmed = preg_replace('/\A[\p{Z}\s]+|[\p{Z}\s]+\z/u', '', $value);

        if ($trimmed === null || $trimmed === '' || mb_strlen($trimmed, 'UTF-8') > 200) {
            throw new InvalidArgumentException('A billing name is required and must not exceed 200 characters.');
        }

        return $trimmed;
    }
}
