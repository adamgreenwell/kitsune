<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Catalogue;

use Illuminate\Database\QueryException;
use Kitsune\Core\Audit\Auditor;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Currency\Currencies;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Tenancy\Context;
use RuntimeException;

/**
 * Selects the current org's currency, under the same Org lock the first sellable takes (ADR-040).
 *
 * No conversion: retained sellables hold a database foreign-key witness of the selected currency. That refusal
 * remains effective when this optional module is disabled or absent. Supported-code validation belongs to core.
 *
 * Like CredentialWriter, a signed-in caller must own the current org; a null actor means the system acting.
 * Every web entry point must require a signed-in owner before calling this service.
 *
 * @internal First-party only; no extension contract before v1.2.
 */
final class OrgCurrency
{
    public const CHANGED = 'commerce.currency_changed';

    public function __construct(
        private readonly Context $context,
        private readonly Auditor $auditor,
    ) {}

    /** Null leaves the currency unselected; an existing foreign-key witness may refuse clearing it too. */
    public function set(mixed $currency): Org
    {
        $orgId = $this->context->orgId() ?? throw new RuntimeException('Choose an organisation before selecting its currency.');
        $actor = Permissions::currentUser();

        if ($actor !== null && ! Permissions::isOwner($actor)) {
            throw new RuntimeException('Only an owner of the current organisation may select its currency.');
        }

        if (! Module::isEnabled('kitsune/commerce')) {
            throw new RuntimeException('Enable commerce before selecting its catalogue currency.');
        }

        if ($currency !== null && ! Currencies::supports($currency)) {
            throw new RuntimeException('Choose a supported uppercase ISO 4217 currency code.');
        }

        return TransactionRecovery::run((new Org)->getConnection(), function () use ($orgId, $currency): Org {
            // Org is unscoped, so the context key is explicit and no caller can supply a different target.
            $org = Org::query()->whereKey($orgId)->lockForUpdate()->first();

            if (! $org instanceof Org) {
                throw new RuntimeException('The current organisation no longer exists.');
            }

            if ($org->currency === $currency) {
                return $org;
            }

            $org->currency = $currency;

            self::save($org);

            $stored = Org::query()->whereKey($orgId)->first();

            if (! $stored instanceof Org || $stored->currency !== $currency) {
                throw new RuntimeException('The currency was not changed: the stored value differs from the selection.');
            }

            if ($this->context->orgId() !== $orgId) {
                throw new RuntimeException('The currency was not changed: the organisation context changed during the save.');
            }

            $audit = $this->auditor->recordOrFail(self::CHANGED, $stored);

            if (! $audit->exists) {
                throw new RuntimeException('The currency was not changed: a listener cancelled its audit record.');
            }

            return $stored;
        });
    }

    private static function save(Org $org): void
    {
        try {
            if (! $org->save()) {
                throw new RuntimeException('The currency was not changed: a listener cancelled the save.');
            }
        } catch (QueryException $failure) {
            // These are the engines' parent-row foreign-key refusals, not a blanket reclassification of DB errors.
            $state = (string) ($failure->errorInfo[0] ?? '');
            $code = (int) ($failure->errorInfo[1] ?? 0);
            $foreignKey = $state === '23503' || $code === 1451 || $code === 787
                || ($code === 19 && str_contains($failure->getMessage(), 'FOREIGN KEY constraint failed'));

            if ($foreignKey) {
                throw new RuntimeException('Existing commerce records hold the organisation currency; it cannot be changed.', previous: $failure);
            }

            throw $failure;
        }
    }
}
