<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Localization\CountMessage;

it('selects integer cardinal categories without an intl runtime dependency', function (string $locale, int $count, string $expected): void {
    $lines = [
        'en' => 'one|other',
        'ar' => 'zero|one|two|few|many|other',
        'pl' => 'one|few|many',
        'ru' => 'one|few|many',
    ];
    app('translator')->addLines(['counts.items' => $lines[$locale]], $locale, 'kitsune');

    expect(CountMessage::format('kitsune::counts.items', $count, locale: $locale))->toBe($expected);
})->with([
    ['en', 0, 'other'], ['en', 1, 'one'], ['en', 2, 'other'],
    ['ar', 0, 'zero'], ['ar', 1, 'one'], ['ar', 2, 'two'],
    ['ar', 3, 'few'], ['ar', 10, 'few'], ['ar', 11, 'many'],
    ['ar', 99, 'many'], ['ar', 100, 'other'], ['ar', 102, 'other'],
    ['ar', 103, 'few'], ['ar', 111, 'many'],
    ['pl', 0, 'many'], ['pl', 1, 'one'], ['pl', 2, 'few'],
    ['pl', 5, 'many'], ['pl', 12, 'many'], ['pl', 21, 'many'],
    ['pl', 22, 'few'], ['pl', 25, 'many'], ['pl', 102, 'few'],
    ['ru', 0, 'many'], ['ru', 1, 'one'], ['ru', 2, 'few'],
    ['ru', 5, 'many'], ['ru', 11, 'many'], ['ru', 21, 'one'],
    ['ru', 22, 'few'], ['ru', 25, 'many'], ['ru', 101, 'one'],
]);

it('refuses fractional or coercible counts instead of selecting a misleading cardinal form', function (mixed $count): void {
    expect(fn () => CountMessage::format('kitsune::readers.example', $count, locale: 'pl'))
        ->toThrow(InvalidArgumentException::class);
})->with(array_map(static fn (mixed $count): array => [$count], [1.5, 1.0, '1', true, false, null, [], -1, INF, NAN]));

it('keeps exact intervals and placeholders in the translated whole message', function (): void {
    app('translator')->addLines([
        'counts.items' => '{0} Nothing|{1} :count item for :name|[2,*] :count items for :name',
    ], 'en', 'kitsune');

    expect(CountMessage::format('kitsune::counts.items', 0, locale: 'en'))->toBe('Nothing')
        ->and(CountMessage::format('kitsune::counts.items', 1, ['name' => 'Ada', 'count' => 9], 'en'))->toBe('1 item for Ada')
        ->and(CountMessage::format('kitsune::counts.items', 7, ['name' => 'Ada'], 'en'))->toBe('7 items for Ada');
});
