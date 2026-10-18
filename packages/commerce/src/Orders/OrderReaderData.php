<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Orders;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Kitsune\Commerce\Carts\CartAmounts;
use Kitsune\Commerce\Carts\CartOwner;
use Kitsune\Commerce\Models\Sellable;
use Kitsune\Commerce\Money\MinorUnits;
use Kitsune\Commerce\Offers\OfferTerms;
use Kitsune\Commerce\Payments\AccessAdjustments;
use Kitsune\Commerce\Payments\AccessFulfillment;
use Kitsune\Commerce\Payments\CheckoutAttempts;
use Kitsune\Commerce\Payments\FinancialReadModel;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Commerce\Payments\StripeEventInbox;
use Kitsune\Commerce\Privacy\OrderPrivacy;
use Kitsune\Commerce\Privacy\PrivacyState;
use Kitsune\Commerce\Taxes\TaxAmounts;
use Kitsune\Core\Auth\EmailAddress;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Currency\Currencies;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Readers\Contracts\ModuleReaderData;
use Kitsune\Core\Tenancy\Concerns\ReadsWrittenKeys;
use Kitsune\Core\Tenancy\Context;
use RuntimeException;
use Throwable;

/** Explicit order projections and complete initial-state erasure, including disabled/stale custody. @internal */
final class OrderReaderData implements ModuleReaderData
{
    use ReadsWrittenKeys;

    public const MAX_LINES = 20;

    public const MAX_TOKENS = 20;

    private const MIGRATION = '0001_01_01_000205_create_commerce_orders';

    private const ORDER_COLUMNS = ['id', 'org_id', 'site_id', 'reference', 'reader_id', 'owner_key', 'contact_email',
        'cart_ref', 'cart_version', 'request_key', 'request_digest', 'snapshot_hash', 'active_cart_key', 'state',
        'currency', 'mode', 'tax_mode', 'billing', 'delivery', 'shipping', 'site_snapshot', 'quoted_item_net_minor',
        'quoted_shipping_net_minor', 'quoted_tax_minor', 'quoted_total_minor', 'confirmed_total_minor', 'created_at', 'cancelled_at'];

    private const LINE_COLUMNS = ['id', 'org_id', 'site_id', 'order_id', 'sellable_ref', 'listing_ref', 'entry_ref',
        'sku', 'title', 'quantity', 'unit_price_minor', 'tax_class', 'tax_rate_parts', 'delivery_kind', 'net_minor',
        'tax_minor', 'gross_minor', 'entitlement_name', 'offer_terms'];

    private const TOKEN_COLUMNS = ['id', 'org_id', 'site_id', 'order_id', 'token_hash', 'expires_at', 'created_at', 'consumed_at'];

    /** @var list<int> Successful physical deletions, captured by the same adapter's final erasure witness. */
    private array $deletedOrderIds = [];

    public function __construct(private ?OrderPrivacy $privacy = null) {}

    public function export(Connection $connection, int $orgId, #[\SensitiveParameter] string $reader): array
    {
        if (! $this->schemaAvailable($connection)) {
            return ['orders' => []];
        }

        $records = [];
        app(OrderClaims::class)->forReader($connection, $orgId, $reader);
        app(AccessFulfillment::class)->forReader($connection, $orgId, $reader);
        foreach ($this->orders($connection, $orgId, $reader)->orderBy('site_id')->orderBy('id')->get() as $order) {
            $this->readerOwnership($connection, $order, $reader);
            $records[] = $this->project($connection, $orgId, $order);
        }

        return ['orders' => $records];
    }

    public function erase(Connection $connection, int $orgId, #[\SensitiveParameter] string $reader): int
    {
        if (! $this->schemaAvailable($connection)) {
            return 0;
        }
        if ($connection->transactionLevel() < 1) {
            throw new RuntimeException('Order erasure requires the enclosing reader transaction.');
        }

        $this->lockSites($connection, $orgId);
        app(OrderClaims::class)->forReader($connection, $orgId, $reader, lock: true);
        app(AccessFulfillment::class)->forReader($connection, $orgId, $reader, lock: true);
        $orders = $this->orders($connection, $orgId, $reader)->orderBy('site_id')->orderBy('id')->lockForUpdate()->get();
        foreach ($orders as $order) {
            $this->readerOwnership($connection, $order, $reader, lock: true);
            $this->project($connection, $orgId, $order, lock: true);
        }
        $deleted = 0;
        $privacy = $this->privacy ??= app(OrderPrivacy::class);
        foreach ($orders as $order) {
            // Retained invoice/uncertain-provider custody is minimized before carts or the host account leave.
            // The module contract counts physically deleted parents; a retained minimized order contributes zero.
            $result = $privacy->eraseForSubject($connection, $order, $reader);
            if ($result['deleted']) {
                $deleted++;
                $this->deletedOrderIds[] = (int) $order->id;
            } elseif (! $result['minimized']) {
                $this->delete($connection, $orgId, $order);
                $deleted++;
                $this->deletedOrderIds[] = (int) $order->id;
            }
        }

        return $deleted;
    }

    /** The same instance retains exact producer IDs; reader rediscovery alone cannot prove detached data absent. */
    public function erasureVerification(Connection $connection, int $org, #[\SensitiveParameter] string $reader): Closure
    {
        $schema = $this->schemaAvailable($connection);
        $orders = ($this->privacy ??= app(OrderPrivacy::class))->verificationForSubject($connection, $org, $reader);
        $deleted = $this->deletedOrderIds;
        $this->deletedOrderIds = [];

        return function () use ($connection, $schema, $orders, $deleted): void {
            if ($this->schemaAvailable($connection) !== $schema) {
                throw new RuntimeException('The order data store changed during erasure.');
            }
            $orders();
            foreach (array_chunk($deleted, 100) as $ids) {
                // Parent IDs are globally unique. A callback cannot hide resurrection by changing its Org/reader stamp.
                if ($connection->table('commerce_orders')->useWritePdo()->whereIn('id', $ids)->exists()) {
                    throw new RuntimeException('An erased order parent reappeared.');
                }
            }
        };
    }

    /** Guest privacy is by this order's verified ownership, never by an email address alone.
     * @return array<string,mixed>
     */
    public function exportGuest(Request $request, mixed $reference): array
    {
        $order = app(OrderAccess::class)->owned($request, $reference)
            ?? throw new RuntimeException('The order privacy request is unavailable.');
        $connection = $order->getConnection();
        $row = $this->find($connection, (int) $order->org_id, (int) $order->site_id, $order->reference)
            ?? throw new RuntimeException('The order privacy request is unavailable.');

        return $this->project($connection, (int) $order->org_id, $row);
    }

    public function eraseGuest(Request $request, mixed $reference): int
    {
        $owned = app(OrderAccess::class)->owned($request, $reference)
            ?? throw new RuntimeException('The order privacy request is unavailable.');
        $connection = $owned->getConnection();

        return TransactionRecovery::run($connection, function () use ($request, $reference, $owned, $connection): int {
            $org = (int) $owned->org_id;
            $this->lockSites($connection, $org);
            // Ownership and the immutable row are re-read under the same locks as erasure.
            $current = app(OrderAccess::class)->owned($request, $reference)
                ?? throw new RuntimeException('The order privacy request is unavailable.');
            $row = $this->find($connection, $org, (int) $owned->site_id, $owned->reference, lock: true)
                ?? throw new RuntimeException('The order privacy request is unavailable.');
            if ((string) $current->getKey() !== (string) $row->id) {
                throw new RuntimeException('The order privacy ownership changed.');
            }
            $this->project($connection, $org, $row, lock: true);
            if ($row->state === 'completed_free' && $row->reader_id === null && app(OrderClaims::class)->forOrder($connection, $row, true) === null) {
                app(OrderPrivacy::class)->eraseFreeGuest($connection, $request, $row);
                $connection->afterCommit(static fn () => app(OrderAccess::class)->forget($request, $reference));

                return 0;
            }
            $this->delete($connection, $org, $row);
            $connection->afterCommit(static fn () => app(OrderAccess::class)->forget($request, $reference));

            return 1;
        });
    }

    /** Explicit single-order projection for authorized controllers/admin; pass raw model attributes as an object.
     * @return array<string,mixed>
     */
    public function record(Connection $connection, #[\SensitiveParameter] object $order): array
    {
        if (! $this->schemaAvailable($connection) || ($org = self::writtenKey($order->org_id ?? null)) === null || $org < 1) {
            throw new RuntimeException('The order privacy data is unavailable.');
        }

        return $this->project($connection, $org, $order, lock: $connection->transactionLevel() > 0);
    }

    /** Caller authorizes an owner and encloses Org→Site→Order locking plus its audit in one transaction. */
    public function eraseRecord(Connection $connection, #[\SensitiveParameter] object $order): int
    {
        $org = self::writtenKey($order->org_id ?? null);
        $site = self::writtenKey($order->site_id ?? null);
        $context = app(Context::class);
        if ($connection->transactionLevel() < 1 || $org === null || $site === null
            || $context->orgId() !== $org || $context->siteId() !== $site || ! self::hex($order->reference ?? null, 32)) {
            throw new RuntimeException('The order erasure request is unavailable.');
        }
        $row = $this->find($connection, $org, $site, $order->reference, lock: true)
            ?? throw new RuntimeException('The order erasure request is unavailable.');
        if (self::writtenKey($row->id) !== self::writtenKey($order->id ?? null)) {
            throw new RuntimeException('The order erasure request changed.');
        }
        $this->project($connection, $org, $row, lock: true);
        $this->delete($connection, $org, $row);

        return 1;
    }

    /** Internal raw custody read: it never trusts model casts or a read replica. */
    public function find(Connection $connection, int $org, int $site, string $reference, bool $lock = false): ?object
    {
        if (! $this->schemaAvailable($connection)) {
            return null;
        }
        $query = $connection->table('commerce_orders')->useWritePdo()->where('org_id', $org)
            ->where('site_id', $site)->where('reference', $reference);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /** @return array<string, mixed> Only this explicit historical projection may leave custody. */
    public function project(Connection $connection, int $org, #[\SensitiveParameter] object $order, bool $lock = false): array
    {
        try {
            return $this->checkedProjection($connection, $org, $order, $lock);
        } catch (Throwable) {
            // JSON, SQL, addresses and stored values never enter public errors or exception messages.
            throw new RuntimeException('The retained order data is inconsistent or unavailable.');
        }
    }

    public function schemaAvailable(Connection $connection): bool
    {
        $schema = $connection->getSchemaBuilder();
        $tables = ['commerce_orders' => self::ORDER_COLUMNS, 'commerce_order_lines' => self::LINE_COLUMNS,
            'commerce_order_access_tokens' => self::TOKEN_COLUMNS];
        $present = array_filter(array_keys($tables), $schema->hasTable(...));
        if ($present === []) {
            if ($connection->table('migrations')->useWritePdo()->where('migration', self::MIGRATION)->exists()) {
                throw new RuntimeException('The recorded order data store is missing.');
            }

            return false;
        }
        foreach ($tables as $table => $columns) {
            if (! $schema->hasTable($table) || ! $schema->hasColumns($table, $columns)) {
                throw new RuntimeException('The order privacy schema is incomplete.');
            }
        }

        return true;
    }

    /** @return array<string,mixed> */
    private function checkedProjection(Connection $connection, int $org, #[\SensitiveParameter] object $order, bool $lock): array
    {
        $orgQuery = $connection->table('orgs')->useWritePdo()->where('id', $org);
        $siteQuery = $connection->table('sites')->useWritePdo()->where('org_id', $org)->where('id', $order->site_id);
        $orgRow = ($lock ? $orgQuery->lockForUpdate() : $orgQuery)->first();
        $site = ($lock ? $siteQuery->lockForUpdate() : $siteQuery)->first();
        // Only the independent checked receipt authorizes a nullable or partial personal-data representation.
        // It seals the exact sanitized row; immutable purchase totals and line validation below remain mandatory.
        $privacy = app(PrivacyState::class)->checked($connection, $order, $lock);
        $subjectErased = $privacy !== null && $privacy['subject_erased'];
        $minimized = $subjectErased || ($privacy !== null && $privacy['invoice_expired']);
        if (self::writtenKey($order->id) === null || (int) $order->id < 1 || self::writtenKey($order->org_id) !== $org
            || self::writtenKey($order->site_id) === null || $site === null
            || ! self::hex($order->reference, 32) || ! self::hex($order->owner_key, 64)
            || ! self::hex($order->request_key, 64) || ! self::hex($order->request_digest, 64) || ! self::hex($order->snapshot_hash, 64)
            || ($subjectErased ? ($order->cart_ref !== null || $order->cart_version !== null)
                : (self::writtenKey($order->cart_ref) === null || (int) $order->cart_ref < 1
                    || self::writtenKey($order->cart_version) === null || (int) $order->cart_version < 0))
            || ! in_array($order->state, ['prepared', 'cancelled', 'paid', 'completed_free'], true)
            || (CompletionFacts::completed($order)
                ? ($order->active_cart_key !== null || MinorUnits::integer($order->confirmed_total_minor) !== MinorUnits::integer($order->quoted_total_minor))
                : $order->confirmed_total_minor !== null)
            || ! in_array($order->mode, ['test', 'live'], true) || ! Currencies::supports($order->currency) || $orgRow === null || $orgRow->currency !== $order->currency || ! in_array($order->tax_mode, ['inclusive', 'exclusive'], true)
            || ($minimized ? $order->contact_email !== null
                : (! is_string($order->contact_email) || EmailAddress::normalise($order->contact_email) !== $order->contact_email))
            || ($subjectErased && $order->reader_id !== null)
            || ($order->reader_id !== null && (! is_string($order->reader_id) || ReaderGuard::stored($order->reader_id) !== $order->reader_id
                || ! hash_equals(CartOwner::readerKey($order->reader_id), $order->owner_key)))
            || ($order->state === 'prepared' && (($subjectErased ? $order->active_cart_key !== null : ! self::hex($order->active_cart_key, 64)) || $order->cancelled_at !== null))
            || ($order->state === 'cancelled' && ($order->active_cart_key !== null || $order->cancelled_at === null))) {
            throw new RuntimeException('The retained order ownership or state is inconsistent.');
        }
        if ($order->state === 'prepared' && ! $subjectErased && ! hash_equals(hash('sha256', $order->owner_key."\0".$order->cart_ref."\0".$order->cart_version), $order->active_cart_key)) {
            throw new RuntimeException('The retained active order identity is inconsistent.');
        }
        $billing = $minimized ? app(PrivacyState::class)->billing($connection, $order) : BillingDetails::from(self::json($order->billing));
        $delivery = $order->delivery === null ? null : BillingDetails::from(self::json($order->delivery));
        if ($minimized && $delivery !== null) {
            throw new RuntimeException('The minimized order delivery is inconsistent.');
        }
        $siteSnapshot = self::json($order->site_snapshot);
        if (array_diff(array_keys($siteSnapshot), ['name', 'base_url']) !== []
            || ! self::text($siteSnapshot['name'] ?? null, 255) || ! self::text($siteSnapshot['base_url'] ?? null, 2048)) {
            throw new RuntimeException('The retained order site snapshot is inconsistent.');
        }
        $address = parse_url($siteSnapshot['base_url']);
        if (! is_array($address) || isset($address['user']) || isset($address['pass'])
            || isset($address['query']) || isset($address['fragment'])
            || (isset($address['scheme']) && (! in_array(strtolower($address['scheme']), ['http', 'https'], true) || ! isset($address['host'])))
            || (! isset($address['scheme']) && (! str_starts_with($siteSnapshot['base_url'], '/') || str_starts_with($siteSnapshot['base_url'], '//')))) {
            throw new RuntimeException('The retained public site address is inconsistent.');
        }
        $linesQuery = $connection->table('commerce_order_lines')->useWritePdo()->where('order_id', $order->id)->orderBy('id')->limit(self::MAX_LINES + 1);
        $tokensQuery = $connection->table('commerce_order_access_tokens')->useWritePdo()->where('order_id', $order->id)->orderBy('id')->limit(self::MAX_TOKENS + 1);
        $lines = ($lock ? $linesQuery->lockForUpdate() : $linesQuery)->get();
        $tokens = ($lock ? $tokensQuery->lockForUpdate() : $tokensQuery)->get();
        if ($lines->isEmpty() || $lines->count() > self::MAX_LINES || $tokens->count() > self::MAX_TOKENS) {
            throw new RuntimeException('The retained order children exceed custody bounds.');
        }
        $items = [];
        $itemNet = $itemTax = $itemGross = '0';
        $physical = false;
        foreach ($lines as $line) {
            $this->child($line, $order);
            if (! self::text($line->sku, 100) || ! self::text($line->title, 255) || ! self::text($line->tax_class, 64)
                || self::writtenKey($line->quantity) === null || (int) $line->quantity < 1 || (int) $line->quantity > 99
                || self::writtenKey($line->tax_rate_parts) === null || (int) $line->tax_rate_parts < 0 || (int) $line->tax_rate_parts > 1000000
                || ! in_array($line->delivery_kind, ['digital', 'physical'], true)
                || preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $line->tax_class) !== 1) {
                throw new RuntimeException('The retained order line is incompatible.');
            }
            Sellable::skuValue($line->sku);
            Sellable::entitlementNameValue($line->entitlement_name);
            $offer = OfferTerms::forMapping($line->entitlement_name, OfferTerms::stored($line->offer_terms));
            $references = [];
            foreach (['sellable_ref', 'listing_ref', 'entry_ref'] as $key) {
                $references[$key] = MinorUnits::integer($line->{$key});
                if ($references[$key] === '0') {
                    throw new RuntimeException('The retained order line reference is inconsistent.');
                }
            }
            $amount = CartAmounts::multiply(MinorUnits::integer($line->unit_price_minor), (int) $line->quantity);
            $tax = $order->tax_mode === 'inclusive' ? TaxAmounts::inclusive($amount, (int) $line->tax_rate_parts)
                : TaxAmounts::exclusive($amount, (int) $line->tax_rate_parts);
            foreach (['net', 'tax', 'gross'] as $part) {
                if ($tax[$part] !== MinorUnits::integer($line->{$part.'_minor'})) {
                    throw new RuntimeException('The retained order line totals are inconsistent.');
                }
            }
            $itemNet = CartAmounts::add($itemNet, $tax['net']);
            $itemTax = CartAmounts::add($itemTax, $tax['tax']);
            $itemGross = CartAmounts::add($itemGross, $tax['gross']);
            $physical = $physical || $line->delivery_kind === 'physical';
            $items[] = [...$references, 'sku' => $line->sku, 'title' => $line->title, 'quantity' => (int) $line->quantity,
                'unit_price_minor' => MinorUnits::integer($line->unit_price_minor), 'tax_class' => $line->tax_class,
                'tax_rate_parts' => (int) $line->tax_rate_parts, 'delivery_kind' => $line->delivery_kind,
                'net_minor' => MinorUnits::integer($line->net_minor), 'tax_minor' => MinorUnits::integer($line->tax_minor),
                'gross_minor' => MinorUnits::integer($line->gross_minor), 'entitlement_name' => $line->entitlement_name, 'offer_terms' => $offer];
        }
        if (($order->shipping !== null) !== $physical || (! $minimized && ($delivery !== null) !== $physical)) {
            throw new RuntimeException('The retained order delivery is inconsistent.');
        }
        $shipping = $order->shipping === null ? null : self::shipping(self::json($order->shipping), $order->currency, $order->mode);
        $shippingNet = $shipping['net'] ?? '0';
        $totals = ['quoted_item_net_minor' => $itemNet, 'quoted_shipping_net_minor' => $shippingNet,
            'quoted_tax_minor' => CartAmounts::add($itemTax, $shipping['tax'] ?? '0'),
            'quoted_total_minor' => CartAmounts::add($itemGross, $shipping['gross'] ?? '0')];
        foreach ($totals as $key => $expected) {
            if (MinorUnits::integer($order->{$key}) !== $expected) {
                throw new RuntimeException('The retained order totals are inconsistent.');
            }
        }
        $links = [];
        foreach ($tokens as $token) {
            $this->child($token, $order);
            if (! self::hex($token->token_hash, 64)) {
                throw new RuntimeException('The retained order link is malformed.');
            }
            $links[] = ['expires_at' => self::instant($token->expires_at), 'created_at' => self::instant($token->created_at),
                'consumed_at' => $token->consumed_at === null ? null : self::instant($token->consumed_at)];
        }

        $claim = app(OrderClaims::class)->forOrder($connection, $order, lock: $lock);

        return ['reference' => $order->reference, 'site' => $site->handle, 'state' => $order->state,
            'contact_email' => $order->contact_email, 'billing' => $billing, 'delivery' => $delivery,
            'shipping' => $shipping,
            'site_snapshot' => ['name' => $siteSnapshot['name'], 'base_url' => $siteSnapshot['base_url']],
            'currency' => $order->currency, 'mode' => $order->mode, 'tax_mode' => $order->tax_mode,
            ...$totals, 'confirmed_total_minor' => $order->confirmed_total_minor === null ? null : MinorUnits::integer($order->confirmed_total_minor),
            'created_at' => self::instant($order->created_at), 'cancelled_at' => $order->cancelled_at === null ? null : self::instant($order->cancelled_at),
            'lines' => $items, 'access_links' => $links,
            'checkout_attempts' => app(CheckoutAttempts::class)->projection($connection, $org, $order, lock: $lock),
            'stripe_events' => app(StripeEventInbox::class)->projection($connection, $org, $order, lock: $lock),
            ...app(PaidOrders::class)->projection($connection, $org, $order, lock: $lock),
            'access_claim' => $claim === null ? null : ['claimed_at' => self::instant($claim->claimed_at)],
            ...app(AccessFulfillment::class)->projection($connection, $org, $order, lock: $lock),
            ...app(FinancialReadModel::class)->projection($connection, $org, $order, lock: $lock),
            ...app(AccessAdjustments::class)->projection($connection, $org, $order, lock: $lock),
            ...app(FreeAccessCorrections::class)->projection($connection, $order, $lock),
            ...app(HistoricalAccess::class)->projection($connection, $order, $lock),
            ...app(PrivacyState::class)->projection($connection, $order, $lock)];
    }

    private function orders(Connection $connection, int $org, #[\SensitiveParameter] string $reader): Builder
    {
        $claims = app(OrderClaims::class)->schemaAvailable($connection);
        $access = app(AccessFulfillment::class)->schemaAvailable($connection);

        return $connection->table('commerce_orders')->useWritePdo()->where('org_id', $org)
            ->where(function (Builder $owned) use ($reader, $claims, $access): void {
                $owned->where('reader_id', $reader)->orWhere('owner_key', CartOwner::readerKey($reader));
                if ($claims) {
                    // Admit a candidate even when its claim's child scope was forged; strict raw validation below
                    // then refuses the malformed graph instead of silently omitting retained reader custody.
                    $owned->orWhereExists(fn (Builder $claim) => $claim->selectRaw('1')->from(OrderClaims::TABLE)
                        ->whereColumn(OrderClaims::TABLE.'.order_id', 'commerce_orders.id')->where('reader_id', $reader));
                }
                if ($access) {
                    // This independent receipt keeps custody discoverable if a claim's reader field is damaged.
                    $owned->orWhereExists(fn (Builder $work) => $work->selectRaw('1')->from(AccessFulfillment::WORK)
                        ->whereColumn(AccessFulfillment::WORK.'.order_id', 'commerce_orders.id')->where('claim_reader_id', $reader));
                }
            });
    }

    private function readerOwnership(Connection $connection, #[\SensitiveParameter] object $order, #[\SensitiveParameter] string $reader, bool $lock = false): void
    {
        $claim = app(OrderClaims::class)->forOrder($connection, $order, lock: $lock);
        if ($order->reader_id === null && $claim !== null && $claim->reader_id === $reader) {
            return;
        }
        if (! is_string($order->reader_id) || $order->reader_id !== $reader
            || ! is_string($order->owner_key) || ! hash_equals(CartOwner::readerKey($reader), $order->owner_key)) {
            throw new RuntimeException('The retained order reader ownership is inconsistent.');
        }
    }

    private function lockSites(Connection $connection, int $org): void
    {
        if ($connection->getDriverName() === 'sqlite') {
            $connection->table('orgs')->where('id', $org)->update(['id' => $org]);
        }
        $connection->table('orgs')->useWritePdo()->where('id', $org)->lockForUpdate()->first()
            ?? throw new RuntimeException('The order privacy org is unavailable.');
        $connection->table('sites')->useWritePdo()->where('org_id', $org)->orderBy('id')->lockForUpdate()->get();
    }

    private function delete(Connection $connection, int $org, #[\SensitiveParameter] object $order): void
    {
        // Paid and refund obligations remain in custody until #203 supplies an explicit retention/minimization policy.
        // This also runs for disabled/stale-module account and guest erasure, independently of runtime providers.
        app(PaidOrders::class)->assertErasable($connection, $order);
        // A remote test Session can still be open or uncertain. Its recovery evidence must outlive this request.
        app(CheckoutAttempts::class)->deleteTerminal($connection, $org, $order);
        app(AccessAdjustments::class)->deleteUnpaid($connection, $order);
        app(FinancialReadModel::class)->deleteUnpaid($connection, $order);
        app(AccessFulfillment::class)->deleteUnpaid($connection, $order);
        app(PrivacyState::class)->deleteUnpaid($connection, $order);
        foreach (['commerce_order_access_tokens', 'commerce_order_lines'] as $table) {
            $expected = $connection->table($table)->useWritePdo()->where('order_id', $order->id)->count();
            if ($connection->table($table)->where('org_id', $org)->where('site_id', $order->site_id)->where('order_id', $order->id)->delete() !== $expected) {
                throw new RuntimeException('The retained order child erasure was cancelled.');
            }
        }
        if ($connection->table('commerce_orders')->where('org_id', $org)->where('site_id', $order->site_id)
            ->where('id', $order->id)->where('reference', $order->reference)->where('owner_key', $order->owner_key)->delete() !== 1) {
            throw new RuntimeException('The retained order erasure was cancelled.');
        }
    }

    private function child(#[\SensitiveParameter] object $child, #[\SensitiveParameter] object $order): void
    {
        if (self::writtenKey($child->id) === null || (int) $child->id < 1
            || self::writtenKey($child->org_id) !== self::writtenKey($order->org_id)
            || self::writtenKey($child->site_id) !== self::writtenKey($order->site_id)
            || self::writtenKey($child->order_id) !== self::writtenKey($order->id)) {
            throw new RuntimeException('The retained order child ownership is inconsistent.');
        }
    }

    /** @param array<string,mixed> $value
     * @return array<string,mixed>
     */
    private static function shipping(#[\SensitiveParameter] array $value, string $currency, string $mode): array
    {
        // Only the normalized frozen quote contract, never a carrier/provider response.
        $keys = ['id', 'carrier', 'service', 'amountMinor', 'currency', 'shipmentId', 'test',
            'taxClass', 'taxMode', 'taxRateParts', 'net', 'tax', 'gross'];
        if (array_key_exists('international', $value)) {
            if ($value['international'] !== true) {
                throw new RuntimeException('The retained international shipping policy is incompatible.');
            }
            $keys[] = 'international';
        }
        if (array_diff(array_keys($value), $keys) !== [] || array_diff($keys, array_keys($value)) !== []
            || ! self::text($value['id'], 100) || ! self::text($value['shipmentId'], 100)
            || ! self::text($value['carrier'], 200) || ! self::text($value['service'], 200)
            || $value['currency'] !== $currency || ! is_bool($value['test']) || $value['test'] !== ($mode === 'test') || ! self::text($value['taxClass'], 64)
            || preg_match('/\A[A-Za-z0-9_-]+\z/D', $value['id']) !== 1 || preg_match('/\A[A-Za-z0-9_-]+\z/D', $value['shipmentId']) !== 1
            || preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $value['taxClass']) !== 1
            || ! in_array($value['taxMode'], ['inclusive', 'exclusive'], true)
            || ! is_int($value['taxRateParts']) || $value['taxRateParts'] < 0 || $value['taxRateParts'] > 1000000) {
            throw new RuntimeException('The retained shipping quote is incompatible.');
        }
        $amount = MinorUnits::integer($value['amountMinor']);
        $tax = $value['taxMode'] === 'inclusive' ? TaxAmounts::inclusive($amount, $value['taxRateParts'])
            : TaxAmounts::exclusive($amount, $value['taxRateParts']);
        foreach ($tax as $key => $expected) {
            if (MinorUnits::integer($value[$key]) !== $expected) {
                throw new RuntimeException('The retained shipping quote is inconsistent.');
            }
        }

        return ['id' => $value['id'], 'carrier' => $value['carrier'], 'service' => $value['service'],
            'amountMinor' => $amount, 'currency' => $currency, 'shipmentId' => $value['shipmentId'], 'test' => $value['test'],
            'taxClass' => $value['taxClass'], 'taxMode' => $value['taxMode'], 'taxRateParts' => $value['taxRateParts'], ...$tax,
            ...(isset($value['international']) ? ['international' => true] : [])];
    }

    /** @return array<string,mixed> */
    private static function json(#[\SensitiveParameter] mixed $value): array
    {
        if (! is_string($value) || strlen($value) > 65536) {
            throw new RuntimeException('The retained order snapshot is malformed.');
        }
        $decoded = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('The retained order snapshot is malformed.');
        }

        return $decoded;
    }

    private static function text(#[\SensitiveParameter] mixed $value, int $maximum): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= $maximum * 4
            && mb_check_encoding($value, 'UTF-8') && mb_strlen($value, 'UTF-8') <= $maximum
            && preg_match('/[\p{Cc}\x{2028}\x{2029}]/u', $value) !== 1;
    }

    private static function hex(#[\SensitiveParameter] mixed $value, int $length): bool
    {
        return is_string($value) && strlen($value) === $length && preg_match('/\A[a-f0-9]+\z/D', $value) === 1;
    }

    private static function instant(#[\SensitiveParameter] mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D', $value) !== 1) {
            throw new RuntimeException('The retained order instant is malformed.');
        }
        $instant = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $value, 'UTC');
        if (! $instant instanceof CarbonImmutable || $instant->format('Y-m-d H:i:s') !== $value) {
            throw new RuntimeException('The retained order instant is malformed.');
        }

        return $instant->format('Y-m-d\TH:i:s\Z');
    }
}
