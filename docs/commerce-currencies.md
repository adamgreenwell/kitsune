# Commerce currencies and exact amounts

An org chooses one supported currency explicitly. There is no USD, locale or application fallback for an unset currency. A zero-priced sellable still needs a currency. [ADR-040](decision-log.md#adr-040--commerce-is-a-module-entitlements-are-a-kernel-guard-and-v10-waits-for-both) records the org currency and catalogue decisions; currency changes must not relabel existing stored amounts.

`Kitsune\Core\Currency\Currencies` is an internal metadata primitive. `all()` returns a sorted map of uppercase code to `name`, three-character `numeric` code and integer `minor_units`. `supports(mixed)` returns a boolean; `minorUnits(mixed)` throws `InvalidArgumentException` for any unsupported value. Neither method normalizes case or chooses a fallback.

## The supported set

Registry version **`six-2026-09-17-v1`** contains 155 ordinary currency codes. It takes current codes from SIX List One, excludes the ten registered funds in List Two, and excludes all thirteen codes whose minor unit is `N.A.`. This is a deliberate supported set, not an assertion that every ISO code represents a currency suitable for a product price. It says nothing about a future payment provider's supported currencies.

- Registered funds excluded: `BOV`, `CHE`, `CHW`, `CLF`, `COU`, `MXV`, `USN`, `UYI`, `UYW`, `XAD`.
- Codes with no applicable minor unit excluded: `XAG`, `XAU`, `XBA`, `XBB`, `XBC`, `XBD`, `XDR`, `XPD`, `XPT`, `XSU`, `XTS`, `XUA`, `XXX`.
- Sixteen currencies use zero fractional digits: `BIF`, `CLP`, `DJF`, `GNF`, `ISK`, `JPY`, `KMF`, `KRW`, `PYG`, `RWF`, `UGX`, `VND`, `VUV`, `XAF`, `XOF`, `XPF`.
- Seven use three: `BHD`, `IQD`, `JOD`, `KWD`, `LYD`, `OMR`, `TND`. The remaining 132 use two.

`CLF` and `UYW` have four minor-unit digits in SIX's metadata, but are deliberately excluded funds. Supporting them would require an explicit change to this policy. An `X` prefix alone is not an exclusion: ordinary currencies such as `XAF`, `XCD` and `XCG` remain supported. Numeric identifiers stay strings so leading zeroes, such as `BHD`'s `048`, survive.

SIX identifies itself as the official ISO 4217 Maintenance Agency and publishes the lists on its [financial data standards page](https://www.six-group.com/en/products-services/financial-information/market-reference-data/data-standards.html). The reviewed inputs were retrieved on **2026-10-13**:

| Source | Source date | SHA-256 of downloaded bytes |
| --- | --- | --- |
| [List One XML](https://www.six-group.com/dam/download/financial-information/data-center/iso-currrency/lists/list-one.xml) | `Pblshd="2026-09-17"` | `33139b438657d1cee116ba737807ea71d19d6de4b90f799a09c56f0cc6a1b0ff` |
| [List Two funds document](https://www.six-group.com/dam/download/financial-information/data-center/iso-currrency/lists/list-two.doc) | Document date 12 May 2025 | `2c59a66ec930fd72332b7aebb6f5d940205497ca4ab39c6d34a076af2d70468c` |

List One had 280 entity records and 178 distinct codes. The bundled map deduplicates by alphabetic code after verifying that repeated records agree on name, numeric identifier and minor units; surrounding XML text whitespace is trimmed. List One does not mark funds with an XML attribute, so numerical precision alone cannot select the supported set.

## Exact amounts

`Kitsune\Commerce\Money\MinorUnits` separates human decimal amounts from stored integer amounts:

| Method | Accepted input | Returned value |
| --- | --- | --- |
| `fromDecimal(mixed $value, mixed $currency)` | ASCII major-unit decimal string and supported uppercase currency | Canonical minor-unit integer string |
| `integer(mixed $value)` | Nonnegative native PHP integer or canonical ASCII integer string | Canonical minor-unit integer string |
| `format(mixed $minor, mixed $currency)` | Valid minor units and supported uppercase currency | Exact major-unit string, without a currency symbol or code |

All failures throw `InvalidArgumentException`. The methods accept `mixed` so a weak PHP caller cannot coerce a float or boolean through a typed parameter before the validation sees it. Persistence must validate the raw write value before a model integer cast or database coercion can hide its original type.

Human input has mandatory whole-number digits and may have a dot followed by fractional digits. Leading zeroes are accepted. Missing fractional places are padded; excess precision is refused, including excess zeroes. Signs, grouping, whitespace, exponent notation, currency symbols, Unicode digits, `.50` and `1.` are refused. For zero-minor-unit currencies, a fraction is refused even if it is `.0`. The input limit is **100 ASCII bytes**, including leading zeroes, and is checked before regex matching or concatenation. Nothing trims or rounds the submitted value.

The implementation concatenates digits, removes leading zeroes and compares the resulting decimal string by length and lexical order against **`9223372036854775807`**. It performs no float conversion or arithmetic scaling. Stored integer strings must already be canonical: `0` or digits starting with `1`–`9`. They have at most nineteen digits and must fit that bound. Floats, booleans, null, negative values and strings such as `01`, `1.0` or `1e2` are refused.

Converting human input also captures the currency the operator saw. Pass that same currency to the catalogue writer with the resulting minor units. The writer must compare it with the stored org currency under the shared write lock and refuse a stale quote; reading the org currency again before the call leaves the same race. A price form must not silently reinterpret already parsed minor units after another owner changes currency.

The range is **0 through signed BIGINT maximum**, shared by SQLite, PostgreSQL, MySQL and MariaDB. Returning canonical strings preserves this range even on 32-bit PHP; database columns remain integers. Formatting pads and slices strings, preserving exact cents at the upper bound. Callers display the currency code separately.

Database round trips were measured on 64-bit PHP. The 32-bit retrieval design was checked against PHP 8.4/8.5
source: [PDO_SQLite returns out-of-native-range integers as text](https://github.com/php/php-src/blob/PHP-8.5/ext/pdo_sqlite/sqlite_statement.c#L261-L271),
[PDO_PgSQL limits native INT8 conversion to 64-bit builds](https://github.com/php/php-src/blob/PHP-8.5/ext/pdo_pgsql/pgsql_statement.c#L686-L692),
and [mysqlnd stringifies signed integers outside the native range](https://github.com/php/php-src/blob/PHP-8.4/ext/mysqlnd/mysqlnd_ps_codec.c#L132-L153).
Laravel binds the canonical amount strings as strings, and Sellable returns them through string casts. This source
inspection is not an end-to-end 32-bit runtime qualification.

```php
MinorUnits::fromDecimal('12.3', 'USD');                  // '1230'
MinorUnits::fromDecimal('12', 'JPY');                    // '12'
MinorUnits::fromDecimal('12.345', 'KWD');                // '12345'
MinorUnits::fromDecimal('92233720368547758.07', 'USD');  // '9223372036854775807'
MinorUnits::format('9223372036854775807', 'KWD');        // '9223372036854775.807'
```

The fixed table and parser add no runtime service or dependency. `ext-intl` already comes through Filament, but it does not decide the supported set or interpret a persisted amount. PHP's [currency parser returns a float](https://www.php.net/manual/en/numberformatter.parsecurrency.php), and its [integer width and overflow behavior vary by platform](https://www.php.net/manual/en/language.types.integer.php). Exact money uses neither path.

## Updating the registry

1. Download the current List One and List Two through SIX's standards page, and read its amendments. Record retrieval dates, the XML publication date, the funds document date and SHA-256 hashes. This is maintenance work; an installed application never downloads currency metadata.
2. Deduplicate List One by alphabetic code, refusing disagreements in the repeated metadata. Apply the explicit funds exclusion and `N.A.` exclusion, trim surrounding text whitespace, preserve numeric identifiers as strings, and sort by alphabetic code.
3. Review added and withdrawn codes and every changed minor-unit exponent. An update must not silently change an org's selection or reinterpret existing prices. Withdrawal of an already used code or a precision change needs an explicit data and compatibility decision before the new snapshot can ship.
4. Update `Currencies::VERSION`, the table, source provenance in this document and the source-derived digest in `CurrencyTest`. The digest is SHA-256 of the sorted map encoded as compact UTF-8 JSON with key order `name`, `numeric`, `minor_units`, unescaped Unicode and slashes. The current digest is `db959da5027e4c17b086aa31df8d4531eb07e9bef9b3be3ccb2bbc1d0946429b`.
5. Run `vendor/bin/pest tests/Core/Commerce/CurrencyTest.php tests/Core/Commerce/MoneyTest.php`, then the repository's full gate before committing. No online fixture or host ICU version is required by these tests.
