<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Readers;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Entitlements\EntitlementRecords;
use Kitsune\Core\Entitlements\EntitlementRefused;
use Kitsune\Core\Readers\Contracts\ReaderAccount;
use Kitsune\Core\Tenancy\Concerns\ReadsWrittenKeys;
use Kitsune\Core\Tenancy\Context;

/**
 * Everything held about one reader of the org in context, for a subject-access request (ADR-020) — the host's account,
 * as its model classifies it, and core's entitlement rows.
 *
 * ⚠️ AUTHORISED AS AN ENTITLEMENT EXPORT IS, AND FIRST: an owner or the system, never a reader. The entitlement rows are
 * read before the account, so a refusal reads nothing of the host's.
 *
 * ⚠️ NO SECRET, EVER. The account is `exportAccount()`, which the contract forbids to carry a password hash or remember
 * token; core adds no column of its own.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class ReaderExport
{
    use ReadsWrittenKeys;

    public function __construct(
        private readonly ReaderAccounts $accounts,
        private readonly Context $context,
    ) {}

    /**
     * @return array{account: array<string, scalar|null>|null, entitlements: list<array<string, ?string>>, generated_at: string}
     *
     * @throws EntitlementRefused
     */
    public function for(#[\SensitiveParameter] int|string $reader): array
    {
        $entitlements = EntitlementRecords::forReader($reader);
        $account = $this->find($reader);

        return [
            'account' => $account?->exportAccount(),
            'entitlements' => $entitlements,
            'generated_at' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * The org-in-context's reader with this key, through the model's own org-scoped query and fenced on the row's
     * `org_id` as well — or null.
     */
    public function find(#[\SensitiveParameter] int|string $reader, bool $lock = false): (Model&Authenticatable&ReaderAccount)|null
    {
        $model = $this->accounts->model();
        $orgId = $this->context->orgId();
        $key = $model !== null ? Permissions::userKey($reader, $model) : null;

        if ($model === null || $orgId === null || $key === null) {
            return null;
        }

        $found = ReaderAccounts::mapped(static function () use ($model, $key, $lock): ?Model {
            $query = $model::query()->whereKey($key);

            return ($lock ? $query->lockForUpdate() : $query)->first();
        });

        return $found instanceof ReaderAccount
            && $found instanceof Authenticatable
            && self::writtenKey($found->getAttributes()['org_id'] ?? null) === $orgId
            ? $found
            : null;
    }
}
