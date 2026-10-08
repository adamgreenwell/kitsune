<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Readers\ReaderAccounts;
use Kitsune\Core\Readers\ReaderMode;
use Kitsune\Core\Settings\SettingsGuard;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;

/*
 * The setting `reader_accounts` (ADR-022, ADR-037 as built): `off` by default, and nothing else stored.
 */

beforeEach(function (): void {
    $this->world = ReaderFixture::world();
});

it('ships off', function (): void {
    expect(config('kitsune.settings.reader_accounts'))->toBe('off')
        ->and(app(ReaderAccounts::class)->mode($this->world['sites']['golfdom']))->toBe(ReaderMode::Off);
});

it('stores each of its three modes, and inherits them from the org', function (string $mode): void {
    ReaderFixture::mode($this->world['golfdom'], $mode);

    expect(app(ReaderAccounts::class)->mode($this->world['sites']['golfdom']))->toBe(ReaderMode::from($mode));
})->with(['off', 'sign-in', 'open']);

it('refuses to store anything else, in words', function (mixed $value, string $named): void {
    $site = $this->world['sites']['golfdom'];

    expect(fn () => $site->update(['settings' => ['reader_accounts' => $value]]))
        ->toThrow(RuntimeException::class, "Refusing [reader_accounts] on Site {$site->getKey()}: it is off, sign-in or open, and {$named} is none of them. To inherit instead, revert the key rather than storing an empty one.");
})->with([
    'another word' => ['on', '"on"'],
    'upper case' => ['OPEN', '"OPEN"'],
    'empty' => ['', '""'],
    'true' => [true, 'bool'],
    'null' => [null, 'null'],
    'a list' => [['open'], 'array'],
]);

it('refuses a host default that is none of them, when the resolver is first built', function (): void {
    expect(fn () => SettingsGuard::check(['reader_accounts' => 'yes'], 'the configured defaults (config/kitsune.php)'))
        ->toThrow(RuntimeException::class, 'Refusing [reader_accounts] on the configured defaults (config/kitsune.php): it is off, sign-in or open, and "yes" is none of them.');
});

it('reads a host settings map that dropped the key as off', function (): void {
    config(['kitsune.settings' => ['timezone' => 'UTC']]);
    app()->forgetScopedInstances();

    expect(app(ReaderAccounts::class)->mode($this->world['sites']['golfdom']))->toBe(ReaderMode::Off);
});
