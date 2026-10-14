<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Commerce\Shipping\ParcelPacking;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pure packing tests: no application, database, carrier or external service. */
final class ParcelPackingTest extends TestCase
{
    public function test_it_splits_a_sku_into_full_and_partial_parcels_with_tare_once_each(): void
    {
        self::assertSame([
            self::parcel('1625'), self::parcel('1625'), self::parcel('625'),
        ], ParcelPacking::pack([self::line(['quantity' => 7, 'units_per_parcel' => 3])]));
    }

    public function test_skus_never_share_a_parcel_and_order_is_stable(): void
    {
        $a = self::line(['sku' => 'A', 'quantity' => 2, 'units_per_parcel' => 3]);
        $b = self::line(['sku' => 'B', 'quantity' => 1, 'units_per_parcel' => 3, 'length_mm' => '999']);
        $expected = [self::parcel('1125'), array_replace(self::parcel('625'), ['length' => '999'])];

        self::assertSame($expected, ParcelPacking::pack([$b, $a]));
        self::assertSame($expected, ParcelPacking::pack([$a, $b]));
    }

    public function test_capacity_99_and_zero_tare_are_supported_without_inventing_dimensions(): void
    {
        self::assertSame([self::parcel('49500')], ParcelPacking::pack([
            self::line(['quantity' => '99', 'units_per_parcel' => '99', 'tare_grams' => '0']),
        ]));
        self::assertSame([], ParcelPacking::pack([]));
    }

    public function test_weight_and_tare_stay_exact_above_the_float_integer_range(): void
    {
        $line = self::line(['weight_grams' => '9007199254740993', 'tare_grams' => '1', 'quantity' => 2, 'units_per_parcel' => 2]);
        self::assertSame([self::parcel('18014398509481987')], ParcelPacking::pack([$line]));
        self::assertSame([self::parcel('9223372036854775807')], ParcelPacking::pack([
            self::line(['weight_grams' => '9223372036854775806', 'tare_grams' => '1']),
        ]));
    }

    public function test_twenty_parcels_are_admitted_but_a_twenty_first_is_refused(): void
    {
        self::assertCount(20, ParcelPacking::pack([self::line(['quantity' => 20])]));
        $this->expectException(InvalidArgumentException::class);
        ParcelPacking::pack([self::line(['quantity' => 21])]);
    }

    public function test_parcel_limit_is_shared_across_all_skus(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ParcelPacking::pack([self::line(['sku' => 'A', 'quantity' => 20]), self::line(['sku' => 'B'])]);
    }

    #[DataProvider('badLines')]
    public function test_it_refuses_invalid_input_before_producing_a_shipping_request(mixed $lines): void
    {
        $this->expectException(Throwable::class);
        ParcelPacking::pack($lines);
    }

    /** @return iterable<string,array{mixed}> */
    public static function badLines(): iterable
    {
        yield 'not a list' => [['sku' => self::line()]];
        yield 'not an array' => [null];
        yield 'non array line' => [[null]];
        yield 'too many lines' => [array_fill(0, 21, self::line())];
        yield 'duplicate SKU' => [[self::line(), self::line()]];
        yield 'unknown field' => [[self::line(['extra' => ['insurance' => ['amount' => '500']]])]];
        yield 'missing field' => [[array_diff_key(self::line(), ['tare_grams' => true])]];
        yield 'lowercase SKU' => [[self::line(['sku' => 'surf'])]];

        foreach (['quantity', 'units_per_parcel', 'weight_grams', 'length_mm', 'width_mm', 'height_mm', 'tare_grams'] as $field) {
            foreach ([null, true, false, 1.0, '1.0', '01', '+1', '-1', ' 1', "1\n", [], '9223372036854775808'] as $i => $value) {
                yield $field.' malformed '.$i => [[self::line([$field => $value])]];
            }
        }

        foreach (['quantity', 'units_per_parcel', 'weight_grams', 'length_mm', 'width_mm', 'height_mm'] as $field) {
            yield $field.' zero' => [[self::line([$field => '0'])]];
        }

        yield 'quantity beyond 99' => [[self::line(['quantity' => 100])]];
        yield 'capacity beyond 99' => [[self::line(['units_per_parcel' => 100])]];
        yield 'weight multiplication overflow' => [[self::line(['quantity' => 2, 'units_per_parcel' => 2, 'weight_grams' => '9223372036854775807'])]];
        yield 'tare addition overflow' => [[self::line(['weight_grams' => '9223372036854775807', 'tare_grams' => '1'])]];
    }

    public function test_the_request_projection_rejects_extras_provider_ids_and_unit_aliases(): void
    {
        self::assertSame([self::parcel('625')], ParcelPacking::validate([self::parcel('625')]));

        foreach ([[], ['opaque-parcel-id'], [self::parcel('625') + ['extra' => []]],
            [array_replace(self::parcel('625'), ['distance_unit' => 'cm'])],
            [array_replace(self::parcel('625'), ['mass_unit' => 'kg'])],
            [array_replace(self::parcel('625'), ['weight' => 1.5])],
            array_fill(0, 21, self::parcel('625'))] as $bad) {
            try {
                ParcelPacking::validate($bad);
                self::fail('An unchecked parcel was admitted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    /** @return array<string,mixed> */
    private static function line(array $overrides = []): array
    {
        return array_replace(['sku' => 'SURF-BOARD', 'quantity' => 1, 'weight_grams' => '500',
            'length_mm' => '300', 'width_mm' => '200', 'height_mm' => '100', 'tare_grams' => '125', 'units_per_parcel' => 1], $overrides);
    }

    /** @return array<string,string> */
    private static function parcel(string $weight): array
    {
        return ['length' => '300', 'width' => '200', 'height' => '100', 'distance_unit' => 'mm', 'weight' => $weight, 'mass_unit' => 'g'];
    }
}
