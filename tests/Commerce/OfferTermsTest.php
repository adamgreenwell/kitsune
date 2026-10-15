<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Kitsune\Commerce\Offers\OfferTerms;

it('keeps absent historical terms unknown and canonicalizes exact version one objects', function (): void {
    expect(OfferTerms::forMapping(null, null))->toBeNull()
        ->and(OfferTerms::forMapping('course.surf', null))->toBeNull()
        ->and(fn () => OfferTerms::purchasable('course.surf', null))->toThrow(RuntimeException::class)
        ->and(OfferTerms::normalize(['kind' => 'fixed', 'days' => 36500, 'version' => 1]))
        ->toBe(['version' => 1, 'kind' => 'fixed', 'days' => 36500])
        ->and(OfferTerms::stored('{"days":30,"kind":"fixed","version":1}'))
        ->toBe(['version' => 1, 'kind' => 'fixed', 'days' => 30])
        ->and(OfferTerms::stored('{"kind":"permanent","version":1}'))->toBe(['version' => 1, 'kind' => 'permanent'])
        ->and(fn () => OfferTerms::forMapping(null, ['version' => 1, 'kind' => 'permanent']))->toThrow(RuntimeException::class);
});

it('refuses loose scalar types unknown versions and extra offer terms', function (mixed $terms): void {
    expect(fn () => OfferTerms::normalize($terms))->toThrow(RuntimeException::class);
})->with([
    'JSON string' => ['{"version":1,"kind":"permanent"}'], 'empty' => [[]],
    'object' => [(object) ['version' => 1, 'kind' => 'permanent']], 'boolean' => [false],
    'string version' => [['version' => '1', 'kind' => 'permanent']], 'float version' => [['version' => 1.0, 'kind' => 'permanent']],
    'future version' => [['version' => 2, 'kind' => 'permanent']], 'unknown kind' => [['version' => 1, 'kind' => 'forever']],
    'extra days permanent' => [['version' => 1, 'kind' => 'permanent', 'days' => 30]],
    'extra reader' => [['version' => 1, 'kind' => 'fixed', 'days' => 30, 'reader' => 'private']],
    'missing days' => [['version' => 1, 'kind' => 'fixed']], 'zero' => [['version' => 1, 'kind' => 'fixed', 'days' => 0]],
    'negative' => [['version' => 1, 'kind' => 'fixed', 'days' => -1]], 'overbound' => [['version' => 1, 'kind' => 'fixed', 'days' => 36501]],
    'string days' => [['version' => 1, 'kind' => 'fixed', 'days' => '30']],
    'float days' => [['version' => 1, 'kind' => 'fixed', 'days' => 30.0]], 'bool days' => [['version' => 1, 'kind' => 'fixed', 'days' => true]],
]);

it('refuses JSON null malformed JSON and type-coercing stored offers', function (mixed $terms): void {
    expect(fn () => OfferTerms::stored($terms))->toThrow(RuntimeException::class);
})->with(['null JSON' => ['null'], 'list' => ['[]'], 'scalar' => ['1'], 'malformed' => ['{'],
    'float days' => ['{"version":1,"kind":"fixed","days":30.0}'], 'array' => [['version' => 1, 'kind' => 'permanent']],
    'oversize' => [str_repeat(' ', 513).'{"version":1,"kind":"permanent"}']]);

it('resolves UTC whole days across DST without using the recovery clock or extending permanent access', function (): void {
    $terms = ['version' => 1, 'kind' => 'fixed', 'days' => 1];
    $start = CarbonImmutable::parse('2026-03-07 12:34:56.987654', 'America/New_York');
    $end = OfferTerms::resolve($terms, $start);
    expect($end?->format('Y-m-d H:i:s.u e'))->toBe('2026-03-08 17:34:56.000000 UTC')
        ->and($end?->setTimezone('America/New_York')->format('H:i:s'))->toBe('13:34:56')
        ->and(OfferTerms::resolve(['version' => 1, 'kind' => 'permanent'], $start))->toBeNull()
        ->and(OfferTerms::resolve(['version' => 1, 'kind' => 'fixed', 'days' => 36500], CarbonImmutable::parse('2038-01-19 03:14:07', 'UTC'))?->year)->toBe(2137);
});

it('admits the last database instant and refuses terms whose resolved end exceeds it', function (): void {
    $terms = ['version' => 1, 'kind' => 'fixed', 'days' => 1];
    expect(OfferTerms::resolve($terms, CarbonImmutable::parse('9999-12-30 23:59:59', 'UTC'))?->format('Y-m-d H:i:s'))
        ->toBe('9999-12-31 23:59:59')
        ->and(fn () => OfferTerms::resolve($terms, CarbonImmutable::parse('9999-12-31 00:00:00', 'UTC')))->toThrow(RuntimeException::class);
});
