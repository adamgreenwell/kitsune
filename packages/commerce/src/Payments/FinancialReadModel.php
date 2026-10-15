<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Payments;

use Illuminate\Database\Connection;
use Kitsune\Commerce\Models\Order;
use Kitsune\Commerce\Money\MinorUnits;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Tenancy\Context;

/** Explicit safe financial history, independently checked while runtime commerce is disabled. @internal */
final class FinancialReadModel
{
    public function __construct(private readonly Context $context, private readonly FinancialState $state) {}

    /** @return array{financial_state:?array<string,mixed>} */
    public function projection(Connection $connection, int $org, #[\SensitiveParameter] object $order, bool $lock = false): array
    {
        if ($org !== (int) $order->org_id || $this->context->orgId() !== $org) {
            throw new CheckoutRefused('changed');
        }
        if (! $this->state->schemaAvailable($connection)) {
            return ['financial_state' => null];
        }
        $frame = $this->state->checked($connection, $order, $lock);
        $titles = [];
        foreach ($frame['basis']['lines'] as $line) {
            $titles[(int) $line['id']] = $line['title'];
        }
        $refunds = [];
        foreach ($frame['refunds'] as $refund) {
            $selection = [];
            foreach (json_decode($refund->selection, true, flags: JSON_THROW_ON_ERROR) as $line) {
                $selection[] = ['title' => $titles[$line['line_id']], 'quantity' => count($line['units'])];
            }
            $refunds[] = ['reference' => $refund->reference, 'origin' => $refund->origin, 'state' => $refund->state,
                'purpose' => $refund->purpose ?? 'units', 'amount_minor' => MinorUnits::integer($refund->amount_minor), 'selection' => $selection];
        }
        $observations = [];
        foreach ($frame['observations'] as $observation) {
            $observations[] = ['kind' => $observation->kind, 'state' => $observation->state,
                'amount_minor' => MinorUnits::integer($observation->amount_minor), 'verified_at' => $observation->verified_at];
        }

        return ['financial_state' => ['refunds' => $refunds, 'observations' => $observations,
            'observed_at' => $frame['work']->last_observed_at]];
    }

    /** Only the current owner can receive original line IDs or external provider IDs for explicit actions.
     * @return array{lines:list<array{line_id:int,title:string,awarded:int,available:int}>,external:list<array{refund_id:string,state:string,amount_minor:string}>,history:list<array{reference:string,origin:string,purpose:string,state:string,amount_minor:string,reason:string}>,can_request:bool,can_balance_refund:bool,balance_minor:?string}
     */
    public function forOwner(Connection $connection, #[\SensitiveParameter] object $order): array
    {
        $authority = $this->owner($order);
        $frame = $this->state->checked($connection, $order);
        $plan = $this->state->accessPlan($connection, $order);
        $awarded = [];
        foreach ($frame['basis']['allocations'] as $allocation) {
            $awarded[(int) $allocation['line_id']] = (int) $allocation['awarded'];
        }
        $reserved = [];
        $bound = [];
        $history = [];
        foreach ($frame['refunds'] as $refund) {
            $history[] = ['reference' => $refund->reference, 'origin' => $refund->origin, 'purpose' => $refund->purpose,
                'state' => $refund->state, 'amount_minor' => MinorUnits::integer($refund->amount_minor), 'reason' => $refund->reason];
            if ($refund->refund_id !== null) {
                $bound[$refund->refund_id] = true;
            }
            if (! in_array($refund->state, ['failed', 'canceled'], true)) {
                foreach (json_decode($refund->selection, true, flags: JSON_THROW_ON_ERROR) as $line) {
                    $id = (int) $line['line_id'];
                    $reserved[$id] = ($reserved[$id] ?? 0) + count($line['units']);
                }
            }
        }
        $lines = [];
        foreach ($frame['basis']['lines'] as $line) {
            $id = (int) $line['id'];
            $quantity = $awarded[$id] ?? 0;
            $available = $quantity - ($reserved[$id] ?? 0);
            if ($available < 0) {
                throw new CheckoutRefused('changed');
            }
            $lines[] = ['line_id' => $id, 'title' => $line['title'], 'awarded' => $quantity, 'available' => $available];
        }
        $external = [];
        foreach ($frame['observations'] as $observation) {
            if ($observation->kind === 'refund' && $observation->origin === 'external' && ! isset($bound[$observation->object_id])
                && ! in_array($observation->state, ['failed', 'canceled'], true)) {
                $external[] = ['refund_id' => $observation->object_id, 'state' => $observation->state, 'amount_minor' => MinorUnits::integer($observation->amount_minor)];
            }
        }
        $heldBalance = ($frame['basis']['receipt']['allocation_state'] ?? null) === 'held'
            && ! $connection->table(PaidOrders::OBLIGATIONS)->useWritePdo()->where('order_id', $order->id)->exists();
        $balance = $heldBalance && $frame['work']->charge_refunded_minor !== null
            ? AllocationAmounts::subtract(MinorUnits::integer($frame['basis']['receipt']['amount_minor']), MinorUnits::integer($frame['work']->charge_refunded_minor)) : null;
        if ($this->owner($order) !== $authority) {
            throw new CheckoutRefused('changed');
        }

        return ['lines' => $lines, 'external' => $external, 'history' => $history,
            'can_balance_refund' => $heldBalance && $balance !== null && $balance !== '0', 'balance_minor' => $balance,
            'can_request' => $order->state === 'paid' && ! $plan['blocked'] && ($frame['basis']['receipt']['allocation_state'] ?? null) === 'allocated'];
    }

    /** Org-wide account erasure may visit a sibling Site; only a completely empty unpaid marker is disposable. */
    public function deleteUnpaid(Connection $connection, #[\SensitiveParameter] object $order): void
    {
        if (! $this->state->schemaAvailable($connection)) {
            return;
        }
        if ($connection !== (new Order)->getConnection() || $connection->transactionLevel() < 1 || ! $connection->getPdo()->inTransaction()
            || $this->context->orgId() !== (int) $order->org_id || ! in_array($order->state, ['prepared', 'cancelled'], true)) {
            throw new CheckoutRefused('changed');
        }
        $frame = $this->state->checked($connection, $order, true);
        $work = $frame['work'];
        if ($frame['refunds'] !== [] || $frame['observations'] !== [] || $frame['signals'] !== [] || $frame['basis']['receipt'] !== null
            || $work->basis_hash !== null || $work->lease_token !== null || $work->last_observed_at !== null || (int) $work->revision !== 0) {
            throw new CheckoutRefused('changed');
        }
        if ($connection->table(FinancialState::WORK)->where('id', $work->id)->where('org_id', $order->org_id)
            ->where('site_id', $order->site_id)->where('order_id', $order->id)->delete() !== 1) {
            throw new CheckoutRefused('changed');
        }
    }

    private function owner(#[\SensitiveParameter] object $order): string
    {
        Permissions::forget();
        $actor = Permissions::currentUser();
        if ($actor === null || ! Permissions::isOwner($actor) || $this->context->orgId() !== (int) $order->org_id
            || $this->context->siteId() !== (int) $order->site_id) {
            throw new CheckoutRefused;
        }

        return CheckoutAttempts::digest(['org' => $this->context->orgId(), 'site' => $this->context->siteId(),
            'class' => $actor::class, 'id' => (string) $actor->getAuthIdentifier()]);
    }
}
