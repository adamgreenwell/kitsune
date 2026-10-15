<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Commerce\Payments\StripeAmounts;
use Kitsune\Commerce\Payments\StripeUnavailable;
use Kitsune\Commerce\Taxes\TaxAmounts;
use Kitsune\Core\Currency\Currencies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pure charge-encoding fixtures; no provider, account or database is required. */
final class StripeAmountsTest extends TestCase
{
    #[DataProvider('exactAmounts')]
    public function test_exact_charge_encoding_round_trips_without_iso_or_payout_rounding(string $currency, int|string $minor, string $wire): void
    {
        self::assertSame($wire, StripeAmounts::toWire($minor, $currency));
        self::assertSame((string) $minor, StripeAmounts::fromWire($wire, $currency));
    }

    public static function exactAmounts(): iterable
    {
        foreach (['USD', 'EUR', 'GBP', 'JPY', 'BIF', 'HUF', 'TWD'] as $currency) {
            yield $currency.' zero' => [$currency, 0, '0'];
            yield $currency.' ordinary' => [$currency, 1045, '1045'];
            yield $currency.' above float precision' => [$currency, '9007199254740993', '9007199254740993'];
            yield $currency.' signed bigint limit' => [$currency, '9223372036854775807', '9223372036854775807'];
        }
        foreach (['ISK', 'UGX'] as $currency) {
            yield $currency.' zero' => [$currency, 0, '0'];
            yield $currency.' one' => [$currency, 1, '100'];
            yield $currency.' five whole' => [$currency, '5', '500'];
            yield $currency.' exact upper scaling bound' => [$currency, '92233720368547758', '9223372036854775800'];
        }
        yield 'MGA zero' => ['MGA', 0, '0'];
        yield 'MGA one whole' => ['MGA', 100, '1'];
        yield 'MGA five whole' => ['MGA', '500', '5'];
        yield 'MGA exact upper scaling bound' => ['MGA', '9223372036854775800', '92233720368547758'];
    }

    public function test_all_seven_core_three_decimal_codes_are_deferred(): void
    {
        $deferred = [];

        foreach (Currencies::all() as $currency => $metadata) {
            if ($metadata['minor_units'] === 3) {
                $deferred[] = $currency;
                $this->refused(fn () => StripeAmounts::assertCurrency($currency));
                $this->refused(fn () => StripeAmounts::toWire('1000', $currency));
                $this->refused(fn () => StripeAmounts::fromWire('1000', $currency));
            }
        }

        self::assertSame(['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'], $deferred);
    }

    #[DataProvider('invalidCurrencies')]
    public function test_unknown_and_noncanonical_currency_metadata_is_refused(string $currency): void
    {
        $this->refused(fn () => StripeAmounts::assertCurrency($currency));
        $this->refused(fn () => StripeAmounts::toWire('100', $currency));
        $this->refused(fn () => StripeAmounts::fromWire('100', $currency));
    }

    public static function invalidCurrencies(): iterable
    {
        foreach (['', 'ZZZ', 'usd', 'Usd', ' USD', 'USD ', 'US', 'USDD', "USD\n", 'USé', 'XXX', 'XAU'] as $currency) {
            yield [$currency];
        }
    }

    #[DataProvider('unreviewedCoreCurrencies')]
    public function test_iso_metadata_does_not_automatically_admit_an_unreviewed_presentment_currency(string $currency): void
    {
        self::assertTrue(Currencies::supports($currency));
        $this->refused(fn () => StripeAmounts::assertCurrency($currency));
        $this->refused(fn () => StripeAmounts::toWire('100', $currency));
        $this->refused(fn () => StripeAmounts::fromWire('100', $currency));
    }

    public static function unreviewedCoreCurrencies(): iterable
    {
        foreach (['BTN', 'CUP', 'ERN', 'GHS', 'IRR', 'KPW', 'MRU', 'SDG', 'SSP', 'STN', 'SVC', 'SYP', 'TMT', 'VED', 'VES', 'ZWG'] as $currency) {
            yield [$currency];
        }
    }

    #[DataProvider('invalidAmounts')]
    public function test_noncanonical_and_out_of_range_money_is_refused_in_both_directions(mixed $amount): void
    {
        $this->refused(fn () => StripeAmounts::toWire($amount, 'USD'));
        $this->refused(fn () => StripeAmounts::fromWire($amount, 'USD'));
    }

    public static function invalidAmounts(): iterable
    {
        foreach ([-1, 1.0, 1.5, '-1', '+1', '01', '00', '1.0', '1e2', ' 1', '1 ', "1\n", '١',
            '9223372036854775808', str_repeat('9', 100), true, false, null, [], new stdClass] as $i => $amount) {
            yield 'invalid '.$i => [$amount];
        }
    }

    public function test_fractional_mga_tax_is_refused_without_adjusting_the_buyer_total(): void
    {
        $amounts = TaxAmounts::exclusive('100', TaxAmounts::rate('1'));
        self::assertSame(['net' => '100', 'tax' => '1', 'gross' => '101'], $amounts);
        self::assertSame('1', StripeAmounts::toWire($amounts['net'], 'MGA'));
        $this->refused(fn () => StripeAmounts::toWire($amounts['tax'], 'MGA'));
        $this->refused(fn () => StripeAmounts::toWire($amounts['gross'], 'MGA'));
    }

    public function test_special_case_nondivisible_wire_values_and_scaling_overflow_are_refused(): void
    {
        foreach (['ISK', 'UGX'] as $currency) {
            foreach (['1', '5', '99', '101', '9223372036854775807'] as $wire) {
                $this->refused(fn () => StripeAmounts::fromWire($wire, $currency));
            }
            $this->refused(fn () => StripeAmounts::toWire('92233720368547759', $currency));
            $this->refused(fn () => StripeAmounts::toWire('9223372036854775807', $currency));
        }
        foreach (['1', '5', '99', '101', '9223372036854775807'] as $minor) {
            $this->refused(fn () => StripeAmounts::toWire($minor, 'MGA'));
        }
        $this->refused(fn () => StripeAmounts::fromWire('92233720368547759', 'MGA'));
        $this->refused(fn () => StripeAmounts::fromWire('9223372036854775807', 'MGA'));
    }

    private function refused(Closure $operation): void
    {
        try {
            $operation();
            self::fail('The unrepresentable charge amount was admitted.');
        } catch (StripeUnavailable $failure) {
            self::assertSame('rejected', $failure->category);
            self::assertNull($failure->getPrevious());
        }
    }
}
