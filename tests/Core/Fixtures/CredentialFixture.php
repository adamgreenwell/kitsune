<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Credentials\CredentialCipher;
use Kitsune\Core\Credentials\CredentialReader;
use Kitsune\Core\Credentials\CredentialSlot;
use Kitsune\Core\Credentials\CredentialSlots;
use Kitsune\Core\Credentials\CredentialStates;
use Kitsune\Core\Credentials\CredentialWriter;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Tenancy\Context;

/**
 * The credential store's tests, set up one way — ADR-040.
 *
 * ⚠️ INVENTED PREFIXES AND VALUES MADE AT RUNTIME, so nothing committed here matches a real provider's key pattern and
 * a secret scanner has nothing to find. `fx_test_`/`fx_live_` stand in for a provider whose prefixes decide the mode,
 * `fxhook_` for one whose prefix is the same in both, and the shared secret for one that declares none.
 *
 * ⚠️ AN APP KEY OF ITS OWN FOR EVERY TEST: Testbench has none, and the store refuses to seal without one.
 */
final class CredentialFixture
{
    public const PAYMENT = 'fx.payment-key';

    public const HOOK = 'fx.webhook-secret';

    public const SHARED = 'fx.shared-secret';

    public static function boot(): void
    {
        config(['auth.providers.users.model' => TestUser::class]);
        config(['app.key' => self::appKey(), 'app.previous_keys' => []]);
        self::forget();

        app(CredentialSlots::class)->flush();
        app(CredentialSlots::class)
            ->register(new CredentialSlot(
                name: self::PAYMENT,
                label: 'Fixture payment key',
                help: 'Where the fixture provider shows it.',
                moded: true,
                prefixes: ['test' => ['fx_test_'], 'live' => ['fx_live_']],
                minLength: 32,
            ))
            ->register(new CredentialSlot(
                name: self::HOOK,
                label: 'Fixture webhook secret',
                help: 'Where the fixture provider shows it.',
                moded: true,
                prefixes: ['test' => ['fxhook_'], 'live' => ['fxhook_']],
                minLength: 24,
            ))
            ->register(new CredentialSlot(
                name: self::SHARED,
                label: 'Fixture shared secret',
                help: 'Where the fixture service shows it.',
                moded: false,
                minLength: 32,
            ));
    }

    public static function tearDown(): void
    {
        Auth::guard('web')->logout();
        app(Context::class)->forget();
        app(CredentialSlots::class)->flush();
        self::forget();
    }

    /** Drop the scoped store, so the next resolution reads the app key as it is now. */
    public static function forget(): void
    {
        foreach ([CredentialCipher::class, CredentialWriter::class, CredentialStates::class, CredentialReader::class] as $abstract) {
            app()->forgetInstance($abstract);
        }
    }

    public static function appKey(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }

    /** A value that fits a slot: the prefix, then random letters and digits — never one written into the repository. */
    public static function value(string $prefix = 'fx_test_', int $length = 48): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $tail = '';

        for ($i = strlen($prefix); $i < $length; $i++) {
            $tail .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $prefix.$tail;
    }

    /** An org with the context set to it. */
    public static function org(string $slug): Org
    {
        $org = Org::create(['slug' => $slug, 'name' => ucfirst($slug)]);
        app(Context::class)->setOrg($org);

        return $org;
    }

    /** A member of the org in context, signed in, holding the owner role when asked. */
    public static function member(bool $owner = true, string $email = 'owner@kitsune.test'): TestUser
    {
        /** @var TestUser $user */
        $user = TestUser::create(['email' => $email]);
        DB::table('org_user')->insert(['org_id' => app(Context::class)->orgId(), 'user_id' => $user->getKey()]);

        if ($owner) {
            $role = Role::query()->where('is_owner', true)->first()
                ?? Role::create(['handle' => 'owner', 'name' => 'Owner', 'is_owner' => true]);
            $role->assignTo($user->getKey());
        }

        Permissions::forget();
        Auth::guard('web')->setUser($user);

        return $user;
    }

    public static function writer(): CredentialWriter
    {
        return app(CredentialWriter::class);
    }

    public static function reader(): CredentialReader
    {
        return app(CredentialReader::class);
    }

    public static function states(): CredentialStates
    {
        return app(CredentialStates::class);
    }
}
