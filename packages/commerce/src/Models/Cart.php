<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Models;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Kitsune\Commerce\Carts\CartOwner;
use Kitsune\Commerce\Persistence\GuardedCartBuilder;
use Kitsune\Commerce\Persistence\GuardsCartWrites;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Entitlements\UtcInstant;
use Kitsune\Core\Tenancy\Attributes\SiteScoped;
use Kitsune\Core\Tenancy\Concerns\DerivesGuardedColumns;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tenancy\Contracts\RequiresModelSave;
use RuntimeException;

/**
 * An exact site's saved selection. Ownership never moves; sign-in copies selections into the reader's own cart.
 *
 * @internal
 *
 * @property int $id
 * @property int $org_id
 * @property int $site_id
 * @property string|null $reader_id
 * @property string $owner_key
 * @property int $version
 * @property CarbonImmutable $expires_at
 */
#[SiteScoped]
final class Cart extends Model implements RequiresModelSave
{
    use DerivesGuardedColumns;
    use EnforcesScope;
    use GuardsCartWrites;

    protected $table = 'commerce_carts';

    protected $guarded = [];

    protected $attributes = ['version' => 0, 'reader_id' => null];

    protected $casts = ['version' => 'integer', 'expires_at' => UtcInstant::class];

    /** @return array<string, string> */
    public static function columnsRequiringModelSave(): array
    {
        // DerivesGuardedColumns snapshots scalars. The instant cast returns an object; the final builder still
        // checks its raw UTC representation on every write, and refuses every detached/quiet update unconditionally.
        return array_fill_keys(['id', 'org_id', 'site_id', 'reader_id', 'owner_key', 'version'], 'Cart values require a checked instance save.');
    }

    protected static function booted(): void
    {
        self::addGlobalScope('cart_coherence', static function (Builder $query): void {
            $orgId = app(Context::class)->orgId();
            $siteId = app(Context::class)->siteId();

            if ($orgId === null || $siteId === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            // SiteScope admits org-shared rows and its site branch compares no org; carts admit neither.
            $query->where('commerce_carts.org_id', $orgId)->where('commerce_carts.site_id', $siteId)
                ->whereExists(static fn (QueryBuilder $site) => $site->selectRaw('1')->from('sites')
                    ->whereColumn('sites.id', 'commerce_carts.site_id')
                    ->whereColumn('sites.org_id', 'commerce_carts.org_id'));
        });

        self::creating(function (self $row): void {
            $row->refuseInvalidCartValues();
            $row->noteGuardedColumnsDerived();
        });
        self::updating(function (self $row): void {
            $row->refuseInvalidCartValues();
            $row->noteGuardedColumnsDerived();
        });
    }

    public function refuseInvalidCartValues(): void
    {
        $this->refuseRawCartShape();
        $this->refuseInvalidCartScope();
        $this->refuseChangedCartIdentity(['org_id', 'site_id', 'reader_id', 'owner_key']);
    }

    protected function refuseRawCartShape(): void
    {
        $raw = $this->getAttributes();
        $reader = $raw['reader_id'] ?? null;
        $owner = $raw['owner_key'] ?? null;
        $version = self::writtenKey($raw['version'] ?? null);

        if ($reader !== null && (! is_string($reader) || ReaderGuard::stored($reader) !== $reader)) {
            throw new RuntimeException('A cart reader key must be exact printable ASCII of at most 255 bytes.');
        }

        if (! is_string($owner) || preg_match('/\A[a-f0-9]{64}\z/D', $owner) !== 1
            || ($reader !== null && ! hash_equals(CartOwner::readerKey($reader), $owner))) {
            throw new RuntimeException('A cart owner key must be a canonical domain-separated owner digest.');
        }

        if ($version === null || $version < 0 || $version > 4294967295) {
            throw new RuntimeException('A cart version must be a canonical unsigned 32-bit integer.');
        }

        self::expiryValue($raw['expires_at'] ?? null);
    }

    /** Check the exact storage representation before an instant cast can normalize a malformed SQLite value. */
    public static function expiryValue(mixed $value): string
    {
        $instant = is_string($value) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D', $value) === 1
            ? DateTimeImmutable::createFromFormat('!'.UtcInstant::FORMAT, $value, new DateTimeZone('UTC'))
            : false;

        if (! is_string($value) || $instant === false || $instant->format(UtcInstant::FORMAT) !== $value
            || $instant->format('Y') < '1000') {
            throw new RuntimeException('A cart expiration must be a valid UTC instant in whole seconds.');
        }

        return $value;
    }

    public function refuseInvalidCartRemoval(): void
    {
        $this->refuseInvalidCartScope();
        $this->refuseChangedCartIdentity(['org_id', 'site_id', 'reader_id', 'owner_key']);
    }

    /** @return HasMany<CartLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(CartLine::class, 'cart_id')->where('commerce_cart_lines.org_id', $this->org_id)
            ->where('commerce_cart_lines.site_id', $this->site_id);
    }

    /** @param QueryBuilder $query
     * @return GuardedCartBuilder<$this>
     */
    public function newEloquentBuilder($query): GuardedCartBuilder
    {
        return new GuardedCartBuilder($query, $this);
    }
}
