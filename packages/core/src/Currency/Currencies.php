<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Currency;

use InvalidArgumentException;

/**
 * Ordinary currencies from a reviewed ISO 4217 snapshot; no locale inference or runtime lookup.
 *
 * SIX List One, published 2026-09-17, retrieved 2026-10-13:
 * https://www.six-group.com/dam/download/financial-information/data-center/iso-currrency/lists/list-one.xml
 * SHA256: 33139b438657d1cee116ba737807ea71d19d6de4b90f799a09c56f0cc6a1b0ff
 *
 * List Two funds and codes with N.A. minor units are excluded. See docs/commerce-currencies.md for the exact
 * eligibility policy, source provenance and update procedure. Metadata support does not promise payment acceptance.
 *
 * @internal First-party org currency metadata; no extension stability promise before v1.2.
 */
final class Currencies
{
    public const VERSION = 'six-2026-09-17-v1';

    /** @var array<string, array{name: string, numeric: string, minor_units: int}> */
    private const CURRENCIES = [
        'AED' => ['name' => 'UAE Dirham', 'numeric' => '784', 'minor_units' => 2],
        'AFN' => ['name' => 'Afghani', 'numeric' => '971', 'minor_units' => 2],
        'ALL' => ['name' => 'Lek', 'numeric' => '008', 'minor_units' => 2],
        'AMD' => ['name' => 'Armenian Dram', 'numeric' => '051', 'minor_units' => 2],
        'AOA' => ['name' => 'Kwanza', 'numeric' => '973', 'minor_units' => 2],
        'ARS' => ['name' => 'Argentine Peso', 'numeric' => '032', 'minor_units' => 2],
        'AUD' => ['name' => 'Australian Dollar', 'numeric' => '036', 'minor_units' => 2],
        'AWG' => ['name' => 'Aruban Florin', 'numeric' => '533', 'minor_units' => 2],
        'AZN' => ['name' => 'Azerbaijan Manat', 'numeric' => '944', 'minor_units' => 2],
        'BAM' => ['name' => 'Convertible Mark', 'numeric' => '977', 'minor_units' => 2],
        'BBD' => ['name' => 'Barbados Dollar', 'numeric' => '052', 'minor_units' => 2],
        'BDT' => ['name' => 'Taka', 'numeric' => '050', 'minor_units' => 2],
        'BHD' => ['name' => 'Bahraini Dinar', 'numeric' => '048', 'minor_units' => 3],
        'BIF' => ['name' => 'Burundi Franc', 'numeric' => '108', 'minor_units' => 0],
        'BMD' => ['name' => 'Bermudian Dollar', 'numeric' => '060', 'minor_units' => 2],
        'BND' => ['name' => 'Brunei Dollar', 'numeric' => '096', 'minor_units' => 2],
        'BOB' => ['name' => 'Boliviano', 'numeric' => '068', 'minor_units' => 2],
        'BRL' => ['name' => 'Brazilian Real', 'numeric' => '986', 'minor_units' => 2],
        'BSD' => ['name' => 'Bahamian Dollar', 'numeric' => '044', 'minor_units' => 2],
        'BTN' => ['name' => 'Ngultrum', 'numeric' => '064', 'minor_units' => 2],
        'BWP' => ['name' => 'Pula', 'numeric' => '072', 'minor_units' => 2],
        'BYN' => ['name' => 'Belarusian Ruble', 'numeric' => '933', 'minor_units' => 2],
        'BZD' => ['name' => 'Belize Dollar', 'numeric' => '084', 'minor_units' => 2],
        'CAD' => ['name' => 'Canadian Dollar', 'numeric' => '124', 'minor_units' => 2],
        'CDF' => ['name' => 'Congolese Franc', 'numeric' => '976', 'minor_units' => 2],
        'CHF' => ['name' => 'Swiss Franc', 'numeric' => '756', 'minor_units' => 2],
        'CLP' => ['name' => 'Chilean Peso', 'numeric' => '152', 'minor_units' => 0],
        'CNY' => ['name' => 'Yuan Renminbi', 'numeric' => '156', 'minor_units' => 2],
        'COP' => ['name' => 'Colombian Peso', 'numeric' => '170', 'minor_units' => 2],
        'CRC' => ['name' => 'Costa Rican Colon', 'numeric' => '188', 'minor_units' => 2],
        'CUP' => ['name' => 'Cuban Peso', 'numeric' => '192', 'minor_units' => 2],
        'CVE' => ['name' => 'Cabo Verde Escudo', 'numeric' => '132', 'minor_units' => 2],
        'CZK' => ['name' => 'Czech Koruna', 'numeric' => '203', 'minor_units' => 2],
        'DJF' => ['name' => 'Djibouti Franc', 'numeric' => '262', 'minor_units' => 0],
        'DKK' => ['name' => 'Danish Krone', 'numeric' => '208', 'minor_units' => 2],
        'DOP' => ['name' => 'Dominican Peso', 'numeric' => '214', 'minor_units' => 2],
        'DZD' => ['name' => 'Algerian Dinar', 'numeric' => '012', 'minor_units' => 2],
        'EGP' => ['name' => 'Egyptian Pound', 'numeric' => '818', 'minor_units' => 2],
        'ERN' => ['name' => 'Nakfa', 'numeric' => '232', 'minor_units' => 2],
        'ETB' => ['name' => 'Ethiopian Birr', 'numeric' => '230', 'minor_units' => 2],
        'EUR' => ['name' => 'Euro', 'numeric' => '978', 'minor_units' => 2],
        'FJD' => ['name' => 'Fiji Dollar', 'numeric' => '242', 'minor_units' => 2],
        'FKP' => ['name' => 'Falkland Islands Pound', 'numeric' => '238', 'minor_units' => 2],
        'GBP' => ['name' => 'Pound Sterling', 'numeric' => '826', 'minor_units' => 2],
        'GEL' => ['name' => 'Lari', 'numeric' => '981', 'minor_units' => 2],
        'GHS' => ['name' => 'Ghana Cedi', 'numeric' => '936', 'minor_units' => 2],
        'GIP' => ['name' => 'Gibraltar Pound', 'numeric' => '292', 'minor_units' => 2],
        'GMD' => ['name' => 'Dalasi', 'numeric' => '270', 'minor_units' => 2],
        'GNF' => ['name' => 'Guinean Franc', 'numeric' => '324', 'minor_units' => 0],
        'GTQ' => ['name' => 'Quetzal', 'numeric' => '320', 'minor_units' => 2],
        'GYD' => ['name' => 'Guyana Dollar', 'numeric' => '328', 'minor_units' => 2],
        'HKD' => ['name' => 'Hong Kong Dollar', 'numeric' => '344', 'minor_units' => 2],
        'HNL' => ['name' => 'Lempira', 'numeric' => '340', 'minor_units' => 2],
        'HTG' => ['name' => 'Gourde', 'numeric' => '332', 'minor_units' => 2],
        'HUF' => ['name' => 'Forint', 'numeric' => '348', 'minor_units' => 2],
        'IDR' => ['name' => 'Rupiah', 'numeric' => '360', 'minor_units' => 2],
        'ILS' => ['name' => 'New Israeli Sheqel', 'numeric' => '376', 'minor_units' => 2],
        'INR' => ['name' => 'Indian Rupee', 'numeric' => '356', 'minor_units' => 2],
        'IQD' => ['name' => 'Iraqi Dinar', 'numeric' => '368', 'minor_units' => 3],
        'IRR' => ['name' => 'Iranian Rial', 'numeric' => '364', 'minor_units' => 2],
        'ISK' => ['name' => 'Iceland Krona', 'numeric' => '352', 'minor_units' => 0],
        'JMD' => ['name' => 'Jamaican Dollar', 'numeric' => '388', 'minor_units' => 2],
        'JOD' => ['name' => 'Jordanian Dinar', 'numeric' => '400', 'minor_units' => 3],
        'JPY' => ['name' => 'Yen', 'numeric' => '392', 'minor_units' => 0],
        'KES' => ['name' => 'Kenyan Shilling', 'numeric' => '404', 'minor_units' => 2],
        'KGS' => ['name' => 'Som', 'numeric' => '417', 'minor_units' => 2],
        'KHR' => ['name' => 'Riel', 'numeric' => '116', 'minor_units' => 2],
        'KMF' => ['name' => 'Comorian Franc', 'numeric' => '174', 'minor_units' => 0],
        'KPW' => ['name' => 'North Korean Won', 'numeric' => '408', 'minor_units' => 2],
        'KRW' => ['name' => 'Won', 'numeric' => '410', 'minor_units' => 0],
        'KWD' => ['name' => 'Kuwaiti Dinar', 'numeric' => '414', 'minor_units' => 3],
        'KYD' => ['name' => 'Cayman Islands Dollar', 'numeric' => '136', 'minor_units' => 2],
        'KZT' => ['name' => 'Tenge', 'numeric' => '398', 'minor_units' => 2],
        'LAK' => ['name' => 'Lao Kip', 'numeric' => '418', 'minor_units' => 2],
        'LBP' => ['name' => 'Lebanese Pound', 'numeric' => '422', 'minor_units' => 2],
        'LKR' => ['name' => 'Sri Lanka Rupee', 'numeric' => '144', 'minor_units' => 2],
        'LRD' => ['name' => 'Liberian Dollar', 'numeric' => '430', 'minor_units' => 2],
        'LSL' => ['name' => 'Loti', 'numeric' => '426', 'minor_units' => 2],
        'LYD' => ['name' => 'Libyan Dinar', 'numeric' => '434', 'minor_units' => 3],
        'MAD' => ['name' => 'Moroccan Dirham', 'numeric' => '504', 'minor_units' => 2],
        'MDL' => ['name' => 'Moldovan Leu', 'numeric' => '498', 'minor_units' => 2],
        'MGA' => ['name' => 'Malagasy Ariary', 'numeric' => '969', 'minor_units' => 2],
        'MKD' => ['name' => 'Denar', 'numeric' => '807', 'minor_units' => 2],
        'MMK' => ['name' => 'Kyat', 'numeric' => '104', 'minor_units' => 2],
        'MNT' => ['name' => 'Tugrik', 'numeric' => '496', 'minor_units' => 2],
        'MOP' => ['name' => 'Pataca', 'numeric' => '446', 'minor_units' => 2],
        'MRU' => ['name' => 'Ouguiya', 'numeric' => '929', 'minor_units' => 2],
        'MUR' => ['name' => 'Mauritius Rupee', 'numeric' => '480', 'minor_units' => 2],
        'MVR' => ['name' => 'Rufiyaa', 'numeric' => '462', 'minor_units' => 2],
        'MWK' => ['name' => 'Malawi Kwacha', 'numeric' => '454', 'minor_units' => 2],
        'MXN' => ['name' => 'Mexican Peso', 'numeric' => '484', 'minor_units' => 2],
        'MYR' => ['name' => 'Malaysian Ringgit', 'numeric' => '458', 'minor_units' => 2],
        'MZN' => ['name' => 'Mozambique Metical', 'numeric' => '943', 'minor_units' => 2],
        'NAD' => ['name' => 'Namibia Dollar', 'numeric' => '516', 'minor_units' => 2],
        'NGN' => ['name' => 'Naira', 'numeric' => '566', 'minor_units' => 2],
        'NIO' => ['name' => 'Cordoba Oro', 'numeric' => '558', 'minor_units' => 2],
        'NOK' => ['name' => 'Norwegian Krone', 'numeric' => '578', 'minor_units' => 2],
        'NPR' => ['name' => 'Nepalese Rupee', 'numeric' => '524', 'minor_units' => 2],
        'NZD' => ['name' => 'New Zealand Dollar', 'numeric' => '554', 'minor_units' => 2],
        'OMR' => ['name' => 'Rial Omani', 'numeric' => '512', 'minor_units' => 3],
        'PAB' => ['name' => 'Balboa', 'numeric' => '590', 'minor_units' => 2],
        'PEN' => ['name' => 'Sol', 'numeric' => '604', 'minor_units' => 2],
        'PGK' => ['name' => 'Kina', 'numeric' => '598', 'minor_units' => 2],
        'PHP' => ['name' => 'Philippine Peso', 'numeric' => '608', 'minor_units' => 2],
        'PKR' => ['name' => 'Pakistan Rupee', 'numeric' => '586', 'minor_units' => 2],
        'PLN' => ['name' => 'Zloty', 'numeric' => '985', 'minor_units' => 2],
        'PYG' => ['name' => 'Guarani', 'numeric' => '600', 'minor_units' => 0],
        'QAR' => ['name' => 'Qatari Rial', 'numeric' => '634', 'minor_units' => 2],
        'RON' => ['name' => 'Romanian Leu', 'numeric' => '946', 'minor_units' => 2],
        'RSD' => ['name' => 'Serbian Dinar', 'numeric' => '941', 'minor_units' => 2],
        'RUB' => ['name' => 'Russian Ruble', 'numeric' => '643', 'minor_units' => 2],
        'RWF' => ['name' => 'Rwanda Franc', 'numeric' => '646', 'minor_units' => 0],
        'SAR' => ['name' => 'Saudi Riyal', 'numeric' => '682', 'minor_units' => 2],
        'SBD' => ['name' => 'Solomon Islands Dollar', 'numeric' => '090', 'minor_units' => 2],
        'SCR' => ['name' => 'Seychelles Rupee', 'numeric' => '690', 'minor_units' => 2],
        'SDG' => ['name' => 'Sudanese Pound', 'numeric' => '938', 'minor_units' => 2],
        'SEK' => ['name' => 'Swedish Krona', 'numeric' => '752', 'minor_units' => 2],
        'SGD' => ['name' => 'Singapore Dollar', 'numeric' => '702', 'minor_units' => 2],
        'SHP' => ['name' => 'Saint Helena Pound', 'numeric' => '654', 'minor_units' => 2],
        'SLE' => ['name' => 'Leone', 'numeric' => '925', 'minor_units' => 2],
        'SOS' => ['name' => 'Somali Shilling', 'numeric' => '706', 'minor_units' => 2],
        'SRD' => ['name' => 'Surinam Dollar', 'numeric' => '968', 'minor_units' => 2],
        'SSP' => ['name' => 'South Sudanese Pound', 'numeric' => '728', 'minor_units' => 2],
        'STN' => ['name' => 'Dobra', 'numeric' => '930', 'minor_units' => 2],
        'SVC' => ['name' => 'El Salvador Colon', 'numeric' => '222', 'minor_units' => 2],
        'SYP' => ['name' => 'Syrian Pound', 'numeric' => '760', 'minor_units' => 2],
        'SZL' => ['name' => 'Lilangeni', 'numeric' => '748', 'minor_units' => 2],
        'THB' => ['name' => 'Baht', 'numeric' => '764', 'minor_units' => 2],
        'TJS' => ['name' => 'Somoni', 'numeric' => '972', 'minor_units' => 2],
        'TMT' => ['name' => 'Turkmenistan New Manat', 'numeric' => '934', 'minor_units' => 2],
        'TND' => ['name' => 'Tunisian Dinar', 'numeric' => '788', 'minor_units' => 3],
        'TOP' => ['name' => 'Pa’anga', 'numeric' => '776', 'minor_units' => 2],
        'TRY' => ['name' => 'Turkish Lira', 'numeric' => '949', 'minor_units' => 2],
        'TTD' => ['name' => 'Trinidad and Tobago Dollar', 'numeric' => '780', 'minor_units' => 2],
        'TWD' => ['name' => 'New Taiwan Dollar', 'numeric' => '901', 'minor_units' => 2],
        'TZS' => ['name' => 'Tanzanian Shilling', 'numeric' => '834', 'minor_units' => 2],
        'UAH' => ['name' => 'Hryvnia', 'numeric' => '980', 'minor_units' => 2],
        'UGX' => ['name' => 'Uganda Shilling', 'numeric' => '800', 'minor_units' => 0],
        'USD' => ['name' => 'US Dollar', 'numeric' => '840', 'minor_units' => 2],
        'UYU' => ['name' => 'Peso Uruguayo', 'numeric' => '858', 'minor_units' => 2],
        'UZS' => ['name' => 'Uzbekistan Sum', 'numeric' => '860', 'minor_units' => 2],
        'VED' => ['name' => 'Bolívar Soberano', 'numeric' => '926', 'minor_units' => 2],
        'VES' => ['name' => 'Bolívar Soberano', 'numeric' => '928', 'minor_units' => 2],
        'VND' => ['name' => 'Dong', 'numeric' => '704', 'minor_units' => 0],
        'VUV' => ['name' => 'Vatu', 'numeric' => '548', 'minor_units' => 0],
        'WST' => ['name' => 'Tala', 'numeric' => '882', 'minor_units' => 2],
        'XAF' => ['name' => 'CFA Franc BEAC', 'numeric' => '950', 'minor_units' => 0],
        'XCD' => ['name' => 'East Caribbean Dollar', 'numeric' => '951', 'minor_units' => 2],
        'XCG' => ['name' => 'Caribbean Guilder', 'numeric' => '532', 'minor_units' => 2],
        'XOF' => ['name' => 'CFA Franc BCEAO', 'numeric' => '952', 'minor_units' => 0],
        'XPF' => ['name' => 'CFP Franc', 'numeric' => '953', 'minor_units' => 0],
        'YER' => ['name' => 'Yemeni Rial', 'numeric' => '886', 'minor_units' => 2],
        'ZAR' => ['name' => 'Rand', 'numeric' => '710', 'minor_units' => 2],
        'ZMW' => ['name' => 'Zambian Kwacha', 'numeric' => '967', 'minor_units' => 2],
        'ZWG' => ['name' => 'Zimbabwe Gold', 'numeric' => '924', 'minor_units' => 2],
    ];

    /** @return array<string, array{name: string, numeric: string, minor_units: int}> */
    public static function all(): array
    {
        return self::CURRENCIES;
    }

    public static function minorUnits(mixed $code): int
    {
        if (! is_string($code) || ! isset(self::CURRENCIES[$code])) {
            throw new InvalidArgumentException('Choose a supported uppercase ISO 4217 currency code.');
        }

        return self::CURRENCIES[$code]['minor_units'];
    }

    public static function supports(mixed $code): bool
    {
        return is_string($code) && isset(self::CURRENCIES[$code]);
    }
}
