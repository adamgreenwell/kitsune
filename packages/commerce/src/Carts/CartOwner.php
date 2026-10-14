<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Carts;

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Readers\ReaderSessions;
use Kitsune\Core\Tenancy\Context;

/** Ownership comes from the declared reader or an encrypted browser secret, never a submitted cart ID. @internal */
final readonly class CartOwner
{
    private const SEED = ReaderSessions::EPHEMERAL.'.cart_seed';

    public function __construct(
        public int $orgId,
        public int $siteId,
        public string $key,
        public ?string $reader,
    ) {}

    public static function readerKey(#[\SensitiveParameter] string $reader): string
    {
        return hash('sha256', "reader\0".$reader);
    }

    public static function anonymousKey(#[\SensitiveParameter] string $secret): string
    {
        return hash('sha256', "anonymous\0".$secret);
    }

    public static function sessionKey(int $orgId, int $siteId): string
    {
        return ReaderSessions::EPHEMERAL.'.carts.'.$orgId.'.'.$siteId;
    }

    public static function current(Request $request, bool $create = false): ?self
    {
        $context = app(Context::class);
        $org = $context->orgId();
        $site = $context->siteId();

        if ($org === null || $site === null || ! $request->hasSession()) {
            throw new CartRefused('unavailable');
        }

        $reader = app(ReaderGuard::class)->current();

        if ($reader !== null) {
            return new self($org, $site, self::readerKey($reader), $reader);
        }

        self::restore($request, $request->session());

        return self::anonymous($request->session(), $org, $site, $create);
    }

    /** Restore before Login adoption: Laravel has already rotated the session ID when Login fires. */
    public static function restore(Request $request, Session $session): void
    {
        $queued = Cookie::queued(ReaderSessions::EPHEMERAL);

        // A logout can precede another ownership lookup in the same request. Its queued expiry must beat the old
        // incoming cookie, including when a host uses plain SessionGuard::logout() without changing the session ID.
        if ($queued !== null && $queued->getExpiresTime() <= time()) {
            return;
        }

        if (self::valid($session->get(self::SEED))) {
            return;
        }

        $seed = $request->cookie(ReaderSessions::EPHEMERAL);

        if (self::valid($seed)) {
            $session->put(self::SEED, $seed);
        }
    }

    public static function anonymous(Session $session, int $org, int $site, bool $create = false): ?self
    {
        $slot = self::sessionKey($org, $site);
        $secret = $session->get($slot);
        $seed = $session->get(self::SEED);

        if (! self::valid($seed)) {
            if (! $create && ! self::valid($secret)) {
                return null;
            }

            $key = config('app.key');

            if (! is_string($key) || $key === '') {
                throw new CartRefused('unavailable');
            }

            $generation = $session->get(ReaderSessions::LOGOUT_GENERATION);
            $sessionId = $session->getId();

            if (($generation !== null && ! self::valid($generation)) || preg_match('/\A[a-zA-Z0-9]{40}\z/D', $sessionId) !== 1) {
                throw new CartRefused('unavailable');
            }
            // Session-only random handles lost a confirmed 30-day cart at Laravel's ordinary 120-minute idle limit.
            // A secret-derived initial seed also makes simultaneous first Adds on one established CSRF session agree
            // before either reaches the cart's database lock. Login rotation does not alter a restored browser seed.
            $seed = hash_hmac('sha256', "kitsune.cart.browser\0".$sessionId."\0".($generation ?? ''), $key);
            $session->put(self::SEED, $seed);
        }

        if (! self::valid($secret)) {
            $secret = hash_hmac('sha256', "kitsune.cart.site\0".$org."\0".$site, $seed);
            $session->put($slot, $secret);
        }

        $request = app('request');
        Cookie::queue(ReaderSessions::EPHEMERAL, $seed, CartWriter::DAYS * 24 * 60,
            (string) config('session.path', '/'), config('session.domain'),
            config('session.secure') === true || $request->isSecure(), true, false, 'lax');

        return new self($org, $site, self::anonymousKey($secret), null);
    }

    /** @phpstan-assert-if-true string $value */
    private static function valid(#[\SensitiveParameter] mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }
}
