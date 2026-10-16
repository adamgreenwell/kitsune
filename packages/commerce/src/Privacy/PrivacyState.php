<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Privacy;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Kitsune\Commerce\Models\Order;
use Kitsune\Commerce\Orders\BillingDetails;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Commerce\Payments\AccessFulfillment;
use Kitsune\Commerce\Payments\CheckoutAttempts;
use Kitsune\Commerce\Payments\CheckoutReview;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Core\Auth\EmailAddress;
use Kitsune\Core\Tenancy\Context;
use Throwable;

/** Exact privacy representations bridge preserved original commitments, never replacement purchase history. @internal */
final class PrivacyState
{
    public const TABLE = 'commerce_order_privacy';

    public const TRANSITIONS = 'commerce_privacy_transitions';

    public const DELIVERIES = 'commerce_privacy_deliveries';

    public const HOLDS = 'commerce_privacy_holds';

    public const TOMBSTONES = 'commerce_privacy_tombstones';

    public const MIGRATION = '0001_01_01_000218_create_commerce_order_privacy';

    public const MAX_HOLDS = 100;

    public const MAX_TRANSITIONS = 1000;

    private const PII_ORDER = ['reader_id', 'owner_key', 'contact_email', 'billing', 'delivery', 'shipping', 'cart_ref', 'cart_version', 'active_cart_key'];

    private const COMMITMENTS = ['version', 'snapshot_hash', 'checkout_hash', 'financial_hash', 'financial_version', 'access_manifest_hash', 'claim_hash', 'adjustment_hash'];

    public function __construct(private readonly Context $context) {}

    public function schemaAvailable(Connection $connection): bool
    {
        $tables = [self::TABLE => ['id', 'org_id', 'site_id', 'order_id', 'order_reference', 'policy_snapshot', 'policy_hash',
            'confirmed_at', 'retained_until', 'subject_erased_at', 'invoice_expired_at', 'subject_key', 'commitments', 'commitments_hash',
            'order_representation_hash', 'safe_financial_hash', 'access_representation_hash', 'adjustment_representation_hash', 'revision', 'maintenance_count', 'guard_keys', 'guard_set_hash', 'retained_billing_fields', 'created_at', 'updated_at'],
            self::TRANSITIONS => ['id', 'org_id', 'site_id', 'order_id', 'work_id', 'reference', 'action', 'reason', 'policy_before_hash', 'policy_after_hash', 'revision', 'before_hash', 'after_hash', 'created_at'],
            self::DELIVERIES => ['id', 'org_id', 'site_id', 'order_id', 'work_id', 'state', 'payload', 'payload_hash', 'reason', 'review_at', 'completed_at', 'created_at', 'updated_at'],
            self::HOLDS => ['id', 'org_id', 'site_id', 'order_id', 'work_id', 'reference', 'reason', 'review_at', 'released_at', 'release_reason', 'created_at'],
            self::TOMBSTONES => ['id', 'org_id', 'mode', 'kind', 'digest', 'account_digest', 'custody_digest', 'subject_erased_at', 'invoice_expired_at', 'purged_at', 'commitments', 'created_at', 'updated_at']];
        $present = array_filter(array_keys($tables), $connection->getSchemaBuilder()->hasTable(...));
        if ($present === [] && ! $connection->table('migrations')->useWritePdo()->where('migration', self::MIGRATION)->exists()) {
            return false;
        }
        foreach ($tables as $table => $columns) {
            if (! $connection->getSchemaBuilder()->hasColumns($table, $columns)) {
                throw new RetentionRefused;
            }
        }

        return true;
    }

    public function prepare(Connection $connection, object $order): void
    {
        $this->inside($connection, $order);
        if (! $this->schemaAvailable($connection)) {
            return;
        }
        if ($connection->table(self::TABLE)->where('order_id', $order->id)->exists()) {
            throw new RetentionRefused;
        }
        $policy = app(RetentionPolicies::class)->current($connection, (int) $order->org_id, true);
        $connection->table(self::TABLE)->insert(['org_id' => $order->org_id, 'site_id' => $order->site_id,
            'order_id' => $order->id, 'order_reference' => $order->reference,
            'policy_snapshot' => $policy === null ? null : self::encode($policy->snapshot()), 'policy_hash' => $policy?->configHash,
            'created_at' => self::now(), 'updated_at' => self::now()]);
        $this->checked($connection, $order, true);
    }

    /** The only sanctioned first-paid change to an already minimized pending order. No old PII is reconstructed. */
    public function paidTransition(Connection $connection, object $before, object $paid): void
    {
        $this->inside($connection, $paid);
        if (! $this->schemaAvailable($connection)) {
            return;
        }
        $work = $connection->table(self::TABLE)->useWritePdo()->where('order_id', $paid->id)->lockForUpdate()->sole();
        self::child($work, $paid);
        if ($work->subject_erased_at === null && $work->invoice_expired_at === null) {
            $this->checked($connection, $paid, true);

            return;
        }
        $expected = (object) [...get_object_vars($before), 'state' => 'paid', 'confirmed_total_minor' => $paid->quoted_total_minor, 'active_cart_key' => null];
        if ($before->state === 'paid' || $before->confirmed_total_minor !== null || $work->confirmed_at !== null
            || $work->order_representation_hash !== self::orderDigest($before) || self::orderDigest($expected) !== self::orderDigest($paid)) {
            throw new RetentionRefused;
        }
        $commitments = self::decode($work->commitments);
        self::commitments($commitments);
        if ($commitments['financial_hash'] !== null) {
            throw new RetentionRefused;
        }
        $safe = self::safeFinancialDigest($connection, $paid);
        $commitments['financial_version'] = 2;
        $commitments['financial_hash'] = CheckoutAttempts::digest(['version' => 2, 'original_snapshot_hash' => $commitments['snapshot_hash'],
            'original_checkout_hash' => $commitments['checkout_hash'], 'safe_financial_hash' => $safe]);
        $receipt = $connection->table(PaidOrders::RECEIPTS)->useWritePdo()->where('order_id', $paid->id)->sole();
        $policy = RetentionPolicySnapshot::fromSnapshot(self::decode($work->policy_snapshot));
        $this->update($connection, $paid, $work, ['commitments' => self::encode($commitments), 'commitments_hash' => CheckoutAttempts::digest($commitments),
            'order_representation_hash' => self::orderDigest($paid), 'safe_financial_hash' => $safe,
            'confirmed_at' => $receipt->verified_at, 'retained_until' => $policy->deadline(CheckoutAttempts::instant($receipt->verified_at))->format('Y-m-d H:i:s')]);
        $freshWork = $connection->table(self::TABLE)->useWritePdo()->where('order_id', $paid->id)->sole();
        $this->rememberRows($connection, $paid, ['work' => $freshWork, 'commitments' => $commitments], false);
        $this->checked($connection, $paid, true);
    }

    /** First durable confirmation fixes the deadline from the policy frozen at preparation, never current activation. */
    public function freezePaid(Connection $connection, object $order): void
    {
        $this->inside($connection, $order);
        $state = $this->checked($connection, $order, true);
        if ($state === null) {
            return;
        }
        $work = $state['work'];
        $receipt = $connection->table(PaidOrders::RECEIPTS)->useWritePdo()->where('order_id', $order->id)->sole();
        if ($order->state !== 'paid' || ($work->confirmed_at !== null && $work->confirmed_at !== $receipt->verified_at)) {
            throw new RetentionRefused;
        }
        $attempt = $connection->table(CheckoutAttempts::TABLE)->useWritePdo()->where('id', $receipt->attempt_id)->sole();
        $access = $connection->table(AccessFulfillment::WORK)->useWritePdo()->where('order_id', $order->id)->sole();
        $claim = $connection->table('commerce_order_claims')->useWritePdo()->where('order_id', $order->id)->first();
        $commitments = $state['commitments'] ?? ['version' => 1, 'snapshot_hash' => $order->snapshot_hash,
            'checkout_hash' => $attempt->order_hash, 'financial_hash' => CheckoutAttempts::digest(self::financialBasis($connection, $order)),
            'financial_version' => 1, 'access_manifest_hash' => null, 'claim_hash' => null, 'adjustment_hash' => null];
        if ($commitments['checkout_hash'] !== $attempt->order_hash) {
            throw new RetentionRefused;
        }
        $commitments['access_manifest_hash'] ??= $access->manifest_hash;
        $commitments['claim_hash'] ??= $claim === null ? null : CheckoutAttempts::digest(get_object_vars($claim));
        self::commitments($commitments);
        $changes = ['confirmed_at' => $receipt->verified_at,
            'retained_until' => $state['policy']?->deadline(CheckoutAttempts::instant($receipt->verified_at))->format('Y-m-d H:i:s'),
            'commitments' => self::encode($commitments), 'commitments_hash' => CheckoutAttempts::digest($commitments),
            'safe_financial_hash' => self::safeFinancialDigest($connection, $order)];
        if ($state['subject_erased'] && $access->manifest !== null) {
            $changes['access_representation_hash'] = CheckoutAttempts::digest(self::decode($access->manifest));
        }
        $this->update($connection, $order, $work, $changes);
        $this->ensureDelivery($connection, $order, $work);
        if ($state['subject_erased'] || $state['invoice_expired']) {
            $freshWork = $connection->table(self::TABLE)->useWritePdo()->where('order_id', $order->id)->sole();
            $this->rememberRows($connection, $order, ['work' => $freshWork, 'commitments' => $commitments], false);
        }
        $this->checked($connection, $order, true);
    }

    /** Capture only hashes while the original graph still exists, then physically remove unwanted fields.
     * @param  array<string,mixed>  $record
     * @return array<string,mixed>
     */
    public function captureOriginal(Connection $connection, object $order, array $record): array
    {
        $this->inside($connection, $order);
        $state = $this->checked($connection, $order, true) ?? throw new RetentionRefused;
        if ($state['subject_erased']) {
            return $state['commitments'];
        }
        if ($state['invoice_expired']) {
            $commitments = $state['commitments'];
            $claim = $connection->table('commerce_order_claims')->useWritePdo()->where('order_id', $order->id)->first();
            $commitments['claim_hash'] ??= $claim === null ? null : CheckoutAttempts::digest(get_object_vars($claim));
            $commitments['adjustment_hash'] ??= self::adjustmentDigest($connection, $order);

            return $commitments;
        }
        $checkoutHash = CheckoutReview::unmodifiedOrderHash($record);
        $attempts = $this->children($connection, CheckoutAttempts::TABLE, $order, CheckoutAttempts::MAX_ATTEMPTS, true);
        foreach ($attempts as $attempt) {
            if ($attempt->order_hash !== $checkoutHash) {
                throw new RetentionRefused;
            }
        }
        $receipt = $connection->table(PaidOrders::RECEIPTS)->useWritePdo()->where('order_id', $order->id)->first();
        $access = $connection->table(AccessFulfillment::WORK)->useWritePdo()->where('order_id', $order->id)->sole();
        $claim = $connection->table('commerce_order_claims')->useWritePdo()->where('order_id', $order->id)->first();
        $commitments = ['version' => 1, 'snapshot_hash' => $order->snapshot_hash, 'checkout_hash' => $checkoutHash,
            'financial_hash' => $receipt === null ? null : CheckoutAttempts::digest(self::financialBasis($connection, $order)),
            'financial_version' => $receipt === null ? 2 : 1, 'access_manifest_hash' => $access->manifest_hash,
            'claim_hash' => $claim === null ? null : CheckoutAttempts::digest(get_object_vars($claim)),
            'adjustment_hash' => self::adjustmentDigest($connection, $order)];
        if ($state['commitments'] !== null) {
            foreach (['snapshot_hash', 'checkout_hash', 'financial_hash', 'financial_version', 'access_manifest_hash'] as $key) {
                if ($state['commitments'][$key] !== $commitments[$key]) {
                    throw new RetentionRefused;
                }
            }
        }
        $financial = $connection->table('commerce_financial_work')->useWritePdo()->where('order_id', $order->id)->sole();
        if ($financial->basis_hash !== null && $financial->basis_hash !== $commitments['financial_hash']) {
            throw new RetentionRefused;
        }

        return $commitments;
    }

    /** A review date is a reminder, never inferred delivery or an automatic retention expiry. */
    public function ensureDelivery(Connection $connection, object $order, object $work): void
    {
        $this->inside($connection, $order);
        if (! $connection->table('commerce_order_lines')->useWritePdo()->where('order_id', $order->id)->where('delivery_kind', 'physical')->exists()
            || $connection->table(self::DELIVERIES)->useWritePdo()->where('order_id', $order->id)->exists()) {
            return;
        }
        $connection->table(self::DELIVERIES)->insert(['org_id' => $order->org_id, 'site_id' => $order->site_id, 'order_id' => $order->id,
            'work_id' => $work->id, 'state' => 'pending', 'payload' => null, 'payload_hash' => null, 'reason' => 'pending_delivery',
            'review_at' => CarbonImmutable::now('UTC')->addDays(30)->startOfDay()->format('Y-m-d H:i:s'),
            'completed_at' => null, 'created_at' => self::now(), 'updated_at' => self::now()]);
    }

    /** Raw, nonrecursive custody: no reader projection or financial/access writer is invoked here.
     * @return array{work:object,policy:?RetentionPolicySnapshot,deadline:?string,subject_erased:bool,invoice_expired:bool,purged:bool,commitments:?array<string,mixed>,delivery:?object,holds:list<object>}|null
     */
    public function checked(Connection $connection, object $order, bool $lock = false): ?array
    {
        if (! $this->schemaAvailable($connection)) {
            return null;
        }
        if ($connection !== (new Order)->getConnection() || ($lock && ($connection->transactionLevel() < 1 || ! $connection->getPdo()->inTransaction()))
            || $this->context->orgId() !== (int) $order->org_id
            || ! $connection->table('sites')->useWritePdo()->where('id', $order->site_id)->where('org_id', $order->org_id)->exists()) {
            throw new RetentionRefused;
        }
        $actual = $connection->table('commerce_orders')->useWritePdo()->where('id', $order->id)
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if ($actual === null || self::orderDigest($actual) !== self::orderDigest($order)) {
            throw new RetentionRefused;
        }
        $rows = $connection->table(self::TABLE)->useWritePdo()->where('order_id', $order->id)->orderBy('id')->limit(2)
            ->when($lock, fn ($query) => $query->lockForUpdate())->get();
        if ($rows->count() !== 1) {
            throw new RetentionRefused;
        }
        $work = $rows->sole();
        self::child($work, $order);
        if ($work->order_reference !== $order->reference || ! self::hex($order->reference, 32)
            || self::counter($work->revision) > self::MAX_TRANSITIONS) {
            throw new RetentionRefused;
        }
        foreach (['created_at', 'updated_at', 'confirmed_at', 'retained_until', 'subject_erased_at', 'invoice_expired_at'] as $column) {
            if ($work->{$column} !== null) {
                CheckoutAttempts::instant($work->{$column});
            }
        }
        self::counter($work->maintenance_count);
        $policy = $work->policy_snapshot === null ? null : RetentionPolicySnapshot::fromSnapshot(self::decode($work->policy_snapshot));
        if (($policy === null) !== ($work->policy_hash === null)
            || ($policy !== null && ($policy->orgId !== (int) $order->org_id || $policy->configHash !== $work->policy_hash))
            || ($work->retained_until !== null && ($policy === null || $work->confirmed_at === null
                || $policy->deadline(CheckoutAttempts::instant($work->confirmed_at))->format('Y-m-d H:i:s') !== $work->retained_until))) {
            throw new RetentionRefused;
        }
        $commitments = $work->commitments === null ? null : self::decode($work->commitments);
        if (($commitments === null) !== ($work->commitments_hash === null)) {
            throw new RetentionRefused;
        }
        if ($commitments !== null) {
            self::commitments($commitments);
            if (CheckoutAttempts::digest($commitments) !== $work->commitments_hash || $commitments['snapshot_hash'] !== $order->snapshot_hash) {
                throw new RetentionRefused;
            }
        }
        $erased = $work->subject_erased_at !== null;
        $expired = $work->invoice_expired_at !== null;
        if ($erased || $expired) {
            if ($policy === null || $commitments === null || ! self::hex($work->order_representation_hash, 64)
                || ! hash_equals($work->order_representation_hash, self::orderDigest($order))
                || $order->contact_email !== null || $order->delivery !== null
                || ($expired && $order->billing !== null)
                || ($erased && ($order->reader_id !== null || $order->cart_ref !== null || $order->cart_version !== null
                    || $order->active_cart_key !== null || ! self::hex($order->owner_key, 64) || ! self::hex($work->subject_key, 64)))) {
                throw new RetentionRefused;
            }
            $retained = self::decode($work->retained_billing_fields)['fields'] ?? null;
            if (! is_array($retained) || ! array_is_list($retained) || array_diff($retained, $policy->billingFields) !== []
                || count(array_unique($retained)) !== count($retained) || ($expired && $retained !== [])) {
                throw new RetentionRefused;
            }
            self::partialBilling($order->billing, $retained);
            if ($connection->table('commerce_order_claims')->useWritePdo()->where('order_id', $order->id)->exists() && $erased) {
                throw new RetentionRefused;
            }
        } elseif ($work->subject_key !== null || $work->order_representation_hash !== null) {
            throw new RetentionRefused;
        }
        if ($commitments !== null && $commitments['financial_hash'] !== null) {
            if (! self::hex($work->safe_financial_hash, 64) || $work->safe_financial_hash !== self::safeFinancialDigest($connection, $order)) {
                throw new RetentionRefused;
            }
        }
        if ($erased && $work->adjustment_representation_hash !== self::adjustmentDigest($connection, $order)) {
            throw new RetentionRefused;
        }
        $deliveries = $this->children($connection, self::DELIVERIES, $order, 1, $lock);
        $delivery = $deliveries[0] ?? null;
        if ($delivery !== null) {
            if ((string) $delivery->work_id !== (string) $work->id || ! in_array($delivery->state, ['pending', 'complete'], true)
                || ($delivery->state === 'complete') !== ($delivery->completed_at !== null)
                || ($delivery->payload === null) !== ($delivery->payload_hash === null)) {
                throw new RetentionRefused;
            }
            self::text($delivery->reason, 500);
            CheckoutAttempts::instant($delivery->review_at);
            if ($delivery->completed_at !== null) {
                CheckoutAttempts::instant($delivery->completed_at);
                if ($delivery->payload !== null) {
                    throw new RetentionRefused;
                }
            }
            if ($delivery->payload !== null) {
                $payload = self::decode($delivery->payload);
                if (count($payload) !== 2 || array_diff(array_keys($payload), ['contact_email', 'delivery']) !== [] || ! is_string($payload['contact_email'])
                    || CheckoutAttempts::digest($payload) !== $delivery->payload_hash) {
                    throw new RetentionRefused;
                }
                EmailAddress::normalise($payload['contact_email']) === $payload['contact_email'] || throw new RetentionRefused;
                BillingDetails::from($payload['delivery']);
            }
        }
        $holds = $this->children($connection, self::HOLDS, $order, self::MAX_HOLDS, $lock);
        foreach ($holds as $hold) {
            if ((string) $hold->work_id !== (string) $work->id || ! self::hex($hold->reference, 32)
                || ($hold->released_at === null) !== ($hold->release_reason === null)) {
                throw new RetentionRefused;
            }
            self::text($hold->reason, 500);
            CheckoutAttempts::instant($hold->review_at);
            if ($hold->released_at !== null) {
                CheckoutAttempts::instant($hold->released_at);
                self::text($hold->release_reason, 500);
            }
        }

        $transitions = $this->children($connection, self::TRANSITIONS, $order, self::MAX_TRANSITIONS, $lock);
        if (count($transitions) !== self::counter($work->revision)) {
            throw new RetentionRefused;
        }
        foreach ($transitions as $index => $transition) {
            if ((string) $transition->work_id !== (string) $work->id || self::counter($transition->revision) !== $index + 1
                || ! self::hex($transition->reference, 32) || ! self::hex($transition->before_hash, 64) || ! self::hex($transition->after_hash, 64)
                || ! in_array($transition->action, ['policy_adopted', 'subject_erased', 'invoice_expired', 'hold_added', 'hold_released', 'delivery_complete'], true)) {
                throw new RetentionRefused;
            }
            if ($transition->reason !== null) {
                self::text($transition->reason);
            }
            foreach (['policy_before_hash', 'policy_after_hash'] as $key) {
                if ($transition->{$key} !== null && ! self::hex($transition->{$key}, 64)) {
                    throw new RetentionRefused;
                }
            }
            CheckoutAttempts::instant($transition->created_at);
        }

        if ($erased || $expired) {
            if ($work->guard_set_hash !== $this->guardFrameHash($connection, $order, $work)) {
                throw new RetentionRefused;
            }
        } elseif ($work->guard_keys !== null || $work->guard_set_hash !== null) {
            throw new RetentionRefused;
        }

        return ['work' => $work, 'policy' => $policy, 'deadline' => $work->retained_until,
            'subject_erased' => $erased, 'invoice_expired' => $expired, 'purged' => false,
            'commitments' => $commitments, 'delivery' => $delivery, 'holds' => $holds];
    }

    /** @return array{privacy:?array<string,mixed>} */
    public function projection(Connection $connection, object $order, bool $lock = false): array
    {
        $state = $this->checked($connection, $order, $lock);

        return ['privacy' => $state === null ? null : ['subject_erased' => $state['subject_erased'], 'invoice_expired' => $state['invoice_expired'],
            'retained_until' => $state['deadline'], 'policy_version' => $state['policy']?->version,
            'delivery_pending' => $state['delivery']?->state === 'pending',
            'legal_hold' => array_filter($state['holds'], static fn (object $hold): bool => $hold->released_at === null) !== [],
            'minimized_at' => $state['work']->subject_erased_at ?? $state['work']->invoice_expired_at]];
    }

    /** @return array<string,mixed>|null */
    public function billing(Connection $connection, object $order): ?array
    {
        $state = $this->checked($connection, $order);
        if ($state !== null && ($state['subject_erased'] || $state['invoice_expired'])) {
            return self::partialBilling($order->billing, self::decode($state['work']->retained_billing_fields)['fields']);
        }

        return $order->billing === null ? null : BillingDetails::from(self::decode($order->billing));
    }

    public function assertAccessAllowed(Connection $connection, object $order): void
    {
        $state = $this->checked($connection, $order);
        if ($state !== null && $state['subject_erased']) {
            throw new RetentionRefused;
        }
    }

    /** @param array<string,mixed> $basis */
    public function originalFinancialHash(Connection $connection, object $order, array $basis): string
    {
        $state = $this->checked($connection, $order);
        if (CheckoutAttempts::digest(array_diff_key($basis, ['checkout' => true])) !== CheckoutAttempts::digest(self::financialBasis($connection, $order))) {
            throw new RetentionRefused;
        }
        if ($state === null || (! $state['subject_erased'] && ! $state['invoice_expired'])) {
            return CheckoutAttempts::digest(array_diff_key($basis, ['checkout' => true]));
        }
        if ($state['commitments']['financial_hash'] === null) {
            if ($order->state === 'paid' || $connection->table(PaidOrders::RECEIPTS)->useWritePdo()->where('order_id', $order->id)->exists()) {
                throw new RetentionRefused;
            }

            return CheckoutAttempts::digest(['version' => 2, 'original_snapshot_hash' => $state['commitments']['snapshot_hash'],
                'original_checkout_hash' => $state['commitments']['checkout_hash'], 'safe_financial_hash' => self::safeFinancialDigest($connection, $order)]);
        }

        return $state['commitments']['financial_hash'];
    }

    /** @param array<string,mixed> $record */
    public function originalCheckoutHash(Connection $connection, object $order, array $record): string
    {
        $state = $this->checked($connection, $order);
        if ($state === null || (! $state['subject_erased'] && ! $state['invoice_expired'])) {
            return CheckoutReview::unmodifiedOrderHash($record);
        }
        if (! self::hex($state['commitments']['checkout_hash'], 64)) {
            throw new RetentionRefused;
        }

        if (CheckoutReview::unmodifiedOrderHash($record) !== CheckoutReview::unmodifiedOrderHash(app(OrderReaderData::class)->record($connection, $order))) {
            throw new RetentionRefused;
        }

        return $state['commitments']['checkout_hash'];
    }

    /** @param array<string,mixed> $candidate */
    public function originalAccessManifestHash(Connection $connection, object $order, array $candidate): string
    {
        $state = $this->checked($connection, $order);
        if ($state === null || ! $state['subject_erased']) {
            return CheckoutAttempts::digest($candidate);
        }
        $hash = CheckoutAttempts::digest($candidate);
        if ($state['work']->access_representation_hash !== $hash || ! self::hex($state['commitments']['access_manifest_hash'], 64)) {
            throw new RetentionRefused;
        }

        return $state['commitments']['access_manifest_hash'];
    }

    /** @param array<string,mixed>|null $candidate */
    public function originalClaimHash(Connection $connection, object $order, ?array $candidate): ?string
    {
        $state = $this->checked($connection, $order);
        if ($state !== null && $state['subject_erased']) {
            if ($candidate !== null) {
                throw new RetentionRefused;
            }

            return $state['commitments']['claim_hash'];
        }

        return $candidate === null ? null : CheckoutAttempts::digest($candidate);
    }

    /** @return array{subject_erased:bool,invoice_expired:bool,purged:bool,account_unknown:bool}|null */
    public function replayState(Connection $connection, int $org, string $mode, string $chargeId, ?string $verifiedAccountId = null): ?array
    {
        return $this->replayIdentity($connection, $org, $mode, 'charge', $chargeId, $verifiedAccountId);
    }

    /** Unknown ingress account can suppress raw bytes/quarantine, never identify an account or order.
     * @return array{subject_erased:bool,invoice_expired:bool,purged:bool,account_unknown:bool}|null
     */
    public function replayIdentity(Connection $connection, int $org, string $mode, string $kind, string $value, ?string $verifiedAccountId = null): ?array
    {
        if (! $this->schemaAvailable($connection)) {
            return null;
        }
        if ($this->context->orgId() !== $org || ! in_array($mode, ['test', 'live'], true)
            || ! in_array($kind, ['order', 'attempt', 'session', 'refund', 'source', 'charge', 'subject'], true)
            || $value === '' || strlen($value) > 255
            || ($verifiedAccountId !== null && preg_match('/\Aacct_[A-Za-z0-9_]{1,95}\z/D', $verifiedAccountId) !== 1)) {
            throw new RetentionRefused;
        }
        $rows = $connection->table(self::TOMBSTONES)->useWritePdo()->where('org_id', $org)->where('mode', $mode)
            ->where('kind', $kind)->where('digest', self::guardDigest($kind, $value))
            ->when($verifiedAccountId !== null, fn ($query) => $query->where('account_digest', self::guardDigest('account', $verifiedAccountId)))
            ->limit(11)->get();
        if ($rows->count() > 10) {
            throw new RetentionRefused;
        }
        if ($rows->isEmpty()) {
            return null;
        }
        $result = ['subject_erased' => false, 'invoice_expired' => false, 'purged' => false, 'account_unknown' => $verifiedAccountId === null];
        foreach ($rows as $row) {
            self::guard($row);
            $result['subject_erased'] = $result['subject_erased'] || $row->subject_erased_at !== null;
            $result['invoice_expired'] = $result['invoice_expired'] || $row->invoice_expired_at !== null;
            $result['purged'] = $result['purged'] || $row->purged_at !== null;
        }

        return $result;
    }

    /** Remove only a never-minimized, wholly unpaid mandatory marker after the old terminal custody checks. */
    public function deleteUnpaid(Connection $connection, object $order): void
    {
        $this->inside($connection, $order);
        $state = $this->checked($connection, $order, true);
        if ($state === null) {
            return;
        }
        if ($order->state === 'paid' || $order->confirmed_total_minor !== null || $state['subject_erased'] || $state['invoice_expired']
            || $state['work']->confirmed_at !== null || $state['delivery'] !== null || $state['holds'] !== []
            || $connection->table(self::TRANSITIONS)->where('order_id', $order->id)->exists()) {
            throw new RetentionRefused;
        }
        if ($connection->table(self::TABLE)->where('id', $state['work']->id)->where('org_id', $order->org_id)->delete() !== 1) {
            throw new RetentionRefused;
        }
    }

    /** Seal an authorized redaction after its caller captured the original graph and applied the exact edits.
     * @param  array<string,mixed>  $commitments
     * @param  array<string,mixed>  $flags
     */
    public function seal(Connection $connection, object $order, array $commitments, array $flags): void
    {
        $this->inside($connection, $order);
        self::commitments($commitments);
        $work = $connection->table(self::TABLE)->useWritePdo()->where('order_id', $order->id)->lockForUpdate()->sole();
        self::child($work, $order);
        if (array_diff(array_keys($flags), ['subject_erased_at', 'invoice_expired_at', 'subject_key']) !== []) {
            throw new RetentionRefused;
        }
        if ($work->commitments !== null) {
            foreach (self::decode($work->commitments) as $key => $value) {
                if ($value !== null && $value !== $commitments[$key]) {
                    throw new RetentionRefused;
                }
            }
        }
        $safe = $commitments['financial_hash'] === null ? null : self::safeFinancialDigest($connection, $order);
        if ($work->safe_financial_hash !== null && $work->safe_financial_hash !== $safe) {
            throw new RetentionRefused;
        }
        $access = $connection->table(AccessFulfillment::WORK)->useWritePdo()->where('order_id', $order->id)->sole();
        $policy = RetentionPolicySnapshot::fromSnapshot(self::decode($work->policy_snapshot));
        $retained = $work->retained_billing_fields === null ? $policy->billingFields : self::decode($work->retained_billing_fields)['fields'];
        $retained = $order->state !== 'paid' || ($flags['invoice_expired_at'] ?? $work->invoice_expired_at) !== null ? [] : array_values(array_intersect($retained, $policy->billingFields));
        $this->update($connection, $order, $work, [...$flags, 'retained_billing_fields' => self::encode(['fields' => $retained]), 'commitments' => self::encode($commitments),
            'commitments_hash' => CheckoutAttempts::digest($commitments), 'safe_financial_hash' => $safe,
            'order_representation_hash' => self::orderDigest($order),
            'access_representation_hash' => $access->manifest === null ? null : CheckoutAttempts::digest(self::decode($access->manifest)),
            'adjustment_representation_hash' => ($flags['subject_erased_at'] ?? $work->subject_erased_at) === null
                ? null : self::adjustmentDigest($connection, $order)]);
        $freshWork = $connection->table(self::TABLE)->useWritePdo()->where('order_id', $order->id)->sole();
        $this->rememberRows($connection, $order, ['work' => $freshWork, 'commitments' => $commitments], false);
        $this->checked($connection, $order, true);
    }

    /** All original routing identities are opaque guards, independently bound to each original provider account. */
    public function remember(Connection $connection, object $order, bool $purged = false): void
    {
        $this->inside($connection, $order);
        $state = $this->checked($connection, $order, true) ?? throw new RetentionRefused;
        if ($state['commitments'] === null || (! $state['subject_erased'] && ! $state['invoice_expired'] && ! $purged)) {
            throw new RetentionRefused;
        }
        $this->rememberRows($connection, $order, $state, $purged);
    }

    /** @param array<string,mixed> $state */
    private function rememberRows(Connection $connection, object $order, array $state, bool $purged): void
    {
        $keys = [];
        $identities = [['order', $order->reference], ['source', 'commerce.order:'.$order->id]];
        $accounts = [];
        foreach ($this->children($connection, CheckoutAttempts::TABLE, $order, 100, true) as $attempt) {
            $accounts[$attempt->account_id] = true;
            $identities[] = ['attempt', $attempt->reference];
            if ($attempt->session_id !== null) {
                $identities[] = ['session', $attempt->session_id];
            }
        }
        $receipt = $connection->table(PaidOrders::RECEIPTS)->useWritePdo()->where('order_id', $order->id)->first();
        if ($receipt !== null) {
            self::child($receipt, $order);
            $accounts[$receipt->account_id] = true;
            $identities[] = ['charge', $receipt->charge_id];
        }
        foreach ([PaidOrders::ATTEMPTS => 10, 'commerce_financial_refunds' => 100] as $table => $limit) {
            foreach ($this->children($connection, $table, $order, $limit, true) as $refund) {
                $identities[] = ['refund', $refund->reference];
                if ($refund->refund_id !== null) {
                    $identities[] = ['refund', $refund->refund_id];
                }
            }
        }
        // A never-submitted pending marker has no account to invent. Its guard can only quarantine unknown ingress.
        if ($accounts === []) {
            $accounts[''] = true;
        }
        if (count($accounts) > 10 || count($identities) > 450) {
            throw new RetentionRefused;
        }
        foreach (array_keys($accounts) as $account) {
            if ($account !== '' && preg_match('/\Aacct_[A-Za-z0-9_]{1,95}\z/D', $account) !== 1) {
                throw new RetentionRefused;
            }
            foreach ($identities as [$kind, $value]) {
                $where = ['org_id' => $order->org_id, 'mode' => $order->mode, 'kind' => $kind,
                    'digest' => self::guardDigest($kind, $value), 'account_digest' => self::guardDigest('account', $account),
                    'custody_digest' => self::guardDigest('custody', $order->org_id."\0".$order->site_id."\0".$order->id)];
                $keys[CheckoutAttempts::digest($where)] = array_diff_key($where, ['org_id' => true]);
                $rows = $connection->table(self::TOMBSTONES)->useWritePdo()->where($where)->limit(2)->lockForUpdate()->get();
                if ($rows->count() > 1) {
                    throw new RetentionRefused;
                }
                $values = ['subject_erased_at' => $state['work']->subject_erased_at, 'invoice_expired_at' => $state['work']->invoice_expired_at,
                    'purged_at' => $purged ? self::now() : null, 'commitments' => self::encode($state['commitments']), 'updated_at' => self::now()];
                if ($rows->isEmpty()) {
                    $connection->table(self::TOMBSTONES)->insert([...$where, ...$values, 'created_at' => self::now()]);
                } else {
                    $old = $rows->sole();
                    self::guard($old);
                    $oldCommitments = self::decode($old->commitments);
                    foreach ($oldCommitments as $key => $value) {
                        if ($value !== null && $value !== $state['commitments'][$key]) {
                            throw new RetentionRefused;
                        }
                    }
                    foreach (['subject_erased_at', 'invoice_expired_at', 'purged_at'] as $key) {
                        $values[$key] = $old->{$key} ?? $values[$key];
                    }
                    $connection->table(self::TOMBSTONES)->where('id', $old->id)->where($where)->update($values);
                }
                $stored = $connection->table(self::TOMBSTONES)->useWritePdo()->where($where)->sole();
                self::guard($stored);
                if (CheckoutAttempts::digest(self::decode($stored->commitments)) !== CheckoutAttempts::digest($state['commitments'])) {
                    throw new RetentionRefused;
                }
            }
        }
        ksort($keys, SORT_STRING);
        $orderedKeys = [];
        foreach ($keys as $key) {
            $orderedKeys[] = $key;
        }
        $keys = $orderedKeys;
        $work = $connection->table(self::TABLE)->useWritePdo()->where('order_id', $order->id)->sole();
        $this->update($connection, $order, $work, ['guard_keys' => self::encode(['keys' => $keys])]);
        $work = $connection->table(self::TABLE)->useWritePdo()->where('order_id', $order->id)->sole();
        $this->update($connection, $order, $work, ['guard_set_hash' => $this->guardFrameHash($connection, $order, $work)]);
    }

    /** Complete generated negative guard custody, including every provider/account/source identity.
     * @return array<string,mixed>
     */
    public function guardFrame(Connection $connection, object $order, object $work): array
    {
        $payload = self::decode($work->guard_keys);
        $keys = $payload['keys'] ?? null;
        if (count($payload) !== 1 || ! is_array($keys) || ! array_is_list($keys) || $keys === [] || count($keys) > 4500) {
            throw new RetentionRefused;
        }
        $rows = [];
        foreach ($keys as $key) {
            if (! is_array($key) || count($key) !== 5 || array_diff(array_keys($key), ['mode', 'kind', 'digest', 'account_digest', 'custody_digest']) !== []) {
                throw new RetentionRefused;
            }
            $matches = $connection->table(self::TOMBSTONES)->useWritePdo()->where(['org_id' => $order->org_id, ...$key])->limit(2)->get();
            if ($matches->count() !== 1) {
                throw new RetentionRefused;
            }
            $row = $matches->sole();
            self::guard($row);
            if (($work->subject_erased_at !== null && $row->subject_erased_at !== $work->subject_erased_at)
                || ($work->invoice_expired_at !== null && $row->invoice_expired_at !== $work->invoice_expired_at)) {
                throw new RetentionRefused;
            }
            $frame = get_object_vars($row);
            $frame['commitments'] = self::decode($row->commitments);
            $rows[] = $frame;
        }

        return ['keys' => $keys, 'rows' => $rows];
    }

    private function guardFrameHash(Connection $connection, object $order, object $work): string
    {
        return CheckoutAttempts::digest($this->guardFrame($connection, $order, $work));
    }

    /** @return array<string,mixed> */
    public static function adjustmentFrame(Connection $connection, object $order): array
    {
        $work = $connection->table('commerce_access_adjustment_work')->useWritePdo()->where('order_id', $order->id)->sole();
        self::child($work, $order);
        $work = get_object_vars($work);
        unset($work['financial_digest'], $work['replay_count'], $work['updated_at']);
        $effects = $connection->table('commerce_access_adjustment_effects')->useWritePdo()->where('order_id', $order->id)->orderBy('id')->limit(21)->get();
        if ($effects->count() > 20) {
            throw new RetentionRefused;
        }
        $rows = [];
        foreach ($effects as $effect) {
            self::child($effect, $order);
            $row = get_object_vars($effect);
            if ($row['revocation_witness'] !== null) {
                $row['revocation_witness'] = self::decode($row['revocation_witness']);
            }
            $rows[] = $row;
        }

        return ['work' => $work, 'effects' => $rows];
    }

    public static function adjustmentDigest(Connection $connection, object $order): string
    {
        return CheckoutAttempts::digest(self::adjustmentFrame($connection, $order));
    }

    /** @return array<string,mixed> */
    public static function financialBasis(Connection $connection, object $order): array
    {
        $actual = $connection->table('commerce_orders')->useWritePdo()->where('id', $order->id)->where('org_id', $order->org_id)
            ->where('site_id', $order->site_id)->first() ?? throw new RetentionRefused;
        if (self::orderDigest($actual) !== self::orderDigest($order)) {
            throw new RetentionRefused;
        }
        $order = $actual;
        $receipts = $connection->table(PaidOrders::RECEIPTS)->useWritePdo()->where('order_id', $order->id)->limit(2)->get();
        if ($receipts->count() > 1) {
            throw new RetentionRefused;
        }
        $receipt = $receipts->first();
        $rows = [];
        foreach (['commerce_order_lines', PaidOrders::ALLOCATIONS] as $table) {
            $children = $connection->table($table)->useWritePdo()->where('order_id', $order->id)->orderBy('id')->limit(21)->get();
            if ($children->count() > 20) {
                throw new RetentionRefused;
            }
            foreach ($children as $child) {
                self::child($child, $order);
            }
            $rows[$table] = $children->map(static fn (object $row): array => get_object_vars($row))->all();
        }
        if ($receipt !== null) {
            self::child($receipt, $order);
        }

        return ['version' => 1, 'order' => get_object_vars($order), 'receipt' => $receipt === null ? null : get_object_vars($receipt),
            'lines' => $rows['commerce_order_lines'], 'allocations' => $rows[PaidOrders::ALLOCATIONS]];
    }

    public static function safeFinancialDigest(Connection $connection, object $order): string
    {
        $basis = self::financialBasis($connection, $order);
        $basis['order'] = array_diff_key(self::orderFrame($order), array_fill_keys(self::PII_ORDER, true));

        return CheckoutAttempts::digest($basis);
    }

    public static function orderDigest(object $order): string
    {
        return CheckoutAttempts::digest(self::orderFrame($order));
    }

    /** @return array<string,mixed> */
    private static function orderFrame(object $order): array
    {
        $frame = get_object_vars($order);
        foreach (['billing', 'delivery', 'shipping', 'site_snapshot'] as $key) {
            if (isset($frame[$key])) {
                $frame[$key] = self::decode($frame[$key]);
            }
        }
        foreach (['id', 'org_id', 'site_id', 'cart_ref', 'cart_version', 'quoted_item_net_minor', 'quoted_shipping_net_minor', 'quoted_tax_minor', 'quoted_total_minor', 'confirmed_total_minor'] as $key) {
            if (isset($frame[$key])) {
                if ((! is_int($frame[$key]) && ! is_string($frame[$key])) || preg_match('/\A[0-9]+\z/D', (string) $frame[$key]) !== 1) {
                    throw new RetentionRefused;
                }
                $frame[$key] = (string) $frame[$key];
            }
        }

        return $frame;
    }

    /** @param list<string> $fields
     * @return array<string,mixed>|null */
    public static function partialBilling(mixed $stored, array $fields): ?array
    {
        if ($fields === []) {
            if ($stored !== null) {
                throw new RetentionRefused;
            }

            return null;
        }
        $value = self::decode($stored);
        $complete = ['name' => 'Privacy', 'address' => ['line1' => 'Privacy', 'line2' => '', 'city' => '', 'region' => '', 'postal_code' => '', 'country' => 'US']];
        foreach ($fields as $field) {
            if ($field === 'name') {
                $complete['name'] = $value['name'] ?? null;
            } else {
                $part = substr($field, 8);
                $complete['address'][$part] = $value['address'][$part] ?? null;
            }
        }
        $canonical = BillingDetails::from($complete);
        $expected = self::selectedBilling($canonical, $fields);
        if (CheckoutAttempts::digest($value) !== CheckoutAttempts::digest($expected)) {
            throw new RetentionRefused;
        }

        return $expected;
    }

    /** @param array<string,mixed> $billing
     * @param list<string> $fields
     * @return array<string,mixed>|null */
    public static function selectedBilling(array $billing, array $fields): ?array
    {
        $selected = [];
        foreach ($fields as $field) {
            if ($field === 'name') {
                $selected['name'] = $billing['name'];
            } else {
                $part = substr($field, 8);
                $selected['address'][$part] = $billing['address'][$part];
            }
        }

        return $selected === [] ? null : $selected;
    }

    /** @return list<object> */
    public function children(Connection $connection, string $table, object $order, int $limit, bool $lock = false): array
    {
        $rows = $connection->table($table)->useWritePdo()->where('order_id', $order->id)->orderBy('id')->limit($limit + 1)
            ->when($lock, fn ($query) => $query->lockForUpdate())->get()->all();
        if (count($rows) > $limit) {
            throw new RetentionRefused;
        }
        foreach ($rows as $row) {
            self::child($row, $order);
        }

        return $rows;
    }

    public function inside(Connection $connection, object $order): void
    {
        if ($connection !== (new Order)->getConnection() || $connection->transactionLevel() < 1 || ! $connection->getPdo()->inTransaction()
            || $this->context->orgId() !== (int) $order->org_id) {
            throw new RetentionRefused;
        }
    }

    /** @param array<string,mixed> $changes */
    public function update(Connection $connection, object $order, object $work, array $changes): void
    {
        $this->inside($connection, $order);
        if ($connection->table(self::TABLE)->where('id', $work->id)->where('org_id', $order->org_id)->where('site_id', $order->site_id)
            ->where('order_id', $order->id)->update([...$changes, 'updated_at' => self::now()]) !== 1) {
            throw new RetentionRefused;
        }
    }

    public static function child(object $row, object $order): void
    {
        if (self::counter($row->id) < 1 || (string) $row->org_id !== (string) $order->org_id
            || (string) $row->site_id !== (string) $order->site_id || (string) $row->order_id !== (string) $order->id) {
            throw new RetentionRefused;
        }
    }

    /** @param array<string,mixed> $value */
    public static function encode(array $value): string
    {
        $encoded = json_encode($value, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 4194304) {
            throw new RetentionRefused;
        }

        return $encoded;
    }

    /** @return array<string,mixed> */
    public static function decode(mixed $value): array
    {
        try {
            if (! is_string($value) || strlen($value) > 4194304) {
                throw new RetentionRefused;
            }
            $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($decoded) || array_is_list($decoded)) {
                throw new RetentionRefused;
            }

            return $decoded;
        } catch (Throwable) {
            throw new RetentionRefused;
        }
    }

    /** @param array<string,mixed> $value */
    public static function commitments(array $value): void
    {
        if (count($value) !== count(self::COMMITMENTS) || array_diff(array_keys($value), self::COMMITMENTS) !== []
            || ($value['version'] ?? null) !== 1 || ! in_array($value['financial_version'] ?? null, [1, 2], true)
            || ! self::hex($value['snapshot_hash'] ?? null, 64)) {
            throw new RetentionRefused;
        }
        foreach (['checkout_hash', 'financial_hash', 'access_manifest_hash', 'claim_hash', 'adjustment_hash'] as $key) {
            if ($value[$key] !== null && ! self::hex($value[$key], 64)) {
                throw new RetentionRefused;
            }
        }
    }

    public static function guardDigest(string $kind, string $value): string
    {
        return hash('sha256', "kitsune.privacy.v1\0".$kind."\0".$value);
    }

    public static function guard(object $row): void
    {
        if (! in_array($row->mode, ['test', 'live'], true) || ! in_array($row->kind, ['order', 'attempt', 'session', 'refund', 'charge', 'source', 'subject'], true)
            || ! self::hex($row->digest, 64) || ! self::hex($row->account_digest, 64) || ! self::hex($row->custody_digest, 64)
            || ($row->subject_erased_at === null && $row->invoice_expired_at === null && $row->purged_at === null)) {
            throw new RetentionRefused;
        }
        foreach (['subject_erased_at', 'invoice_expired_at', 'purged_at', 'created_at', 'updated_at'] as $key) {
            if ($row->{$key} !== null) {
                CheckoutAttempts::instant($row->{$key});
            }
        }
        self::commitments(self::decode($row->commitments));
    }

    public static function counter(mixed $value): int
    {
        if ((! is_int($value) && ! is_string($value)) || preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', (string) $value) !== 1 || (float) $value > 4294967295) {
            throw new RetentionRefused;
        }

        return (int) $value;
    }

    public static function text(mixed $value, int $limit = 500): string
    {
        if (! is_string($value) || $value === '' || trim($value) !== $value || mb_strlen($value, 'UTF-8') > $limit
            || ! mb_check_encoding($value, 'UTF-8') || preg_match('/[\p{Cc}\x{2028}\x{2029}]/u', $value) === 1) {
            throw new RetentionRefused;
        }

        return $value;
    }

    public static function hex(mixed $value, int $length): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{'.$length.'}\z/D', $value) === 1;
    }

    public static function now(): string
    {
        return CarbonImmutable::now('UTC')->startOfSecond()->format('Y-m-d H:i:s');
    }
}
