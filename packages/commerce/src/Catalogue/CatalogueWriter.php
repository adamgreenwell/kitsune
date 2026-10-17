<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Catalogue;

use Closure;
use Kitsune\Commerce\Models\Listing;
use Kitsune\Commerce\Models\Sellable;
use Kitsune\Commerce\Money\MinorUnits;
use Kitsune\Commerce\Offers\OfferTerms;
use Kitsune\Core\Audit\Auditor;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Currency\Currencies;
use Kitsune\Core\Database\EntryWriteReads;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Tenancy\Context;
use RuntimeException;

/**
 * The audited owner/system write API. Scope comes from Context, never caller-supplied models or ownership IDs.
 * A null staff actor means the system, following CredentialWriter; a web endpoint must require a signed-in owner.
 * Evented direct model saves also validate their values and references, and are not an untrusted input surface.
 *
 * @internal First-party only.
 */
final class CatalogueWriter
{
    private static Sellable|Listing|null $removing = null;

    public function __construct(private readonly Context $context, private readonly Auditor $auditor) {}

    public function createSellable(mixed $sku, mixed $priceMinor, mixed $stock, mixed $currency, #[\SensitiveParameter] mixed $entitlementName = null,
        #[\SensitiveParameter] mixed $offerTerms = null): Sellable
    {
        $this->refuseWithoutAuthority();
        $attributes = [...$this->sellableAttributes($sku, $priceMinor, $stock), 'entitlement_name' => Sellable::entitlementNameValue($entitlementName),
            'offer_terms' => OfferTerms::forMapping($entitlementName, $offerTerms)];
        $currency = $this->currencyQuote($currency);

        return $this->transaction(function () use ($attributes, $currency): Sellable {
            $this->refuseStaleCurrencyQuote($this->lockOrg(), $currency);
            $row = new Sellable;
            $row->forceFill($attributes);
            $this->save($row);
            $this->record('commerce.sellable_created', $row);

            return $row;
        });
    }

    /**
     * Omitted offer metadata preserves the stored mapping for existing first-party callers; an explicit null clears
     * it. Argument presence, including a named entitlementName argument, distinguishes the two.
     */
    public function updateSellable(mixed $id, mixed $sku, mixed $priceMinor, mixed $stock, mixed $currency, #[\SensitiveParameter] mixed $entitlementName = null,
        #[\SensitiveParameter] mixed $offerTerms = null): Sellable
    {
        $this->refuseWithoutAuthority();
        $key = Sellable::catalogueKey($id);
        $attributes = $this->sellableAttributes($sku, $priceMinor, $stock);

        if (func_num_args() >= 6) {
            $attributes['entitlement_name'] = Sellable::entitlementNameValue($entitlementName);
            if ($entitlementName === null) {
                $attributes['offer_terms'] = null;
            }
        }
        if (func_num_args() >= 7) {
            $attributes['offer_terms'] = OfferTerms::normalize($offerTerms);
        }

        $currency = $this->currencyQuote($currency);

        return $this->transaction(function () use ($key, $attributes, $currency): Sellable {
            $this->refuseStaleCurrencyQuote($this->lockOrg(), $currency);
            $row = EntryWriteReads::query(Sellable::query()->useWritePdo())->whereKey($key)->first()
                ?? throw new RuntimeException('The sellable is not available in this org.');
            if (($row->stock === null) !== ($attributes['stock'] === null)
                || ($row->stock !== null && $row->stock !== $attributes['stock'])) {
                throw new RuntimeException('Change existing stock through StockWriter with a reason.');
            }
            $row->forceFill($attributes);

            if ($row->isDirty(array_keys($attributes))) {
                $this->save($row);
                $this->record('commerce.sellable_updated', $row);
            }

            return $row;
        });
    }

    public function removeSellable(mixed $id): void
    {
        $this->refuseWithoutAuthority();
        $key = Sellable::catalogueKey($id);
        $this->transaction(function () use ($key): void {
            $this->lockOrg();
            $row = Sellable::query()->useWritePdo()->whereKey($key)->first()
                ?? throw new RuntimeException('The sellable is not available in this org.');
            $row->refuseInvalidCatalogueValues();

            // Explicitly every site, including a site other than Context's. The FK is the race backstop.
            if ($row->getConnection()->table('commerce_listings')->useWritePdo()->where('sellable_id', $key)->exists()) {
                throw new RuntimeException('Remove every site listing before removing its sellable.');
            }

            $this->remove($row);
            $this->record('commerce.sellable_removed', $row);
        });
    }

    public function createListing(mixed $sellableId, mixed $entryId, mixed $isAvailable = false): Listing
    {
        $this->refuseWithoutAuthority(site: true);
        $sellable = Sellable::catalogueKey($sellableId);
        $entry = Listing::catalogueKey($entryId);
        $available = $this->availability($isAvailable);

        return $this->transaction(function () use ($sellable, $entry, $available): Listing {
            $this->lockOrg();
            $row = new Listing;
            $row->forceFill(['sellable_id' => $sellable, 'entry_id' => $entry, 'is_available' => $available]);
            $this->save($row);
            $this->record('commerce.listing_created', $row);

            return $row;
        });
    }

    public function setAvailability(mixed $listingId, mixed $isAvailable): Listing
    {
        $this->refuseWithoutAuthority(site: true);
        $key = Listing::catalogueKey($listingId);
        $available = $this->availability($isAvailable);

        return $this->transaction(function () use ($key, $available): Listing {
            $this->lockOrg();
            $row = Listing::query()->withoutGlobalScope('catalogue_coherence')->useWritePdo()->where('org_id', $this->context->orgId())->where('site_id', $this->context->siteId())->whereKey($key)->lockForUpdate()->first()
                ?? throw new RuntimeException('The listing is not available on this site.');

            $row->refuseInvalidCatalogueValues();
            if ($row->is_available !== $available) {
                $row->is_available = $available;
                $this->save($row);
                $this->record('commerce.listing_availability_changed', $row);
            }

            return $row;
        });
    }

    public function removeListing(mixed $id): void
    {
        $this->refuseWithoutAuthority(site: true);
        $key = Listing::catalogueKey($id);
        $this->transaction(function () use ($key): void {
            $this->lockOrg();
            $row = Listing::query()->withoutGlobalScope('catalogue_coherence')->useWritePdo()->whereKey($key)
                ->where('org_id', $this->context->orgId())->where('site_id', $this->context->siteId())->first()
                ?? throw new RuntimeException('The listing is not available on this site.');
            $row->refuseInvalidCatalogueRemoval();
            $this->remove($row);
            $this->record('commerce.listing_removed', $row);
        });
    }

    public static function isRemoving(Sellable|Listing $row): bool
    {
        return self::$removing === $row;
    }

    public static function isRemovingThrough(Sellable|Listing $row, object $through): bool
    {
        return self::isRemoving($row) && $row->isPerformingCatalogueDelete($through);
    }

    private function refuseWithoutAuthority(bool $site = false): void
    {
        if ($this->context->orgId() === null || ($site && $this->context->siteId() === null)) {
            throw new RuntimeException('Catalogue writes require the current org and, for a listing, its site.');
        }

        $actor = Permissions::currentUser();

        if ($actor !== null && ! Permissions::isOwner($actor)) {
            throw new RuntimeException('Only an org owner may write the catalogue.');
        }

        if (! EntryWriteReads::exists(Module::query()->where('handle', 'kitsune/commerce')->where('is_enabled', true))) {
            throw new RuntimeException('The commerce module is not enabled.');
        }
    }

    private function lockOrg(): Org
    {
        // Serialises missing-SKU creates without a MySQL/MariaDB gap lock, and agrees with the currency writer.
        return Org::query()->useWritePdo()->whereKey($this->context->orgId())->lockForUpdate()->first()
            ?? throw new RuntimeException('The catalogue org is no longer available.');
    }

    private function currencyQuote(mixed $currency): string
    {
        if (! Currencies::supports($currency)) {
            throw new RuntimeException('A catalogue amount requires an exact supported currency code.');
        }

        return $currency;
    }

    private function refuseStaleCurrencyQuote(Org $org, string $currency): void
    {
        if ($org->getAttribute('currency') !== $currency) {
            throw new RuntimeException('The org currency changed; review the amount in the current currency and try again.');
        }
    }

    /** @return array{sku: string, price_minor: string, stock: string|null} */
    private function sellableAttributes(mixed $sku, mixed $priceMinor, mixed $stock): array
    {
        return ['sku' => Sellable::skuValue($sku), 'price_minor' => MinorUnits::integer($priceMinor),
            'stock' => $stock === null ? null : MinorUnits::integer($stock)];
    }

    private function availability(mixed $available): bool
    {
        if (! is_bool($available)) {
            throw new RuntimeException('Listing availability must be a boolean.');
        }

        return $available;
    }

    private function save(Sellable|Listing $row): void
    {
        if (! $row->save()) {
            throw new RuntimeException('The catalogue save was cancelled.');
        }
    }

    private function record(string $action, Sellable|Listing $row): void
    {
        $offer = $row instanceof Sellable && $action !== 'commerce.sellable_removed'
            ? [$row->entitlement_name, OfferTerms::stored($row->getAttributes()['offer_terms'] ?? null)] : null;
        if (! $this->auditor->recordOrFail($action, $row)->exists) {
            throw new RuntimeException('The catalogue audit save was cancelled.');
        }
        if ($offer !== null) {
            $fresh = Sellable::query()->useWritePdo()->whereKey($row->getKey())->lockForUpdate()->first()
                ?? throw new RuntimeException('The saved purchased access offer is unavailable.');
            if ($offer !== [$fresh->entitlement_name, OfferTerms::stored($fresh->getAttributes()['offer_terms'] ?? null)]) {
                throw new RuntimeException('The saved purchased access offer changed during its audit.');
            }
        }
    }

    private function remove(Sellable|Listing $row): void
    {
        self::$removing = $row;

        try {
            if (! $row->delete()) {
                throw new RuntimeException('The catalogue removal was cancelled.');
            }
        } finally {
            self::$removing = null;
        }
    }

    /** @template T
     * @param  Closure(): T  $write
     * @return T
     */
    private function transaction(Closure $write): mixed
    {
        return TransactionRecovery::run((new Sellable)->getConnection(), $write);
    }
}
