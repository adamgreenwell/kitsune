<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Carts;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Entitlements\EntitlementUnavailable;
use Kitsune\Core\Readers\ReaderAccounts;
use Kitsune\Core\Readers\ReaderSessions;
use Kitsune\Core\Tenancy\Context;

/** A recoverable browser secret survives Login's rotation; only that Login's site may adopt it. @internal */
final class CartSessions
{
    public function __construct(private readonly CartWriter $writer, private readonly Context $context) {}

    public static function listen(Dispatcher $events): void
    {
        $events->listen(Login::class, [self::class, 'signedIn']);
    }

    public static function pendingKey(int $org, int $site): string
    {
        return ReaderSessions::EPHEMERAL.'.cart_pending.'.$org.'.'.$site;
    }

    public function signedIn(Login $event): void
    {
        $readers = app(ReaderGuard::class);
        $org = $this->context->orgId();
        $site = $this->context->siteId();
        $model = $readers->model();

        if ($org === null || $site === null || $model === null || $event->guard !== $readers->name()
            || ! $event->user instanceof $model || (string) ($event->user->getAttributes()['org_id'] ?? '') !== (string) $org) {
            return;
        }

        $guard = Auth::guard($event->guard);
        $reader = $readers->key($event->user->getAuthIdentifier());

        if (! $guard instanceof SessionGuard || $reader === null) {
            return;
        }

        $session = $guard->getSession();
        CartOwner::restore(app('request'), $session);

        if (CartOwner::anonymous($session, $org, $site) === null) {
            return;
        }

        // Login fires before setUser(): use the checked event user, never the guard's old current user.
        $session->put(self::pendingKey($org, $site), CartOwner::readerKey($reader));

        try {
            $this->writer->adopt($session, $reader);
        } catch (CartRefused) {
            // Both carts and the guest handle survive a refused merge, for an explicit same-site retry.
        } catch (QueryException $failure) {
            self::unavailable(ReaderAccounts::sqlstate($failure));
        } catch (EntitlementUnavailable $failure) {
            self::unavailable($failure->state);
        }
    }

    /** Returns true only for a capacity/validation refusal; database failures reach the route's safe 503 boundary. */
    public function retryPending(Request $request): bool
    {
        $owner = CartOwner::current($request);

        if ($owner === null || $owner->reader === null) {
            return false;
        }

        $pending = $request->session()->get(self::pendingKey($owner->orgId, $owner->siteId));

        if (! is_string($pending) || ! hash_equals($owner->key, $pending)) {
            return false;
        }

        try {
            $this->writer->adopt($request->session(), $owner->reader);
        } catch (CartRefused) {
            return true;
        }

        return false;
    }

    private static function unavailable(string $state): void
    {
        Log::warning('Cart adoption could not complete; both carts were retained.', ['sqlstate' => $state]);
    }
}
