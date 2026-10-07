<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use App\Models\Reader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/**
 * Two organisations and four sites, with the skeleton's readers in them — ADR-037's boundaries, from both sides.
 *
 * | Org | Site | `base_url` |
 * |---|---|---|
 * | golfdom | golfdom | `/golfdom` (host-less) |
 * | golfdom | golfdom-fr | `/golfdom-fr` (host-less, locale `fr`) |
 * | rival | rival | `/rival` (host-less) |
 * | rival | shop | `https://rival.test/shop` |
 *
 * ⚠️ NO MODE IS SET HERE. Reader accounts are `off` by default, and every fail-closed test starts from that; a test
 * that signs in says which mode it needs with `mode()`.
 *
 * ⚠️ A READER IS SIGNED IN THROUGH `login()`, NEVER `actingAs()` OR `setUser()`: the session binding refuses a reader
 * nobody signed in (`ReaderSessions`).
 */
final class ReaderFixture
{
    public const PASSWORD = 'correct-horse-battery-staple';

    public const GUARD = 'readers';

    /** @return array{golfdom: Org, rival: Org, sites: array<string, Site>} */
    public static function world(): array
    {
        $golfdom = Org::create(['slug' => 'golfdom', 'name' => 'Golfdom Media']);
        $rival = Org::create(['slug' => 'rival', 'name' => 'Rival']);

        $sites = [
            'golfdom' => self::site($golfdom, 'golfdom', 'Golfdom', '/golfdom'),
            'golfdom-fr' => self::site($golfdom, 'golfdom-fr', 'Golfdom FR', '/golfdom-fr', 'fr'),
            'rival' => self::site($rival, 'rival', 'Rival', '/rival'),
            'shop' => self::site($rival, 'shop', 'Rival Shop', 'https://rival.test/shop'),
        ];

        self::forget();

        return ['golfdom' => $golfdom, 'rival' => $rival, 'sites' => $sites];
    }

    public static function site(Org $org, string $handle, string $name, ?string $baseUrl, string $locale = 'en'): Site
    {
        app(Context::class)->forget()->setOrg($org);

        return Site::create([
            'handle' => $handle,
            'slug' => $handle,
            'name' => $name,
            'locale' => $locale,
            'base_url' => $baseUrl,
        ]);
    }

    /** The `reader_accounts` mode at an org or a site, stored as an operator's write would store it. */
    public static function mode(Org|Site $scope, string $mode): void
    {
        $settings = $scope->settings ?? [];
        $settings['reader_accounts'] = $mode;
        $scope->settings = $settings;
        $scope->save();
        self::forget();
    }

    /** A reader of this org, through the model's own contract, with a password or none. */
    public static function reader(Org $org, string $email, ?string $password = self::PASSWORD): Reader
    {
        app(Context::class)->forget()->setOrg($org);

        try {
            return Reader::createReader($email, $password !== null ? Hash::make($password) : null, CarbonImmutable::now());
        } finally {
            self::forget();
        }
    }

    /** Sign a reader in through the reader guard's own `login()`, under their org, as a sign-in does. */
    public static function signIn(Reader $reader): void
    {
        app(Context::class)->forget()->setOrg(Org::query()->findOrFail($reader->org_id));

        try {
            Auth::guard(self::GUARD)->login($reader);
        } finally {
            self::forget();
        }
    }

    /** The session's CSRF token, started if need be — what a form's hidden field carries. */
    public static function token(): string
    {
        $session = app('session.store');

        if (! $session->has('_token')) {
            $session->regenerateToken();
        }

        return (string) $session->token();
    }

    /**
     * What a new request starts from: no org or site in context, no guard built, no permission memo — each request's
     * middleware sets them again.
     */
    public static function forget(): void
    {
        app(Context::class)->forget();
        Auth::forgetGuards();
        Auth::shouldUse('web');
        app()->forgetInstance(ReaderGuard::class);
        Permissions::forget();
    }
}
