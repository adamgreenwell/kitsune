<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Payments;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Kitsune\Commerce\Carts\CartAmounts;
use Kitsune\Commerce\Carts\CartWriter;
use Kitsune\Commerce\Catalogue\PublicCatalogue;
use Kitsune\Commerce\Models\Order;
use Kitsune\Commerce\Money\MinorUnits;
use Kitsune\Commerce\Orders\OrderAccess;
use Kitsune\Commerce\Orders\OrderJson;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Orders\OrderQuotes;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Commerce\Orders\OrderRefused;
use Kitsune\Commerce\Shipping\ShippoRates;
use Kitsune\Commerce\Taxes\TaxAmounts;
use Kitsune\Core\Credentials\CredentialReader;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Readers\ReaderSessions;
use Kitsune\Core\Tenancy\Context;
use Throwable;

/** Fresh, exact-price buyer review for an owned immutable order, independently of its old cart. @internal */
final class CheckoutReview
{
    public function __construct(
        private readonly OrderLocks $locks,
        private readonly OrderAccess $access,
        private readonly OrderReaderData $privacy,
        private readonly PublicCatalogue $catalogue,
        private readonly OrderQuotes $quotes,
        private readonly Context $context,
    ) {}

    /** @return array{token:string,expires:int,order:array<string,mixed>} */
    public function review(Request $request, mixed $reference): array
    {
        try {
            if (! $request->hasSession()) {
                throw new OrderRefused;
            }
            $outerLevel = (new Order)->getConnection()->transactionLevel();
            $review = $this->locks->run(fn (Org $org, Site $site): array => $this->capture($request, $reference, $org, $site));
            $shipping = null;
            if ($review['snapshot']['parcels'] !== []) {
                if ($outerLevel !== 0) {
                    throw new OrderRefused;
                }
                $rates = app(ShippoRates::class)->quote(app(CredentialReader::class)->secret(OrderQuotes::SHIPPO_SLOT), true,
                    $review['snapshot']['shipFrom'], $review['details']['delivery'], $review['snapshot']['parcels'], $review['record']['currency']);
                foreach ($rates as $rate) {
                    $original = $review['record']['shipping'];
                    if ($rate['carrier'] === $original['carrier'] && $rate['service'] === $original['service']
                        && $rate['amountMinor'] === $original['amountMinor'] && $rate['currency'] === $original['currency'] && $rate['test'] === true) {
                        $shipping = $rate;
                        break;
                    }
                }
                if ($shipping === null) {
                    throw new OrderRefused('stale');
                }
            }
            // Ownership, live inputs and immutable history may change while Shippo is outside the transaction.
            $this->locks->run(function (Org $org, Site $site) use ($request, $reference, $review): void {
                $current = $this->capture($request, $reference, $org, $site);
                if (! hash_equals($review['orderHash'], $current['orderHash']) || ! hash_equals($review['snapshot']['hash'], $current['snapshot']['hash'])) {
                    throw new OrderRefused('stale');
                }
            });
            $token = bin2hex(random_bytes(32));
            $expires = CarbonImmutable::now('UTC')->addMinutes(OrderQuotes::MINUTES)->getTimestamp();
            $stored = $this->slots($request);
            $stored[$token] = ['reference' => $reference, 'expires' => $expires, 'orderHash' => $review['orderHash'],
                'fingerprint' => $review['snapshot']['hash'], 'shipping' => $shipping];
            $request->session()->put($this->key(), array_slice($stored, -5, null, true));

            return ['token' => $token, 'expires' => $expires, 'order' => $review['record']];
        } catch (OrderRefused $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new OrderRefused;
        }
    }

    /** Called only under OrderLocks; never performs provider I/O.
     * @return array{order:Order,record:array<string,mixed>,orderHash:string}
     */
    public function approve(Request $request, mixed $reference, mixed $token): array
    {
        try {
            if ((new Order)->getConnection()->transactionLevel() < 1 || ! $request->hasSession()
                || ! is_string($token) || preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1) {
                throw new OrderRefused;
            }
            $saved = $this->slots($request)[$token] ?? null;
            if ($saved === null || ($saved['reference'] ?? null) !== $reference) {
                throw new OrderRefused('expired');
            }
            $org = Org::query()->useWritePdo()->whereKey($this->context->orgId())->lockForUpdate()->first() ?? throw new OrderRefused;
            $site = Site::query()->useWritePdo()->whereKey($this->context->siteId())->where('org_id', $org->getKey())->lockForUpdate()->first() ?? throw new OrderRefused;
            $review = $this->capture($request, $reference, $org, $site);
            if (! is_string($saved['orderHash'] ?? null) || ! is_string($saved['fingerprint'] ?? null)
                || ! hash_equals($saved['orderHash'], $review['orderHash']) || ! hash_equals($saved['fingerprint'], $review['snapshot']['hash'])) {
                throw new OrderRefused('stale');
            }

            return ['order' => $review['order'], 'record' => $review['record'], 'orderHash' => $review['orderHash']];
        } catch (OrderRefused $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new OrderRefused;
        }
    }

    /** Mutable operation history and payment effects are not the buyer's original purchase consent.
     * @param  array<string,mixed>  $record
     */
    public static function orderHash(#[\SensitiveParameter] array $record): string
    {
        unset($record['checkout_attempts'], $record['access_links'], $record['stripe_events'], $record['payment'], $record['allocations'], $record['refund']);
        if (in_array($record['state'] ?? null, ['paid', 'cancelled'], true)) {
            $record['state'] = 'prepared';
            $record['confirmed_total_minor'] = null;
            $record['cancelled_at'] = null;
        }

        return hash('sha256', json_encode(self::canonical($record), JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed> */
    private function capture(Request $request, mixed $reference, Org $org, Site $site): array
    {
        $order = $this->access->owned($request, $reference) ?? throw new OrderRefused;
        $raw = $order->getAttributes();
        $record = $this->privacy->record($order->getConnection(), (object) $raw);
        if ($record['state'] !== 'prepared' || $record['mode'] !== 'test') {
            throw new OrderRefused;
        }
        $ids = array_map(static fn (array $line): int => CartWriter::integer($line['listing_ref'], PHP_INT_MAX), $record['lines']);
        $products = $this->catalogue->forOrders($ids);
        $items = [];
        foreach ($record['lines'] as $line) {
            $listingId = CartWriter::integer($line['listing_ref'], PHP_INT_MAX);
            $product = $products[$listingId] ?? throw new OrderRefused('stale');
            $quantity = $line['quantity'];
            if ((string) $product['sellableRef'] !== $line['sellable_ref'] || (string) $product['entryRef'] !== $line['entry_ref']) {
                throw new OrderRefused('stale');
            }
            if ($product['stock'] !== null) {
                $stock = MinorUnits::integer($product['stock']);
                if (strlen($stock) < strlen((string) $quantity) || (strlen($stock) === strlen((string) $quantity) && strcmp($stock, (string) $quantity) < 0)) {
                    throw new OrderRefused('stale');
                }
            }
            $items[] = [...$product, 'quantity' => $quantity];
        }
        $selection = ['orgId' => (int) $raw['org_id'], 'siteId' => (int) $raw['site_id'], 'ownerKey' => $raw['owner_key'],
            'reader' => $raw['reader_id'], 'cartRef' => (int) $raw['cart_ref'], 'cartVersion' => (int) $raw['cart_version'], 'items' => $items];
        $details = ['email' => $record['contact_email'], 'billing' => $record['billing'], 'delivery' => $record['delivery'] ?? $record['billing']];
        $snapshot = $this->quotes->capture($request, $details, $org, $site, $selection);
        if ($snapshot['test'] !== true || $snapshot['currency'] !== $record['currency'] || $snapshot['taxMode'] !== $record['tax_mode']) {
            throw new OrderRefused('stale');
        }
        $lines = $snapshot['lines'];
        foreach ($lines as &$line) {
            foreach (['sellable_ref', 'listing_ref', 'entry_ref'] as $key) {
                $line[$key] = (string) $line[$key];
            }
        }
        unset($line);
        if (! OrderJson::same(json_encode($lines, JSON_THROW_ON_ERROR), json_encode($record['lines'], JSON_THROW_ON_ERROR))) {
            throw new OrderRefused('stale');
        }
        $shipping = $record['shipping'];
        $shippingTax = ['net' => '0', 'tax' => '0', 'gross' => '0'];
        if ($shipping !== null) {
            if ($snapshot['parcels'] === [] || $snapshot['shippingTaxClass'] !== $shipping['taxClass']
                || $snapshot['shippingTaxMode'] !== $shipping['taxMode'] || $snapshot['shippingRate'] !== $shipping['taxRateParts']) {
                throw new OrderRefused('stale');
            }
            $shippingTax = $snapshot['shippingTaxMode'] === 'inclusive'
                ? TaxAmounts::inclusive($shipping['amountMinor'], $snapshot['shippingRate'])
                : TaxAmounts::exclusive($shipping['amountMinor'], $snapshot['shippingRate']);
        } elseif ($snapshot['parcels'] !== []) {
            throw new OrderRefused('stale');
        }
        $totals = ['quoted_item_net_minor' => $snapshot['itemNet'], 'quoted_shipping_net_minor' => $shippingTax['net'],
            'quoted_tax_minor' => CartAmounts::add($snapshot['itemTax'], $shippingTax['tax']),
            'quoted_total_minor' => CartAmounts::add($snapshot['itemGross'], $shippingTax['gross'])];
        foreach ($totals as $key => $value) {
            if ($record[$key] !== $value) {
                throw new OrderRefused('stale');
            }
        }

        return ['order' => $order, 'record' => $record, 'orderHash' => self::orderHash($record), 'snapshot' => $snapshot, 'details' => $details];
    }

    /** @return array<string,array<string,mixed>> */
    private function slots(Request $request): array
    {
        $stored = $request->session()->get($this->key(), []);
        if (! is_array($stored) || count($stored) > 5) {
            return [];
        }

        return array_filter($stored, static fn ($slot, $token): bool => is_string($token) && preg_match('/\A[a-f0-9]{64}\z/D', $token) === 1
            && is_array($slot) && is_int($slot['expires'] ?? null) && $slot['expires'] > CarbonImmutable::now('UTC')->getTimestamp(), ARRAY_FILTER_USE_BOTH);
    }

    private function key(): string
    {
        return ReaderSessions::EPHEMERAL.'.checkout_reviews.'.$this->context->orgId().'.'.$this->context->siteId();
    }

    private static function canonical(#[\SensitiveParameter] mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$child) {
            $child = self::canonical($child);
        }
        unset($child);

        return $value;
    }
}
