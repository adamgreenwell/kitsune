<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Commerce\Carts\CartAmounts;
use Kitsune\Commerce\Money\MinorUnits;
use Kitsune\Commerce\Taxes\TaxAmounts;

it('parses exact decimal percentages into integer millionths', function (mixed $percentage, int $rate): void {
    expect(TaxAmounts::rate($percentage))->toBe($rate);
})->with([
    [0, 0], ['0', 0], ['0.0000', 0], ['0.0001', 1], ['0.1', 1000], ['7.25', 72500], ['7.3125', 73125],
    ['7.2500', 72500], ['12.3456', 123456], ['99.9999', 999999], [100, 1000000], ['100.0000', 1000000],
]);

it('refuses percentage aliases invalid precision and rates beyond one hundred percent', function (mixed $value): void {
    expect(fn () => TaxAmounts::rate($value))->toThrow(InvalidArgumentException::class);
})->with([
    [''], ['00'], ['07.25'], ['.5'], ['1.'], ['1.00000'], ['100.0001'], ['101'], ['999'], ['1000'],
    ['-1'], ['+1'], [' 1'], ['1 '], ["1\n"], ['1,5'], ['1e1'], ['１'], [1.0], [0.0], [true], [false], [null], [[]],
    [str_repeat('1', 1000)],
]);

it('calculates exclusive tax half-up once for the complete line', function (string $net, int $rate, array $expected): void {
    expect(TaxAmounts::exclusive($net, $rate))->toBe($expected);
})->with([
    ['100', 200000, ['net' => '100', 'tax' => '20', 'gross' => '120']],
    ['1', 499999, ['net' => '1', 'tax' => '0', 'gross' => '1']],
    ['1', 500000, ['net' => '1', 'tax' => '1', 'gross' => '2']],
    ['1', 500001, ['net' => '1', 'tax' => '1', 'gross' => '2']],
    ['800', 73125, ['net' => '800', 'tax' => '59', 'gross' => '859']],
    ['10000', 123456, ['net' => '10000', 'tax' => '1235', 'gross' => '11235']],
    ['499999', 1, ['net' => '499999', 'tax' => '0', 'gross' => '499999']],
    ['500000', 1, ['net' => '500000', 'tax' => '1', 'gross' => '500001']],
    ['9007199254740993', 200000, ['net' => '9007199254740993', 'tax' => '1801439850948199', 'gross' => '10808639105689192']],
    ['4611686018427387903', 1000000, ['net' => '4611686018427387903', 'tax' => '4611686018427387903', 'gross' => '9223372036854775806']],
]);

it('extracts inclusive tax half-up and preserves the exact gross as net plus tax', function (string $gross, int $rate, array $expected): void {
    $amounts = TaxAmounts::inclusive($gross, $rate);
    expect($amounts)->toBe($expected)->and(CartAmounts::add($amounts['net'], $amounts['tax']))->toBe($gross);
})->with([
    ['120', 200000, ['net' => '100', 'tax' => '20', 'gross' => '120']],
    ['100', 200000, ['net' => '83', 'tax' => '17', 'gross' => '100']],
    ['1', 1000000, ['net' => '0', 'tax' => '1', 'gross' => '1']],
    ['2', 1000000, ['net' => '1', 'tax' => '1', 'gross' => '2']],
    ['3', 1000000, ['net' => '1', 'tax' => '2', 'gross' => '3']],
    ['10000', 123456, ['net' => '8901', 'tax' => '1099', 'gross' => '10000']],
    ['500000', 1, ['net' => '500000', 'tax' => '0', 'gross' => '500000']],
    ['500001', 1, ['net' => '500000', 'tax' => '1', 'gross' => '500001']],
    ['9007199254740993', 200000, ['net' => '7505999378950827', 'tax' => '1501199875790166', 'gross' => '9007199254740993']],
    ['9223372036854775807', 1000000, ['net' => '4611686018427387903', 'tax' => '4611686018427387904', 'gross' => '9223372036854775807']],
    ['9223372036854775807', 200000, ['net' => '7686143364045646506', 'tax' => '1537228672809129301', 'gross' => '9223372036854775807']],
    ['9223372036854775807', 1, ['net' => '9223362813491962315', 'tax' => '9223362813492', 'gross' => '9223372036854775807']],
]);

it('treats zero rate and zero line amounts explicitly on both paths', function (string $method): void {
    expect(TaxAmounts::{$method}('9223372036854775807', 0))->toBe(['net' => '9223372036854775807', 'tax' => '0', 'gross' => '9223372036854775807'])
        ->and(TaxAmounts::{$method}('0', 0))->toBe(['net' => '0', 'tax' => '0', 'gross' => '0'])
        ->and(TaxAmounts::{$method}('0', 1000000))->toBe(['net' => '0', 'tax' => '0', 'gross' => '0']);
})->with(['exclusive', 'inclusive']);

it('uses existing currency minor units for zero and three decimal places', function (): void {
    $jpy = TaxAmounts::exclusive(MinorUnits::fromDecimal('5', 'JPY'), TaxAmounts::rate('10'));
    $kwd = TaxAmounts::exclusive(MinorUnits::fromDecimal('0.005', 'KWD'), TaxAmounts::rate('10'));
    expect($jpy)->toBe(['net' => '5', 'tax' => '1', 'gross' => '6'])
        ->and($kwd)->toBe($jpy)->and(MinorUnits::format($jpy['tax'], 'JPY'))->toBe('1')
        ->and(MinorUnits::format($kwd['tax'], 'KWD'))->toBe('0.001');
});

it('rounds a quantity line once instead of multiplying rounded unit taxes', function (): void {
    $rate = TaxAmounts::rate('25');
    $unit = TaxAmounts::exclusive('5', $rate);
    $line = TaxAmounts::exclusive(CartAmounts::multiply('5', 2), $rate);
    expect($unit['tax'])->toBe('1')->and($line['tax'])->toBe('3')
        ->and(CartAmounts::multiply($unit['tax'], 2))->toBe('2');
});

it('accepts canonical integer rate strings without coercing malformed rates', function (): void {
    expect(TaxAmounts::exclusive('100', '200000'))->toBe(TaxAmounts::exclusive('100', 200000))
        ->and(TaxAmounts::inclusive('120', '200000'))->toBe(TaxAmounts::inclusive('120', 200000));
});

it('refuses invalid millionth rates on both calculation paths', function (mixed $rate): void {
    foreach (['exclusive', 'inclusive'] as $method) {
        expect(fn () => TaxAmounts::{$method}('100', $rate))->toThrow(InvalidArgumentException::class);
    }
})->with([
    [-1], [1000001], ['1000001'], ['01'], ['-1'], ['+1'], ['1.0'], ['1e5'], [' 1'], ['1 '], ["1\n"],
    [''], [1.0], [true], [false], [null], [[]], [str_repeat('1', 1000)],
]);

it('refuses invalid or out-of-range money on both calculation paths', function (mixed $minor): void {
    foreach (['exclusive', 'inclusive'] as $method) {
        expect(fn () => TaxAmounts::{$method}($minor, 200000))->toThrow(InvalidArgumentException::class);
    }
})->with([
    [-1], ['9223372036854775808'], ['01'], ['-1'], ['+1'], ['1.0'], ['1e3'], [' 1'], ['1 '], ["1\n"],
    [''], [1.0], [true], [false], [null], [[]], [str_repeat('1', 1000)],
]);

it('refuses gross overflow even when the exact tax calculation fits', function (string $net, int $rate): void {
    expect(fn () => TaxAmounts::exclusive($net, $rate))->toThrow(InvalidArgumentException::class);
})->with([
    ['4611686018427387904', 1000000], ['9223372036854775807', 1], ['9223372036854775807', 200000], ['9223372036854775807', 1000000],
]);
