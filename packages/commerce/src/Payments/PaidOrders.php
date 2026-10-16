<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Payments;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Kitsune\Commerce\Carts\CartAmounts;
use Kitsune\Commerce\Inventory\StockWriter;
use Kitsune\Commerce\Money\MinorUnits;
use Kitsune\Commerce\Privacy\PrivacyState;
use Kitsune\Core\Tenancy\Context;

/** Atomic test-payment receipt, one availability decision and its exact refund obligation. @internal */
final class PaidOrders
{
    public const RECEIPTS = 'commerce_payment_receipts';

    public const ALLOCATIONS = 'commerce_order_allocations';

    public const OBLIGATIONS = 'commerce_refund_obligations';

    public const ATTEMPTS = 'commerce_refund_attempts';

    public const MAX_REFUND_ATTEMPTS = 10;

    /** Called only after authoritative expanded Session proof, inside OrderLocks. Never performs I/O.
     * @param  array<string,mixed>  $session
     */
    public function confirm(Connection $connection, object $order, object $attempt, array $session): void
    {
        $context = app(Context::class);
        if ($connection->transactionLevel() < 1 || $context->orgId() !== (int) $order->org_id
            || $context->siteId() !== (int) $order->site_id || ! in_array($order->mode, ['test', 'live'], true)
            || ! in_array($order->state, ['prepared', 'cancelled', 'paid'], true)) {
            throw new CheckoutRefused;
        }
        $privacy = app(PrivacyState::class)->checked($connection, $order, true);
        $erased = $privacy['subject_erased'] ?? false;
        CheckoutSession::assertMatches($attempt, $session, $session['id']);
        $payment = $session['payment'] ?? null;
        if (CheckoutSession::state($session) !== 'completed' || ! is_array($payment)
            || ($payment['test'] ?? null) !== StripeMode::isTest($order->mode) || StripeAmounts::fromWire($payment['amount'], $order->currency) !== MinorUnits::integer($order->quoted_total_minor)
            || $payment['received'] !== $payment['amount'] || $payment['captured'] !== $payment['amount']
            || strtoupper($payment['currency']) !== $order->currency) {
            throw new CheckoutRefused('changed');
        }
        $prior = $this->receipt($connection, $order, true);
        if ($prior !== null) {
            foreach (['payment_intent_id' => 'intentId', 'charge_id' => 'chargeId'] as $column => $key) {
                if ($prior->{$column} !== $payment[$key]) {
                    throw new CheckoutRefused('changed');
                }
            }
            if ((string) $prior->attempt_id !== (string) $attempt->id || $prior->session_id !== $session['id']) {
                throw new CheckoutRefused('changed');
            }
            $this->projection($connection, (int) $order->org_id, $order, true);

            return;
        }
        if ($order->state === 'paid' || $order->confirmed_total_minor !== null) {
            throw new CheckoutRefused('changed');
        }
        $charged = StripeAmounts::fromWire($payment['amount'], $order->currency);
        $now = self::now();
        $held = $erased || $payment['disputed'] || $payment['refunded'] !== '0';
        $id = $connection->table(self::RECEIPTS)->insertGetId(['org_id' => $order->org_id, 'site_id' => $order->site_id,
            'order_id' => $order->id, 'attempt_id' => $attempt->id, 'account_id' => $attempt->account_id, 'mode' => $order->mode,
            'api_version' => $attempt->api_version, 'session_id' => $session['id'], 'payment_intent_id' => $payment['intentId'],
            'charge_id' => $payment['chargeId'], 'currency' => $order->currency, 'amount_minor' => $charged,
            'allocation_state' => $held ? 'held' : 'allocated', 'category' => $held ? ($erased ? 'privacy_erased' : ($payment['disputed'] ? 'disputed' : 'external_refund')) : null,
            'verified_at' => $now]);
        if (! $held) {
            $lines = $connection->table('commerce_order_lines')->useWritePdo()->where('order_id', $order->id)
                ->orderBy('id')->lockForUpdate()->get();
            if ($lines->isEmpty() || $lines->count() > 20) {
                throw new CheckoutRefused;
            }
            $sellables = $connection->table('commerce_sellables')->useWritePdo()->where('org_id', $order->org_id)
                ->whereIn('id', $lines->pluck('sellable_ref')->all())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $shippingWeights = $lines->map(static fn (object $line): string => $line->delivery_kind === 'physical' ? MinorUnits::integer($line->net_minor) : '0')->all();
            if (array_filter($shippingWeights, static fn (string $weight): bool => $weight !== '0') === []) {
                $shippingWeights = $lines->map(static fn (object $line): string => $line->delivery_kind === 'physical' ? (string) $line->quantity : '0')->all();
            }
            $shippingNet = MinorUnits::integer($order->quoted_shipping_net_minor);
            $itemTax = '0';
            foreach ($lines as $line) {
                $itemTax = CartAmounts::add($itemTax, MinorUnits::integer($line->tax_minor));
            }
            $shippingTax = AllocationAmounts::subtract(MinorUnits::integer($order->quoted_tax_minor), $itemTax);
            $shipNetParts = AllocationAmounts::split($shippingNet, $shippingWeights);
            $shipTaxParts = AllocationAmounts::split($shippingTax, $shippingWeights);
            $refund = '0';
            foreach ($lines as $index => $line) {
                self::child($line, $order);
                $quantity = (int) $line->quantity;
                $sellable = $sellables->get($line->sellable_ref);
                $awarded = 0;
                if ($order->state !== 'cancelled' && $sellable !== null && $sellable->currency === $order->currency && $this->available($connection, $order, $line)) {
                    $stock = $sellable->stock === null ? null : MinorUnits::integer($sellable->stock);
                    $awarded = $stock === null || AllocationAmounts::compare($stock, (string) $quantity) >= 0 ? $quantity : (int) $stock;
                    if ($awarded > 0) {
                        app(StockWriter::class)->recordSale($connection, (int) $order->org_id, (int) $order->site_id,
                            $sellable, $awarded, $order->reference, (string) $line->id);
                        // Two old lines can reference one shared product; the second must see the first allocation.
                        if ($stock !== null) {
                            $sellable->stock = AllocationAmounts::subtract($stock, (string) $awarded);
                        }
                    }
                }
                $parts = [];
                foreach (['item_net_minor' => MinorUnits::integer($line->net_minor), 'item_tax_minor' => MinorUnits::integer($line->tax_minor),
                    'shipping_net_minor' => $shipNetParts[$index], 'shipping_tax_minor' => $shipTaxParts[$index]] as $key => $amount) {
                    // First units win allocation; stable unit order also decides the original minor-unit remainders.
                    $unitParts = AllocationAmounts::split($amount, array_fill(0, $quantity, '1'));
                    $owed = '0';
                    foreach (array_slice($unitParts, $awarded) as $part) {
                        $owed = CartAmounts::add($owed, $part);
                    }
                    $parts['refund_'.$key] = $owed;
                    $refund = CartAmounts::add($refund, $owed);
                }
                $connection->table(self::ALLOCATIONS)->insert(['org_id' => $order->org_id, 'site_id' => $order->site_id,
                    'order_id' => $order->id, 'line_id' => $line->id, 'awarded' => $awarded, 'unavailable' => $quantity - $awarded,
                    ...$parts, 'created_at' => $now]);
            }
            if ($refund !== '0') {
                $obligation = $connection->table(self::OBLIGATIONS)->insertGetId(['org_id' => $order->org_id, 'site_id' => $order->site_id,
                    'order_id' => $order->id, 'payment_id' => $id, 'reference' => bin2hex(random_bytes(16)), 'amount_minor' => $refund,
                    'reason' => $order->state === 'cancelled' ? 'cancelled_before_confirmation' : 'unavailable_items', 'created_at' => $now]);
                if (self::encodable($refund, $order->currency)) {
                    $this->newRefundAttempt($connection, $order, (int) $obligation, $payment['chargeId'], $refund);
                }
            }
        }
        if ($connection->table('commerce_orders')->where('id', $order->id)->where('org_id', $order->org_id)->where('site_id', $order->site_id)
            ->where('state', $order->state)->whereNull('confirmed_total_minor')->update(['state' => 'paid', 'confirmed_total_minor' => $charged, 'active_cart_key' => null]) !== 1) {
            throw new CheckoutRefused;
        }
        $paidOrder = (object) [...get_object_vars($order), 'state' => 'paid', 'confirmed_total_minor' => $charged, 'active_cart_key' => null];
        app(PrivacyState::class)->paidTransition($connection, $order, $paidOrder);
        app(AccessFulfillment::class)->freeze($connection, $paidOrder);
        app(PrivacyState::class)->freezePaid($connection, $paidOrder);
        app(PaidConfirmationAudit::class)->record($connection, $paidOrder);
    }

    /** A new identity is legal only for a never-submitted obligation or after an explicit verified failed/cancelled retry. */
    public function newRefundAttempt(Connection $connection, object $order, int $obligation, string $charge, string $amount): void
    {
        if ($connection->transactionLevel() < 1) {
            throw new CheckoutRefused;
        }
        $context = app(Context::class);
        if ($context->orgId() !== (int) $order->org_id || $context->siteId() !== (int) $order->site_id || ! in_array($order->mode, ['test', 'live'], true)) {
            throw new CheckoutRefused;
        }
        $owed = $connection->table(self::OBLIGATIONS)->useWritePdo()->where('id', $obligation)->lockForUpdate()->first() ?? throw new CheckoutRefused;
        self::child($owed, $order);
        $payment = $connection->table(self::RECEIPTS)->useWritePdo()->where('id', $owed->payment_id)->first() ?? throw new CheckoutRefused;
        self::child($payment, $order);
        $old = $connection->table(self::ATTEMPTS)->useWritePdo()->where('obligation_id', $obligation)->orderBy('id')->lockForUpdate()->get();
        if ($payment->charge_id !== $charge || MinorUnits::integer($owed->amount_minor) !== $amount || $amount === '0'
            || $old->count() >= self::MAX_REFUND_ATTEMPTS) {
            throw new CheckoutRefused;
        }
        foreach ($old as $row) {
            self::child($row, $order);
            if (! in_array($row->state, ['failed', 'canceled'], true) || $row->refund_id === null || $row->lease_token !== null) {
                throw new CheckoutRefused;
            }
        }
        $wireAmount = StripeAmounts::toWire($amount, $order->currency);
        $reference = bin2hex(random_bytes(16));
        $metadata = ['kitsune_order' => $order->reference, 'kitsune_refund' => $reference,
            'kitsune_request' => CheckoutAttempts::digest(['order' => $order->reference, 'refund' => $reference, 'charge' => $charge, 'amount' => $wireAmount])];
        $payload = ['charge' => $charge, 'amount' => $wireAmount, 'metadata' => $metadata];
        $connection->table(self::ATTEMPTS)->insert(['org_id' => $order->org_id, 'site_id' => $order->site_id, 'order_id' => $order->id,
            'obligation_id' => $obligation, 'reference' => $reference, 'idempotency_key' => 'kitsune-refund-'.$reference,
            'request_payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'request_hash' => CheckoutAttempts::digest($payload),
            'state' => 'owed', 'refund_id' => null, 'category' => null, 'replay_count' => 0, 'first_submitted_at' => null,
            'lease_token' => null, 'lease_until' => null, 'created_at' => self::now(), 'updated_at' => self::now()]);
    }

    public function receipt(Connection $connection, object $order, bool $lock = false): ?object
    {
        if (! $this->schema($connection)) {
            return null;
        }
        $query = $connection->table(self::RECEIPTS)->useWritePdo()->where('order_id', $order->id)->limit(2);
        $rows = ($lock ? $query->lockForUpdate() : $query)->get();
        if ($rows->count() > 1) {
            throw new CheckoutRefused;
        }
        $row = $rows->first();
        if ($row !== null) {
            self::child($row, $order);
            $attempt = $connection->table(CheckoutAttempts::TABLE)->useWritePdo()->where('id', $row->attempt_id)->first() ?? throw new CheckoutRefused;
            self::child($attempt, $order);
            if ($row->account_id !== $attempt->account_id || $row->session_id !== $attempt->session_id
                || $row->mode !== $order->mode || $attempt->mode !== $order->mode || ! in_array($order->mode, ['test', 'live'], true) || $row->api_version !== $attempt->api_version
                || $row->currency !== $order->currency || MinorUnits::integer($row->amount_minor) !== MinorUnits::integer($order->quoted_total_minor)
                || ! in_array($row->allocation_state, ['allocated', 'held'], true)
                || ($row->allocation_state === 'allocated' && $row->category !== null)
                || ($row->allocation_state === 'held' && ! in_array($row->category, ['disputed', 'external_refund', 'privacy_erased'], true))
                || preg_match('/\Api_[A-Za-z0-9_]{1,95}\z/D', $row->payment_intent_id) !== 1
                || preg_match('/\Ach_[A-Za-z0-9_]{1,95}\z/D', $row->charge_id) !== 1) {
                throw new CheckoutRefused;
            }
            if ($row->category === 'privacy_erased' && ! (app(PrivacyState::class)->checked($connection, $order, $lock)['subject_erased'] ?? false)) {
                throw new CheckoutRefused;
            }
            CheckoutAttempts::instant($row->verified_at);
        }

        return $row;
    }

    /** @return array{payment:?array<string,mixed>,allocations:list<array<string,mixed>>,refund:?array<string,mixed>} */
    public function projection(Connection $connection, int $org, object $order, bool $lock = false): array
    {
        if ((string) $order->org_id !== (string) $org) {
            throw new CheckoutRefused;
        }
        $empty = ['payment' => null, 'allocations' => [], 'refund' => null];
        if (! $this->schema($connection)) {
            if ($order->state === 'paid' || $order->confirmed_total_minor !== null) {
                throw new CheckoutRefused;
            }

            return $empty;
        }
        $receipt = $this->receipt($connection, $order, $lock);
        $children = [];
        foreach ([self::ALLOCATIONS => 20, self::OBLIGATIONS => 1, self::ATTEMPTS => self::MAX_REFUND_ATTEMPTS] as $table => $bound) {
            $query = $connection->table($table)->useWritePdo()->where('order_id', $order->id)->orderBy('id')->limit($bound + 1);
            $rows = ($lock ? $query->lockForUpdate() : $query)->get();
            if ($rows->count() > $bound) {
                throw new CheckoutRefused;
            }
            foreach ($rows as $row) {
                self::child($row, $order);
            }
            $children[$table] = $rows;
        }
        if ($receipt === null) {
            if ($order->state === 'paid' || $order->confirmed_total_minor !== null || array_filter($children, static fn ($rows): bool => $rows->isNotEmpty()) !== []) {
                throw new CheckoutRefused;
            }

            return $empty;
        }
        if ($order->state !== 'paid' || MinorUnits::integer($order->confirmed_total_minor) !== MinorUnits::integer($receipt->amount_minor)) {
            throw new CheckoutRefused;
        }
        $lines = $connection->table('commerce_order_lines')->useWritePdo()->where('order_id', $order->id)->orderBy('id')->get()->keyBy('id');
        $physicalWeights = $lines->values()->map(static fn (object $line): string => $line->delivery_kind === 'physical' ? MinorUnits::integer($line->net_minor) : '0')->all();
        if (array_filter($physicalWeights, static fn (string $v): bool => $v !== '0') === []) {
            $physicalWeights = $lines->values()->map(static fn (object $line): string => $line->delivery_kind === 'physical' ? (string) $line->quantity : '0')->all();
        }
        $itemTax = '0';
        foreach ($lines as $line) {
            $itemTax = CartAmounts::add($itemTax, MinorUnits::integer($line->tax_minor));
        }
        $shippingNet = AllocationAmounts::split(MinorUnits::integer($order->quoted_shipping_net_minor), $physicalWeights);
        $shippingTax = AllocationAmounts::split(AllocationAmounts::subtract(MinorUnits::integer($order->quoted_tax_minor), $itemTax), $physicalWeights);
        $lineIds = $lines->keys()->map(static fn ($id): string => (string) $id)->all();
        $allocations = [];
        $total = '0';
        foreach ($children[self::ALLOCATIONS] as $row) {
            $line = $lines->get($row->line_id) ?? throw new CheckoutRefused;
            self::child($line, $order);
            $awarded = self::allocationQuantity($row->awarded);
            $unavailable = self::allocationQuantity($row->unavailable);
            if ($awarded + $unavailable !== (int) $line->quantity) {
                throw new CheckoutRefused;
            }
            $parts = [];
            foreach (['item_net_minor', 'item_tax_minor', 'shipping_net_minor', 'shipping_tax_minor'] as $part) {
                $parts[$part] = MinorUnits::integer($row->{'refund_'.$part});
                $total = CartAmounts::add($total, $parts[$part]);
            }
            $index = array_search((string) $row->line_id, $lineIds, true);
            if ($index === false) {
                throw new CheckoutRefused;
            }
            foreach (['item_net_minor' => MinorUnits::integer($line->net_minor), 'item_tax_minor' => MinorUnits::integer($line->tax_minor),
                'shipping_net_minor' => $shippingNet[$index], 'shipping_tax_minor' => $shippingTax[$index]] as $part => $original) {
                $expected = '0';
                foreach (array_slice(AllocationAmounts::split($original, array_fill(0, (int) $line->quantity, '1')), $awarded) as $unit) {
                    $expected = CartAmounts::add($expected, $unit);
                }
                if ($parts[$part] !== $expected) {
                    throw new CheckoutRefused;
                }
            }
            if (AllocationAmounts::compare($parts['item_net_minor'], MinorUnits::integer($line->net_minor)) > 0
                || AllocationAmounts::compare($parts['item_tax_minor'], MinorUnits::integer($line->tax_minor)) > 0) {
                throw new CheckoutRefused;
            }
            $allocations[] = ['line_ref' => (string) $line->id, 'sku' => $line->sku, 'awarded' => $awarded,
                'unavailable' => $unavailable, 'refund_components' => $parts];
        }
        if (($receipt->allocation_state === 'allocated' && count($allocations) !== $lines->count())
            || ($receipt->allocation_state === 'held' && $allocations !== [])) {
            throw new CheckoutRefused;
        }
        $obligation = $children[self::OBLIGATIONS]->first();
        $attempts = $children[self::ATTEMPTS];
        if (($total !== '0') !== ($obligation !== null) || ($obligation === null && $attempts->isNotEmpty())) {
            throw new CheckoutRefused;
        }
        $refund = null;
        if ($obligation !== null) {
            if ((string) $obligation->payment_id !== (string) $receipt->id || MinorUnits::integer($obligation->amount_minor) !== $total
                || ! in_array($obligation->reason, ['unavailable_items', 'cancelled_before_confirmation'], true)
                || ! self::hex($obligation->reference, 32) || ($attempts->isEmpty() && self::encodable($total, $order->currency))) {
                throw new CheckoutRefused;
            }
            $history = [];
            foreach ($attempts as $attempt) {
                $this->refundAttempt($attempt, $obligation, $receipt, $order);
                $history[] = ['reference' => $attempt->reference, 'state' => $attempt->state, 'category' => $attempt->category,
                    'updated_at' => CheckoutAttempts::instant($attempt->updated_at)->format('Y-m-d\TH:i:s\Z')];
            }
            $latest = $attempts->last();
            $refund = ['reference' => $obligation->reference, 'amount_minor' => $total, 'reason' => $obligation->reason,
                'state' => $latest->state ?? 'held', 'category' => $latest === null ? 'currency' : $latest->category,
                'owed_minor' => $latest?->state === 'succeeded' ? '0' : $total, 'attempts' => $history];
        }

        return ['payment' => ['amount_minor' => MinorUnits::integer($receipt->amount_minor), 'mode' => $receipt->mode,
            'allocation_state' => $receipt->allocation_state, 'category' => $receipt->category,
            'verified_at' => CheckoutAttempts::instant($receipt->verified_at)->format('Y-m-d\TH:i:s\Z')],
            'allocations' => $allocations, 'refund' => $refund];
    }

    public function assertErasable(Connection $connection, object $order): void
    {
        if ($order->state === 'paid' || $order->confirmed_total_minor !== null || ($this->schema($connection)
            && $connection->table(self::RECEIPTS)->useWritePdo()->where('order_id', $order->id)->exists())) {
            throw new CheckoutRefused('retained');
        }
    }

    /** @return array<string,mixed> */
    public function refundRequest(object $attempt): array
    {
        $payload = json_decode($attempt->request_payload, true, 8, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        if (! is_array($payload) || ! hash_equals($attempt->request_hash, CheckoutAttempts::digest($payload))) {
            throw new CheckoutRefused;
        }

        return $payload;
    }

    public function refundAttempt(object $attempt, object $obligation, object $receipt, object $order): void
    {
        self::child($attempt, $order);
        self::child($obligation, $order);
        self::child($receipt, $order);
        $payload = $this->refundRequest($attempt);
        if ((string) $attempt->obligation_id !== (string) $obligation->id || ! self::hex($attempt->reference, 32)
            || $attempt->idempotency_key !== 'kitsune-refund-'.$attempt->reference || ! self::hex($attempt->request_hash, 64)
            || ! in_array($attempt->state, ['owed', 'submitting', 'unknown', 'held', 'pending', 'requires_action', 'succeeded', 'failed', 'canceled'], true)
            || (in_array($attempt->state, ['pending', 'requires_action', 'succeeded', 'failed', 'canceled'], true) && $attempt->refund_id === null)
            || ($attempt->refund_id !== null && preg_match('/\Are_[A-Za-z0-9_]{1,95}\z/D', $attempt->refund_id) !== 1)
            || (($attempt->lease_token === null) !== ($attempt->lease_until === null))
            || ($attempt->lease_token !== null && ! self::hex($attempt->lease_token, 64))
            || ($attempt->category !== null && ! in_array($attempt->category, ['unavailable', 'changed', 'operator', 'busy', 'refund_failed', 'external_refund'], true))
        ) {
            throw new CheckoutRefused;
        }
        $wireAmount = StripeAmounts::toWire(MinorUnits::integer($obligation->amount_minor), $order->currency);
        $expected = ['charge' => $receipt->charge_id, 'amount' => $wireAmount,
            'metadata' => ['kitsune_order' => $order->reference, 'kitsune_refund' => $attempt->reference,
                'kitsune_request' => CheckoutAttempts::digest(['order' => $order->reference, 'refund' => $attempt->reference,
                    'charge' => $receipt->charge_id, 'amount' => $wireAmount])]];
        if (CheckoutAttempts::digest($payload) !== CheckoutAttempts::digest($expected)) {
            throw new CheckoutRefused;
        }
        foreach (['created_at', 'updated_at', 'first_submitted_at', 'lease_until'] as $column) {
            if ($attempt->{$column} !== null) {
                CheckoutAttempts::instant($attempt->{$column});
            }
        }
    }

    private function schema(Connection $connection): bool
    {
        $parents = ['id', 'org_id', 'site_id', 'order_id'];
        $tables = [
            self::RECEIPTS => [...$parents, 'attempt_id', 'account_id', 'mode', 'api_version', 'session_id', 'payment_intent_id', 'charge_id',
                'currency', 'amount_minor', 'allocation_state', 'category', 'verified_at'],
            self::ALLOCATIONS => [...$parents, 'line_id', 'awarded', 'unavailable', 'refund_item_net_minor', 'refund_item_tax_minor',
                'refund_shipping_net_minor', 'refund_shipping_tax_minor', 'created_at'],
            self::OBLIGATIONS => [...$parents, 'payment_id', 'reference', 'amount_minor', 'reason', 'created_at'],
            self::ATTEMPTS => [...$parents, 'obligation_id', 'reference', 'idempotency_key', 'request_payload', 'request_hash', 'state',
                'refund_id', 'category', 'replay_count', 'first_submitted_at', 'lease_token', 'lease_until', 'created_at', 'updated_at'],
        ];
        $present = array_filter(array_keys($tables), $connection->getSchemaBuilder()->hasTable(...));
        if (count($present) === count($tables)) {
            foreach ($tables as $table => $columns) {
                if (! $connection->getSchemaBuilder()->hasColumns($table, $columns)) {
                    throw new CheckoutRefused;
                }
            }

            return true;
        }
        if ($present !== [] || $connection->table('migrations')->useWritePdo()->where('migration', '0001_01_01_000209_create_commerce_paid_custody')->exists()) {
            throw new CheckoutRefused;
        }

        return false;
    }

    private function available(Connection $connection, object $order, object $line): bool
    {
        return $connection->table('commerce_listings as l')->useWritePdo()->join('entries as e', 'e.id', '=', 'l.entry_id')
            ->where('l.id', $line->listing_ref)->where('l.org_id', $order->org_id)->where('l.site_id', $order->site_id)
            ->where('l.sellable_id', $line->sellable_ref)->where('l.entry_id', $line->entry_ref)->where('l.is_available', true)
            ->where('e.org_id', $order->org_id)->where('e.site_id', $order->site_id)->whereNull('e.deleted_at')->exists();
    }

    private static function child(object $row, object $order): void
    {
        if ((string) $row->org_id !== (string) $order->org_id || (string) $row->site_id !== (string) $order->site_id
            || (string) $row->order_id !== (string) $order->id) {
            throw new CheckoutRefused;
        }
    }

    private static function allocationQuantity(mixed $value): int
    {
        if ((! is_int($value) && ! is_string($value)) || preg_match('/\A(?:0|[1-9][0-9]?)\z/D', (string) $value) !== 1) {
            throw new CheckoutRefused;
        }

        return (int) $value;
    }

    private static function encodable(string $amount, string $currency): bool
    {
        try {
            StripeAmounts::toWire($amount, $currency);

            return true;
        } catch (StripeUnavailable) {
            return false;
        }
    }

    private static function hex(mixed $value, int $length): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{'.$length.'}\z/D', $value) === 1;
    }

    private static function now(): string
    {
        return CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
    }
}
