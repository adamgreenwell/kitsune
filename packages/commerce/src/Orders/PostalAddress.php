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
 * A bounded postal address, independent of billing, delivery and country-specific requirements.
 *
 * Missing optional parts become empty strings. Country currently validates spelling only: two uppercase ASCII
 * letters, not membership of a country registry. Tax and carrier admission must not mistake that for deliverability.
 * Limits count Unicode characters after trimming; a 4 KiB raw-field bound limits work before decoding or matching.
 *
 * @internal First-party commerce input; no extension stability promise before v1.2.
 */
final class PostalAddress
{
    private const FIELDS = ['line1', 'line2', 'city', 'region', 'postal_code', 'country'];

    private const READ_BOUND = 4096;

    /** @return array{line1:string,line2:string,city:string,region:string,postal_code:string,country:string} */
    public static function from(#[\SensitiveParameter] mixed $value): array
    {
        if (! is_array($value) || array_diff(array_keys($value), self::FIELDS) !== []) {
            throw new InvalidArgumentException('A postal address accepts only its named address fields.');
        }

        $country = self::text($value['country'] ?? null, 2, required: true);

        if (preg_match('/\A[A-Z]{2}\z/D', $country) !== 1) {
            throw new InvalidArgumentException('An address country must be two uppercase ASCII letters.');
        }

        return [
            'line1' => self::text($value['line1'] ?? null, 200, required: true),
            'line2' => self::text(array_key_exists('line2', $value) ? $value['line2'] : '', 200),
            'city' => self::text(array_key_exists('city', $value) ? $value['city'] : '', 100),
            'region' => self::text(array_key_exists('region', $value) ? $value['region'] : '', 100),
            'postal_code' => self::text(array_key_exists('postal_code', $value) ? $value['postal_code'] : '', 20),
            'country' => $country,
        ];
    }

    private static function text(#[\SensitiveParameter] mixed $value, int $maximum, bool $required = false): string
    {
        // Check controls before trimming: padding a value with a newline must not turn it into accepted input.
        if (! is_string($value) || strlen($value) > self::READ_BOUND || ! mb_check_encoding($value, 'UTF-8')
            || preg_match('/[\p{Cc}\x{2028}\x{2029}]/u', $value) === 1) {
            throw new InvalidArgumentException('Address fields must be bounded, single-line UTF-8 strings without controls.');
        }

        $trimmed = preg_replace('/\A[\p{Z}\s]+|[\p{Z}\s]+\z/u', '', $value);

        if ($trimmed === null || mb_strlen($trimmed, 'UTF-8') > $maximum || ($required && $trimmed === '')) {
            throw new InvalidArgumentException('An address field is missing or exceeds its character limit.');
        }

        return $trimmed;
    }
}
