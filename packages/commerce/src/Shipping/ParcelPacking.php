<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Shipping;

use InvalidArgumentException;
use Kitsune\Commerce\Carts\CartAmounts;
use Kitsune\Commerce\Models\Sellable;
use Kitsune\Commerce\Money\MinorUnits;

/**
 * Packs each SKU independently into its operator-declared parcel, never guesses geometry or mixes products.
 * Dimensions describe the outside of one configured parcel at its declared capacity, including a partial parcel.
 * Unit weight is multiplied by the actual units inside; packaging tare is added once to each parcel.
 * Shippo supports mm and g directly, so no floating-point unit conversion is necessary.
 *
 * @internal First-party commerce primitive; no extension stability promise before v1.2.
 *
 * @phpstan-type Parcel array{length:string,width:string,height:string,distance_unit:'mm',weight:string,mass_unit:'g'}
 */
final class ParcelPacking
{
    public const MAX_PARCELS = 20;

    private const FIELDS = ['sku', 'quantity', 'weight_grams', 'length_mm', 'width_mm', 'height_mm', 'tare_grams', 'units_per_parcel'];

    /**
     * Only physical lines enter this helper. The caller chooses physical products from authoritative catalogue data.
     * No carrier's maximum weight/dimensions or multi-piece support is promised by these local bounds.
     *
     * @return list<Parcel>
     */
    public static function pack(mixed $lines): array
    {
        if (! is_array($lines) || ! array_is_list($lines) || count($lines) > 20) {
            throw new InvalidArgumentException('Packing requires at most twenty physical product lines.');
        }

        $checked = [];

        foreach ($lines as $line) {
            if (! is_array($line) || count($line) !== count(self::FIELDS) || array_diff(array_keys($line), self::FIELDS) !== []) {
                throw new InvalidArgumentException('Packing accepts only the declared product and parcel fields.');
            }

            $sku = Sellable::skuValue($line['sku']);

            if (isset($checked[$sku])) {
                throw new InvalidArgumentException('Packing requires one line per SKU.');
            }

            $checked[$sku] = [
                'quantity' => self::quantity($line['quantity']),
                'units' => self::quantity($line['units_per_parcel']),
                'weight' => self::positive($line['weight_grams']),
                'length' => self::positive($line['length_mm']),
                'width' => self::positive($line['width_mm']),
                'height' => self::positive($line['height_mm']),
                'tare' => MinorUnits::integer($line['tare_grams']),
            ];
        }

        // SKU order is stable even when the source cart's line order differs.
        ksort($checked, SORT_STRING);
        $parcels = [];

        foreach ($checked as $line) {
            for ($remaining = $line['quantity']; $remaining > 0; $remaining -= $units) {
                if (count($parcels) >= self::MAX_PARCELS) {
                    throw new InvalidArgumentException('A shipment can contain at most twenty parcels.');
                }

                $units = min($remaining, $line['units']);
                $parcels[] = [
                    'length' => $line['length'], 'width' => $line['width'], 'height' => $line['height'],
                    'distance_unit' => 'mm', 'weight' => CartAmounts::add(CartAmounts::multiply($line['weight'], $units), $line['tare']),
                    'mass_unit' => 'g',
                ];
            }
        }

        return $parcels;
    }

    /** A strict projection also protects the client from arbitrary parcel extras or pre-existing provider IDs.
     * @return list<Parcel>
     */
    public static function validate(mixed $parcels): array
    {
        if (! is_array($parcels) || ! array_is_list($parcels) || count($parcels) < 1 || count($parcels) > self::MAX_PARCELS) {
            throw new InvalidArgumentException('A shipment requires one through twenty checked parcels.');
        }

        $result = [];

        foreach ($parcels as $parcel) {
            if (! is_array($parcel) || count($parcel) !== 6
                || array_diff(array_keys($parcel), ['length', 'width', 'height', 'distance_unit', 'weight', 'mass_unit']) !== []
                || $parcel['distance_unit'] !== 'mm' || $parcel['mass_unit'] !== 'g') {
                throw new InvalidArgumentException('A parcel accepts only its millimetre dimensions and gram weight.');
            }

            $result[] = [
                'length' => self::positive($parcel['length']), 'width' => self::positive($parcel['width']),
                'height' => self::positive($parcel['height']), 'distance_unit' => 'mm',
                'weight' => self::positive($parcel['weight']), 'mass_unit' => 'g',
            ];
        }

        return $result;
    }

    private static function positive(mixed $value): string
    {
        $value = MinorUnits::integer($value);

        if ($value === '0') {
            throw new InvalidArgumentException('Physical parcel dimensions and weight must be positive integers.');
        }

        return $value;
    }

    private static function quantity(mixed $value): int
    {
        $value = self::positive($value);

        if (strlen($value) > 2) {
            throw new InvalidArgumentException('Parcel capacity and product quantities range from one through ninety-nine.');
        }

        return (int) $value;
    }
}
