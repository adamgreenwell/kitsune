<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Readers;

use Closure;
use Illuminate\Database\Connection;
use Kitsune\Core\Auth\EmailAddress;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Entitlements\EntitlementAuthority;
use Kitsune\Core\Entitlements\EntitlementRefused;
use Kitsune\Core\Entitlements\EntitlementWriter;
use Kitsune\Core\Models\Entitlement;
use Kitsune\Core\Models\ReaderToken;
use Kitsune\Core\Tenancy\Context;

/**
 * One reader of the org in context, erased from every store core knows of (ADR-020) — their entitlements on every site
 * of the org, then any link waiting in their mail, then installed modules' reader data and the host's account.
 *
 * ⚠️ ONE TRANSACTION, ENTITLEMENTS FIRST, THE ACCOUNT LAST. `EntitlementWriter::forget()` authorises (an owner or the
 * system), locks and audits each row as it does alone, nested; then the reader's links go — the recovery link by their
 * key, a sign-up link by their address, normalised, because the contract's address is "as stored" and a host's model need
 * not lower-case it — then the account is locked and `eraseAccount()` runs. Link rows before the reader's row, the one
 * order `ReaderLinkUse` takes them in too. When the stores share a connection, a failure rolls back the full
 * erasure. With separate connections, see the host-commit boundary below.
 *
 * ⚠️ AN ADDRESS WITH NO ACCOUNT can still be erased (`eraseAddress()`): someone asked for a sign-up link and never used
 * it, and the address is all that is held.
 *
 * ⚠️ SPLIT HOST CONNECTIONS. Both stores are held open while proofs run, and the core transaction commits first.
 * An anchored account keeps its receipt pending until the host commit. A retry can finish that second commit.
 * A pair of independent databases cannot guarantee a distributed commit.
 *
 * ⚠️ IDEMPOTENT, AND THE HOST'S DELETION IS NOT ENOUGH. A second run erases nothing and says so. A reader the host
 * deleted with a bare `delete()` still has their entitlements, which this erases by key.
 *
 * Replayable receipts are recorded when the host provides a stable account anchor.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class ReaderErasure
{
    public function __construct(
        private readonly EntitlementWriter $entitlements,
        private readonly ReaderExport $readers,
        private readonly ReaderTokens $tokens,
        private readonly EntitlementAuthority $authority,
        private readonly ReaderErasureReceipts $receipts,
    ) {}

    /**
     * @return array{grants: int, links: int, account: bool} how many grants and links were erased, and whether an account was
     *
     * @throws EntitlementRefused
     */
    public function erase(#[\SensitiveParameter] int|string $reader, ?string $expectedAnchor = null, bool $requireReceipt = false, ?Connection $expectedStore = null): array
    {
        $org = $this->authority->org(EntitlementRefused::FORGET);
        $this->authority->authorise(EntitlementRefused::FORGET, null);
        $connection = (new Entitlement)->getConnection();
        $accounts = app(ReaderAccounts::class);
        $model = $accounts->model();
        $host = $model === null ? $connection : (new $model)->getConnection();
        if (($expectedStore !== null && $host !== $expectedStore)
            || ($expectedAnchor !== null && $host !== $connection && $expectedStore === null)) {
            throw new ReaderErasureReplayUnavailable('The configured reader connection differs from the confirmed restore target.');
        }

        $pending = null;
        if ($host === $connection) {
            return $this->eraseStores($reader, $connection, $host, $expectedAnchor, $requireReceipt, null, $pending);
        }

        if ($connection->transactionLevel() !== 0 || $host->transactionLevel() !== 0
            || $connection->getPdo()->inTransaction() || $host->getPdo()->inTransaction()) {
            throw new ReaderErasureReplayUnavailable('Split reader erasure must own both transactions.');
        }

        $result = TransactionRecovery::run($host, function () use ($reader, $connection, $expectedAnchor, $requireReceipt, $host, &$pending): array {
            $proveHost = $this->hostProof($host);

            return $this->eraseStores($reader, $connection, $host, $expectedAnchor, $requireReceipt, $proveHost, $pending);
        });

        if ($pending !== null && ($this->receipts->readerFor($pending, $org, $accounts, expectedStore: $host) !== null
            || ! $this->receipts->finishHost($connection, $org, $pending))) {
            throw new ReaderErasureReplayUnavailable('The host erasure still needs verification.');
        }

        return $result;
    }

    /** @return Closure():void */
    private function hostProof(Connection $host): Closure
    {
        $native = $host->getPdo();
        $level = $host->transactionLevel();

        return static function () use ($host, $native, $level): void {
            if ($host->getPdo() !== $native || $host->transactionLevel() !== $level || ! $native->inTransaction()) {
                throw new ReaderErasureReplayUnavailable('The reader store lost its erasure transaction.');
            }
        };
    }

    /** @return array{grants: int, links: int, account: bool} */
    private function eraseStores(#[\SensitiveParameter] int|string $reader, Connection $connection, Connection $host, ?string $expectedAnchor, bool $requireReceipt, ?Closure $proveHost, ?string &$pending): array
    {
        return TransactionRecovery::run($connection, function () use ($reader, $expectedAnchor, $requireReceipt, $connection, $host, $proveHost, &$pending): array {
            $org = $this->authority->org(EntitlementRefused::FORGET);
            $site = app(Context::class)->siteId();
            $grantErasure = $this->entitlements->forgetWithWitness($reader);
            $grants = $grantErasure['erased'];
            $found = $this->readers->find($reader, lock: true);
            if ($found !== null && $found->getConnection() !== $host) {
                throw new ReaderErasureReplayUnavailable('The account resolved outside its configured reader store.');
            }
            $anchor = ReaderErasureReceipts::anchor($found);
            if ($anchor === null && $requireReceipt) {
                throw new ReaderErasureReplayUnavailable('This reader has no usable erasure anchor in its host store.');
            }
            if ($expectedAnchor !== null && $anchor !== $expectedAnchor) {
                throw new ReaderErasureReplayUnavailable('The restored reader does not match the erasure receipt.');
            }
            $address = $found !== null ? EmailAddress::normalise($found->readerEmail()) : null;

            $key = $this->authority->filedKey(EntitlementRefused::FORGET, $reader);
            $consentKey = ReaderGuard::stored($found?->getKey()) ?? $key;
            $consentProof = app(ReaderConsents::class)->forgetWithWitness($connection, $consentKey, $found?->getConnection());
            $recoveryErasure = $this->tokens->forgetSubjectWithWitness(ReaderTokens::RECOVER, $key);
            $registrationErasure = $address === null ? null : $this->tokens->forgetSubjectWithWitness(ReaderTokens::REGISTER, $address);
            $links = $recoveryErasure['erased'] + ($registrationErasure['erased'] ?? 0);

            if (app(Context::class)->orgId() !== $org || app(Context::class)->siteId() !== $site) {
                throw new ReaderModuleDataUnavailable('erase');
            }
            $accountErased = false;
            /** @var Closure|null $accountProof */
            $accountProof = null;
            app(ReaderModuleData::class)->erase($reader, afterErase: function () use ($reader, $anchor, $host, $found, $org, &$accountErased, &$accountProof): void {
                $account = $this->readers->find($reader, lock: true);
                if ($account !== null && $account->getConnection() !== $host) {
                    throw new ReaderErasureReplayUnavailable('The account changed reader stores during erasure.');
                }
                if ($anchor !== null && ReaderErasureReceipts::anchor($account) !== $anchor) {
                    throw new ReaderErasureReplayUnavailable('The reader changed during erasure.');
                }
                if ($found === null && $account === null) {
                    return;
                }
                if ($found === null || $account === null || $account::class !== $found::class || $account->getTable() !== $found->getTable()
                    || $account->getKeyName() !== $found->getKeyName() || $account->getRawOriginal($account->getKeyName()) !== $found->getRawOriginal($found->getKeyName())) {
                    throw new ReaderErasureReplayUnavailable('The reader account changed before deletion.');
                }

                // Capture host metadata before any deleting/deleted observer. Final readback is raw and invokes no
                // host contract/model callback after modules have proved their retained representations.
                $accountConnection = $account->getConnection();
                $native = $accountConnection->getPdo();
                $table = $account->getTable();
                $keyName = $account->getKeyName();
                $key = $account->getRawOriginal($keyName);
                if (! is_string($key) && ! is_int($key)) {
                    throw new ReaderErasureReplayUnavailable('The reader account has no usable stored key.');
                }
                $accountProof = function () use ($accountConnection, $native, $table, $keyName, $key, $account, $org, $anchor): void {
                    // This exact primary key was owned and captured above. Even a foreign-Org transplant must be
                    // absent; the query returns no row or personal fields from another Org.
                    if ($accountConnection->getPdo() !== $native || $accountConnection->table($table)->useWritePdo()->where($keyName, $key)->exists()
                        || ($anchor !== null && $this->receipts->retainsAccountAnchor($accountConnection, $table, $account::class, $org, $anchor))) {
                        throw new ReaderModuleDataUnavailable('erase');
                    }
                };
                ReaderAccounts::mapped(static fn () => $account->eraseAccount());
                $accountErased = true;
            });
            if ($found !== null && ! $accountErased) {
                throw new ReaderErasureReplayUnavailable('The reader account deletion could not be verified.');
            }
            if ($anchor !== null) {
                if ($proveHost === null) {
                    $this->receipts->record($connection, $org, $anchor);
                } else {
                    $this->receipts->awaitHost($connection, $org, $anchor);
                    $pending = $anchor;
                    // A restore can contain the older core rows and a host that has already removed its account.
                    $this->receipts->recordErased($connection, $org, $grantErasure['anchors'], ReaderErasureReceipts::GRANT, requireComplete: $requireReceipt);
                }
                $this->receipts->recordErased($connection, $org, $registrationErasure['anchors'] ?? [], ReaderErasureReceipts::SIGNUP);
            } elseif ($found === null && ! $accountErased) {
                $this->receipts->recordErased($connection, $org, $grantErasure['anchors'], ReaderErasureReceipts::GRANT, requireComplete: false);
            }

            EntitlementAuthority::mapped(EntitlementRefused::FORGET, null, static function () use ($grantErasure, $recoveryErasure, $registrationErasure, $consentProof, $accountProof): void {
                $accountProof?->__invoke();
                $grantErasure['verify']();
                $consentProof();
                $recoveryErasure['verify']();
                if ($registrationErasure !== null) {
                    $registrationErasure['verify']();
                }
            });

            $proveHost?->__invoke();

            return ['grants' => $grants, 'links' => $links, 'account' => $accountErased];
        });
    }

    /**
     * An address with no account here: the sign-up link waiting for it, if any — authorised as an erasure is.
     *
     * @return int how many links were erased
     *
     * @throws EntitlementRefused
     */
    public function eraseAddress(#[\SensitiveParameter] string $address): int
    {
        return EntitlementAuthority::mapped(EntitlementRefused::FORGET, null, function () use ($address): int {
            $org = $this->authority->org(EntitlementRefused::FORGET);
            $this->authority->authorise(EntitlementRefused::FORGET, null);
            $connection = (new ReaderToken)->getConnection();

            return TransactionRecovery::run($connection, function () use ($connection, $org, $address): int {
                $erasure = $this->tokens->forgetSubjectWithWitness(ReaderTokens::REGISTER, $address);
                $this->receipts->recordErased($connection, $org, $erasure['anchors'], ReaderErasureReceipts::SIGNUP);
                $erasure['verify']();

                return $erasure['erased'];
            });
        });
    }
}
