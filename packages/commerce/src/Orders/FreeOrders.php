<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Orders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Kitsune\Commerce\Inventory\StockWriter;
use Kitsune\Commerce\Models\Order;
use Kitsune\Commerce\Money\MinorUnits;
use Kitsune\Commerce\Payments\AccessFulfillment;
use Kitsune\Commerce\Payments\AllocationAmounts;
use Kitsune\Commerce\Payments\CheckoutAttempts;
use Kitsune\Commerce\Payments\CheckoutReview;
use Kitsune\Commerce\Payments\PaidConfirmationAudit;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Commerce\Privacy\PrivacyState;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Tenancy\Context;
use Throwable;

/** Buyer-authorized zero-total completion. Durable authority and availability, without any payment provider. @internal */
final class FreeOrders
{
    public const TABLE = 'commerce_free_completions';

    public function __construct(private readonly OrderLocks $locks, private readonly Context $context) {}

    /** @return array{state:string,reference:string,completed_at:string} */
    public function complete(Request $request, mixed $reference, mixed $review): array
    {
        try {
            $connection = (new Order)->getConnection();
            if ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()
                || ! is_string($reference) || preg_match('/\A[a-f0-9]{32}\z/D', $reference) !== 1
                || ! is_string($review) || preg_match('/\A[a-f0-9]{64}\z/D', $review) !== 1) {
                throw new OrderRefused;
            }
            $result = $this->locks->run(function () use ($request, $reference, $review, $connection): array {
                $module = $connection->table('modules')->useWritePdo()->where('handle', 'kitsune/commerce')->where('is_enabled', true)->lockForUpdate()->first();
                if ($module === null || $module->version !== ModuleDiscovery::version('kitsune/commerce') || ! $this->schema($connection)) {
                    throw new OrderRefused('setup');
                }
                $model = app(OrderAccess::class)->owned($request, $reference) ?? throw new OrderRefused;
                $order = (object) $model->getAttributes();
                app(PrivacyState::class)->assertAccessAllowed($connection, $order);
                if ($order->state === 'completed_free') {
                    $receipt = $this->receipt($connection, $order, true) ?? throw new OrderRefused;
                    if (! hash_equals($receipt->request_hash, hash('sha256', $review))) {
                        throw new OrderRefused('stale');
                    }

                    return $this->result($receipt);
                }
                // No uncertain, historical or planted provider custody may be converted into a free purchase.
                foreach ([CheckoutAttempts::TABLE, PaidOrders::RECEIPTS, PaidOrders::OBLIGATIONS, PaidOrders::ATTEMPTS] as $table) {
                    if ($connection->table($table)->useWritePdo()->where('order_id', $order->id)->lockForUpdate()->exists()) {
                        throw new OrderRefused;
                    }
                }
                $approved = app(CheckoutReview::class)->approve($request, $reference, $review, free: true);
                if (MinorUnits::integer($order->quoted_total_minor) !== '0' || $order->state !== 'prepared') {
                    throw new OrderRefused;
                }
                $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
                $lines = $connection->table('commerce_order_lines')->useWritePdo()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
                $products = $connection->table('commerce_sellables')->useWritePdo()->where('org_id', $order->org_id)
                    ->whereIn('id', $lines->pluck('sellable_ref')->all())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                foreach ($lines as $line) {
                    $product = $products->get($line->sellable_ref) ?? throw new OrderRefused('stale');
                    $quantity = (int) $line->quantity;
                    $stock = $product->stock === null ? null : MinorUnits::integer($product->stock);
                    $awarded = $stock === null || strlen($stock) > 2 ? $quantity : min($quantity, (int) $stock);
                    if ($awarded > 0) {
                        app(StockWriter::class)->recordSale($connection, (int) $order->org_id, (int) $order->site_id, $product, $awarded, $order->reference, (string) $line->id, free: true);
                        if ($stock !== null) {
                            $product->stock = AllocationAmounts::subtract($stock, (string) $awarded);
                        }
                    }
                    $connection->table(PaidOrders::ALLOCATIONS)->insert(['org_id' => $order->org_id, 'site_id' => $order->site_id,
                        'order_id' => $order->id, 'line_id' => $line->id, 'awarded' => $awarded, 'unavailable' => $quantity - $awarded,
                        'refund_item_net_minor' => '0', 'refund_item_tax_minor' => '0', 'refund_shipping_net_minor' => '0', 'refund_shipping_tax_minor' => '0', 'created_at' => $now]);
                }
                $allocations = $this->allocations($connection, $order, true);
                $id = $connection->table(self::TABLE)->insertGetId(['org_id' => $order->org_id, 'site_id' => $order->site_id, 'order_id' => $order->id,
                    'reference' => bin2hex(random_bytes(16)), 'request_hash' => hash('sha256', $review), 'order_hash' => $approved['orderHash'],
                    'purchase_hash' => $this->purchaseHash($connection, $order), 'allocation_hash' => CheckoutAttempts::digest(['allocations' => $allocations]), 'completed_at' => $now]);
                if ($connection->table('commerce_orders')->where('id', $order->id)->where('org_id', $order->org_id)->where('site_id', $order->site_id)
                    ->where('state', 'prepared')->whereNull('confirmed_total_minor')->update(['state' => 'completed_free', 'confirmed_total_minor' => '0', 'active_cart_key' => null]) !== 1) {
                    throw new OrderRefused;
                }
                $order = $connection->table('commerce_orders')->useWritePdo()->where('id', $order->id)->sole();
                app(FreeAccessCorrections::class)->prepare($connection, $order);
                app(AccessFulfillment::class)->freeze($connection, $order);
                app(PaidConfirmationAudit::class)->record($connection, $order);
                app(OrderReaderData::class)->record($connection, $order);
                $receipt = $this->receipt($connection, $order, true) ?? throw new OrderRefused;
                if ((string) $receipt->id !== (string) $id || ! hash_equals($receipt->request_hash, hash('sha256', $review))) {
                    throw new OrderRefused;
                }
                // Audit callbacks may change the reader guard or browser proof as well as persisted rows.
                $completedGraph = app(PaidConfirmationAudit::class)->witness($connection, $order);
                $owned = app(OrderAccess::class)->owned($request, $reference) ?? throw new OrderRefused;
                if ($owned->getKey() !== $model->getKey() || $completedGraph !== app(PaidConfirmationAudit::class)->witness($connection, $order)) {
                    throw new OrderRefused;
                }

                return $this->result($receipt);
            });
        } catch (OrderRefused $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new OrderRefused;
        }
        // Access is recoverable separately after the actual completion commit. Guests still explicitly claim.
        try {
            app(AccessFulfillment::class)->reconcileReader($request, $reference);
        } catch (Throwable) {
            // The durable order page exposes queued/held access and its existing retry action.
        }

        return $result;
    }

    public function schema(Connection $connection): bool
    {
        if (! $connection->getSchemaBuilder()->hasTable(self::TABLE)) {
            if ($connection->table('migrations')->useWritePdo()->where('migration', '0001_01_01_000220_create_commerce_free_completion')->exists()) {
                throw new OrderRefused('setup');
            }

            return false;
        }
        if (! $connection->getSchemaBuilder()->hasColumns(self::TABLE, ['id', 'org_id', 'site_id', 'order_id', 'reference', 'request_hash', 'order_hash', 'purchase_hash', 'allocation_hash', 'completed_at'])) {
            throw new OrderRefused('setup');
        }

        return true;
    }

    public function receipt(Connection $connection, object $order, bool $lock = false): ?object
    {
        if ($this->context->orgId() !== (int) $order->org_id) {
            throw new OrderRefused;
        }
        if (! $this->schema($connection)) {
            return $order->state === 'completed_free' ? throw new OrderRefused('setup') : null;
        }
        $rows = $connection->table(self::TABLE)->useWritePdo()->where('order_id', $order->id)->limit(2)
            ->when($lock, fn ($q) => $q->lockForUpdate())->get();
        if ($rows->count() > 1 || ($rows->isNotEmpty() !== ($order->state === 'completed_free'))) {
            throw new OrderRefused;
        }
        $row = $rows->first();
        if ($row === null) {
            foreach ([FreeAccessCorrections::WORK, FreeAccessCorrections::TABLE] as $table) {
                if ($connection->table($table)->useWritePdo()->where('order_id', $order->id)->when($lock, fn ($q) => $q->lockForUpdate())->exists()) {
                    throw new OrderRefused;
                }
            }

            return null;
        }
        if ((string) $row->org_id !== (string) $order->org_id || (string) $row->site_id !== (string) $order->site_id
            || MinorUnits::integer($order->quoted_total_minor) !== '0' || MinorUnits::integer($order->confirmed_total_minor) !== '0'
            || $order->active_cart_key !== null || $order->cancelled_at !== null) {
            throw new OrderRefused;
        }
        foreach (['request_hash', 'order_hash', 'purchase_hash', 'allocation_hash'] as $key) {
            if (! is_string($row->{$key}) || preg_match('/\A[a-f0-9]{64}\z/D', $row->{$key}) !== 1) {
                throw new OrderRefused;
            }
        }
        if (! is_string($row->reference) || preg_match('/\A[a-f0-9]{32}\z/D', $row->reference) !== 1
            || ! hash_equals($row->purchase_hash, $this->purchaseHash($connection, $order))
            || ! hash_equals($row->allocation_hash, CheckoutAttempts::digest(['allocations' => $this->allocations($connection, $order, $lock)]))) {
            throw new OrderRefused;
        }
        CheckoutAttempts::instant($row->completed_at);
        app(PrivacyState::class)->checked($connection, $order, $lock);
        foreach ([CheckoutAttempts::TABLE, PaidOrders::RECEIPTS, PaidOrders::OBLIGATIONS, PaidOrders::ATTEMPTS] as $table) {
            if ($connection->table($table)->useWritePdo()->where('order_id', $order->id)->when($lock, fn ($q) => $q->lockForUpdate())->exists()) {
                throw new OrderRefused;
            }
        }

        return $row;
    }

    /** @return array<string,mixed> */
    public function projection(Connection $connection, object $order, bool $lock = false): array
    {
        $receipt = $this->receipt($connection, $order, $lock) ?? throw new OrderRefused;
        $lines = $connection->table('commerce_order_lines')->useWritePdo()->where('order_id', $order->id)->get()->keyBy('id');
        $allocations = array_map(static fn (array $row): array => ['line_ref' => (string) $row['line_id'], 'sku' => $lines->get($row['line_id'])->sku,
            'awarded' => (int) $row['awarded'], 'unavailable' => (int) $row['unavailable'],
            'refund_components' => ['item_net_minor' => '0', 'item_tax_minor' => '0', 'shipping_net_minor' => '0', 'shipping_tax_minor' => '0']], $this->allocations($connection, $order, $lock));

        return ['payment' => null, 'allocations' => $allocations, 'refund' => null,
            'free_completion' => $this->result($receipt)];
    }

    /** @return list<array<string,mixed>> */
    private function allocations(Connection $connection, object $order, bool $lock): array
    {
        $lines = $connection->table('commerce_order_lines')->useWritePdo()->where('order_id', $order->id)->orderBy('id')->limit(21)
            ->when($lock, fn ($q) => $q->lockForUpdate())->get()->keyBy('id');
        $rows = $connection->table(PaidOrders::ALLOCATIONS)->useWritePdo()->where('order_id', $order->id)->orderBy('id')->limit(21)
            ->when($lock, fn ($q) => $q->lockForUpdate())->get();
        if ($lines->isEmpty() || $lines->count() > 20 || $rows->count() !== $lines->count()) {
            throw new OrderRefused;
        }
        $seen = [];
        foreach ($rows as $row) {
            $line = $lines->get($row->line_id) ?? throw new OrderRefused;
            foreach ([$line, $row] as $child) {
                if ((string) $child->org_id !== (string) $order->org_id || (string) $child->site_id !== (string) $order->site_id) {
                    throw new OrderRefused;
                }
            }
            $awarded = AccessFulfillment::counter($row->awarded, 99);
            $unavailable = AccessFulfillment::counter($row->unavailable, 99);
            if ($awarded + $unavailable !== (int) $line->quantity || isset($seen[$row->line_id])) {
                throw new OrderRefused;
            }
            foreach (['refund_item_net_minor', 'refund_item_tax_minor', 'refund_shipping_net_minor', 'refund_shipping_tax_minor'] as $key) {
                if (MinorUnits::integer($row->{$key}) !== '0') {
                    throw new OrderRefused;
                }
            }
            CheckoutAttempts::instant($row->created_at);
            $seen[$row->line_id] = true;
        }

        return $rows->map(static fn (object $r): array => get_object_vars($r))->all();
    }

    private function purchaseHash(Connection $connection, object $order): string
    {
        $keys = ['id', 'org_id', 'site_id', 'reference', 'mode', 'currency', 'tax_mode', 'snapshot_hash', 'created_at',
            'quoted_item_net_minor', 'quoted_shipping_net_minor', 'quoted_tax_minor', 'quoted_total_minor'];
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = (string) $order->{$key};
        }
        $lines = $connection->table('commerce_order_lines')->useWritePdo()->where('order_id', $order->id)->orderBy('id')->limit(21)->get()
            ->map(static fn (object $row): array => array_map(static fn ($value) => $value === null ? null : (string) $value, get_object_vars($row)))->all();

        return CheckoutAttempts::digest(['order' => $values, 'lines' => $lines]);
    }

    /** @return array{state:string,reference:string,completed_at:string} */
    private function result(object $row): array
    {
        return ['state' => 'completed_free', 'reference' => $row->reference,
            'completed_at' => CheckoutAttempts::instant($row->completed_at)->format('Y-m-d\TH:i:s\Z')];
    }
}
