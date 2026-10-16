<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Payments;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Kitsune\Commerce\Models\Order;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Commerce\Privacy\PrivacyState;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Tenancy\Concerns\ReadsWrittenKeys;
use Kitsune\Core\Tenancy\Context;
use stdClass;
use Throwable;

/** Verified, private org inbox. A routing hint is never authority; raw bytes are never a replay dependency. @internal */
final class StripeEventInbox
{
    use ReadsWrittenKeys;

    public const TABLE = 'commerce_stripe_events';

    public const ROUTING = 'commerce_checkout_routing';

    public const MAX_EVENTS_PER_ORDER = 1000;

    private const MIGRATION = '0001_01_01_000207_create_commerce_stripe_inbox';

    private const ROUTE_COLUMNS = ['id', 'org_id', 'site_id', 'order_id', 'attempt_id', 'order_reference', 'attempt_reference', 'account_id', 'mode', 'created_at'];

    // Deliberately never select raw bytes when projecting, processing, replaying or erasing a receipt.
    private const COLUMNS = ['id', 'org_id', 'site_id', 'order_id', 'attempt_id', 'event_id', 'event_type', 'api_version',
        'session_id', 'account_id', 'mode', 'order_reference', 'attempt_reference', 'payload_hash', 'state', 'replay_count', 'category',
        'lease_token', 'lease_until', 'verified_at', 'received_at', 'raw_expires_at', 'processed_at', 'updated_at', 'refund_reference', 'refund_id', 'financial_charge_id', 'financial_subject_id'];

    public function __construct(private readonly Context $context) {}

    /** The signature verifier owns ingress authority; this method independently proves the exact stored graph.
     * @param  array<string,mixed>  $event
     * @return array{id:int,duplicate:bool,state:string}
     */
    public function capture(int $orgId, array $event, #[\SensitiveParameter] string $raw, CarbonImmutable $verifiedAt): array
    {
        return $this->safe(function () use ($orgId, $event, $raw, $verifiedAt): array {
            $this->event($event);
            if (strlen($raw) < 2 || strlen($raw) > StripeCheckout::MAX_RESPONSE_BYTES || $this->context->orgId() !== $orgId
                || abs($verifiedAt->getTimestamp() - self::now()->getTimestamp()) > 300) {
                throw new CheckoutRefused;
            }
            $connection = (new Order)->getConnection();

            return $this->locked($connection, $orgId, fn (): array => $this->captureLocked($connection, $orgId, $event, $raw, $verifiedAt));
        });
    }

    /** @param array<string,mixed> $event
     * @return array{id:int,duplicate:bool,state:string}
     */
    private function captureLocked(Connection $connection, int $orgId, array $event, #[\SensitiveParameter] string $raw, CarbonImmutable $verifiedAt): array
    {
        $this->runtime($connection, $orgId);
        $this->schema($connection, required: true);
        $mode = StripeMode::fromTest($event['test'] ?? null);
        $hash = hash('sha256', $raw);
        // This also recognizes an erased receipt without recreating its deleted correlation graph.
        $duplicates = $connection->table(self::TABLE)->useWritePdo()->where('org_id', $orgId)->where('mode', $mode)
            ->where('event_id', $event['id'])->orderBy('id')->limit(2)->lockForUpdate()->get(self::COLUMNS);
        if ($duplicates->isNotEmpty()) {
            if ($duplicates->count() !== 1) {
                throw new CheckoutRefused;
            }
            $duplicate = $duplicates->sole();
            $this->validate($duplicate, $orgId);
            if (! hash_equals($duplicate->payload_hash, $hash)) {
                throw new CheckoutRefused('changed');
            }
            $privacy = $this->receiptPrivacy($connection, $orgId, $duplicate);
            if ($privacy['purged']) {
                $duplicate = $this->eraseReceipt($connection, $duplicate);
            } elseif ($privacy['rawless']) {
                $this->clearRaw($connection, $duplicate);
            }
            $this->expireRaw($connection, $duplicate);

            return ['id' => self::key($duplicate->id), 'duplicate' => true, 'state' => $duplicate->state];
        }
        $privacy = $this->replayPrivacy($connection, $orgId, $event);
        $financialPrivacy = null;
        if (isset($event['financialChargeId'])) {
            $envelope = $this->financialEnvelope($connection, $orgId, $event);
            if ($envelope === null) {
                if ($privacy['purged']) {
                    // No current receipt proves an account: quarantine only, never reconstruct a retired graph.
                    return ['id' => 0, 'duplicate' => false, 'state' => 'erased'];
                }
                $signal = app(FinancialSignals::class)->capture($connection, $orgId, $event, $raw, $verifiedAt);

                return ['id' => 0, 'duplicate' => $signal['duplicate'], 'state' => $signal['state'] === 'erased' ? 'erased' : 'unresolved'];
            }
            $financialPrivacy = $this->replayPrivacy($connection, $orgId, $event, $envelope['account_id']);
            if ($financialPrivacy['purged']) {
                return ['id' => 0, 'duplicate' => false, 'state' => 'erased'];
            }
            $event = $envelope['event'];
            if (isset($event['financialChargeId'])) {
                app(FinancialSignals::class)->capture($connection, $orgId, $event, $raw, $verifiedAt, $envelope['account_id']);
            }
        }
        if (isset($event['refundReference']) && ! isset($event['financialChargeId'])) {
            if ($privacy['purged'] && ! $connection->table(PaidOrders::ATTEMPTS)->useWritePdo()->where('org_id', $orgId)
                ->where('reference', $event['refundReference'])->exists()) {
                return ['id' => 0, 'duplicate' => false, 'state' => 'erased'];
            }
            $event = $this->refundEnvelope($connection, $orgId, $event);
        }
        $routes = $connection->table(self::ROUTING)->useWritePdo()->where('org_id', $orgId)
            ->where('mode', $mode)->where('order_reference', $event['orderReference'])->where('attempt_reference', $event['attemptReference'])
            ->orderBy('id')->limit(2)->lockForUpdate()->get();
        if ($routes->isEmpty() && $privacy['purged']) {
            return ['id' => 0, 'duplicate' => false, 'state' => 'erased'];
        }
        if ($routes->count() !== 1) {
            throw new CheckoutRefused;
        }
        $route = $routes->sole();
        $this->route($route, $orgId);
        $graphPrivacy = $this->graph($connection, $route, $event['sessionId']);
        $provedPrivacy = $this->replayPrivacy($connection, $orgId, $event, $route->account_id);
        if ($provedPrivacy['purged']) {
            return ['id' => 0, 'duplicate' => false, 'state' => 'erased'];
        }
        $keepRaw = ! ($provedPrivacy['rawless'] || ($financialPrivacy['rawless'] ?? false)
            || $graphPrivacy['subject_erased'] || $graphPrivacy['invoice_expired']);
        // Admission and projection share one bound. The Org lock prevents concurrent inserts passing it.
        if ($connection->table(self::TABLE)->useWritePdo()->where('order_id', $route->order_id)->count() >= self::MAX_EVENTS_PER_ORDER) {
            throw new CheckoutRefused('limit');
        }
        $received = self::now();
        $row = ['org_id' => $orgId, 'site_id' => $route->site_id, 'order_id' => $route->order_id,
            'attempt_id' => $route->attempt_id, 'event_id' => $event['id'], 'event_type' => $event['type'],
            'api_version' => $event['apiVersion'], 'session_id' => $event['sessionId'], 'account_id' => $route->account_id,
            'refund_reference' => $event['refundReference'] ?? null, 'refund_id' => $event['refundId'] ?? null,
            'financial_charge_id' => $event['financialChargeId'] ?? null, 'financial_subject_id' => $event['financialSubjectId'] ?? null,
            'mode' => $mode, 'order_reference' => $route->order_reference, 'attempt_reference' => $route->attempt_reference,
            'payload_hash' => $hash, 'raw_payload' => $keepRaw ? $raw : null, 'state' => 'pending', 'replay_count' => 0, 'category' => null,
            'lease_token' => null, 'lease_until' => null, 'verified_at' => self::stored($verifiedAt),
            'received_at' => self::stored($received), 'raw_expires_at' => self::stored($received->addDays(7)),
            'processed_at' => null, 'updated_at' => self::stored($received)];
        $id = $connection->table(self::TABLE)->insertGetId($row);
        $stored = $this->find($connection, $orgId, (int) $id, true);
        $retained = $connection->table(self::TABLE)->useWritePdo()->where('id', $id)->where('org_id', $orgId)->value('raw_payload');
        if ($keepRaw ? (! is_string($retained) || ! hash_equals($hash, hash('sha256', $retained))) : $retained !== null) {
            throw new CheckoutRefused;
        }
        foreach (array_diff_key($row, ['raw_payload' => true]) as $key => $value) {
            if (($value === null) !== ($stored->{$key} === null) || ($value !== null && (string) $value !== (string) $stored->{$key})) {
                throw new CheckoutRefused;
            }
        }

        return ['id' => (int) $id, 'duplicate' => false, 'state' => 'pending'];
    }

    /** Called inside the same Org→Site→Order transaction that inserts an immutable attempt. */
    public function registerAttempt(Connection $connection, object $expected): void
    {
        $this->safe(function () use ($connection, $expected): void {
            $org = self::key($expected->org_id ?? null);
            if ($connection->transactionLevel() < 1 || $this->context->orgId() !== $org || ! $this->schema($connection)) {
                throw new CheckoutRefused;
            }
            $order = $connection->table('commerce_orders')->useWritePdo()->where('id', self::key($expected->order_id ?? null))->lockForUpdate()->first()
                ?? throw new CheckoutRefused;
            $route = (object) ['org_id' => $org, 'site_id' => self::key($expected->site_id ?? null), 'order_id' => $order->id,
                'attempt_id' => self::key($expected->id ?? null), 'order_reference' => $order->reference,
                'attempt_reference' => $expected->reference ?? null, 'account_id' => $expected->account_id ?? null,
                'mode' => $expected->mode ?? null, 'created_at' => $expected->created_at ?? null];
            $this->route($route, $org);
            $this->graph($connection, $route);
            $existing = $connection->table(self::ROUTING)->useWritePdo()->where('org_id', $org)->where('attempt_id', $route->attempt_id)->lockForUpdate()->first();
            if ($existing !== null) {
                $this->route($existing, $org);
                foreach ((array) $route as $key => $value) {
                    if ((string) $existing->{$key} !== (string) $value) {
                        throw new CheckoutRefused;
                    }
                }

                return;
            }
            $connection->table(self::ROUTING)->insert((array) $route);
        });
    }

    /** A bounded non-raw replay; the current org is mandatory.
     * @return array{processed:int,retryable:int,blocked:int,pending:int}
     */
    public function replay(int $limit = 100, ?int $eventId = null, int $seconds = 30): array
    {
        return $this->safe(function () use ($limit, $eventId, $seconds): array {
            self::limit($limit);
            if ($seconds < 1 || $seconds > 300) {
                throw new CheckoutRefused;
            }
            $started = hrtime(true);
            $org = $this->context->orgId() ?? throw new CheckoutRefused;
            $connection = (new Order)->getConnection();
            $this->runtime($connection, $org);
            $this->schema($connection, required: true);
            $query = $connection->table(self::TABLE)->useWritePdo()->where('org_id', $org);
            $eventId === null ? $query->whereIn('state', ['pending', 'retryable', 'blocked', 'processing']) : $query->where('id', self::key($eventId));
            $ids = $query->where(static fn ($eligible) => $eligible->whereNull('lease_until')->orWhere('lease_until', '<=', self::stored(self::now())))
                ->orderBy('replay_count')->orderBy('id')->limit($limit)->pluck('id');
            $counts = ['processed' => 0, 'retryable' => 0, 'blocked' => 0, 'pending' => 0];
            foreach ($ids as $offset => $id) {
                if (hrtime(true) - $started >= $seconds * 1000000000) {
                    $counts['pending'] += $ids->count() - $offset;
                    break;
                }
                $state = $this->process(self::key($id));
                $counts[in_array($state, ['processed', 'erased'], true) ? 'processed' : ($state === 'processing' ? 'pending' : $state)]++;
            }

            return $counts;
        });
    }

    /** Provider I/O occurs after the receipt lease commits and never reads raw_payload. */
    public function process(int $id): string
    {
        return $this->safe(function () use ($id): string {
            $org = $this->context->orgId() ?? throw new CheckoutRefused;
            $connection = (new Order)->getConnection();
            if ($connection->transactionLevel() !== 0) {
                throw new CheckoutRefused;
            }
            $claimed = false;
            $captured = $this->locked($connection, $org, function () use ($connection, $org, $id, &$claimed): object {
                $this->runtime($connection, $org);
                $row = $this->find($connection, $org, $id, true);
                $privacy = $this->receiptPrivacy($connection, $org, $row);
                if ($privacy['purged']) {
                    return $this->eraseReceipt($connection, $row);
                }
                if ($privacy['rawless']) {
                    $this->clearRaw($connection, $row);
                }
                $this->expireRaw($connection, $row);
                if (in_array($row->state, ['processed', 'erased'], true)
                    || ($row->lease_until !== null && CheckoutAttempts::instant($row->lease_until)->greaterThan(self::now()))) {
                    return $row;
                }
                $graphPrivacy = $this->graph($connection, $row, $row->session_id);
                if ($graphPrivacy['subject_erased'] || $graphPrivacy['invoice_expired']) {
                    $this->clearRaw($connection, $row);
                }
                $count = self::writtenKey($row->replay_count) ?? throw new CheckoutRefused;
                if ($count >= 2147483647) {
                    throw new CheckoutRefused('operator');
                }
                $changes = ['state' => 'processing', 'replay_count' => $count + 1, 'category' => null, 'lease_token' => bin2hex(random_bytes(32)),
                    'lease_until' => self::stored(self::now()->addSeconds($row->refund_reference === null && $row->financial_charge_id === null ? 30 : 120)), 'updated_at' => self::stored(self::now())];
                $this->update($connection, $row, $changes);
                $claimed = true;

                return (object) [...(array) $row, ...$changes];
            });
            if (! $claimed || $captured->state !== 'processing' || $captured->lease_token === null
                || CheckoutAttempts::instant($captured->lease_until)->lessThanOrEqualTo(self::now())) {
                return $captured->state;
            }
            $beforeOrg = $this->context->org();
            $beforeSite = $this->context->site();
            $state = 'retryable';
            $category = 'unavailable';
            try {
                $site = Site::query()->useWritePdo()->whereKey($captured->site_id)->where('org_id', $org)->first() ?? throw new CheckoutRefused;
                $this->context->setSite($site);
                if ($captured->financial_charge_id !== null) {
                    // The committed unresolved signal suspends its own order source before credentials/network
                    // admission. Provider unavailability must not leave an already granted source usable.
                    app(AccessAdjustments::class)->reconcile($captured->order_reference);
                    $result = app(FinancialReconciler::class)->reconcile($captured->order_reference);
                    app(AccessFulfillment::class)->reconcile($captured->order_reference);
                    app(AccessAdjustments::class)->reconcile($captured->order_reference);
                } else {
                    $result = $captured->refund_reference === null
                        ? app(CheckoutReconciler::class)->reconcile($captured->order_reference, $captured->attempt_reference, $captured->session_id)
                        : app(RefundRecovery::class)->reconcile($captured->order_reference, $captured->refund_reference, $captured->refund_id);
                }
                if (in_array($result['state'], ['open', 'expired', 'completed', 'rejected', 'pending', 'requires_action', 'succeeded', 'failed', 'canceled', 'processed'], true)
                    || ($captured->financial_charge_id !== null && $result['state'] === 'held')) {
                    $state = 'processed';
                    $category = null;
                }
            } catch (CheckoutRefused $failure) {
                $category = in_array($failure->reason, ['busy', 'changed', 'setup', 'operator', 'live'], true) ? $failure->reason : 'unavailable';
                $state = in_array($category, ['changed', 'setup', 'operator', 'live'], true) ? 'blocked' : 'retryable';
            } catch (Throwable) {
                // Neither provider text nor chained database/input exceptions become receipt text.
            } finally {
                $this->context->forget();
                $beforeSite !== null ? $this->context->setSite($beforeSite) : $this->context->setOrg($beforeOrg);
            }

            return $this->locked($connection, $org, function () use ($connection, $org, $captured, $state, $category): string {
                $this->runtime($connection, $org);
                $row = $this->find($connection, $org, self::key($captured->id), true);
                if ($row->state === 'erased' || $row->lease_token !== $captured->lease_token) {
                    return $row->state;
                }
                foreach (self::COLUMNS as $column) {
                    if (($row->{$column} === null) !== ($captured->{$column} === null)
                        || ($row->{$column} !== null && (string) $row->{$column} !== (string) $captured->{$column})) {
                        throw new CheckoutRefused;
                    }
                }
                $this->graph($connection, $row, $row->session_id);
                $changes = ['state' => $state, 'category' => $category, 'lease_token' => null, 'lease_until' => null,
                    'processed_at' => $state === 'processed' ? self::stored(self::now()) : null, 'updated_at' => self::stored(self::now())];
                $this->update($connection, $row, $changes);
                $this->expireRaw($connection, $row);

                return $state;
            });
        });
    }

    /** Physical deletion is operator-invoked and bounded; logical expiry applies even on an idle installation. */
    public function prune(int $limit = 100): int
    {
        return $this->safe(function () use ($limit): int {
            self::limit($limit);
            $org = $this->context->orgId() ?? throw new CheckoutRefused;
            $connection = (new Order)->getConnection();

            return $this->locked($connection, $org, function () use ($connection, $org, $limit): int {
                $this->schema($connection, required: true);
                $rows = $connection->table(self::TABLE)->useWritePdo()->where('org_id', $org)->whereNotNull('raw_payload')
                    ->where('raw_expires_at', '<=', self::stored(self::now()))->orderBy('id')->limit($limit)->lockForUpdate()->get(self::COLUMNS);
                foreach ($rows as $row) {
                    $this->validate($row, $org);
                    $this->expireRaw($connection, $row);
                }

                return $rows->count();
            });
        });
    }

    /** Safe summaries accompany export even when module runtime is disabled.
     * @return list<array<string,mixed>>
     */
    public function projection(Connection $connection, int $org, object $order, bool $lock = false): array
    {
        return $this->orderRows($connection, $org, $order, $lock)->map(static fn (stdClass $row): array => [
            'state' => $row->state, 'event_type' => $row->event_type, 'category' => $row->category,
            'received_at' => CheckoutAttempts::instant($row->received_at)->format('Y-m-d\TH:i:s\Z'),
            'raw_expires_at' => CheckoutAttempts::instant($row->raw_expires_at)->format('Y-m-d\TH:i:s\Z'),
            'processed_at' => $row->processed_at === null ? null : CheckoutAttempts::instant($row->processed_at)->format('Y-m-d\TH:i:s\Z'),
        ])->all();
    }

    /** Recover only one exact Session hint from the verified minimal receipt; raw expiry is irrelevant. */
    public function sessionForAttempt(Connection $connection, int $org, object $order, object $attempt): ?string
    {
        return $this->safe(function () use ($connection, $org, $order, $attempt): ?string {
            if ($connection->transactionLevel() < 1 || self::key($attempt->org_id) !== $org
                || self::key($attempt->site_id) !== self::key($order->site_id) || self::key($attempt->order_id) !== self::key($order->id)) {
                throw new CheckoutRefused;
            }
            $sessions = [];
            foreach ($this->orderRows($connection, $org, $order, true) as $row) {
                if (self::key($row->attempt_id) === self::key($attempt->id)) {
                    if ($row->account_id !== $attempt->account_id || $row->mode !== $attempt->mode
                        || $row->api_version !== $attempt->api_version || $row->attempt_reference !== $attempt->reference) {
                        throw new CheckoutRefused;
                    }
                    $sessions[$row->session_id] = true;
                }
            }
            if (count($sessions) > 1) {
                throw new CheckoutRefused('changed');
            }

            return array_key_first($sessions);
        });
    }

    /** Raw parent-first reads detect children misfiled into another org or site instead of hiding them. */
    /** A durable verified refund hint survives failed GETs; absence from a list never authorizes replacement.
     * Caller still retrieves and proves the exact provider object before financial state changes.
     */
    public function refundForAttempt(Connection $connection, int $org, object $order, object $refund): ?string
    {
        $ids = [];
        foreach ($this->orderRows($connection, $org, $order, $connection->transactionLevel() > 0) as $event) {
            if ($event->state !== 'erased' && $event->refund_reference === $refund->reference) {
                $ids[$event->refund_id] = true;
            }
        }
        if (count($ids) > 1) {
            throw new CheckoutRefused('changed');
        }

        return $ids === [] ? null : array_key_first($ids);
    }

    /** A verified selected-unit identity remains known even when raw bytes expire or a provider list omits it. */
    public function financialRefundForAttempt(Connection $connection, int $org, object $order, object $refund): ?string
    {
        $ids = [];
        foreach ($this->orderRows($connection, $org, $order, $connection->transactionLevel() > 0) as $event) {
            if ($event->state !== 'erased' && $event->financial_charge_id !== null && $event->refund_reference === $refund->reference) {
                $ids[$event->refund_id] = true;
            }
        }
        if (count($ids) > 1) {
            throw new CheckoutRefused('changed');
        }

        return $ids === [] ? null : array_key_first($ids);
    }

    public function assertOrderCustody(Connection $connection, int $org, object $order, bool $lock = false): void
    {
        $this->orderRows($connection, $org, $order, $lock);
    }

    /** Terminal-order erasure keeps only the stable dedup identity/digest, with no replayable correlations. */
    public function forgetOrder(Connection $connection, int $org, object $order): void
    {
        $this->safe(function () use ($connection, $org, $order): void {
            if ($connection->transactionLevel() < 1) {
                throw new CheckoutRefused;
            }
            app(CheckoutAttempts::class)->assertErasable($connection, $org, $order);
            $rows = $this->orderRows($connection, $org, $order, true);
            foreach ($rows as $row) {
                $this->update($connection, $row, ['site_id' => null, 'order_id' => null, 'attempt_id' => null,
                    'order_reference' => null, 'attempt_reference' => null, 'session_id' => null, 'refund_reference' => null, 'refund_id' => null, 'financial_charge_id' => null, 'financial_subject_id' => null, 'raw_payload' => null,
                    'state' => 'erased', 'category' => null, 'lease_token' => null, 'lease_until' => null,
                    'processed_at' => null, 'updated_at' => self::stored(self::now())]);
            }
            if ($this->schema($connection)) {
                $routes = $connection->table(self::ROUTING)->useWritePdo()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
                foreach ($routes as $route) {
                    $this->route($route, $org, $order);
                    if ($connection->table(self::ROUTING)->where('id', $route->id)->where('org_id', $org)->where('order_id', $order->id)->delete() !== 1) {
                        throw new CheckoutRefused;
                    }
                }
            }
        });
    }

    private function schema(Connection $connection, bool $required = false): bool
    {
        $schema = $connection->getSchemaBuilder();
        $tables = [self::TABLE => [...self::COLUMNS, 'raw_payload'], self::ROUTING => self::ROUTE_COLUMNS];
        if (! $schema->hasTable(self::TABLE) && ! $schema->hasTable(self::ROUTING)) {
            if ($required || ($schema->hasTable('migrations') && $connection->table('migrations')->useWritePdo()->where('migration', self::MIGRATION)->exists())) {
                throw new CheckoutRefused;
            }

            return false;
        }
        foreach ($tables as $table => $columns) {
            if (! $schema->hasTable($table) || ! $schema->hasColumns($table, $columns)) {
                throw new CheckoutRefused;
            }
        }

        return true;
    }

    /** @return Collection<int,stdClass> */
    private function orderRows(Connection $connection, int $org, object $order, bool $lock): Collection
    {
        return $this->safe(function () use ($connection, $org, $order, $lock): Collection {
            if (self::key($order->org_id ?? null) !== $org) {
                throw new CheckoutRefused;
            }
            if (! $this->schema($connection)) {
                return collect();
            }
            $query = $connection->table(self::TABLE)->useWritePdo()->where('order_id', self::key($order->id ?? null))->orderBy('id')->limit(self::MAX_EVENTS_PER_ORDER + 1);
            $rows = ($lock ? $query->lockForUpdate() : $query)->get(self::COLUMNS);
            if ($rows->count() > self::MAX_EVENTS_PER_ORDER) {
                throw new CheckoutRefused;
            }
            foreach ($rows as $row) {
                $this->validate($row, $org, $order);
                $this->correlation($connection, $row, $org, $order);
            }
            $query = $connection->table(self::ROUTING)->useWritePdo()->where('order_id', $order->id)->orderBy('id')->limit(CheckoutAttempts::MAX_ATTEMPTS + 1);
            $routes = ($lock ? $query->lockForUpdate() : $query)->get();
            if ($routes->count() > CheckoutAttempts::MAX_ATTEMPTS) {
                throw new CheckoutRefused;
            }
            foreach ($routes as $route) {
                $this->route($route, $org, $order);
                $this->correlation($connection, $route, $org, $order);
            }

            return $rows;
        });
    }

    private function correlation(Connection $connection, object $row, int $org, object $order): void
    {
        $attempt = $connection->table(CheckoutAttempts::TABLE)->useWritePdo()->where('id', self::key($row->attempt_id))->first();
        if ($attempt === null || self::key($attempt->org_id) !== $org || self::key($attempt->site_id) !== self::key($order->site_id)
            || self::key($attempt->order_id) !== self::key($order->id) || $attempt->reference !== $row->attempt_reference
            || $attempt->account_id !== $row->account_id || $attempt->mode !== $row->mode
            || (isset($row->session_id) && $attempt->session_id !== null && $row->session_id !== $attempt->session_id)) {
            throw new CheckoutRefused;
        }
        if (isset($row->financial_charge_id)) {
            $payment = app(PaidOrders::class)->receipt($connection, $order);
            if ($payment === null || $payment->charge_id !== $row->financial_charge_id || $payment->account_id !== $row->account_id
                || $payment->session_id !== $row->session_id || (string) $payment->attempt_id !== (string) $attempt->id) {
                throw new CheckoutRefused;
            }
            if ($row->refund_reference !== null) {
                $refund = $connection->table(FinancialState::REFUNDS)->useWritePdo()->where('reference', $row->refund_reference)
                    ->where('org_id', $org)->where('order_id', $order->id)->limit(2)->get();
                if ($refund->count() !== 1 || ($refund->sole()->refund_id !== null && $refund->sole()->refund_id !== $row->refund_id)) {
                    throw new CheckoutRefused;
                }
            }
        } elseif (isset($row->refund_reference)) {
            $refund = $connection->table(PaidOrders::ATTEMPTS)->useWritePdo()->where('org_id', $org)
                ->where('reference', $row->refund_reference)->limit(2)->get();
            if ($refund->count() !== 1) {
                throw new CheckoutRefused;
            }
            $refund = $refund->sole();
            $payment = app(PaidOrders::class)->receipt($connection, $order);
            $obligation = $connection->table(PaidOrders::OBLIGATIONS)->useWritePdo()->where('id', $refund->obligation_id)->first();
            if ($payment === null || $obligation === null || (string) $payment->attempt_id !== (string) $attempt->id
                || (string) $obligation->payment_id !== (string) $payment->id
                || ($refund->refund_id !== null && $refund->refund_id !== $row->refund_id)) {
                throw new CheckoutRefused;
            }
            app(PaidOrders::class)->refundAttempt($refund, $obligation, $payment, $order);
        }

    }

    private function find(Connection $connection, int $org, int $id, bool $lock): object
    {
        $this->schema($connection, required: true);
        $query = $connection->table(self::TABLE)->useWritePdo()->where('org_id', $org)->where('id', self::key($id));
        $row = ($lock ? $query->lockForUpdate() : $query)->first(self::COLUMNS) ?? throw new CheckoutRefused;
        $this->validate($row, $org);

        return $row;
    }

    private function validate(object $row, int $org, ?object $order = null): void
    {
        if (self::key($row->id ?? null) < 1 || self::key($row->org_id ?? null) !== $org || ! in_array($row->mode, ['test', 'live'], true)
            || ! self::identifier($row->account_id ?? null, 'acct_') || ! self::identifier($row->event_id ?? null, 'evt_', 255)
            || ! in_array($row->event_type ?? null, ['checkout.session.completed', 'checkout.session.expired', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed', ...FinancialSignals::TYPES], true)
            || ! is_string($row->api_version ?? null) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\.[a-z]+\z/D', $row->api_version) !== 1
            || self::writtenKey($row->replay_count ?? null) === null || self::writtenKey($row->replay_count) < 0 || self::writtenKey($row->replay_count) > 2147483647
            || ! self::hex($row->payload_hash ?? null, 64) || ! in_array($row->state ?? null, ['pending', 'processing', 'retryable', 'blocked', 'processed', 'erased'], true)
            || ($row->category !== null && ! in_array($row->category, ['busy', 'changed', 'setup', 'operator', 'live', 'unavailable'], true))
            || ($row->lease_token === null) !== ($row->lease_until === null)
            || ($row->lease_token !== null && (! self::hex($row->lease_token, 64) || $row->state !== 'processing'))
            || ($row->state === 'processing' && $row->lease_token === null)
            || ($row->state === 'processed') !== ($row->processed_at !== null)
            || (in_array($row->state, ['pending', 'processing', 'processed', 'erased'], true) !== ($row->category === null))) {
            throw new CheckoutRefused;
        }
        $financial = $row->financial_charge_id !== null;
        if ($row->state !== 'erased' && in_array($row->event_type, FinancialSignals::TYPES, true)
            && ! str_starts_with($row->event_type, 'refund.') && ! $financial) {
            throw new CheckoutRefused;
        }
        if ($row->state !== 'erased' && ($financial !== ($row->financial_subject_id !== null)
            || ($financial && (! in_array($row->event_type, FinancialSignals::TYPES, true)
                || ! self::identifier($row->financial_charge_id, 'ch_')
                || ! self::identifier($row->financial_subject_id, str_starts_with($row->event_type, 'refund.') ? 're_'
                    : (str_starts_with($row->event_type, 'charge.dispute.') ? 'du_' : 'ch_')))))) {
            throw new CheckoutRefused;
        }
        $refundEvent = str_starts_with($row->event_type, 'refund.') && ! $financial;
        if ($financial && (($row->refund_reference === null) !== ($row->refund_id === null)
            || ($row->refund_reference !== null && (! self::hex($row->refund_reference, 32)
                || $row->refund_id !== $row->financial_subject_id)))) {
            throw new CheckoutRefused;
        }
        if ($row->state !== 'erased' && ((! $financial && $refundEvent !== ($row->refund_reference !== null && $row->refund_id !== null))
            || ($refundEvent && (! self::hex($row->refund_reference, 32) || ! self::identifier($row->refund_id, 're_'))))) {
            throw new CheckoutRefused;
        }
        $received = CheckoutAttempts::instant($row->received_at);
        $verified = CheckoutAttempts::instant($row->verified_at);
        if ($received->diffInSeconds($verified, true) > 300 || ! CheckoutAttempts::instant($row->raw_expires_at)->equalTo($received->addDays(7))
            || CheckoutAttempts::instant($row->updated_at)->lessThan($received)
            || ($row->processed_at !== null && CheckoutAttempts::instant($row->processed_at)->lessThan($received))
            || ($row->lease_until !== null && CheckoutAttempts::instant($row->lease_until)->lessThan($received))) {
            throw new CheckoutRefused;
        }
        if ($row->state === 'erased') {
            foreach (['site_id', 'order_id', 'attempt_id', 'order_reference', 'attempt_reference', 'session_id', 'refund_reference', 'refund_id', 'financial_charge_id', 'financial_subject_id', 'category', 'lease_token', 'lease_until'] as $column) {
                if ($row->{$column} !== null) {
                    throw new CheckoutRefused;
                }
            }
        } else {
            foreach (['site_id', 'order_id', 'attempt_id'] as $column) {
                self::key($row->{$column});
            }
            if (! self::hex($row->order_reference, 32) || ! self::hex($row->attempt_reference, 32) || ! StripeMode::session($row->session_id, $row->mode)
                || ($order !== null && (self::key($row->order_id) !== self::key($order->id) || self::key($row->site_id) !== self::key($order->site_id)
                    || $row->order_reference !== $order->reference || $row->mode !== $order->mode))) {
                throw new CheckoutRefused;
            }
        }
    }

    private function route(object $row, int $org, ?object $order = null): void
    {
        foreach (['org_id', 'site_id', 'order_id', 'attempt_id'] as $column) {
            self::key($row->{$column} ?? null);
        }
        if (self::key($row->org_id) !== $org || ! self::hex($row->order_reference ?? null, 32)
            || ! self::hex($row->attempt_reference ?? null, 32) || ! self::identifier($row->account_id ?? null, 'acct_') || ! in_array($row->mode, ['test', 'live'], true)
            || ($order !== null && (self::key($row->order_id) !== self::key($order->id) || self::key($row->site_id) !== self::key($order->site_id)
                || $row->order_reference !== $order->reference || $row->mode !== $order->mode))) {
            throw new CheckoutRefused;
        }
        CheckoutAttempts::instant($row->created_at);
    }

    /** Independent graph check, with the recorded Site in context, never the ingress Site.
     * @return array{subject_erased:bool,invoice_expired:bool}
     */
    private function graph(Connection $connection, object $receipt, ?string $sessionId = null): array
    {
        $org = self::key($receipt->org_id);
        $beforeOrg = $this->context->org();
        $beforeSite = $this->context->site();
        try {
            $site = Site::query()->useWritePdo()->whereKey(self::key($receipt->site_id))->where('org_id', $org)->lockForUpdate()->first() ?? throw new CheckoutRefused;
            $this->context->setSite($site);
            $order = app(OrderReaderData::class)->find($connection, $org, self::key($receipt->site_id), $receipt->order_reference, true) ?? throw new CheckoutRefused;
            if (self::key($order->id) !== self::key($receipt->order_id)) {
                throw new CheckoutRefused;
            }
            app(OrderReaderData::class)->record($connection, $order);
            $this->correlation($connection, $receipt, $org, $order);
            $attempt = app(CheckoutAttempts::class)->rows($connection, $org, $order, true)->first(static fn (object $row): bool => self::key($row->id) === self::key($receipt->attempt_id)) ?? throw new CheckoutRefused;
            if ($attempt->reference !== $receipt->attempt_reference || $attempt->account_id !== $receipt->account_id || $attempt->mode !== $receipt->mode
                || (isset($receipt->api_version) && $attempt->api_version !== $receipt->api_version)
                || ($sessionId !== null && $attempt->session_id !== null && $attempt->session_id !== $sessionId)) {
                throw new CheckoutRefused;
            }
            $privacy = app(PrivacyState::class)->checked($connection, $order, true);

            return ['subject_erased' => $privacy['subject_erased'] ?? false, 'invoice_expired' => $privacy['invoice_expired'] ?? false];
        } finally {
            $this->context->forget();
            $beforeSite !== null ? $this->context->setSite($beforeSite) : $this->context->setOrg($beforeOrg);
        }
    }

    /** A signed hint selects no Site or order until the exact original receipt proves its Charge relation.
     * @param  array<string,mixed>  $event
     * @return array{event:array<string,mixed>,account_id:string}|null
     */
    private function financialEnvelope(Connection $connection, int $org, array $event): ?array
    {
        $payments = $connection->table(PaidOrders::RECEIPTS)->useWritePdo()->where('org_id', $org)
            ->where('mode', StripeMode::fromTest($event['test']))->where('charge_id', $event['financialChargeId'])->limit(2)->lockForUpdate()->get();
        if ($payments->isEmpty()) {
            return null;
        }
        if ($payments->count() !== 1) {
            throw new CheckoutRefused;
        }
        $payment = $payments->sole();
        $order = $connection->table('commerce_orders')->useWritePdo()->where('id', $payment->order_id)->lockForUpdate()->first() ?? throw new CheckoutRefused;
        if ((string) $order->org_id !== (string) $org || (string) $order->site_id !== (string) $payment->site_id) {
            throw new CheckoutRefused;
        }
        app(PaidOrders::class)->receipt($connection, $order, true) ?? throw new CheckoutRefused;
        $attempt = $connection->table(CheckoutAttempts::TABLE)->useWritePdo()->where('id', $payment->attempt_id)->lockForUpdate()->first() ?? throw new CheckoutRefused;
        if (isset($event['refundReference'])) {
            $old = $connection->table(PaidOrders::ATTEMPTS)->useWritePdo()->where('org_id', $org)
                ->where('reference', $event['refundReference'])->limit(2)->lockForUpdate()->get();
            if ($old->isNotEmpty()) {
                $legacy = $this->refundEnvelope($connection, $org, $event);
                if ($legacy['sessionId'] !== $payment->session_id) {
                    throw new CheckoutRefused;
                }
                unset($legacy['financialChargeId'], $legacy['financialSubjectId']);

                return ['event' => $legacy, 'account_id' => $payment->account_id];
            }
            $locals = $connection->table(FinancialState::REFUNDS)->useWritePdo()->where('org_id', $org)
                ->where('reference', $event['refundReference'])->limit(2)->lockForUpdate()->get();
            if ($locals->count() === 1) {
                $local = $locals->sole();
                $request = json_decode($local->request_payload, true, 32, JSON_THROW_ON_ERROR);
                if ((string) $local->order_id !== (string) $order->id || $event['orderReference'] !== $order->reference
                    || ($request['metadata']['kitsune_request'] ?? null) !== $event['requestDigest']
                    || ($local->refund_id !== null && $local->refund_id !== $event['refundId'])) {
                    throw new CheckoutRefused;
                }
            } elseif ($locals->count() > 1) {
                throw new CheckoutRefused;
            } else {
                // Unknown metadata is never adopted as a local request; the current graph classifies it as external.
                unset($event['refundReference'], $event['refundId'], $event['requestDigest']);
            }
        }

        return ['event' => [...$event, 'orderReference' => $order->reference, 'attemptReference' => $attempt->reference, 'sessionId' => $payment->session_id],
            'account_id' => $payment->account_id];
    }

    /** @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    private function refundEnvelope(Connection $connection, int $org, array $event): array
    {
        $rows = $connection->table(PaidOrders::ATTEMPTS)->useWritePdo()->where('org_id', $org)
            ->where('reference', $event['refundReference'])->limit(2)->lockForUpdate()->get();
        if ($rows->count() !== 1) {
            throw new CheckoutRefused;
        }
        $refund = $rows->sole();
        $order = $connection->table('commerce_orders')->useWritePdo()->where('id', $refund->order_id)->first() ?? throw new CheckoutRefused;
        $payment = app(PaidOrders::class)->receipt($connection, $order, true) ?? throw new CheckoutRefused;
        $obligation = $connection->table(PaidOrders::OBLIGATIONS)->useWritePdo()->where('id', $refund->obligation_id)->first() ?? throw new CheckoutRefused;
        app(PaidOrders::class)->refundAttempt($refund, $obligation, $payment, $order);
        $request = app(PaidOrders::class)->refundRequest($refund);
        if ($payment->mode !== StripeMode::fromTest($event['test']) || (string) $order->org_id !== (string) $org || $order->reference !== $event['orderReference']
            || $request['metadata']['kitsune_request'] !== $event['requestDigest']
            || ($refund->refund_id !== null && $refund->refund_id !== $event['refundId'])) {
            throw new CheckoutRefused;
        }
        $attempt = $connection->table(CheckoutAttempts::TABLE)->useWritePdo()->where('id', $payment->attempt_id)->first() ?? throw new CheckoutRefused;

        return [...$event, 'sessionId' => $payment->session_id, 'attemptReference' => $attempt->reference];
    }

    /** @param array<string,mixed> $event */
    private function event(array $event): void
    {
        if (isset($event['financialChargeId'])) {
            $base = ['id', 'type', 'apiVersion', 'financialChargeId', 'financialSubjectId', 'test'];
            $metadata = ['refundId', 'orderReference', 'refundReference', 'requestDigest'];
            if (array_diff(array_keys($event), [...$base, ...$metadata]) !== [] || array_diff($base, array_keys($event)) !== []
                || ! is_bool($event['test']) || ! self::identifier($event['id'], 'evt_', 255)
                || ! in_array($event['type'], FinancialSignals::TYPES, true) || $event['apiVersion'] !== StripeCheckout::API_VERSION
                || ! self::identifier($event['financialChargeId'], 'ch_')
                || ! self::identifier($event['financialSubjectId'], str_starts_with($event['type'], 'refund.') ? 're_'
                    : (str_starts_with($event['type'], 'charge.dispute.') ? 'du_' : 'ch_'))
                || (isset($event['refundReference']) && (! self::hex($event['refundReference'], 32)
                    || ! self::hex($event['orderReference'] ?? null, 32) || ! self::hex($event['requestDigest'] ?? null, 64)
                    || ($event['refundId'] ?? null) !== $event['financialSubjectId']))) {
                throw new CheckoutRefused;
            }

            return;
        }
        if (str_starts_with($event['type'] ?? '', 'refund.')) {
            $keys = ['id', 'type', 'apiVersion', 'refundId', 'orderReference', 'refundReference', 'requestDigest', 'test'];
            if (count($event) !== count($keys) || array_diff(array_keys($event), $keys) !== [] || ! is_bool($event['test'])
                || ! self::identifier($event['id'], 'evt_', 255) || ! in_array($event['type'], ['refund.created', 'refund.updated', 'refund.failed'], true)
                || $event['apiVersion'] !== StripeCheckout::API_VERSION || ! self::identifier($event['refundId'], 're_')
                || ! self::hex($event['orderReference'], 32) || ! self::hex($event['refundReference'], 32) || ! self::hex($event['requestDigest'], 64)) {
                throw new CheckoutRefused;
            }

            return;
        }
        $keys = ['id', 'type', 'apiVersion', 'sessionId', 'orderReference', 'attemptReference', 'test'];
        if (count($event) !== count($keys) || array_diff(array_keys($event), $keys) !== [] || ! is_bool($event['test'])
            || ! self::identifier($event['id'], 'evt_', 255) || ! in_array($event['type'], ['checkout.session.completed', 'checkout.session.expired', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed', 'refund.created', 'refund.updated', 'refund.failed'], true)
            || $event['apiVersion'] !== StripeCheckout::API_VERSION || ! StripeMode::session($event['sessionId'], StripeMode::fromTest($event['test']))
            || ! self::hex($event['orderReference'], 32) || ! self::hex($event['attemptReference'], 32)) {
            throw new CheckoutRefused;
        }
    }

    /** @param array<string,mixed> $changes */
    private function update(Connection $connection, object $row, array $changes): void
    {
        if ($connection->table(self::TABLE)->where('id', $row->id)->where('org_id', $row->org_id)->update($changes) !== 1) {
            throw new CheckoutRefused;
        }
    }

    /** Verified signatures give hints, not account authority. Unknown-account matches only suppress or quarantine.
     * @param  array<string,mixed>  $hints
     * @return array{subject_erased:bool,invoice_expired:bool,purged:bool,rawless:bool}
     */
    private function replayPrivacy(Connection $connection, int $org, array $hints, ?string $verifiedAccountId = null): array
    {
        $result = ['subject_erased' => false, 'invoice_expired' => false, 'purged' => false, 'rawless' => false];
        foreach (['financialChargeId' => 'charge', 'orderReference' => 'order', 'attemptReference' => 'attempt',
            'sessionId' => 'session', 'refundReference' => 'refund', 'refundId' => 'refund'] as $key => $kind) {
            if (! isset($hints[$key])) {
                continue;
            }
            $state = app(PrivacyState::class)->replayIdentity($connection, $org, StripeMode::fromTest($hints['test'] ?? null), $kind, $hints[$key], $verifiedAccountId);
            if ($state !== null) {
                foreach (['subject_erased', 'invoice_expired'] as $flag) {
                    $result[$flag] = $result[$flag] || $state[$flag];
                }
                $result['rawless'] = $result['rawless'] || $state['subject_erased'] || $state['invoice_expired'] || $state['purged'];
                // Local references are unique per Site, so an Org-wide match alone cannot retire sibling work.
                if (in_array($key, ['financialChargeId', 'sessionId', 'refundId'], true)) {
                    $result['purged'] = $result['purged'] || $state['purged'];
                }
            }
        }

        return $result;
    }

    /** Existing custody proves its own account before a tombstone may retire it.
     * @return array{subject_erased:bool,invoice_expired:bool,purged:bool,rawless:bool}
     */
    private function receiptPrivacy(Connection $connection, int $org, object $row): array
    {
        $hints = self::receiptHints($row);
        $unknown = $this->replayPrivacy($connection, $org, $hints);
        if ($row->state === 'erased' || ! $unknown['rawless']) {
            return $unknown;
        }
        if (! $connection->table('commerce_orders')->useWritePdo()->where('id', $row->order_id)->exists()) {
            if (! $unknown['purged']) {
                throw new CheckoutRefused;
            }

            return $unknown;
        }
        $graph = $this->graph($connection, $row, $row->session_id);
        $proved = $this->replayPrivacy($connection, $org, $hints, $row->account_id);
        $proved['rawless'] = $proved['rawless'] || $graph['subject_erased'] || $graph['invoice_expired'];

        return $proved;
    }

    /** @return array<string,mixed> */
    private static function receiptHints(object $row): array
    {
        return ['test' => StripeMode::isTest($row->mode), 'financialChargeId' => $row->financial_charge_id, 'orderReference' => $row->order_reference,
            'attemptReference' => $row->attempt_reference, 'sessionId' => $row->session_id,
            'refundReference' => $row->refund_reference, 'refundId' => $row->refund_id];
    }

    private function clearRaw(Connection $connection, object $row): void
    {
        $connection->table(self::TABLE)->where('id', $row->id)->where('org_id', $row->org_id)->whereNotNull('raw_payload')->update(['raw_payload' => null]);
        if ($connection->table(self::TABLE)->useWritePdo()->where('id', $row->id)->where('org_id', $row->org_id)->value('raw_payload') !== null) {
            throw new CheckoutRefused;
        }
    }

    private function eraseReceipt(Connection $connection, object $row): object
    {
        if ($row->state === 'erased') {
            $this->clearRaw($connection, $row);

            return $row;
        }
        $changes = ['site_id' => null, 'order_id' => null, 'attempt_id' => null,
            'order_reference' => null, 'attempt_reference' => null, 'session_id' => null, 'refund_reference' => null, 'refund_id' => null,
            'financial_charge_id' => null, 'financial_subject_id' => null, 'raw_payload' => null,
            'state' => 'erased', 'category' => null, 'lease_token' => null, 'lease_until' => null,
            'processed_at' => null, 'updated_at' => self::stored(self::now())];
        $this->update($connection, $row, $changes);
        $this->clearRaw($connection, $row);
        $stored = $this->find($connection, (int) $row->org_id, (int) $row->id, true);
        foreach (array_diff_key($changes, ['raw_payload' => true]) as $key => $value) {
            if ($stored->{$key} !== $value) {
                throw new CheckoutRefused;
            }
        }

        return $stored;
    }

    private function expireRaw(Connection $connection, object $row): void
    {
        if ($row->state === 'erased' || CheckoutAttempts::instant($row->raw_expires_at)->lessThanOrEqualTo(self::now())) {
            $connection->table(self::TABLE)->where('id', $row->id)->where('org_id', $row->org_id)->whereNotNull('raw_payload')->update(['raw_payload' => null]);
        }
    }

    private function runtime(Connection $connection, int $org): void
    {
        $this->schema($connection, required: true);
        $mode = $connection->table('org_credential_modes')->useWritePdo()->where('org_id', $org)->first();
        $version = ModuleDiscovery::version('kitsune/commerce');
        if ($this->context->orgId() !== $org || ($mode !== null && ! in_array($mode->mode, ['test', 'live'], true)) || $version === null
            || ! $connection->table('modules')->useWritePdo()->where('handle', 'kitsune/commerce')->where('is_enabled', true)->where('version', $version)->exists()) {
            throw new CheckoutRefused('changed');
        }
    }

    /** @template T
     * @param  Closure():T  $work
     * @return T
     */
    private function locked(Connection $connection, int $org, Closure $work): mixed
    {
        return TransactionRecovery::run($connection, function () use ($connection, $org, $work): mixed {
            if ($this->context->orgId() !== $org) {
                throw new CheckoutRefused;
            }
            if ($connection->getDriverName() === 'sqlite') {
                $connection->table('orgs')->where('id', $org)->update(['id' => $org]);
            }
            $connection->table('orgs')->useWritePdo()->where('id', $org)->whereNull('deleted_at')->lockForUpdate()->first() ?? throw new CheckoutRefused;

            return $work();
        });
    }

    private static function key(mixed $value): int
    {
        $key = self::writtenKey($value);

        return $key !== null && $key > 0 ? $key : throw new CheckoutRefused;
    }

    private static function identifier(mixed $value, string $prefix, int $max = 100): bool
    {
        return is_string($value) && strlen($value) <= $max && preg_match('/\A'.preg_quote($prefix, '/').'[A-Za-z0-9_]+\z/D', $value) === 1;
    }

    private static function hex(mixed $value, int $length): bool
    {
        return is_string($value) && strlen($value) === $length && preg_match('/\A[a-f0-9]+\z/D', $value) === 1;
    }

    private static function limit(int $limit): void
    {
        if ($limit < 1 || $limit > 1000) {
            throw new CheckoutRefused;
        }
    }

    private static function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->startOfSecond();
    }

    private static function stored(CarbonImmutable $time): string
    {
        return $time->utc()->startOfSecond()->format('Y-m-d H:i:s');
    }

    /** @template T
     * @param  Closure():T  $work
     * @return T
     */
    private function safe(Closure $work): mixed
    {
        try {
            return $work();
        } catch (CheckoutRefused $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new CheckoutRefused;
        }
    }
}
