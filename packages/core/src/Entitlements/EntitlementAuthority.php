<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Entitlements;

use Closure;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Tenancy\Context;

/**
 * The steps every door to a reader's rows takes before it reads or writes them — `EntitlementWriter` and
 * `EntitlementRecords` alike, so the rule of who may act has one encoding — ADR-040.
 *
 * ⚠️ AUTHORITY: an owner of the org in context; otherwise a reader signed in to this request is refused (`ReaderActing`),
 * whether the route made them the default guard or their session merely rides along on a route with no auth
 * middleware; otherwise anyone else signed in is refused (`NotAnOwner`); otherwise nobody is signed in, and the caller
 * is trusted as the system — a webhook, the console, an import — except for a comp, which only a person may give.
 *
 * ⚠️ A PUBLIC ROUTE REACHES THE WRITER BY DESIGN — commerce's webhook — so every caller with nobody signed in must have
 * proved its own authority first; the signature check is commerce's. The credential writer could claim no anonymous
 * web path reaches it; this one cannot.
 *
 * ⚠️ EVERY QUERY A DOOR RUNS SITS INSIDE `mapped()`: the session's reader load, the host-table lookup and the
 * transaction alike, so a database error never leaves with the reader's identifier or the source in its message.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class EntitlementAuthority
{
    public function __construct(private readonly Context $context, private readonly ReaderGuard $readers) {}

    /**
     * Run a door's body with its database failures turned into refusals that chain nothing.
     *
     * @template T
     *
     * @param  EntitlementRefused::*  $act
     * @param  Closure(): T  $body
     * @return T
     */
    public static function mapped(string $act, ?string $entitlement, Closure $body): mixed
    {
        try {
            return $body();
        } catch (UniqueConstraintViolationException) {
            throw EntitlementRefused::because(EntitlementRefusal::Race, $act, $entitlement);
        } catch (DeadlockException) {
            /*
             * ⚠️ NOT A `QueryException`, AND IT CHAINS ONE, which a nested transaction produces: inside a caller's
             * transaction Laravel turns a deadlock or a lock-wait timeout into this, with the query and its bindings as
             * its previous exception.
             */
            throw EntitlementRefused::because(EntitlementRefusal::Race, $act, $entitlement);
        } catch (QueryException $e) {
            throw EntitlementRefused::because(EntitlementRefusal::Database, $act, $entitlement, [
                'state' => (string) ($e->errorInfo[0] ?? $e->getCode()),
            ]);
        }
    }

    /**
     * The site in context. The site implies the org.
     *
     * @param  EntitlementRefused::*  $act
     */
    public function site(string $act, ?string $entitlement): int
    {
        return $this->context->siteId() ?? throw EntitlementRefused::because(EntitlementRefusal::NoSiteContext, $act, $entitlement);
    }

    /**
     * The org in context.
     *
     * @param  EntitlementRefused::*  $act
     */
    public function org(string $act): int
    {
        return $this->context->orgId() ?? throw EntitlementRefused::because(EntitlementRefusal::NoOrgContext, $act);
    }

    /**
     * An owner, or the system — and never a reader, a non-owner, or, for a comp, nobody.
     *
     * @param  EntitlementRefused::*  $act
     */
    public function authorise(string $act, ?string $entitlement): void
    {
        $user = Permissions::currentUser();

        if ($user !== null && Permissions::isOwner($user)) {
            return;
        }

        if ($this->readers->current() !== null) {
            throw EntitlementRefused::because(EntitlementRefusal::ReaderActing, $act, $entitlement);
        }

        // A comp is the one act that gives back a revoked access, so its warrant is a person deciding now.
        if ($user !== null || $act === EntitlementRefused::COMP) {
            throw EntitlementRefused::because(EntitlementRefusal::NotAnOwner, $act, $entitlement);
        }
    }

    /**
     * The declared reader guard is usable.
     *
     * @param  EntitlementRefused::*  $act
     */
    public function guard(string $act, ?string $entitlement): void
    {
        if ($this->readers->fault() !== null) {
            throw EntitlementRefused::because(EntitlementRefusal::NoReaderGuard, $act, $entitlement, [
                'fault' => (string) $this->readers->faultSentence(),
            ]);
        }
    }

    /**
     * The reader's key as the guard's model types it.
     *
     * @param  EntitlementRefused::*  $act
     */
    public function key(string $act, ?string $entitlement, #[\SensitiveParameter] int|string $reader): string
    {
        return $this->readers->key($reader) ?? throw EntitlementRefused::because(EntitlementRefusal::NotAReader, $act, $entitlement);
    }

    /**
     * The key a reader's rows are filed under, for an export or an erasure: the host's own spelling of a reader it still
     * has, the key itself for one it has deleted, and with no usable guard the identifier exactly as given — an erasure
     * is never blocked by configuration.
     *
     * @param  EntitlementRefused::*  $act
     */
    public function filedKey(string $act, #[\SensitiveParameter] int|string $reader): string
    {
        if ($this->readers->fault() !== null) {
            return ReaderGuard::stored($reader) ?? throw EntitlementRefused::because(EntitlementRefusal::NotAReader, $act);
        }

        $key = $this->key($act, null, $reader);

        return $this->readers->canonical($reader) ?? $key;
    }
}
