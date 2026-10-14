<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Kitsune\Commerce\Persistence\GuardedCartBuilder;
use Kitsune\Commerce\Persistence\GuardsCartWrites;
use Kitsune\Core\Tenancy\Attributes\SiteScoped;
use Kitsune\Core\Tenancy\Concerns\DerivesGuardedColumns;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tenancy\Contracts\RequiresModelSave;
use RuntimeException;

/**
 * A saved site listing and quantity, retaining a removable NULL reference after that listing is deleted.
 * No prices, stock reservations, product content or access grants are copied into cart storage.
 *
 * @internal
 *
 * @property int $id
 * @property int $org_id
 * @property int $site_id
 * @property int $cart_id
 * @property int|null $listing_id
 * @property int $quantity
 */
#[SiteScoped]
final class CartLine extends Model implements RequiresModelSave
{
    use DerivesGuardedColumns;
    use EnforcesScope;
    use GuardsCartWrites;

    protected $table = 'commerce_cart_lines';

    protected $guarded = [];

    protected $casts = ['quantity' => 'integer'];

    /** @return array<string, string> */
    public static function columnsRequiringModelSave(): array
    {
        return array_fill_keys(['id', 'org_id', 'site_id', 'cart_id', 'listing_id', 'quantity'], 'Cart line values require a checked instance save.');
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

            $query->where('commerce_cart_lines.org_id', $orgId)->where('commerce_cart_lines.site_id', $siteId)
                ->whereExists(static fn (QueryBuilder $site) => $site->selectRaw('1')->from('sites')
                    ->whereColumn('sites.id', 'commerce_cart_lines.site_id')
                    ->whereColumn('sites.org_id', 'commerce_cart_lines.org_id'))
                ->whereExists(static fn (QueryBuilder $cart) => $cart->selectRaw('1')->from('commerce_carts')
                    ->whereColumn('commerce_carts.id', 'commerce_cart_lines.cart_id')
                    ->whereColumn('commerce_carts.org_id', 'commerce_cart_lines.org_id')
                    ->whereColumn('commerce_carts.site_id', 'commerce_cart_lines.site_id'))
                ->where(static fn (Builder $listing) => $listing->whereNull('commerce_cart_lines.listing_id')
                    ->orWhereExists(static fn (QueryBuilder $parent) => $parent->selectRaw('1')->from('commerce_listings')
                        ->whereColumn('commerce_listings.id', 'commerce_cart_lines.listing_id')
                        ->whereColumn('commerce_listings.org_id', 'commerce_cart_lines.org_id')
                        ->whereColumn('commerce_listings.site_id', 'commerce_cart_lines.site_id')));
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
        $this->refuseChangedCartIdentity(['org_id', 'site_id', 'cart_id', 'listing_id']);
        $raw = $this->getAttributes();
        $cart = Cart::query()->useWritePdo()->whereKey(self::cartKey($raw['cart_id'] ?? null))->lockForUpdate()->first();

        if ($cart === null) {
            throw new RuntimeException('A cart line requires its exact site\'s cart.');
        }

        if (($raw['listing_id'] ?? null) !== null) {
            // Retain unavailable content for notices and removal; the writer separately checks public buyability.
            $listing = $this->getConnection()->table('commerce_listings')->useWritePdo()
                ->where('id', self::cartKey($raw['listing_id']))->where('org_id', $raw['org_id'])
                ->where('site_id', $raw['site_id'])->lockForUpdate()->first();

            if ($listing === null) {
                throw new RuntimeException('A cart line requires a listing from its exact org and site.');
            }
        }
    }

    protected function refuseRawCartShape(): void
    {
        $raw = $this->getAttributes();
        self::cartKey($raw['cart_id'] ?? null);

        if (($raw['listing_id'] ?? null) !== null) {
            self::cartKey($raw['listing_id']);
        }

        $quantity = self::writtenKey($raw['quantity'] ?? null);

        if ($quantity === null || $quantity < 1 || $quantity > 99) {
            throw new RuntimeException('A cart quantity must be a canonical integer from 1 to 99.');
        }
    }

    /** Broken endpoints cannot trap a row its exact site needs to remove. */
    public function refuseInvalidCartRemoval(): void
    {
        $this->refuseInvalidCartScope();
        $this->refuseChangedCartIdentity(['org_id', 'site_id', 'cart_id', 'listing_id']);
    }

    /** @return BelongsTo<Cart, $this> */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class)->where('commerce_carts.org_id', $this->org_id)->where('commerce_carts.site_id', $this->site_id);
    }

    /** @return BelongsTo<Listing, $this> */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class)->where('commerce_listings.org_id', $this->org_id)->where('commerce_listings.site_id', $this->site_id);
    }

    /** @param QueryBuilder $query
     * @return GuardedCartBuilder<$this>
     */
    public function newEloquentBuilder($query): GuardedCartBuilder
    {
        return new GuardedCartBuilder($query, $this);
    }
}
