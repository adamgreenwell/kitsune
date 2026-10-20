<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Payments;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Kitsune\Commerce\Models\Order;
use Kitsune\Commerce\Offers\OfferTerms;
use Kitsune\Commerce\Orders\CompletionFacts;
use Kitsune\Commerce\Orders\FreeAccessCorrections;
use Kitsune\Commerce\Orders\FreeOrders;
use Kitsune\Commerce\Orders\HistoricalAccess;
use Kitsune\Commerce\Orders\OrderClaims;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Privacy\PrivacyState;
use Kitsune\Commerce\Schema\RequiredColumns;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Entitlements\EntitlementRefusal;
use Kitsune\Core\Entitlements\EntitlementRefused;
use Kitsune\Core\Entitlements\EntitlementWriter;
use Kitsune\Core\Entitlements\GrantOutcome;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Readers\ReaderSessions;
use Kitsune\Core\Tenancy\Concerns\ReadsWrittenKeys;
use Kitsune\Core\Tenancy\Context;
use Throwable;

/** Frozen order-source grants, independently recoverable after money and stock have committed. @internal */
final class AccessFulfillment
{
    use ReadsWrittenKeys;

    public const WORK = 'commerce_access_work';

    public const EFFECTS = 'commerce_access_effects';

    public const MAX_EFFECTS = 20;

    private const MIGRATION = '0001_01_01_000213_create_commerce_access_fulfillment';

    public const WORK_COLUMNS = ['id', 'org_id', 'site_id', 'order_id', 'state', 'category', 'manifest', 'manifest_hash',
        'claim_binding', 'claim_hash', 'claim_reader_id', 'effect_count', 'replay_count', 'created_at', 'updated_at'];

    public const EFFECT_COLUMNS = ['id', 'org_id', 'site_id', 'order_id', 'work_id', 'entitlement', 'source', 'expires_at',
        'state', 'category', 'processed_at'];

    public function __construct(private readonly Context $context, private readonly OrderLocks $locks) {}

    /** The order writer creates the absence-detecting marker before exposing its first projection. */
    public function prepare(Connection $connection, #[\SensitiveParameter] object $order): void
    {
        $this->inside($connection, $order);
        if (! $this->schema($connection)) {
            throw new CheckoutRefused('setup');
        }
        if ($connection->table(self::WORK)->useWritePdo()->where('order_id', $order->id)->exists()
            || $connection->table(self::EFFECTS)->useWritePdo()->where('order_id', $order->id)->exists()
            || $order->state !== 'prepared' || $order->confirmed_total_minor !== null) {
            throw new CheckoutRefused('changed');
        }
        $connection->table(self::WORK)->insert(['org_id' => $order->org_id, 'site_id' => $order->site_id,
            'order_id' => $order->id, 'state' => 'awaiting_payment', 'category' => null, 'manifest' => null,
            'manifest_hash' => null, 'claim_binding' => null, 'claim_hash' => null, 'claim_reader_id' => null, 'effect_count' => 0,
            'replay_count' => 0, 'created_at' => $order->created_at, 'updated_at' => $order->created_at]);
        app(HistoricalAccess::class)->prepare($connection, $order);
    }

    /** Called inside paid confirmation, before its complete-graph audit. No grants belong in this transaction. */
    public function freeze(Connection $connection, #[\SensitiveParameter] object $order): void
    {
        $this->inside($connection, $order);
        $work = $this->parent($connection, $order, true);
        if (! in_array($work->state, ['awaiting_payment', 'initializing'], true) && ! ($work->state === 'suppressed' && $work->manifest === null)) {
            $this->checked($connection, $order, true);

            return;
        }
        if ($work->manifest !== null || $work->effect_count != 0 || $this->effects($connection, $order, true) !== []) {
            throw new CheckoutRefused('changed');
        }
        $manifest = $this->manifest($connection, $order, true);
        foreach ($manifest['definitions'] as $definition) {
            $connection->table(self::EFFECTS)->insert(['org_id' => $order->org_id, 'site_id' => $order->site_id,
                'order_id' => $order->id, 'work_id' => $work->id, 'entitlement' => $definition['entitlement'],
                'source' => $definition['source'], 'expires_at' => $definition['expires_at'],
                'state' => $definition['known'] ? 'pending' : 'held',
                'category' => $definition['known'] ? null : 'unknown_terms', 'processed_at' => null]);
        }
        $claim = $this->claim($connection, $order, true);
        $erased = app(PrivacyState::class)->checked($connection, $order, true)['subject_erased'] ?? false;
        $state = match (true) {
            $erased => 'suppressed',
            $manifest['allocation_state'] === 'held' => 'held',
            $manifest['definitions'] === [] => 'processed',
            $order->reader_id === null && $claim === null => 'awaiting_claim',
            default => 'pending',
        };
        $category = $erased ? 'subject_erased' : ($state === 'held' ? 'financial_hold' : null);
        $this->update($connection, $order, $work, ['manifest' => self::encode($manifest),
            'manifest_hash' => CheckoutAttempts::digest($manifest), 'effect_count' => count($manifest['definitions']),
            'claim_binding' => $claim === null ? null : self::encode(get_object_vars($claim)),
            'claim_hash' => $claim === null ? null : CheckoutAttempts::digest(get_object_vars($claim)),
            'claim_reader_id' => $claim?->reader_id,
            'state' => $state, 'category' => $category]);
        app(PrivacyState::class)->freezePaid($connection, $order);
        $this->checked($connection, $order, true);
    }

    /** A reader's claim binds one durable beneficiary; it queues work but never invokes EntitlementWriter. */
    public function claimed(Connection $connection, #[\SensitiveParameter] object $order): void
    {
        $this->inside($connection, $order);
        app(PrivacyState::class)->assertAccessAllowed($connection, $order);
        $work = $this->parent($connection, $order, true);
        if ($order->reader_id !== null || ! CompletionFacts::completed($order)) {
            throw new CheckoutRefused('changed');
        }
        if ($work->state === 'initializing') {
            $this->freeze($connection, $order);
            $work = $this->parent($connection, $order, true);
        }
        $claim = $this->claim($connection, $order, true) ?? throw new CheckoutRefused('changed');
        $binding = get_object_vars($claim);
        if ($work->claim_binding !== null) {
            if (! hash_equals($work->claim_hash, CheckoutAttempts::digest($binding))) {
                throw new CheckoutRefused('changed');
            }
            $this->checked($connection, $order, true);

            return;
        }
        $this->update($connection, $order, $work, ['claim_binding' => self::encode($binding),
            'claim_hash' => CheckoutAttempts::digest($binding),
            'claim_reader_id' => $claim->reader_id,
            'state' => $work->state === 'awaiting_claim' ? 'pending' : $work->state]);
        $this->checked($connection, $order, true);
    }

    /** No reader request calls this door. The owner action and explicit/verified system recovery are its callers.
     * @return array{state:string,category:?string,processed:int}
     */
    public function reconcile(string $reference): array
    {
        $this->genericAuthority();

        return $this->process($reference);
    }

    /** The one reader-facing recovery door: exactly this authenticated reader's immutable guest claim.
     * @return array{state:string,category:?string,processed:int}
     */
    public function reconcileClaim(Request $request, string $reference): array
    {
        return $this->reconcileReader($request, $reference);
    }

    /** An authenticated reader retries only their original purchase or their immutable whole-order guest claim.
     * @return array{state:string,category:?string,processed:int}
     */
    public function reconcileReader(Request $request, string $reference): array
    {
        return $this->process($reference, $request);
    }

    /** @return array{state:string,category:?string,processed:int} */
    private function process(string $reference, ?Request $request = null): array
    {
        try {
            if (preg_match('/\A[a-f0-9]{32}\z/D', $reference) !== 1) {
                throw new CheckoutRefused;
            }

            return $this->locks->run(fn (): array => $this->processLocked($reference, $request));
        } catch (Throwable) {
            throw new CheckoutRefused;
        }
    }

    /** @return array{state:string,category:?string,processed:int} */
    private function processLocked(string $reference, ?Request $request): array
    {
        $connection = (new Order)->getConnection();
        $this->ready($connection);
        $order = $connection->table('commerce_orders')->useWritePdo()->where('org_id', $this->context->orgId())
            ->where('site_id', $this->context->siteId())->where('reference', $reference)->lockForUpdate()->first() ?? throw new CheckoutRefused;
        $privacy = app(PrivacyState::class)->checked($connection, $order, true);
        if ($privacy !== null && $privacy['subject_erased']) {
            if ($request !== null) {
                throw new CheckoutRefused;
            }
            $this->genericAuthority();
            [$suppressed] = $this->checked($connection, $order, true);

            return ['state' => $suppressed->state, 'category' => $suppressed->category, 'processed' => 0];
        }
        $authority = $this->admission($connection, $order, $request);
        $work = $this->parent($connection, $order, true);
        if ($work->state === 'initializing') {
            $this->freeze($connection, $order);
        }
        [$work, $effects] = $this->checked($connection, $order, true);
        $financial = CompletionFacts::completed($order) ? app(FinancialState::class)->accessPlan($connection, $order, true) : null;
        if (in_array($work->state, ['awaiting_payment', 'awaiting_claim', 'processed'], true)
            || $work->category === 'financial_hold') {
            return ['state' => $work->state, 'category' => $work->category, 'processed' => 0];
        }
        $effective = $work->manifest === null ? null : app(HistoricalAccess::class)->effective($connection, $order, self::decode($work->manifest), true);
        $contributions = $financial === null || $effective === null ? [] : array_column(
            AccessContributions::project($effective, $financial['removed_units'], $financial['blocked']),
            null, 'entitlement',
        );
        $filedReader = $order->reader_id ?? $this->claim($connection, $order, true)?->reader_id;
        if (! is_string($filedReader) || ReaderGuard::stored($filedReader) !== $filedReader) {
            throw new CheckoutRefused('changed');
        }
        $sourceRows = [];
        foreach ($effects as $effect) {
            if (! in_array($effect->state, ['pending', 'held'], true)) {
                $sourceRows[] = $this->sourceExpectation($connection, $order, $filedReader, $effect);
            }
        }
        $processed = 0;
        foreach ($effects as $effect) {
            $this->checkSourceRows($connection, $order, $sourceRows);
            if ($effect->state !== 'pending') {
                continue;
            }
            $contribution = $contributions[$effect->entitlement] ?? throw new CheckoutRefused('changed');
            if (in_array($effect->entitlement, $effective['review_excluded'] ?? [], true)) {
                $this->effectUpdate($connection, $order, $effect, 'omitted');
                $processed++;

                continue;
            }
            if ($contribution['suspended']) {
                // Keep unperformed work recoverable. A later verified failure may release the original contribution.
                // Merely revoking an absent source would let a later guest claim or payment replay create it.
                continue;
            }
            $end = $contribution['expires_at'] === null ? null : CheckoutAttempts::instant($contribution['expires_at']);
            if ($end !== null && $end->lessThanOrEqualTo(CarbonImmutable::now('UTC')->startOfSecond())) {
                $sourceRows[] = $this->sourceExpectation($connection, $order, $filedReader, $effect);
                $this->effectUpdate($connection, $order, $effect, 'expired');
                $processed++;

                continue;
            }
            $before = $this->custody($connection, $order);
            try {
                $reader = $this->beneficiary($connection, $order, true);
                $outcome = app(EntitlementWriter::class)->grantVerifiedOrder($reader, $effect->entitlement, $effect->source, $end,
                    function () use ($connection, $order, $before, $reader, $request, $authority, $financial, $sourceRows): void {
                        $this->ready($connection);
                        $this->inside($connection, $order);
                        $this->checked($connection, $order, true);
                        $this->checkSourceRows($connection, $order, $sourceRows);
                        if ($this->admission($connection, $order, $request) !== $authority
                            || $this->beneficiary($connection, $order, true) !== $reader
                            || ! hash_equals($financial['digest'], app(FinancialState::class)->accessPlan($connection, $order, true)['digest'])
                            || ! hash_equals($before, $this->custody($connection, $order))) {
                            throw new CheckoutRefused('changed');
                        }
                    });
                if (! hash_equals($before, $this->custody($connection, $order))) {
                    throw new CheckoutRefused('changed');
                }
                $this->checkSourceRows($connection, $order, $sourceRows);
                $source = $this->sourceExpectation($connection, $order, $reader, $effect);
                if ($source['row'] === null) {
                    throw new CheckoutRefused('changed');
                }
                $sourceRows[] = $source;
                $state = match ($outcome) {
                    GrantOutcome::Granted, GrantOutcome::Extended => 'granted',
                    GrantOutcome::Unchanged => 'unchanged',
                    GrantOutcome::StillRevoked => 'revoked',
                    default => throw new CheckoutRefused('changed'),
                };
                $this->effectUpdate($connection, $order, $effect, $state);
                $processed++;
            } catch (EntitlementRefused $failure) {
                $category = match ($failure->reason) {
                    EntitlementRefusal::Race, EntitlementRefusal::Database => 'retryable',
                    EntitlementRefusal::UnknownReader, EntitlementRefusal::NotAReader => 'unknown_reader',
                    EntitlementRefusal::NoReaderGuard => 'reader_guard',
                    default => 'grant_refused',
                };
                $this->checkSourceRows($connection, $order, $sourceRows);
                $this->update($connection, $order, $work, ['state' => 'held', 'category' => $category]);

                return ['state' => 'held', 'category' => $category, 'processed' => $processed];
            }
        }
        $effects = $this->effects($connection, $order, true);
        $pending = array_filter($effects, static fn (object $effect): bool => $effect->state === 'pending');
        $held = array_filter($effects, static fn (object $effect): bool => $effect->state === 'held');
        $state = $pending !== [] ? 'pending' : ($held !== [] ? 'held' : 'processed');
        $category = $held !== [] ? 'unknown_terms' : null;
        $this->update($connection, $order, $work, ['state' => $state, 'category' => $category]);
        $this->checked($connection, $order, true);
        $this->checkSourceRows($connection, $order, $sourceRows);

        return ['state' => $state, 'category' => $category, 'processed' => $processed];
    }

    /** Core fences the current write; later names must also preserve every earlier row or erased/expired absence.
     *
     * @return array{reader:string,entitlement:string,source:string,row:array<string,mixed>|null}
     */
    private function sourceExpectation(Connection $connection, #[\SensitiveParameter] object $order, #[\SensitiveParameter] string $reader, #[\SensitiveParameter] object $effect): array
    {
        return ['reader' => $reader, 'entitlement' => $effect->entitlement, 'source' => $effect->source,
            'row' => $this->sourceRow($connection, $order, $reader, $effect->entitlement, $effect->source)];
    }

    /** @param list<array{reader:string,entitlement:string,source:string,row:array<string,mixed>|null}> $expected */
    private function checkSourceRows(Connection $connection, #[\SensitiveParameter] object $order, #[\SensitiveParameter] array $expected): void
    {
        foreach ($expected as $source) {
            if ($this->sourceRow($connection, $order, $source['reader'], $source['entitlement'], $source['source']) !== $source['row']) {
                throw new CheckoutRefused('changed');
            }
        }
    }

    /** A raw primary read includes the opaque write identity; no model cache or timestamp-only comparison.
     *
     * @return array<string,mixed>|null
     */
    private function sourceRow(Connection $connection, #[\SensitiveParameter] object $order, #[\SensitiveParameter] string $reader, string $entitlement, #[\SensitiveParameter] string $source): ?array
    {
        $rows = $connection->table('entitlements')->useWritePdo()->where('site_id', $order->site_id)->where('reader_id', $reader)
            ->where('entitlement', $entitlement)->where('source', $source)->limit(2)->lockForUpdate()->get();
        if ($rows->count() > 1) {
            throw new CheckoutRefused('changed');
        }
        $row = $rows->first();
        if ($row !== null && (self::writtenKey($row->id) === null || (string) $row->org_id !== (string) $order->org_id)) {
            throw new CheckoutRefused('changed');
        }

        return $row === null ? null : get_object_vars($row);
    }

    /** Only the owner review transaction may queue a previously excluded/expired historical offer. */
    public function reviewed(Connection $connection, object $order): void
    {
        $this->inside($connection, $order);
        $this->genericAuthority();
        [$work, $effects, $manifest] = $this->checked($connection, $order, true);
        if ($manifest === null || $work->state === 'suppressed' || $manifest['allocation_state'] !== 'allocated') {
            throw new CheckoutRefused;
        }
        $effective = app(HistoricalAccess::class)->effective($connection, $order, $manifest, true);
        $definitions = array_column($effective['definitions'], null, 'entitlement');
        $originalDefinitions = array_column($manifest['definitions'], null, 'entitlement');
        $expectedEffects = [];
        foreach ($effects as $effect) {
            $expectedEffects[] = get_object_vars($effect);
            if ($originalDefinitions[$effect->entitlement]['known'] || ! in_array($effect->state, ['held', 'omitted', 'expired'], true) || ! $definitions[$effect->entitlement]['known']) {
                continue;
            }
            $connection->table(self::EFFECTS)->where('id', $effect->id)->where('org_id', $order->org_id)
                ->where('site_id', $order->site_id)->where('order_id', $order->id)
                ->update(['state' => 'pending', 'category' => null, 'processed_at' => null]);
            $expectedEffects[array_key_last($expectedEffects)] = [...get_object_vars($effect), 'state' => 'pending', 'category' => null, 'processed_at' => null];
        }
        $effects = $this->effects($connection, $order, true);
        $pending = array_filter($effects, static fn (object $effect): bool => $effect->state === 'pending') !== [];
        $held = array_filter($effects, static fn (object $effect): bool => $effect->state === 'held') !== [];
        $awaiting = ($pending || $held) && $order->reader_id === null && $this->claim($connection, $order, true) === null;
        $changes = ['state' => $awaiting ? 'awaiting_claim' : ($pending ? 'pending' : ($held ? 'held' : 'processed')),
            'category' => ! $awaiting && ! $pending && $held ? 'unknown_terms' : null,
            'updated_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s')];
        $connection->table(self::WORK)->where('id', $work->id)->where('org_id', $order->org_id)->where('site_id', $order->site_id)
            ->where('order_id', $order->id)->update($changes);
        if (get_object_vars($this->parent($connection, $order, true)) !== [...get_object_vars($work), ...$changes]
            || array_map(get_object_vars(...), $this->effects($connection, $order, true)) !== $expectedEffects) {
            throw new CheckoutRefused;
        }
        $this->checked($connection, $order, true);
    }

    /** Owner retry is a proof-only retry: no new beneficiary, offer, end or source can be supplied.
     * @return array{state:string,category:?string,processed:int}
     */
    public function retryAsOwner(string $reference): array
    {
        Permissions::forget();
        $actor = Permissions::currentUser();
        if ($actor === null || ! Permissions::isOwner($actor)) {
            throw new CheckoutRefused;
        }

        return $this->reconcile($reference);
    }

    /** Only the financial source-effect consumer needs the private original manifest and filed beneficiary.
     * It may revoke a deleted reader's source; the core reinstatement door independently requires a fresh reader.
     *
     * @return array{work:object,effects:list<object>,manifest:array<string,mixed>|null,reader:?string,custody:string}
     *
     * @internal
     */
    public function forAdjustment(Connection $connection, #[\SensitiveParameter] object $order): array
    {
        $this->inside($connection, $order);
        [$work, $effects, $manifest] = $this->checked($connection, $order, true);
        $manifest = $manifest === null ? null : app(HistoricalAccess::class)->effective($connection, $order, $manifest, true);
        $reader = $order->reader_id ?? $this->claim($connection, $order, true)?->reader_id;
        if ($reader !== null && (! is_string($reader) || ReaderGuard::stored($reader) !== $reader)) {
            throw new CheckoutRefused('changed');
        }

        return ['work' => $work, 'effects' => $effects, 'manifest' => $manifest,
            'reader' => $reader, 'custody' => $this->custody($connection, $order)];
    }

    private function genericAuthority(): string
    {
        Permissions::forget();
        $actor = Permissions::currentUser();
        if ($actor !== null && Permissions::isOwner($actor)) {
            return CheckoutAttempts::digest(['class' => $actor::class, 'id' => (string) $actor->getAuthIdentifier()]);
        }
        if ($actor !== null || app(ReaderGuard::class)->signedIn()) {
            throw new CheckoutRefused;
        }

        return 'system';
    }

    private function admission(Connection $connection, #[\SensitiveParameter] object $order, ?Request $request): string
    {
        app(PrivacyState::class)->assertAccessAllowed($connection, $order);
        if ($request === null) {
            return $this->genericAuthority();
        }
        $claim = $this->claim($connection, $order, true);
        $reader = app(OrderClaims::class)->freshReader($request, $connection);
        $key = app(ReaderGuard::class)->key($reader->getAuthIdentifier());
        $beneficiary = $order->reader_id ?? $claim?->reader_id;
        if ($key === null || $beneficiary !== $key) {
            throw new CheckoutRefused;
        }

        return CheckoutAttempts::digest(['claim' => $claim === null ? null : get_object_vars($claim),
            'original_reader' => $order->reader_id, 'reader' => $key, 'binding' => ReaderSessions::binding($reader)]);
    }

    /** Exact checked custody, safe for guest/reader/owner export even while commerce is disabled.
     * @return array{access_fulfillment:?array<string,mixed>}
     */
    public function projection(Connection $connection, int $org, #[\SensitiveParameter] object $order, bool $lock = false): array
    {
        try {
            if ((string) $order->org_id !== (string) $org) {
                throw new CheckoutRefused;
            }
            if (! $this->schema($connection)) {
                return ['access_fulfillment' => null];
            }
            [$work, $effects, $manifest] = $this->checked($connection, $order, $lock);

            $effective = $manifest === null ? null : app(HistoricalAccess::class)->effective($connection, $order, $manifest, $lock);
            $definitions = $effective === null ? [] : array_column($effective['definitions'], null, 'entitlement');

            return ['access_fulfillment' => ['state' => $work->state, 'category' => $work->category,
                'confirmed_at' => $manifest === null ? null : self::iso($manifest['confirmed_at']),
                'effects' => array_map(static fn (object $effect): array => ['entitlement' => $effect->entitlement,
                    'expires_at' => ($definitions[$effect->entitlement]['expires_at'] ?? null) === null ? null : self::iso($definitions[$effect->entitlement]['expires_at']),
                    'state' => $effect->state, 'category' => $effect->category,
                    'processed_at' => $effect->processed_at === null ? null : self::iso($effect->processed_at)], $effects)]];
        } catch (Throwable) {
            throw new CheckoutRefused;
        }
    }

    /** Only the empty pre-payment parent can leave through the initial-order erasure path. */
    public function deleteUnpaid(Connection $connection, #[\SensitiveParameter] object $order): void
    {
        // Reader erasure owns every current-Org Site lock and legitimately visits sibling Sites, or has Org-only Context.
        if ($connection !== (new Order)->getConnection() || $connection->transactionLevel() < 1 || ! $connection->getPdo()->inTransaction()
            || $this->context->orgId() !== (int) $order->org_id
            || ! $connection->table('sites')->useWritePdo()->where('id', $order->site_id)->where('org_id', $order->org_id)->lockForUpdate()->exists()) {
            throw new CheckoutRefused('changed');
        }
        if (! $this->schema($connection)) {
            return;
        }
        [$work, $effects] = $this->checked($connection, $order, true);
        if ($work->state !== 'awaiting_payment' || $effects !== [] || $work->manifest !== null) {
            throw new CheckoutRefused;
        }
        $review = app(HistoricalAccess::class)->plan($connection, $order, true);
        if ($review['revision'] !== 0 || $connection->table(HistoricalAccess::WORK)->where('id', $review['work']->id)->where('org_id', $order->org_id)
            ->where('site_id', $order->site_id)->where('order_id', $order->id)->delete() !== 1) {
            throw new CheckoutRefused;
        }
        if ($connection->table(self::WORK)->where('id', $work->id)->where('org_id', $order->org_id)
            ->where('site_id', $order->site_id)->where('order_id', $order->id)->delete() !== 1) {
            throw new CheckoutRefused;
        }
    }

    /** Schema absence is permitted only before this store was ever installed; partial custody fails closed. */
    public function schema(Connection $connection): bool
    {
        $schema = $connection->getSchemaBuilder();
        if (! $schema->hasTable(self::WORK) && ! $schema->hasTable(self::EFFECTS)) {
            if ($connection->table('migrations')->useWritePdo()->where('migration', self::MIGRATION)->exists()) {
                throw new CheckoutRefused('setup');
            }

            return false;
        }
        if (! RequiredColumns::present($connection, self::WORK, self::WORK_COLUMNS) || ! RequiredColumns::present($connection, self::EFFECTS, self::EFFECT_COLUMNS)) {
            throw new CheckoutRefused('setup');
        }

        return true;
    }

    public function schemaAvailable(Connection $connection): bool
    {
        return $this->schema($connection);
    }

    /** @return array{0:object,1:list<object>,2:?array<string,mixed>} */
    private function checked(Connection $connection, #[\SensitiveParameter] object $order, bool $lock): array
    {
        $work = $this->parent($connection, $order, $lock);
        $effects = $this->effects($connection, $order, $lock);
        $reviews = app(HistoricalAccess::class)->plan($connection, $order, $lock);
        $erased = app(PrivacyState::class)->checked($connection, $order, $lock)['subject_erased'] ?? false;
        if (($work->state === 'suppressed') !== $erased
            || ($erased && ($work->category !== 'subject_erased' || $work->claim_binding !== null
                || $work->claim_hash !== null || $work->claim_reader_id !== null))) {
            throw new CheckoutRefused('changed');
        }
        if ($erased && $work->manifest === null) {
            if (CompletionFacts::completed($order) || $work->manifest_hash !== null || $effects !== [] || self::counter($work->effect_count) !== 0) {
                throw new CheckoutRefused('changed');
            }

            return [$work, [], null];
        }
        if (in_array($work->state, ['awaiting_payment', 'initializing'], true)) {
            if ($work->manifest !== null || $work->manifest_hash !== null || $work->claim_binding !== null || $work->claim_hash !== null || $work->claim_reader_id !== null
                || $effects !== [] || self::counter($work->effect_count, self::MAX_EFFECTS) !== 0 || $work->category !== null
                || ($work->state === 'awaiting_payment' && CompletionFacts::completed($order))
                || ($work->state === 'initializing' && ! CompletionFacts::completed($order))) {
                throw new CheckoutRefused('changed');
            }

            return [$work, [], null];
        }
        if (! CompletionFacts::completed($order) || ! is_string($work->manifest_hash) || preg_match('/\A[a-f0-9]{64}\z/D', $work->manifest_hash) !== 1) {
            throw new CheckoutRefused('changed');
        }
        $manifest = self::decode($work->manifest);
        if (! hash_equals($work->manifest_hash, app(PrivacyState::class)->originalAccessManifestHash($connection, $order, $manifest))
            || ! hash_equals($work->manifest_hash, app(PrivacyState::class)->originalAccessManifestHash($connection, $order, $this->manifest($connection, $order, $lock)))
            || count($effects) !== self::counter($work->effect_count, self::MAX_EFFECTS)
            || count($effects) !== count($manifest['definitions'])) {
            throw new CheckoutRefused('changed');
        }
        foreach ($effects as $index => $effect) {
            $definition = $manifest['definitions'][$index];
            $reviewed = true;
            $awarded = array_column($manifest['allocations'], 'awarded', 'line_id');
            foreach ($manifest['lines'] as $line) {
                if ($line['entitlement_name'] === $definition['entitlement'] && $line['offer_terms'] === null
                    && (int) ($awarded[$line['id']] ?? 0) > 0 && ! isset($reviews['decisions'][(int) $line['id']])) {
                    $reviewed = false;
                }
            }
            if ((string) $effect->work_id !== (string) $work->id || $effect->entitlement !== $definition['entitlement']
                || $effect->source !== $definition['source'] || $effect->expires_at !== $definition['expires_at']
                || ! in_array($effect->state, ['pending', 'held', 'granted', 'unchanged', 'revoked', 'expired', 'omitted'], true)
                || ($effect->state === 'held' ? ($effect->category !== 'unknown_terms' || $definition['known'] !== false) : $effect->category !== null)
                || ($definition['known'] === false && $effect->state !== 'held' && ! $reviewed)
                || ($effect->state === 'omitted' && ($definition['known'] !== false || ! $reviewed))
                || (in_array($effect->state, ['pending', 'held'], true) !== ($effect->processed_at === null))) {
                throw new CheckoutRefused('changed');
            }
            if ($effect->expires_at !== null) {
                CheckoutAttempts::instant($effect->expires_at);
            }
            if ($effect->processed_at !== null) {
                CheckoutAttempts::instant($effect->processed_at);
            }
        }
        $claim = $this->claim($connection, $order, $lock);
        if (($work->claim_binding === null) !== ($work->claim_hash === null)
            || ($work->claim_reader_id === null) !== ($work->claim_binding === null)
            || ($claim === null) !== ($work->claim_binding === null)
            || ($claim !== null && (! is_string($work->claim_hash)
                || ! is_string($work->claim_reader_id) || ReaderGuard::stored($work->claim_reader_id) !== $work->claim_reader_id
                || $work->claim_reader_id !== $claim->reader_id
                || ! hash_equals($work->claim_hash, CheckoutAttempts::digest(self::decode($work->claim_binding)))
                || ! hash_equals($work->claim_hash, CheckoutAttempts::digest(get_object_vars($claim)))))) {
            throw new CheckoutRefused('changed');
        }
        $terminal = array_filter($effects, static fn (object $effect): bool => in_array($effect->state, ['pending', 'held'], true)) === [];
        if (! $erased && (($work->state === 'processed') !== ($terminal && $manifest['allocation_state'] === 'allocated')
            || ($work->state === 'awaiting_claim' && ($order->reader_id !== null || $claim !== null || $effects === []))
            || ($work->state === 'pending' && ($order->reader_id === null && $claim === null))
            || ($work->state === 'held' && ! in_array($work->category, ['financial_hold', 'unknown_terms', 'unknown_reader', 'reader_guard', 'retryable', 'grant_refused'], true))
            || ($work->state !== 'held' && $work->category !== null)
            || ($manifest['allocation_state'] === 'held' && ($work->state !== 'held' || $work->category !== 'financial_hold' || $effects !== [])))) {
            throw new CheckoutRefused('changed');
        }

        return [$work, $effects, $manifest];
    }

    /** Independent scalar discovery protects a claim whose own reader column was partially corrupted.
     * @return list<object>
     */
    public function forReader(Connection $connection, int $org, #[\SensitiveParameter] string $reader, bool $lock = false): array
    {
        if (! $this->schemaAvailable($connection)) {
            return [];
        }
        if (ReaderGuard::stored($reader) !== $reader) {
            throw new CheckoutRefused('changed');
        }
        $query = $connection->table(self::WORK)->useWritePdo()->where('org_id', $org)->where('claim_reader_id', $reader)->orderBy('site_id')->orderBy('id');
        $rows = ($lock ? $query->lockForUpdate() : $query)->get();
        foreach ($rows as $row) {
            $parent = $connection->table('commerce_orders')->useWritePdo()->where('id', $row->order_id)
                ->when($lock, static fn ($query) => $query->lockForUpdate())->first();
            if ($parent === null || (string) $parent->org_id !== (string) $org) {
                throw new CheckoutRefused('changed');
            }
            [$checked] = $this->checked($connection, $parent, $lock);
            if ((string) $checked->id !== (string) $row->id || $checked->claim_reader_id !== $reader) {
                throw new CheckoutRefused('changed');
            }
        }

        return $rows->all();
    }

    private function parent(Connection $connection, #[\SensitiveParameter] object $order, bool $lock): object
    {
        if (! $this->schema($connection)) {
            throw new CheckoutRefused('setup');
        }
        $query = $connection->table(self::WORK)->useWritePdo()->where('order_id', $order->id)->limit(2);
        $rows = ($lock ? $query->lockForUpdate() : $query)->get();
        if ($rows->count() !== 1) {
            throw new CheckoutRefused('changed');
        }
        $work = $rows->sole();
        self::child($work, $order);
        self::counter($work->effect_count, self::MAX_EFFECTS);
        self::counter($work->replay_count);
        CheckoutAttempts::instant($work->created_at);
        CheckoutAttempts::instant($work->updated_at);
        if (! in_array($work->state, ['awaiting_payment', 'initializing', 'awaiting_claim', 'pending', 'held', 'processed', 'suppressed'], true)) {
            throw new CheckoutRefused('changed');
        }

        return $work;
    }

    /** @return list<object> */
    private function effects(Connection $connection, #[\SensitiveParameter] object $order, bool $lock): array
    {
        $query = $connection->table(self::EFFECTS)->useWritePdo()->where('order_id', $order->id)->orderBy('entitlement')->limit(self::MAX_EFFECTS + 1);
        $rows = ($lock ? $query->lockForUpdate() : $query)->get();
        if ($rows->count() > self::MAX_EFFECTS) {
            throw new CheckoutRefused('changed');
        }
        foreach ($rows as $row) {
            self::child($row, $order);
        }
        $effects = $rows->all();
        usort($effects, static fn (object $a, object $b): int => strcmp($a->entitlement, $b->entitlement));

        return $effects;
    }

    /** Build exclusively from the immutable purchase and durable checked availability; never today's products.
     * @return array<string,mixed>
     */
    private function manifest(Connection $connection, #[\SensitiveParameter] object $order, bool $lock): array
    {
        if (! CompletionFacts::completed($order) || ! in_array($order->mode, ['test', 'live'], true)) {
            throw new CheckoutRefused('changed');
        }
        app(PaidOrders::class)->projection($connection, (int) $order->org_id, $order, $lock);
        $receipt = CompletionFacts::receipt($connection, $order, $lock) ?? throw new CheckoutRefused;
        $completedAt = CompletionFacts::instant($order, $receipt);
        $start = CheckoutAttempts::instant($completedAt);
        $lines = $connection->table('commerce_order_lines')->useWritePdo()->where('order_id', $order->id)->orderBy('id')->limit(21)
            ->when($lock, static fn ($query) => $query->lockForUpdate())->get();
        $allocations = $connection->table(PaidOrders::ALLOCATIONS)->useWritePdo()->where('order_id', $order->id)->orderBy('line_id')->limit(21)
            ->when($lock, static fn ($query) => $query->lockForUpdate())->get()->keyBy('line_id');
        if ($lines->isEmpty() || $lines->count() > 20 || $allocations->count() > 20) {
            throw new CheckoutRefused('changed');
        }
        $definitions = [];
        foreach ($lines as $line) {
            self::child($line, $order);
            $terms = OfferTerms::forMapping($line->entitlement_name, OfferTerms::stored($line->offer_terms));
            $allocation = $allocations->get($line->id);
            if ($allocation === null || self::counter($allocation->awarded, 99) === 0 || $line->entitlement_name === null) {
                continue;
            }
            self::child($allocation, $order);
            $known = $terms !== null;
            $end = $known ? OfferTerms::resolve($terms, $start)?->format('Y-m-d H:i:s') : null;
            $name = $line->entitlement_name;
            if (! isset($definitions[$name])) {
                $definitions[$name] = ['entitlement' => $name, 'source' => 'commerce.order:'.$order->id,
                    'expires_at' => $end, 'known' => $known];
            } else {
                $definitions[$name]['known'] = $definitions[$name]['known'] && $known;
                $old = $definitions[$name]['expires_at'];
                $definitions[$name]['expires_at'] = $old === null || $end === null ? null : max($old, $end);
            }
        }
        ksort($definitions, SORT_STRING);

        return ['version' => 1, 'order' => ['id' => (string) $order->id, 'org_id' => (string) $order->org_id,
            'site_id' => (string) $order->site_id, 'reference' => $order->reference, 'reader_id' => $order->reader_id,
            'owner_key' => $order->owner_key, 'snapshot_hash' => $order->snapshot_hash],
            'confirmed_at' => $completedAt, 'allocation_state' => ($order->state === 'completed_free' ? 'allocated' : $receipt->allocation_state),
            'receipt' => get_object_vars($receipt), 'lines' => $lines->map(static fn (object $line): array => get_object_vars($line))->all(),
            'allocations' => $allocations->values()->map(static fn (object $row): array => get_object_vars($row))->all(),
            'definitions' => array_values($definitions)];
    }

    private function claim(Connection $connection, #[\SensitiveParameter] object $order, bool $lock): ?object
    {
        return app(OrderClaims::class)->forOrder($connection, $order, $lock);
    }

    /** The canonical beneficiary must still exist in this Org; a reader from another database cannot join this commit. */
    private function beneficiary(Connection $connection, #[\SensitiveParameter] object $order, bool $lock): string
    {
        app(PrivacyState::class)->assertAccessAllowed($connection, $order);
        $reader = $order->reader_id ?? $this->claim($connection, $order, $lock)?->reader_id;
        $guard = app(ReaderGuard::class);
        if ($guard->fault() !== null) {
            throw EntitlementRefused::because(EntitlementRefusal::NoReaderGuard, EntitlementRefused::GRANT);
        }
        $model = $guard->model() ?? throw EntitlementRefused::because(EntitlementRefusal::NoReaderGuard, EntitlementRefused::GRANT);
        $instance = new $model;
        if ($instance->getConnection() !== $connection || ! is_string($reader) || $guard->key($reader) !== $reader) {
            throw EntitlementRefused::because(EntitlementRefusal::UnknownReader, EntitlementRefused::GRANT);
        }
        $row = $instance->newQuery()->useWritePdo()->whereKey($reader)->where('org_id', $order->org_id)
            ->when($lock, static fn ($query) => $query->lockForUpdate())->first();
        if (! $row instanceof Authenticatable || $guard->key($row->getAuthIdentifier()) !== $reader) {
            throw EntitlementRefused::because(EntitlementRefusal::UnknownReader, EntitlementRefused::GRANT);
        }

        return $reader;
    }

    /** Freeze all graph inputs before host audit observers can run. Mutable effect outcomes are included here. */
    private function custody(Connection $connection, #[\SensitiveParameter] object $order): string
    {
        $tables = ['commerce_order_lines' => 20, CheckoutAttempts::TABLE => CheckoutAttempts::MAX_ATTEMPTS,
            FreeOrders::TABLE => 1, FreeAccessCorrections::WORK => 1, FreeAccessCorrections::TABLE => 1000,
            HistoricalAccess::WORK => 1, HistoricalAccess::TABLE => HistoricalAccess::MAX,
            PaidOrders::RECEIPTS => 1, PaidOrders::ALLOCATIONS => 20, PaidOrders::OBLIGATIONS => 1,
            PaidOrders::ATTEMPTS => PaidOrders::MAX_REFUND_ATTEMPTS, self::WORK => 1, self::EFFECTS => self::MAX_EFFECTS];
        $rows = [];
        $actual = $connection->table('commerce_orders')->useWritePdo()->where('id', $order->id)->lockForUpdate()->first() ?? throw new CheckoutRefused;
        if (CheckoutAttempts::digest(get_object_vars($actual)) !== CheckoutAttempts::digest(get_object_vars($order))) {
            throw new CheckoutRefused('changed');
        }
        foreach ($tables as $table => $bound) {
            $children = $connection->table($table)->useWritePdo()->where('order_id', $order->id)->orderBy('id')->limit($bound + 1)->lockForUpdate()->get();
            if ($children->count() > $bound) {
                throw new CheckoutRefused('changed');
            }
            foreach ($children as $child) {
                self::child($child, $order);
            }
            $rows[$table] = $children->map(static fn (object $row): array => get_object_vars($row))->all();
        }
        $claim = $this->claim($connection, $order, true);

        return CheckoutAttempts::digest(['order' => get_object_vars($actual), 'children' => $rows,
            'claim' => $claim === null ? null : get_object_vars($claim)]);
    }

    /** @param array<string,mixed> $changes */
    private function update(Connection $connection, #[\SensitiveParameter] object $order, #[\SensitiveParameter] object $work, #[\SensitiveParameter] array $changes): void
    {
        $changes['updated_at'] = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        $dirty = false;
        foreach ($changes as $column => $value) {
            if (($value === null) !== ($work->{$column} === null)
                || ($value !== null && (string) $value !== (string) $work->{$column})) {
                $dirty = true;
                break;
            }
        }
        // MySQL counts changed rows. An already identical hold, in this same second, is an idempotent decision.
        if (! $dirty) {
            return;
        }
        if ($connection->table(self::WORK)->where('id', $work->id)->where('org_id', $order->org_id)
            ->where('site_id', $order->site_id)->where('order_id', $order->id)->update($changes) !== 1) {
            throw new CheckoutRefused('changed');
        }
    }

    private function effectUpdate(Connection $connection, #[\SensitiveParameter] object $order, #[\SensitiveParameter] object $effect, string $state): void
    {
        if ($connection->table(self::EFFECTS)->where('id', $effect->id)->where('org_id', $order->org_id)->where('site_id', $order->site_id)
            ->where('order_id', $order->id)->where('state', 'pending')->whereNull('processed_at')
            ->update(['state' => $state, 'category' => null, 'processed_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s')]) !== 1) {
            throw new CheckoutRefused('changed');
        }
    }

    private function inside(Connection $connection, #[\SensitiveParameter] object $order): void
    {
        if ($connection !== (new Order)->getConnection() || $connection->transactionLevel() < 1 || ! $connection->getPdo()->inTransaction()
            || $this->context->orgId() !== (int) $order->org_id || $this->context->siteId() !== (int) $order->site_id) {
            throw new CheckoutRefused('changed');
        }
    }

    private function ready(Connection $connection): void
    {
        $module = $connection->table('modules')->useWritePdo()->where('handle', 'kitsune/commerce')->where('is_enabled', true)->lockForUpdate()->first();
        if ($module === null || $module->version !== ModuleDiscovery::version('kitsune/commerce') || ! $this->schema($connection)) {
            throw new CheckoutRefused('setup');
        }
    }

    private static function child(#[\SensitiveParameter] object $child, #[\SensitiveParameter] object $order): void
    {
        if (self::writtenKey($child->id) === null || self::writtenKey($child->id) < 1 || (string) $child->org_id !== (string) $order->org_id
            || (string) $child->site_id !== (string) $order->site_id || (string) $child->order_id !== (string) $order->id) {
            throw new CheckoutRefused('changed');
        }
    }

    public static function counter(mixed $value, int $max = 2147483647): int
    {
        if ((! is_int($value) && ! is_string($value)) || preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', (string) $value) !== 1 || (int) $value > $max) {
            throw new CheckoutRefused('changed');
        }

        return (int) $value;
    }

    /** @param array<string,mixed> $value */
    private static function encode(#[\SensitiveParameter] array $value): string
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR);
        if (strlen($json) > 131072) {
            throw new CheckoutRefused('changed');
        }

        return $json;
    }

    /** @return array<string,mixed> */
    private static function decode(#[\SensitiveParameter] mixed $json): array
    {
        if (! is_string($json) || strlen($json) > 131072) {
            throw new CheckoutRefused('changed');
        }
        $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new CheckoutRefused('changed');
        }

        return $decoded;
    }

    private static function iso(string $instant): string
    {
        return CheckoutAttempts::instant($instant)->format('Y-m-d\TH:i:s\Z');
    }
}
