<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Exports;

use Illuminate\Database\Connection;
use Kitsune\Commerce\Money\MinorUnits;
use Kitsune\Commerce\Orders\OrderClaims;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Commerce\Payments\CheckoutAttempts;
use Kitsune\Commerce\Payments\FinancialState;
use Kitsune\Commerce\Privacy\PrivacyState;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Tenancy\Concerns\ReadsWrittenKeys;
use Kitsune\Core\Tenancy\Context;
use Throwable;

/** Explicit financial ledger over checked historical custody; never a host-reader or provider lookup. @internal */
final class OrderExportRecords
{
    use ReadsWrittenKeys;

    public function __construct(private readonly Context $context, private readonly OrderReaderData $orders,
        private readonly OrderClaims $claims, private readonly FinancialState $financial, private readonly PrivacyState $privacy) {}

    /** The caller owns authorization and Org→Site→Order locks. The entire result is checked before it is returned.
     * @return list<array<string,mixed>>
     */
    public function forOrder(Connection $connection, #[\SensitiveParameter] object $order, string $orgSlug, bool $includeBilling): iterable
    {
        try {
            return $this->records($connection, $order, $orgSlug, $includeBilling);
        } catch (Throwable) {
            throw new ExportRefused;
        }
    }

    /** @return list<array<string,mixed>> */
    private function records(Connection $connection, #[\SensitiveParameter] object $order, string $orgSlug, bool $includeBilling): array
    {
        $orgId = self::writtenKey($order->org_id ?? null);
        $siteId = self::writtenKey($order->site_id ?? null);
        $orderId = self::writtenKey($order->id ?? null);
        if ($orgId === null || $orgId < 1 || $siteId === null || $siteId < 1 || $orderId === null || $orderId < 1
            || $this->context->orgId() !== $orgId || $connection->transactionLevel() < 1 || ! $connection->getPdo()->inTransaction()) {
            throw new ExportRefused;
        }
        $org = $connection->table('orgs')->useWritePdo()->where('id', $orgId)->whereNull('deleted_at')->lockForUpdate()->first();
        $site = $connection->table('sites')->useWritePdo()->where('id', $siteId)->where('org_id', $orgId)->lockForUpdate()->first();
        $actual = $connection->table('commerce_orders')->useWritePdo()->where('id', $orderId)->where('org_id', $orgId)
            ->where('site_id', $siteId)->lockForUpdate()->first();
        if ($org === null || $org->slug !== $orgSlug || $site === null || $actual === null
            || CheckoutAttempts::digest((array) $actual) !== CheckoutAttempts::digest((array) $order)) {
            throw new ExportRefused;
        }
        $record = $this->orders->record($connection, $actual);
        $state = $this->privacy->checked($connection, $actual, true);
        $claim = $this->claims->forOrder($connection, $actual, true);
        $reader = $claim->reader_id ?? $actual->reader_id;
        $erased = $state !== null && $state['subject_erased'];
        if ($reader !== null && (! is_string($reader) || ReaderGuard::stored($reader) !== $reader)) {
            throw new ExportRefused;
        }
        if ($erased && $reader !== null) {
            throw new ExportRefused;
        }
        $kind = $erased ? 'erased' : ($reader === null ? 'guest' : 'reader');
        $customer = 'customer:'.hash('sha256', $reader !== null
            ? "kitsune.commerce.export.reader.v1\0".$orgId."\0".$reader
            : "kitsune.commerce.export.order.v1\0".$orgId."\0".$siteId."\0".$actual->reference."\0".$kind);
        $base = ['schema' => 'kitsune.commerce.export', 'version' => 1, 'org' => $orgSlug, 'site' => $site->handle,
            'order_reference' => $actual->reference, 'customer_reference' => $customer];
        $records = [self::row($base, 'customer', $customer, null, ['customer_kind' => $kind], customer: true)];
        $orderData = self::fields($record, ['state', 'mode', 'currency', 'tax_mode', 'quoted_item_net_minor',
            'quoted_shipping_net_minor', 'quoted_tax_minor', 'quoted_total_minor', 'confirmed_total_minor']);
        $orderData += ['created_at' => self::instant($record['created_at']), 'cancelled_at' => self::instant($record['cancelled_at']),
            'site_name' => $record['site_snapshot']['name'], 'site_base_url' => $record['site_snapshot']['base_url']];
        if ($record['financial_state'] !== null) {
            $orderData['observed_at'] = self::instant($record['financial_state']['observed_at']);
        }
        $records[] = self::row($base, 'order', $actual->reference, $customer, $orderData);

        // Parent-first lookup exposes a foreign-stamped planted child instead of hiding it behind a scope predicate.
        $lines = $connection->table('commerce_order_lines')->useWritePdo()->where('order_id', $orderId)
            ->orderBy('id')->limit(OrderReaderData::MAX_LINES + 1)->lockForUpdate()->get()->all();
        if (count($lines) !== count($record['lines']) || $lines === [] || count($lines) > OrderReaderData::MAX_LINES) {
            throw new ExportRefused;
        }
        $lineReferences = [];
        foreach ($lines as $index => $line) {
            $lineId = self::writtenKey($line->id);
            if ($lineId === null || $lineId < 1 || self::writtenKey($line->order_id) !== $orderId
                || self::writtenKey($line->org_id) !== $orgId || self::writtenKey($line->site_id) !== $siteId) {
                throw new ExportRefused;
            }
            $item = $record['lines'][$index];
            if ($line->sku !== $item['sku'] || $line->title !== $item['title']) {
                throw new ExportRefused;
            }
            $reference = $lineReferences[$lineId] = 'line:'.$lineId;
            $data = self::fields($item, ['sku', 'title', 'quantity', 'unit_price_minor', 'tax_class', 'tax_rate_parts',
                'delivery_kind', 'net_minor', 'tax_minor', 'gross_minor']);
            $data += ['line_reference' => $reference, 'sellable_reference' => $item['sellable_ref'],
                'listing_reference' => $item['listing_ref'], 'entry_reference' => $item['entry_ref'],
                'entitlement' => $item['entitlement_name'], 'offer_kind' => $item['offer_terms']['kind'] ?? null,
                'offer_duration_days' => $item['offer_terms']['days'] ?? null];
            $records[] = self::row($base, 'line', $reference, $actual->reference, $data);
        }
        if ($record['shipping'] !== null) {
            $shipping = $record['shipping'];
            $records[] = self::row($base, 'shipping', 'shipping', $actual->reference,
                self::fields($shipping, ['carrier', 'service', 'currency']) + ['amount_minor' => $shipping['amountMinor'],
                    'tax_class' => $shipping['taxClass'], 'tax_mode' => $shipping['taxMode'], 'tax_rate_parts' => $shipping['taxRateParts'],
                    'net_minor' => $shipping['net'], 'tax_minor' => $shipping['tax'], 'gross_minor' => $shipping['gross']]);
        }
        if ($record['payment'] !== null) {
            $records[] = self::row($base, 'payment', 'payment', $actual->reference,
                self::fields($record['payment'], ['amount_minor', 'mode', 'allocation_state', 'category'])
                    + ['verified_at' => self::instant($record['payment']['verified_at'])]);
        }
        foreach ($record['allocations'] as $allocation) {
            $line = self::lineReference($lineReferences, $allocation['line_ref']);
            $records[] = self::row($base, 'allocation', 'allocation:'.$line, 'payment',
                self::fields($allocation, ['sku', 'awarded', 'unavailable']) + ['line_reference' => $line]
                    + self::fields($allocation['refund_components'], ['item_net_minor', 'item_tax_minor', 'shipping_net_minor', 'shipping_tax_minor']));
        }
        $refundReferences = [];
        if ($record['refund'] !== null) {
            $refund = $record['refund'];
            $reference = 'automatic-refund:'.$refund['reference'];
            $data = self::fields($refund, ['amount_minor', 'state', 'category', 'owed_minor']);
            if (in_array($refund['reason'], ['unavailable_items', 'cancelled_before_confirmation'], true)) {
                $data['reason'] = $refund['reason'];
            }
            $records[] = self::row($base, 'automatic_refund', $reference, 'payment', $data);
            foreach ($refund['attempts'] as $attempt) {
                // Verified automatic observations identify the submitted attempt, not its enclosing obligation.
                $attemptReference = $refundReferences['unavailable'][$attempt['reference']] = 'refund-attempt:'.$attempt['reference'];
                $records[] = self::row($base, 'refund_attempt', $attemptReference, $reference,
                    self::fields($attempt, ['state', 'category']) + ['attempt_reference' => $attempt['reference'],
                        'updated_at' => self::instant($attempt['updated_at'])]);
            }
        }
        if ($this->financial->schemaAvailable($connection)) {
            $frame = $this->financial->checked($connection, $actual, true);
            foreach ($frame['refunds'] as $refund) {
                $reference = $refundReferences[$refund->origin][$refund->reference] = 'refund:'.$refund->reference;
                $records[] = self::row($base, 'refund', $reference, 'payment', ['origin' => $refund->origin,
                    'purpose' => $refund->purpose, 'state' => $refund->state, 'category' => $refund->category,
                    'amount_minor' => MinorUnits::integer($refund->amount_minor), 'created_at' => self::instant($refund->created_at),
                    'updated_at' => self::instant($refund->updated_at)]);
                foreach (json_decode($refund->selection, true, flags: JSON_THROW_ON_ERROR) as $selection) {
                    $line = self::lineReference($lineReferences, $selection['line_id']);
                    $records[] = self::row($base, 'refund_selection', $reference.':'.$line, $reference,
                        ['line_reference' => $line, 'quantity' => count($selection['units'])]
                            + self::fields($selection, ['item_net_minor', 'item_tax_minor', 'shipping_net_minor', 'shipping_tax_minor']));
                }
            }
            foreach ($frame['observations'] as $index => $observation) {
                $parent = $observation->local_reference === null ? 'payment'
                    : ($refundReferences[$observation->origin][$observation->local_reference] ?? throw new ExportRefused);
                $records[] = self::row($base, 'financial_observation', 'financial-observation:'.($index + 1), $parent,
                    ['kind' => $observation->kind, 'origin' => $observation->origin, 'state' => $observation->state,
                        'amount_minor' => MinorUnits::integer($observation->amount_minor), 'verified_at' => self::instant($observation->verified_at)]);
            }
        }
        if ($record['access_claim'] !== null) {
            $records[] = self::row($base, 'access_claim', 'access-claim', $actual->reference,
                ['claimed_at' => self::instant($record['access_claim']['claimed_at'])]);
        }
        foreach (['access_fulfillment' => ['access', 'access_effect'], 'financial_access' => ['financial_access', 'financial_access_effect']] as $key => [$type, $effectType]) {
            $access = $record[$key];
            if ($access === null) {
                continue;
            }
            $data = self::fields($access, ['state', 'category']);
            if ($key === 'access_fulfillment') {
                $data['confirmed_at'] = self::instant($access['confirmed_at']);
            }
            $records[] = self::row($base, $type, $type, $actual->reference, $data);
            foreach ($access['effects'] as $index => $effect) {
                $data = self::fields($effect, ['entitlement', 'state']);
                $data['expires_at'] = self::instant($effect['expires_at']);
                if ($key === 'access_fulfillment') {
                    $data['category'] = $effect['category'];
                    $data['processed_at'] = self::instant($effect['processed_at']);
                }
                $records[] = self::row($base, $effectType, $effectType.':'.($index + 1), $type, $data);
            }
        }
        if ($record['privacy'] !== null) {
            $records[] = self::row($base, 'privacy', 'privacy', $actual->reference,
                self::fields($record['privacy'], ['subject_erased', 'invoice_expired', 'policy_version', 'delivery_pending', 'legal_hold'])
                    + ['retained_until' => self::instant($record['privacy']['retained_until']),
                        'minimized_at' => self::instant($record['privacy']['minimized_at'])]);
        }
        if ($includeBilling) {
            foreach ($this->billing($connection, $actual, $state) as $field => $value) {
                $records[] = self::row($base, 'billing', 'billing:'.$field, $actual->reference,
                    ['billing_field' => $field, 'billing_value' => $value]);
            }
        }

        return $records;
    }

    /** @param array<string,mixed>|null $state
     * @return array<string,string|null>
     */
    private function billing(Connection $connection, object $order, ?array $state): array
    {
        if ($order->state !== 'paid' || $state === null || $state['policy'] === null || $state['invoice_expired']
            || $state['deadline'] === null || ($state['deadline'] <= PrivacyState::now()
                && array_filter($state['holds'], static fn (object $hold): bool => $hold->released_at === null) === [])) {
            return [];
        }
        $billing = $this->privacy->billing($connection, $order);
        if ($billing === null) {
            return [];
        }
        $selected = [];
        foreach ($state['policy']->billingFields as $field) {
            $values = $field === 'name' ? $billing : ($billing['address'] ?? []);
            $key = $field === 'name' ? 'name' : substr($field, 8);
            if (array_key_exists($key, $values)) {
                if ($values[$key] !== null && ! is_string($values[$key])) {
                    throw new ExportRefused;
                }
                $selected[$field] = $values[$key];
            }
        }

        return $selected;
    }

    /** @param array<string,mixed> $base
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private static function row(array $base, string $type, string $reference, ?string $parent, array $data, bool $customer = false): array
    {
        return ['schema' => $base['schema'], 'version' => $base['version'], 'type' => $type, 'org' => $base['org'],
            'site' => $customer ? null : $base['site'], 'order_reference' => $customer ? null : $base['order_reference'],
            'customer_reference' => $base['customer_reference'], 'reference' => $reference, 'parent_reference' => $parent, 'data' => $data];
    }

    /** @param array<string,mixed> $values
     * @param  list<string>  $keys
     * @return array<string,mixed>
     */
    private static function fields(array $values, array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            if (! array_key_exists($key, $values)) {
                throw new ExportRefused;
            }
            $result[$key] = $values[$key];
        }

        return $result;
    }

    /** @param array<int,string> $references */
    private static function lineReference(array $references, mixed $id): string
    {
        $key = self::writtenKey($id);

        return $key === null ? throw new ExportRefused : ($references[$key] ?? throw new ExportRefused);
    }

    private static function instant(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D', $value) === 1) {
            $value = substr($value, 0, 10).' '.substr($value, 11, 8);
        }

        return CheckoutAttempts::instant($value)->format('Y-m-d\TH:i:s\Z');
    }
}
