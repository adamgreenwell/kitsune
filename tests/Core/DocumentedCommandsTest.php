<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

/*
 * The commands the README tells a newcomer to run have to exist.
 *
 * ⚠️ THIS FILE EXISTS BECAUSE THERE WAS NO WAY IN AT ALL. Until this commit the repository documented its
 * architecture, its decisions, its licence, its governance and its roadmap — and nowhere said how to run it.
 * A clone gets you a skeleton whose `artisan` dies on a missing autoloader, because `kitsune/core` is not on
 * Packagist yet (#8) and the skeleton resolves it through a path repository that `composer skeleton:install`
 * writes and then reverts. Somebody who does not already know that cannot start.
 *
 * ⚠️ AND A DOCUMENTED COMMAND IS A PROMISE, which is the same rule `PromisedDocumentsTest` enforces about
 * files. `skeleton:install` is a composer script, so renaming it would leave the README telling people to run
 * something that does not exist — silently, because prose is not executed. This asserts the two halves that
 * can drift: the scripts the README names exist, and the file the install script reverts is still committed
 * in the state that makes the instruction true.
 */
it('names only composer scripts that exist', function (): void {
    $root = dirname(__DIR__, 2);
    $readme = (string) file_get_contents($root.'/README.md');

    /** @var array<string, mixed> $composer */
    $composer = json_decode((string) file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);

    /** @var array<string, mixed> $scripts */
    $scripts = $composer['scripts'] ?? [];

    // ⚠️ Not vacuous: the README has to actually contain the instruction this is about.
    expect($readme)->toContain('composer skeleton:install');

    preg_match_all('/^composer ([a-z][a-z0-9:_-]*)/m', $readme, $matches);

    $named = array_values(array_unique($matches[1]));

    expect($named)->not->toBeEmpty();

    $missing = array_values(array_filter(
        $named,
        static fn (string $script): bool => $script !== 'install' && ! array_key_exists($script, $scripts),
    ));

    expect($missing)->toBe([], 'the README names composer scripts that do not exist: '.implode(', ', $missing));
});

it('names only sign-in accounts the seeder creates', function (): void {
    /*
     * ⚠️ THIS IS THE ERROR THAT GOT THROUGH THE FIRST VERSION OF THIS FILE. The README was written while a
     * feature branch was checked out, so it advertised a copy-editor account the seeder on this branch does
     * not create — an onboarding instruction that fails at the one step a newcomer cannot debug. The test
     * above checks COMMANDS; a credential is the same kind of promise and was not checked at all.
     */
    $root = dirname(__DIR__, 2);
    $readme = (string) file_get_contents($root.'/README.md');
    $seeder = (string) file_get_contents($root.'/skeleton/database/seeders/DatabaseSeeder.php');

    preg_match_all('/[a-z0-9._-]+@kitsune\.test/i', $readme, $matches);

    $documented = array_values(array_unique($matches[0]));

    // ⚠️ Not vacuous: the README has to name at least one account for this to be about anything.
    expect($documented)->not->toBeEmpty();

    $unseeded = array_values(array_filter(
        $documented,
        static fn (string $address): bool => ! str_contains($seeder, $address),
    ));

    expect($unseeded)->toBe([], 'the README names accounts the seeder does not create: '.implode(', ', $unseeded));
});

it('keeps the skeleton resolvable the way the README says it is', function (): void {
    $root = dirname(__DIR__, 2);

    /** @var array<string, mixed> $skeleton */
    $skeleton = json_decode((string) file_get_contents($root.'/skeleton/composer.json'), true, 512, JSON_THROW_ON_ERROR);

    /*
     * ⚠️ THE COMMITTED FILE MUST NOT CARRY THE PATH REPOSITORY, which is the whole reason the install is a
     * script rather than a plain `composer install -d skeleton`. `skeleton/composer.json` is what a real
     * installation will look like once #8 lands: it requires `kitsune/core` from Packagist and nothing else.
     * If a `repositories` block ever gets committed here, the README's explanation becomes false and the
     * skeleton stops being an honest example of what a host application installs.
     */
    expect($skeleton)->not->toHaveKey('repositories');
    expect($skeleton['require'] ?? [])->toHaveKey('kitsune/core');
});
