<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Currency\Currencies;

it('pins ordinary currency metadata to the reviewed SIX snapshot', function (): void {
    $currencies = Currencies::all();

    // Derived independently from SIX List One, Pblshd 2026-09-17, excluding List Two funds and N.A. minor units.
    // A metadata change requires the reviewed source update described in docs/commerce-currencies.md.
    expect($currencies)->toHaveCount(155)
        ->and(hash('sha256', json_encode($currencies, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))
        ->toBe('db959da5027e4c17b086aa31df8d4531eb07e9bef9b3be3ccb2bbc1d0946429b');

    foreach ($currencies as $code => $metadata) {
        expect($code)->toMatch('/\A[A-Z]{3}\z/')
            ->and($metadata['numeric'])->toMatch('/\A[0-9]{3}\z/')
            ->and($metadata['name'])->toBeString()->not->toBe('')
            ->and(Currencies::supports($code))->toBeTrue()
            ->and(Currencies::minorUnits($code))->toBe($metadata['minor_units']);
    }

    expect(array_unique(array_column($currencies, 'numeric')))->toHaveCount(155)
        ->and(array_count_values(array_column($currencies, 'minor_units')))->toEqual([0 => 16, 2 => 132, 3 => 7])
        ->and($currencies['BHD']['numeric'])->toBe('048');
});

it('uses each currency minor unit rather than assuming cents', function (string $code, int $digits): void {
    expect(Currencies::minorUnits($code))->toBe($digits);
})->with([
    'US dollar' => ['USD', 2],
    'euro' => ['EUR', 2],
    'pound sterling' => ['GBP', 2],
    'yen' => ['JPY', 0],
    'won' => ['KRW', 0],
    'Kuwaiti dinar' => ['KWD', 3],
    'Bahraini dinar' => ['BHD', 3],
    'ordinary X code' => ['XAF', 0],
    'Caribbean guilder' => ['XCG', 2],
]);

it('refuses unset malformed historic and non-currency codes without a fallback', function (mixed $code): void {
    expect(Currencies::supports($code))->toBeFalse()
        ->and(fn () => Currencies::minorUnits($code))->toThrow(InvalidArgumentException::class);
})->with([
    'unset' => [null],
    'false' => [false],
    'integer' => [840],
    'float' => [840.0],
    'array' => [['USD']],
    'object' => [new stdClass],
    'blank' => [''],
    'lowercase' => ['usd'],
    'mixed case' => ['Usd'],
    'leading space' => [' USD'],
    'trailing space' => ['USD '],
    'newline' => ["USD\n"],
    'unknown' => ['ZZZ'],
    'historic lev' => ['BGN'],
    'historic Zimbabwe dollar' => ['ZWL'],
    'fund with four digits' => ['CLF'],
    'other four-digit fund' => ['UYW'],
    'indexed fund' => ['UYI'],
    'gold' => ['XAU'],
    'silver' => ['XAG'],
    'accounting unit' => ['XDR'],
    'testing code' => ['XTS'],
    'no currency' => ['XXX'],
]);

it('does not let a caller mutate the shared currency metadata', function (): void {
    $currencies = Currencies::all();
    $currencies['USD']['minor_units'] = 0;
    unset($currencies['JPY']);

    expect(Currencies::minorUnits('USD'))->toBe(2)
        ->and(Currencies::supports('JPY'))->toBeTrue();
});
