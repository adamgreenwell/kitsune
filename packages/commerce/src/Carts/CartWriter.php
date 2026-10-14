<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Carts;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Kitsune\Commerce\Catalogue\PublicCatalogue;
use Kitsune\Commerce\Models\Cart;
use Kitsune\Commerce\Models\CartLine;
use Kitsune\Commerce\Money\MinorUnits;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/** Current-session cart writes, serialized on the owning org and site; never a price quote or reservation. @internal */
final class CartWriter
{
    public const MAX_LINES = 20;

    public const MAX_QUANTITY = 99;

    public const DAYS = 30;

    public function __construct(private readonly Context $context, private readonly PublicCatalogue $catalogue) {}

    /**
     * @return array{version:int,items:list<array{lineId:int,title:string,quantity:int,available:bool,currency:?string,price:?string,lineTotal:?string,stock:?string,imageUrl:?string}>,currency:?string,total:?string,mergeBlocked:bool,isReader:bool}
     */
    public function snapshot(Request $request): array
    {
        $blocked = app(CartSessions::class)->retryPending($request);
        $owner = CartOwner::current($request);
        $empty = ['version' => 0, 'items' => [], 'currency' => null, 'total' => null, 'mergeBlocked' => $blocked, 'isReader' => $owner?->reader !== null];

        if ($owner === null) {
            return $empty;
        }

        $this->enabled();
        $cart = $this->cart($owner);

        if ($cart === null || $cart->expires_at->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
            return $empty;
        }

        $lines = $this->lines($cart);
        $products = $this->products($lines);
        $items = [];
        $total = '0';
        $currency = null;
        $complete = true;

        foreach ($lines as $line) {
            $product = $products[(int) $line->listing_id] ?? null;
            $available = $product !== null && self::hasStock($product['stock'], (int) $line->quantity);
            $lineTotal = null;

            if ($available) {
                try {
                    $amount = CartAmounts::multiply($product['priceMinor'], (int) $line->quantity);
                    $total = CartAmounts::add($total, $amount);
                    $lineTotal = MinorUnits::format($amount, $product['currency']);
                    $currency = $product['currency'];
                } catch (InvalidArgumentException) {
                    $available = false;
                }
            }

            $complete = $complete && $available;
            $items[] = [
                'lineId' => (int) $line->getKey(), 'title' => $product['title'] ?? '', 'quantity' => (int) $line->quantity,
                'available' => $available, 'currency' => $product['currency'] ?? null, 'price' => $product['price'] ?? null,
                'lineTotal' => $lineTotal, 'stock' => $product['stock'] ?? null, 'imageUrl' => $product['imageUrl'] ?? null,
            ];
        }

        return [
            'version' => (int) $cart->version, 'items' => $items, 'currency' => $currency,
            'total' => $complete && $currency !== null ? MinorUnits::format($total, $currency) : null,
            'mergeBlocked' => $blocked, 'isReader' => $owner->reader !== null,
        ];
    }

    /** Repeated Add requests keep the greater quantity; they never increment it. */
    public function add(Request $request, mixed $listing, mixed $quantity): void
    {
        $listing = self::integer($listing, PHP_INT_MAX);
        $quantity = self::integer($quantity, self::MAX_QUANTITY);
        $owner = CartOwner::current($request, true);

        if ($owner === null) {
            throw new CartRefused('unavailable');
        }

        $this->write(function () use ($owner, $listing, $quantity): void {
            $cart = $this->cart($owner, true);
            $lines = $this->lines($cart);
            $found = collect($lines)->first(static fn (CartLine $line): bool => (int) $line->listing_id === $listing);
            $desired = max($quantity, $found !== null ? (int) $found->quantity : 0);
            $this->admit($listing, $desired);

            if ($found !== null && $desired === (int) $found->quantity) {
                return;
            }

            if ($found === null && count($lines) >= self::MAX_LINES) {
                throw new CartRefused('limit');
            }

            $line = $found ?? new CartLine(['org_id' => $owner->orgId, 'site_id' => $owner->siteId, 'cart_id' => $cart->getKey(), 'listing_id' => $listing]);
            $line->quantity = $desired;
            $this->saveRow($line);
            $this->validateTotal($this->lines($cart));
            $this->changed($cart);
        });
    }

    /** Absolute quantity plus a cart revision prevents a stale tab from silently replacing newer state. */
    public function update(Request $request, mixed $line, mixed $quantity, mixed $version): void
    {
        $line = self::integer($line, PHP_INT_MAX);
        $quantity = self::integer($quantity, self::MAX_QUANTITY);
        $version = self::integer($version, 4294967295, true);
        $owner = CartOwner::current($request);

        $this->write(function () use ($owner, $line, $quantity, $version): void {
            $cart = $owner !== null ? $this->cart($owner) : null;
            $found = $cart !== null ? collect($this->lines($cart))->first(static fn (CartLine $row): bool => (int) $row->getKey() === $line) : null;

            if ($cart === null || $found === null || $cart->expires_at->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
                throw new CartRefused('unavailable');
            }

            // An exact replay is a no-op, including expiry; it cannot overwrite newer quantities.
            if ((int) $found->quantity === $quantity) {
                return;
            }

            if ((int) $cart->version !== $version) {
                throw new CartRefused('stale');
            }

            $this->admit((int) $found->listing_id, $quantity);
            $found->quantity = $quantity;
            $this->saveRow($found);
            $this->validateTotal($this->lines($cart));
            $this->changed($cart);
        });
    }

    /** Missing owned lines are an idempotent no-op; existing ones require the observed revision. */
    public function remove(Request $request, mixed $line, mixed $version): void
    {
        $line = self::integer($line, PHP_INT_MAX);
        $version = self::integer($version, 4294967295, true);
        $owner = CartOwner::current($request);

        $this->write(function () use ($owner, $line, $version): void {
            $cart = $owner !== null ? $this->cart($owner) : null;
            $found = $cart !== null ? collect($this->lines($cart))->first(static fn (CartLine $row): bool => (int) $row->getKey() === $line) : null;

            if ($cart === null || $found === null) {
                return;
            }

            if ($cart->expires_at->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
                $this->deleteRow($cart);

                return;
            }

            if ((int) $cart->version !== $version) {
                throw new CartRefused('stale');
            }

            $this->deleteRow($found);
            $this->changed($cart);
        });
    }

    /** Called only after a validated reader Login on this exact site, or a retry explicitly marked by that event. */
    public function adopt(Session $session, #[\SensitiveParameter] string $reader): void
    {
        $org = $this->context->orgId();
        $site = $this->context->siteId();

        if ($org === null || $site === null || app(ReaderGuard::class)->canonical($reader) !== $reader) {
            throw new CartRefused('unavailable');
        }

        $guest = CartOwner::anonymous($session, $org, $site);

        if ($guest === null) {
            $session->forget(CartSessions::pendingKey($org, $site));

            return;
        }

        $this->write(function () use ($session, $reader, $guest, $org, $site): void {
            $source = $this->cart($guest);

            if ($source !== null && $source->expires_at->greaterThan(CarbonImmutable::now('UTC'))) {
                $target = $this->cart(new CartOwner($org, $site, CartOwner::readerKey($reader), $reader), true);
                $old = $this->lines($target);
                $incoming = $this->lines($source);
                $byListing = [];

                foreach ($old as $line) {
                    if ($line->listing_id !== null) {
                        $byListing[(int) $line->listing_id] = $line;
                    }
                }

                $extra = count(array_filter($incoming, static fn (CartLine $line): bool => $line->listing_id === null || ! isset($byListing[(int) $line->listing_id])));

                if (count($old) + $extra > self::MAX_LINES) {
                    throw new CartRefused('limit');
                }

                $changed = false;

                foreach ($incoming as $line) {
                    $match = $line->listing_id !== null ? ($byListing[(int) $line->listing_id] ?? null) : null;
                    $quantity = max((int) $line->quantity, $match !== null ? (int) $match->quantity : 0);

                    if ($match !== null && (int) $match->quantity === $quantity) {
                        continue;
                    }

                    $copy = $match ?? new CartLine(['org_id' => $org, 'site_id' => $site, 'cart_id' => $target->getKey(), 'listing_id' => $line->listing_id]);
                    $copy->quantity = $quantity;
                    $this->saveRow($copy);
                    $changed = true;
                }

                $this->validateTotal($this->lines($target));

                if ($changed) {
                    $this->changed($target);
                }
            }

            if ($source !== null) {
                $this->deleteRow($source);
            }
            // A host may wrap Login in a larger transaction. Never discard its only guest handle before that commits.
            (new Cart)->getConnection()->afterCommit(function () use ($session, $guest, $org, $site): void {
                $current = CartOwner::anonymous($session, $org, $site);

                if ($current !== null && hash_equals($guest->key, $current->key)) {
                    $session->forget(CartOwner::sessionKey($org, $site));
                }

                $session->forget(CartSessions::pendingKey($org, $site));
            });
        });
    }

    /** Laravel's OrFail helpers still return false when a model observer vetoes a write. */
    private function saveRow(Cart|CartLine $row): void
    {
        if ($row->saveOrFail() !== true) {
            throw new CartRefused('unavailable');
        }
    }

    private function deleteRow(Cart|CartLine $row): void
    {
        if ($row->deleteOrFail() !== true) {
            throw new CartRefused('unavailable');
        }
    }

    private function enabled(bool $current = false): void
    {
        if (Module::query()->useWritePdo()->where('handle', 'kitsune/commerce')->where('is_enabled', true)
            ->when($current, static fn ($query) => $query->lockForUpdate())->first() === null) {
            throw new CartRefused('unavailable');
        }
    }

    private function write(Closure $work): void
    {
        $this->enabled();
        $orgId = $this->context->orgId();
        $siteId = $this->context->siteId();

        if ($orgId === null || $siteId === null) {
            throw new CartRefused('unavailable');
        }

        TransactionRecovery::run((new Cart)->getConnection(), function () use ($orgId, $siteId, $work): void {
            // SQLite's FOR UPDATE is a no-op. Acquire its write reservation before any owner/cart snapshot is read.
            $connection = (new Cart)->getConnection();

            if ($connection->getDriverName() === 'sqlite') {
                $connection->table('orgs')->where('id', $orgId)->update(['id' => $orgId]);
            }

            $org = Org::query()->useWritePdo()->whereKey($orgId)->lockForUpdate()->first();
            $site = Site::query()->useWritePdo()->whereKey($siteId)->where('org_id', $orgId)->lockForUpdate()->first();

            if ($org === null || $site === null) {
                throw new CartRefused('unavailable');
            }

            $this->enabled(current: true);
            $work();
        });
    }

    /** A current locking read, not a repeatable-read snapshot left by the host before it waited for our org lock. */
    private function liveReader(CartOwner $owner): bool
    {
        $readers = app(ReaderGuard::class);
        $model = $readers->model();

        if ($model === null || $owner->reader === null || $readers->key($owner->reader) !== $owner->reader) {
            return false;
        }

        $instance = new $model;
        $row = $instance->newQuery()->useWritePdo()->whereKey($owner->reader)
            ->where($instance->qualifyColumn('org_id'), $owner->orgId)->lockForUpdate()->first();

        return $row instanceof Authenticatable && $readers->key($row->getAuthIdentifier()) === $owner->reader
            && (string) ($row->getAttributes()['org_id'] ?? '') === (string) $owner->orgId;
    }

    private function cart(CartOwner $owner, bool $create = false): ?Cart
    {
        // A session can cache a reader loaded before erasure. Under the org/site write locks, recheck the live
        // canonical host row so a request waiting behind erasure cannot recreate that reader's personal cart.
        if ($owner->reader !== null && (new Cart)->getConnection()->transactionLevel() > 0
            && ! $this->liveReader($owner)) {
            throw new CartRefused('unavailable');
        }

        $cart = Cart::withoutScopeBecause(
            'Read the current session unique site/owner claim including corrupt org stamps, so adoption refuses rather than loses it.',
            static fn ($query) => $query->withoutGlobalScope('cart_coherence')->useWritePdo()->where('site_id', $owner->siteId)->where('owner_key', $owner->key)
                ->when($query->getConnection()->transactionLevel() > 0, static fn ($query) => $query->lockForUpdate())->first(),
        );

        if ($cart !== null) {
            $raw = $cart->getAttributes();

            try {
                Cart::expiryValue($raw['expires_at'] ?? null);

                if (self::integer($raw['org_id'] ?? null, PHP_INT_MAX) !== $owner->orgId
                    || self::integer($raw['site_id'] ?? null, PHP_INT_MAX) !== $owner->siteId
                    || ($raw['reader_id'] ?? null) !== $owner->reader || ! is_string($raw['owner_key'] ?? null)
                    || ! hash_equals($owner->key, $raw['owner_key']) || $cart->expires_at === null) {
                    throw new CartRefused('unavailable');
                }

                self::integer($raw['version'] ?? null, 4294967295, true);
            } catch (InvalidArgumentException|\RuntimeException) {
                throw new CartRefused('unavailable');
            }
        }

        if ($create && $cart !== null && $cart->expires_at->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
            $this->deleteRow($cart);
            $cart = null;
        }

        if ($cart === null && $create) {
            $cart = new Cart(['org_id' => $owner->orgId, 'site_id' => $owner->siteId, 'reader_id' => $owner->reader,
                'owner_key' => $owner->key, 'version' => 0, 'expires_at' => CarbonImmutable::now('UTC')->addDays(self::DAYS)]);
            $this->saveRow($cart);
        }

        return $cart;
    }

    /** @return list<CartLine> */
    private function lines(Cart $cart): array
    {
        // The parent was resolved from the current session's unique owner claim. Read its bounded raw children:
        // filtering corrupt stamps through a model scope would hide a selection, then cascade-delete it at adoption.
        $connection = $cart->getConnection();
        $rows = $connection->table('commerce_cart_lines')->useWritePdo()->where('cart_id', $cart->getKey())
            ->when($connection->transactionLevel() > 0, static fn ($query) => $query->lockForUpdate())
            ->orderBy('id')->limit(self::MAX_LINES + 1)->get();

        if ($rows->count() > self::MAX_LINES) {
            throw new CartRefused('limit');
        }

        $listingIds = $rows->pluck('listing_id')->filter(static fn (mixed $id): bool => $id !== null)->all();
        $listings = $connection->table('commerce_listings')->useWritePdo()->whereIn('id', $listingIds)
            ->when($connection->transactionLevel() > 0, static fn ($query) => $query->lockForUpdate())
            ->limit(self::MAX_LINES)->get(['id', 'org_id', 'site_id'])->keyBy('id');
        $lines = [];

        foreach ($rows as $row) {
            try {
                if (self::integer($row->org_id, PHP_INT_MAX) !== (int) $cart->org_id
                    || self::integer($row->site_id, PHP_INT_MAX) !== (int) $cart->site_id
                    || self::integer($row->cart_id, PHP_INT_MAX) !== (int) $cart->getKey()) {
                    throw new CartRefused('unavailable');
                }

                self::integer($row->id, PHP_INT_MAX);
                self::integer($row->quantity, self::MAX_QUANTITY);

                if ($row->listing_id !== null) {
                    $listing = $listings->get(self::integer($row->listing_id, PHP_INT_MAX));

                    if ($listing === null || self::integer($listing->org_id, PHP_INT_MAX) !== (int) $cart->org_id
                        || self::integer($listing->site_id, PHP_INT_MAX) !== (int) $cart->site_id) {
                        throw new CartRefused('unavailable');
                    }
                }
            } catch (CartRefused) {
                throw new CartRefused('unavailable');
            }

            $lines[] = (new CartLine)->newFromBuilder((array) $row);
        }

        return $lines;
    }

    /** @param list<CartLine> $lines
     * @return array<int, array<string, mixed>>
     */
    private function products(array $lines, bool $current = false): array
    {
        return $this->catalogue->forListings(array_values(array_unique(array_map(static fn (CartLine $line): int => (int) $line->listing_id,
            array_filter($lines, static fn (CartLine $line): bool => $line->listing_id !== null)))), current: $current);
    }

    private function admit(int $listing, int $quantity): void
    {
        $product = $listing > 0 ? ($this->catalogue->forListings([$listing], current: true)[$listing] ?? null) : null;

        if ($product === null || ! self::hasStock($product['stock'], $quantity)) {
            throw new CartRefused('unavailable');
        }
    }

    /** @param list<CartLine> $lines */
    private function validateTotal(array $lines): void
    {
        $products = $this->products($lines, current: true);
        $total = '0';

        try {
            foreach ($lines as $line) {
                $product = $products[(int) $line->listing_id] ?? null;

                if ($product !== null) {
                    $total = CartAmounts::add($total, CartAmounts::multiply($product['priceMinor'], (int) $line->quantity));
                }
            }
        } catch (InvalidArgumentException) {
            throw new CartRefused('limit');
        }
    }

    private function changed(Cart $cart): void
    {
        if ((int) $cart->version >= 4294967295) {
            throw new CartRefused('limit');
        }

        $cart->version = (int) $cart->version + 1;
        $cart->expires_at = CarbonImmutable::now('UTC')->addDays(self::DAYS);
        $this->saveRow($cart);
    }

    public static function integer(#[\SensitiveParameter] mixed $value, int $maximum, bool $zero = false): int
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || strlen($value) > strlen((string) $maximum)
            || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1
            || (strlen($value) === strlen((string) $maximum) && strcmp($value, (string) $maximum) > 0)
            || (! $zero && $value === '0')) {
            throw new CartRefused('invalid');
        }

        return (int) $value;
    }

    private static function hasStock(?string $stock, int $quantity): bool
    {
        return $stock === null || strlen($stock) > strlen((string) $quantity)
            || (strlen($stock) === strlen((string) $quantity) && strcmp($stock, (string) $quantity) >= 0);
    }
}
