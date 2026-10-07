<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Readers;

use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Entitlements\EntitlementRefused;
use Kitsune\Core\Entitlements\EntitlementWriter;
use Kitsune\Core\Models\Entitlement;

/**
 * One reader of the org in context, erased from every store core knows of (ADR-020) — their entitlements on every site
 * of the org, then the host's account.
 *
 * ⚠️ ONE TRANSACTION, ENTITLEMENTS FIRST. `EntitlementWriter::forget()` authorises (an owner or the system), locks and
 * audits each row as it does alone, nested; then the account is loaded and `eraseAccount()` runs. A failure anywhere
 * rolls back everything, so a reader is never left half-erased: no account with their grants gone, and no grants left
 * on an account that is.
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
    ) {}

    /**
     * @return array{grants: int, account: bool} how many grants were erased, and whether an account was
     *
     * @throws EntitlementRefused
     */
    public function erase(#[\SensitiveParameter] int|string $reader): array
    {
        return TransactionRecovery::run((new Entitlement)->getConnection(), function () use ($reader): array {
            $grants = $this->entitlements->forget($reader);
            $account = $this->readers->find($reader, lock: true);

            if ($account !== null) {
                ReaderAccounts::mapped(static fn () => $account->eraseAccount());
            }

            return ['grants' => $grants, 'account' => $account !== null];
        });
    }
}
