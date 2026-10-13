<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Commerce\Money\MinorUnits;
use Kitsune\Core\Currency\Currencies;

it('converts human decimal strings to exact minor-unit integer strings', function (string $decimal, string $currency, string $minor): void {
    expect(MinorUnits::fromDecimal($decimal, $currency))->toBe($minor);
})->with([
    ['0', 'USD', '0'],
    ['0.00', 'USD', '0'],
    ['0.01', 'USD', '1'],
    ['12', 'USD', '1200'],
    ['12.3', 'USD', '1230'],
    ['12.34', 'USD', '1234'],
    ['00012.30', 'EUR', '1230'],
    ['0', 'JPY', '0'],
    ['12', 'JPY', '12'],
    ['0.001', 'KWD', '1'],
    ['12.3', 'KWD', '12300'],
    ['12.345', 'KWD', '12345'],
    ['90071992547409.91', 'USD', '9007199254740991'],
    ['92233720368547758.06', 'USD', '9223372036854775806'],
    ['92233720368547758.07', 'USD', '9223372036854775807'],
    ['9223372036854775807', 'JPY', '9223372036854775807'],
    ['9223372036854775.807', 'KWD', '9223372036854775807'],
    [str_repeat('0', 96).'1.23', 'USD', '123'],
    [str_repeat('0', 100), 'JPY', '0'],
]);

it('refuses human inputs that need guessing rounding coercion or overflow', function (mixed $decimal, mixed $currency): void {
    expect(fn () => MinorUnits::fromDecimal($decimal, $currency))->toThrow(InvalidArgumentException::class);
})->with([
    'integer is not a human decimal string' => [1, 'USD'],
    'fractional float' => [1.23, 'USD'],
    'integral float' => [1.0, 'USD'],
    'true' => [true, 'USD'],
    'false' => [false, 'USD'],
    'null' => [null, 'USD'],
    'array' => [[], 'USD'],
    'object' => [new stdClass, 'USD'],
    'empty' => ['', 'USD'],
    'negative' => ['-1', 'USD'],
    'negative zero' => ['-0', 'USD'],
    'positive sign' => ['+1', 'USD'],
    'exponent' => ['1e2', 'USD'],
    'grouping' => ['1,234.56', 'USD'],
    'decimal comma' => ['1,23', 'USD'],
    'currency symbol' => ['$1.00', 'USD'],
    'leading space' => [' 1', 'USD'],
    'trailing space' => ['1 ', 'USD'],
    'newline' => ["1\n", 'USD'],
    'missing whole digits' => ['.50', 'USD'],
    'missing fraction digits' => ['1.', 'USD'],
    'NaN' => ['NaN', 'USD'],
    'infinity' => ['INF', 'USD'],
    'Unicode digits' => ['١٢.٣٤', 'USD'],
    'NUL' => ["1\0", 'USD'],
    'too many cents' => ['1.234', 'USD'],
    'extra zero is still extra precision' => ['1.000', 'USD'],
    'no fractional yen' => ['1.0', 'JPY'],
    'too many dinar digits' => ['0.0001', 'KWD'],
    'USD max plus one' => ['92233720368547758.08', 'USD'],
    'JPY max plus one' => ['9223372036854775808', 'JPY'],
    'KWD max plus one' => ['9223372036854775.808', 'KWD'],
    'huge decimal' => [str_repeat('9', 100), 'USD'],
    'too long even if zero' => [str_repeat('0', 101), 'USD'],
    'megabyte input' => [str_repeat('0', 1024 * 1024), 'USD'],
    'unset currency' => ['1', null],
    'lowercase currency' => ['1', 'usd'],
    'unknown currency' => ['1', 'ZZZ'],
    'excluded fund' => ['1.2345', 'CLF'],
    'no currency' => ['1', 'XXX'],
]);

it('validates stored integers without truncating a float or narrowing signed BIGINT to the PHP platform', function (mixed $value, string $expected): void {
    expect(MinorUnits::integer($value))->toBe($expected);
})->with([
    [0, '0'],
    [1, '1'],
    [PHP_INT_MAX, (string) PHP_INT_MAX],
    ['0', '0'],
    ['1', '1'],
    ['9007199254740991', '9007199254740991'],
    ['9223372036854775806', '9223372036854775806'],
    ['9223372036854775807', '9223372036854775807'],
]);

it('refuses noncanonical negative floating and overflowing stored integers', function (mixed $value): void {
    expect(fn () => MinorUnits::integer($value))->toThrow(InvalidArgumentException::class);
})->with([
    [null], [true], [false], [[]], [new stdClass],
    [-1], [PHP_INT_MIN], [1.0], [1.5], [INF], [NAN],
    [''], ['00'], ['01'], ['-0'], ['-1'], ['+1'], ['1.0'], ['1e2'], ['0x10'], ['1_000'],
    [' 1'], ['1 '], ["1\n"], ["1\0"], ['١'],
    ['9223372036854775808'], ['9999999999999999999'], ['10000000000000000000'],
    [str_repeat('0', 100)], [str_repeat('9', 1024 * 1024)],
]);

it('formats minor units as an exact major-unit amount without floats', function (mixed $minor, string $currency, string $decimal): void {
    expect(MinorUnits::format($minor, $currency))->toBe($decimal);
})->with([
    [0, 'USD', '0.00'],
    ['1', 'USD', '0.01'],
    ['10', 'GBP', '0.10'],
    ['1234', 'EUR', '12.34'],
    ['0', 'JPY', '0'],
    ['1', 'JPY', '1'],
    ['0', 'KWD', '0.000'],
    ['1', 'KWD', '0.001'],
    ['10', 'KWD', '0.010'],
    ['12345', 'KWD', '12.345'],
    ['9007199254740991', 'USD', '90071992547409.91'],
    ['9223372036854775807', 'USD', '92233720368547758.07'],
    ['9223372036854775807', 'JPY', '9223372036854775807'],
    ['9223372036854775807', 'KWD', '9223372036854775.807'],
]);

it('does not allow formatting to bypass minor-unit or currency validation', function (mixed $minor, mixed $currency): void {
    expect(fn () => MinorUnits::format($minor, $currency))->toThrow(InvalidArgumentException::class);
})->with([
    [1.0, 'USD'], ['1.0', 'USD'], ['01', 'USD'], [-1, 'USD'],
    ['9223372036854775808', 'USD'], ['0', null], ['0', 'usd'], ['0', 'XXX'],
]);

it('round trips exact minor-unit boundaries through every supported currency', function (): void {
    foreach (array_keys(Currencies::all()) as $currency) {
        foreach (['0', '1', '9', '10', '1000', '9007199254740991', '9223372036854775807'] as $minor) {
            expect(MinorUnits::fromDecimal(MinorUnits::format($minor, $currency), $currency))->toBe($minor);
        }
    }
});

it('refuses floats even through weak PHP invocation', function (): void {
    foreach ([['fromDecimal', [1.23, 'USD']], ['integer', [1.0]], ['format', [1.0, 'USD']]] as [$method, $arguments]) {
        // Reflection invokes without the strict scalar checks of this test file: a string/int parameter could coerce.
        expect(fn () => (new ReflectionMethod(MinorUnits::class, $method))->invokeArgs(null, $arguments))
            ->toThrow(InvalidArgumentException::class);
    }
});
