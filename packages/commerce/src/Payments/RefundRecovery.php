<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Payments;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Kitsune\Commerce\Carts\CartAmounts;
use Kitsune\Commerce\Models\Order;
use Kitsune\Commerce\Models\RefundAttempt;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Core\Audit\Auditor;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Credentials\CredentialReader;
use Kitsune\Core\Credentials\FreshCredentialAuthentication;
use Kitsune\Core\Credentials\Secret;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Tenancy\Context;
use SensitiveParameter;
use Throwable;

/**
 * One durable TEST refund identity, with bounded reconciliation across crashes and historical credentials.
 * Unknown submission never permits a new identity. The same request may be retried for less than 23 hours;
 * beyond that only exact provider adoption is allowed. A previously succeeded refund is always read again,
 * because Stripe can subsequently report failure. Every provider call happens after the claim commits.
 *
 * @internal
 */
final class RefundRecovery
{
    public const LEASE_SECONDS = 90;

    public const RETRY_HOURS = 23;

    public function __construct(private readonly OrderLocks $locks, private readonly PaidOrders $paid,
        private readonly OrderReaderData $orders, private readonly Context $context, private readonly Auditor $auditor,
        private readonly CredentialReader $credentials, private readonly StripeCheckout $stripe) {}

    /** @return array{reference:string,state:string} */
    public function run(string $orderReference, string $attemptReference): array
    {
        return $this->reconcile($orderReference, $attemptReference);
    }

    /** @return array{reference:string,state:string} */
    public function reconcile(string $orderReference, string $attemptReference, ?string $knownRefundId = null): array
    {
        return $this->process($orderReference, $attemptReference, knownRefundId: $knownRefundId);
    }

    /** The original account's supplied original-mode key remains transient; no current key or mode is rewritten.
     * @return array{reference:string,state:string}
     */
    public function recoverAsOwner(string $orderReference, string $attemptReference, Secret $key,
        #[SensitiveParameter] string $password): array
    {
        try {
            return $this->process($orderReference, $attemptReference, $key, $password);
        } finally {
            unset($key, $password);
        }
    }

    /** Explicitly authorize one new, initially owed identity after freshly verifying the latest failed refund.
     * @return array{reference:string,state:string}
     */
    public function retryAsOwner(string $orderReference, Secret $key, #[SensitiveParameter] string $password): array
    {
        try {
            $this->outside();
            self::reference($orderReference);
            $reference = $this->locks->run(function () use ($orderReference, $password): string {
                $this->authority(true, $password);
                $order = $this->order($orderReference);
                $frame = $this->frame($order);
                $latest = $frame['attempts'][array_key_last($frame['attempts'])];
                if (! in_array($latest->state, ['failed', 'canceled'], true) || $latest->refund_id === null) {
                    throw new CheckoutRefused('changed');
                }

                return self::reference($latest->reference);
            });

            return $this->process($orderReference, $reference, $key, $password, retry: true);
        } catch (Throwable $failure) {
            throw new CheckoutRefused($failure instanceof CheckoutRefused ? $failure->reason : 'unavailable');
        } finally {
            unset($key, $password);
        }
    }

    /** @return array{reference:string,state:string} */
    private function process(string $orderReference, string $attemptReference, ?Secret $key = null,
        #[SensitiveParameter] ?string $password = null, bool $retry = false, ?string $knownRefundId = null): array
    {
        $captured = null;
        $owner = $key !== null;
        try {
            $this->outside();
            self::reference($orderReference);
            self::reference($attemptReference);
            if ($knownRefundId !== null && preg_match('/\Are_[A-Za-z0-9_]{1,95}\z/D', $knownRefundId) !== 1) {
                throw new CheckoutRefused;
            }
            $captured = $this->locks->run(function () use ($orderReference, $attemptReference, $owner, $password, $knownRefundId): array {
                $identity = $this->authority($owner, $password);
                $order = $this->order($orderReference);
                $frame = $this->frame($order);
                $row = $this->find($frame, $attemptReference);
                if ($knownRefundId !== null && $row->refund_id !== null && $row->refund_id !== $knownRefundId) {
                    throw new CheckoutRefused('changed');
                }
                $remembered = app(StripeEventInbox::class)->refundForAttempt($order->getConnection(), (int) $order->org_id,
                    (object) $order->getAttributes(), $row);
                if ($remembered !== null && $knownRefundId !== null && $remembered !== $knownRefundId) {
                    throw new CheckoutRefused('changed');
                }
                $known = $knownRefundId ?? $row->refund_id ?? $remembered;
                if ($row->lease_until !== null && CheckoutAttempts::instant($row->lease_until)->greaterThan(self::now())) {
                    throw new CheckoutRefused('busy');
                }
                $witness = $owner ? null : $this->witness();
                if (! $owner && ! hash_equals($frame['checkout']->credential_witness, $witness)) {
                    throw new CheckoutRefused('changed');
                }
                $count = (string) $row->replay_count;
                if (preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', $count) !== 1 || (int) $count >= 2147483647) {
                    throw new CheckoutRefused;
                }
                $financial = app(FinancialState::class)->checked($order->getConnection(), (object) $order->getAttributes(), true);
                if ($financial['work']->lease_until !== null && CheckoutAttempts::instant($financial['work']->lease_until)->greaterThan(self::now())) {
                    throw new CheckoutRefused('busy');
                }
                // The compatibility path can add six bounded provider reads to the original workflow.
                $leaseSeconds = $financial['refunds'] === [] ? self::LEASE_SECONDS : 180;
                $changes = ['lease_token' => bin2hex(random_bytes(32)), 'lease_until' => self::stored(self::now()->addSeconds($leaseSeconds)),
                    'replay_count' => (int) $count + 1, 'updated_at' => self::stored(self::now())];
                $this->update($order, $row, $changes);
                $frame = $this->frame($order);
                $row = $this->find($frame, $attemptReference);
                $graph = $this->graph($order, $frame);
                $secret = $owner ? null : $this->credentials->secret(StripeCheckout::SLOT);
                if ($this->authority($owner, $password) !== $identity || (! $owner && $witness !== $this->witness())
                    || $graph !== $this->graph($this->order($orderReference))) {
                    throw new CheckoutRefused('changed');
                }

                return ['row' => $row, 'frame' => $frame, 'identity' => $identity, 'witness' => $witness, 'secret' => $secret,
                    'graph' => $graph, 'knownRefundId' => $known];
            });
            $secret = $key ?? $captured['secret'];
            if ($this->stripe->account($secret, $captured['frame']['receipt']->mode) !== $captured['frame']['receipt']->account_id) {
                throw new CheckoutRefused('changed');
            }
            $this->check($orderReference, $attemptReference, $captured, $owner, $password);
            $charge = $this->stripe->retrieveCharge($secret, $captured['frame']['receipt']->charge_id, $captured['frame']['receipt']->mode);
            $this->chargeProof($charge, $captured['frame']['receipt']);
            $this->check($orderReference, $attemptReference, $captured, $owner, $password);
            $financialIds = [];
            $financial = $this->financialCompatibility($captured, $secret, $owner, $password);
            if ($financial !== null) {
                $charge = $financial['charge'];
                $financialIds = $financial['ids'];
            }
            if ($charge['disputed']) {
                return $this->finish($orderReference, $attemptReference, $captured, $owner, $password, 'held', null, 'operator');
            }
            $row = $captured['row'];
            $known = $captured['knownRefundId'];
            if ($known !== null) {
                $refund = $this->stripe->retrieveRefund($secret, $known, $captured['frame']['receipt']->mode);
                $this->refundProof($refund, $captured);
                $this->check($orderReference, $attemptReference, $captured, $owner, $password);
                if (AllocationAmounts::compare($charge['refunded'], StripeAmounts::toWire($captured['frame']['obligation']->amount_minor, $charge['currency'])) > 0
                    || (in_array($refund['status'], ['failed', 'canceled'], true) && $charge['refunded'] !== '0')) {
                    return $this->finish($orderReference, $attemptReference, $captured, $owner, $password, 'held', $refund['id'], 'external_refund');
                }

                return $this->finish($orderReference, $attemptReference, $captured, $owner, $password, $refund['status'], $refund['id'], retry: $retry);
            }
            $matches = [];
            $seen = [];
            $cursor = null;
            $conflict = false;
            for ($pageNumber = 0; $pageNumber < 2; $pageNumber++) {
                $page = $this->stripe->listRefunds($secret, $captured['frame']['receipt']->charge_id, $cursor, mode: $captured['frame']['receipt']->mode);
                $this->check($orderReference, $attemptReference, $captured, $owner, $password);
                foreach ($page['refunds'] as $refund) {
                    if (isset($seen[$refund['id']])) {
                        throw new CheckoutRefused('changed');
                    }
                    $seen[$refund['id']] = true;
                    if (($refund['metadata']['kitsune_refund'] ?? null) === $row->reference) {
                        $this->refundProof($refund, $captured);
                        $matches[$refund['id']] = $refund;
                    } elseif (! isset($financialIds[$refund['id']]) && ! in_array($refund['status'], ['failed', 'canceled'], true)) {
                        $conflict = true;
                    }
                }
                if (! $page['hasMore']) {
                    break;
                }
                $cursor = $page['next'];
            }
            if ($page['hasMore'] || $conflict || count($matches) > 1) {
                return $this->finish($orderReference, $attemptReference, $captured, $owner, $password, 'held', null, 'operator');
            }
            if (count($matches) === 1) {
                $refund = array_values($matches)[0];
                if (AllocationAmounts::compare($charge['refunded'], StripeAmounts::toWire($captured['frame']['obligation']->amount_minor, $charge['currency'])) > 0) {
                    return $this->finish($orderReference, $attemptReference, $captured, $owner, $password, 'held', $refund['id'], 'external_refund');
                }

                return $this->finish($orderReference, $attemptReference, $captured, $owner, $password, $refund['status'], $refund['id']);
            }
            if ($charge['refunded'] !== '0' || $row->first_submitted_at !== null
                && ! CheckoutAttempts::instant($row->first_submitted_at)->addHours(self::RETRY_HOURS)->greaterThan(self::now())) {
                return $this->finish($orderReference, $attemptReference, $captured, $owner, $password, 'held', null,
                    $charge['refunded'] !== '0' ? 'external_refund' : 'operator');
            }
            // Commit the first-submission deadline before the possibly ambiguous POST, never after its response.
            $captured = $this->locks->run(function () use ($orderReference, $attemptReference, $captured, $owner, $password): array {
                $order = $this->checked($orderReference, $attemptReference, $captured, $owner, $password);
                $row = $this->find($this->frame($order), $attemptReference);
                $this->update($order, $row, ['state' => 'submitting', 'category' => null,
                    'first_submitted_at' => $row->first_submitted_at ?? self::stored(self::now()), 'updated_at' => self::stored(self::now())]);
                $frame = $this->frame($order);

                return [...$captured, 'row' => $this->find($frame, $attemptReference), 'frame' => $frame, 'graph' => $this->graph($order, $frame)];
            });
            $this->check($orderReference, $attemptReference, $captured, $owner, $password);
            if (! CheckoutAttempts::instant($captured['row']->first_submitted_at)->addHours(self::RETRY_HOURS)->greaterThan(self::now())) {
                return $this->finish($orderReference, $attemptReference, $captured, $owner, $password, 'held', null, 'operator');
            }
            $refund = $this->stripe->createRefund($secret, $this->paid->refundRequest($captured['row']), $captured['row']->idempotency_key, $captured['frame']['receipt']->mode);
            $this->refundProof($refund, $captured);

            return $this->finish($orderReference, $attemptReference, $captured, $owner, $password, $refund['status'], $refund['id']);
        } catch (Throwable $failure) {
            if (is_array($captured)) {
                try {
                    $this->locks->run(function () use ($orderReference, $attemptReference, $captured, $owner, $password, $failure): void {
                        $order = $this->checked($orderReference, $attemptReference, $captured, $owner, $password);
                        $row = $this->find($this->frame($order), $attemptReference);
                        $changed = $failure instanceof CheckoutRefused && $failure->reason === 'changed';
                        $this->update($order, $row, ['state' => $changed ? 'held' : 'unknown', 'category' => $changed ? 'changed' : 'unavailable',
                            'lease_token' => null, 'lease_until' => null, 'updated_at' => self::stored(self::now())]);
                    });
                } catch (Throwable) {
                    // Failed authority, changed custody or expired lease cannot authorize even cleanup writes.
                }
            }
            throw new CheckoutRefused($failure instanceof CheckoutRefused ? $failure->reason : 'unavailable');
        } finally {
            unset($password, $key);
        }
    }

    /** @param array<string,mixed> $captured
     * @return array{reference:string,state:string}
     */
    private function finish(string $orderReference, string $attemptReference, array $captured, bool $owner,
        #[SensitiveParameter] ?string $password, string $state, ?string $refundId, ?string $category = null, bool $retry = false): array
    {
        return $this->locks->run(function () use ($orderReference, $attemptReference, $captured, $owner, $password, $state, $refundId, $category, $retry): array {
            $order = $this->checked($orderReference, $attemptReference, $captured, $owner, $password);
            $frame = $this->frame($order);
            $row = $this->find($frame, $attemptReference);
            $latest = $frame['attempts'][array_key_last($frame['attempts'])];
            $conflict = false;
            foreach ($frame['attempts'] as $other) {
                if ($other->reference !== $row->reference && in_array($other->state, ['succeeded', 'pending', 'requires_action'], true)) {
                    $conflict = true;
                }
            }
            if ($row->reference !== $latest->reference && ! in_array($state, ['failed', 'canceled'], true)) {
                $conflict = true;
                $this->update($order, $latest, ['state' => 'held', 'category' => 'operator', 'updated_at' => self::stored(self::now())]);
            }
            $changes = ['state' => $conflict ? 'held' : $state, 'refund_id' => $refundId ?? $row->refund_id,
                'category' => $conflict ? 'operator' : ($category ?? (in_array($state, ['failed', 'canceled'], true) ? 'refund_failed' : null)),
                'lease_token' => null, 'lease_until' => null, 'updated_at' => self::stored(self::now())];
            $this->update($order, $row, $changes);
            $frame = $this->frame($order);
            $expected = $this->find($frame, $attemptReference);
            $graph = $this->graph($order, $frame);
            $this->audit($owner ? 'commerce.refund_owner_recovered' : 'commerce.refund_reconciled', $expected);
            if ($this->authority($owner, $password) !== $captured['identity'] || (! $owner && $this->witness() !== $captured['witness'])
                || $graph !== $this->graph($this->order($orderReference))) {
                throw new CheckoutRefused('changed');
            }
            if ($retry) {
                if (! $owner || $conflict || ! in_array($state, ['failed', 'canceled'], true)
                    || $row->reference !== $latest->reference || $refundId === null || count($frame['attempts']) >= PaidOrders::MAX_REFUND_ATTEMPTS) {
                    throw new CheckoutRefused('changed');
                }
                foreach ($frame['attempts'] as $attempt) {
                    if (($attempt->lease_until !== null && CheckoutAttempts::instant($attempt->lease_until)->greaterThan(self::now()))
                        || ! in_array($attempt->state, ['failed', 'canceled'], true)) {
                        throw new CheckoutRefused('changed');
                    }
                }
                $this->paid->newRefundAttempt($order->getConnection(), (object) $order->getAttributes(), (int) $frame['obligation']->id,
                    $frame['receipt']->charge_id, (string) $frame['obligation']->amount_minor);
                $frame = $this->frame($order);
                $expected = $frame['attempts'][array_key_last($frame['attempts'])];
                $graph = $this->graph($order, $frame);
                $this->audit('commerce.refund_retry_authorized', $expected);
                if ($this->authority(true, $password) !== $captured['identity'] || $graph !== $this->graph($this->order($orderReference))) {
                    throw new CheckoutRefused('changed');
                }
            }

            return self::result($expected);
        });
    }

    /** @param array<string,mixed> $captured */
    private function check(string $orderReference, string $attemptReference, array $captured, bool $owner,
        #[SensitiveParameter] ?string $password): void
    {
        $this->locks->run(fn () => $this->checked($orderReference, $attemptReference, $captured, $owner, $password));
    }

    /** @param array<string,mixed> $captured */
    private function checked(string $orderReference, string $attemptReference, array $captured, bool $owner,
        #[SensitiveParameter] ?string $password): Order
    {
        if ($this->authority($owner, $password) !== $captured['identity']) {
            throw new CheckoutRefused('changed');
        }
        $order = $this->order($orderReference);
        $frame = $this->frame($order);
        $row = $this->find($frame, $attemptReference);
        $remembered = app(StripeEventInbox::class)->refundForAttempt($order->getConnection(), (int) $order->org_id,
            (object) $order->getAttributes(), $row);
        if ($captured['graph'] !== $this->graph($order, $frame) || $row->lease_token !== $captured['row']->lease_token
            || $row->lease_until === null || ! CheckoutAttempts::instant($row->lease_until)->greaterThan(self::now())
            || ($remembered !== null && $remembered !== $captured['knownRefundId'])
            || ($row->refund_id !== null && $row->refund_id !== $captured['knownRefundId'])
            || (! $owner && $captured['witness'] !== $this->witness())) {
            throw new CheckoutRefused('changed');
        }

        return $order;
    }

    private function order(string $reference): Order
    {
        $connection = (new Order)->getConnection();
        $row = $this->orders->find($connection, $this->context->orgId() ?? throw new CheckoutRefused,
            $this->context->siteId() ?? throw new CheckoutRefused, $reference, true) ?? throw new CheckoutRefused;
        $this->orders->record($connection, $row);
        if ($row->state !== 'paid' || ! in_array($row->mode, ['test', 'live'], true)) {
            throw new CheckoutRefused('live');
        }

        return (new Order)->newFromBuilder((array) $row);
    }

    /** @return array{receipt:object,checkout:object,obligation:object,attempts:list<object>} */
    private function frame(Order $order): array
    {
        $connection = $order->getConnection();
        $raw = (object) $order->getAttributes();
        $this->paid->projection($connection, (int) $order->org_id, $raw, true);
        $receipt = $this->paid->receipt($connection, $raw, true) ?? throw new CheckoutRefused;
        if ($receipt->api_version !== StripeCheckout::API_VERSION || $receipt->allocation_state !== 'allocated') {
            throw new CheckoutRefused('changed');
        }
        $checkout = $connection->table(CheckoutAttempts::TABLE)->useWritePdo()->where('id', $receipt->attempt_id)->lockForUpdate()->first() ?? throw new CheckoutRefused;
        $obligation = $connection->table(PaidOrders::OBLIGATIONS)->useWritePdo()->where('order_id', $order->getKey())->lockForUpdate()->first() ?? throw new CheckoutRefused;
        $attempts = $connection->table(PaidOrders::ATTEMPTS)->useWritePdo()->where('order_id', $order->getKey())->orderBy('id')
            ->limit(PaidOrders::MAX_REFUND_ATTEMPTS + 1)->lockForUpdate()->get()->all();
        if ($attempts === [] || count($attempts) > PaidOrders::MAX_REFUND_ATTEMPTS) {
            throw new CheckoutRefused;
        }
        foreach ($attempts as $row) {
            $this->paid->refundAttempt($row, $obligation, $receipt, $raw);
            if (in_array($row->state, ['pending', 'requires_action', 'succeeded', 'failed', 'canceled'], true) && $row->refund_id === null) {
                throw new CheckoutRefused('changed');
            }
            if ($row->first_submitted_at !== null && (CheckoutAttempts::instant($row->first_submitted_at)->greaterThan(self::now())
                || CheckoutAttempts::instant($row->first_submitted_at)->lessThan(CheckoutAttempts::instant($row->created_at)))) {
                throw new CheckoutRefused('changed');
            }
        }

        return ['receipt' => $receipt, 'checkout' => $checkout, 'obligation' => $obligation, 'attempts' => $attempts];
    }

    /** @param array{attempts:list<object>} $frame */
    private function find(array $frame, string $reference): object
    {
        foreach ($frame['attempts'] as $row) {
            if ($row->reference === $reference) {
                return $row;
            }
        }
        throw new CheckoutRefused;
    }

    /** @param array<string,mixed>|null $frame */
    private function graph(Order $order, ?array $frame = null): string
    {
        $frame ??= $this->frame($order);
        $record = $this->orders->record($order->getConnection(), (object) $order->getAttributes());
        unset($record['stripe_events']);
        $allocations = $order->getConnection()->table(PaidOrders::ALLOCATIONS)->useWritePdo()->where('order_id', $order->getKey())
            ->orderBy('id')->lockForUpdate()->get()->map(static fn (object $row): array => (array) $row)->all();

        return CheckoutAttempts::digest(['order' => $record, 'receipt' => (array) $frame['receipt'], 'checkout' => (array) $frame['checkout'],
            'obligation' => (array) $frame['obligation'], 'attempts' => array_map(static fn (object $row): array => (array) $row, $frame['attempts']),
            'allocations' => $allocations, 'financial' => app(FinancialState::class)->checked($order->getConnection(), (object) $order->getAttributes(), true)]);
    }

    /** Recognized selected-unit identities may coexist with the original unavailable-unit obligation.
     * Verify every active sibling against its exact local request/attribution and complete current provider graph.
     * No amount-only adoption or relaxed aggregate comparison is permitted.
     *
     * @param  array<string,mixed>  $captured
     * @return array{charge:array<string,mixed>,ids:array<string,true>}|null
     */
    private function financialCompatibility(array $captured, Secret $secret, bool $owner, #[SensitiveParameter] ?string $password): ?array
    {
        $order = $this->locks->run(function () use ($captured, $owner, $password): Order {
            $order = $this->orderById($captured['row']->order_id);
            $this->checked($order->reference, $captured['row']->reference, $captured, $owner, $password);

            return $order;
        });
        $frame = $this->locks->run(function () use ($order): array {
            $frame = app(FinancialState::class)->checked($order->getConnection(), (object) $order->getAttributes(), true);
            if ($frame['work']->lease_until !== null && CheckoutAttempts::instant($frame['work']->lease_until)->greaterThan(self::now())) {
                throw new CheckoutRefused('busy');
            }

            return $frame;
        });
        if ($frame['refunds'] === []) {
            return null;
        }
        $graph = app(StripeFinancialGraph::class)->retrieve($secret, $captured['frame']['receipt'],
            fn () => $this->check($order->reference, $captured['row']->reference, $captured, $owner, $password));
        $ids = [];
        $amount = '0';
        foreach ($graph['refunds'] as $refund) {
            $recognized = false;
            foreach ($frame['refunds'] as $local) {
                if ($local->origin === 'operator') {
                    $request = json_decode($local->request_payload, true, 32, JSON_THROW_ON_ERROR);
                    if (($refund['metadata']['kitsune_refund'] ?? null) !== $local->reference) {
                        continue;
                    }
                    if ($refund['metadata'] !== $request['metadata'] || $refund['amount'] !== $request['amount']
                        || ($local->refund_id !== null && $local->refund_id !== $refund['id'])) {
                        throw new CheckoutRefused('changed');
                    }
                } elseif ($local->refund_id !== $refund['id']) {
                    continue;
                } elseif ($refund['amount'] !== StripeAmounts::toWire($local->amount_minor, $order->currency)) {
                    throw new CheckoutRefused('changed');
                }
                $recognized = true;
                $ids[$refund['id']] = true;
                if (! in_array($refund['status'], ['failed', 'canceled'], true)) {
                    $amount = CartAmounts::add($amount, $refund['amount']);
                }
                break;
            }
            if (! $recognized && ! in_array($refund['status'], ['failed', 'canceled'], true)) {
                $ours = false;
                foreach ($captured['frame']['attempts'] as $old) {
                    $request = $this->paid->refundRequest($old);
                    if ($refund['metadata'] === $request['metadata'] && $refund['amount'] === $request['amount']
                        && ($old->refund_id === null || $old->refund_id === $refund['id'])) {
                        $ours = true;
                    }
                }
                if (! $ours) {
                    throw new CheckoutRefused('operator');
                }
            }
        }
        $charge = $graph['charge'];
        if (AllocationAmounts::compare($amount, $charge['refunded']) > 0) {
            throw new CheckoutRefused('changed');
        }
        $charge['refunded'] = AllocationAmounts::subtract($charge['refunded'], $amount);
        $charge['disputed'] = false;
        foreach ($graph['disputes'] as $dispute) {
            if (in_array($dispute['status'], ['needs_response', 'under_review', 'lost'], true)) {
                $charge['disputed'] = true;
            }
        }

        return ['charge' => $charge, 'ids' => $ids];
    }

    private function orderById(int|string $id): Order
    {
        return Order::query()->useWritePdo()->whereKey($id)->where('org_id', $this->context->orgId())
            ->where('site_id', $this->context->siteId())->lockForUpdate()->first() ?? throw new CheckoutRefused;
    }

    /** @param array<string,mixed> $charge */
    private function chargeProof(array $charge, object $receipt): void
    {
        if ($charge['test'] !== StripeMode::isTest($receipt->mode) || $charge['id'] !== $receipt->charge_id || $charge['paymentIntent'] !== $receipt->payment_intent_id
            || $charge['currency'] !== $receipt->currency || $charge['amount'] !== StripeAmounts::toWire($receipt->amount_minor, $receipt->currency)
            || $charge['captured'] !== $charge['amount'] || ! $charge['paid'] || ! $charge['capturedFully'] || $charge['status'] !== 'succeeded') {
            throw new CheckoutRefused('changed');
        }
    }

    /** @param array<string,mixed> $refund
     * @param  array<string,mixed>  $captured
     */
    private function refundProof(array $refund, array $captured): void
    {
        $receipt = $captured['frame']['receipt'];
        $row = $captured['row'];
        $payload = $this->paid->refundRequest($row);
        if ($refund['chargeId'] !== $receipt->charge_id || $refund['paymentIntent'] !== $receipt->payment_intent_id
            || $refund['currency'] !== $receipt->currency || $refund['amount'] !== $payload['amount']
            || $refund['metadata'] === null || CheckoutAttempts::digest($refund['metadata']) !== CheckoutAttempts::digest($payload['metadata'])
            || ($row->refund_id !== null && $refund['id'] !== $row->refund_id)) {
            throw new CheckoutRefused('changed');
        }
    }

    /** @return array<string,mixed>|null */
    private function authority(bool $owner, #[SensitiveParameter] ?string $password): ?array
    {
        $connection = (new Order)->getConnection();
        $module = $connection->table('modules')->useWritePdo()->where('handle', 'kitsune/commerce')->where('is_enabled', true)->lockForUpdate()->first();
        if ($module === null || $module->version !== ModuleDiscovery::version('kitsune/commerce')) {
            throw new CheckoutRefused('setup');
        }
        if (! $owner) {
            return null;
        }
        $proof = app(FreshCredentialAuthentication::class)->confirm($password);
        $actor = Permissions::currentUser();
        if (! $actor instanceof Model) {
            throw new CheckoutRefused;
        }
        $column = $actor->getAuthPasswordName();
        $raw = $actor->getConnection()->table($actor->getTable())->useWritePdo()->where($actor->getKeyName(), $actor->getKey())->lockForUpdate()->first([$column]);
        $hash = $raw->{$column} ?? null;
        if (! is_string($hash)) {
            throw new CheckoutRefused;
        }
        $proof->assertCurrent();

        return ['org' => $this->context->orgId(), 'site' => $this->context->siteId(), 'class' => $actor::class,
            'id' => (string) $actor->getKey(), 'password' => hash('sha256', $hash)];
    }

    private function witness(): string
    {
        $connection = (new Order)->getConnection();
        $org = $this->context->orgId() ?? throw new CheckoutRefused;

        return StripeMode::witness($connection, $org, StripeMode::current($connection, $org));
    }

    /** @param array<string,mixed> $changes */
    private function update(Order $order, object $row, array $changes): void
    {
        if ($order->getConnection()->table(PaidOrders::ATTEMPTS)->where('id', $row->id)->where('org_id', $order->org_id)
            ->where('site_id', $order->site_id)->where('order_id', $order->getKey())->update($changes) !== 1) {
            throw new CheckoutRefused;
        }
    }

    private function audit(string $action, object $row): void
    {
        $model = (new RefundAttempt)->newFromBuilder((array) $row);
        $actor = Permissions::currentUser();
        $expected = ['org_id' => $row->org_id, 'site_id' => $row->site_id, 'action' => $action,
            'target_type' => $model->getMorphClass(), 'target_id' => (string) $row->id,
            'actor_type' => $actor === null ? null : ($actor instanceof Model ? $actor->getMorphClass() : $actor::class),
            'actor_id' => $actor === null ? null : (string) $actor->getAuthIdentifier()];
        $audit = $this->auditor->recordOrFail($action, $model);
        $saved = (new AuditLog)->getConnection()->table('audit_log')->useWritePdo()->where('id', $audit->getKey())->lockForUpdate()->first();
        foreach ($expected as $column => $value) {
            if ($saved === null || ($value === null) !== ($saved->{$column} === null) || ($value !== null && (string) $saved->{$column} !== (string) $value)) {
                throw new CheckoutRefused;
            }
        }
    }

    private function outside(): void
    {
        if ((new Order)->getConnection()->transactionLevel() !== 0) {
            throw new CheckoutRefused;
        }
    }

    /** @return array{reference:string,state:string} */
    private static function result(object $row): array
    {
        return ['reference' => $row->reference, 'state' => $row->state];
    }

    private static function reference(string $reference): string
    {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $reference) !== 1) {
            throw new CheckoutRefused;
        }

        return $reference;
    }

    private static function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->startOfSecond();
    }

    private static function stored(CarbonImmutable $instant): string
    {
        return $instant->format('Y-m-d H:i:s');
    }
}
