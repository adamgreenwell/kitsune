<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Persistence;

use Kitsune\Commerce\Catalogue\CatalogueWriter;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Tenancy\Concerns\ReadsWrittenKeys;
use Kitsune\Core\Tenancy\Context;
use RuntimeException;

/** Shared locks and identity checks for the two catalogue models. @internal */
trait GuardsCatalogueWrites
{
    use ReadsWrittenKeys;

    private ?object $deletingCatalogueThrough = null;

    /** Keep the org and reference locks until the actual instance write has finished. */
    public function save(array $options = [])
    {
        return TransactionRecovery::run($this->getConnection(), function () use ($options) {
            // Laravel compares cast attributes before `updating`; validate the raw shape before that comparison.
            $this->refuseRawCatalogueShape();

            return parent::save($options);
        });
    }

    /** The writer alone removes rows; bulk deletion never inherits that permission. */
    public function delete()
    {
        if (! CatalogueWriter::isRemoving($this)) {
            throw new RuntimeException('Remove catalogue rows through CatalogueWriter.');
        }

        return parent::delete();
    }

    protected function performDeleteOnModel()
    {
        $query = $this->newModelQuery();
        $this->deletingCatalogueThrough = $query;

        try {
            $this->setKeysForSaveQuery($query)->delete();
            $this->exists = false;
        } finally {
            $this->deletingCatalogueThrough = null;
        }
    }

    public function isPerformingCatalogueDelete(object $through): bool
    {
        return $this->deletingCatalogueThrough === $through;
    }

    /** A canonical positive database key, before a driver can coerce a fractional value. */
    public static function catalogueKey(mixed $value): int
    {
        $key = self::writtenKey($value);

        if ($key === null || $key < 1) {
            throw new RuntimeException('A catalogue reference must be a canonical positive integer key.');
        }

        return $key;
    }

    /** The authoritative org is the mutex shared with its currency changes. */
    protected function catalogueOrg(): Org
    {
        $orgId = app(Context::class)->orgId();
        $raw = $this->getAttributes();

        if ($orgId === null || self::catalogueKey($raw['org_id'] ?? null) !== $orgId) {
            throw new RuntimeException('The catalogue row must belong to the org in context.');
        }

        return Org::query()->useWritePdo()->whereKey($orgId)->lockForUpdate()->first()
            ?? throw new RuntimeException('The catalogue org is no longer available.');
    }

    /**
     * An instance may be stale, caller-constructed, or have had its primary key changed. Compare with the row that
     * will actually be written, not just the original attributes on the caller's object.
     *
     * @param  list<string>  $fixed
     */
    protected function refuseChangedCatalogueIdentity(array $fixed): void
    {
        if (! $this->exists) {
            return;
        }

        $raw = $this->getAttributes();
        $id = self::catalogueKey($this->getRawOriginal($this->getKeyName()));

        if (self::catalogueKey($raw[$this->getKeyName()] ?? null) !== $id) {
            throw new RuntimeException('A catalogue row cannot change its primary key.');
        }

        $stored = $this->getConnection()->table($this->getTable())->useWritePdo()
            ->where($this->getKeyName(), $id)->lockForUpdate()->first();

        if ($stored === null) {
            throw new RuntimeException('The catalogue row no longer exists.');
        }

        foreach ($fixed as $column) {
            if ((string) ($raw[$column] ?? '') !== (string) $stored->{$column}) {
                throw new RuntimeException('Catalogue ownership and references cannot be changed; remove the listing first.');
            }
        }
    }
}
