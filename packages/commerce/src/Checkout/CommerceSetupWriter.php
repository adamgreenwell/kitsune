<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Checkout;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Kitsune\Commerce\Models\CommerceSettings;
use Kitsune\Commerce\Models\SalesPolicy;
use Kitsune\Commerce\Models\Sellable;
use Kitsune\Commerce\Models\TaxRate;
use Kitsune\Commerce\Persistence\CommerceSetupValues;
use Kitsune\Core\Audit\Auditor;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Tenancy\Context;
use RuntimeException;

/**
 * Owner/system configuration, serialized on the current org. Null expectedVersion means creation only; existing rows
 * require their exact positive version, even for a no-op. Audits name only actor, action and target, never addresses.
 * Input maps replace the complete configuration, with explicit nullable origin and digital packing fields.
 *
 * @internal First-party commerce administration; no extension stability promise before v1.2.
 */
final class CommerceSetupWriter
{
    private static TaxRate|SalesPolicy|null $removing = null;

    public function __construct(private readonly Context $context, private readonly Auditor $auditor) {}

    /** @param array<array-key, mixed> $values */
    public function setSettings(#[\SensitiveParameter] array $values, mixed $expectedVersion = null): CommerceSettings
    {
        $this->authorise();
        $attributes = CommerceSettings::values($values);
        $expected = $this->expected($expectedVersion);

        return $this->write(function () use ($attributes, $expected): CommerceSettings {
            $row = CommerceSettings::query()->useWritePdo()->where('org_id', $this->context->orgId())->lockForUpdate()->first();
            $this->checkVersion($row, $expected);

            if ($row !== null && $this->matches($row, $attributes)) {
                $row->refuseInvalidSetupValues();

                return $row;
            }

            $row ??= new CommerceSettings;
            $this->apply($row, $attributes);

            return $this->persist($row, $attributes, 'commerce.settings_saved');
        });
    }

    /** @param array<array-key, mixed> $values */
    public function saveTaxRate(mixed $id, array $values, mixed $expectedVersion = null): TaxRate
    {
        $this->authorise();
        $key = $id === null ? null : TaxRate::setupKey($id);
        $attributes = TaxRate::values($values);
        $expected = $this->expected($expectedVersion);

        return $this->write(function () use ($key, $attributes, $expected): TaxRate {
            $row = $key === null ? null : TaxRate::query()->useWritePdo()->whereKey($key)->lockForUpdate()->first();

            if ($key !== null && $row === null) {
                throw new RuntimeException('The tax rate is unavailable in the current org.');
            }

            $this->checkVersion($row, $expected);

            if ($row !== null && $this->matches($row, $attributes)) {
                $row->refuseInvalidSetupValues();

                return $row;
            }

            $row ??= new TaxRate;
            $this->apply($row, $attributes);

            return $this->persist($row, $attributes, 'commerce.tax_rate_saved');
        });
    }

    public function removeTaxRate(mixed $id, mixed $version): void
    {
        $this->authorise();
        $key = TaxRate::setupKey($id);
        $expected = $this->expected($version);

        if ($expected === null) {
            throw new RuntimeException('Review the current tax rate version before removing it.');
        }

        $this->write(function () use ($key, $expected): void {
            $row = TaxRate::query()->useWritePdo()->whereKey($key)->lockForUpdate()->first()
                ?? throw new RuntimeException('The tax rate is unavailable in the current org.');
            $this->checkVersion($row, $expected);
            $row->refuseInvalidSetupRemoval();
            self::$removing = $row;

            try {
                if ($row->delete() !== true) {
                    throw new RuntimeException('The tax rate removal was cancelled.');
                }
            } finally {
                self::$removing = null;
            }

            $this->record('commerce.tax_rate_removed', $row);
        });
    }

    /** @param array<array-key, mixed> $values */
    public function setSalesPolicy(mixed $sellableId, array $values, mixed $expectedVersion = null): SalesPolicy
    {
        $this->authorise();
        $sellable = SalesPolicy::setupKey($sellableId);
        $attributes = SalesPolicy::values($values);
        $expected = $this->expected($expectedVersion);

        return $this->write(function () use ($sellable, $attributes, $expected): SalesPolicy {
            if (Sellable::query()->useWritePdo()->whereKey($sellable)->where('org_id', $this->context->orgId())->lockForUpdate()->first() === null) {
                throw new RuntimeException('The shared product is unavailable in the current org.');
            }

            $row = SalesPolicy::query()->useWritePdo()->where('org_id', $this->context->orgId())->where('sellable_id', $sellable)->lockForUpdate()->first();
            $this->checkVersion($row, $expected);

            if ($row !== null && $this->matches($row, $attributes)) {
                $row->refuseInvalidSetupValues();

                return $row;
            }

            $row ??= new SalesPolicy(['sellable_id' => $sellable]);
            $this->apply($row, $attributes);

            return $this->persist($row, $attributes, 'commerce.sales_policy_saved');
        });
    }

    public function removeSalesPolicy(mixed $sellableId, mixed $version): void
    {
        $this->authorise();
        $sellable = SalesPolicy::setupKey($sellableId);
        $expected = $this->expected($version);

        if ($expected === null) {
            throw new RuntimeException('Review the current sales policy version before removing it.');
        }

        $this->write(function () use ($sellable, $expected): void {
            $row = SalesPolicy::query()->useWritePdo()->where('org_id', $this->context->orgId())
                ->where('sellable_id', $sellable)->lockForUpdate()->first()
                ?? throw new RuntimeException('The sales policy is unavailable in the current org.');
            $this->checkVersion($row, $expected);
            $row->refuseInvalidSetupRemoval();
            self::$removing = $row;

            try {
                if ($row->delete() !== true) {
                    throw new RuntimeException('The sales policy removal was cancelled.');
                }
            } finally {
                self::$removing = null;
            }

            $this->record('commerce.sales_policy_removed', $row);
        });
    }

    public static function isRemoving(Model $row): bool
    {
        return ($row instanceof TaxRate || $row instanceof SalesPolicy) && self::$removing === $row;
    }

    private function authorise(): void
    {
        if ($this->context->orgId() === null) {
            throw new RuntimeException('Commerce setup requires the current org.');
        }

        $actor = Permissions::currentUser();

        if ($actor !== null && Permissions::isOwner($actor)) {
            $this->enabled();

            return;
        }

        if ($actor !== null || app(ReaderGuard::class)->signedIn()) {
            throw new RuntimeException('Only an org owner or the trusted system may configure commerce.');
        }

        $this->enabled();
    }

    private function enabled(): void
    {
        if (! Module::query()->useWritePdo()->where('handle', 'kitsune/commerce')->where('is_enabled', true)->exists()) {
            throw new RuntimeException('Enable commerce before configuring its calculators.');
        }
    }

    private function expected(mixed $version): ?string
    {
        return $version === null ? null : CommerceSetupValues::integer($version, CommerceSetupValues::MAX_VERSION);
    }

    private function checkVersion(CommerceSettings|TaxRate|SalesPolicy|null $row, ?string $expected): void
    {
        if ($row === null) {
            if ($expected !== null) {
                throw new RuntimeException('Commerce configuration changed; reload it before saving.');
            }

            return;
        }

        $stored = CommerceSetupValues::integer($row->getAttributes()['version'] ?? null, CommerceSetupValues::MAX_VERSION);

        if ($expected === null || $expected !== $stored) {
            throw new RuntimeException('Commerce configuration changed; reload its current version before saving.');
        }
    }

    /** @param array<string, mixed> $attributes */
    private function apply(CommerceSettings|TaxRate|SalesPolicy $row, array $attributes): void
    {
        $version = $row->exists ? CommerceSetupValues::integer($row->getAttributes()['version'] ?? null, CommerceSetupValues::MAX_VERSION) : null;

        if ($version === CommerceSetupValues::MAX_VERSION) {
            throw new RuntimeException('The commerce configuration version is exhausted.');
        }

        $row->forceFill([...$attributes, 'version' => $version === null ? 1 : (int) $version + 1]);
    }

    /** @template T of CommerceSettings|TaxRate|SalesPolicy
     * @param  T  $row
     * @param  array<string, mixed>  $attributes
     * @return T
     */
    private function persist(CommerceSettings|TaxRate|SalesPolicy $row, array $attributes, string $action): CommerceSettings|TaxRate|SalesPolicy
    {
        $version = (string) $row->version;

        if ($row->save() !== true) {
            throw new RuntimeException('The commerce configuration save was cancelled.');
        }

        $stored = $row->newQuery()->useWritePdo()->whereKey($row->getKey())->where('org_id', $this->context->orgId())->lockForUpdate()->first();

        if (! $stored instanceof $row || ! $this->matches($stored, [...$attributes, 'version' => $version])) {
            throw new RuntimeException('The stored commerce configuration differs from the reviewed values.');
        }

        $this->record($action, $stored);

        return $stored;
    }

    /** @param array<string, mixed> $attributes */
    private function matches(CommerceSettings|TaxRate|SalesPolicy $row, array $attributes): bool
    {
        foreach ($attributes as $column => $expected) {
            $stored = $row->getAttribute($column);

            if (is_array($expected)) {
                if (! is_array($stored)) {
                    return false;
                }

                ksort($expected);
                ksort($stored);

                if ($stored !== $expected) {
                    return false;
                }
            } elseif (($stored === null) !== ($expected === null)
                || ($stored !== null && (! is_scalar($stored) || ! is_scalar($expected) || (string) $stored !== (string) $expected))) {
                return false;
            }
        }

        return true;
    }

    private function record(string $action, CommerceSettings|TaxRate|SalesPolicy $row): void
    {
        if (! $this->auditor->recordOrFail($action, $row)->exists) {
            throw new RuntimeException('The commerce configuration audit was cancelled.');
        }
    }

    /** @template T
     * @param  Closure():T  $work
     * @return T
     */
    private function write(Closure $work): mixed
    {
        return TransactionRecovery::run((new CommerceSettings)->getConnection(), function () use ($work): mixed {
            $org = $this->context->orgId();
            $connection = (new CommerceSettings)->getConnection();

            if ($connection->getDriverName() === 'sqlite') {
                $connection->table('orgs')->where('id', $org)->update(['id' => $org]);
            }

            if (Org::query()->useWritePdo()->whereKey($org)->lockForUpdate()->first() === null) {
                throw new RuntimeException('The commerce configuration org is no longer available.');
            }

            Permissions::forget();
            $this->authorise();

            return $work();
        });
    }
}
