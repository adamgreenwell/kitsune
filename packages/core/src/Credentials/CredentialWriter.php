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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Kitsune\Core\Audit\Auditor;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Credential;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\OrgCredentialMode;
use Kitsune\Core\Tenancy\Context;
use Throwable;

/**
 * The one door in: sets, replaces and removes an org's credentials, and switches it between test and live — ADR-040.
 *
 * ⚠️ NO `Org` PARAMETER ANYWHERE. It writes for the org in context, so a wrong org cannot be passed in.
 *
 * Value validation and sealing precede the write transaction. No database binding, query log or database error ever
 * holds the value in plaintext: at worst, ciphertext.
 *
 * ⚠️ RECORDED INSIDE THE WRITE'S TRANSACTION — the action and the row, never the value or the credential's name. A write
 * that cannot be recorded is not kept (ADR-020), and a refused write records nothing.
 *
 * Authority is a current owner, checked again under the org lock and after audit. Live changes additionally verify
 * the current local password for this operation. A null actor has no authority without a one-use console lease.
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

    public const REENCRYPTED = 'credential.reencrypted';

    /**
     * The window `GuardedCredentialBuilder` reads. Armed only in the private `save()`, around one save, and cleared in a
     * `finally`; `save()` is called from the four checked operations alone (asserted) — there is no opener, so
     * nothing outside this class can hold it open.
     */
    private static bool $writing = false;

    public function __construct(
        private readonly Auditor $auditor,
        private readonly Context $context,
        private readonly CredentialSlots $slots,
        private readonly CredentialCipher $cipher,
        private readonly FreshCredentialAuthentication $authentication,
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
    public function set(#[\SensitiveParameter] string $slot, ?CredentialMode $mode, #[\SensitiveParameter] string $value, #[\SensitiveParameter] ?string $currentPassword = null, ?CredentialConsoleAuthority $console = null): void
    {
        $orgId = $this->refuseWithoutAuthority(CredentialRefused::SET, $slot, $mode, $console);
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

        $this->transaction(CredentialRefused::SET, $slot, $declared, $mode, function () use ($orgId, $slot, $mode, $modeValue, $sealed, $currentPassword, $console): void {
            $this->lockOrg($orgId);
            $orgMode = $mode === null ? ($this->modeRow()->mode ?? CredentialMode::Test->value) : null;
            $proof = $this->authenticate($mode, $currentPassword, $console);
            $row = $this->lockedRow($slot, $modeValue);
            $action = $row->exists && $row->ciphertext !== null ? self::REPLACED : self::SET;

            $row->forceFill([
                'slot' => $slot,
                'mode' => $modeValue,
                'ciphertext' => $sealed->ciphertext,
                'key_id' => $sealed->keyId,
                'changed_at' => now(),
            ]);

            $expected = $this->save($row, CredentialRefused::SET, $slot);
            $this->record($action, $row);
            $this->assertKept($orgId, $row, $proof, $console, $expected, $orgMode);
        });
    }

    /** Take a value away. With nothing stored, nothing is written and nothing is recorded. */
    public function remove(#[\SensitiveParameter] string $slot, ?CredentialMode $mode, #[\SensitiveParameter] ?string $currentPassword = null, ?CredentialConsoleAuthority $console = null): void
    {
        $orgId = $this->refuseWithoutAuthority(CredentialRefused::REMOVE, $slot, $mode, $console);
        $declared = $this->declared($slot, $mode, CredentialRefused::REMOVE);
        $modeValue = $mode->value ?? 'none';

        $this->transaction(CredentialRefused::REMOVE, $slot, $declared, $mode, function () use ($orgId, $slot, $mode, $modeValue, $currentPassword, $console): void {
            $this->lockOrg($orgId);
            $orgMode = $mode === null ? ($this->modeRow()->mode ?? CredentialMode::Test->value) : null;
            $proof = $this->authenticate($mode, $currentPassword, $console);
            $row = $this->lockedRow($slot, $modeValue);

            if (! $row->exists || $row->ciphertext === null) {
                return;
            }

            // A tombstone, not a delete: the audit row keeps a target, and "who removed the live key" stays answerable.
            $row->forceFill(['ciphertext' => null, 'key_id' => null, 'changed_at' => now()]);

            $expected = $this->save($row, CredentialRefused::REMOVE, $slot);
            $this->record(self::REMOVED, $row);
            $this->assertKept($orgId, $row, $proof, $console, $expected, $orgMode);
        });
    }

    /**
     * Put the org in test or live mode. False when it already was, and then nothing is written or recorded.
     *
     * Not refused when live values are missing: each consumer then fails closed on `NotSet`, and refusing would need a
     * "required" flag that no credential has.
     */
    public function switchTo(CredentialMode $mode, #[\SensitiveParameter] ?string $currentPassword = null, ?CredentialConsoleAuthority $console = null): bool
    {
        $orgId = $this->refuseWithoutAuthority(CredentialRefused::SWITCH, null, $mode, $console);

        return $this->transaction(CredentialRefused::SWITCH, null, null, null, function () use ($orgId, $mode, $currentPassword, $console): bool {
            $this->lockOrg($orgId);
            $proof = $console === null ? $this->authentication->confirm($currentPassword, $mode === CredentialMode::Live) : null;

            $row = $this->modeRow();

            if (($row->exists ? $row->mode : CredentialMode::Test->value) === $mode->value) {
                return false;
            }

            $row->forceFill(['mode' => $mode->value, 'changed_at' => now()]);

            $expected = $this->save($row, CredentialRefused::SWITCH, null);
            $this->record($mode === CredentialMode::Live ? self::MODE_LIVE : self::MODE_TEST, $row);
            $this->assertKept($orgId, $row, $proof, $console, $expected);

            return true;
        });
    }

    /** @param  CredentialRefused::*  $act */
    private function refuseWithoutAuthority(string $act, ?string $slot = null, ?CredentialMode $mode = null, ?CredentialConsoleAuthority $console = null, ?int $credentialId = null): int
    {
        $orgId = $this->context->orgId() ?? throw CredentialRefused::because(CredentialRefusal::NoOrgContext, $act);
        $user = Permissions::currentUser();

        if ($console !== null) {
            $console->consume($this->context, $act, $slot, $mode, $credentialId);
        }

        if (($console !== null && $user !== null) || ($console === null && ($user === null || ! Permissions::isOwner($user)))) {
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
     * Missing credential rows are not locked: their InnoDB gap can span other orgs. Known primary keys use current
     * locking reads so an older outer snapshot cannot overwrite a replacement. Mode decisions also require current
     * reads; a first mode-row insert can still encounter a gap race, which safely refuses and asks for a retry.
     */
    private function lockOrg(int $orgId): void
    {
        $connection = (new Credential)->getConnection();

        // SQLite has no row locks. Reserve its writer before reading password/mode state, including in a savepoint.
        if ($connection->getDriverName() === 'sqlite') {
            $connection->table('orgs')->where('id', $orgId)->update(['id' => $orgId]);
        }

        $found = Org::query()->withoutGlobalScopes()->useWritePdo()->whereKey($orgId)->lockForUpdate()->value('id');

        if ($found === null || $this->context->orgId() !== $orgId) {
            throw CredentialRefused::because(CredentialRefusal::Race, CredentialRefused::SET);
        }
    }

    private function lockedRow(string $slot, string $mode): Credential
    {
        $row = Credential::query()->useWritePdo()->where('slot', $slot)->where('mode', $mode)->first();

        // Lock only a known primary key: a missing scoped row would take an InnoDB gap lock across other orgs.
        // An older outer snapshot missing a newly inserted row instead loses the unique race and safely refuses.
        return $row === null ? new Credential : (Credential::query()->useWritePdo()->whereKey($row->getKey())->lockForUpdate()->first() ?? new Credential);
    }

    private function modeRow(): OrgCredentialMode
    {
        // A current read, including absence: a stale outer snapshot must never treat a live org as test.
        return OrgCredentialMode::query()->useWritePdo()->lockForUpdate()->first() ?? new OrgCredentialMode;
    }

    private function authenticate(?CredentialMode $mode, #[\SensitiveParameter] ?string $password, ?CredentialConsoleAuthority $console): ?CredentialAuthentication
    {
        if ($console !== null) {
            $console->assertActiveFor($this->context);

            return null;
        }

        $live = $mode === CredentialMode::Live || ($mode === null && $this->modeRow()->mode === CredentialMode::Live->value);

        return $this->authentication->confirm($password, $live);
    }

    /** @param array<string, mixed> $expectedAttributes */
    private function assertKept(int $orgId, Credential|OrgCredentialMode $row, ?CredentialAuthentication $proof, ?CredentialConsoleAuthority $console, array $expectedAttributes, ?string $orgMode = null): void
    {
        if ($this->context->orgId() !== $orgId || (int) $row->org_id !== $orgId) {
            throw CredentialRefused::because(CredentialRefusal::Race, CredentialRefused::SET);
        }

        if ($console !== null) {
            $console->assertActiveFor($this->context);

            if (Permissions::currentUser() !== null) {
                throw CredentialRefused::because(CredentialRefusal::NotAnOwner, CredentialRefused::SET);
            }
        } else {
            $proof?->assertCurrent();
        }

        $kept = $row->newQuery()->useWritePdo()->whereKey($row->getKey())->lockForUpdate()->first();
        $keptAttributes = $kept?->getRawOriginal() ?? [];
        ksort($expectedAttributes);
        ksort($keptAttributes);

        if ($kept === null || $keptAttributes !== $expectedAttributes
            || ($orgMode !== null && ($this->modeRow()->mode ?? CredentialMode::Test->value) !== $orgMode)
            || ($row instanceof Credential && $row->ciphertext !== null && $row->key_id !== $this->cipher->currentKeyId())) {
            throw CredentialRefused::because(CredentialRefusal::Race, CredentialRefused::SET);
        }
    }

    /** Reseal any retained row, including one belonging to a disabled module, without opening a consumer read path. */
    public function reencrypt(int $credentialId, CredentialConsoleAuthority $console): bool
    {
        $orgId = $this->refuseWithoutAuthority(CredentialRefused::REENCRYPT, console: $console, credentialId: $credentialId);

        return $this->transaction(CredentialRefused::REENCRYPT, null, null, null, function () use ($orgId, $credentialId, $console): bool {
            $this->lockOrg($orgId);
            $console->assertActiveFor($this->context);
            $row = Credential::query()->useWritePdo()->whereKey($credentialId)->lockForUpdate()->first();

            if ($row === null || $row->ciphertext === null) {
                return false;
            }

            $currentKeyId = $this->cipher->currentKeyId();

            if ($currentKeyId === null) {
                throw CredentialRefused::because(CredentialRefusal::NoAppKey, CredentialRefused::REENCRYPT);
            }

            if ($row->key_id === $currentKeyId) {
                return false;
            }

            $sealed = $this->cipher->reseal($row);
            $row->forceFill(['ciphertext' => $sealed->ciphertext, 'key_id' => $sealed->keyId]);
            $expected = $this->save($row, CredentialRefused::REENCRYPT, null);
            $this->record(self::REENCRYPTED, $row);
            $this->assertKept($orgId, $row, null, $console, $expected);

            return true;
        });
    }

    private function record(string $action, Credential|OrgCredentialMode $row): void
    {
        $orgId = $this->context->orgId();
        $siteId = $this->context->siteId();
        $actor = Permissions::currentUser();
        $actorId = $actor === null ? null : (string) $actor->getAuthIdentifier();
        $actorType = $actor === null ? null : ($actor instanceof Model ? $actor->getMorphClass() : $actor::class);
        $audit = $this->auditor->recordOrFail($action, $row);
        $kept = $audit->exists ? AuditLog::query()->useWritePdo()->whereKey($audit->getKey())->lockForUpdate()->first() : null;

        if ($kept === null || (int) $kept->org_id !== $orgId || $kept->site_id !== $siteId
            || $kept->action !== $action || $kept->target_type !== $row->getMorphClass()
            || $kept->target_id !== (string) $row->getKey() || $kept->actor_id !== $actorId || $kept->actor_type !== $actorType) {
            throw CredentialRefused::because(CredentialRefusal::Cancelled, CredentialRefused::SET);
        }
    }

    /**
     * @param  CredentialRefused::*  $act
     * @return array<string, mixed> intended attributes, captured before any model observer can rewrite them
     */
    private function save(Credential|OrgCredentialMode $row, string $act, ?string $slot): array
    {
        $expected = $row->getAttributes();
        $expected['org_id'] = $this->context->orgId();
        self::$writing = true;

        try {
            $saved = $row->save();
        } finally {
            self::$writing = false;
        }

        if (! $saved) {
            throw CredentialRefused::because(CredentialRefusal::Cancelled, $act, $slot, $slot !== null ? $this->slots->find($slot) : null);
        }

        $expected[$row->getKeyName()] ??= $row->getKey();

        return $expected;
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
        } catch (CredentialRefused $refused) {
            throw $refused;
        } catch (CredentialAuthenticationRefused) {
            throw CredentialRefused::because(CredentialRefusal::FreshAuthentication, $act);
        } catch (CredentialUnavailable) {
            throw CredentialRefused::because(CredentialRefusal::Unreadable, $act);
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
        } catch (Throwable) {
            // Host listeners and accessors can hold ciphertext or input in their errors; never chain or repeat them.
            throw CredentialRefused::because(CredentialRefusal::Cancelled, $act, $slot, $declared, $mode);
        }
    }
}
