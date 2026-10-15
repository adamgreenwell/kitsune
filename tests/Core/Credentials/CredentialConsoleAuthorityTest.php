<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Credentials\CredentialConsoleAuthority;
use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as Fx;

beforeEach(function (): void {
    Fx::boot();
    $this->org = Fx::org('console-authority');
});

afterEach(fn () => Fx::tearDown());

it('spends one exact authority and keeps it active only inside the operation', function (string $action, ?string $slot, ?CredentialMode $mode, ?int $id): void {
    $context = app(Context::class);
    $kept = CredentialConsoleAuthority::run($context, $action, $slot, $mode, $id,
        function (CredentialConsoleAuthority $authority) use ($context, $action, $slot, $mode, $id): CredentialConsoleAuthority {
            expect(fn () => $authority->assertActiveFor($context))->toThrow(LogicException::class);
            $authority->consume($context, $action, $slot, $mode, $id);
            $authority->assertActiveFor($context);

            return $authority;
        });

    expect(fn () => $kept->assertActiveFor($context))->toThrow(LogicException::class)
        ->and(fn () => $kept->consume($context, $action, $slot, $mode, $id))->toThrow(LogicException::class);
})->with([
    'neutral set' => ['set', Fx::SHARED, null, null],
    'live set' => ['set', Fx::PAYMENT, CredentialMode::Live, null],
    'test remove' => ['remove', Fx::PAYMENT, CredentialMode::Test, null],
    'switch' => ['switch', null, CredentialMode::Live, null],
    'rotation' => ['reencrypt', null, null, 27],
]);

it('refuses a second use even before the command operation ends', function (): void {
    $context = app(Context::class);
    CredentialConsoleAuthority::run($context, 'set', Fx::SHARED, null, null,
        function (CredentialConsoleAuthority $authority) use ($context): void {
            $authority->consume($context, 'set', Fx::SHARED);
            expect(fn () => $authority->consume($context, 'set', Fx::SHARED))->toThrow(LogicException::class)
                ->and(fn () => $authority->assertActiveFor($context))->toThrow(LogicException::class);
        });
});

it('refuses every different action slot mode and row target', function (string $action, ?string $slot, ?CredentialMode $mode, ?int $id): void {
    $context = app(Context::class);
    CredentialConsoleAuthority::run($context, 'set', Fx::PAYMENT, CredentialMode::Test, null,
        function (CredentialConsoleAuthority $authority) use ($context, $action, $slot, $mode, $id): void {
            expect(fn () => $authority->consume($context, $action, $slot, $mode, $id))->toThrow(LogicException::class)
                ->and(fn () => $authority->consume($context, 'set', Fx::PAYMENT, CredentialMode::Test))->toThrow(LogicException::class);
        });
})->with([
    'action' => ['remove', Fx::PAYMENT, CredentialMode::Test, null],
    'slot' => ['set', Fx::HOOK, CredentialMode::Test, null],
    'mode' => ['set', Fx::PAYMENT, CredentialMode::Live, null],
    'missing mode' => ['set', Fx::PAYMENT, null, null],
    'row' => ['set', Fx::PAYMENT, CredentialMode::Test, 2],
]);

it('binds the exact rotation row', function (): void {
    $context = app(Context::class);
    CredentialConsoleAuthority::run($context, 'reencrypt', null, null, 11,
        fn (CredentialConsoleAuthority $authority) => expect(fn () => $authority->consume($context, 'reencrypt', null, null, 12))->toThrow(LogicException::class));
});

it('refuses another org both at entry and after consumption', function (bool $consumed): void {
    $context = app(Context::class);
    $other = Fx::org('other-console-authority');
    $context->setOrg($this->org);
    CredentialConsoleAuthority::run($context, 'set', Fx::SHARED, null, null,
        function (CredentialConsoleAuthority $authority) use ($context, $other, $consumed): void {
            if ($consumed) {
                $authority->consume($context, 'set', Fx::SHARED);
            }
            $context->setOrg($other);
            expect(fn () => $consumed ? $authority->assertActiveFor($context) : $authority->consume($context, 'set', Fx::SHARED))
                ->toThrow(LogicException::class);
        });
})->with([false, true]);

it('expires an authority when the callback throws and cannot be copied or serialized', function (): void {
    $context = app(Context::class);
    $kept = null;
    expect(function () use ($context, &$kept): void {
        CredentialConsoleAuthority::run($context, 'switch', null, CredentialMode::Test, null,
            function (CredentialConsoleAuthority $authority) use (&$kept, $context): void {
                $kept = $authority;
                $authority->consume($context, 'switch', null, CredentialMode::Test);
                expect(fn () => clone $authority)->toThrow(LogicException::class)
                    ->and(fn () => serialize($authority))->toThrow(LogicException::class);
                throw new RuntimeException('fixture stop');
            });
    })->toThrow(RuntimeException::class, 'fixture stop');
    expect(fn () => $kept->assertActiveFor($context))->toThrow(LogicException::class);
});

it('refuses missing context and malformed factory targets', function (string $action, ?string $slot, ?CredentialMode $mode, ?int $id): void {
    $context = app(Context::class);
    expect(fn () => CredentialConsoleAuthority::run($context, $action, $slot, $mode, $id, fn () => null))->toThrow(LogicException::class);
    $context->forget();
    expect(fn () => CredentialConsoleAuthority::run($context, 'set', Fx::SHARED, null, null, fn () => null))->toThrow(LogicException::class);
})->with([
    ['other', null, null, null], ['set', 'not a name', null, null], ['remove', null, null, null],
    ['switch', null, null, null], ['switch', Fx::SHARED, CredentialMode::Live, null],
    ['reencrypt', null, null, 0], ['reencrypt', Fx::SHARED, null, 1], ['reencrypt', null, CredentialMode::Test, 1],
]);
