<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Privacy;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Kitsune\Commerce\Models\Order;
use Kitsune\Commerce\Orders\BillingDetails;
use Kitsune\Commerce\Orders\CompletionFacts;
use Kitsune\Commerce\Orders\FreeAccessCorrections;
use Kitsune\Commerce\Orders\FreeOrders;
use Kitsune\Commerce\Orders\HistoricalAccess;
use Kitsune\Commerce\Orders\OrderAccess;
use Kitsune\Commerce\Orders\OrderClaims;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Commerce\Payments\AccessAdjustments;
use Kitsune\Commerce\Payments\AccessContributions;
use Kitsune\Commerce\Payments\AccessFulfillment;
use Kitsune\Commerce\Payments\CheckoutAttempts;
use Kitsune\Commerce\Payments\FinancialSignals;
use Kitsune\Commerce\Payments\FinancialState;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Commerce\Payments\StripeEventInbox;
use Kitsune\Core\Audit\Auditor;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Entitlements\EntitlementWriter;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Throwable;

/** Checked, atomic privacy changes. Financial promises survive account erasure; personal copies do not. @internal */
final class OrderPrivacy
{
    private const TABLES = [
        'commerce_order_lines' => 20, 'commerce_order_access_tokens' => 1000, CheckoutAttempts::TABLE => 20,
        FreeOrders::TABLE => 1, FreeAccessCorrections::WORK => 1, FreeAccessCorrections::TABLE => 1000,
        HistoricalAccess::WORK => 1, HistoricalAccess::TABLE => HistoricalAccess::MAX,
        StripeEventInbox::ROUTING => 20, StripeEventInbox::TABLE => 1000, PaidOrders::RECEIPTS => 1,
        PaidOrders::ALLOCATIONS => 20, PaidOrders::OBLIGATIONS => 1, PaidOrders::ATTEMPTS => 10,
        AccessFulfillment::WORK => 1, AccessFulfillment::EFFECTS => 20, 'commerce_order_claims' => 1,
        FinancialState::WORK => 1, FinancialState::REFUNDS => 100, FinancialState::OBSERVATIONS => 240,
        AccessAdjustments::WORK => 1, AccessAdjustments::EFFECTS => 20,
        PrivacyState::TABLE => 1, PrivacyState::TRANSITIONS => 1000, PrivacyState::DELIVERIES => 1, PrivacyState::HOLDS => 100,
    ];

    /** @var array<string,array<int,true>> */
    private array $erasedSubjects = [];

    public function __construct(private readonly Context $context, private readonly PrivacyState $state, private readonly Auditor $auditor) {}

    /** The whole-reader erasure coordinator already holds Org and all Site locks and has removed the reader's sources.
     * Retained minimization is deliberately not counted as a physically deleted order parent.
     *
     * @return array{deleted:bool,minimized:bool}
     */
    public function eraseForSubject(Connection $connection, #[\SensitiveParameter] object $order, #[\SensitiveParameter] ?string $reader): array
    {
        return $this->safe(function () use ($connection, $order, $reader): array {
            $this->state->inside($connection, $order);
            $privacy = $this->state->checked($connection, $order, true);
            if ($privacy === null) {
                return ['deleted' => false, 'minimized' => false];
            }
            if ($privacy['subject_erased']) {
                return ['deleted' => false, 'minimized' => true];
            }
            $claim = $connection->table('commerce_order_claims')->useWritePdo()->where('order_id', $order->id)->first();
            if ($reader === null || ($order->reader_id !== $reader && $claim?->reader_id !== $reader)) {
                throw new RetentionRefused;
            }
            $attempts = app(CheckoutAttempts::class)->rows($connection, (int) $order->org_id, $order, true);
            $pending = $attempts->contains(static fn (object $attempt): bool => ! in_array($attempt->state, ['expired', 'rejected'], true));
            if (! CompletionFacts::completed($order) && $order->confirmed_total_minor === null && ! $pending) {
                return ['deleted' => false, 'minimized' => false];
            }
            if ($privacy['policy'] === null) {
                throw new RetentionRefused;
            }
            $this->minimize($connection, $order, true, $reader);
            $key = $order->org_id.':'.PrivacyState::guardDigest('subject', $order->org_id."\0".$reader);
            $this->erasedSubjects[$key][(int) $order->id] = true;

            return ['deleted' => false, 'minimized' => true];
        });
    }

    /** Exact unclaimed free-order guest proof; no account or email identity is invented. */
    public function eraseFreeGuest(Connection $connection, Request $request, object $order): void
    {
        $this->safe(function () use ($connection, $request, $order): void {
            $this->state->inside($connection, $order);
            if ($order->state !== 'completed_free' || $order->reader_id !== null
                || app(OrderAccess::class)->guestProof($request, (new Order)->newFromBuilder(get_object_vars($order))) === null
                || $connection->table('commerce_order_claims')->useWritePdo()->where('order_id', $order->id)->lockForUpdate()->exists()) {
                throw new RetentionRefused;
            }
            $this->minimize($connection, $order, true, null);
        });
    }

    /** A final, nonrecursive module witness runs after the host account's last observer in the same transaction. */
    public function verificationForSubject(Connection $connection, int $org, #[\SensitiveParameter] string $reader): Closure
    {
        $this->verificationContext($connection, $org);
        $native = $connection->getPdo();
        if (! $this->state->schemaAvailable($connection)) {
            $available = ['commerce_orders' => app(OrderReaderData::class)->schemaAvailable($connection),
                'commerce_order_claims' => app(OrderClaims::class)->schemaAvailable($connection),
                AccessFulfillment::WORK => app(AccessFulfillment::class)->schemaAvailable($connection)];

            return function () use ($connection, $org, $reader, $available, $native): void {
                $this->verificationContext($connection, $org);
                if ($connection->getPdo() !== $native || $this->state->schemaAvailable($connection)
                    || $available !== ['commerce_orders' => app(OrderReaderData::class)->schemaAvailable($connection),
                        'commerce_order_claims' => app(OrderClaims::class)->schemaAvailable($connection),
                        AccessFulfillment::WORK => app(AccessFulfillment::class)->schemaAvailable($connection)]) {
                    throw new RetentionRefused;
                }
                foreach (['commerce_orders' => 'reader_id', 'commerce_order_claims' => 'reader_id', AccessFulfillment::WORK => 'claim_reader_id'] as $table => $column) {
                    if ($available[$table] && $connection->table($table)->useWritePdo()->where('org_id', $org)->where($column, $reader)->exists()) {
                        throw new RetentionRefused;
                    }
                }
            };
        }
        $subject = PrivacyState::guardDigest('subject', $org."\0".$reader);
        $key = $org.':'.$subject;
        $expectedIds = array_keys($this->erasedSubjects[$key] ?? []);
        unset($this->erasedSubjects[$key]);
        $capture = function () use ($connection, $org, $reader, $subject, $expectedIds, $native): array {
            $this->verificationContext($connection, $org);
            if ($connection->getPdo() !== $native) {
                throw new RetentionRefused;
            }
            foreach (['commerce_orders' => 'reader_id', 'commerce_order_claims' => 'reader_id', AccessFulfillment::WORK => 'claim_reader_id'] as $table => $column) {
                if ($connection->table($table)->useWritePdo()->where('org_id', $org)->where($column, $reader)->exists()) {
                    throw new RetentionRefused;
                }
            }
            $rows = $connection->table(PrivacyState::TABLE)->useWritePdo()->where('org_id', $org)->where('subject_key', $subject)->orderBy('order_id')->lockForUpdate()->get();
            $found = [];
            foreach ($rows as $row) {
                $order = $connection->table('commerce_orders')->useWritePdo()->where('org_id', $org)->where('id', $row->order_id)->lockForUpdate()->first()
                    ?? throw new RetentionRefused;
                $state = $this->state->checked($connection, $order, true) ?? throw new RetentionRefused;
                if (! $state['subject_erased'] || $state['work']->subject_key !== $subject) {
                    throw new RetentionRefused;
                }
                if ($connection->table('entitlements')->useWritePdo()->where('source', 'commerce.order:'.$order->id)->exists()) {
                    throw new RetentionRefused;
                }
                $found[(int) $order->id] = CheckoutAttempts::digest($this->graph($connection, $order));
            }
            if (array_diff($expectedIds, array_keys($found)) !== []) {
                throw new RetentionRefused;
            }

            return $found;
        };
        $expected = $capture();

        return static function () use ($capture, $expected): void {
            if ($capture() !== $expected) {
                throw new RetentionRefused;
            }
        };
    }

    /** Only an explicit recorded adoption changes an existing order; removed personal fields never return. */
    public function adoptAsOwner(string $reference, int $version, #[\SensitiveParameter] string $reason, bool $confirmed): void
    {
        $this->owner($reference, function (Connection $connection, object $order) use ($version, $reason, $confirmed): void {
            $this->confirmation($reason, $confirmed);
            $before = $this->state->checked($connection, $order, true) ?? throw new RetentionRefused;
            $policy = app(RetentionPolicies::class)->version($connection, (int) $order->org_id, $version, true);
            if ($before['policy']?->snapshot() === $policy->snapshot()) {
                return;
            }
            $receipt = CompletionFacts::receipt($connection, $order, true);
            $changes = ['policy_snapshot' => PrivacyState::encode($policy->snapshot()), 'policy_hash' => $policy->configHash];
            if ($receipt !== null) {
                $changes += ['confirmed_at' => CompletionFacts::instant($order, $receipt),
                    'retained_until' => $policy->deadline(CheckoutAttempts::instant(CompletionFacts::instant($order, $receipt)))->format('Y-m-d H:i:s')];
            }
            $this->change($connection, $order, 'policy_adopted', function () use ($connection, $order, $before, $changes, $policy): void {
                $this->state->update($connection, $order, $before['work'], $changes);
                if ($before['subject_erased'] || $before['invoice_expired']) {
                    $billing = PrivacyState::partialBilling($order->billing, PrivacyState::decode($before['work']->retained_billing_fields)['fields']);
                    $oldFields = PrivacyState::decode($before['work']->retained_billing_fields)['fields'];
                    $fields = array_values(array_intersect($oldFields, $policy->billingFields));
                    $selected = $billing === null ? null : PrivacyState::selectedBilling($billing, $fields);
                    $this->state->updateRow($connection, 'commerce_orders', $order, $order, ['billing' => $selected === null ? null : PrivacyState::encode($selected)]);
                    $this->state->seal($connection, $this->fresh($connection, $order), $before['commitments'], []);
                }
            }, reason: $reason, policyBefore: $before['policy']?->configHash, policyAfter: $policy->configHash);
        });
    }

    public function holdAsOwner(string $reference, #[\SensitiveParameter] string $reason, string $reviewDate, bool $confirmed): string
    {
        return $this->owner($reference, function (Connection $connection, object $order) use ($reason, $reviewDate, $confirmed): string {
            $this->confirmation($reason, $confirmed);
            $review = $this->reviewDate($reviewDate);
            $state = $this->state->checked($connection, $order, true) ?? throw new RetentionRefused;
            if ($state['policy'] === null || count($state['holds']) >= PrivacyState::MAX_HOLDS) {
                throw new RetentionRefused;
            }
            $reference = bin2hex(random_bytes(16));
            $this->change($connection, $order, 'hold_added', fn () => $connection->table(PrivacyState::HOLDS)->insert([
                'org_id' => $order->org_id, 'site_id' => $order->site_id, 'order_id' => $order->id, 'work_id' => $state['work']->id,
                'reference' => $reference, 'reason' => $reason, 'review_at' => $review, 'released_at' => null, 'release_reason' => null,
                'created_at' => PrivacyState::now()]));

            return $reference;
        });
    }

    public function releaseHoldAsOwner(string $reference, string $holdReference, #[\SensitiveParameter] string $reason, bool $confirmed): void
    {
        $this->owner($reference, function (Connection $connection, object $order) use ($holdReference, $reason, $confirmed): void {
            $this->confirmation($reason, $confirmed);
            $this->reference($holdReference);
            $state = $this->state->checked($connection, $order, true) ?? throw new RetentionRefused;
            $matches = array_values(array_filter($state['holds'], static fn (object $hold): bool => $hold->reference === $holdReference));
            if (count($matches) !== 1 || $matches[0]->released_at !== null) {
                throw new RetentionRefused;
            }
            $this->change($connection, $order, 'hold_released', function () use ($connection, $order, $matches, $reason): void {
                $this->updateChild($connection, PrivacyState::HOLDS, $order, $matches[0], ['released_at' => PrivacyState::now(), 'release_reason' => $reason]);
            });
        });
    }

    /** @return list<array{reference:string,reason:string,review_at:string}> */
    public function holdsAsOwner(string $reference): array
    {
        return $this->owner($reference, function (Connection $connection, object $order): array {
            $state = $this->state->checked($connection, $order, true) ?? throw new RetentionRefused;

            $result = [];
            foreach ($state['holds'] as $hold) {
                if ($hold->released_at === null) {
                    $result[] = ['reference' => PrivacyState::text($hold->reference, 32), 'reason' => PrivacyState::text($hold->reason),
                        'review_at' => CheckoutAttempts::instant($hold->review_at)->format('Y-m-d H:i:s')];
                }
            }

            return $result;
        });
    }

    /** Completion can be recorded before erasure; no delivered date is guessed from payment or elapsed time. */
    public function completeDeliveryAsOwner(string $reference, #[\SensitiveParameter] string $reason, bool $confirmed): void
    {
        $this->owner($reference, function (Connection $connection, object $order) use ($reason, $confirmed): void {
            $this->confirmation($reason, $confirmed);
            $state = $this->state->checked($connection, $order, true) ?? throw new RetentionRefused;
            if ($state['policy'] === null) {
                throw new RetentionRefused;
            }
            $this->state->ensureDelivery($connection, $order, $state['work']);
            $delivery = $this->state->checked($connection, $order, true)['delivery'] ?? throw new RetentionRefused;
            if ($delivery->state === 'complete') {
                return;
            }
            $this->change($connection, $order, 'delivery_complete', function () use ($connection, $order, $delivery, $reason): void {
                $this->updateChild($connection, PrivacyState::DELIVERIES, $order, $delivery, ['state' => 'complete', 'payload' => null,
                    'payload_hash' => null, 'reason' => $reason, 'completed_at' => PrivacyState::now(), 'updated_at' => PrivacyState::now()]);
            });
        });
    }

    /** Explicit owner projection; the separate pending-delivery data never enters buyer/export summaries.
     * @return array{contact_email:string,delivery:array<string,mixed>,review_at:string}|null
     */
    public function deliveryAsOwner(string $reference): ?array
    {
        return $this->owner($reference, function (Connection $connection, object $order): ?array {
            $state = $this->state->checked($connection, $order, true) ?? throw new RetentionRefused;
            $delivery = $state['delivery'];
            if ($delivery === null || $delivery->state !== 'pending') {
                return null;
            }
            $payload = $delivery->payload === null ? ['contact_email' => $order->contact_email,
                'delivery' => $order->delivery === null ? null : PrivacyState::decode($order->delivery)] : PrivacyState::decode($delivery->payload);
            if (! is_string($payload['contact_email']) || ! is_array($payload['delivery'])) {
                throw new RetentionRefused;
            }

            return ['contact_email' => $payload['contact_email'], 'delivery' => BillingDetails::from($payload['delivery']),
                'review_at' => CheckoutAttempts::instant($delivery->review_at)->format('Y-m-d H:i:s')];
        });
    }

    /** @return array{examined:int,minimized:int,purged:int,held:int} */
    public function maintainOrder(string $reference, bool $dryRun = false): array
    {
        return $this->scoped($reference, fn (Connection $connection, object $order): array => $this->maintain($connection, $order, $dryRun));
    }

    /** Bounded, cold data maintenance; requires an explicit Org and works with disabled/stale module runtime.
     * @return array{examined:int,minimized:int,purged:int,held:int}
     */
    public function sweep(int $limit = 100, bool $dryRun = false): array
    {
        return $this->safe(function () use ($limit, $dryRun): array {
            $org = $this->context->orgId() ?? throw new RetentionRefused;
            if ($limit < 1 || $limit > 1000 || app(ReaderGuard::class)->signedIn()) {
                throw new RetentionRefused;
            }
            $this->authority(false);
            $connection = (new Order)->getConnection();
            if (! $this->state->schemaAvailable($connection)) {
                return ['examined' => 0, 'minimized' => 0, 'purged' => 0, 'held' => 0];
            }
            if ($connection->table('commerce_orders')->useWritePdo()->leftJoin(PrivacyState::TABLE, PrivacyState::TABLE.'.order_id', '=', 'commerce_orders.id')
                ->where('commerce_orders.org_id', $org)->whereNull(PrivacyState::TABLE.'.id')->exists()) {
                throw new RetentionRefused;
            }
            $rows = $connection->table('commerce_orders')->useWritePdo()->join(PrivacyState::TABLE, PrivacyState::TABLE.'.order_id', '=', 'commerce_orders.id')
                ->where('commerce_orders.org_id', $org)->where(PrivacyState::TABLE.'.org_id', $org)
                ->orderBy(PrivacyState::TABLE.'.maintenance_count')->orderBy('commerce_orders.site_id')->orderBy('commerce_orders.id')
                ->select('commerce_orders.*')->limit($limit)->get();
            $result = ['examined' => 0, 'minimized' => 0, 'purged' => 0, 'held' => 0];
            foreach ($rows as $row) {
                $piece = $this->scoped($row->reference, function (Connection $connection, object $order) use ($dryRun): array {
                    $state = $this->state->checked($connection, $order, true) ?? throw new RetentionRefused;
                    if (! $dryRun) {
                        $count = PrivacyState::counter($state['work']->maintenance_count);
                        if ($count === 4294967295) {
                            throw new RetentionRefused;
                        }
                        $this->state->update($connection, $order, $state['work'], ['maintenance_count' => $count + 1]);
                    }

                    return $this->maintain($connection, $order, $dryRun);
                }, (int) $row->site_id);
                foreach ($result as $key => $value) {
                    $result[$key] += $piece[$key];
                }
            }

            return $result;
        });
    }

    /** @return array{examined:int,minimized:int,purged:int,held:int} */
    private function maintain(Connection $connection, object $order, bool $dryRun): array
    {
        $state = $this->state->checked($connection, $order, true) ?? throw new RetentionRefused;
        $result = ['examined' => 1, 'minimized' => 0, 'purged' => 0, 'held' => 0];
        if ($state['subject_erased'] && ! CompletionFacts::completed($order) && $order->confirmed_total_minor === null
            && ! array_filter($state['holds'], static fn (object $hold): bool => $hold->released_at === null)
            && $state['delivery']?->state !== 'pending' && $this->terminalUnpaid($connection, $order) && $this->sourceInactive($connection, $order)) {
            if (! $dryRun) {
                $this->purge($connection, $order);
            }

            return [...$result, 'purged' => 1];
        }
        if ($state['policy'] === null || $state['deadline'] === null || $state['deadline'] > PrivacyState::now()) {
            return [...$result, 'held' => 1];
        }
        $legal = array_filter($state['holds'], static fn (object $hold): bool => $hold->released_at === null) !== [];
        if ($legal) {
            return [...$result, 'held' => 1];
        }
        if (! $state['invoice_expired']) {
            $result['minimized'] = 1;
            if (! $dryRun) {
                $this->minimize($connection, $order, false, null);
                $order = $this->fresh($connection, $order);
                $state = $this->state->checked($connection, $order, true) ?? throw new RetentionRefused;
            }
        }
        if ($state['delivery']?->state === 'pending' || $this->activePromise($connection, $order, $state) || ! $this->financialClosed($connection, $order) || ! $this->sourceInactive($connection, $order)) {
            return [...$result, 'held' => 1];
        }
        $result['purged'] = 1;
        $result['minimized'] = 0;
        if (! $dryRun) {
            $this->purge($connection, $order);
        }

        return $result;
    }

    /** Physical deletion is downstream of independent invoice, financial, fulfillment and original-access lifetimes. */
    private function purge(Connection $connection, object $order): void
    {
        $this->state->remember($connection, $order, true);
        $expected = $this->graph($connection, $order);
        $this->retireSource($connection, $order, $expected);
        $identity = $this->authority(false);
        $audit = $this->audit($connection, $order, 'commerce.order_privacy_purged', $identity);
        $this->assertAuthority($identity, false);
        if ($expected !== $this->graph($connection, $this->fresh($connection, $order))) {
            throw new RetentionRefused;
        }
        $this->assertAudit($connection, $audit, $identity);
        // Restricted foreign keys require this exact descendant-first deletion order.
        foreach ([HistoricalAccess::TABLE, HistoricalAccess::WORK, FreeAccessCorrections::TABLE, FreeAccessCorrections::WORK, FreeOrders::TABLE, StripeEventInbox::TABLE, StripeEventInbox::ROUTING, 'commerce_order_access_tokens', 'commerce_order_claims',
            AccessAdjustments::EFFECTS, AccessAdjustments::WORK, AccessFulfillment::EFFECTS, AccessFulfillment::WORK,
            FinancialState::OBSERVATIONS, FinancialState::REFUNDS, FinancialState::WORK, PaidOrders::ATTEMPTS,
            PaidOrders::OBLIGATIONS, PaidOrders::ALLOCATIONS, PaidOrders::RECEIPTS, CheckoutAttempts::TABLE,
            PrivacyState::HOLDS, PrivacyState::DELIVERIES, PrivacyState::TRANSITIONS, PrivacyState::TABLE, 'commerce_order_lines'] as $table) {
            $rows = $this->state->children($connection, $table, $order, self::TABLES[$table], true);
            if ($connection->table($table)->where('org_id', $order->org_id)->where('site_id', $order->site_id)->where('order_id', $order->id)->delete() !== count($rows)) {
                throw new RetentionRefused;
            }
        }
        $receipt = $expected['receipt_charge'];
        if ($receipt !== null) {
            $connection->table(FinancialSignals::TABLE)->where('org_id', $order->org_id)->where('mode', $order->mode)->where('charge_id', $receipt)->delete();
        }
        if ($connection->table('commerce_orders')->where('id', $order->id)->where('org_id', $order->org_id)->where('site_id', $order->site_id)
            ->where('reference', $order->reference)->delete() !== 1) {
            throw new RetentionRefused;
        }
        foreach (array_keys(self::TABLES) as $table) {
            if ($connection->table($table)->useWritePdo()->where('order_id', $order->id)->exists()) {
                throw new RetentionRefused;
            }
        }
        if ($connection->table('commerce_orders')->useWritePdo()->where('id', $order->id)->exists()) {
            throw new RetentionRefused;
        }
        $this->assertAudit($connection, $audit, $identity);
    }

    /** @param array<string,mixed> $expected */
    private function retireSource(Connection $connection, object $order, array $expected): void
    {
        $verify = function () use ($connection, $order, $expected): void {
            $current = $this->fresh($connection, $order);
            $state = $this->state->checked($connection, $current, true) ?? throw new RetentionRefused;
            if ($this->graph($connection, $current) !== $expected || $state['delivery']?->state === 'pending'
                || array_filter($state['holds'], static fn (object $hold): bool => $hold->released_at === null) !== []
                || $this->activePromise($connection, $current, $state)
                || (CompletionFacts::completed($current) ? (! $state['invoice_expired'] || $state['deadline'] === null
                    || $state['deadline'] > PrivacyState::now() || ! $this->financialClosed($connection, $current)) : ! $this->terminalUnpaid($connection, $current))) {
                throw new RetentionRefused;
            }
        };
        $beforeOrg = $this->context->org();
        $beforeSite = $this->context->site();
        try {
            $site = Site::query()->useWritePdo()->whereKey($order->site_id)->where('org_id', $order->org_id)->lockForUpdate()->first() ?? throw new RetentionRefused;
            $this->context->setSite($site);
            app(EntitlementWriter::class)->retireVerifiedOrderSource('commerce.order:'.$order->id, $verify);
        } finally {
            $this->context->forget();
            $beforeSite !== null ? $this->context->setSite($beforeSite) : $this->context->setOrg($beforeOrg);
        }
    }

    private function minimize(Connection $connection, object $order, bool $subject, ?string $reader): void
    {
        $state = $this->state->checked($connection, $order, true) ?? throw new RetentionRefused;
        if ($state['policy'] === null) {
            throw new RetentionRefused;
        }
        $access = $connection->table(AccessFulfillment::WORK)->useWritePdo()->where('order_id', $order->id)->sole();
        if (CompletionFacts::completed($order) && $access->state === 'initializing') {
            $beforeOrg = $this->context->org();
            $beforeSite = $this->context->site();
            try {
                $site = Site::query()->useWritePdo()->whereKey($order->site_id)->where('org_id', $order->org_id)->first() ?? throw new RetentionRefused;
                $this->context->setSite($site);
                app(AccessFulfillment::class)->freeze($connection, $order);
            } finally {
                $this->context->forget();
                $beforeSite !== null ? $this->context->setSite($beforeSite) : $this->context->setOrg($beforeOrg);
            }
            $state = $this->state->checked($connection, $order, true) ?? throw new RetentionRefused;
        }
        $record = app(OrderReaderData::class)->record($connection, $order);
        $commitments = $this->state->captureOriginal($connection, $order, $record);
        $expired = $state['invoice_expired'] || ($state['deadline'] !== null && $state['deadline'] <= PrivacyState::now()
            && array_filter($state['holds'], static fn (object $hold): bool => $hold->released_at === null) === []);
        $this->change($connection, $order, $subject ? 'subject_erased' : 'invoice_expired', function () use ($connection, $order, $state, $commitments, $expired, $subject, $reader): void {
            $this->state->ensureDelivery($connection, $order, $state['work']);
            $delivery = $connection->table(PrivacyState::DELIVERIES)->useWritePdo()->where('order_id', $order->id)->first();
            if ($delivery !== null && $delivery->state === 'pending' && $delivery->payload === null) {
                $payload = ['contact_email' => $order->contact_email, 'delivery' => $order->delivery === null ? null : PrivacyState::decode($order->delivery)];
                if (! is_string($payload['contact_email']) || ! is_array($payload['delivery'])) {
                    throw new RetentionRefused;
                }
                $this->updateChild($connection, PrivacyState::DELIVERIES, $order, $delivery, ['payload' => PrivacyState::encode($payload),
                    'payload_hash' => CheckoutAttempts::digest($payload), 'updated_at' => PrivacyState::now()]);
            }
            $billing = $expired || ! CompletionFacts::completed($order) ? null : ($state['invoice_expired'] ? null : PrivacyState::selectedBilling(
                $state['subject_erased'] ? PrivacyState::partialBilling($order->billing, PrivacyState::decode($state['work']->retained_billing_fields)['fields'])
                    : BillingDetails::from(PrivacyState::decode($order->billing)), $state['policy']->billingFields));
            $changes = ['contact_email' => null, 'delivery' => null, 'billing' => $billing === null ? null : PrivacyState::encode($billing)];
            $flags = $expired || ! $subject ? ['invoice_expired_at' => $state['work']->invoice_expired_at ?? PrivacyState::now()] : [];
            if ($subject) {
                if (($reader === null && ($order->state !== 'completed_free' || $order->reader_id !== null
                    || $connection->table('commerce_order_claims')->useWritePdo()->where('order_id', $order->id)->exists())) || $connection->table('entitlements')->useWritePdo()->where('source', 'commerce.order:'.$order->id)->exists()) {
                    throw new RetentionRefused;
                }
                $changes += ['reader_id' => null, 'owner_key' => bin2hex(random_bytes(32)), 'cart_ref' => null, 'cart_version' => null, 'active_cart_key' => null];
                $flags += ['subject_erased_at' => PrivacyState::now(), 'subject_key' => $reader === null ? PrivacyState::guardDigest('free_guest', $order->org_id."\0".$order->reference) : PrivacyState::guardDigest('subject', $order->org_id."\0".$reader)];
                $connection->table('commerce_order_claims')->where('org_id', $order->org_id)->where('site_id', $order->site_id)->where('order_id', $order->id)->delete();
                $work = $connection->table(AccessFulfillment::WORK)->useWritePdo()->where('order_id', $order->id)->sole();
                $manifest = $work->manifest === null ? null : PrivacyState::decode($work->manifest);
                if ($manifest !== null) {
                    $manifest['order']['reader_id'] = null;
                    $manifest['order']['owner_key'] = $changes['owner_key'];
                }
                $this->updateChild($connection, AccessFulfillment::WORK, $order, $work, ['manifest' => $manifest === null ? null : PrivacyState::encode($manifest),
                    'claim_binding' => null, 'claim_hash' => null, 'claim_reader_id' => null, 'state' => 'suppressed', 'category' => 'subject_erased', 'updated_at' => PrivacyState::now()]);
                $adjustment = $connection->table(AccessAdjustments::WORK)->useWritePdo()->where('order_id', $order->id)->sole();
                $this->updateChild($connection, AccessAdjustments::WORK, $order, $adjustment, ['state' => 'suppressed', 'category' => 'subject_erased',
                    'beneficiary_hash' => null, 'updated_at' => PrivacyState::now()]);
                foreach ($this->state->children($connection, AccessAdjustments::EFFECTS, $order, 20, true) as $effect) {
                    $this->updateChild($connection, AccessAdjustments::EFFECTS, $order, $effect, ['revocation_witness' => null]);
                }
            }
            foreach ($this->state->children($connection, CheckoutAttempts::TABLE, $order, 20, true) as $attempt) {
                if ($attempt->checkout_url !== null) {
                    $this->updateChild($connection, CheckoutAttempts::TABLE, $order, $attempt, ['checkout_url' => null]);
                }
            }
            $connection->table('commerce_order_access_tokens')->where('org_id', $order->org_id)->where('site_id', $order->site_id)->where('order_id', $order->id)->delete();
            $connection->table(StripeEventInbox::TABLE)->where('org_id', $order->org_id)->where('site_id', $order->site_id)->where('order_id', $order->id)->update(['raw_payload' => null]);
            app(HistoricalAccess::class)->redact($connection, $order);
            $this->state->updateRow($connection, 'commerce_orders', $order, $order, $changes);
            $current = $this->fresh($connection, $order);
            $this->state->seal($connection, $current, $commitments, $flags);
            $this->state->remember($connection, $current);
        });
    }

    /** No current product or stock balance may replace an immutable access promise.
     * @param  array<string,mixed>  $state
     */
    private function activePromise(Connection $connection, object $order, array $state): bool
    {
        if ($state['subject_erased']) {
            return false;
        }
        app(AccessFulfillment::class)->projection($connection, (int) $order->org_id, $order, true);
        $work = $connection->table(AccessFulfillment::WORK)->useWritePdo()->where('order_id', $order->id)->sole();
        $manifest = $work->manifest === null ? null : PrivacyState::decode($work->manifest);
        if ($manifest === null || in_array($work->state, ['initializing', 'awaiting_payment'], true)) {
            return true;
        }
        if ($manifest['allocation_state'] === 'held') {
            return false;
        }
        $plan = app(FinancialState::class)->accessPlan($connection, $order, true);
        $effective = app(HistoricalAccess::class)->effective($connection, $order, $manifest, true);
        $contributions = AccessContributions::project($effective, $plan['removed_units'], $plan['blocked']);
        foreach ($contributions as $definition) {
            if (($definition['remaining'] && ! $definition['known']) || (! $definition['suspended'] && ($definition['expires_at'] === null || $definition['expires_at'] > PrivacyState::now()))) {
                return true;
            }
        }

        return false;
    }

    /** A financial conclusion cannot silently delete a stale live source. */
    private function sourceInactive(Connection $connection, object $order): bool
    {
        $rows = $connection->table('entitlements')->useWritePdo()->where('org_id', $order->org_id)->where('site_id', $order->site_id)
            ->where('source', 'commerce.order:'.$order->id)->orderBy('id')->limit(21)->lockForUpdate()->get();
        if ($rows->count() > 20) {
            throw new RetentionRefused;
        }
        foreach ($rows as $row) {
            if ($row->expires_at !== null) {
                CheckoutAttempts::instant($row->expires_at);
            }
            if ($row->revoked_at !== null) {
                CheckoutAttempts::instant($row->revoked_at);
            } elseif ($row->expires_at === null || $row->expires_at > PrivacyState::now()) {
                return false;
            }
        }

        return true;
    }

    /** Latest complete durable provider evidence, never inferred settlement from local clocks. */
    private function financialClosed(Connection $connection, object $order): bool
    {
        $frame = app(FinancialState::class)->checked($connection, $order, true);
        $plan = app(FinancialState::class)->accessPlan($connection, $order, true);
        if ($order->state === 'completed_free') {
            CompletionFacts::receipt($connection, $order, true) ?? throw new RetentionRefused;

            return $frame['refunds'] === [] && $frame['observations'] === [] && $frame['signals'] === []
                && $frame['work']->lease_token === null && $frame['work']->last_observed_at === null && ! $plan['blocked'];
        }
        $heldReturned = $plan['category'] === 'original_financial_hold' && $frame['work']->charge_refunded_minor !== null
            && (string) $frame['work']->charge_refunded_minor === (string) $order->confirmed_total_minor;
        if (! CompletionFacts::completed($order) || $frame['work']->last_observed_at === null || $frame['work']->lease_token !== null
            || ($plan['blocked'] && ! $heldReturned)) {
            return false;
        }
        foreach ($frame['signals'] as $signal) {
            if ($signal->state !== 'resolved') {
                return false;
            }
        }
        foreach ($frame['refunds'] as $refund) {
            if (! in_array($refund->state, ['succeeded', 'failed', 'canceled'], true)) {
                return false;
            }
        }
        foreach ($frame['unavailable_attempts'] as $refund) {
            if ($refund->lease_token !== null || ! in_array($refund->state, ['succeeded', 'failed', 'canceled'], true)) {
                return false;
            }
        }
        if ($frame['unavailable_obligations'] !== [] && array_filter($frame['unavailable_attempts'], static fn (object $refund): bool => $refund->state === 'succeeded') === []) {
            return false;
        }
        foreach ($frame['observations'] as $observation) {
            if ($observation->origin === 'external' && ! in_array($observation->state, ['failed', 'canceled'], true)
                && array_filter($frame['refunds'], static fn (object $refund): bool => $refund->refund_id === $observation->object_id) === []) {
                return false;
            }
            if (($observation->kind === 'refund' && ! in_array($observation->state, ['succeeded', 'failed', 'canceled'], true))
                || ($observation->kind === 'dispute' && in_array($observation->state, ['needs_response', 'under_review', 'lost'], true))) {
                return false;
            }
        }

        return true;
    }

    /** The audited transition and exact complete raw graph are reread after every host callback. */
    private function change(Connection $connection, object $order, string $action, Closure $change, ?string $reason = null, ?string $policyBefore = null, ?string $policyAfter = null): void
    {
        $native = $connection->getPdo();
        $identity = $this->authority(false);
        if ($connection->getPdo() !== $native) {
            throw new RetentionRefused;
        }
        $before = CheckoutAttempts::digest($this->graph($connection, $order));
        $change();
        $current = $this->fresh($connection, $order);
        $state = $this->state->checked($connection, $current, true) ?? throw new RetentionRefused;
        $revision = PrivacyState::counter($state['work']->revision) + 1;
        if ($revision > PrivacyState::MAX_TRANSITIONS) {
            throw new RetentionRefused;
        }
        $after = CheckoutAttempts::digest($this->graph($connection, $current));
        $connection->table(PrivacyState::TRANSITIONS)->insert(['org_id' => $order->org_id, 'site_id' => $order->site_id, 'order_id' => $order->id,
            'work_id' => $state['work']->id, 'reference' => bin2hex(random_bytes(16)), 'action' => $action, 'reason' => $reason,
            'policy_before_hash' => $policyBefore, 'policy_after_hash' => $policyAfter, 'revision' => $revision,
            'before_hash' => $before, 'after_hash' => $after, 'created_at' => PrivacyState::now()]);
        $this->state->update($connection, $current, $state['work'], ['revision' => $revision]);
        $expected = $this->graph($connection, $current);
        $audit = $this->audit($connection, $current, 'commerce.order_privacy_'.$action, $identity);
        $this->assertAuthority($identity, false);
        if ($connection->getPdo() !== $native) {
            throw new RetentionRefused;
        }
        $current = $this->fresh($connection, $order);
        app(OrderReaderData::class)->record($connection, $current);
        if ($this->state->checked($connection, $current, true)['subject_erased']
            && $connection->table('entitlements')->useWritePdo()->where('source', 'commerce.order:'.$order->id)->exists()) {
            throw new RetentionRefused;
        }
        if (CheckoutAttempts::digest($expected) !== CheckoutAttempts::digest($this->graph($connection, $current))) {
            throw new RetentionRefused;
        }
        $this->assertAudit($connection, $audit, $identity);
    }

    /** @return array<string,mixed> */
    private function graph(Connection $connection, object $order): array
    {
        $rows = [];
        foreach (self::TABLES as $table => $limit) {
            $rows[$table] = array_map(get_object_vars(...), $this->state->children($connection, $table, $order, $limit, true));
        }
        $charge = $rows[PaidOrders::RECEIPTS][0]['charge_id'] ?? null;
        $signals = $charge === null ? [] : array_map(get_object_vars(...), FinancialSignals::rows($connection, (int) $order->org_id, $charge, true, $order->mode));
        $work = $rows[PrivacyState::TABLE][0];
        $guards = $work['guard_keys'] === null ? [] : $this->state->guardFrame($connection, $order, (object) $work);

        // Installation receipts are global; cold custody accepts their recorded switch/version but freezes them across callbacks.
        $modules = $connection->table('modules')->useWritePdo()->where('handle', 'kitsune/commerce')->limit(2)->lockForUpdate()->get();
        if ($modules->count() !== 1) {
            throw new RetentionRefused;
        }

        return ['order' => get_object_vars($order), 'children' => $rows, 'signals' => $signals, 'module' => get_object_vars($modules->sole()),
            'guards' => $guards, 'receipt_charge' => $charge];
    }

    /** A provider-authoritative terminal Session is distinct from an elapsed local clock. */
    private function terminalUnpaid(Connection $connection, object $order): bool
    {
        app(OrderReaderData::class)->record($connection, $order);
        $attempts = app(CheckoutAttempts::class)->rows($connection, (int) $order->org_id, $order, true);
        if ($attempts->isEmpty() || $attempts->contains(static fn (object $attempt): bool => ! in_array($attempt->state, ['expired', 'rejected'], true)
            || $attempt->lease_token !== null)) {
            return false;
        }
        foreach ([PaidOrders::RECEIPTS, PaidOrders::ALLOCATIONS, PaidOrders::OBLIGATIONS, PaidOrders::ATTEMPTS,
            FinancialState::REFUNDS, FinancialState::OBSERVATIONS, AccessFulfillment::EFFECTS, AccessAdjustments::EFFECTS, 'commerce_order_claims'] as $table) {
            if ($connection->table($table)->useWritePdo()->where('order_id', $order->id)->exists()) {
                return false;
            }
        }

        return true;
    }

    /** Final raw proof after host-backed authority callbacks, including removed-parent negative custody. */
    private function finalProof(Connection $connection, object $order): string
    {
        $current = $connection->table('commerce_orders')->useWritePdo()->where('id', $order->id)->first();
        if ($current !== null) {
            $this->state->checked($connection, $current, true);

            return CheckoutAttempts::digest($this->graph($connection, $current));
        }
        foreach (array_keys(self::TABLES) as $table) {
            if ($connection->table($table)->useWritePdo()->where('order_id', $order->id)->exists()) {
                throw new RetentionRefused;
            }
        }
        $guards = $connection->table(PrivacyState::TOMBSTONES)->useWritePdo()->where('org_id', $order->org_id)
            ->where('custody_digest', PrivacyState::guardDigest('custody', $order->org_id."\0".$order->site_id."\0".$order->id))
            ->orderBy('id')->limit(4501)->get();
        if ($guards->isEmpty() || $guards->count() > 4500) {
            throw new RetentionRefused;
        }
        foreach ($guards as $guard) {
            PrivacyState::guard($guard);
            if ($guard->purged_at === null) {
                throw new RetentionRefused;
            }
        }

        return CheckoutAttempts::digest(['guards' => $guards->map(get_object_vars(...))->all()]);
    }

    /** @param array<string,mixed> $changes */
    private function updateChild(Connection $connection, string $table, object $order, object $row, array $changes): void
    {
        $this->state->updateRow($connection, $table, $order, $row, $changes);
    }

    private function fresh(Connection $connection, object $order): object
    {
        return $connection->table('commerce_orders')->useWritePdo()->where('id', $order->id)->where('org_id', $order->org_id)
            ->where('site_id', $order->site_id)->lockForUpdate()->first() ?? throw new RetentionRefused;
    }

    /** @template T
     * @param  Closure(Connection,object):T  $work
     * @return T
     */
    private function owner(string $reference, Closure $work): mixed
    {
        $authority = $this->authority(true);

        return $this->scoped($reference, function (Connection $connection, object $order) use ($authority, $work) {
            $this->assertAuthority($authority, true);

            return $work($connection, $order);
        });
    }

    /** @template T
     * @param  Closure(Connection,object):T  $work
     * @return T
     */
    private function scoped(string $reference, Closure $work, ?int $siteId = null): mixed
    {
        return $this->safe(function () use ($reference, $work, $siteId) {
            $this->reference($reference);
            $org = $this->context->orgId() ?? throw new RetentionRefused;
            $site = $siteId ?? $this->context->siteId() ?? throw new RetentionRefused;
            $authority = $this->authority(false);
            $connection = (new Order)->getConnection();

            return TransactionRecovery::run($connection, function () use ($reference, $work, $org, $site, $authority, $connection) {
                if ($connection->getDriverName() === 'sqlite') {
                    $connection->table('orgs')->where('id', $org)->update(['id' => $org]);
                }
                $connection->table('orgs')->useWritePdo()->where('id', $org)->lockForUpdate()->first() ?? throw new RetentionRefused;
                $connection->table('sites')->useWritePdo()->where('id', $site)->where('org_id', $org)->lockForUpdate()->first() ?? throw new RetentionRefused;
                $order = $connection->table('commerce_orders')->useWritePdo()->where('org_id', $org)->where('site_id', $site)->where('reference', $reference)
                    ->lockForUpdate()->first() ?? throw new RetentionRefused;
                $native = $connection->getPdo();
                $result = $work($connection, $order);
                if ($connection->getPdo() !== $native) {
                    throw new RetentionRefused;
                }
                $proof = $this->finalProof($connection, $order);
                $this->assertAuthority($authority, false);
                if ($connection->getPdo() !== $native || $proof !== $this->finalProof($connection, $order)) {
                    throw new RetentionRefused;
                }

                return $result;
            });
        });
    }

    /** @return array{org:int,site:?int,type:?string,id:?string} */
    private function authority(bool $ownerOnly): array
    {
        Permissions::forget();
        if (app(ReaderGuard::class)->signedIn()) {
            throw new RetentionRefused;
        }
        $org = $this->context->orgId() ?? throw new RetentionRefused;
        $actor = Permissions::currentUser();
        if ($actor === null) {
            if ($ownerOnly || ! app()->runningInConsole()) {
                throw new RetentionRefused;
            }

            return ['org' => $org, 'site' => $this->context->siteId(), 'type' => null, 'id' => null];
        }
        if (! Permissions::isOwner($actor)) {
            throw new RetentionRefused;
        }
        $id = $actor->getAuthIdentifier();
        if (! is_int($id) && ! is_string($id)) {
            throw new RetentionRefused;
        }

        return ['org' => $org, 'site' => $this->context->siteId(), 'type' => $actor instanceof Model ? $actor->getMorphClass() : $actor::class, 'id' => (string) $id];
    }

    /** @param array{org:int,site:?int,type:?string,id:?string} $expected */
    private function assertAuthority(array $expected, bool $ownerOnly): void
    {
        if ($this->authority($ownerOnly) !== $expected) {
            throw new RetentionRefused;
        }
    }

    /** @param array{org:int,site:?int,type:?string,id:?string} $identity
     * @return array{audit:AuditLog,expected:array<string,mixed>}
     */
    private function audit(Connection $connection, object $order, string $action, array $identity): array
    {
        $target = new Order;
        $expected = ['org_id' => $identity['org'], 'site_id' => $identity['site'], 'actor_type' => $identity['type'], 'actor_id' => $identity['id'],
            'action' => $action, 'target_type' => $target->getMorphClass(), 'target_id' => (string) $order->id];
        if ((new AuditLog)->getConnection() !== $connection) {
            throw new RetentionRefused;
        }
        $audit = $this->auditor->recordOrFail($action, $target->newFromBuilder(get_object_vars($order)));

        return ['audit' => $audit, 'expected' => $expected];
    }

    /** @param array{audit:AuditLog,expected:array<string,mixed>} $record
     * @param  array{org:int,site:?int,type:?string,id:?string}  $identity
     */
    private function assertAudit(Connection $connection, array $record, array $identity): void
    {
        $audit = $record['audit'];
        if (! $audit->exists || $audit->getConnection() !== $connection || $audit->getTable() !== AuditLog::TABLE || $audit->getKeyName() !== 'id') {
            throw new RetentionRefused;
        }
        $row = $connection->table(AuditLog::TABLE)->useWritePdo()->where('id', $audit->getKey())->lockForUpdate()->first() ?? throw new RetentionRefused;
        foreach ($record['expected'] as $column => $expected) {
            $actual = in_array($column, ['org_id', 'site_id'], true) && $row->{$column} !== null ? (int) $row->{$column} : $row->{$column};
            if ($actual !== $expected) {
                throw new RetentionRefused;
            }
        }
    }

    /** @phpstan-impure */
    private function verificationContext(Connection $connection, int $org): void
    {
        if ($connection !== (new Order)->getConnection() || $connection->transactionLevel() < 1 || ! $connection->getPdo()->inTransaction()
            || $this->context->orgId() !== $org) {
            throw new RetentionRefused;
        }
    }

    private function confirmation(string $reason, bool $confirmed): void
    {
        PrivacyState::text($reason);
        if (! $confirmed) {
            throw new RetentionRefused;
        }
    }

    private function reviewDate(string $date): string
    {
        if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $date) !== 1) {
            throw new RetentionRefused;
        }
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
        if ($parsed->format('Y-m-d') !== $date || $parsed->format('Y') < '0001' || $parsed->format('Y-m-d') < CarbonImmutable::now('UTC')->format('Y-m-d')) {
            throw new RetentionRefused;
        }

        return $parsed->format('Y-m-d H:i:s');
    }

    private function reference(string $reference): void
    {
        if (! PrivacyState::hex($reference, 32)) {
            throw new RetentionRefused;
        }
    }

    /** @template T
     * @param  Closure():T  $work
     * @return T
     */
    private function safe(Closure $work): mixed
    {
        try {
            return $work();
        } catch (Throwable) {
            throw new RetentionRefused;
        }
    }
}
