<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Entitlements\EntitlementCheck;
use Kitsune\Core\Entitlements\EntitlementWriter;
use Kitsune\Core\Entitlements\UtcInstant;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/**
 * The entitlement tests, set up one way — ADR-040.
 *
 * ⚠️ NOTHING IS DECLARED UNTIL A TEST DECLARES IT. `boot()` leaves `kitsune.readers.guard` as shipped — null — so a
 * test that needs a reader asks for one with `declareReaders()`, and every fail-closed test starts from absence.
 */
final class EntitlementFixture
{
    public const GUARD = 'test_readers';

    public static function boot(): void
    {
        config(['auth.providers.users.model' => TestUser::class]);
        Auth::provider(ScopeStrippingReaderProvider::DRIVER, static fn ($app, array $config): ScopeStrippingReaderProvider => new ScopeStrippingReaderProvider($app['hash'], $config['model']));
        self::forget();
    }

    public static function tearDown(): void
    {
        Auth::guard('web')->logout();
        // Not logged out through the reader guard, which a test may have left declared with a provider that cannot build.
        Auth::forgetGuards();
        app(Context::class)->forget();
        self::forget();
    }

    /** Drop the scoped doors, so the next resolution reads the configuration as it is now. */
    public static function forget(): void
    {
        foreach ([ReaderGuard::class, EntitlementCheck::class, EntitlementWriter::class] as $abstract) {
            app()->forgetInstance($abstract);
        }

        Permissions::forget();
    }

    /** An org with the context set to it. */
    public static function org(string $slug): Org
    {
        $org = Org::create(['slug' => $slug, 'name' => ucfirst($slug)]);
        app(Context::class)->setOrg($org);

        return $org;
    }

    /** A site of the org, with the context set to it. */
    public static function site(Org $org, string $handle, ?string $slug = null): Site
    {
        app(Context::class)->setOrg($org);
        $site = Site::create(['handle' => $handle, 'slug' => $slug ?? $org->slug.'-'.$handle, 'name' => ucfirst($handle), 'locale' => 'en']);
        app(Context::class)->setSite($site);

        return $site;
    }

    /**
     * Declare the reader guard — the host's auth configuration and `kitsune.readers.guard`, as a host writes them.
     *
     * @param  class-string  $model
     */
    public static function declareReaders(string $model = TestReader::class, string $provider = 'eloquent'): void
    {
        config([
            'auth.guards.'.self::GUARD => ['driver' => 'session', 'provider' => self::GUARD],
            'auth.providers.'.self::GUARD => ['driver' => $provider, 'model' => $model],
            ReaderGuard::CONFIG => self::GUARD,
        ]);

        // The guard is built once per manager; a test that re-declares it gets the new provider.
        Auth::forgetGuards();
        self::forget();
    }

    /** A reader of the org in context, or of the org given. */
    public static function reader(?Org $org = null, string $email = 'reader@example.test'): TestReader
    {
        return TestReader::withoutScopeBecause(
            'a fixture reader, filed under the org the test names',
            static fn ($query) => $query->create(['org_id' => ($org ?? app(Context::class)->org())?->getKey(), 'email' => $email]),
        );
    }

    /** Sign a reader in on the reader guard — a reader's own session, which rides along on any route. */
    public static function signIn(Authenticatable $reader): void
    {
        Auth::guard(self::GUARD)->setUser($reader);
        self::forget();
    }

    /** An owner of the org in context, signed in on `web`. */
    public static function owner(string $email = 'owner@kitsune.test'): TestUser
    {
        return CredentialFixture::member(owner: true, email: $email);
    }

    /** A member of the org in context without the owner role, signed in on `web`. */
    public static function member(string $email = 'member@kitsune.test'): TestUser
    {
        return CredentialFixture::member(owner: false, email: $email);
    }

    /** Nobody signed in on `web`: the system acting, as a webhook, an import or the console does. */
    public static function nobody(): void
    {
        Auth::guard('web')->logout();
        self::forget();
    }

    /**
     * A row written below Eloquent, as nothing in core can write one — for the fail-closed tests' planted rows.
     *
     * @return int the row's id
     */
    public static function plant(
        Site $site,
        string $readerId,
        string $entitlement,
        string $source = 'test.order:1',
        ?DateTimeInterface $expires = null,
        ?DateTimeInterface $revoked = null,
        ?int $orgId = null,
    ): int {
        return (int) DB::table('entitlements')->insertGetId([
            'org_id' => $orgId ?? $site->org_id,
            'site_id' => $site->getKey(),
            'reader_id' => $readerId,
            'entitlement' => $entitlement,
            'source' => $source,
            'expires_at' => $expires !== null ? UtcInstant::stored($expires) : null,
            'revoked_at' => $revoked !== null ? UtcInstant::stored($revoked) : null,
            'changed_at' => UtcInstant::stored(CarbonImmutable::now()),
        ]);
    }

    public static function writer(): EntitlementWriter
    {
        return app(EntitlementWriter::class);
    }

    public static function check(): EntitlementCheck
    {
        return app(EntitlementCheck::class);
    }

    public static function guard(): ReaderGuard
    {
        return app(ReaderGuard::class);
    }
}
