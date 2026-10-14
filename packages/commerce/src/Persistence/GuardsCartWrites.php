<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Persistence;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Entitlements\UtcInstant;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Concerns\ReadsWrittenKeys;
use Kitsune\Core\Tenancy\Context;
use RuntimeException;

/** Instance writes hold their scope locks and recheck identity at the database boundary. @internal */
trait GuardsCartWrites
{
    use ReadsWrittenKeys;

    private ?object $deletingCartThrough = null;

    private bool $cartDeletionChecked = false;

    public function freshTimestamp(): Carbon
    {
        return Carbon::instance(CarbonImmutable::now('UTC'));
    }

    /** Timestamp columns pass through Eloquent's date conversion before class casts; mutators run before it. */
    public function setCreatedAtAttribute(mixed $value): void
    {
        $this->attributes['created_at'] = $this->cartTimestamp($value);
    }

    public function setUpdatedAtAttribute(mixed $value): void
    {
        $this->attributes['updated_at'] = $this->cartTimestamp($value);
    }

    public function getCreatedAtAttribute(mixed $value): ?CarbonImmutable
    {
        return (new UtcInstant)->get($this, 'created_at', $value, $this->getAttributes());
    }

    public function getUpdatedAtAttribute(mixed $value): ?CarbonImmutable
    {
        return (new UtcInstant)->get($this, 'updated_at', $value, $this->getAttributes());
    }

    private function cartTimestamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Eloquent's builder fills a missing dirty timestamp from freshTimestampString(), then runs the mutator
        // on a new instance. Its exact UTC storage spelling is safe; arbitrary date strings still are not.
        if (is_string($value) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D', $value) === 1) {
            $instant = DateTimeImmutable::createFromFormat('!'.UtcInstant::FORMAT, $value, new DateTimeZone('UTC'));

            if ($instant !== false && $instant->format(UtcInstant::FORMAT) === $value && $instant->format('Y') >= '1000') {
                return $value;
            }
        }

        if (! $value instanceof DateTimeInterface) {
            throw new RuntimeException('Cart activity timestamps must be instants or exact UTC storage values.');
        }

        return UtcInstant::stored($value);
    }

    public function save(array $options = [])
    {
        return TransactionRecovery::run($this->getConnection(), function () use ($options) {
            // Dirty comparisons may consult casts before an updating observer can validate the raw values.
            $this->refuseRawCartShape();

            return parent::save($options);
        });
    }

    public static function bootGuardsCartWrites(): void
    {
        static::deleting(function (self $row): void {
            $row->refuseInvalidCartRemoval();
            $row->cartDeletionChecked = true;
        });
    }

    /** Checked evented removal only; a failed attempt cannot arm a subsequent quiet deletion. */
    public function delete()
    {
        return TransactionRecovery::run($this->getConnection(), function () {
            $this->cartDeletionChecked = false;

            try {
                return parent::delete();
            } finally {
                $this->cartDeletionChecked = false;
            }
        });
    }

    protected function performDeleteOnModel()
    {
        $query = $this->newModelQuery();
        $this->deletingCartThrough = $query;

        try {
            $this->setKeysForSaveQuery($query)->delete();
            $this->exists = false;
        } finally {
            $this->deletingCartThrough = null;
        }
    }

    public function isPerformingCartDelete(object $through): bool
    {
        return $this->cartDeletionChecked && $this->deletingCartThrough === $through;
    }

    public static function cartKey(mixed $value): int
    {
        $key = self::writtenKey($value);

        if ($key === null || $key < 1) {
            throw new RuntimeException('A cart reference must be a canonical positive integer key.');
        }

        return $key;
    }

    /** Org then Site is the shared lock order for cart writers and their checked model writes. */
    protected function refuseInvalidCartScope(): void
    {
        $context = app(Context::class);
        $orgId = $context->orgId();
        $siteId = $context->siteId();
        $raw = $this->getAttributes();

        if ($orgId === null || $siteId === null || self::cartKey($raw['org_id'] ?? null) !== $orgId
            || self::cartKey($raw['site_id'] ?? null) !== $siteId) {
            throw new RuntimeException('A cart row must belong to the exact org and site in context.');
        }

        $org = Org::query()->useWritePdo()->whereKey($orgId)->lockForUpdate()->first();
        $site = Site::query()->useWritePdo()->whereKey($siteId)->where('org_id', $orgId)->lockForUpdate()->first();

        if ($org === null || $site === null) {
            throw new RuntimeException('The cart org or site is no longer available.');
        }
    }

    /** Compare with the stored row, including for constructed instances and stale original attributes.
     * @param  list<string>  $fixed
     */
    protected function refuseChangedCartIdentity(array $fixed): void
    {
        $raw = $this->getAttributes();

        if (! $this->exists) {
            if (array_key_exists($this->getKeyName(), $raw)) {
                self::cartKey($raw[$this->getKeyName()]);
            }

            return;
        }

        $id = self::cartKey($this->getRawOriginal($this->getKeyName()));

        if (self::cartKey($raw[$this->getKeyName()] ?? null) !== $id) {
            throw new RuntimeException('A cart row cannot change its primary key.');
        }

        $stored = $this->getConnection()->table($this->getTable())->useWritePdo()
            ->where($this->getKeyName(), $id)->lockForUpdate()->first();

        if ($stored === null) {
            throw new RuntimeException('The cart row no longer exists.');
        }

        foreach ($fixed as $column) {
            $value = $raw[$column] ?? null;

            if (($value === null) !== ($stored->{$column} === null)
                || ($value !== null && (string) $value !== (string) $stored->{$column})) {
                throw new RuntimeException('Cart ownership and references cannot be changed.');
            }
        }
    }
}
