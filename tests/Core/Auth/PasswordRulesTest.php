<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Container\Container;
use Kitsune\Core\Auth\PasswordRefusal;
use Kitsune\Core\Auth\PasswordRules;

/*
 * How long a password must be — configurable upward and never below the floor, by any setting (Adam, 2026-10-09).
 * `kitsune.passwords.min_characters`, for readers and the first owner alike.
 */

it('asks for fifteen characters when nothing is configured', function (): void {
    config(['kitsune.passwords.min_characters' => null]);

    expect(PasswordRules::minCharacters())->toBe(15)
        ->and(PasswordRules::FLOOR_CHARACTERS)->toBe(15)
        ->and(PasswordRules::misconfiguration())->toBeNull()
        ->and(PasswordRules::refusal(str_repeat('a', 14), 'reader@kitsune.test'))->toBe(PasswordRefusal::Short)
        ->and(PasswordRules::refusal(str_repeat('a', 15), 'reader@kitsune.test'))->toBeNull();
});

it('ships asking for the floor', function (): void {
    expect((require __DIR__.'/../../../packages/core/config/kitsune.php')['passwords']['min_characters'])->toBe(15);
});

it('raises the minimum when asked, from a number or an environment variable\'s digits', function (mixed $configured, int $minimum): void {
    config(['kitsune.passwords.min_characters' => $configured]);

    expect(PasswordRules::minCharacters())->toBe($minimum)
        ->and(PasswordRules::misconfiguration())->toBeNull()
        ->and(PasswordRules::refusal(str_repeat('a', $minimum - 1), 'reader@kitsune.test'))->toBe(PasswordRefusal::Short)
        ->and(PasswordRules::refusal(str_repeat('a', $minimum), 'reader@kitsune.test'))->toBeNull();
})->with([
    'the floor itself' => [15, 15],
    'twenty' => [20, 20],
    'twenty, as the environment gives it' => ['20', 20],
    'the ceiling' => [64, 64],
]);

it('never lets a setting lower the minimum, and says so', function (mixed $configured): void {
    config(['kitsune.passwords.min_characters' => $configured]);

    expect(PasswordRules::minCharacters())->toBe(15)
        ->and(PasswordRules::misconfiguration())->not->toBeNull()
        ->and(PasswordRules::refusal(str_repeat('a', 14), 'reader@kitsune.test'))->toBe(PasswordRefusal::Short);
})->with([
    'eight' => [8],
    'zero' => [0],
    'below zero' => [-1],
    'eight, from the environment' => ['8'],
    'below zero, from the environment' => ['-20'],
    'a word' => ['twenty'],
    'padded digits' => [' 20'],
    'a fraction' => [20.5],
    'a whole number as a float' => [20.0],
    'true' => [true],
    'false' => [false],
    'a list' => [[20]],
    'empty' => [''],
]);

it('says what is wrong in words that name the setting and the floor', function (): void {
    config(['kitsune.passwords.min_characters' => 8]);
    expect(PasswordRules::misconfiguration())
        ->toBe('kitsune.passwords.min_characters asks for 8 characters, below the floor of 15, which applies instead — a password can be made stronger here, never weaker');

    config(['kitsune.passwords.min_characters' => 'twenty']);
    expect(PasswordRules::misconfiguration())
        ->toBe('kitsune.passwords.min_characters is not a whole number, so the floor of 15 characters applies');
});

it('caps the minimum at what bcrypt can hold', function (mixed $configured): void {
    config(['kitsune.passwords.min_characters' => $configured]);

    expect(PasswordRules::minCharacters())->toBe(64)
        ->and(PasswordRules::CEILING_CHARACTERS)->toBe(64)
        ->and(PasswordRules::CEILING_CHARACTERS)->toBeLessThanOrEqual(PasswordRules::MAX_BYTES)
        ->and(PasswordRules::misconfiguration())
        ->toBe("kitsune.passwords.min_characters asks for {$configured} characters, above the ceiling of 64, which applies instead — a longer password would not fit bcrypt's 72 bytes");
})->with([[65], ['999']]);

it('asks for the floor where no application is booted, as the first owner\'s child process is', function (): void {
    $container = Container::getInstance();

    try {
        Container::setInstance(new Container);

        expect(PasswordRules::minCharacters())->toBe(15)
            ->and(PasswordRules::misconfiguration())->toBeNull();
    } finally {
        Container::setInstance($container);
    }
});
