<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Readers;

use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Http\Controllers\Readers\LinkUseController;
use Kitsune\Core\Readers\Contracts\ReaderAccount;
use Kitsune\Core\Tenancy\Concerns\ReadsWrittenKeys;
use Kitsune\Core\Tenancy\Context;

/**
 * A reader's session — ADR-037, as built. The guard is Laravel's own `session` driver; core adds a binding, and no
 * guard driver of its own (no driver name to promise).
 *
 * ⚠️ THE BINDING. At `Login`, the session gets an HMAC of the reader's password hash and remember token; whenever the
 * guard loads the reader (`Authenticated`), a missing or different value signs that session out. So a password changed
 * anywhere ends every other session of that reader, `ReaderGuard::current()` and every entitlement check included.
 * `login()` fires `Login` after it regenerates the session and before `setUser()`, so the binding is in place before
 * the check runs. Probed on Laravel 13.30.1; 13.34's own `password_hash_<guard>` line is not relied on.
 *
 * ⚠️ ONLY A MODEL WITH THE CONTRACT. A host with its own login, and a test's `setUser()` reader without
 * `ReaderAccount`, are untouched. A test that signs a contract reader in uses `login()`, never `actingAs()`.
 * Laravel's `AuthenticateSession` is not used: it flushes the whole session, staff included.
 *
 * ⚠️ SIGN-OUT NEVER INVALIDATES AND NEVER REGENERATES THE TOKEN. One cookie carries a staff session too; Filament's
 * logout calls `invalidate()` and ends both, and this must not repay the favour.
 *
 * ⚠️ A RESET CYCLES `remember_token` AS WELL AS THE PASSWORD (`ReaderLinkUse::reset()`), so when remember-me lands a
 * recaller sign-in — which fires `Login` and so re-writes the binding — cannot outlive one. A future password change
 * must do the same.
 *
 * ⚠️ A NAMED RESIDUAL: a rehash at sign-in — after the operator changes the hashing cost or driver — changes the hash,
 * and so ends that reader's other sessions as a password change would.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class ReaderSessions
{
    use ReadsWrittenKeys;

    /** The session key the binding lives under. */
    public const BINDING = 'kitsune_reader_binding';

    /** The cache key's prefix for the timing hash; the rest is a digest of the hashing configuration. */
    public const TIMING_HASH = 'kitsune:readers:timing-hash:';

    public function __construct(
        private readonly ReaderAccounts $accounts,
        private readonly Context $context,
    ) {}

    /** The two listeners, registered once by core's provider. */
    public static function listen(Dispatcher $events): void
    {
        $events->listen(Login::class, [self::class, 'bind']);
        $events->listen(Authenticated::class, [self::class, 'check']);
    }

    /** At sign-in, on the reader guard and a model with the contract: the binding goes into the session. */
    public static function bind(Login $event): void
    {
        $guard = self::guardFor($event->guard, $event->user);

        $guard?->getSession()->put(self::BINDING, self::binding($event->user));
    }

    /** Whenever the reader guard loads a reader: a missing or different binding signs this session out. */
    public static function check(Authenticated $event): void
    {
        $guard = self::guardFor($event->guard, $event->user);

        if ($guard === null) {
            return;
        }

        $session = $guard->getSession();
        $held = $session->get(self::BINDING);

        if (! is_string($held) || ! hash_equals(self::binding($event->user), $held)) {
            $guard->logoutCurrentDevice();
            $session->forget(self::BINDING);
        }
    }

    /** The value a session of this reader must hold: secret-derived, so a session file says nothing about the hash. */
    public static function binding(Authenticatable $user): string
    {
        // Either may be null on a real row, whatever the contract's docblock says; null concatenates as ''.
        return hash_hmac('sha256', $user->getAuthPassword()."\0".$user->getRememberToken(), (string) config('app.key'));
    }

    /**
     * A real hash at the configured cost, made once and cached, which sign-in checks a password against when there is
     * no reader or no password — so every path costs exactly one hash check.
     *
     * ⚠️ NOT A CONSTANT. A hash written into the source has one cost; at any other `hashing.bcrypt.rounds` the
     * unknown-address path would answer measurably faster or slower. The cache key is a digest of the hashing
     * configuration, so changing the cost makes a new one.
     */
    public static function timingHash(): string
    {
        $key = self::TIMING_HASH.sha1((string) json_encode([
            config('hashing.driver'),
            config('hashing.bcrypt'),
            config('hashing.argon'),
        ]));

        return Cache::rememberForever($key, static fn (): string => Hash::make(Str::random(40)));
    }

    /** Signs this reader in on the reader guard: the session id and CSRF token rotate (Laravel's `login()`). */
    public function signIn(#[\SensitiveParameter] Model&Authenticatable&ReaderAccount $reader): void
    {
        $this->sessionGuard()->login($reader);
    }

    /**
     * Signs the reader out of this session alone — idempotent for a guest.
     *
     * ⚠️ `migrate()`, so the id changes; never `invalidate()` and never `regenerateToken()`, so a staff session in the
     * same cookie, and its open admin tab, keep working.
     */
    public function signOut(): void
    {
        $guard = $this->sessionGuard();

        $guard->logoutCurrentDevice();
        $guard->getSession()->forget([self::BINDING, LinkUseController::STASH]);
        $guard->getSession()->migrate(true);
    }

    /**
     * The reader signed in to this session for the org in context, or null.
     *
     * ⚠️ FENCED ON THE ROW'S OWN `org_id`, as `ReaderGuard::current()` is: a host provider that strips the org scope
     * would otherwise hand back another organisation's reader.
     */
    public function current(): (Model&Authenticatable&ReaderAccount)|null
    {
        $name = $this->accounts->guardName();
        $model = $this->accounts->model();
        $orgId = $this->context->orgId();

        if ($name === null || $model === null || $orgId === null) {
            return null;
        }

        $user = ReaderAccounts::mapped(static fn () => Auth::guard($name)->user());

        return $user instanceof $model && self::writtenKey($user->getAttributes()['org_id'] ?? null) === $orgId ? $user : null;
    }

    /** The reader guard, which `ReaderAccounts` has proved is a session guard before any reader route runs. */
    private function sessionGuard(): SessionGuard
    {
        $name = $this->accounts->guardName();
        $guard = $name !== null ? Auth::guard($name) : null;

        if (! $guard instanceof SessionGuard) {
            throw new \LogicException('Reader accounts are not usable here; a reader route should have answered 404.');
        }

        return $guard;
    }

    /** The guard, when this event is the declared reader guard's and its user has the contract — else null. */
    private static function guardFor(string $name, Authenticatable $user): ?SessionGuard
    {
        if ($name !== config(ReaderGuard::CONFIG) || ! $user instanceof ReaderAccount) {
            return null;
        }

        $guard = Auth::guard($name);

        return $guard instanceof SessionGuard ? $guard : null;
    }
}
