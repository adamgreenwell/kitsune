<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Orders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Kitsune\Commerce\Carts\CartAmounts;
use Kitsune\Commerce\Carts\CartOwner;
use Kitsune\Commerce\Models\Order;
use Kitsune\Commerce\Money\MinorUnits;
use Kitsune\Commerce\Taxes\TaxAmounts;
use Kitsune\Core\Audit\Auditor;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Readers\ReaderMail;
use Kitsune\Core\Tenancy\Context;
use Throwable;

/** The sole preparation/cancellation writer; immutable invoice/line copies and no payment or stock side effects. @internal */
final class OrderWriter
{
    public function __construct(private readonly OrderLocks $locks, private readonly OrderQuotes $quotes,
        private readonly OrderAccess $access, private readonly Context $context, private readonly Auditor $auditor) {}

    public function prepare(Request $request, #[\SensitiveParameter] mixed $token, mixed $rateId): Order
    {
        if (! is_string($token) || preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1
            || ! is_string($rateId) || preg_match('/\A(?:none|[A-Za-z0-9_-]{1,100})\z/D', $rateId) !== 1) {
            throw new OrderRefused('invalid');
        }
        try {
            return $this->locks->run(function (Org $org, Site $site) use ($request, $token, $rateId): Order {
                $owner = CartOwner::current($request) ?? throw new OrderRefused;
                $requestKey = hash('sha256', "kitsune.order.request\0".$owner->key."\0".$token);
                $digest = hash('sha256', $token."\0".$rateId);
                $existing = $this->current()->where('request_key', $requestKey)->first();
                if ($existing !== null) {
                    if (! hash_equals($existing->getAttributes()['request_digest'], $digest)
                        || $this->access->owned($request, $existing->getAttributes()['reference']) === null) {
                        throw new OrderRefused('replay');
                    }

                    return $existing;
                }
                if (ReaderMail::fault() !== null) {
                    throw new OrderRefused('mail');
                }
                $quote = $this->quotes->saved($request, $token);
                $current = $this->quotes->capture($request, $quote['details'], $org, $site);
                if (! hash_equals($quote['snapshot']['hash'], $current['hash'])) {
                    throw new OrderRefused('stale');
                }
                $rate = null;
                foreach ($quote['rates'] as $candidate) {
                    if ($candidate['id'] === $rateId) {
                        $rate = $candidate;
                    }
                }
                if ($rate === null || $rate['currency'] !== $current['currency'] || $rate['test'] !== $current['test']) {
                    throw new OrderRefused('invalid');
                }
                $shippingAmount = MinorUnits::integer($rate['amountMinor']);
                $shippingTax = $current['parcels'] === [] ? ['net' => '0', 'tax' => '0', 'gross' => '0']
                    : ($current['shippingTaxMode'] === 'inclusive' ? TaxAmounts::inclusive($shippingAmount, $current['shippingRate'])
                        : TaxAmounts::exclusive($shippingAmount, $current['shippingRate']));
                $active = hash('sha256', $owner->key."\0".$current['cartRef']."\0".$current['cartVersion']);
                if ($this->current()->where('active_cart_key', $active)->first() !== null) {
                    throw new OrderRefused('existing');
                }
                $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
                $shipping = $current['parcels'] === [] ? null : [...$rate, 'taxClass' => $current['shippingTaxClass'],
                    'taxMode' => $current['shippingTaxMode'], 'taxRateParts' => $current['shippingRate'], ...$shippingTax];
                $data = ['org_id' => $owner->orgId, 'site_id' => $owner->siteId, 'reference' => bin2hex(random_bytes(16)),
                    'reader_id' => $owner->reader, 'owner_key' => $owner->key, 'contact_email' => $quote['details']['email'],
                    'cart_ref' => $current['cartRef'], 'cart_version' => $current['cartVersion'], 'request_key' => $requestKey,
                    'request_digest' => $digest, 'snapshot_hash' => $current['hash'], 'active_cart_key' => $active,
                    'state' => 'prepared', 'mode' => $current['test'] ? 'test' : 'live', 'currency' => $current['currency'], 'tax_mode' => $current['taxMode'],
                    'billing' => json_encode($quote['details']['billing'], JSON_THROW_ON_ERROR),
                    'delivery' => $current['parcels'] === [] ? null : json_encode($quote['details']['delivery'], JSON_THROW_ON_ERROR),
                    'shipping' => $shipping === null ? null : json_encode($shipping, JSON_THROW_ON_ERROR),
                    'site_snapshot' => json_encode($current['siteSnapshot'], JSON_THROW_ON_ERROR),
                    'quoted_item_net_minor' => $current['itemNet'], 'quoted_shipping_net_minor' => $shippingTax['net'],
                    'quoted_tax_minor' => CartAmounts::add($current['itemTax'], $shippingTax['tax']),
                    'quoted_total_minor' => CartAmounts::add($current['itemGross'], $shippingTax['gross']),
                    'confirmed_total_minor' => null, 'created_at' => $now, 'cancelled_at' => null];
                $connection = (new Order)->getConnection();
                $id = $connection->table('commerce_orders')->insertGetId($data);
                $lineIds = [];
                foreach ($current['lines'] as $line) {
                    $lineIds[] = $connection->table('commerce_order_lines')->insertGetId([...$line, 'org_id' => $owner->orgId,
                        'site_id' => $owner->siteId, 'order_id' => $id]);
                }
                $order = $this->current()->whereKey($id)->first() ?? throw new OrderRefused;
                if (! $this->auditor->recordOrFail('commerce.order_prepared', $order)->exists) {
                    throw new OrderRefused;
                }
                // Audit listeners are host code: recheck the persisted snapshot and scope after they run.
                $stored = $connection->table('commerce_orders')->useWritePdo()->where('id', $id)->lockForUpdate()->first();
                foreach ($data as $column => $value) {
                    if ($stored === null || ($value === null) !== ($stored->{$column} === null)
                        || ($value !== null && (in_array($column, ['billing', 'delivery', 'shipping', 'site_snapshot'], true)
                            ? ! OrderJson::same($value, $stored->{$column})
                            : (string) $value !== (string) $stored->{$column}))) {
                        throw new OrderRefused;
                    }
                }
                $savedLines = $connection->table('commerce_order_lines')->useWritePdo()->where('order_id', $id)->orderBy('id')->limit(21)->lockForUpdate()->get();
                if ($savedLines->count() !== count($current['lines'])) {
                    throw new OrderRefused;
                }
                foreach ($savedLines as $index => $line) {
                    foreach ([...$current['lines'][$index], 'id' => $lineIds[$index], 'org_id' => $owner->orgId, 'site_id' => $owner->siteId, 'order_id' => $id] as $column => $value) {
                        if (($value === null) !== ($line->{$column} === null) || ($value !== null && (string) $value !== (string) $line->{$column})) {
                            throw new OrderRefused;
                        }
                    }
                }
                if ($connection->table('commerce_order_access_tokens')->useWritePdo()->where('order_id', $id)->lockForUpdate()->first() !== null) {
                    throw new OrderRefused;
                }
                app(OrderReaderData::class)->project($connection, $owner->orgId, $stored, lock: true);
                if (! hash_equals($current['hash'], $this->quotes->capture($request, $quote['details'], $org, $site)['hash'])) {
                    throw new OrderRefused('stale');
                }
                $connection->afterCommit(fn () => $this->access->requestLink($request, $data['reference']));

                return $order;
            });
        } catch (OrderRefused $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new OrderRefused;
        }
    }

    public function cancel(Request $request, mixed $reference): void
    {
        try {
            $this->locks->run(function () use ($request, $reference): void {
                $this->cancelRecord($this->access->owned($request, $reference) ?? throw new OrderRefused);
            });
        } catch (OrderRefused $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new OrderRefused;
        }
    }

    /** The same transition for a signed-in org owner, never a second admin implementation. @internal */
    public function cancelAsOwner(mixed $reference): void
    {
        if (! is_string($reference) || preg_match('/\A[a-f0-9]{32}\z/D', $reference) !== 1) {
            throw new OrderRefused;
        }
        $this->owner();
        try {
            $this->locks->run(function () use ($reference): void {
                $this->owner();
                $order = $this->current()->where('reference', $reference)->first() ?? throw new OrderRefused;
                $this->cancelRecord($order);
                $this->owner();
            });
        } catch (Throwable) {
            throw new OrderRefused;
        }
    }

    /**
     * Org and Site were already proved by OrderLocks. An ordinary coherence EXISTS retains an older host
     * transaction's snapshot on MySQL/MariaDB even when the outer query locks, hiding fresh and own inserted rows.
     * Keep SiteScope and explicit exact stamps; bypass only that redundant subquery at this authoritative seam.
     *
     * @return Builder<Order>
     */
    private function current(): Builder
    {
        if ((new Order)->getConnection()->transactionLevel() < 1) {
            throw new OrderRefused;
        }

        return Order::query()->withoutGlobalScope('order_coherence')->useWritePdo()
            ->where('commerce_orders.org_id', $this->context->orgId() ?? throw new OrderRefused)
            ->where('commerce_orders.site_id', $this->context->siteId() ?? throw new OrderRefused)->lockForUpdate();
    }

    private function owner(): void
    {
        $actor = Permissions::currentUser();
        if ($actor === null || ! Permissions::isOwner($actor)) {
            throw new OrderRefused;
        }
    }

    private function cancelRecord(Order $order): void
    {
        $raw = $order->getAttributes();
        $connection = $order->getConnection();
        $orgId = $this->context->orgId() ?? throw new OrderRefused;
        app(OrderReaderData::class)->project($connection, $orgId, (object) $raw, lock: true);
        if ($raw['state'] === 'cancelled') {
            return;
        }
        $children = $this->children($order);
        $changes = ['state' => 'cancelled', 'active_cart_key' => null, 'cancelled_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s')];
        if ($connection->table('commerce_orders')->where('org_id', $this->context->orgId())->where('site_id', $this->context->siteId())
            ->where('id', $order->getKey())->where('state', 'prepared')->whereNull('confirmed_total_minor')
            ->update($changes) !== 1 || ! $this->auditor->recordOrFail('commerce.order_cancelled', $order)->exists) {
            throw new OrderRefused;
        }
        $stored = $connection->table('commerce_orders')->useWritePdo()->where('id', $order->getKey())->lockForUpdate()->first();
        foreach ([...$raw, ...$changes] as $column => $value) {
            if ($stored === null || ($value === null) !== ($stored->{$column} === null) || ($value !== null && (string) $value !== (string) $stored->{$column})) {
                throw new OrderRefused;
            }
        }
        if ($this->children($order) !== $children) {
            throw new OrderRefused;
        }
        app(OrderReaderData::class)->project($connection, $orgId, $stored, lock: true);
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function children(Order $order): array
    {
        $result = [];
        foreach (['commerce_order_lines', 'commerce_order_access_tokens'] as $table) {
            $result[$table] = $order->getConnection()->table($table)->useWritePdo()->where('order_id', $order->getKey())
                ->orderBy('id')->limit(21)->lockForUpdate()->get()->map(static fn (object $row): array => (array) $row)->all();
        }

        return $result;
    }
}
