<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Audit;

use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Tenancy\Context;
use RuntimeException;

/**
 * Records what happened, and deliberately not what changed (ADR-020).
 *
 * ⚠️ There is no `$changes` parameter and there must never be one. The
 * signature is the enforcement: a caller cannot pass a payload because there
 * is nowhere to put it, which is a stronger guarantee than a convention
 * everybody agrees with and someone eventually breaks under deadline.
 *
 * Silent when there is no org context. An audit row needs an org — the model
 * is `#[OrgScoped]` — and console commands, migrations and the installer all
 * run without one. Throwing there would make audit an obstacle to routine
 * work, and an audit system people switch off records nothing at all.
 *
 * ⚠️ That leniency is for actions the caller CHOSE to record. It is wrong for
 * a write that has already happened: console code supplying `org_id` and
 * `site_id` by hand inserts an entry perfectly well without populating
 * Context, and this would then return null and let the transaction commit an
 * entry with no audit row — the exact thing ADR-020's amendment says is
 * refused. `recordOrFail()` is what the entry paths use.
 */
final class Auditor
{
    public function __construct(private readonly Context $context) {}

    /**
     * Record, or refuse the write that could not be recorded.
     *
     * Used by AuditedBuilder for every entry write. Failing here rolls the
     * surrounding transaction back, which is the whole point: an entry that
     * cannot be audited must not exist. The fix is always the same, so the
     * message says it.
     */
    public function recordOrFail(string $action, ?Model $target = null): AuditLog
    {
        return $this->record($action, $target) ?? throw new RuntimeException(
            "Refusing [{$action}]: there is no organisation context, so the write could not be "
            .'audited and an unauditable write is refused (ADR-020). Set the org context — '
            .'app(Context::class)->setOrg(...) — before writing entries from a command, a '
            .'migration or a seeder.'
        );
    }

    /**
     * An identifier as the log stores it: a string, or null.
     *
     * ⚠️ WHATEVER THE HOST'S KEYS ARE. An integer id, a UUID, an LDAP subject — the column is a string so
     * that recording the actor never fails, because an audit insert that fails takes the write it was
     * recording with it (they share a transaction by design). Anything that cannot be expressed as a scalar
     * is recorded as nothing rather than as a guess.
     */
    private static function identifier(mixed $id): ?string
    {
        return is_int($id) || is_string($id) ? (string) $id : null;
    }

    public function record(string $action, ?Model $target = null): ?AuditLog
    {
        $orgId = $this->context->orgId();
        $actor = Permissions::currentUser();

        if ($orgId === null) {
            return null;
        }

        return AuditLog::create([
            'org_id' => $orgId,
            'site_id' => $this->context->siteId(),
            /*
             * NULL for the system acting on its own — a scheduled prune, a
             * replayed erasure. Attributing that to whoever happened to be
             * logged in would be a lie in the one place that must not hold
             * one.
             *
             * ⚠️ ASKED OF THE PANEL WHEN THERE IS ONE, which review found this
             * not doing. A host may authenticate its Kitsune panel through a
             * guard that is not the application default — Filament's own
             * `Panel::authGuard()` exists for exactly that — and bare
             * `auth()->id()` then asks the DEFAULT guard: it records null, or
             * worse, whichever unrelated user happens to be signed in on
             * another guard at the same time. ADR-020's log claims to answer
             * "at whose hand", so an actor resolved from somebody else's guard
             * is the one kind of wrong this column must not be.
             *
             * `Permissions::currentUser()` is that resolution and already
             * carries the binding check the package test suite needs — core's
             * tests never register `filament`, so reaching for the facade
             * unguarded fails with "Target class [filament] does not exist".
             * One encoding, used by both layers, rather than a second copy
             * here to drift from it.
             */
            /*
             * ⚠️ AND ITS CLASS, because an id alone is not an identity — review found the asymmetry with
             * `target_type` directly below. Two providers mean two user models with two sequences, so an
             * unqualified 7 names whichever row the reader assumes. `getMorphClass()` is what `target_type`
             * already stores, so the two halves of a row now answer the same way.
             *
             * ⚠️ `getMorphClass()` IS A MODEL METHOD AND AN ACTOR NEED NOT BE ONE. An `Authenticatable` that
             * is not Eloquent — an LDAP or SSO identity — still acted, and its class name is a better answer
             * than a bare id; it simply cannot be looked up through a morph map, which nothing here does.
             */
            'actor_type' => match (true) {
                $actor === null => null,
                $actor instanceof Model => $actor->getMorphClass(),
                default => $actor::class,
            },
            'actor_id' => self::identifier($actor?->getAuthIdentifier()),
            'action' => $action,
            'target_type' => $target?->getMorphClass(),
            'target_id' => self::identifier($target?->getKey()),
            'created_at' => now(),
        ]);
    }
}
