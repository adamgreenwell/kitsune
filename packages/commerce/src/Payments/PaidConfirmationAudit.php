<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Payments;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Kitsune\Commerce\Models\Order;
use Kitsune\Commerce\Orders\CompletionFacts;
use Kitsune\Commerce\Orders\FreeAccessCorrections;
use Kitsune\Commerce\Orders\FreeOrders;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Core\Audit\Auditor;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Tenancy\Context;
use Throwable;

/** Freeze financial and shared-stock custody before invoking host audit observers. @internal */
final class PaidConfirmationAudit
{
    private const ACTION = 'commerce.order_paid';

    private const MAX_GRAPH_BYTES = 4194304;

    public function __construct(private readonly Context $context, private readonly Auditor $auditor) {}

    /** The caller owns OrderLocks and must let refusal roll back the complete confirmation transaction. */
    public function record(Connection $connection, object $paidOrder): void
    {
        try {
            if ($connection !== (new Order)->getConnection() || $connection->transactionLevel() < 1
                || ! $connection->getPdo()->inTransaction()) {
                throw new CheckoutRefused;
            }
            $context = $this->contextProof();
            $graph = $this->graph($connection, $paidOrder);
            $actor = $this->actor($connection);
            $model = (new Order)->newFromBuilder(get_object_vars($paidOrder));
            $targetType = $model->getMorphClass();
            $action = $paidOrder->state === 'completed_free' ? 'commerce.order_completed_free' : self::ACTION;
            $audit = $this->auditor->recordOrFail($action, $model);
            $stored = $audit->exists ? $connection->table('audit_log')->useWritePdo()->where('id', $audit->getKey())->lockForUpdate()->first() : null;
            if ($stored === null || (string) $stored->org_id !== (string) $paidOrder->org_id
                || (string) $stored->site_id !== (string) $paidOrder->site_id || $stored->action !== $action
                || $stored->target_type !== $targetType || (string) $stored->target_id !== (string) $paidOrder->id
                || $stored->actor_type !== $actor['type'] || $stored->actor_id !== $actor['id']
                || $actor !== $this->actor($connection)
                || ! hash_equals($graph, $this->graph($connection, $paidOrder)) || ! hash_equals($context, $this->contextProof())) {
                throw new CheckoutRefused('changed');
            }
            $finalAudits = $connection->table('audit_log')->useWritePdo()->where('id', $audit->getKey())->lockForUpdate()->get();
            if ($finalAudits->count() !== 1 || get_object_vars($finalAudits->sole()) !== get_object_vars($stored)) {
                throw new CheckoutRefused('changed');
            }
        } catch (Throwable) {
            // Neither retained financial/contact data nor authentication witnesses enter an error chain.
            throw new CheckoutRefused('changed');
        }
    }

    /** @return array{type:string|null,id:string|null,proof:string|null} */
    private function actor(Connection $connection): array
    {
        Permissions::forget();
        $actor = Permissions::currentUser();
        if ($actor === null) {
            return ['type' => null, 'id' => null, 'proof' => null];
        }
        // A system payment needs no owner. A present first-party staff identity must still exist as recorded.
        if (! $actor instanceof Model || $actor->getConnection() !== $connection) {
            throw new CheckoutRefused;
        }
        $id = $actor->getAuthIdentifier();
        $type = $actor->getMorphClass();
        if ((! is_int($id) && ! is_string($id)) || (string) $id === '' || strlen((string) $id) > 255
            || $type === '' || strlen($type) > 255) {
            throw new CheckoutRefused;
        }
        $columns = array_values(array_unique([$actor->getKeyName(), $actor->getAuthIdentifierName(), $actor->getAuthPasswordName()]));
        $row = $connection->table($actor->getTable())->useWritePdo()->where($actor->getKeyName(), $actor->getKey())
            ->lockForUpdate()->first($columns) ?? throw new CheckoutRefused;
        $identifier = $actor->getAuthIdentifierName();
        if (! is_int($row->{$identifier}) && ! is_string($row->{$identifier})) {
            throw new CheckoutRefused;
        }
        if ((string) $row->{$identifier} !== (string) $id) {
            throw new CheckoutRefused;
        }
        $held = Permissions::held($actor);
        sort($held, SORT_STRING);

        return ['type' => $type, 'id' => (string) $id,
            'proof' => CheckoutAttempts::digest(['row' => get_object_vars($row), 'owner' => Permissions::isOwner($actor), 'grants' => $held])];
    }

    private function contextProof(): string
    {
        if ($this->context->orgId() === null || $this->context->siteId() === null) {
            throw new CheckoutRefused;
        }

        return CheckoutAttempts::digest(['org' => $this->context->org()?->getAttributes(), 'site' => $this->context->site()?->getAttributes(),
            'orgId' => $this->context->orgId(), 'siteId' => $this->context->siteId()]);
    }

    /** Internal before/after witness for corrections under the same original completion locks. */
    public function witness(Connection $connection, object $order): string
    {
        return $this->graph($connection, $order);
    }

    private function graph(Connection $connection, object $expected): string
    {
        if (! CompletionFacts::completed($expected) || $this->context->orgId() !== (int) $expected->org_id
            || $this->context->siteId() !== (int) $expected->site_id) {
            throw new CheckoutRefused;
        }
        $order = $connection->table('commerce_orders')->useWritePdo()->where('id', $expected->id)->lockForUpdate()->first()
            ?? throw new CheckoutRefused;
        $attributes = get_object_vars($order);
        $expectedAttributes = get_object_vars($expected);
        if (array_diff_key($attributes, $expectedAttributes) !== [] || array_diff_key($expectedAttributes, $attributes) !== []) {
            throw new CheckoutRefused;
        }
        foreach ($attributes as $column => $value) {
            if (($value !== null && ! is_scalar($value)) || ($expected->{$column} !== null && ! is_scalar($expected->{$column}))
                || ($value === null) !== ($expected->{$column} === null)
                || ($value !== null && (string) $value !== (string) $expected->{$column})) {
                throw new CheckoutRefused;
            }
        }
        app(PaidOrders::class)->projection($connection, (int) $order->org_id, $order, true);
        app(AccessFulfillment::class)->projection($connection, (int) $order->org_id, $order, true);
        $rows = [];
        foreach (['commerce_order_lines' => OrderReaderData::MAX_LINES, CheckoutAttempts::TABLE => CheckoutAttempts::MAX_ATTEMPTS,
            FreeOrders::TABLE => 1, FreeAccessCorrections::WORK => 1, FreeAccessCorrections::TABLE => 1000,
            PaidOrders::RECEIPTS => 1, PaidOrders::ALLOCATIONS => OrderReaderData::MAX_LINES, PaidOrders::OBLIGATIONS => 1,
            PaidOrders::ATTEMPTS => PaidOrders::MAX_REFUND_ATTEMPTS, AccessFulfillment::WORK => 1,
            AccessFulfillment::EFFECTS => AccessFulfillment::MAX_EFFECTS] as $table => $bound) {
            $children = $connection->table($table)->useWritePdo()->where('order_id', $order->id)->orderBy('id')->limit($bound + 1)->lockForUpdate()->get();
            if ($children->count() > $bound) {
                throw new CheckoutRefused;
            }
            foreach ($children as $child) {
                if ((string) $child->org_id !== (string) $order->org_id || (string) $child->site_id !== (string) $order->site_id) {
                    throw new CheckoutRefused;
                }
            }
            $rows[$table] = $children->map(static fn (object $row): array => get_object_vars($row))->all();
        }
        if ($rows['commerce_order_lines'] === [] || ($order->state === 'completed_free' ? $rows[FreeOrders::TABLE] : $rows[PaidOrders::RECEIPTS]) === []) {
            throw new CheckoutRefused;
        }
        $keys = array_values(array_unique(array_column($rows['commerce_order_lines'], 'sellable_ref')));
        $sellables = $connection->table('commerce_sellables')->useWritePdo()->where('org_id', $order->org_id)->whereIn('id', $keys)
            ->orderBy('id')->limit(OrderReaderData::MAX_LINES + 1)->lockForUpdate()->get();
        $movements = $connection->table('commerce_stock_movements')->useWritePdo()->where('org_id', $order->org_id)
            ->where('order_reference', $order->reference)->orderBy('id')->limit(OrderReaderData::MAX_LINES + 1)->lockForUpdate()->get();
        if ($sellables->count() > OrderReaderData::MAX_LINES || $movements->count() > OrderReaderData::MAX_LINES) {
            throw new CheckoutRefused;
        }
        $encoded = json_encode(['order' => $attributes, 'children' => $rows,
            'sellables' => $sellables->map(static fn (object $row): array => get_object_vars($row))->all(),
            'movements' => $movements->map(static fn (object $row): array => get_object_vars($row))->all()], JSON_THROW_ON_ERROR);
        if (strlen($encoded) > self::MAX_GRAPH_BYTES) {
            throw new CheckoutRefused;
        }

        return hash('sha256', $encoded);
    }
}
