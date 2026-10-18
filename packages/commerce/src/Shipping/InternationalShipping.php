<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Shipping;

use InvalidArgumentException;
use Kitsune\Commerce\Models\SalesPolicy;
use Kitsune\Commerce\Money\MinorUnits;
use Kitsune\Commerce\Orders\BillingDetails;
use Kitsune\Commerce\Persistence\CommerceSetupValues;

/** Explicit owner inputs only. A rate declaration never authorizes a label or certifies legal compliance. @internal */
final class InternationalShipping
{
    public const SKU_FIELDS = ['customs_description', 'customs_origin_country', 'customs_tariff_number'];

    /** @return array{destinations:list<string>,carrier_accounts:array{test:list<string>,live:list<string>},certify_signer:string,certified:true}|null */
    public static function profile(#[\SensitiveParameter] mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException('International shipping requires an explicit configuration.');
        }
        CommerceSetupValues::keys($value, ['destinations', 'carrier_accounts', 'certify_signer', 'certified']);
        $countries = $value['destinations'] ?? null;
        $accounts = $value['carrier_accounts'] ?? null;
        if (! is_array($countries) || ! array_is_list($countries) || count($countries) < 1 || count($countries) > 50
            || ! is_array($accounts) || ($value['certified'] ?? null) !== true) {
            throw new InvalidArgumentException('Choose destinations, mode-specific carrier accounts and explicit customs certification.');
        }
        CommerceSetupValues::keys($accounts, ['test', 'live']);
        foreach ($countries as $country) {
            self::country($country);
        }
        if (count(array_unique($countries)) !== count($countries)) {
            throw new InvalidArgumentException('Destination countries must be distinct.');
        }
        foreach (['test', 'live'] as $mode) {
            $ids = $accounts[$mode] ?? null;
            if (! is_array($ids) || ! array_is_list($ids) || count($ids) > 10) {
                throw new InvalidArgumentException('Choose at most ten carrier accounts per mode.');
            }
            foreach ($ids as $id) {
                if (! is_string($id) || preg_match('/\A[a-f0-9]{32}\z/D', $id) !== 1) {
                    throw new InvalidArgumentException('Use an exact Shippo carrier account object ID.');
                }
            }
            if (count(array_unique($ids)) !== count($ids)) {
                throw new InvalidArgumentException('Carrier account IDs must be distinct.');
            }
        }
        if ($accounts['test'] === [] && $accounts['live'] === []) {
            throw new InvalidArgumentException('Configure at least one mode explicitly.');
        }

        return ['destinations' => $countries, 'carrier_accounts' => ['test' => $accounts['test'], 'live' => $accounts['live']],
            'certify_signer' => BillingDetails::name($value['certify_signer'] ?? null), 'certified' => true];
    }

    /** Partial customs fields may be saved for domestic goods; international admission requires all three.
     * @param  array<string,mixed>  $values
     * @return array<string,?string>
     */
    public static function sku(array $values, bool $physical): array
    {
        $result = [];
        foreach (self::SKU_FIELDS as $field) {
            $value = $values[$field] ?? null;
            if ($value !== null) {
                if (! $physical) {
                    throw new InvalidArgumentException('Digital goods cannot carry customs data.');
                }
                if ($field === 'customs_origin_country') {
                    self::country($value);
                } elseif ($field === 'customs_tariff_number') {
                    if (! is_string($value) || preg_match('/\A[0-9]{6,12}\z/D', $value) !== 1) {
                        throw new InvalidArgumentException('Enter a six to twelve digit tariff number.');
                    }
                } elseif (! is_string($value) || strlen($value) > 200 || ! mb_check_encoding($value, 'UTF-8')
                    || mb_strlen($value, 'UTF-8') > 50 || trim($value) === '' || preg_match('/[\p{Cc}\x{2028}\x{2029}]/u', $value) === 1) {
                    throw new InvalidArgumentException('Enter a customs description of at most fifty characters.');
                }
            }
            $result[$field] = $value;
        }

        return $result;
    }

    /** Build only the provider's checked customs fields; no arbitrary catalogue, profile or buyer extras.
     * @param  array<string,mixed>  $context
     * @return array{carrier_accounts:list<string>,customs_declaration:array<string,mixed>}
     */
    public static function payload(#[\SensitiveParameter] array $context, string $destination, string $currency, bool $test): array
    {
        CommerceSetupValues::keys($context, ['profile', 'items']);
        $profile = self::profile($context['profile'] ?? null);
        $items = $context['items'] ?? null;
        if ($profile === null || ! in_array($destination, $profile['destinations'], true)
            || $profile['carrier_accounts'][$test ? 'test' : 'live'] === []
            || ! is_array($items) || ! array_is_list($items) || count($items) < 1 || count($items) > 20) {
            throw new InvalidArgumentException('International shipping is unavailable for these goods, destination or carrier mode.');
        }
        $customs = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException('Customs goods must be checked SKU inputs.');
            }
            CommerceSetupValues::keys($item, [...self::SKU_FIELDS, 'quantity', 'weight_grams', 'net_minor']);
            $sku = self::sku($item, true);
            if (in_array(null, $sku, true)) {
                throw new InvalidArgumentException('Complete every physical SKU customs profile before international shipping.');
            }
            $quantity = (int) CommerceSetupValues::integer($item['quantity'] ?? null, '99');
            $weight = (int) CommerceSetupValues::integer($item['weight_grams'] ?? null, (string) SalesPolicy::MAX_WEIGHT_GRAMS);
            $net = MinorUnits::integer($item['net_minor'] ?? null);
            if ($net === '0') {
                throw new InvalidArgumentException('Zero-value goods require a separate international shipping policy.');
            }
            $customs[] = ['description' => $sku['customs_description'], 'origin_country' => $sku['customs_origin_country'],
                'tariff_number' => $sku['customs_tariff_number'], 'quantity' => $quantity, 'net_weight' => (string) ($weight * $quantity),
                'mass_unit' => 'g', 'value_amount' => MinorUnits::format($net, $currency), 'value_currency' => $currency];
        }

        return ['carrier_accounts' => $profile['carrier_accounts'][$test ? 'test' : 'live'],
            'customs_declaration' => ['contents_type' => 'MERCHANDISE', 'non_delivery_option' => 'RETURN', 'incoterm' => 'DDU',
                'certify' => true, 'certify_signer' => $profile['certify_signer'], 'items' => $customs]];
    }

    private static function country(mixed $value): void
    {
        if (! is_string($value) || preg_match('/\A[A-Z]{2}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('Use an uppercase two-letter country code.');
        }
    }
}
