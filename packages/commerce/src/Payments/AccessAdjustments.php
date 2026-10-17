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
use Kitsune\Commerce\Models\Order;
use Kitsune\Commerce\Orders\CompletionFacts;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Privacy\PrivacyState;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Entitlements\EntitlementRefusal;
use Kitsune\Core\Entitlements\EntitlementRefused;
use Kitsune\Core\Entitlements\EntitlementWriter;
use Kitsune\Core\Entitlements\VerifiedOrderOutcome;
use Kitsune\Core\Models\Entitlement;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Tenancy\Context;
use Throwable;

/** Financial source effects, distinct from completed original grants and independently recoverable. @internal */
final class AccessAdjustments
{
    public const WORK = 'commerce_access_adjustment_work';

    public const EFFECTS = 'commerce_access_adjustment_effects';

    public function __construct(private readonly Context $context, private readonly OrderLocks $locks) {}

    public function prepare(Connection $connection, #[\SensitiveParameter] object $order): void
    {
        $this->inside($connection, $order);
        if (! $this->schemaAvailable($connection) || $connection->table(self::WORK)->useWritePdo()->where('order_id', $order->id)->exists()) {
            throw new CheckoutRefused('setup');
        }
        $connection->table(self::WORK)->insert(['org_id' => $order->org_id, 'site_id' => $order->site_id,
            'order_id' => $order->id, 'state' => 'awaiting_payment', 'category' => null, 'manifest_hash' => null,
            'financial_digest' => null, 'beneficiary_hash' => null, 'effect_count' => 0, 'replay_count' => 0,
            'created_at' => $order->created_at, 'updated_at' => $order->created_at]);
    }

    /** Queue an immutable beneficiary; a buyer never invokes an owner/system source adjustment. */
    public function claimed(Connection $connection, #[\SensitiveParameter] object $order): void
    {
        $this->inside($connection, $order);
        app(PrivacyState::class)->assertAccessAllowed($connection, $order);
        [$work] = $this->checked($connection, $order, true);
        $original = app(AccessFulfillment::class)->forAdjustment($connection, $order);
        if ($order->reader_id !== null || $original['reader'] === null || $original['work']->claim_hash === null) {
            throw new CheckoutRefused('changed');
        }
        $hash = CheckoutAttempts::digest(['reader' => $original['reader'], 'claim_hash' => $original['work']->claim_hash]);
        if ($work->beneficiary_hash !== null && ! hash_equals($work->beneficiary_hash, $hash)) {
            throw new CheckoutRefused('changed');
        }
        $financial = app(FinancialState::class)->accessPlan($connection, $order, true);
        $this->updateWork($connection, $order, ['beneficiary_hash' => $hash,
            'state' => $financial['blocked'] ? 'held' : 'pending', 'category' => $financial['blocked'] ? $financial['category'] : null]);
    }

    /** @return array{state:string,category:?string,processed:int} */
    public function reconcile(string $reference): array
    {
        $this->authority();
        if (preg_match('/\A[a-f0-9]{32}\z/D', $reference) !== 1) {
            throw new CheckoutRefused;
        }
        try {
            return $this->locks->run(function () use ($reference): array {
                $connection = (new Order)->getConnection();
                $this->ready($connection);
                $order = $connection->table('commerce_orders')->useWritePdo()->where('org_id', $this->context->orgId())
                    ->where('site_id', $this->context->siteId())->where('reference', $reference)->lockForUpdate()->first() ?? throw new CheckoutRefused;

                return $this->process($connection, $order);
            });
        } catch (CheckoutRefused $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new CheckoutRefused;
        }
    }

    /** @return array{state:string,category:?string,processed:int} */
    private function process(Connection $connection, object $order): array
    {
        $authority = $this->authority();
        [$work] = $this->checked($connection, $order, true);
        if ($work->state === 'suppressed') {
            return ['state' => 'suppressed', 'category' => 'subject_erased', 'processed' => 0];
        }
        app(PrivacyState::class)->assertAccessAllowed($connection, $order);
        if (! CompletionFacts::completed($order)) {
            return ['state' => 'awaiting_payment', 'category' => null, 'processed' => 0];
        }
        $original = app(AccessFulfillment::class)->forAdjustment($connection, $order);
        if ($original['manifest'] === null) {
            return ['state' => 'pending', 'category' => null, 'processed' => 0];
        }
        if ($work->manifest_hash === null) {
            foreach ($original['effects'] as $effect) {
                $connection->table(self::EFFECTS)->insert(['org_id' => $order->org_id, 'site_id' => $order->site_id,
                    'order_id' => $order->id, 'work_id' => $work->id, 'access_effect_id' => $effect->id,
                    'entitlement' => $effect->entitlement, 'source' => $effect->source,
                    'original_end' => $effect->expires_at, 'projected_end' => $effect->expires_at,
                    'state' => 'pending', 'revocation_witness' => null, 'processed_digest' => null, 'processed_at' => null]);
            }
            $this->updateWork($connection, $order, ['manifest_hash' => $original['work']->manifest_hash,
                'effect_count' => count($original['effects']), 'state' => 'pending']);
        }
        [$work, $effects] = $this->checked($connection, $order, true);
        if ($effects === []) {
            $financial = app(FinancialState::class)->accessPlan($connection, $order, true);
            $state = $financial['blocked'] ? 'held' : 'processed';
            $this->updateWork($connection, $order, ['financial_digest' => $financial['digest'], 'state' => $state, 'category' => $financial['category']]);

            return ['state' => $state, 'category' => $financial['category'], 'processed' => 0];
        }
        $reader = $original['reader'];
        if ($reader === null) {
            $this->updateWork($connection, $order, ['state' => 'awaiting_claim', 'category' => null]);

            return ['state' => 'awaiting_claim', 'category' => null, 'processed' => 0];
        }
        $beneficiary = CheckoutAttempts::digest(['reader' => $reader, 'claim_hash' => $original['work']->claim_hash]);
        if ($work->beneficiary_hash !== null && ! hash_equals($work->beneficiary_hash, $beneficiary)) {
            throw new CheckoutRefused('changed');
        }
        if ($work->beneficiary_hash === null) {
            $this->updateWork($connection, $order, ['beneficiary_hash' => $beneficiary]);
        }
        $financial = app(FinancialState::class)->accessPlan($connection, $order, true);
        $targets = array_column(AccessContributions::project($original['manifest'], $financial['removed_units'], $financial['blocked']), null, 'entitlement');
        $initial = array_column($original['effects'], null, 'entitlement');
        $processed = 0;
        $pending = false;
        $unknown = false;
        $expectedSources = [];
        foreach ($effects as $effect) {
            $expectedSources[$effect->entitlement] = $this->sourceSnapshot($connection, $order, $reader, $effect->entitlement);
        }
        foreach ($effects as $effect) {
            $target = $targets[$effect->entitlement] ?? throw new CheckoutRefused('changed');
            if (! $target['known']) {
                $unknown = true;
                $this->updateEffect($connection, $order, $effect, ['state' => 'held', 'processed_digest' => $financial['digest'], 'processed_at' => self::now()]);

                continue;
            }
            $owned = self::witness($effect->revocation_witness);
            $ownedBefore = $owned !== null && $this->owns($connection, $order, $reader, $effect, $owned);
            $before = $this->digest($connection, $order);
            $verify = function () use ($connection, $order, $authority, $before, $reader, $effect, &$expectedSources): void {
                $this->ready($connection);
                $this->inside($connection, $order);
                app(PrivacyState::class)->assertAccessAllowed($connection, $order);
                // The core writer checks its own row. Other names must survive this callback byte for byte.
                $this->checkSources($connection, $order, $reader, $expectedSources, $effect->entitlement);
                if ($this->authority() !== $authority || ! hash_equals($before, $this->digest($connection, $order))) {
                    throw new CheckoutRefused('changed');
                }
            };
            $end = $target['expires_at'] === null ? null : CheckoutAttempts::instant($target['expires_at']);
            try {
                $result = ! $target['suspended'] && $owned !== null
                    ? app(EntitlementWriter::class)->reinstate($reader, $effect->entitlement, $effect->source, $end, $owned, $verify)
                    : app(EntitlementWriter::class)->reviseVerifiedOrder($reader, $effect->entitlement, $effect->source, $end, $target['suspended'], $verify);
            } catch (EntitlementRefused $failure) {
                $this->checkSources($connection, $order, $reader, $expectedSources);
                $category = match ($failure->reason) {
                    EntitlementRefusal::UnknownReader, EntitlementRefusal::NotAReader => 'unknown_reader',
                    EntitlementRefusal::Race, EntitlementRefusal::Database => 'retryable',
                    default => 'source_refused',
                };
                $this->updateWork($connection, $order, ['state' => 'held', 'category' => $category]);

                return ['state' => 'held', 'category' => $category, 'processed' => $processed];
            }
            $expectedSources[$effect->entitlement] = $result->witness;
            $retained = match (true) {
                $result->outcome === VerifiedOrderOutcome::Revoked => $result->witness,
                $ownedBefore && in_array($result->outcome, [VerifiedOrderOutcome::StillRevoked, VerifiedOrderOutcome::Expired], true)
                    && ($result->witness['revoked_at'] ?? null) !== null => $result->witness,
                default => null,
            };
            $state = $result->outcome === VerifiedOrderOutcome::StillRevoked ? 'still_revoked' : strtolower($result->outcome->name);
            if ($result->outcome === VerifiedOrderOutcome::Missing
                && in_array($initial[$effect->entitlement]->state, ['pending', 'held'], true)) {
                // Initial fulfillment may still create its first row after a failed refund releases its contribution.
                $state = 'pending';
                $pending = true;
            }
            $this->updateEffect($connection, $order, $effect, ['projected_end' => $target['expires_at'], 'state' => $state,
                'revocation_witness' => $retained === null ? null : self::encode($retained),
                'processed_digest' => $financial['digest'], 'processed_at' => self::now()]);
            $this->checkSources($connection, $order, $reader, $expectedSources);
            $processed++;
        }
        $category = $financial['blocked'] ? $financial['category'] : ($unknown ? 'unknown_terms' : ($pending ? 'initial_access' : null));
        $state = $financial['blocked'] || $unknown ? 'held' : ($pending ? 'pending' : 'processed');
        $this->updateWork($connection, $order, ['financial_digest' => $financial['digest'], 'state' => $state, 'category' => $category]);
        $this->checked($connection, $order, true);
        $this->checkSources($connection, $order, $reader, $expectedSources);
        if ($this->authority() !== $authority
            || ! hash_equals($financial['digest'], app(FinancialState::class)->accessPlan($connection, $order, true)['digest'])
            || ! hash_equals($original['custody'], app(AccessFulfillment::class)->forAdjustment($connection, $order)['custody'])) {
            throw new CheckoutRefused('changed');
        }

        return ['state' => $state, 'category' => $category, 'processed' => $processed];
    }

    /** @return array{id:string,expires_at:?string,revoked_at:?string,changed_at:string,change_token:string}|null */
    private function sourceSnapshot(Connection $connection, object $order, string $reader, string $name): ?array
    {
        $query = $connection->table('entitlements')->useWritePdo()->where('org_id', $order->org_id)
            ->where('site_id', $order->site_id)->where('reader_id', $reader)->where('entitlement', $name)->where('source', 'commerce.order:'.$order->id);
        // Do not gap-lock an absent first grant on MySQL/MariaDB. The held Org lock serializes honest writers.
        $first = (clone $query)->first(['id']);
        if ($first === null) {
            return null;
        }
        $row = $query->where('id', $first->id)->lockForUpdate()->first() ?? throw new CheckoutRefused('changed');
        if (! is_string($row->change_token) || preg_match('/\A[a-f0-9]{64}\z/D', $row->change_token) !== 1) {
            throw new CheckoutRefused('changed');
        }
        foreach (['expires_at', 'revoked_at', 'changed_at'] as $column) {
            if ($row->{$column} !== null) {
                CheckoutAttempts::instant($row->{$column});
            }
        }

        return ['id' => (string) $row->id, 'expires_at' => $row->expires_at, 'revoked_at' => $row->revoked_at,
            'changed_at' => $row->changed_at, 'change_token' => $row->change_token];
    }

    /** @param array<string,array{id:string,expires_at:?string,revoked_at:?string,changed_at:string,change_token:string}|null> $expected */
    private function checkSources(Connection $connection, object $order, string $reader, array $expected, ?string $except = null): void
    {
        foreach ($expected as $name => $witness) {
            if ($name !== $except && $this->sourceSnapshot($connection, $order, $reader, $name) !== $witness) {
                throw new CheckoutRefused('changed');
            }
        }
    }

    /** Exact pre-call ownership: timestamps alone cannot distinguish a same-second manual source write.
     * @param  array<string,mixed>  $witness
     */
    private function owns(Connection $connection, object $order, string $reader, object $effect, array $witness): bool
    {
        $row = $connection->table('entitlements')->useWritePdo()->where('id', $witness['id'])
            ->where('org_id', $order->org_id)->where('site_id', $order->site_id)->where('reader_id', $reader)
            ->where('entitlement', $effect->entitlement)->where('source', $effect->source)->lockForUpdate()->first();
        if ($row === null) {
            return false;
        }

        return $witness === ['id' => (string) $row->id, 'expires_at' => $row->expires_at,
            'revoked_at' => $row->revoked_at, 'changed_at' => $row->changed_at, 'change_token' => $row->change_token];
    }

    /** Read-only paid custody remains checked when the module is disabled or stale.
     * @return array{0:object,1:list<object>}
     */
    public function checked(Connection $connection, #[\SensitiveParameter] object $order, bool $lock = false): array
    {
        if (! $this->schemaAvailable($connection)) {
            throw new CheckoutRefused('setup');
        }
        $parents = $connection->table(self::WORK)->useWritePdo()->where('order_id', $order->id)->limit(2)
            ->when($lock, static fn ($q) => $q->lockForUpdate())->get();
        if ($parents->count() !== 1) {
            throw new CheckoutRefused('changed');
        }
        $work = $parents->sole();
        self::child($work, $order);
        if (! in_array($work->state, ['awaiting_payment', 'awaiting_claim', 'pending', 'held', 'processed', 'suppressed'], true)
            || ($work->category !== null && (! is_string($work->category) || preg_match('/\A[a-z_]{1,32}\z/D', $work->category) !== 1))) {
            throw new CheckoutRefused('changed');
        }
        $erased = app(PrivacyState::class)->checked($connection, $order, $lock)['subject_erased'] ?? false;
        if (($work->state === 'suppressed') !== $erased
            || ($erased && ($work->category !== 'subject_erased' || $work->beneficiary_hash !== null))) {
            throw new CheckoutRefused('changed');
        }
        foreach (['manifest_hash', 'financial_digest', 'beneficiary_hash'] as $column) {
            if ($work->{$column} !== null && (! is_string($work->{$column}) || preg_match('/\A[a-f0-9]{64}\z/D', $work->{$column}) !== 1)) {
                throw new CheckoutRefused('changed');
            }
        }
        self::counter($work->replay_count);
        CheckoutAttempts::instant($work->created_at);
        CheckoutAttempts::instant($work->updated_at);
        $effects = $connection->table(self::EFFECTS)->useWritePdo()->where('order_id', $order->id)->orderBy('access_effect_id')->limit(21)
            ->when($lock, static fn ($q) => $q->lockForUpdate())->get()->all();
        if (count($effects) > 20 || count($effects) !== self::counter($work->effect_count)
            || ($work->manifest_hash === null && $effects !== [])) {
            throw new CheckoutRefused('changed');
        }
        $original = $connection->table(AccessFulfillment::WORK)->useWritePdo()->where('order_id', $order->id)
            ->when($lock, static fn ($q) => $q->lockForUpdate())->first() ?? throw new CheckoutRefused('changed');
        self::child($original, $order);
        if ($work->manifest_hash !== null && ($work->manifest_hash !== $original->manifest_hash || self::counter($work->effect_count) !== self::counter($original->effect_count))) {
            throw new CheckoutRefused('changed');
        }
        $sourceEffects = $connection->table(AccessFulfillment::EFFECTS)->useWritePdo()->where('order_id', $order->id)->orderBy('id')->limit(21)
            ->when($lock, static fn ($q) => $q->lockForUpdate())->get()->keyBy('id');
        $seen = [];
        foreach ($effects as $effect) {
            self::child($effect, $order);
            $source = $sourceEffects->get($effect->access_effect_id);
            if ($source === null || (string) $effect->work_id !== (string) $work->id
                || $effect->entitlement !== $source->entitlement || $effect->source !== $source->source
                || $effect->source !== 'commerce.order:'.$order->id || $effect->original_end !== $source->expires_at
                || isset($seen[(string) $effect->access_effect_id])
                || ! in_array($effect->state, ['pending', 'held', 'missing', 'unchanged', 'revised', 'revoked', 'still_revoked', 'reinstated', 'expired'], true)) {
                throw new CheckoutRefused('changed');
            }
            $seen[(string) $effect->access_effect_id] = true;
            self::child($source, $order);
            foreach (['original_end', 'projected_end', 'processed_at'] as $column) {
                if ($effect->{$column} !== null) {
                    CheckoutAttempts::instant($effect->{$column});
                }
            }
            if ($effect->processed_digest !== null && (! is_string($effect->processed_digest) || preg_match('/\A[a-f0-9]{64}\z/D', $effect->processed_digest) !== 1)) {
                throw new CheckoutRefused('changed');
            }
            if ($erased && $effect->revocation_witness !== null) {
                throw new CheckoutRefused('changed');
            }
            self::witness($effect->revocation_witness);
            if (($effect->processed_digest === null) !== ($effect->processed_at === null)
                || ($effect->state !== 'pending' && $effect->processed_at === null)
                || ($effect->revocation_witness !== null && ! in_array($effect->state, ['revoked', 'still_revoked', 'expired'], true))) {
                throw new CheckoutRefused('changed');
            }
        }

        return [$work, $effects];
    }

    /** @return array{financial_access:?array<string,mixed>} */
    public function projection(Connection $connection, int $org, #[\SensitiveParameter] object $order, bool $lock = false): array
    {
        if ((string) $order->org_id !== (string) $org) {
            throw new CheckoutRefused;
        }
        if (! $this->schemaAvailable($connection)) {
            return ['financial_access' => null];
        }
        [$work, $effects] = $this->checked($connection, $order, $lock);
        if ($work->manifest_hash === null) {
            // Before financial source work runs, keep the checked original fulfillment and claim status.
            return ['financial_access' => null];
        }

        return ['financial_access' => ['state' => $work->state, 'category' => $work->category,
            'effects' => array_map(static fn (object $effect): array => ['entitlement' => $effect->entitlement,
                'expires_at' => $effect->projected_end, 'state' => $effect->state], $effects)]];
    }

    public function deleteUnpaid(Connection $connection, #[\SensitiveParameter] object $order): void
    {
        if (! $this->schemaAvailable($connection)) {
            return;
        }
        [$work, $effects] = $this->checked($connection, $order, true);
        if (CompletionFacts::completed($order) || $order->confirmed_total_minor !== null || $work->manifest_hash !== null || $effects !== []) {
            throw new CheckoutRefused('changed');
        }
        $connection->table(self::WORK)->where('id', $work->id)->where('org_id', $order->org_id)->where('site_id', $order->site_id)->delete();
    }

    public function schemaAvailable(Connection $connection): bool
    {
        $schema = $connection->getSchemaBuilder();
        $columns = [self::WORK => ['id', 'org_id', 'site_id', 'order_id', 'state', 'category', 'manifest_hash',
            'financial_digest', 'beneficiary_hash', 'effect_count', 'replay_count', 'created_at', 'updated_at'],
            self::EFFECTS => ['id', 'org_id', 'site_id', 'order_id', 'work_id', 'access_effect_id', 'entitlement', 'source',
                'original_end', 'projected_end', 'state', 'revocation_witness', 'processed_digest', 'processed_at']];
        if (! $schema->hasTable(self::WORK) && ! $schema->hasTable(self::EFFECTS)
            && ! $connection->table('migrations')->useWritePdo()->where('migration', '0001_01_01_000216_create_commerce_access_adjustments')->exists()) {
            return false;
        }
        foreach ($columns as $table => $required) {
            if (! $schema->hasTable($table) || ! $schema->hasColumns($table, $required)) {
                throw new CheckoutRefused('setup');
            }
        }

        return true;
    }

    private function digest(Connection $connection, object $order): string
    {
        [$work, $effects] = $this->checked($connection, $order, true);
        $original = app(AccessFulfillment::class)->forAdjustment($connection, $order);
        $financial = app(FinancialState::class)->accessPlan($connection, $order, true);

        return CheckoutAttempts::digest(['original' => $original['custody'], 'reader' => $original['reader'],
            'financial' => $financial['digest'], 'work' => get_object_vars($work),
            'effects' => array_map(static fn (object $row): array => get_object_vars($row), $effects)]);
    }

    /** @param array<string,mixed> $changes */
    private function updateWork(Connection $connection, object $order, array $changes): void
    {
        $changes['updated_at'] = self::now();
        $connection->table(self::WORK)->where('org_id', $order->org_id)->where('site_id', $order->site_id)->where('order_id', $order->id)->update($changes);
        $row = $connection->table(self::WORK)->useWritePdo()->where('org_id', $order->org_id)->where('site_id', $order->site_id)
            ->where('order_id', $order->id)->lockForUpdate()->first() ?? throw new CheckoutRefused('changed');
        $this->changedReadback($row, $changes);
    }

    /** @param array<string,mixed> $changes */
    private function updateEffect(Connection $connection, object $order, object $effect, array $changes): void
    {
        $connection->table(self::EFFECTS)->where('id', $effect->id)->where('org_id', $order->org_id)
            ->where('site_id', $order->site_id)->where('order_id', $order->id)->update($changes);
        $row = $connection->table(self::EFFECTS)->useWritePdo()->where('id', $effect->id)->where('org_id', $order->org_id)
            ->where('site_id', $order->site_id)->where('order_id', $order->id)->lockForUpdate()->first() ?? throw new CheckoutRefused('changed');
        $this->changedReadback($row, $changes);
    }

    /** @param array<string,mixed> $changes */
    private function changedReadback(object $row, array $changes): void
    {
        foreach ($changes as $column => $expected) {
            if ($column === 'revocation_witness') {
                if (self::witness($row->{$column}) !== self::witness($expected)) {
                    throw new CheckoutRefused('changed');
                }
            } elseif (($row->{$column} === null) !== ($expected === null)
                || ($expected !== null && (string) $row->{$column} !== (string) $expected)) {
                throw new CheckoutRefused('changed');
            }
        }
    }

    private function authority(): string
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

    private function ready(Connection $connection): void
    {
        $module = $connection->table('modules')->useWritePdo()->where('handle', 'kitsune/commerce')->where('is_enabled', true)->first();
        if ($module === null || $module->version !== ModuleDiscovery::version('kitsune/commerce') || ! $this->schemaAvailable($connection)) {
            throw new CheckoutRefused('setup');
        }
    }

    private function inside(Connection $connection, object $order): void
    {
        if ($connection !== (new Entitlement)->getConnection() || $connection->transactionLevel() < 1 || ! $connection->getPdo()->inTransaction()
            || (string) $order->org_id !== (string) $this->context->orgId() || (string) $order->site_id !== (string) $this->context->siteId()) {
            throw new CheckoutRefused('changed');
        }
    }

    /** @return array{id:string,expires_at:?string,revoked_at:string,changed_at:string,change_token:string}|null */
    private static function witness(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || strlen($value) > 1024) {
            throw new CheckoutRefused('changed');
        }
        $witness = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
        if (! is_array($witness) || count($witness) !== 5 || ! is_string($witness['id'] ?? null)
            || preg_match('/\A[1-9][0-9]{0,18}\z/D', $witness['id']) !== 1
            || ! is_string($witness['revoked_at'] ?? null) || ! is_string($witness['changed_at'] ?? null)
            || ! is_string($witness['change_token'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $witness['change_token']) !== 1
            || ! array_key_exists('expires_at', $witness) || ($witness['expires_at'] !== null && ! is_string($witness['expires_at']))) {
            throw new CheckoutRefused('changed');
        }
        foreach (['revoked_at', 'changed_at', 'expires_at'] as $column) {
            if ($witness[$column] !== null) {
                CheckoutAttempts::instant($witness[$column]);
            }
        }

        return ['id' => $witness['id'], 'expires_at' => $witness['expires_at'], 'revoked_at' => $witness['revoked_at'],
            'changed_at' => $witness['changed_at'], 'change_token' => $witness['change_token']];
    }

    private static function child(object $row, object $order): void
    {
        if ((string) $row->org_id !== (string) $order->org_id || (string) $row->site_id !== (string) $order->site_id
            || (string) $row->order_id !== (string) $order->id) {
            throw new CheckoutRefused('changed');
        }
    }

    private static function counter(mixed $value): int
    {
        if ((! is_int($value) && ! is_string($value)) || preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', (string) $value) !== 1
            || (int) $value > 2147483647) {
            throw new CheckoutRefused('changed');
        }

        return (int) $value;
    }

    /** @param array<string,mixed> $value */
    private static function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private static function now(): string
    {
        return CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
    }
}
