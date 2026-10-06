<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Credentials;

use Closure;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Kitsune\Core\Audit\Auditor;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Models\Credential;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\OrgCredentialMode;
use Kitsune\Core\Tenancy\Context;

/**
 * The one door in: sets, replaces and removes an org's credentials, and switches it between test and live — ADR-040.
 *
 * ⚠️ NO `Org` PARAMETER ANYWHERE. It writes for the org in context, so a wrong org cannot be passed in.
 *
 * ⚠️ EVERY CHECK ON THE VALUE RUNS BEFORE THE FIRST QUERY, and the value is sealed before the transaction opens, so no
 * binding, query log or database error ever holds it in plaintext: at worst, ciphertext.
 *
 * ⚠️ RECORDED INSIDE THE WRITE'S TRANSACTION — the action and the row, never the value or the credential's name. A write
 * that cannot be recorded is not kept (ADR-020), and a refused write records nothing.
 *
 * ⚠️ AUTHORITY: an owner of the org in context (ADR-033). With nobody signed in the writer trusts its caller, as
 * `Entry::refuseUnpermittedRepublication()` reads a null actor as the system acting; no web path reaches it without a
 * signed-in owner — the credentials page, its actions and `CredentialSetController` each refuse first, the controller's
 * refusal asserted with nobody signed in — and there is no console write path. One that comes must open an explicit window of its
 * own for that — never `runningInConsole()`, which is true in a queue worker, a CLI-served HTTP worker and every test.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class CredentialWriter
{
    public const SET = 'credential.set';

    public const REPLACED = 'credential.replaced';

    public const REMOVED = 'credential.removed';

    public const MODE_LIVE = 'credential.mode_live';

    public const MODE_TEST = 'credential.mode_test';

    /**
     * The window `GuardedCredentialBuilder` reads. Armed only in the private `save()`, around one save, and cleared in a
     * `finally`; `save()` is called from `set()`, `remove()` and `switchTo()` alone (asserted) — there is no opener, so
     * nothing outside this class can hold it open.
     */
    private static bool $writing = false;

    public function __construct(
        private readonly Auditor $auditor,
        private readonly Context $context,
        private readonly CredentialSlots $slots,
        private readonly CredentialCipher $cipher,
    ) {}

    /** Whether the writer is saving right now — for `GuardedCredentialBuilder`, which cannot open it. */
    public static function isWriting(): bool
    {
        return self::$writing;
    }

    /**
     * Set or replace a value. The caller trims surrounding whitespace; this refuses any that remains.
     *
     * ⚠️ THE NAME IS KEPT OUT OF A TRACE AS WELL AS THE VALUE, because a value pasted where the name belongs is a
     * mistake this refuses — and refusing it must not print it. The refusal names a credential only when it has one's
     * shape.
     */
    public function set(#[\SensitiveParameter] string $slot, ?CredentialMode $mode, #[\SensitiveParameter] string $value): void
    {
        $orgId = $this->refuseWithoutAuthority(CredentialRefused::SET);
        $declared = $this->declared($slot, $mode, CredentialRefused::SET);

        $refusal = $declared->refusalFor($mode, $value);

        if ($refusal !== null) {
            throw CredentialRefused::because($refusal, CredentialRefused::SET, $slot, $declared, $mode, [
                'prefix' => $mode !== null ? (string) $declared->otherModePrefixOf($mode, $value) : '',
            ]);
        }

        if ($this->cipher->currentKeyId() === null) {
            throw CredentialRefused::because(CredentialRefusal::NoAppKey, CredentialRefused::SET, $slot, $declared, $mode);
        }

        $modeValue = $mode->value ?? 'none';
        $sealed = $this->cipher->seal($orgId, $slot, $modeValue, $value);
        unset($value);

        $this->transaction(CredentialRefused::SET, $slot, $declared, $mode, function () use ($slot, $modeValue, $sealed): void {
            $row = $this->lockedRow($slot, $modeValue);
            $action = $row->exists && $row->ciphertext !== null ? self::REPLACED : self::SET;

            $row->forceFill([
                'slot' => $slot,
                'mode' => $modeValue,
                'ciphertext' => $sealed->ciphertext,
                'key_id' => $sealed->keyId,
                'changed_at' => now(),
            ]);

            $this->save($row, CredentialRefused::SET, $slot);
            $this->auditor->recordOrFail($action, $row);
        });
    }

    /** Take a value away. With nothing stored, nothing is written and nothing is recorded. */
    public function remove(#[\SensitiveParameter] string $slot, ?CredentialMode $mode): void
    {
        $this->refuseWithoutAuthority(CredentialRefused::REMOVE);
        $declared = $this->declared($slot, $mode, CredentialRefused::REMOVE);
        $modeValue = $mode->value ?? 'none';

        $this->transaction(CredentialRefused::REMOVE, $slot, $declared, $mode, function () use ($slot, $modeValue): void {
            $row = $this->lockedRow($slot, $modeValue);

            if (! $row->exists || $row->ciphertext === null) {
                return;
            }

            // A tombstone, not a delete: the audit row keeps a target, and "who removed the live key" stays answerable.
            $row->forceFill(['ciphertext' => null, 'key_id' => null, 'changed_at' => now()]);

            $this->save($row, CredentialRefused::REMOVE, $slot);
            $this->auditor->recordOrFail(self::REMOVED, $row);
        });
    }

    /**
     * Put the org in test or live mode. False when it already was, and then nothing is written or recorded.
     *
     * Not refused when live values are missing: each consumer then fails closed on `NotSet`, and refusing would need a
     * "required" flag that no credential has.
     */
    public function switchTo(CredentialMode $mode): bool
    {
        $this->refuseWithoutAuthority(CredentialRefused::SWITCH);

        return $this->transaction(CredentialRefused::SWITCH, null, null, null, function () use ($mode): bool {
            $this->lockOrg();

            $row = OrgCredentialMode::query()->first() ?? new OrgCredentialMode;

            if (($row->exists ? $row->mode : CredentialMode::Test->value) === $mode->value) {
                return false;
            }

            $row->forceFill(['mode' => $mode->value, 'changed_at' => now()]);

            $this->save($row, CredentialRefused::SWITCH, null);
            $this->auditor->recordOrFail($mode === CredentialMode::Live ? self::MODE_LIVE : self::MODE_TEST, $row);

            return true;
        });
    }

    /** @param  CredentialRefused::*  $act */
    private function refuseWithoutAuthority(string $act): int
    {
        $orgId = $this->context->orgId() ?? throw CredentialRefused::because(CredentialRefusal::NoOrgContext, $act);
        $user = Permissions::currentUser();

        if ($user !== null && ! Permissions::isOwner($user)) {
            throw CredentialRefused::because(CredentialRefusal::NotAnOwner, $act);
        }

        return $orgId;
    }

    /** @param  CredentialRefused::*  $act */
    private function declared(#[\SensitiveParameter] string $slot, ?CredentialMode $mode, string $act): CredentialSlot
    {
        if (! CredentialSlot::isName($slot)) {
            throw CredentialRefused::because(CredentialRefusal::NotAName, $act);
        }

        $declared = $this->slots->find($slot) ?? throw CredentialRefused::because(CredentialRefusal::UnknownSlot, $act, $slot);

        if ($declared->moded && $mode === null) {
            throw CredentialRefused::because(CredentialRefusal::ModeRequired, $act, $slot, $declared);
        }

        if (! $declared->moded && $mode !== null) {
            throw CredentialRefused::because(CredentialRefusal::ModeNotTaken, $act, $slot, $declared);
        }

        return $declared;
    }

    /**
     * The org's row, locked: one org's credential writes and mode switches take it in turn, as `Role` does.
     *
     * ⚠️ AND THAT LOCK ALONE — the rows read after it are read plainly. A `FOR UPDATE` on a credential that does not
     * exist yet takes a gap lock on MySQL and MariaDB at REPEATABLE READ, and two orgs' first credentials then
     * deadlock on each other's gap: measured on MariaDB, a valid first save refused as a database error (review). The
     * org's lock already serialises every write this class makes for that org, so a row lock would guard nothing more.
     */
    private function lockOrg(): void
    {
        Org::query()->withoutGlobalScopes()->whereKey($this->context->orgId())->lockForUpdate()->value('id');
    }

    private function lockedRow(string $slot, string $mode): Credential
    {
        $this->lockOrg();

        return Credential::query()->where('slot', $slot)->where('mode', $mode)->first() ?? new Credential;
    }

    /** @param  CredentialRefused::*  $act */
    private function save(Credential|OrgCredentialMode $row, string $act, ?string $slot): void
    {
        self::$writing = true;

        try {
            $saved = $row->save();
        } finally {
            self::$writing = false;
        }

        if (! $saved) {
            throw CredentialRefused::because(CredentialRefusal::Cancelled, $act, $slot, $slot !== null ? $this->slots->find($slot) : null);
        }
    }

    /**
     * The write in a transaction on the models' connection, its database failures turned into refusals that chain
     * nothing: a database exception's message interpolates its bindings.
     *
     * ⚠️ THROUGH `TransactionRecovery`, which review found missing: inside a caller's transaction a lock-wait timeout on
     * MySQL or MariaDB becomes a `DeadlockException` with no `ROLLBACK TO`, so the credential saved before the audit
     * insert timed out stayed in the caller's transaction — committed, unrecorded, if the caller caught the refusal.
     * The helper rolls the call's own savepoint back, so "nothing was written" is true.
     *
     * @template T
     *
     * @param  CredentialRefused::*  $act
     * @param  Closure(): T  $write
     * @return T
     */
    private function transaction(string $act, ?string $slot, ?CredentialSlot $declared, ?CredentialMode $mode, Closure $write): mixed
    {
        try {
            return TransactionRecovery::run((new Credential)->getConnection(), static fn (): mixed => $write());
        } catch (UniqueConstraintViolationException) {
            throw CredentialRefused::because(CredentialRefusal::Race, $act, $slot, $declared, $mode);
        } catch (DeadlockException) {
            /*
             * ⚠️ NOT A `QueryException`, AND IT CHAINS ONE, which a nested transaction produces: inside a caller's
             * transaction Laravel turns a deadlock or a lock-wait timeout into this, with the query and its bindings as
             * its previous exception — ciphertext at worst, and never let out.
             */
            throw CredentialRefused::because(CredentialRefusal::Race, $act, $slot, $declared, $mode);
        } catch (QueryException $e) {
            throw CredentialRefused::because(CredentialRefusal::Database, $act, $slot, $declared, $mode, [
                'state' => (string) ($e->errorInfo[0] ?? $e->getCode()),
            ]);
        }
    }
}
