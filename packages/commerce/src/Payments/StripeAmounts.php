<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Payments;

use InvalidArgumentException;
use Kitsune\Commerce\Money\MinorUnits;
use Kitsune\Core\Currency\Currencies;

/**
 * Exact conversion between Kitsune's ISO minor units and Stripe's charge request/response integers.
 * Checked 2026-10-14: https://docs.stripe.com/currencies#zero-decimal and #special-cases. ISK/UGX retain a
 * two-decimal wire representation; MGA is zero-decimal on Stripe. HUF/TWD's payout exceptions do not affect charges.
 * Three-decimal currencies are deferred in this test-only slice; ISO metadata alone never promises account acceptance.
 *
 * @internal
 */
final class StripeAmounts
{
    /**
     * Reviewed presentment list, intersected with core metadata, retrieved 2026-10-14:
     * https://docs.stripe.com/currencies?presentment-currency=US. Account/card acceptance remains Stripe's decision.
     * Keep this explicit: a newly added ISO metadata code must not silently become a provider request currency.
     */
    private const PRESENTMENT_CURRENCIES = [
        'AED', 'AFN', 'ALL', 'AMD', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BIF', 'BMD',
        'BND', 'BOB', 'BRL', 'BSD', 'BWP', 'BYN', 'BZD', 'CAD', 'CDF', 'CHF', 'CLP', 'CNY', 'COP', 'CRC',
        'CVE', 'CZK', 'DJF', 'DKK', 'DOP', 'DZD', 'EGP', 'ETB', 'EUR', 'FJD', 'FKP', 'GBP', 'GEL', 'GIP',
        'GMD', 'GNF', 'GTQ', 'GYD', 'HKD', 'HNL', 'HTG', 'HUF', 'IDR', 'ILS', 'INR', 'ISK', 'JMD', 'JPY',
        'KES', 'KGS', 'KHR', 'KMF', 'KRW', 'KYD', 'KZT', 'LAK', 'LBP', 'LKR', 'LRD', 'LSL', 'MAD', 'MDL',
        'MGA', 'MKD', 'MMK', 'MNT', 'MOP', 'MUR', 'MVR', 'MWK', 'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO',
        'NOK', 'NPR', 'NZD', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'PYG', 'QAR', 'RON', 'RSD', 'RUB',
        'RWF', 'SAR', 'SBD', 'SCR', 'SEK', 'SGD', 'SHP', 'SLE', 'SOS', 'SRD', 'SZL', 'THB', 'TJS', 'TOP',
        'TRY', 'TTD', 'TWD', 'TZS', 'UAH', 'UGX', 'USD', 'UYU', 'UZS', 'VND', 'VUV', 'WST', 'XAF', 'XCD',
        'XCG', 'XOF', 'XPF', 'YER', 'ZAR', 'ZMW',
    ];

    public static function assertCurrency(string $currency): void
    {
        if (! in_array($currency, self::PRESENTMENT_CURRENCIES, true) || ! Currencies::supports($currency)
            || ! in_array(Currencies::minorUnits($currency), [0, 2], true)) {
            throw new StripeUnavailable('rejected');
        }
    }

    public static function toWire(mixed $minor, string $currency): string
    {
        self::assertCurrency($currency);

        try {
            $value = MinorUnits::integer($minor);

            return match ($currency) {
                'ISK', 'UGX' => self::multiplyHundred($value),
                'MGA' => self::divideHundred($value),
                default => $value,
            };
        } catch (InvalidArgumentException) {
            throw new StripeUnavailable('rejected');
        }
    }

    public static function fromWire(mixed $wire, string $currency): string
    {
        self::assertCurrency($currency);

        try {
            $value = MinorUnits::integer($wire);

            return match ($currency) {
                'ISK', 'UGX' => self::divideHundred($value),
                'MGA' => self::multiplyHundred($value),
                default => $value,
            };
        } catch (InvalidArgumentException) {
            throw new StripeUnavailable('rejected');
        }
    }

    private static function multiplyHundred(string $value): string
    {
        return MinorUnits::integer($value === '0' ? '0' : $value.'00');
    }

    private static function divideHundred(string $value): string
    {
        if ($value === '0') {
            return '0';
        }

        if (strlen($value) < 3 || ! str_ends_with($value, '00')) {
            throw new StripeUnavailable('rejected');
        }

        return substr($value, 0, -2);
    }
}
