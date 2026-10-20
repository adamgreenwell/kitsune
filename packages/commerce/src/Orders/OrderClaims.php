<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Orders;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Kitsune\Commerce\Models\Order;
use Kitsune\Commerce\Models\OrderClaim;
use Kitsune\Commerce\Payments\AccessAdjustments;
use Kitsune\Commerce\Payments\AccessFulfillment;
use Kitsune\Commerce\Payments\CheckoutAttempts;
use Kitsune\Commerce\Payments\FinancialState;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Commerce\Privacy\PrivacyState;
use Kitsune\Commerce\Schema\RequiredColumns;
use Kitsune\Core\Audit\Auditor;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Readers\Contracts\ReaderAccount;
use Kitsune\Core\Readers\ReaderAccounts;
use Kitsune\Core\Readers\ReaderLinks;
use Kitsune\Core\Readers\ReaderSessions;
use Kitsune\Core\Tenancy\Concerns\ReadsWrittenKeys;
use Kitsune\Core\Tenancy\Context;
use RuntimeException;
use Throwable;

/** One exact guest order, explicitly and permanently claimed by a fresh signed-in reader. @internal */
final class OrderClaims
{
    use ReadsWrittenKeys;

    public const TABLE = 'commerce_order_claims';

    private const MIGRATION = '0001_01_01_000212_create_commerce_order_claims';

    private const PENDING = ReaderSessions::EPHEMERAL.'.order_claim_pending';

    private const COLUMNS = ['id', 'org_id', 'site_id', 'order_id', 'reader_id', 'owner_key', 'order_reference', 'snapshot_hash', 'proof_kind', 'claimed_at'];

    public function __construct(private readonly Context $context) {}

    /** Retained custody is checked even while commerce is disabled; the host account need not still exist.
     * @return object|null A validated immutable receipt; no model casts authorize its reader.
     */
    public function forOrder(Connection $connection, object $order, bool $lock = false): ?object
    {
        if (! $this->schemaAvailable($connection)) {
            return null;
        }
        $query = $connection->table(self::TABLE)->useWritePdo()->where('order_id', $order->id)->orderBy('id')->limit(2);
        $rows = ($lock ? $query->lockForUpdate() : $query)->get();
        if ($rows->isEmpty()) {
            return null;
        }
        $row = $rows->first();
        if ($rows->count() !== 1 || self::writtenKey($row->id) === null || (int) $row->id < 1
            || self::writtenKey($order->id) === null || (int) $order->id < 1
            || self::writtenKey($order->org_id) === null || (int) $order->org_id < 1 || self::writtenKey($order->site_id) === null || (int) $order->site_id < 1
            || self::writtenKey($row->org_id) !== self::writtenKey($order->org_id) || self::writtenKey($row->site_id) !== self::writtenKey($order->site_id)
            || self::writtenKey($row->order_id) !== self::writtenKey($order->id) || $order->reader_id !== null || ! CompletionFacts::completed($order)
            || ! is_string($row->reader_id) || ReaderGuard::stored($row->reader_id) !== $row->reader_id
            || ! is_string($row->owner_key) || preg_match('/\A[a-f0-9]{64}\z/D', $row->owner_key) !== 1 || ! is_string($order->owner_key) || ! hash_equals($order->owner_key, $row->owner_key)
            || ! is_string($row->order_reference) || preg_match('/\A[a-f0-9]{32}\z/D', $row->order_reference) !== 1 || $row->order_reference !== $order->reference
            || ! is_string($row->snapshot_hash) || preg_match('/\A[a-f0-9]{64}\z/D', $row->snapshot_hash) !== 1 || ! is_string($order->snapshot_hash) || ! hash_equals($order->snapshot_hash, $row->snapshot_hash)
            || ! in_array($row->proof_kind, ['browser', 'email'], true)) {
            throw new RuntimeException('The retained order claim is inconsistent.');
        }
        if (! $connection->table('sites')->useWritePdo()->where('id', $order->site_id)->where('org_id', $order->org_id)->exists()) {
            throw new RuntimeException('The retained order claim site is inconsistent.');
        }
        $claimed = CheckoutAttempts::instant($row->claimed_at);
        if ($claimed->format('Y-m-d H:i:s') !== $row->claimed_at || $claimed->lessThan(CheckoutAttempts::instant($order->created_at))
            || $claimed->greaterThan(CarbonImmutable::now('UTC')->startOfSecond())) {
            throw new RuntimeException('The retained order claim time is inconsistent.');
        }

        return $row;
    }

    /** Every retained current-org claim participates in reader custody, including malformed foreign parents.
     * @return list<object>
     */
    public function forReader(Connection $connection, int $org, #[\SensitiveParameter] string $reader, bool $lock = false): array
    {
        if (! $this->schemaAvailable($connection)) {
            return [];
        }
        if (ReaderGuard::stored($reader) !== $reader) {
            throw new RuntimeException('The retained order claim reader is inconsistent.');
        }
        $query = $connection->table(self::TABLE)->useWritePdo()->where('org_id', $org)->where('reader_id', $reader)->orderBy('site_id')->orderBy('id');
        $rows = ($lock ? $query->lockForUpdate() : $query)->get();
        $checked = [];
        foreach ($rows as $row) {
            $query = $connection->table('commerce_orders')->useWritePdo()->where('id', $row->order_id);
            $order = ($lock ? $query->lockForUpdate() : $query)->first();
            if ($order === null || self::writtenKey($order->org_id) !== $org) {
                throw new RuntimeException('The retained order claim parent is inconsistent.');
            }
            $claim = $this->forOrder($connection, $order, $lock);
            if ($claim === null || (string) $claim->id !== (string) $row->id || $claim->reader_id !== $reader) {
                throw new RuntimeException('The retained order claim reader is inconsistent.');
            }
            $checked[] = $claim;
        }

        return $checked;
    }

    public function claimedReader(Connection $connection, object $order, bool $lock = false): ?string
    {
        return $this->forOrder($connection, $order, $lock)?->reader_id;
    }

    public function schemaAvailable(Connection $connection): bool
    {
        $schema = $connection->getSchemaBuilder();
        if (! $schema->hasTable(self::TABLE)) {
            if ($schema->hasTable('migrations') && $connection->table('migrations')->useWritePdo()->where('migration', self::MIGRATION)->exists()) {
                throw new RuntimeException('The retained order claim store is missing.');
            }

            return false;
        }
        if (! RequiredColumns::present($connection, self::TABLE, self::COLUMNS)) {
            throw new RuntimeException('The retained order claim schema is incomplete.');
        }

        return true;
    }

    /** The exact current-Site claim page is also an accepted reader sign-in continuation. */
    public function path(string $reference): string
    {
        $site = $this->context->site();
        if ($site === null || preg_match('/\A[a-f0-9]{32}\z/D', $reference) !== 1) {
            throw new OrderRefused;
        }

        return ReaderLinks::path($site, '/order-claims/'.$reference);
    }

    /** A non-authoritative reminder only; the claim POST always verifies original proof and reader again. */
    public function remember(Request $request, string $reference): void
    {
        $site = $this->context->site();
        if ($site === null || ! $request->hasSession()) {
            throw new OrderRefused;
        }
        $pending = $this->pending($request);
        $pending = array_values(array_filter($pending, static fn (array $row): bool => $row['reference'] !== $reference));
        $pending[] = ['reference' => $reference, 'path' => $this->path($reference), 'org' => $site->org_id, 'site' => $site->getKey(),
            'expires' => CarbonImmutable::now('UTC')->addMinutes(OrderAccess::GRANT_MINUTES)->timestamp];
        $request->session()->put(self::PENDING, array_slice($pending, -OrderAccess::MAX_SLOTS));
    }

    /** Bounded account-page reminders for the current Site, including after a successful registration.
     * @return list<array{reference:string,path:string,org:int,site:int,expires:int}>
     */
    public function pending(Request $request): array
    {
        if (! $request->hasSession() || $this->context->orgId() === null || $this->context->siteId() === null) {
            return [];
        }
        $held = $request->session()->get(self::PENDING);
        $valid = [];
        $now = CarbonImmutable::now('UTC')->timestamp;
        foreach (is_array($held) ? array_slice($held, -OrderAccess::MAX_SLOTS) : [] as $row) {
            if (is_array($row) && ($row['org'] ?? null) === $this->context->orgId() && ($row['site'] ?? null) === $this->context->siteId()
                && is_string($row['reference'] ?? null) && preg_match('/\A[a-f0-9]{32}\z/D', $row['reference']) === 1
                && ($row['path'] ?? null) === $this->path($row['reference']) && is_int($row['expires'] ?? null)
                && $row['expires'] > $now && $row['expires'] <= $now + OrderAccess::GRANT_MINUTES * 60) {
                $valid[] = ['reference' => $row['reference'], 'path' => $row['path'], 'org' => $row['org'], 'site' => $row['site'], 'expires' => $row['expires']];
            }
        }

        return $valid;
    }

    /** Persist ownership and trusted work only: this reader-facing door never grants an entitlement. */
    public function claim(Request $request, mixed $reference): Order
    {
        try {
            if (! is_string($reference) || preg_match('/\A[a-f0-9]{32}\z/D', $reference) !== 1 || ! $request->hasSession()) {
                throw new OrderRefused;
            }

            return app(OrderLocks::class)->run(function ($org, $site) use ($request, $reference): Order {
                $connection = (new Order)->getConnection();
                $accounts = app(ReaderAccounts::class);
                if ($accounts->fault() !== null || ! $accounts->mode($site)->signsIn()) {
                    throw new OrderRefused;
                }
                $module = $connection->table('modules')->useWritePdo()->where('handle', 'kitsune/commerce')->lockForUpdate()->first();
                if ($module === null || ! $module->is_enabled || $module->version !== ModuleDiscovery::version('kitsune/commerce')) {
                    throw new OrderRefused;
                }
                $order = app(OrderReaderData::class)->find($connection, $this->context->orgId() ?? throw new OrderRefused, $this->context->siteId() ?? throw new OrderRefused, $reference, true)
                    ?? throw new OrderRefused;
                app(OrderReaderData::class)->project($connection, (int) $order->org_id, $order, true);
                app(PrivacyState::class)->assertAccessAllowed($connection, $order);
                $reader = $this->freshReader($request, $connection);
                $key = app(ReaderGuard::class)->key($reader->getAuthIdentifier()) ?? throw new OrderRefused;
                $existing = $this->forOrder($connection, $order, true);
                if ($existing !== null) {
                    if ($existing->reader_id !== $key) {
                        throw new OrderRefused;
                    }

                    return (new Order)->newFromBuilder((array) $order, $connection->getName());
                }
                if (! CompletionFacts::completed($order) || $order->reader_id !== null || ! $this->schemaAvailable($connection)) {
                    throw new OrderRefused;
                }
                $kind = app(OrderAccess::class)->guestProof($request, $order) ?? throw new OrderRefused;
                $data = ['org_id' => (int) $order->org_id, 'site_id' => (int) $order->site_id, 'order_id' => (int) $order->id,
                    'reader_id' => $key, 'owner_key' => $order->owner_key, 'order_reference' => $order->reference,
                    'snapshot_hash' => $order->snapshot_hash, 'proof_kind' => $kind, 'claimed_at' => CarbonImmutable::now('UTC')->startOfSecond()->format('Y-m-d H:i:s')];
                $id = $connection->table(self::TABLE)->insertGetId($data);
                $claim = $this->forOrder($connection, $order, true) ?? throw new OrderRefused;
                if ((string) $claim->id !== (string) $id || array_diff_assoc($data, (array) $claim) !== []) {
                    throw new OrderRefused;
                }
                // Every old mailed token becomes unusable; immutable browser keys are fenced by this receipt.
                $tokens = $connection->table('commerce_order_access_tokens')->where('order_id', $order->id);
                $count = (clone $tokens)->useWritePdo()->count();
                if ($tokens->where('org_id', $order->org_id)->where('site_id', $order->site_id)->delete() !== $count) {
                    throw new OrderRefused;
                }
                app(AccessFulfillment::class)->claimed($connection, $order);
                app(AccessAdjustments::class)->claimed($connection, $order);
                $this->audit($connection, $request, $order, $claim, $reader);
                $freshModule = $connection->table('modules')->useWritePdo()->where('handle', 'kitsune/commerce')->lockForUpdate()->first();
                if ($freshModule === null || (array) $freshModule !== (array) $module) {
                    throw new OrderRefused;
                }
                $connection->afterCommit(function () use ($request, $reference): void {
                    app(OrderAccess::class)->forget($request, $reference);
                    $pending = $request->session()->get(self::PENDING);
                    if (is_array($pending)) {
                        $request->session()->put(self::PENDING, array_values(array_filter($pending,
                            static fn (mixed $row): bool => ! is_array($row) || ($row['reference'] ?? null) !== $reference)));
                    }
                });

                return (new Order)->newFromBuilder((array) $order, $connection->getName());
            });
        } catch (Throwable) {
            throw new OrderRefused;
        }
    }

    /** Also explicitly check the fresh row's session binding when the SessionGuard has cached its object.
     */
    public function freshReader(Request $request, Connection $connection): Model&Authenticatable&ReaderAccount
    {
        $siteQuery = Site::query()->useWritePdo()->where('org_id', $this->context->orgId())->whereKey($this->context->siteId());
        $site = ($connection->transactionLevel() > 0 ? $siteQuery->lockForUpdate() : $siteQuery)->first();
        $accounts = app(ReaderAccounts::class);
        if ($site === null || $accounts->fault() !== null || ! $accounts->mode($site)->signsIn()) {
            throw new OrderRefused;
        }
        $held = app(ReaderSessions::class)->current();
        $guard = app(ReaderGuard::class);
        $model = $guard->model();
        $key = $held !== null ? $guard->key($held->getAuthIdentifier()) : null;
        if ($held === null || $model === null || $key === null || $held->getConnection() !== $connection || ! $request->hasSession()) {
            throw new OrderRefused;
        }
        $query = $model::query()->useWritePdo()->whereKey(Permissions::userKey($key, $model))->where('org_id', $this->context->orgId());
        $fresh = ($connection->transactionLevel() > 0 ? $query->lockForUpdate() : $query)->first();
        $binding = $request->session()->get(ReaderSessions::BINDING);
        if (! $fresh instanceof Authenticatable || ! $fresh instanceof ReaderAccount || $guard->key($fresh->getAuthIdentifier()) !== $key
            || ! is_string($binding) || ! hash_equals(ReaderSessions::binding($fresh), $binding)) {
            throw new OrderRefused;
        }

        return $fresh;
    }

    private function audit(Connection $connection, Request $request, object $order, object $claim, Model&Authenticatable&ReaderAccount $reader): void
    {
        app(PrivacyState::class)->assertAccessAllowed($connection, $order);
        $graph = $this->graph($connection, $order);
        $context = $this->contextProof();
        $binding = ReaderSessions::binding($reader);
        $target = (new OrderClaim)->newFromBuilder((array) $claim, $connection->getName());
        // Readers deliberately stay out of the audit actor columns. Their proof-backed identity is in the receipt.
        $audit = app(Auditor::class)->recordForProducerOrFail('commerce.order_claimed', $target);
        $stored = $audit->exists ? $connection->table('audit_log')->useWritePdo()->where('id', $audit->getKey())->lockForUpdate()->first() : null;
        if ($stored === null || (string) $stored->org_id !== (string) $order->org_id || (string) $stored->site_id !== (string) $order->site_id
            || $stored->actor_type !== null || $stored->actor_id !== null || $stored->action !== 'commerce.order_claimed'
            || $stored->target_type !== $target->getMorphClass() || (string) $stored->target_id !== (string) $claim->id
            || ! hash_equals($context, $this->contextProof())
            || app(ReaderGuard::class)->key($this->freshReader($request, $connection)->getAuthIdentifier()) !== $claim->reader_id
            || ! hash_equals($binding, ReaderSessions::binding($this->freshReader($request, $connection)))
            || ! hash_equals($graph, $this->graph($connection, $order))) {
            throw new OrderRefused;
        }
    }

    private function contextProof(): string
    {
        return CheckoutAttempts::digest(['org' => $this->context->org()?->getAttributes(), 'site' => $this->context->site()?->getAttributes(),
            'orgId' => $this->context->orgId(), 'siteId' => $this->context->siteId()]);
    }

    private function graph(Connection $connection, object $expected): string
    {
        $order = $connection->table('commerce_orders')->useWritePdo()->where('id', $expected->id)->lockForUpdate()->first() ?? throw new OrderRefused;
        if ((array) $order !== (array) $expected) {
            throw new OrderRefused;
        }
        app(PrivacyState::class)->assertAccessAllowed($connection, $order);
        $rows = ['order' => (array) $order, 'claim' => (array) $this->forOrder($connection, $order, true),
            'org' => (array) $connection->table('orgs')->useWritePdo()->where('id', $order->org_id)->lockForUpdate()->first(),
            'site' => (array) $connection->table('sites')->useWritePdo()->where('id', $order->site_id)->where('org_id', $order->org_id)->lockForUpdate()->first()];
        foreach (['commerce_order_lines' => OrderReaderData::MAX_LINES, 'commerce_order_access_tokens' => OrderReaderData::MAX_TOKENS,
            CheckoutAttempts::TABLE => CheckoutAttempts::MAX_ATTEMPTS, FreeOrders::TABLE => 1, FreeAccessCorrections::WORK => 1, FreeAccessCorrections::TABLE => 1000,
            PaidOrders::RECEIPTS => 1, PaidOrders::ALLOCATIONS => OrderReaderData::MAX_LINES,
            PaidOrders::OBLIGATIONS => 1, PaidOrders::ATTEMPTS => PaidOrders::MAX_REFUND_ATTEMPTS,
            AccessFulfillment::WORK => 1, AccessFulfillment::EFFECTS => AccessFulfillment::MAX_EFFECTS,
            FinancialState::WORK => 1, FinancialState::REFUNDS => FinancialState::MAX_REFUNDS,
            FinancialState::OBSERVATIONS => FinancialState::MAX_OBSERVATIONS,
            AccessAdjustments::WORK => 1, AccessAdjustments::EFFECTS => AccessFulfillment::MAX_EFFECTS] as $table => $bound) {
            $children = $connection->table($table)->useWritePdo()->where('order_id', $order->id)->orderBy('id')->limit($bound + 1)->lockForUpdate()->get();
            if ($children->count() > $bound) {
                throw new OrderRefused;
            }
            foreach ($children as $child) {
                if ((string) $child->org_id !== (string) $order->org_id || (string) $child->site_id !== (string) $order->site_id) {
                    throw new OrderRefused;
                }
            }
            $rows[$table] = $children->map(static fn (object $row): array => (array) $row)->all();
        }
        $encoded = json_encode($rows, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 4194304) {
            throw new OrderRefused;
        }

        return hash('sha256', $encoded);
    }
}
