<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Readers;

use Kitsune\Core\Auth\EmailAddress;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Entitlements\EntitlementAuthority;
use Kitsune\Core\Entitlements\EntitlementRefused;
use Kitsune\Core\Entitlements\EntitlementWriter;
use Kitsune\Core\Models\Entitlement;

/**
 * One reader of the org in context, erased from every store core knows of (ADR-020) — their entitlements on every site
 * of the org, then any link waiting in their mail, then the host's account.
 *
 * ⚠️ ONE TRANSACTION, ENTITLEMENTS FIRST, THE ACCOUNT LAST. `EntitlementWriter::forget()` authorises (an owner or the
 * system), locks and audits each row as it does alone, nested; then the reader's links go — the recovery link by their
 * key, a sign-up link by their address, normalised, because the contract's address is "as stored" and a host's model need
 * not lower-case it — then the account is locked and `eraseAccount()` runs. Link rows before the reader's row, the one
 * order `ReaderLinkUse` takes them in too. A failure anywhere rolls back everything, so a reader is never left
 * half-erased: no account with their grants gone, and no grants left on an account that is.
 *
 * ⚠️ AN ADDRESS WITH NO ACCOUNT can still be erased (`eraseAddress()`): someone asked for a sign-up link and never used
 * it, and the address is all that is held.
 *
 * ⚠️ ON THE ENTITLEMENTS' CONNECTION. A host whose reader model lives on another database gets the two halves in two
 * transactions; the order still means the account goes last.
 *
 * ⚠️ IDEMPOTENT, AND THE HOST'S DELETION IS NOT ENOUGH. A second run erases nothing and says so. A reader the host
 * deleted with a bare `delete()` still has their entitlements, which this erases by key.
 *
 * Before v1.1's erasure log, an operator re-runs `erase` after restoring a backup, as `forget` documents.
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
    ) {}

    /**
     * @return array{grants: int, links: int, account: bool} how many grants and links were erased, and whether an account was
     *
     * @throws EntitlementRefused
     */
    public function erase(#[\SensitiveParameter] int|string $reader): array
    {
        return TransactionRecovery::run((new Entitlement)->getConnection(), function () use ($reader): array {
            $grants = $this->entitlements->forget($reader);
            $found = $this->readers->find($reader);
            $address = $found !== null ? EmailAddress::normalise($found->readerEmail()) : null;

            $links = $this->tokens->forgetSubject(ReaderTokens::RECOVER, $this->authority->filedKey(EntitlementRefused::FORGET, $reader))
                + ($address !== null ? $this->tokens->forgetSubject(ReaderTokens::REGISTER, $address) : 0);

            $account = $this->readers->find($reader, lock: true);

            if ($account !== null) {
                ReaderAccounts::mapped(static fn () => $account->eraseAccount());
            }

            return ['grants' => $grants, 'links' => $links, 'account' => $account !== null];
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
            $this->authority->org(EntitlementRefused::FORGET);
            $this->authority->authorise(EntitlementRefused::FORGET, null);

            return $this->tokens->forgetSubject(ReaderTokens::REGISTER, $address);
        });
    }
}
