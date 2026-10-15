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
use Illuminate\Database\Eloquent\Model;
use Kitsune\Commerce\Carts\CartAmounts;
use Kitsune\Commerce\Models\FinancialRefund;
use Kitsune\Commerce\Models\FinancialWork;
use Kitsune\Commerce\Models\Order;
use Kitsune\Commerce\Money\MinorUnits;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Core\Audit\Auditor;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Tenancy\Context;
use Throwable;

/** Independent financial adjustments. Original payment, allocation and purchased-access inputs never change. @internal */
final class FinancialState
{
    public const WORK = 'commerce_financial_work';

    public const REFUNDS = 'commerce_financial_refunds';

    public const OBSERVATIONS = 'commerce_financial_observations';

    public const MAX_REFUNDS = 100;

    public const MAX_OBSERVATIONS = 240;

    public const LEASE_SECONDS = 120;

    private const MAX_CUSTODY_BYTES = 4194304;

    private const STATES = ['owed', 'submitting', 'unknown', 'held', 'pending', 'requires_action', 'succeeded', 'failed', 'canceled'];

    private const REFUND_STATES = ['pending', 'requires_action', 'succeeded', 'failed', 'canceled'];

    private const DISPUTE_STATES = ['needs_response', 'under_review', 'won', 'lost', 'warning_needs_response', 'warning_under_review', 'warning_closed'];

    public function __construct(private readonly Context $context, private readonly OrderLocks $locks, private readonly Auditor $auditor) {}

    public function prepare(Connection $connection, #[\SensitiveParameter] object $order): void
    {
        $this->inside($connection, $order);
        if (! $this->schemaAvailable($connection)) {
            throw new CheckoutRefused('setup');
        }
        if ($connection->table(self::WORK)->useWritePdo()->where('order_id', $order->id)->exists()) {
            throw new CheckoutRefused('changed');
        }
        $now = self::now();
        $connection->table(self::WORK)->insert(['org_id' => $order->org_id, 'site_id' => $order->site_id,
            'order_id' => $order->id, 'order_reference' => self::reference($order->reference), 'revision' => 0, 'basis_hash' => null,
            'charge_refunded_minor' => null, 'charge_disputed' => null, 'last_observed_at' => null,
            'lease_token' => null, 'lease_until' => null, 'replay_count' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $this->checked($connection, $order, true);
    }

    /** No runtime-provider dependency: disabled-module export must still validate retained custody.
     * @return array{work:object,refunds:list<object>,observations:list<object>,signals:list<object>,unavailable_obligations:list<object>,unavailable_attempts:list<object>,basis:array<string,mixed>,basis_hash:string}
     */
    public function checked(Connection $connection, #[\SensitiveParameter] object $order, bool $lock = false): array
    {
        if (! $this->schemaAvailable($connection) || $this->context->orgId() !== (int) $order->org_id) {
            throw new CheckoutRefused('changed');
        }
        $actual = $connection->table('commerce_orders')->useWritePdo()->where('id', self::positive($order->id))
            ->where('org_id', $order->org_id)->where('site_id', $order->site_id)->when($lock, fn ($q) => $q->lockForUpdate())->first()
            ?? throw new CheckoutRefused('changed');
        if ($actual->reference !== $order->reference) {
            throw new CheckoutRefused('changed');
        }
        $parents = $this->rows($connection, self::WORK, $actual, 1, $lock);
        if (count($parents) !== 1) {
            throw new CheckoutRefused('changed');
        }
        $work = $parents[0];
        if ($work->order_reference !== $actual->reference || (($work->lease_token === null) !== ($work->lease_until === null))
            || ($work->lease_token !== null && ! self::hex($work->lease_token, 64))
            || ($work->charge_disputed !== null && ! in_array($work->charge_disputed, [true, false, 0, 1, '0', '1'], true))
            || (($work->last_observed_at === null) !== ($work->charge_refunded_minor === null))
            || (($work->last_observed_at === null) !== ($work->charge_disputed === null))) {
            throw new CheckoutRefused('changed');
        }
        self::counter($work->revision);
        self::counter($work->replay_count);
        foreach (['created_at', 'updated_at', 'lease_until', 'last_observed_at'] as $column) {
            if ($work->{$column} !== null) {
                CheckoutAttempts::instant($work->{$column});
            }
        }
        $basis = $this->basis($connection, $actual, $lock);
        $basisHash = CheckoutAttempts::digest(array_diff_key($basis, ['checkout' => true]));
        if ($work->basis_hash !== null && (! self::hex($work->basis_hash, 64) || ! hash_equals($work->basis_hash, $basisHash))) {
            throw new CheckoutRefused('changed');
        }
        $refunds = $this->rows($connection, self::REFUNDS, $actual, self::MAX_REFUNDS, $lock);
        $observations = $this->rows($connection, self::OBSERVATIONS, $actual, self::MAX_OBSERVATIONS, $lock);
        if (($refunds !== [] || $observations !== [] || $work->last_observed_at !== null) && ($actual->state !== 'paid' || $work->basis_hash === null)) {
            throw new CheckoutRefused('changed');
        }
        $bytes = 0;
        foreach ($refunds as $refund) {
            foreach (['selection', 'authorization', 'request_payload'] as $column) {
                $bytes += is_string($refund->{$column}) ? strlen($refund->{$column}) : 0;
            }
            if ($bytes > self::MAX_CUSTODY_BYTES) {
                throw new CheckoutRefused('operator');
            }
            $this->refundRow($refund, $work, $basis, $basisHash);
        }
        foreach ($observations as $observation) {
            if ((string) $observation->work_id !== (string) $work->id || $observation->account_id !== ($basis['receipt']['account_id'] ?? null)
                || $observation->mode !== 'test' || $observation->currency !== $actual->currency
                || ! in_array($observation->kind, ['refund', 'dispute'], true)
                || ! self::identifier($observation->object_id, $observation->kind === 'refund' ? 're_' : 'du_')
                || ! in_array($observation->state, $observation->kind === 'refund' ? self::REFUND_STATES : self::DISPUTE_STATES, true)
                || ! in_array($observation->origin, ['operator', 'unavailable', 'external', 'dispute'], true)
                || ($observation->kind === 'dispute' && ($observation->origin !== 'dispute' || $observation->local_reference !== null || $observation->request_digest !== null))) {
                throw new CheckoutRefused('changed');
            }
            $amount = MinorUnits::integer($observation->amount_minor);
            if ($amount === '0' || ($observation->kind === 'refund' && AllocationAmounts::compare($amount, MinorUnits::integer($basis['receipt']['amount_minor'])) > 0)) {
                throw new CheckoutRefused('changed');
            }
            CheckoutAttempts::instant($observation->verified_at);
            if (in_array($observation->origin, ['operator', 'unavailable'], true)) {
                if (! self::hex($observation->local_reference, 32) || ! self::hex($observation->request_digest, 64)) {
                    throw new CheckoutRefused('changed');
                }
                $this->knownObservation($connection, $actual, $observation, $refunds, $basis);
            } elseif ($observation->local_reference !== null || $observation->request_digest !== null) {
                throw new CheckoutRefused('changed');
            }
        }
        if ($work->charge_refunded_minor !== null && AllocationAmounts::compare(MinorUnits::integer($work->charge_refunded_minor),
            MinorUnits::integer($basis['receipt']['amount_minor'])) > 0) {
            throw new CheckoutRefused('changed');
        }

        $signals = [];
        if (isset($basis['receipt']['charge_id'])) {
            $signals = FinancialSignals::rows($connection, (int) $actual->org_id, $basis['receipt']['charge_id'], $lock);
        }

        $unavailableObligations = $this->rows($connection, PaidOrders::OBLIGATIONS, $actual, 1, $lock);
        $unavailableAttempts = $this->rows($connection, PaidOrders::ATTEMPTS, $actual, PaidOrders::MAX_REFUND_ATTEMPTS, $lock);

        return ['work' => $work, 'refunds' => $refunds, 'observations' => $observations, 'signals' => $signals,
            'unavailable_obligations' => $unavailableObligations, 'unavailable_attempts' => $unavailableAttempts, 'basis' => $basis, 'basis_hash' => $basisHash];
    }

    /** @return array{digest:string,blocked:bool,category:?string,removed_units:array<int,int>,revision:int} */
    public function accessPlan(Connection $connection, #[\SensitiveParameter] object $order, bool $lock = false): array
    {
        $frame = $this->checked($connection, $order, $lock);
        $plan = $this->plan($frame);

        return ['digest' => $this->digest($frame), ...$plan, 'revision' => self::counter($frame['work']->revision)];
    }

    /** Explicit confirmation is represented by the caller's stable, unpredictable request key; no provider I/O.
     * @param  array<int|string,mixed>  $lineQuantities
     */
    public function requestAsOwner(#[\SensitiveParameter] string $orderReference, array $lineQuantities, #[\SensitiveParameter] string $reason, #[\SensitiveParameter] string $requestKey): string
    {
        self::reference($orderReference);
        self::reference($requestKey);
        $reason = self::reason($reason);

        return $this->locks->run(function () use ($orderReference, $lineQuantities, $reason, $requestKey): string {
            $actor = $this->authority(true);
            $order = $this->order($orderReference);
            $connection = (new Order)->getConnection();
            $frame = $this->checked($connection, $order, true);
            $quantities = self::quantities($lineQuantities);
            foreach ($frame['refunds'] as $old) {
                if ($old->request_key === $requestKey) {
                    $selected = self::decode($old->selection);
                    $prior = [];
                    foreach ($selected as $line) {
                        $prior[$line['line_id']] = count($line['units']);
                    }
                    if ($old->origin !== 'operator' || $old->purpose !== 'units' || $old->reason !== $reason || $prior !== $quantities) {
                        throw new CheckoutRefused('changed');
                    }

                    return $old->reference;
                }
            }
            $this->idle($frame['work']);
            if ($order->state !== 'paid' || $this->plan($frame)['blocked'] || ($frame['basis']['receipt']['allocation_state'] ?? null) !== 'allocated') {
                throw new CheckoutRefused('operator');
            }
            $selection = $this->select($frame, $quantities);
            $amount = self::selectionAmount($selection);
            if ($amount === '0') {
                throw new CheckoutRefused('changed');
            }
            $reference = bin2hex(random_bytes(16));
            $wire = StripeAmounts::toWire($amount, $order->currency);
            $charge = $frame['basis']['receipt']['charge_id'];
            $metadata = ['kitsune_order' => $order->reference, 'kitsune_refund' => $reference,
                'kitsune_request' => CheckoutAttempts::digest(['order' => $order->reference, 'refund' => $reference, 'charge' => $charge, 'amount' => $wire])];
            $request = ['charge' => $charge, 'amount' => $wire, 'metadata' => $metadata];
            $row = $this->insertRefund($connection, $order, $frame, $selection, $reason, $reference, [
                'request_key' => $requestKey, 'origin' => 'operator', 'idempotency_key' => 'kitsune-refund-'.$reference,
                'request_payload' => self::encode($request), 'request_digest' => CheckoutAttempts::digest($request),
                'refund_id' => null, 'state' => 'owed']);
            $this->audit($connection, $order, 'commerce.refund_requested', (new FinancialRefund)->newFromBuilder((array) $row), $actor);
            $this->applyAccess($connection, $order);

            return $reference;
        });
    }

    /** Resolve an originally held payment only by explicitly refunding its freshly proved entire remainder.
     * No delivered unit or access entitlement is invented, and no unavailable-item obligation is replaced.
     */
    public function requestBalanceRefundAsOwner(#[\SensitiveParameter] string $orderReference, #[\SensitiveParameter] string $reason, #[\SensitiveParameter] string $requestKey): string
    {
        self::reference($orderReference);
        self::reference($requestKey);
        $reason = self::reason($reason);

        return $this->locks->run(function () use ($orderReference, $reason, $requestKey): string {
            $actor = $this->authority(true);
            $order = $this->order($orderReference);
            $connection = (new Order)->getConnection();
            $frame = $this->checked($connection, $order, true);
            foreach ($frame['refunds'] as $old) {
                if ($old->request_key === $requestKey) {
                    if ($old->origin !== 'operator' || $old->purpose !== 'held_balance' || $old->reason !== $reason) {
                        throw new CheckoutRefused('changed');
                    }

                    return $old->reference;
                }
            }
            $this->idle($frame['work']);
            $amount = $this->balance($frame);
            $reference = bin2hex(random_bytes(16));
            $wire = StripeAmounts::toWire($amount, $order->currency);
            $charge = $frame['basis']['receipt']['charge_id'];
            $metadata = ['kitsune_order' => $order->reference, 'kitsune_refund' => $reference,
                'kitsune_request' => CheckoutAttempts::digest(['order' => $order->reference, 'refund' => $reference, 'charge' => $charge, 'amount' => $wire])];
            $request = ['charge' => $charge, 'amount' => $wire, 'metadata' => $metadata];
            $authorization = ['version' => 1, 'receipt_id' => (string) $frame['basis']['receipt']['id'],
                'verified_at' => $frame['work']->last_observed_at, 'refunded_minor' => MinorUnits::integer($frame['work']->charge_refunded_minor),
                'observations' => array_map(get_object_vars(...), $frame['observations']), 'remaining_minor' => $amount];
            $row = $this->insertRefund($connection, $order, $frame, [], $reason, $reference, [
                'request_key' => $requestKey, 'origin' => 'operator', 'purpose' => 'held_balance',
                'amount_minor' => $amount, 'authorization' => self::encode($authorization), 'authorization_hash' => CheckoutAttempts::digest($authorization),
                'idempotency_key' => 'kitsune-refund-'.$reference, 'request_payload' => self::encode($request),
                'request_digest' => CheckoutAttempts::digest($request), 'refund_id' => null, 'state' => 'owed']);
            $this->audit($connection, $order, 'commerce.balance_refund_requested', (new FinancialRefund)->newFromBuilder((array) $row), $actor);
            $this->applyAccess($connection, $order);

            return $reference;
        });
    }

    /** Attribution requires one exact authenticated external object and exact original unit money.
     * @param  array<int|string,mixed>  $lineQuantities
     */
    public function attributeAsOwner(#[\SensitiveParameter] string $orderReference, string $refundId, array $lineQuantities, #[\SensitiveParameter] string $reason): string
    {
        self::reference($orderReference);
        if (! self::identifier($refundId, 're_')) {
            throw new CheckoutRefused;
        }
        $reason = self::reason($reason);

        return $this->locks->run(function () use ($orderReference, $refundId, $lineQuantities, $reason): string {
            $actor = $this->authority(true);
            $order = $this->order($orderReference);
            $connection = (new Order)->getConnection();
            $frame = $this->checked($connection, $order, true);
            $this->idle($frame['work']);
            $quantities = self::quantities($lineQuantities);
            foreach ($frame['refunds'] as $old) {
                if ($old->refund_id === $refundId) {
                    $prior = [];
                    foreach (self::decode($old->selection) as $line) {
                        $prior[$line['line_id']] = count($line['units']);
                    }
                    if ($old->origin !== 'external' || $old->reason !== $reason || $prior !== $quantities) {
                        throw new CheckoutRefused('changed');
                    }

                    return $old->reference;
                }
            }
            $observation = null;
            foreach ($frame['observations'] as $row) {
                if ($row->kind === 'refund' && $row->object_id === $refundId && $row->origin === 'external') {
                    $observation = $row;
                }
            }
            if ($observation === null || in_array($observation->state, ['failed', 'canceled'], true)
                || ($frame['basis']['receipt']['allocation_state'] ?? null) !== 'allocated') {
                throw new CheckoutRefused('operator');
            }
            $selection = $this->select($frame, $quantities);
            if (self::selectionAmount($selection) !== MinorUnits::integer($observation->amount_minor)) {
                throw new CheckoutRefused('changed');
            }
            $reference = bin2hex(random_bytes(16));
            $row = $this->insertRefund($connection, $order, $frame, $selection, $reason, $reference, [
                'request_key' => null, 'origin' => 'external', 'idempotency_key' => null, 'request_payload' => null,
                'request_digest' => null, 'refund_id' => $refundId, 'state' => $observation->state]);
            $this->audit($connection, $order, 'commerce.refund_attributed', (new FinancialRefund)->newFromBuilder((array) $row), $actor);
            $this->applyAccess($connection, $order);

            return $reference;
        });
    }

    /** @return array<string,mixed> */
    public function claimGraph(#[\SensitiveParameter] string $orderReference): array
    {
        return $this->claim($orderReference, null);
    }

    /** @return array<string,mixed> */
    public function claimRefund(#[\SensitiveParameter] string $orderReference, string $reference): array
    {
        self::reference($reference);

        return $this->claim($orderReference, $reference);
    }

    /** @return array<string,mixed> */
    private function claim(#[\SensitiveParameter] string $orderReference, ?string $reference): array
    {
        self::reference($orderReference);
        if ((new Order)->getConnection()->transactionLevel() !== 0) {
            throw new CheckoutRefused;
        }

        return $this->locks->run(function () use ($orderReference, $reference): array {
            $authority = $this->authority();
            $order = $this->order($orderReference);
            $connection = (new Order)->getConnection();
            $frame = $this->checked($connection, $order, true);
            $this->idle($frame['work']);
            foreach ($frame['unavailable_attempts'] as $attempt) {
                if ($attempt->lease_until !== null && CheckoutAttempts::instant($attempt->lease_until)->greaterThan(CarbonImmutable::now('UTC'))) {
                    throw new CheckoutRefused('busy');
                }
            }
            if ($order->state !== 'paid') {
                throw new CheckoutRefused('changed');
            }
            $count = self::counter($frame['work']->replay_count);
            if ($count >= 2147483647) {
                throw new CheckoutRefused('operator');
            }
            $this->updateWork($connection, $order, ['lease_token' => bin2hex(random_bytes(32)),
                'lease_until' => CarbonImmutable::now('UTC')->startOfSecond()->addSeconds(self::LEASE_SECONDS)->format('Y-m-d H:i:s'),
                'replay_count' => $count + 1]);

            return $this->captured($connection, $order, $authority, $reference);
        });
    }

    /** @param array<string,mixed> $captured */
    public function checkGraphClaim(Connection $connection, #[\SensitiveParameter] object $order, #[\SensitiveParameter] array $captured): void
    {
        $this->inside($connection, $order);
        $this->runtime($connection);
        $frame = $this->checked($connection, $order, true);
        if (! is_string($captured['digest'] ?? null) || ! hash_equals($captured['digest'], $this->digest($frame))
            || ($captured['lease_token'] ?? null) !== $frame['work']->lease_token || $frame['work']->lease_until === null
            || ! CheckoutAttempts::instant($frame['work']->lease_until)->greaterThan(CarbonImmutable::now('UTC'))
            || ($captured['authority'] ?? null) !== $this->authority()) {
            throw new CheckoutRefused('changed');
        }
    }

    /** @param array<string,mixed> $captured */
    public function checkClaim(Connection $connection, #[\SensitiveParameter] object $order, #[\SensitiveParameter] array $captured): void
    {
        $this->checkGraphClaim($connection, $order, $captured);
    }

    /** @param array<string,mixed> $captured
     * @param  array<string,mixed>  $graph
     */
    public function finishGraph(Connection $connection, #[\SensitiveParameter] object $order, #[\SensitiveParameter] array $captured, #[\SensitiveParameter] array $graph): void
    {
        $this->checkGraphClaim($connection, $order, $captured);
        $this->observe($connection, $order, $graph);
        $this->release($connection, $order);
    }

    /** Fresh whole-payment proof is required under the same lease immediately before any outbound refund.
     * @param  array<string,mixed>  $captured
     * @param  array<string,mixed>  $graph
     * @return array<string,mixed>
     */
    public function observeForRefund(Connection $connection, #[\SensitiveParameter] object $order, #[\SensitiveParameter] array $captured, #[\SensitiveParameter] array $graph): array
    {
        $this->checkGraphClaim($connection, $order, $captured);
        $this->observe($connection, $order, $graph);
        $next = $this->captured($connection, $order, $captured['authority'], $captured['refund']->reference);

        // Keep freshly proved blockers committed. The submitting door refuses them before POST; throwing here
        // would roll back precisely the external refund/dispute evidence that needs operator attention.
        return [...$next, 'graph_observed' => true];
    }

    /** @param array<string,mixed> $captured
     * @return array<string,mixed>
     */
    public function submitting(Connection $connection, #[\SensitiveParameter] object $order, #[\SensitiveParameter] array $captured, string $credentialWitness): array
    {
        $this->checkGraphClaim($connection, $order, $captured);
        $row = $captured['refund'] ?? throw new CheckoutRefused;
        if (! self::hex($credentialWitness, 64) || $row->origin !== 'operator' || $row->refund_id !== null
            || ! in_array($row->state, ['owed', 'unknown', 'submitting'], true)
            || ($row->first_submitted_at !== null && ! CheckoutAttempts::instant($row->first_submitted_at)->addHours(23)->greaterThan(CarbonImmutable::now('UTC')))
            || ($row->purpose === 'units' && $this->plan($captured)['blocked']) || ($captured['graph_observed'] ?? false) !== true
            || ($row->purpose === 'held_balance' && $this->balance($captured, $row->reference) !== MinorUnits::integer($row->amount_minor))) {
            throw new CheckoutRefused('operator');
        }
        $this->updateRefund($connection, $order, $row, ['state' => 'submitting', 'category' => null,
            'credential_witness' => $row->credential_witness ?? $credentialWitness, 'first_submitted_at' => $row->first_submitted_at ?? self::now()]);
        $this->bump($connection, $order);

        return $this->captured($connection, $order, $captured['authority'], $row->reference);
    }

    /** @param array<string,mixed> $captured
     * @param  array<string,mixed>  $refund
     */
    public function finishRefund(Connection $connection, #[\SensitiveParameter] object $order, #[\SensitiveParameter] array $captured, #[\SensitiveParameter] array $refund): void
    {
        $this->checkGraphClaim($connection, $order, $captured);
        $this->bindRefund($connection, $order, $captured['refund']->reference, $refund);
        $this->release($connection, $order);
    }

    /** @param array<string,mixed> $captured */
    public function markUnknown(Connection $connection, #[\SensitiveParameter] object $order, #[\SensitiveParameter] array $captured, string $category = 'unavailable'): void
    {
        $this->checkGraphClaim($connection, $order, $captured);
        if (! in_array($category, ['unavailable', 'changed', 'operator', 'external_refund'], true)) {
            throw new CheckoutRefused;
        }
        if (isset($captured['refund'])) {
            $row = $captured['refund'];
            // Failed refresh is not a new provider lifecycle. In particular it cannot undo proved failure,
            // or turn succeeded money into an unknown operation inconsistent with its retained receipt.
            $state = $row->state;
            if ($row->refund_id === null && $row->first_submitted_at !== null) {
                $state = $category === 'unavailable' ? 'unknown' : 'held';
            }
            $this->updateRefund($connection, $order, $row, ['state' => $state, 'category' => $category]);
            $this->bump($connection, $order);
        }
        $this->release($connection, $order);
    }

    /** Only normalized authenticated provider data, never raw payload status, enters this door.
     * @param  array<string,mixed>  $graph
     */
    public function observe(Connection $connection, #[\SensitiveParameter] object $order, #[\SensitiveParameter] array $graph): void
    {
        $this->inside($connection, $order);
        $this->runtime($connection);
        $frame = $this->checked($connection, $order, true);
        $receipt = $frame['basis']['receipt'] ?? throw new CheckoutRefused;
        $charge = $graph['charge'] ?? null;
        if (! is_array($charge) || ($charge['test'] ?? null) !== true || ($charge['id'] ?? null) !== $receipt['charge_id']
            || ($charge['paymentIntent'] ?? null) !== $receipt['payment_intent_id'] || ($charge['currency'] ?? null) !== $receipt['currency']
            || ($charge['amount'] ?? null) !== StripeAmounts::toWire($receipt['amount_minor'], $receipt['currency'])
            || ($charge['captured'] ?? null) !== $charge['amount'] || ($charge['paid'] ?? null) !== true
            || ($charge['capturedFully'] ?? null) !== true || ($charge['status'] ?? null) !== 'succeeded' || ! is_bool($charge['disputed'] ?? null)
            || ! is_array($graph['refunds'] ?? null) || ! array_is_list($graph['refunds']) || count($graph['refunds']) > 200
            || ! is_array($graph['disputes'] ?? null) || ! array_is_list($graph['disputes']) || count($graph['disputes']) > 40) {
            throw new CheckoutRefused('changed');
        }
        $refunded = StripeAmounts::fromWire(MinorUnits::integer($charge['refunded'] ?? null), $order->currency);
        if (AllocationAmounts::compare($refunded, MinorUnits::integer($receipt['amount_minor'])) > 0) {
            throw new CheckoutRefused('changed');
        }
        $seen = [];
        $bindings = [];
        foreach ($graph['refunds'] as $refund) {
            $normalized = $this->refundProof($refund, $receipt);
            if (isset($seen['refund:'.$normalized['object_id']])) {
                throw new CheckoutRefused('changed');
            }
            $seen['refund:'.$normalized['object_id']] = true;
            $known = $this->classifyRefund($connection, $order, $frame, $refund);
            if ($known['origin'] === 'operator') {
                if (isset($bindings[$known['local_reference']]) && $bindings[$known['local_reference']] !== $refund['id']) {
                    // One request can bind one exact provider object. A second object is retained as an
                    // unattributed financial hold, never silently adopted as the same reimbursement.
                    $known = ['origin' => 'external', 'local_reference' => null, 'request_digest' => null];
                } else {
                    $bindings[$known['local_reference']] = $refund['id'];
                }
            }
            $this->observation($connection, $order, $frame, [...$normalized, ...$known]);
            if ($known['origin'] === 'operator') {
                $local = $this->findRefund($frame, $known['local_reference']);
                $this->updateRefund($connection, $order, $local, ['refund_id' => $refund['id'], 'state' => $refund['status'], 'category' => null]);
            } else {
                foreach ($frame['refunds'] as $local) {
                    if ($local->origin === 'external' && $local->refund_id === $refund['id']) {
                        $this->updateRefund($connection, $order, $local, ['state' => $refund['status'], 'category' => null]);
                    }
                }
            }
        }
        foreach ($graph['disputes'] as $dispute) {
            if (! is_array($dispute) || ! self::identifier($dispute['id'] ?? null, 'du_')
                || ($dispute['chargeId'] ?? null) !== $receipt['charge_id'] || ($dispute['paymentIntent'] ?? null) !== $receipt['payment_intent_id']
                || ($dispute['currency'] ?? null) !== $receipt['currency'] || ($dispute['test'] ?? null) !== true
                || ! in_array($dispute['status'] ?? null, self::DISPUTE_STATES, true)
                || ! is_int($dispute['created'] ?? null) || $dispute['created'] < 1 || isset($seen['dispute:'.$dispute['id']])) {
                throw new CheckoutRefused('changed');
            }
            $seen['dispute:'.$dispute['id']] = true;
            $amount = StripeAmounts::fromWire(MinorUnits::integer($dispute['amount'] ?? null), $order->currency);
            if ($amount === '0') {
                throw new CheckoutRefused('changed');
            }
            $this->observation($connection, $order, $frame, ['kind' => 'dispute', 'object_id' => $dispute['id'], 'amount_minor' => $amount,
                'state' => $dispute['status'], 'origin' => 'dispute', 'local_reference' => null, 'request_digest' => null]);
        }
        // A bounded complete graph may not silently lose a previously known object. Absence never restores access.
        foreach ($frame['observations'] as $old) {
            if (! isset($seen[$old->kind.':'.$old->object_id])) {
                throw new CheckoutRefused('operator');
            }
        }
        foreach ($frame['signals'] as $signal) {
            if ($signal->state !== 'unresolved' || $signal->event_type === 'charge.refunded') {
                continue;
            }
            $kind = str_starts_with($signal->event_type, 'refund.') ? 'refund' : 'dispute';
            if (! isset($seen[$kind.':'.$signal->subject_id])) {
                throw new CheckoutRefused('operator');
            }
        }
        // Verified subject signals veto access from ingress until this complete original-account graph is proved.
        FinancialSignals::resolve($connection, (int) $order->org_id, $receipt['charge_id'], $graph);
        $this->updateWork($connection, $order, ['basis_hash' => $frame['basis_hash'], 'charge_refunded_minor' => $refunded,
            'charge_disputed' => $charge['disputed'], 'last_observed_at' => self::now()]);
        $this->bump($connection, $order);
        $this->audit($connection, $order, 'commerce.financial_observed', (new FinancialWork)->newFromBuilder((array) $this->checked($connection, $order, true)['work']), null);
        $this->applyAccess($connection, $order);
    }

    /** @param array<string,mixed> $refund */
    public function bindRefund(Connection $connection, #[\SensitiveParameter] object $order, string $reference, #[\SensitiveParameter] array $refund): void
    {
        $this->inside($connection, $order);
        $frame = $this->checked($connection, $order, true);
        $local = $this->findRefund($frame, self::reference($reference));
        $normalized = $this->refundProof($refund, $frame['basis']['receipt']);
        $known = $this->classifyRefund($connection, $order, $frame, $refund);
        if ($local->origin !== 'operator' || $known['origin'] !== 'operator' || $known['local_reference'] !== $reference) {
            throw new CheckoutRefused('changed');
        }
        $this->observation($connection, $order, $frame, [...$normalized, ...$known]);
        $this->updateRefund($connection, $order, $local, ['refund_id' => $refund['id'], 'state' => $refund['status'], 'category' => null]);
        $this->updateWork($connection, $order, ['basis_hash' => $frame['basis_hash']]);
        $this->bump($connection, $order);
        $this->audit($connection, $order, 'commerce.financial_refund_observed', (new FinancialRefund)->newFromBuilder((array) $this->findRefund($this->checked($connection, $order, true), $reference)), null);
        $this->applyAccess($connection, $order);
    }

    public function schemaAvailable(Connection $connection): bool
    {
        $tables = [self::WORK => ['order_reference', 'revision', 'basis_hash', 'lease_token', 'lease_until', 'replay_count', 'last_observed_at', 'charge_refunded_minor', 'charge_disputed'],
            self::REFUNDS => ['work_id', 'payment_id', 'reference', 'request_key', 'origin', 'purpose', 'reason', 'selection', 'selection_hash', 'basis_hash', 'authorization', 'authorization_hash', 'amount_minor', 'request_payload', 'request_digest', 'idempotency_key', 'credential_witness', 'first_submitted_at', 'refund_id', 'state', 'category', 'replay_count'],
            self::OBSERVATIONS => ['work_id', 'account_id', 'mode', 'kind', 'object_id', 'amount_minor', 'currency', 'state', 'origin', 'local_reference', 'request_digest', 'verified_at']];
        $schema = $connection->getSchemaBuilder();
        $present = array_filter(array_keys($tables), $schema->hasTable(...));
        if ($present === [] && ! $connection->table('migrations')->useWritePdo()->where('migration', '0001_01_01_000214_create_commerce_financial_state')->exists()) {
            return false;
        }
        foreach ($tables as $table => $columns) {
            if (! $schema->hasTable($table) || ! $schema->hasColumns($table, ['id', 'org_id', 'site_id', 'order_id', ...$columns])) {
                throw new CheckoutRefused('changed');
            }
        }

        return true;
    }

    /** @return array<string,mixed> */
    private function basis(Connection $connection, #[\SensitiveParameter] object $order, bool $lock): array
    {
        $lines = $this->rows($connection, 'commerce_order_lines', $order, 20, $lock);
        $allocations = $this->rows($connection, PaidOrders::ALLOCATIONS, $order, 20, $lock);
        $receipt = app(PaidOrders::class)->receipt($connection, $order, $lock);
        app(PaidOrders::class)->projection($connection, (int) $order->org_id, $order, $lock);
        $checkout = $receipt === null ? null : $connection->table(CheckoutAttempts::TABLE)->useWritePdo()->where('id', $receipt->attempt_id)
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();

        return ['version' => 1, 'order' => get_object_vars($order), 'receipt' => $receipt === null ? null : get_object_vars($receipt),
            'checkout' => $checkout === null ? null : get_object_vars($checkout),
            'lines' => array_map(get_object_vars(...), $lines), 'allocations' => array_map(get_object_vars(...), $allocations)];
    }

    /** @param array<string,mixed> $frame
     * @return array{blocked:bool,category:?string,removed_units:array<int,int>}
     */
    private function plan(#[\SensitiveParameter] array $frame): array
    {
        $category = ($frame['basis']['receipt']['allocation_state'] ?? null) === 'held' ? 'original_financial_hold' : null;
        foreach ($frame['signals'] ?? [] as $signal) {
            if ($signal->state === 'unresolved') {
                $category ??= 'financial_signal';
            }
        }
        $used = [];
        $bound = [];
        foreach ($frame['refunds'] as $refund) {
            if ($refund->refund_id !== null) {
                $bound[$refund->refund_id] = true;
            }
            if (in_array($refund->state, ['failed', 'canceled'], true)) {
                continue;
            }
            foreach (self::decode($refund->selection) as $line) {
                foreach ($line['units'] as $unit) {
                    if (isset($used[$line['line_id']][$unit])) {
                        $category = 'capacity_conflict';
                    }
                    $used[$line['line_id']][$unit] = true;
                }
            }
        }
        $knownRefunded = '0';
        $unavailableRefunded = '0';
        $formal = false;
        foreach ($frame['observations'] as $observation) {
            if ($observation->kind === 'dispute') {
                if (in_array($observation->state, ['needs_response', 'under_review', 'lost'], true)) {
                    $category ??= 'dispute';
                }
                $formal = $formal || in_array($observation->state, ['needs_response', 'under_review', 'lost', 'won'], true);
            } elseif (! in_array($observation->state, ['failed', 'canceled'], true)) {
                $knownRefunded = CartAmounts::add($knownRefunded, MinorUnits::integer($observation->amount_minor));
                if ($observation->origin === 'unavailable') {
                    $unavailableRefunded = CartAmounts::add($unavailableRefunded, MinorUnits::integer($observation->amount_minor));
                }
                if ($observation->origin === 'external' && ! isset($bound[$observation->object_id])) {
                    $category ??= 'external_refund';
                }
            }
        }
        if ($unavailableRefunded !== '0') {
            $owed = $frame['unavailable_obligations'][0]->amount_minor ?? null;
            if ($owed === null || AllocationAmounts::compare($unavailableRefunded, MinorUnits::integer($owed)) > 0) {
                $category = 'capacity_conflict';
            }
        }
        if ($frame['work']->charge_disputed && ! $formal) {
            $category ??= 'unresolved_dispute';
        }
        if ($frame['work']->charge_refunded_minor !== null && AllocationAmounts::compare(MinorUnits::integer($frame['work']->charge_refunded_minor), $knownRefunded) > 0) {
            $category ??= 'external_refund';
        }
        $counts = [];
        foreach ($used as $line => $units) {
            $counts[(int) $line] = count($units);
        }
        ksort($counts, SORT_NUMERIC);

        return ['blocked' => $category !== null, 'category' => $category, 'removed_units' => $counts];
    }

    /** @param array<string,mixed> $frame
     * @param  array<int,int>  $quantities
     * @return list<array<string,mixed>>
     */
    private function select(#[\SensitiveParameter] array $frame, array $quantities): array
    {
        $used = [];
        foreach ($frame['refunds'] as $refund) {
            if (! in_array($refund->state, ['failed', 'canceled'], true)) {
                foreach (self::decode($refund->selection) as $line) {
                    foreach ($line['units'] as $unit) {
                        $used[$line['line_id']][$unit] = true;
                    }
                }
            }
        }
        $units = $this->units($frame['basis']);
        $selection = [];
        foreach ($quantities as $id => $quantity) {
            $available = array_values(array_filter(array_keys($units[$id] ?? []), static fn (int $unit): bool => ! isset($used[$id][$unit])));
            if (count($available) < $quantity) {
                throw new CheckoutRefused('changed');
            }
            $selected = array_slice($available, 0, $quantity);
            $parts = array_fill_keys(['item_net_minor', 'item_tax_minor', 'shipping_net_minor', 'shipping_tax_minor'], '0');
            foreach ($selected as $unit) {
                foreach ($parts as $key => $amount) {
                    $parts[$key] = CartAmounts::add($amount, $units[$id][$unit][$key]);
                }
            }
            $selection[] = ['line_id' => $id, 'units' => $selected, ...$parts];
        }

        return $selection;
    }

    /** Stable unit ordinals are the original first-awarded units; original rounding remainders never move.
     * @param  array<string,mixed>  $basis
     * @return array<int,array<int,array<string,string>>>
     */
    private function units(#[\SensitiveParameter] array $basis): array
    {
        $lines = $basis['lines'];
        if ($lines === []) {
            throw new CheckoutRefused('changed');
        }
        $weights = array_map(static fn (array $line): string => $line['delivery_kind'] === 'physical' ? MinorUnits::integer($line['net_minor']) : '0', $lines);
        if (array_filter($weights, static fn (string $amount): bool => $amount !== '0') === []) {
            $weights = array_map(static fn (array $line): string => $line['delivery_kind'] === 'physical' ? (string) $line['quantity'] : '0', $lines);
        }
        $tax = '0';
        foreach ($lines as $line) {
            $tax = CartAmounts::add($tax, MinorUnits::integer($line['tax_minor']));
        }
        $shipNet = AllocationAmounts::split(MinorUnits::integer($basis['order']['quoted_shipping_net_minor']), $weights);
        $shipTax = AllocationAmounts::split(AllocationAmounts::subtract(MinorUnits::integer($basis['order']['quoted_tax_minor']), $tax), $weights);
        $allocations = [];
        foreach ($basis['allocations'] as $allocation) {
            $allocations[(int) $allocation['line_id']] = $allocation;
        }
        $units = [];
        foreach ($lines as $index => $line) {
            $quantity = self::positive($line['quantity'], 99);
            $award = self::counter($allocations[(int) $line['id']]['awarded'] ?? 0, 99);
            $parts = [];
            foreach (['item_net_minor' => MinorUnits::integer($line['net_minor']), 'item_tax_minor' => MinorUnits::integer($line['tax_minor']),
                'shipping_net_minor' => $shipNet[$index], 'shipping_tax_minor' => $shipTax[$index]] as $key => $amount) {
                $parts[$key] = AllocationAmounts::split($amount, array_fill(0, $quantity, '1'));
            }
            for ($unit = 1; $unit <= $award; $unit++) {
                foreach ($parts as $key => $values) {
                    $units[(int) $line['id']][$unit][$key] = $values[$unit - 1];
                }
            }
        }

        return $units;
    }

    /** @param array<string,mixed> $basis */
    private function refundRow(#[\SensitiveParameter] object $row, #[\SensitiveParameter] object $work, #[\SensitiveParameter] array $basis, string $basisHash): void
    {
        self::counter($row->replay_count);
        if ((string) $row->work_id !== (string) $work->id || (string) $row->payment_id !== (string) ($basis['receipt']['id'] ?? '')
            || ! self::hex($row->reference, 32) || ! in_array($row->origin, ['operator', 'external'], true) || ! in_array($row->purpose, ['units', 'held_balance'], true)
            || ! in_array($row->state, self::STATES, true) || $row->basis_hash !== $basisHash
            || ! self::hex($row->selection_hash, 64) || ($row->category !== null && ! in_array($row->category, ['unavailable', 'changed', 'operator', 'external_refund'], true))) {
            throw new CheckoutRefused('changed');
        }
        self::reason($row->reason);
        $selection = self::decode($row->selection);
        if (! array_is_list($selection) || ($row->purpose === 'units' && $selection === []) || ($row->purpose === 'held_balance' && $selection !== [])
            || count($selection) > 20 || CheckoutAttempts::digest(['selection' => $selection]) !== $row->selection_hash) {
            throw new CheckoutRefused('changed');
        }
        $all = $this->units($basis);
        $priorLine = 0;
        foreach ($selection as $line) {
            if (! is_array($line) || count($line) !== 6 || array_diff(array_keys($line), ['line_id', 'units', 'item_net_minor', 'item_tax_minor', 'shipping_net_minor', 'shipping_tax_minor']) !== []
                || ! is_int($line['line_id']) || $line['line_id'] <= $priorLine || ! is_array($line['units']) || ! array_is_list($line['units'])
                || $line['units'] === [] || count($line['units']) > 99) {
                throw new CheckoutRefused('changed');
            }
            $priorLine = $line['line_id'];
            $parts = array_fill_keys(['item_net_minor', 'item_tax_minor', 'shipping_net_minor', 'shipping_tax_minor'], '0');
            $priorUnit = 0;
            foreach ($line['units'] as $unit) {
                if (! is_int($unit) || $unit <= $priorUnit || ! isset($all[$line['line_id']][$unit])) {
                    throw new CheckoutRefused('changed');
                }
                $priorUnit = $unit;
                foreach ($parts as $key => $value) {
                    $parts[$key] = CartAmounts::add($value, $all[$line['line_id']][$unit][$key]);
                }
            }
            foreach ($parts as $key => $value) {
                if (! is_string($line[$key]) || $line[$key] !== $value) {
                    throw new CheckoutRefused('changed');
                }
            }
        }
        $amount = MinorUnits::integer($row->amount_minor);
        if ($amount === '0' || ($row->purpose === 'units' && $amount !== self::selectionAmount($selection))
            || ($row->refund_id !== null && ! self::identifier($row->refund_id, 're_'))) {
            throw new CheckoutRefused('changed');
        }
        if ($row->purpose === 'held_balance') {
            if ($row->origin !== 'operator' || ($basis['receipt']['allocation_state'] ?? null) !== 'held'
                || ! self::hex($row->authorization_hash, 64)) {
                throw new CheckoutRefused('changed');
            }
            $authorization = self::decode($row->authorization);
            if (($authorization['version'] ?? null) !== 1 || ($authorization['receipt_id'] ?? null) !== (string) $basis['receipt']['id']
                || ($authorization['remaining_minor'] ?? null) !== $amount || CheckoutAttempts::digest($authorization) !== $row->authorization_hash
                || ! is_array($authorization['observations'] ?? null) || ! array_is_list($authorization['observations'])
                || count($authorization['observations']) > self::MAX_OBSERVATIONS) {
                throw new CheckoutRefused('changed');
            }
            CheckoutAttempts::instant($authorization['verified_at'] ?? null);
            $credited = '0';
            foreach ($authorization['observations'] as $observation) {
                if (! is_array($observation) || ($observation['kind'] ?? null) !== 'refund') {
                    if (! is_array($observation) || ! in_array($observation['state'] ?? null, ['won', 'warning_needs_response', 'warning_under_review', 'warning_closed'], true)) {
                        throw new CheckoutRefused('changed');
                    }

                    continue;
                }
                if (! in_array($observation['state'] ?? null, ['succeeded', 'failed', 'canceled'], true)) {
                    throw new CheckoutRefused('changed');
                }
                if ($observation['state'] === 'succeeded') {
                    $credited = CartAmounts::add($credited, MinorUnits::integer($observation['amount_minor'] ?? null));
                }
            }
            if ($credited !== MinorUnits::integer($authorization['refunded_minor'] ?? null)
                || AllocationAmounts::subtract(MinorUnits::integer($basis['receipt']['amount_minor']), $credited) !== $amount) {
                throw new CheckoutRefused('changed');
            }
        } elseif ($row->authorization !== null || $row->authorization_hash !== null) {
            throw new CheckoutRefused('changed');
        }
        if ($row->origin === 'operator') {
            if (! self::hex($row->request_key, 32) || $row->idempotency_key !== 'kitsune-refund-'.$row->reference
                || ! self::hex($row->request_digest, 64) || ! is_string($row->request_payload)) {
                throw new CheckoutRefused('changed');
            }
            $wire = StripeAmounts::toWire($amount, $basis['order']['currency']);
            $expected = ['charge' => $basis['receipt']['charge_id'], 'amount' => $wire, 'metadata' => [
                'kitsune_order' => $basis['order']['reference'], 'kitsune_refund' => $row->reference,
                'kitsune_request' => CheckoutAttempts::digest(['order' => $basis['order']['reference'], 'refund' => $row->reference,
                    'charge' => $basis['receipt']['charge_id'], 'amount' => $wire])]];
            if (CheckoutAttempts::digest(self::decode($row->request_payload)) !== $row->request_digest
                || CheckoutAttempts::digest($expected) !== $row->request_digest
                || (($row->credential_witness === null) !== ($row->first_submitted_at === null))
                || ($row->credential_witness !== null && ! self::hex($row->credential_witness, 64))) {
                throw new CheckoutRefused('changed');
            }
        } elseif ($row->request_key !== null || $row->idempotency_key !== null || $row->request_payload !== null || $row->request_digest !== null
            || $row->credential_witness !== null || $row->first_submitted_at !== null || $row->refund_id === null) {
            throw new CheckoutRefused('changed');
        }
        if (in_array($row->state, self::REFUND_STATES, true) && $row->refund_id === null) {
            throw new CheckoutRefused('changed');
        }
        foreach (['created_at', 'updated_at', 'first_submitted_at'] as $column) {
            if ($row->{$column} !== null) {
                CheckoutAttempts::instant($row->{$column});
            }
        }
    }

    /** @param array<string,mixed> $frame
     * @param  list<array<string,mixed>>  $selection
     * @param  array<string,mixed>  $extra
     */
    private function insertRefund(Connection $connection, #[\SensitiveParameter] object $order, #[\SensitiveParameter] array $frame, #[\SensitiveParameter] array $selection, #[\SensitiveParameter] string $reason, string $reference, array $extra): object
    {
        if (count($frame['refunds']) >= self::MAX_REFUNDS) {
            throw new CheckoutRefused('operator');
        }
        $id = $connection->table(self::REFUNDS)->insertGetId(['org_id' => $order->org_id, 'site_id' => $order->site_id,
            'order_id' => $order->id, 'work_id' => $frame['work']->id, 'payment_id' => $frame['basis']['receipt']['id'],
            'reference' => $reference, 'reason' => $reason, 'selection' => self::encode($selection),
            'selection_hash' => CheckoutAttempts::digest(['selection' => $selection]), 'basis_hash' => $frame['basis_hash'],
            'amount_minor' => self::selectionAmount($selection), 'credential_witness' => null, 'first_submitted_at' => null,
            'purpose' => 'units', 'authorization' => null, 'authorization_hash' => null,
            'category' => null, 'created_at' => self::now(), 'updated_at' => self::now(), ...$extra]);
        $this->updateWork($connection, $order, ['basis_hash' => $frame['basis_hash']]);
        $this->bump($connection, $order);
        $after = $this->checked($connection, $order, true);

        return $this->findRefund($after, $reference, (int) $id);
    }

    /** @param array<string,mixed> $frame
     * @param  array<string,mixed>  $refund
     * @return array{origin:string,local_reference:?string,request_digest:?string}
     */
    private function classifyRefund(Connection $connection, #[\SensitiveParameter] object $order, #[\SensitiveParameter] array $frame, #[\SensitiveParameter] array $refund): array
    {
        $metadata = $refund['metadata'] ?? null;
        if (is_array($metadata) && ($metadata['kitsune_order'] ?? null) === $order->reference) {
            foreach ($frame['refunds'] as $local) {
                if ($local->origin === 'operator' && ($metadata['kitsune_refund'] ?? null) === $local->reference) {
                    $request = self::decode($local->request_payload);
                    if (CheckoutAttempts::digest($metadata) !== CheckoutAttempts::digest($request['metadata']) || $refund['amount'] !== $request['amount']) {
                        throw new CheckoutRefused('changed');
                    }
                    if ($local->refund_id !== null && $local->refund_id !== $refund['id']) {
                        return ['origin' => 'external', 'local_reference' => null, 'request_digest' => null];
                    }

                    return ['origin' => 'operator', 'local_reference' => $local->reference, 'request_digest' => $metadata['kitsune_request']];
                }
            }
            $old = $connection->table(PaidOrders::ATTEMPTS)->useWritePdo()->where('order_id', $order->id)
                ->where('reference', $metadata['kitsune_refund'] ?? '')->first();
            if ($old !== null) {
                self::child($old, $order);
                $payload = app(PaidOrders::class)->refundRequest($old);
                if (CheckoutAttempts::digest($metadata) !== CheckoutAttempts::digest($payload['metadata']) || $refund['amount'] !== $payload['amount']) {
                    throw new CheckoutRefused('changed');
                }
                if ($old->refund_id !== null && $old->refund_id !== $refund['id']) {
                    return ['origin' => 'external', 'local_reference' => null, 'request_digest' => null];
                }

                return ['origin' => 'unavailable', 'local_reference' => $old->reference, 'request_digest' => $metadata['kitsune_request']];
            }
        }

        return ['origin' => 'external', 'local_reference' => null, 'request_digest' => null];
    }

    /** @param array<string,mixed> $refund
     * @param  array<string,mixed>  $receipt
     * @return array{kind:string,object_id:string,amount_minor:string,state:string}
     */
    private function refundProof(#[\SensitiveParameter] array $refund, #[\SensitiveParameter] array $receipt): array
    {
        if (! self::identifier($refund['id'] ?? null, 're_') || ($refund['chargeId'] ?? null) !== $receipt['charge_id']
            || ($refund['paymentIntent'] ?? null) !== $receipt['payment_intent_id'] || ($refund['currency'] ?? null) !== $receipt['currency']
            || ! in_array($refund['status'] ?? null, self::REFUND_STATES, true) || ! is_int($refund['created'] ?? null) || $refund['created'] < 1) {
            throw new CheckoutRefused('changed');
        }
        $amount = StripeAmounts::fromWire(MinorUnits::integer($refund['amount'] ?? null), $receipt['currency']);
        if ($amount === '0' || AllocationAmounts::compare($amount, MinorUnits::integer($receipt['amount_minor'])) > 0) {
            throw new CheckoutRefused('changed');
        }

        return ['kind' => 'refund', 'object_id' => $refund['id'], 'amount_minor' => $amount, 'state' => $refund['status']];
    }

    /** @param array<string,mixed> $frame
     * @param  array<string,mixed>  $values
     */
    private function observation(Connection $connection, #[\SensitiveParameter] object $order, #[\SensitiveParameter] array $frame, #[\SensitiveParameter] array $values): void
    {
        $existing = null;
        foreach ($frame['observations'] as $row) {
            if ($row->kind === $values['kind'] && $row->object_id === $values['object_id']) {
                $existing = $row;
            }
        }
        if ($existing !== null && (MinorUnits::integer($existing->amount_minor) !== $values['amount_minor'] || $existing->origin !== $values['origin']
            || $existing->local_reference !== $values['local_reference'] || $existing->request_digest !== $values['request_digest'])) {
            throw new CheckoutRefused('changed');
        }
        $values = [...$values, 'verified_at' => self::now()];
        if ($existing === null) {
            if ($connection->table(self::OBSERVATIONS)->useWritePdo()->where('order_id', $order->id)->count() >= self::MAX_OBSERVATIONS) {
                throw new CheckoutRefused('operator');
            }
            $connection->table(self::OBSERVATIONS)->insert(['org_id' => $order->org_id, 'site_id' => $order->site_id,
                'order_id' => $order->id, 'work_id' => $frame['work']->id, 'account_id' => $frame['basis']['receipt']['account_id'],
                'mode' => 'test', 'currency' => $order->currency, ...$values]);
        } elseif ($connection->table(self::OBSERVATIONS)->where('id', $existing->id)->where('org_id', $order->org_id)
            ->where('site_id', $order->site_id)->where('order_id', $order->id)->update($values) > 1) {
            throw new CheckoutRefused('changed');
        }
    }

    /** @param list<object> $refunds
     * @param  array<string,mixed>  $basis
     */
    private function knownObservation(Connection $connection, #[\SensitiveParameter] object $order, #[\SensitiveParameter] object $observation, #[\SensitiveParameter] array $refunds, #[\SensitiveParameter] array $basis): void
    {
        if ($observation->origin === 'operator') {
            $row = $this->findRefund(['refunds' => $refunds], $observation->local_reference);
            $request = self::decode($row->request_payload);
            if ($row->origin !== 'operator' || $row->refund_id !== $observation->object_id || $row->state !== $observation->state
                || MinorUnits::integer($row->amount_minor) !== MinorUnits::integer($observation->amount_minor)) {
                throw new CheckoutRefused('changed');
            }
        } else {
            $row = $connection->table(PaidOrders::ATTEMPTS)->useWritePdo()->where('order_id', $order->id)
                ->where('reference', $observation->local_reference)->first() ?? throw new CheckoutRefused('changed');
            self::child($row, $order);
            $request = app(PaidOrders::class)->refundRequest($row);
            if (StripeAmounts::fromWire($request['amount'], $basis['order']['currency']) !== MinorUnits::integer($observation->amount_minor)) {
                throw new CheckoutRefused('changed');
            }
        }
        if ($request['metadata']['kitsune_request'] !== $observation->request_digest) {
            throw new CheckoutRefused('changed');
        }
    }

    /** @return list<object> */
    private function rows(Connection $connection, string $table, #[\SensitiveParameter] object $order, int $limit, bool $lock): array
    {
        $rows = $connection->table($table)->useWritePdo()->where('order_id', $order->id)->orderBy('id')->limit($limit + 1)
            ->when($lock, fn ($q) => $q->lockForUpdate())->get()->all();
        if (count($rows) > $limit) {
            throw new CheckoutRefused('changed');
        }
        foreach ($rows as $row) {
            self::child($row, $order);
        }

        return $rows;
    }

    /** @param array<string,mixed> $frame */
    private function digest(#[\SensitiveParameter] array $frame): string
    {
        $input = ['work' => get_object_vars($frame['work']), 'basis' => $frame['basis'],
            'refunds' => array_map(get_object_vars(...), $frame['refunds']), 'observations' => array_map(get_object_vars(...), $frame['observations']),
            'signals' => array_map(get_object_vars(...), $frame['signals'] ?? []),
            'unavailable_obligations' => array_map(get_object_vars(...), $frame['unavailable_obligations'] ?? []),
            'unavailable_attempts' => array_map(get_object_vars(...), $frame['unavailable_attempts'] ?? [])];
        if (strlen(json_encode($input, JSON_THROW_ON_ERROR)) > self::MAX_CUSTODY_BYTES) {
            throw new CheckoutRefused('operator');
        }

        return CheckoutAttempts::digest($input);
    }

    /** @return array<string,mixed> */
    private function captured(Connection $connection, #[\SensitiveParameter] object $order, string $authority, ?string $reference): array
    {
        $frame = $this->checked($connection, $order, true);
        $captured = [...$frame, 'order' => $order, 'digest' => $this->digest($frame), 'lease_token' => $frame['work']->lease_token, 'authority' => $authority];
        if ($reference !== null) {
            $row = $this->findRefund($frame, $reference);
            if ($row->origin !== 'operator') {
                throw new CheckoutRefused('changed');
            }
            $captured += ['refund' => $row, 'request' => self::decode($row->request_payload), 'idempotency_key' => $row->idempotency_key,
                'credential_witness' => $row->credential_witness];
        }

        return $captured;
    }

    /** @param array<string,mixed> $frame */
    private function findRefund(#[\SensitiveParameter] array $frame, string $reference, ?int $id = null): object
    {
        foreach ($frame['refunds'] as $row) {
            if ($row->reference === $reference && ($id === null || (int) $row->id === $id)) {
                return $row;
            }
        }
        throw new CheckoutRefused('changed');
    }

    private function order(string $reference): object
    {
        $connection = (new Order)->getConnection();
        $this->runtime($connection);

        return $connection->table('commerce_orders')->useWritePdo()->where('org_id', $this->context->orgId())
            ->where('site_id', $this->context->siteId())->where('reference', $reference)->lockForUpdate()->first()
            ?? throw new CheckoutRefused('changed');
    }

    private function authority(bool $ownerOnly = false): string
    {
        if ($ownerOnly && app(ReaderGuard::class)->signedIn()) {
            // Persisting an immediately effective intent must have the same authority as the source
            // suspension it requires. A co-present staff owner never bypasses a buyer's reader guard.
            throw new CheckoutRefused;
        }
        Permissions::forget();
        $actor = Permissions::currentUser();
        if ($actor === null) {
            if ($ownerOnly || app(ReaderGuard::class)->signedIn()) {
                throw new CheckoutRefused;
            }

            return 'system';
        }
        if (! $actor instanceof Model || ! Permissions::isOwner($actor) || $actor->getConnection() !== (new Order)->getConnection()) {
            throw new CheckoutRefused;
        }
        $row = $actor->getConnection()->table($actor->getTable())->useWritePdo()->where($actor->getKeyName(), $actor->getKey())
            ->lockForUpdate()->first([$actor->getKeyName(), $actor->getAuthIdentifierName(), $actor->getAuthPasswordName()]) ?? throw new CheckoutRefused;

        return CheckoutAttempts::digest(['org' => $this->context->orgId(), 'site' => $this->context->siteId(),
            'class' => $actor::class, 'id' => (string) $actor->getAuthIdentifier(), 'row' => get_object_vars($row)]);
    }

    private function runtime(Connection $connection): void
    {
        $module = $connection->table('modules')->useWritePdo()->where('handle', 'kitsune/commerce')->where('is_enabled', true)->first();
        if ($module === null || $module->version !== ModuleDiscovery::version('kitsune/commerce')) {
            throw new CheckoutRefused('setup');
        }
    }

    private function inside(Connection $connection, #[\SensitiveParameter] object $order): void
    {
        if ($connection !== (new Order)->getConnection() || $connection->transactionLevel() < 1 || ! $connection->getPdo()->inTransaction()
            || $this->context->orgId() !== (int) $order->org_id || $this->context->siteId() !== (int) $order->site_id) {
            throw new CheckoutRefused('changed');
        }
    }

    private function idle(#[\SensitiveParameter] object $work): void
    {
        if ($work->lease_until !== null && CheckoutAttempts::instant($work->lease_until)->greaterThan(CarbonImmutable::now('UTC'))) {
            throw new CheckoutRefused('busy');
        }
    }

    /** @param array<string,mixed> $frame */
    private function balance(#[\SensitiveParameter] array $frame, ?string $except = null): string
    {
        if (($frame['basis']['receipt']['allocation_state'] ?? null) !== 'held' || $frame['unavailable_obligations'] !== []
            || $frame['work']->last_observed_at === null || CheckoutAttempts::instant($frame['work']->last_observed_at)
                ->addSeconds(self::LEASE_SECONDS)->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
            throw new CheckoutRefused('operator');
        }
        foreach ($frame['signals'] as $signal) {
            if ($signal->state === 'unresolved') {
                throw new CheckoutRefused('operator');
            }
        }
        foreach ($frame['refunds'] as $row) {
            if ($row->reference !== $except && ! in_array($row->state, ['succeeded', 'failed', 'canceled'], true)) {
                throw new CheckoutRefused('operator');
            }
        }
        $credited = '0';
        $formal = false;
        foreach ($frame['observations'] as $row) {
            if ($row->kind === 'dispute') {
                if (in_array($row->state, ['needs_response', 'under_review', 'lost'], true)) {
                    throw new CheckoutRefused('operator');
                }
                $formal = $formal || $row->state === 'won';
            } else {
                if (! in_array($row->state, ['succeeded', 'failed', 'canceled'], true)) {
                    throw new CheckoutRefused('operator');
                }
                if ($row->state === 'succeeded') {
                    $credited = CartAmounts::add($credited, MinorUnits::integer($row->amount_minor));
                }
            }
        }
        if (($frame['work']->charge_disputed && ! $formal) || $credited !== MinorUnits::integer($frame['work']->charge_refunded_minor)) {
            throw new CheckoutRefused('operator');
        }
        $remaining = AllocationAmounts::subtract(MinorUnits::integer($frame['basis']['receipt']['amount_minor']), $credited);
        if ($remaining === '0') {
            throw new CheckoutRefused('operator');
        }

        return $remaining;
    }

    /** @param array<string,mixed> $values */
    private function updateWork(Connection $connection, #[\SensitiveParameter] object $order, #[\SensitiveParameter] array $values): void
    {
        $values['updated_at'] = self::now();
        if ($connection->table(self::WORK)->where('org_id', $order->org_id)->where('site_id', $order->site_id)->where('order_id', $order->id)->update($values) > 1) {
            throw new CheckoutRefused('changed');
        }
    }

    private function bump(Connection $connection, #[\SensitiveParameter] object $order): void
    {
        $revision = self::counter($connection->table(self::WORK)->useWritePdo()->where('order_id', $order->id)->value('revision'));
        if ($revision >= 2147483647 || $connection->table(self::WORK)->where('org_id', $order->org_id)->where('site_id', $order->site_id)
            ->where('order_id', $order->id)->where('revision', $revision)->update(['revision' => $revision + 1, 'updated_at' => self::now()]) !== 1) {
            throw new CheckoutRefused('changed');
        }
    }

    /** @param array<string,mixed> $values */
    private function updateRefund(Connection $connection, #[\SensitiveParameter] object $order, #[\SensitiveParameter] object $row, #[\SensitiveParameter] array $values): void
    {
        $values['updated_at'] = self::now();
        if ($connection->table(self::REFUNDS)->where('id', $row->id)->where('org_id', $order->org_id)->where('site_id', $order->site_id)
            ->where('order_id', $order->id)->update($values) > 1) {
            throw new CheckoutRefused('changed');
        }
    }

    private function release(Connection $connection, #[\SensitiveParameter] object $order): void
    {
        $this->updateWork($connection, $order, ['lease_token' => null, 'lease_until' => null]);
        $this->checked($connection, $order, true);
    }

    private function audit(Connection $connection, #[\SensitiveParameter] object $order, string $action, #[\SensitiveParameter] Model $model, ?string $actor): void
    {
        $authority = $this->authority();
        $frame = $this->checked($connection, $order, true);
        $before = $this->digest($frame);
        $staff = $actor === null ? null : Permissions::currentUser();
        $audit = $actor === null ? $this->auditor->recordForProducerOrFail($action, $model) : $this->auditor->recordOrFail($action, $model);
        $stored = $connection->table('audit_log')->useWritePdo()->where('id', $audit->getKey())->lockForUpdate()->first();
        if ($stored === null || (string) $stored->org_id !== (string) $order->org_id || (string) $stored->site_id !== (string) $order->site_id
            || $stored->action !== $action || $stored->target_type !== $model->getMorphClass() || (string) $stored->target_id !== (string) $model->getKey()
            || $stored->actor_type !== ($staff instanceof Model ? $staff->getMorphClass() : null)
            || $stored->actor_id !== ($staff === null ? null : (string) $staff->getAuthIdentifier())
            || ($actor !== null && $this->authority(true) !== $actor) || $this->authority() !== $authority
            || ! hash_equals($before, $this->digest($this->checked($connection, $order, true)))) {
            throw new CheckoutRefused('changed');
        }
        $this->inside($connection, $order);
        $this->runtime($connection);
    }

    /** Financial consent and its source restriction share the outer commit. This reconciliation has no I/O.
     * Missing readers can remain conservatively held, but a failed or refused source write cannot be advertised
     * as an immediately effective refund intent and then followed by a provider POST.
     */
    private function applyAccess(Connection $connection, #[\SensitiveParameter] object $order): void
    {
        $this->inside($connection, $order);
        $authority = $this->authority();
        $before = $this->digest($this->checked($connection, $order, true));
        $result = app(AccessAdjustments::class)->reconcile($order->reference);
        if (in_array($result['category'], ['retryable', 'source_refused'], true)
            || $this->authority() !== $authority
            || ! hash_equals($before, $this->digest($this->checked($connection, $order, true)))) {
            throw new CheckoutRefused('changed');
        }
        $this->inside($connection, $order);
        $this->runtime($connection);
    }

    private static function child(#[\SensitiveParameter] object $row, #[\SensitiveParameter] object $order): void
    {
        if ((string) $row->org_id !== (string) $order->org_id || (string) $row->site_id !== (string) $order->site_id
            || (string) $row->order_id !== (string) $order->id || self::positive($row->id) < 1) {
            throw new CheckoutRefused('changed');
        }
    }

    /** @param array<int|string,mixed> $values
     * @return array<int,int>
     */
    private static function quantities(#[\SensitiveParameter] array $values): array
    {
        if ($values === [] || count($values) > 20) {
            throw new CheckoutRefused;
        }
        $normalized = [];
        foreach ($values as $id => $quantity) {
            $id = self::positive($id);
            if (! is_int($quantity) || $quantity < 1 || $quantity > 99 || isset($normalized[$id])) {
                throw new CheckoutRefused;
            }
            $normalized[$id] = $quantity;
        }
        ksort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    /** @param list<array<string,mixed>> $selection */
    private static function selectionAmount(#[\SensitiveParameter] array $selection): string
    {
        $total = '0';
        foreach ($selection as $line) {
            foreach (['item_net_minor', 'item_tax_minor', 'shipping_net_minor', 'shipping_tax_minor'] as $key) {
                $total = CartAmounts::add($total, MinorUnits::integer($line[$key]));
            }
        }

        return $total;
    }

    private static function reason(#[\SensitiveParameter] string $reason): string
    {
        if ($reason !== trim($reason) || strlen($reason) < 3 || strlen($reason) > 500 || preg_match('/[\x00-\x1F\x7F]/', $reason) === 1 || preg_match('//u', $reason) !== 1) {
            throw new CheckoutRefused;
        }

        return $reason;
    }

    private static function reference(string $value): string
    {
        if (! self::hex($value, 32)) {
            throw new CheckoutRefused;
        }

        return $value;
    }

    private static function hex(#[\SensitiveParameter] mixed $value, int $length): bool
    {
        return is_string($value) && strlen($value) === $length && preg_match('/\A[a-f0-9]+\z/D', $value) === 1;
    }

    private static function identifier(#[\SensitiveParameter] mixed $value, string $prefix): bool
    {
        return is_string($value) && preg_match('/\A'.preg_quote($prefix, '/').'[A-Za-z0-9_]{1,95}\z/D', $value) === 1;
    }

    private static function counter(#[\SensitiveParameter] mixed $value, int $max = 2147483647): int
    {
        if ((! is_int($value) && ! is_string($value)) || preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', (string) $value) !== 1 || (int) $value > $max) {
            throw new CheckoutRefused('changed');
        }

        return (int) $value;
    }

    private static function positive(#[\SensitiveParameter] mixed $value, int $max = PHP_INT_MAX): int
    {
        if ((! is_int($value) && ! is_string($value)) || preg_match('/\A[1-9][0-9]{0,18}\z/D', (string) $value) !== 1
            || strlen((string) $value) > strlen((string) $max) || (strlen((string) $value) === strlen((string) $max) && strcmp((string) $value, (string) $max) > 0)) {
            throw new CheckoutRefused('changed');
        }

        return (int) $value;
    }

    /** @return array<mixed> */
    private static function decode(#[\SensitiveParameter] mixed $value): array
    {
        if (! is_string($value) || strlen($value) > 262144) {
            throw new CheckoutRefused('changed');
        }
        try {
            $decoded = json_decode($value, true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (Throwable) {
            throw new CheckoutRefused('changed');
        }
        if (! is_array($decoded)) {
            throw new CheckoutRefused('changed');
        }

        return $decoded;
    }

    /** @param array<mixed> $value */
    private static function encode(#[\SensitiveParameter] array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    private static function now(): string
    {
        return CarbonImmutable::now('UTC')->startOfSecond()->format('Y-m-d H:i:s');
    }
}
