<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Credentials\CredentialRefusal;
use Kitsune\Core\Credentials\CredentialRefused;
use Kitsune\Core\Credentials\CredentialSlot;
use Kitsune\Core\Credentials\CredentialSlots;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as Fx;

/*
 * What a module may declare — ADR-040's slots, refused at construction in the developer's own terms.
 */

beforeEach(function (): void {
    Fx::boot();
    Fx::org('acme');
    Fx::member();
});

afterEach(fn () => Fx::tearDown());

it('refuses a declaration that could not be kept honestly', function (array $declared, string $why): void {
    $declare = fn () => new CredentialSlot(...[
        'name' => 'shop.api-key',
        'label' => 'API key',
        'help' => 'Where the provider shows it.',
        'moded' => true,
        'prefixes' => [],
        ...$declared,
    ]);

    expect($declare)->toThrow(new LogicException(sprintf('Refusing the credential slot [%s]: %s', $declared['name'] ?? 'shop.api-key', $why)));
})->with([
    'one word' => [['name' => 'apikey'], 'a slot\'s name is two lower-case words joined by a dot, the first naming the module that declares it — for example commerce.stripe-secret-key.'],
    'upper case' => [['name' => 'Shop.ApiKey'], 'a slot\'s name is two lower-case words joined by a dot, the first naming the module that declares it — for example commerce.stripe-secret-key.'],
    'too long' => [['name' => 'shop.'.str_repeat('a', 96)], 'a slot\'s name is two lower-case words joined by a dot, the first naming the module that declares it — for example commerce.stripe-secret-key.'],
    'core\'s own' => [['name' => 'core.api-key'], 'the core. prefix is reserved for Kitsune itself.'],
    'no label' => [['label' => ' '], 'it needs a label and help text, because an owner pastes a key into it by name.'],
    'no help' => [['help' => ''], 'it needs a label and help text, because an owner pastes a key into it by name.'],
    'one mode only' => [['prefixes' => ['test' => ['ab_test_']]], 'a slot kept for test and live mode names prefixes for both modes or for neither.'],
    'none on a moded one' => [['prefixes' => ['none' => ['ab_']]], 'a slot kept for test and live mode names prefixes for both modes or for neither.'],
    'a mode on one value' => [['moded' => false, 'prefixes' => ['test' => ['ab_']]], 'a slot with one value names its prefixes under \'none\'.'],
    'a bad prefix' => [['prefixes' => ['test' => ['ab test'], 'live' => ['ab_live_']]], 'a prefix is 1 to 32 letters, digits, hyphens or underscores.'],
    'an empty list' => [['prefixes' => ['test' => [], 'live' => ['ab_live_']]], 'a prefix is 1 to 32 letters, digits, hyphens or underscores.'],
    'overlapping prefixes' => [['prefixes' => ['test' => ['ab_'], 'live' => ['ab_live_']]], 'its test and live prefixes overlap, so one key could match both modes.'],
    'too short a minimum' => [['minLength' => 15], 'lengths must satisfy 16 ≤ minimum ≤ maximum ≤ 1024, and the minimum must exceed every prefix.'],
    'a maximum below the minimum' => [['minLength' => 40, 'maxLength' => 39], 'lengths must satisfy 16 ≤ minimum ≤ maximum ≤ 1024, and the minimum must exceed every prefix.'],
    'too long a maximum' => [['maxLength' => 1025], 'lengths must satisfy 16 ≤ minimum ≤ maximum ≤ 1024, and the minimum must exceed every prefix.'],
    'a minimum no longer than a prefix' => [['prefixes' => ['test' => ['abcdefghijklmnop'], 'live' => ['qrstuvwxyzabcdef']]], 'lengths must satisfy 16 ≤ minimum ≤ maximum ≤ 1024, and the minimum must exceed every prefix.'],
]);

it('allows one prefix shared by both modes, which then decides nothing', function (): void {
    $slot = new CredentialSlot('shop.hook-secret', 'Hook', 'Help', true, ['test' => ['whk_'], 'live' => ['whk_']]);

    expect($slot->refusalFor(CredentialMode::Live, 'whk_'.str_repeat('a', 20)))->toBeNull()
        ->and($slot->otherModePrefixOf(CredentialMode::Live, 'whk_'.str_repeat('a', 20)))->toBeNull();
});

it('refuses a second declaration of one name, and keeps the order they came in', function (): void {
    $slots = new CredentialSlots;
    $slots->register(new CredentialSlot('shop.b-key', 'B', 'Help', false))
        ->register(new CredentialSlot('shop.a-key', 'A', 'Help', true));

    expect(fn () => $slots->register(new CredentialSlot('shop.b-key', 'B again', 'Help', false)))
        ->toThrow(new LogicException('Refusing the credential slot [shop.b-key]: it is already registered, and two declarations of one slot would disagree about what it holds.'))
        ->and(array_map(fn (CredentialSlot $slot): string => $slot->name, $slots->all()))->toBe(['shop.b-key', 'shop.a-key'])
        ->and($slots->hasModed())->toBeTrue()
        ->and((new CredentialSlots)->register(new CredentialSlot('shop.c-key', 'C', 'Help', false))->hasModed())->toBeFalse();
});

it('does not repeat a value passed where a name belongs', function (): void {
    $value = Fx::value('fx_live_');

    try {
        Fx::writer()->set($value, null, 'whatever');
        $this->fail('a value in the name\'s place was taken as a name');
    } catch (CredentialRefused $refused) {
        expect($refused->reason)->toBe(CredentialRefusal::NotAName)
            ->and(str_contains($refused->getMessage(), $value))->toBeFalse()
            ->and(str_contains($refused->getMessage(), substr($value, 0, 12)))->toBeFalse()
            ->and($refused->slot)->toBeNull();
    }
});
