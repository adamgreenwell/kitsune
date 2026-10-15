<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Orders;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Kitsune\Commerce\Carts\CartAmounts;
use Kitsune\Commerce\Carts\CartRefused;
use Kitsune\Commerce\Carts\CartWriter;
use Kitsune\Commerce\Models\CommerceSettings;
use Kitsune\Commerce\Models\Order;
use Kitsune\Commerce\Models\SalesPolicy;
use Kitsune\Commerce\Models\TaxRate;
use Kitsune\Commerce\Offers\OfferTerms;
use Kitsune\Commerce\Shipping\ParcelPacking;
use Kitsune\Commerce\Shipping\ShippoRates;
use Kitsune\Commerce\Taxes\TaxAmounts;
use Kitsune\Core\Auth\EmailAddress;
use Kitsune\Core\Credentials\CredentialReader;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Readers\ReaderLinks;
use Kitsune\Core\Readers\ReaderMail;
use Kitsune\Core\Readers\ReaderSessions;
use Kitsune\Core\Tenancy\Context;
use Throwable;

/** Bounded provisional quotes live only in the server session; providers are called outside database locks. @internal */
final class OrderQuotes
{
    public const MINUTES = 15;

    public const SHIPPO_SLOT = 'commerce.shippo-api-token';

    public function __construct(private readonly OrderLocks $locks, private readonly CartWriter $carts, private readonly Context $context) {}

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function quote(Request $request, #[\SensitiveParameter] array $input): array
    {
        if (! $request->hasSession()) {
            throw new OrderRefused;
        }
        try {
            $outerLevel = (new Order)->getConnection()->transactionLevel();
            $details = self::details($input);
            if (ReaderMail::fault() !== null) {
                throw new OrderRefused('mail');
            }
            $snapshot = $this->locks->run(fn (Org $org, Site $site): array => $this->capture($request, $details, $org, $site));
            $rates = [['id' => 'none', 'carrier' => '', 'service' => '', 'amountMinor' => '0', 'currency' => $snapshot['currency'], 'shipmentId' => '', 'test' => $snapshot['test']]];
            if ($snapshot['parcels'] !== []) {
                if ($outerLevel !== 0) {
                    throw new OrderRefused;
                }
                $secret = app(CredentialReader::class)->secret(self::SHIPPO_SLOT);
                $rates = app(ShippoRates::class)->quote($secret, $snapshot['test'], $snapshot['shipFrom'], $details['delivery'], $snapshot['parcels'], $snapshot['currency']);
            }
            // A rate response cannot approve a cart/configuration that changed while the network request ran.
            $this->locks->run(function (Org $org, Site $site) use ($request, $details, $snapshot): void {
                if (! hash_equals($snapshot['hash'], $this->capture($request, $details, $org, $site)['hash'])) {
                    throw new OrderRefused('stale');
                }
            });
            $token = bin2hex(random_bytes(32));
            $quote = ['token' => $token, 'expires' => CarbonImmutable::now('UTC')->addMinutes(self::MINUTES)->getTimestamp(),
                'details' => $details, 'snapshot' => $snapshot, 'rates' => $rates];
            $stored = $request->session()->get($this->key(), []);
            $stored = is_array($stored) ? $stored : [];
            $stored = array_filter($stored, static fn ($value): bool => is_array($value) && is_int($value['expires'] ?? null) && $value['expires'] > CarbonImmutable::now('UTC')->getTimestamp());
            $stored[$token] = $quote;
            $request->session()->put($this->key(), array_slice($stored, -5, null, true));

            return $quote;
        } catch (OrderRefused $failure) {
            throw $failure;
        } catch (Throwable) {
            // Provider/SQL exceptions can contain invoice data; none becomes the previous exception or a log body.
            throw new OrderRefused;
        }
    }

    /** @return array<string,mixed> */
    public function saved(Request $request, string $token): array
    {
        $quotes = $request->session()->get($this->key(), []);
        $quote = is_array($quotes) ? ($quotes[$token] ?? null) : null;
        if (! is_array($quote) || ! is_int($quote['expires'] ?? null) || $quote['expires'] <= CarbonImmutable::now('UTC')->getTimestamp()) {
            throw new OrderRefused('expired');
        }

        return $quote;
    }

    /** Requires OrderLocks. Every value used to prepare an order participates in its fingerprint.
     * @param  array<string,mixed>  $details
     * @param  array<string,mixed>|null  $selection  Authoritative current order selection, already proved under OrderLocks.
     * @return array<string,mixed>
     */
    public function capture(Request $request, #[\SensitiveParameter] array $details, Org $org, Site $site, ?array $selection = null): array
    {
        try {
            $siteName = $site->getAttributes()['name'] ?? null;
            $siteUrl = $site->getAttributes()['base_url'] ?? null;
            if (ReaderLinks::origin($site) === null || ! is_string($siteUrl) || ! is_string($siteName)
                || ! mb_check_encoding($siteName, 'UTF-8') || mb_strlen($siteName, 'UTF-8') > 255) {
                throw new OrderRefused('setup');
            }
            $selection ??= $this->carts->checkout($request);
            $connection = (new Order)->getConnection();
            $settings = $connection->table('commerce_settings')->useWritePdo()->where('org_id', $org->getKey())->lockForUpdate()->first();
            if ($settings === null || ! in_array($settings->tax_mode, ['inclusive', 'exclusive'], true)
                || ! in_array($settings->shipping_tax_mode, ['inclusive', 'exclusive'], true)) {
                throw new OrderRefused('setup');
            }
            CommerceSettings::values(array_intersect_key([...(array) $settings, 'ship_from_address' => $settings->ship_from_address === null ? null : self::json($settings->ship_from_address)], array_flip(CommerceSettings::FIELDS)));
            self::taxClass($settings->shipping_tax_class);
            CartWriter::integer($settings->version, 4294967295);
            $lines = $parcels = $policies = $rates = [];
            $itemNet = $itemTax = $itemGross = '0';
            foreach ($selection['items'] as $item) {
                if ($item['currency'] !== $org->currency) {
                    throw new OrderRefused('stale');
                }
                $policy = $connection->table('commerce_sales_policies')->useWritePdo()->where('org_id', $org->getKey())
                    ->where('sellable_id', $item['sellableRef'])->lockForUpdate()->first();
                if ($policy === null || ! in_array($policy->delivery_kind, ['digital', 'physical'], true)) {
                    throw new OrderRefused('setup');
                }
                SalesPolicy::values(array_intersect_key((array) $policy, array_flip(SalesPolicy::FIELDS)));
                self::taxClass($policy->tax_class);
                CartWriter::integer($policy->version, 4294967295);
                $address = $policy->delivery_kind === 'physical' ? $details['delivery']['address'] : $details['billing']['address'];
                $rate = $this->rate($policy->tax_class, $address);
                $rates[] = $rate;
                $policies[] = (array) $policy;
                $amount = CartAmounts::multiply($item['priceMinor'], $item['quantity']);
                $tax = $settings->tax_mode === 'inclusive' ? TaxAmounts::inclusive($amount, (int) $rate->rate_parts) : TaxAmounts::exclusive($amount, (int) $rate->rate_parts);
                if (! is_string($item['title']) || ! mb_check_encoding($item['title'], 'UTF-8')
                    || mb_strlen($item['title'], 'UTF-8') > 255 || preg_match('/[\p{Cc}\x{2028}\x{2029}]/u', $item['title']) === 1) {
                    throw new OrderRefused;
                }
                $lines[] = ['sellable_ref' => $item['sellableRef'], 'listing_ref' => $item['listingId'], 'entry_ref' => $item['entryRef'],
                    'sku' => $item['sku'], 'title' => $item['title'], 'quantity' => $item['quantity'], 'unit_price_minor' => $item['priceMinor'],
                    'tax_class' => $policy->tax_class, 'tax_rate_parts' => (int) $rate->rate_parts, 'delivery_kind' => $policy->delivery_kind,
                    'net_minor' => $tax['net'], 'tax_minor' => $tax['tax'], 'gross_minor' => $tax['gross'], 'entitlement_name' => $item['entitlementName'],
                    'offer_terms' => OfferTerms::purchasable($item['entitlementName'], $item['offerTerms'])];
                $itemNet = CartAmounts::add($itemNet, $tax['net']);
                $itemTax = CartAmounts::add($itemTax, $tax['tax']);
                $itemGross = CartAmounts::add($itemGross, $tax['gross']);
                if ($policy->delivery_kind === 'physical') {
                    $parcels[] = ['sku' => $item['sku'], 'quantity' => $item['quantity'], 'weight_grams' => $policy->weight_grams,
                        'length_mm' => $policy->length_mm, 'width_mm' => $policy->width_mm, 'height_mm' => $policy->height_mm,
                        'tare_grams' => $policy->tare_grams, 'units_per_parcel' => $policy->units_per_parcel];
                }
            }
            $shippingRate = null;
            $from = null;
            $mode = $connection->table('org_credential_modes')->useWritePdo()->where('org_id', $org->getKey())->lockForUpdate()->first();
            $test = $mode === null || $mode->mode === 'test';
            if ($mode !== null && ! in_array($mode->mode, ['test', 'live'], true)) {
                throw new OrderRefused('setup');
            }
            $credential = null;
            if ($parcels !== []) {
                $from = BillingDetails::from(['name' => $settings->ship_from_name, 'address' => self::json($settings->ship_from_address)]);
                $parcels = ParcelPacking::pack($parcels);
                $shippingRate = $this->rate($settings->shipping_tax_class, $details['delivery']['address']);
                $credentialRow = $connection->table('credentials')->useWritePdo()->where('org_id', $org->getKey())
                    ->where('slot', self::SHIPPO_SLOT)->where('mode', $test ? 'test' : 'live')->lockForUpdate()->first();
                if ($credentialRow === null || $credentialRow->ciphertext === null) {
                    throw new OrderRefused('setup');
                }
                $credential = hash('sha256', json_encode((array) $credentialRow, JSON_THROW_ON_ERROR));
            }
            $data = [...$selection, 'currency' => $org->currency, 'taxMode' => $settings->tax_mode,
                'shippingTaxMode' => $settings->shipping_tax_mode, 'shippingTaxClass' => $settings->shipping_tax_class,
                'shippingRate' => $shippingRate === null ? null : (int) $shippingRate->rate_parts,
                'lines' => $lines, 'itemNet' => $itemNet, 'itemTax' => $itemTax, 'itemGross' => $itemGross,
                'parcels' => $parcels, 'shipFrom' => $from, 'test' => $test,
                'siteSnapshot' => ['name' => $siteName, 'base_url' => ReaderLinks::origin($site).($site->path_prefix ?? '')]];
            $data['hash'] = hash('sha256', json_encode([$data, $details, (array) $settings, $policies, $rates, $shippingRate, $credential], JSON_THROW_ON_ERROR));

            return $data;
        } catch (CartRefused|InvalidArgumentException) {
            throw new OrderRefused;
        }
    }

    /** @param array<string,string> $address */
    private function rate(string $class, array $address): object
    {
        $region = strtoupper($address['region']);
        if ($region !== '' && preg_match('/\A[A-Z0-9][A-Z0-9-]{0,31}\z/D', $region) !== 1) {
            throw new OrderRefused('region');
        }
        $rows = (new Order)->getConnection()->table('commerce_tax_rates')->useWritePdo()->where('org_id', $this->context->orgId())
            ->where('country', $address['country'])->where('tax_class', $class)->whereIn('region', array_unique([$region, '*']))
            ->orderBy('id')->limit(3)->lockForUpdate()->get();
        if ($rows->isEmpty() || $rows->count() > 2) {
            throw new OrderRefused('tax');
        }
        $chosen = null;
        $seen = [];
        foreach ($rows as $row) {
            TaxRate::values(array_intersect_key((array) $row, array_flip(TaxRate::FIELDS)));
            if ($row->country !== $address['country'] || $row->tax_class !== $class || ! in_array($row->region, [$region, '*'], true)) {
                throw new OrderRefused('tax');
            }
            if (isset($seen[$row->region])) {
                throw new OrderRefused('tax');
            }
            $seen[$row->region] = true;
            CartWriter::integer($row->rate_parts, 1000000, true);
            CartWriter::integer($row->version, 4294967295);
            if ($chosen === null || $row->region === $region) {
                $chosen = $row;
            }
        }

        return $chosen ?? throw new OrderRefused('tax');
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private static function details(#[\SensitiveParameter] array $input): array
    {
        if (array_diff(array_keys($input), ['email', 'billing', 'delivery']) !== [] || ! is_string($input['email'] ?? null)) {
            throw new OrderRefused('invalid');
        }
        $email = EmailAddress::normalise($input['email']) ?? throw new OrderRefused('invalid');
        $billing = BillingDetails::from($input['billing'] ?? null);
        $delivery = ($input['delivery'] ?? null) === null ? $billing : BillingDetails::from($input['delivery']);

        return ['email' => $email, 'billing' => $billing, 'delivery' => $delivery];
    }

    private function key(): string
    {
        return ReaderSessions::EPHEMERAL.'.order_quotes.'.$this->context->orgId().'.'.$this->context->siteId();
    }

    private static function taxClass(mixed $value): void
    {
        if (! is_string($value) || preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $value) !== 1) {
            throw new OrderRefused('setup');
        }
    }

    private static function json(#[\SensitiveParameter] mixed $value): mixed
    {
        return is_string($value) && strlen($value) <= 8192 ? json_decode($value, true, 16, JSON_THROW_ON_ERROR) : throw new OrderRefused('setup');
    }
}
