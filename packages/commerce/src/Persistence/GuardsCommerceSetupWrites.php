<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Persistence;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Kitsune\Commerce\Checkout\CommerceSetupWriter;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Tenancy\Concerns\ReadsWrittenKeys;
use Kitsune\Core\Tenancy\Context;
use RuntimeException;

/** Final writes keep their org mutex and immutable identity even inside the ordinary scope hatch. @internal */
trait GuardsCommerceSetupWrites
{
    use ReadsWrittenKeys;

    private ?object $deletingSetupThrough = null;

    public static function bootGuardsCommerceSetupWrites(): void
    {
        static::addGlobalScope('commerce_setup_coherence', static function (Builder $query): void {
            $org = app(Context::class)->orgId();
            $table = $query->getModel()->getTable();

            if ($org === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->where($table.'.org_id', $org)
                ->whereExists(static fn (QueryBuilder $parent) => $parent->selectRaw('1')->from('orgs')
                    ->whereColumn('orgs.id', $table.'.org_id')->whereNull('orgs.deleted_at'));
        });

        static::creating(function (self $row): void {
            $row->refuseInvalidSetupValues();
            $row->noteGuardedColumnsDerived();
        });
        static::updating(function (self $row): void {
            $row->refuseInvalidSetupValues();
            $row->noteGuardedColumnsDerived();
        });
    }

    public function save(array $options = [])
    {
        return TransactionRecovery::run($this->getConnection(), function () use ($options) {
            $this->refuseRawSetupShape();

            return parent::save($options);
        });
    }

    public function delete()
    {
        if (! CommerceSetupWriter::isRemoving($this)) {
            throw new RuntimeException('Remove commerce configuration through CommerceSetupWriter.');
        }

        return TransactionRecovery::run($this->getConnection(), fn () => parent::delete());
    }

    protected function performDeleteOnModel()
    {
        $query = $this->newModelQuery();
        $this->deletingSetupThrough = $query;

        try {
            $this->setKeysForSaveQuery($query)->delete();
            $this->exists = false;
        } finally {
            $this->deletingSetupThrough = null;
        }
    }

    public function isPerformingSetupDelete(object $through): bool
    {
        return CommerceSetupWriter::isRemoving($this) && $this->deletingSetupThrough === $through;
    }

    public static function setupKey(mixed $value): int
    {
        $key = self::writtenKey($value);

        if ($key === null || $key < 1) {
            throw new RuntimeException('A commerce configuration reference must be a canonical positive integer key.');
        }

        return $key;
    }

    protected function setupOrg(): Org
    {
        $org = app(Context::class)->orgId();

        if ($org === null || self::setupKey($this->getAttributes()['org_id'] ?? null) !== $org) {
            throw new RuntimeException('A commerce configuration row must belong to the org in context.');
        }

        return Org::query()->useWritePdo()->whereKey($org)->lockForUpdate()->first()
            ?? throw new RuntimeException('The commerce configuration org is no longer available.');
    }

    /** @param list<string> $fixed */
    protected function refuseChangedSetupIdentity(array $fixed): void
    {
        $raw = $this->getAttributes();

        if (! $this->exists) {
            if (array_key_exists($this->getKeyName(), $raw)) {
                self::setupKey($raw[$this->getKeyName()]);
            }

            return;
        }

        $id = self::setupKey($this->getRawOriginal($this->getKeyName()));

        if (self::setupKey($raw[$this->getKeyName()] ?? null) !== $id) {
            throw new RuntimeException('A commerce configuration row cannot change its primary key.');
        }

        $stored = $this->getConnection()->table($this->getTable())->useWritePdo()->where($this->getKeyName(), $id)->lockForUpdate()->first();

        if ($stored === null) {
            throw new RuntimeException('The commerce configuration row no longer exists.');
        }

        foreach ($fixed as $column) {
            if (self::setupKey($raw[$column] ?? null) !== self::setupKey($stored->{$column})) {
                throw new RuntimeException('Commerce configuration ownership and product references cannot be changed.');
            }
        }
    }

    public function refuseInvalidSetupRemoval(): void
    {
        $this->setupOrg();
        $this->refuseChangedSetupIdentity(['org_id']);
    }
}
