<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Orders;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Kitsune\Commerce\Models\Order;
use Kitsune\Commerce\Offers\OfferTerms;
use Kitsune\Commerce\Payments\AccessAdjustments;
use Kitsune\Commerce\Payments\AccessFulfillment;
use Kitsune\Commerce\Payments\CheckoutAttempts;
use Kitsune\Commerce\Payments\FinancialState;
use Kitsune\Commerce\Payments\PaidConfirmationAudit;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Commerce\Privacy\PrivacyState;
use Kitsune\Core\Audit\Auditor;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Tenancy\Context;
use Throwable;

/** Separate reviewed decisions about missing historical terms. Never rewrites the original purchase. @internal */
final class HistoricalAccess
{
    public const WORK = 'commerce_access_review_work';

    public const TABLE = 'commerce_access_reviews';

    public const MAX = 1000;

    public function __construct(private readonly Context $context, private readonly OrderLocks $locks, private readonly Auditor $auditor) {}

    public static function genesis(object $order): string
    {
        return CheckoutAttempts::digest(['access_review' => 1, 'org' => (string) $order->org_id,
            'site' => (string) $order->site_id, 'order' => (string) $order->id, 'reference' => $order->reference]);
    }

    public function prepare(Connection $connection, object $order): void
    {
        $this->inside($connection, $order);
        if ($connection->table(self::WORK)->useWritePdo()->where('order_id', $order->id)->exists()) {
            throw new OrderRefused;
        }
        $connection->table(self::WORK)->insert(['org_id' => $order->org_id, 'site_id' => $order->site_id,
            'order_id' => $order->id, 'revision' => 0, 'head_hash' => self::genesis($order)]);
    }

    /** @param array<string,mixed>|null $terms */
    public function decideAsOwner(string $reference, int $line, string $decision, ?array $terms, int $revision,
        #[\SensitiveParameter] string $reason, #[\SensitiveParameter] string $evidence, string $requestKey, bool $confirmed): string
    {
        if (! $confirmed || $line < 1 || $revision < 0 || $revision >= self::MAX
            || preg_match('/\A[a-f0-9]{32}\z/D', $reference) !== 1 || preg_match('/\A[a-f0-9]{32}\z/D', $requestKey) !== 1) {
            throw new OrderRefused;
        }
        try {
            $terms = self::terms($decision, $terms);
        } catch (Throwable) {
            throw new OrderRefused;
        }
        self::text($reason, 500);
        self::text($evidence, 255);
        try {
            return $this->locks->run(function () use ($reference, $line, $decision, $terms, $revision, $reason, $evidence, $requestKey): string {
                $connection = (new Order)->getConnection();
                $actor = $this->authority($connection);
                $order = $connection->table('commerce_orders')->useWritePdo()->where('org_id', $this->context->orgId())
                    ->where('site_id', $this->context->siteId())->where('reference', $reference)->lockForUpdate()->first() ?? throw new OrderRefused;
                $this->inside($connection, $order);
                app(PrivacyState::class)->assertAccessAllowed($connection, $order);
                $privacy = app(PrivacyState::class)->checked($connection, $order, true);
                if (! CompletionFacts::completed($order)) {
                    throw new OrderRefused;
                }
                $access = $connection->table(AccessFulfillment::WORK)->useWritePdo()->where('order_id', $order->id)->lockForUpdate()->first();
                if ($access?->state === 'initializing') {
                    app(AccessFulfillment::class)->freeze($connection, $order);
                }
                // Prove the original allocation and immutable manifest before consulting owner input.
                app(AccessFulfillment::class)->projection($connection, (int) $order->org_id, $order, true);
                $plan = $this->plan($connection, $order, true);
                $notes = ['actor_type' => $actor['type'], 'actor_id' => $actor['id'], 'reason' => $reason, 'evidence' => $evidence];
                $notesHash = CheckoutAttempts::digest($notes);
                foreach ($plan['history'] as $old) {
                    if ($old->request_key === $requestKey) {
                        if ((int) $old->revision !== $revision + 1 || (int) $old->line_id !== $line || $old->decision !== $decision
                            || self::decodeTerms($old) !== $terms || $old->notes_hash !== $notesHash) {
                            throw new OrderRefused('stale');
                        }

                        return $old->reference;
                    }
                }
                if ($plan['revision'] !== $revision || ! isset($plan['candidates'][$line])) {
                    throw new OrderRefused('stale');
                }
                $original = app(AccessFulfillment::class)->forAdjustment($connection, $order);
                $reader = $original['reader'];
                $names = $original['manifest'] === null ? [] : array_column($original['manifest']['definitions'], 'entitlement');
                $financial = $this->financialWitness($connection, $order);
                $producer = $this->producerWitness($connection, $order);
                $allAccess = $this->sourceWitness($connection, $order, $reader, []);
                $untouchedAccess = $this->sourceWitness($connection, $order, $reader, $names);
                $row = ['org_id' => $order->org_id, 'site_id' => $order->site_id, 'order_id' => $order->id,
                    'line_id' => $line, 'reference' => bin2hex(random_bytes(16)), 'request_key' => $requestKey,
                    'revision' => $revision + 1, 'decision' => $decision, 'terms' => $terms === null ? null : json_encode($terms, JSON_THROW_ON_ERROR),
                    ...$notes, 'notes_hash' => $notesHash, 'previous_hash' => $plan['head_hash'], 'created_at' => PrivacyState::now(), 'redacted_at' => null];
                if ($privacy['invoice_expired'] ?? false) {
                    $row = [...$row, 'actor_type' => null, 'actor_id' => null, 'reason' => null, 'evidence' => null, 'redacted_at' => PrivacyState::now()];
                }
                $row['record_hash'] = self::recordHash((object) $row);
                $connection->table(self::TABLE)->insert($row);
                if ($connection->table(self::WORK)->where('id', $plan['work']->id)->where('org_id', $order->org_id)
                    ->where('site_id', $order->site_id)->where('order_id', $order->id)->where('revision', $revision)->where('head_hash', $plan['head_hash'])
                    ->update(['revision' => $revision + 1, 'head_hash' => $row['record_hash']]) !== 1) {
                    throw new OrderRefused;
                }
                $this->plan($connection, $order, true);
                $graph = app(PaidConfirmationAudit::class)->witness($connection, $order);
                $model = (new Order)->newFromBuilder(get_object_vars($order));
                $action = 'commerce.historical_access_reviewed';
                $audit = $this->auditor->recordOrFail($action, $model);
                $stored = $audit->exists ? $connection->table('audit_log')->useWritePdo()->where('id', $audit->getKey())->lockForUpdate()->first() : null;
                if ($stored === null || (string) $stored->org_id !== (string) $order->org_id || (string) $stored->site_id !== (string) $order->site_id
                    || $stored->action !== $action || $stored->target_type !== $model->getMorphClass() || (string) $stored->target_id !== (string) $order->id
                    || $stored->actor_type !== $actor['type'] || $stored->actor_id !== $actor['id'] || $actor !== $this->authority($connection)
                    || $graph !== app(PaidConfirmationAudit::class)->witness($connection, $order)
                    || $financial !== $this->financialWitness($connection, $order)
                    || $producer !== $this->producerWitness($connection, $order)
                    || $allAccess !== $this->sourceWitness($connection, $order, $reader, [])) {
                    throw new OrderRefused;
                }
                // Explicit owner review can queue a never-performed/expired unknown effect. It cannot recreate a completed source.
                app(AccessFulfillment::class)->reviewed($connection, $order);
                app(AccessAdjustments::class)->reconcile($reference);
                app(AccessFulfillment::class)->reconcile($reference);
                app(AccessAdjustments::class)->reconcile($reference);
                if ($actor !== $this->authority($connection) || $this->plan($connection, $order, true)['head_hash'] !== $row['record_hash']
                    || $financial !== $this->financialWitness($connection, $order)
                    || $untouchedAccess !== $this->sourceWitness($connection, $order, $reader, $names)) {
                    throw new OrderRefused;
                }
                $final = $connection->table('audit_log')->useWritePdo()->where('id', $audit->getKey())->lockForUpdate()->first();
                if ($final === null || get_object_vars($final) !== get_object_vars($stored)) {
                    throw new OrderRefused;
                }

                return $row['reference'];
            });
        } catch (Throwable) {
            throw new OrderRefused;
        }
    }

    /** Raw checked custody also serves cold exports and erasure; never invokes an entitlement writer.
     * @return array<string,mixed> */
    public function plan(Connection $connection, object $order, bool $lock = false): array
    {
        $parents = $connection->table(self::WORK)->useWritePdo()->where('order_id', $order->id)->limit(2)
            ->when($lock, fn ($q) => $q->lockForUpdate())->get();
        if ($parents->count() !== 1) {
            throw new OrderRefused;
        }
        $work = $parents->sole();
        $this->child($work, $order);
        $revision = AccessFulfillment::counter($work->revision, self::MAX);
        $history = $connection->table(self::TABLE)->useWritePdo()->where('order_id', $order->id)->orderBy('revision')->limit(self::MAX + 1)
            ->when($lock, fn ($q) => $q->lockForUpdate())->get()->all();
        if (count($history) !== $revision) {
            throw new OrderRefused;
        }
        $lines = $connection->table('commerce_order_lines')->useWritePdo()->where('order_id', $order->id)->orderBy('id')->limit(21)
            ->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $allocations = $connection->table(PaidOrders::ALLOCATIONS)->useWritePdo()->where('order_id', $order->id)->limit(21)
            ->when($lock, fn ($q) => $q->lockForUpdate())->get()->keyBy('line_id');
        if ($lines->count() > 20 || $allocations->count() > 20) {
            throw new OrderRefused;
        }
        $candidates = [];
        foreach ($lines as $line) {
            $this->child($line, $order);
            $allocation = $allocations->get($line->id);
            if ($allocation !== null) {
                $this->child($allocation, $order);
                if ($line->entitlement_name !== null && $line->offer_terms === null && AccessFulfillment::counter($allocation->awarded, 99) > 0) {
                    $candidates[(int) $line->id] = ['name' => $line->entitlement_name, 'awarded' => (int) $allocation->awarded];
                }
            }
        }
        $head = self::genesis($order);
        $decisions = [];
        // Empty custody contains no private review notes to minimize; avoid a second full privacy projection.
        $privacy = $history === [] ? null : app(PrivacyState::class)->checked($connection, $order, $lock);
        $redacted = ($privacy['subject_erased'] ?? false) || ($privacy['invoice_expired'] ?? false);
        foreach ($history as $index => $row) {
            $this->child($row, $order);
            if ((int) $row->revision !== $index + 1 || ! isset($candidates[(int) $row->line_id])
                || $row->previous_hash !== $head || $row->record_hash !== self::recordHash($row)
                || preg_match('/\A[a-f0-9]{32}\z/D', $row->reference) !== 1 || preg_match('/\A[a-f0-9]{32}\z/D', $row->request_key) !== 1) {
                throw new OrderRefused;
            }
            if ($row->redacted_at !== null) {
                if (! $redacted || $row->reason !== null || $row->evidence !== null || $row->actor_type !== null || $row->actor_id !== null) {
                    throw new OrderRefused;
                }
                CheckoutAttempts::instant($row->redacted_at);
            } else {
                if ($redacted) {
                    throw new OrderRefused;
                }
                self::text($row->reason, 500);
                self::text($row->evidence, 255);
                if (! is_string($row->actor_type) || $row->actor_type === '' || ! is_string($row->actor_id) || $row->actor_id === ''
                    || $row->notes_hash !== CheckoutAttempts::digest(['actor_type' => $row->actor_type, 'actor_id' => $row->actor_id,
                        'reason' => $row->reason, 'evidence' => $row->evidence])) {
                    throw new OrderRefused;
                }
            }
            CheckoutAttempts::instant($row->created_at);
            $decisions[(int) $row->line_id] = ['decision' => $row->decision, 'terms' => self::decodeTerms($row)];
            $head = $row->record_hash;
        }
        if ($work->head_hash !== $head) {
            throw new OrderRefused;
        }

        return ['revision' => $revision, 'head_hash' => $head, 'history' => $history, 'work' => $work,
            'candidates' => $candidates, 'decisions' => $decisions];
    }

    /** Derive current reviewed inputs; never persist them over the immutable manifest.
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>
     */
    public function effective(Connection $connection, object $order, array $manifest, bool $lock = false): array
    {
        $plan = $this->plan($connection, $order, $lock);
        $awarded = array_column($manifest['allocations'], 'awarded', 'line_id');
        foreach ($manifest['lines'] as &$line) {
            $decision = $plan['decisions'][(int) $line['id']] ?? null;
            if ($decision === null) {
                continue;
            }
            if ($decision['decision'] === 'none') {
                $line['entitlement_name'] = null;
            } elseif ($decision['decision'] === 'award') {
                $line['offer_terms'] = json_encode($decision['terms'], JSON_THROW_ON_ERROR);
            }
        }
        unset($line);
        $manifest['review_excluded'] = [];
        foreach ($manifest['definitions'] as &$definition) {
            $definition['known'] = true;
            $definition['expires_at'] = null;
            $first = true;
            foreach ($manifest['lines'] as $line) {
                if ($line['entitlement_name'] !== $definition['entitlement'] || (int) ($awarded[$line['id']] ?? 0) === 0) {
                    continue;
                }
                $terms = OfferTerms::forMapping($line['entitlement_name'], OfferTerms::stored($line['offer_terms']));
                $end = $terms === null ? null : OfferTerms::resolve($terms, CheckoutAttempts::instant($manifest['confirmed_at']))?->format('Y-m-d H:i:s');
                $definition['known'] = $definition['known'] && $terms !== null;
                $old = $definition['expires_at'];
                $definition['expires_at'] = $first ? $end : ($old === null || $end === null ? null : max($old, $end));
                $first = false;
            }
            if ($first) {
                $manifest['review_excluded'][] = $definition['entitlement'];
            }
        }
        unset($definition);

        return $manifest;
    }

    /** Buyer-safe summary deliberately excludes private evidence, reasons, actors and source identifiers.
     * @return array<string,mixed> */
    public function projection(Connection $connection, object $order, bool $lock = false): array
    {
        $plan = $this->plan($connection, $order, $lock);

        return ['historical_access' => ['revision' => $plan['revision'], 'candidates' => $plan['candidates'],
            'decisions' => $plan['decisions'], 'history' => array_map(static fn (object $row): array => [
                'reference' => $row->reference, 'revision' => (int) $row->revision, 'line_id' => (int) $row->line_id,
                'decision' => $row->decision, 'terms' => self::decodeTerms($row), 'created_at' => $row->created_at], $plan['history'])]];
    }

    /** Owner-only private provenance; never merge this with buyer/export projections.
     * @return list<array<string,mixed>> */
    public function historyAsOwner(string $reference): array
    {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $reference) !== 1) {
            throw new OrderRefused;
        }

        return $this->locks->run(function () use ($reference): array {
            $connection = (new Order)->getConnection();
            $this->authority($connection);
            $order = $connection->table('commerce_orders')->useWritePdo()->where('org_id', $this->context->orgId())
                ->where('site_id', $this->context->siteId())->where('reference', $reference)->lockForUpdate()->first() ?? throw new OrderRefused;
            $plan = $this->plan($connection, $order, true);

            return $this->privateHistory($plan['history']);
        });
    }

    /** @param list<object> $history
     * @return list<array<string,mixed>>
     */
    private function privateHistory(array $history): array
    {
        $result = [];
        foreach (array_slice($history, -20) as $row) {
            $result[] = ['revision' => (int) $row->revision, 'decision' => $row->decision, 'line_id' => (int) $row->line_id,
                'created_at' => $row->created_at, 'reason' => $row->reason, 'evidence' => $row->evidence,
                'actor_type' => $row->actor_type, 'actor_id' => $row->actor_id];
        }

        return $result;
    }

    /** Notes/actor references have no invoice-retention purpose. Hash commitments retain minimal decision custody. */
    public function redact(Connection $connection, object $order): void
    {
        if ($connection !== (new Order)->getConnection() || $connection->transactionLevel() < 1 || ! $connection->getPdo()->inTransaction()) {
            throw new OrderRefused;
        }
        $plan = $this->plan($connection, $order, true);
        $changes = ['reason' => null, 'evidence' => null, 'actor_type' => null, 'actor_id' => null, 'redacted_at' => PrivacyState::now()];
        $expected = array_map(static fn (object $row): array => $row->redacted_at === null
            ? [...get_object_vars($row), ...$changes] : get_object_vars($row), $plan['history']);
        $connection->table(self::TABLE)->where('org_id', $order->org_id)->where('site_id', $order->site_id)->where('order_id', $order->id)
            ->whereNull('redacted_at')->update($changes);
        $actual = $connection->table(self::TABLE)->useWritePdo()->where('order_id', $order->id)->orderBy('revision')->limit(self::MAX + 1)
            ->lockForUpdate()->get()->map(get_object_vars(...))->all();
        $parents = $connection->table(self::WORK)->useWritePdo()->where('order_id', $order->id)->limit(2)->lockForUpdate()->get()->map(get_object_vars(...))->all();
        if ($actual !== $expected || $parents !== [get_object_vars($plan['work'])]) {
            throw new OrderRefused;
        }
    }

    private static function recordHash(object $row): string
    {
        $values = [];
        foreach (['org_id', 'site_id', 'order_id', 'line_id', 'reference', 'request_key', 'revision', 'decision', 'notes_hash', 'previous_hash', 'created_at'] as $key) {
            $values[$key] = (string) $row->{$key};
        }
        $values['terms'] = self::decodeTerms($row);

        return CheckoutAttempts::digest($values);
    }

    /** @return array<string,mixed>|null */
    private static function decodeTerms(object $row): ?array
    {
        return self::terms($row->decision, $row->terms === null ? null : json_decode($row->terms, true, flags: JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed>|null $terms
     * @return array<string,mixed>|null
     */
    private static function terms(string $decision, ?array $terms): ?array
    {
        if (! in_array($decision, ['hold', 'none', 'award'], true) || ($decision !== 'award' && $terms !== null)
            || ($decision === 'award' && $terms === null)) {
            throw new OrderRefused;
        }

        return $terms === null ? null : OfferTerms::stored(json_encode($terms, JSON_THROW_ON_ERROR));
    }

    private static function text(mixed $value, int $max): void
    {
        if (! is_string($value) || $value === '' || $value !== trim($value) || ! mb_check_encoding($value, 'UTF-8')
            || mb_strlen($value) > $max || preg_match('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]/u', $value) === 1) {
            throw new OrderRefused;
        }
    }

    private function inside(Connection $connection, object $order): void
    {
        if ($connection !== (new Order)->getConnection() || $connection->transactionLevel() < 1 || ! $connection->getPdo()->inTransaction()
            || $this->context->orgId() !== (int) $order->org_id || $this->context->siteId() !== (int) $order->site_id) {
            throw new OrderRefused;
        }
    }

    private function child(object $row, object $order): void
    {
        if ($this->context->orgId() !== (int) $order->org_id || (string) $row->org_id !== (string) $order->org_id
            || (string) $row->site_id !== (string) $order->site_id || (string) $row->order_id !== (string) $order->id) {
            throw new OrderRefused;
        }
    }

    /** @return array<string,mixed> */
    private function authority(Connection $connection): array
    {
        Permissions::forget();
        $actor = Permissions::currentUser();
        if (! $actor instanceof Model || ! Permissions::isOwner($actor) || $actor->getConnection() !== $connection) {
            throw new OrderRefused;
        }
        $row = $connection->table($actor->getTable())->useWritePdo()->where($actor->getKeyName(), $actor->getKey())->lockForUpdate()->first() ?? throw new OrderRefused;

        return ['type' => $actor->getMorphClass(), 'id' => (string) $actor->getAuthIdentifier(), 'row' => get_object_vars($row),
            'org' => $this->context->orgId(), 'site' => $this->context->siteId(), 'permissions' => Permissions::held($actor)];
    }

    private function producerWitness(Connection $connection, object $order): string
    {
        $rows = [];
        foreach ([AccessFulfillment::WORK => 1, AccessFulfillment::EFFECTS => 20,
            AccessAdjustments::WORK => 1, AccessAdjustments::EFFECTS => 20, 'commerce_order_claims' => 1] as $table => $bound) {
            $data = $connection->table($table)->useWritePdo()->where('order_id', $order->id)->orderBy('id')->limit($bound + 1)->lockForUpdate()->get();
            if ($data->count() > $bound) {
                throw new OrderRefused;
            }
            $rows[$table] = $data->map(get_object_vars(...))->all();
        }

        return CheckoutAttempts::digest($rows);
    }

    /** The review audit cannot change any source; eventual effects can change only this order's original names.
     * Stream the comparison so a reader's unrelated purchase history does not set the memory floor.
     *
     * @param  list<string>  $allowedNames
     */
    private function sourceWitness(Connection $connection, object $order, ?string $reader, array $allowedNames): string
    {
        // Unclaimed orders have no beneficiary and cannot reconcile an entitlement source. Never scan the Site.
        if ($reader === null) {
            return hash('sha256', '');
        }
        $query = $connection->table('entitlements')->useWritePdo()->where('org_id', $order->org_id)
            ->where('site_id', $order->site_id)->where('reader_id', $reader);
        if ($allowedNames !== []) {
            $query->where(fn ($q) => $q->where('source', '!=', 'commerce.order:'.$order->id)->orWhereNotIn('entitlement', $allowedNames));
        }
        $hash = hash_init('sha256');
        foreach ($query->orderBy('id')->lockForUpdate()->cursor() as $row) {
            hash_update($hash, json_encode(get_object_vars($row), JSON_THROW_ON_ERROR)."\n");
        }

        return hash_final($hash);
    }

    private function financialWitness(Connection $connection, object $order): string
    {
        $rows = ['order' => get_object_vars($connection->table('commerce_orders')->useWritePdo()->where('id', $order->id)->lockForUpdate()->sole())];
        foreach (['commerce_order_lines' => 20, PaidOrders::RECEIPTS => 1, PaidOrders::ALLOCATIONS => 20,
            PaidOrders::OBLIGATIONS => 1, PaidOrders::ATTEMPTS => PaidOrders::MAX_REFUND_ATTEMPTS, FreeOrders::TABLE => 1,
            FinancialState::WORK => 1, FinancialState::REFUNDS => 100, FinancialState::OBSERVATIONS => 240,
            PrivacyState::TABLE => 1, PrivacyState::TRANSITIONS => 1000, PrivacyState::HOLDS => 100, PrivacyState::DELIVERIES => 1] as $table => $bound) {
            $data = $connection->table($table)->useWritePdo()->where('order_id', $order->id)->orderBy('id')->limit($bound + 1)->lockForUpdate()->get();
            if ($data->count() > $bound) {
                throw new OrderRefused;
            }
            $rows[$table] = $data->map(get_object_vars(...))->all();
        }
        $rows['financial_plan'] = app(FinancialState::class)->accessPlan($connection, $order, true);
        $ids = array_column($rows['commerce_order_lines'], 'sellable_ref');
        $rows['products'] = $connection->table('commerce_sellables')->useWritePdo()->where('org_id', $order->org_id)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->map(get_object_vars(...))->all();
        $rows['stock'] = $connection->table('commerce_stock_movements')->useWritePdo()->where('org_id', $order->org_id)->where('order_reference', $order->reference)->orderBy('id')->lockForUpdate()->get()->map(get_object_vars(...))->all();

        return CheckoutAttempts::digest($rows);
    }
}
