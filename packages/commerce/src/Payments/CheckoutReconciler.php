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
use Kitsune\Commerce\Models\CheckoutAttempt;
use Kitsune\Commerce\Models\Order;
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
 * Reconcile a recorded TEST Session and confirm captured payments under the original custody.
 * Provider reads retain #197's current-mode/witness rule; a fresh owner can explicitly supply a transient original-mode key.
 * An expired attempt or completed attempt with a paid receipt can finish local receipt bookkeeping without I/O.
 * Provider calls occur after the claim commits. Every durable graph and owner proof is checked around the I/O.
 *
 * @internal
 */
final class CheckoutReconciler
{
    public function __construct(private readonly OrderLocks $locks, private readonly CheckoutAttempts $attempts,
        private readonly OrderReaderData $orders, private readonly Context $context, private readonly Auditor $auditor,
        private readonly CredentialReader $credentials, private readonly StripeCheckout $stripe) {}

    /** Verified inbox callers establish the recorded Site first; metadata alone is never authority.
     * @return array{reference:string,state:string,expiresAt:string}
     */
    public function reconcile(string $orderReference, string $attemptReference, string $sessionId): array
    {
        return $this->run($orderReference, $attemptReference, $sessionId);
    }

    /** The supplied key lives only for this invocation; current org mode and original credential witness stay intact.
     * @return array{reference:string,state:string,expiresAt:string}
     */
    public function recoverAsOwner(string $orderReference, string $attemptReference, string $sessionId,
        Secret $key, #[SensitiveParameter] string $password): array
    {
        try {
            return $this->run($orderReference, $attemptReference, $sessionId, $key, $password);
        } finally {
            unset($password, $key);
        }
    }

    /** @return array{reference:string,state:string,expiresAt:string} */
    private function run(string $orderReference, string $attemptReference, string $sessionId, ?Secret $key = null,
        #[SensitiveParameter] ?string $password = null): array
    {
        $captured = null;
        try {
            $this->outside();
            self::reference($orderReference);
            self::reference($attemptReference);
            if ($sessionId !== '' && preg_match('/\Acs_(?:test|live)_[A-Za-z0-9_]{1,240}\z/D', $sessionId) !== 1) {
                throw new CheckoutRefused;
            }
            $owner = $key !== null;
            $captured = $this->locks->run(function () use ($orderReference, $attemptReference, $sessionId, $owner, $password): array {
                $identity = $this->authority($owner, $password);
                $order = $this->order($orderReference);
                $row = $this->find($order, $attemptReference);
                $this->binding($order, $row);
                $known = $sessionId === '' ? ($row->session_id ?? ($owner
                    ? app(StripeEventInbox::class)->sessionForAttempt($order->getConnection(), (int) $order->org_id, (object) $order->getAttributes(), $row)
                    : null)) : $sessionId;
                if (! is_string($known) || ($row->session_id !== null && $row->session_id !== $known) || $row->state === 'rejected') {
                    throw new CheckoutRefused('changed');
                }
                if ($row->lease_until !== null && CheckoutAttempts::instant($row->lease_until)->greaterThan(self::now())) {
                    throw new CheckoutRefused('busy');
                }
                if (! $owner && ($row->state === 'expired' || ($row->state === 'completed'
                    && app(PaidOrders::class)->receipt($order->getConnection(), (object) $order->getAttributes(), true) !== null))) {
                    // A prior verified reconciliation (including explicit historical owner recovery) already
                    // settled this exact Session. Finish its receipt without reopening old credential custody.
                    return ['terminal' => self::result($row)];
                }
                $witness = $owner ? null : $this->witness();
                if (! $owner && ! hash_equals($row->credential_witness, $witness)) {
                    throw new CheckoutRefused('changed');
                }
                $changes = ['lease_token' => bin2hex(random_bytes(32)), 'lease_until' => self::stored(self::now()->addSeconds(30)),
                    'updated_at' => self::stored(self::now())];
                $this->update($order, $row, $changes);
                $row = (object) [...(array) $row, ...$changes];
                $graph = $this->graph($order);
                $secret = $owner ? null : $this->credentials->secret(StripeCheckout::SLOT);
                if ($this->authority($owner, $password) !== $identity || (! $owner && ! hash_equals($witness, $this->witness()))) {
                    throw new CheckoutRefused('changed');
                }
                $fresh = $this->order($orderReference);
                $this->binding($fresh, $this->find($fresh, $attemptReference));
                if (! hash_equals($graph, $this->graph($fresh))) {
                    throw new CheckoutRefused('changed');
                }

                return ['row' => $row, 'sessionId' => $known, 'identity' => $identity, 'witness' => $witness, 'secret' => $secret,
                    'graph' => $graph];
            });
            if (isset($captured['terminal'])) {
                return $captured['terminal'];
            }
            $secret = $key ?? $captured['secret'];
            $this->outside();
            if ($this->stripe->account($secret, $captured['row']->mode) !== $captured['row']->account_id) {
                throw new CheckoutRefused('changed');
            }
            // Account lookup is itself I/O: revocation or a credential change must stop the next provider read too.
            $this->locks->run(fn () => $this->checked($orderReference, $attemptReference, $captured, $owner, $password));
            $session = $this->stripe->retrievePaid($secret, $captured['sessionId'], $captured['row']->mode);
            CheckoutSession::assertMatches($captured['row'], $session, $captured['sessionId']);

            return $this->locks->run(function () use ($orderReference, $attemptReference, $captured, $session, $owner, $password): array {
                $order = $this->checked($orderReference, $attemptReference, $captured, $owner, $password);
                $row = $this->find($order, $attemptReference);
                $state = in_array($row->state, ['completed', 'expired'], true) ? $row->state : CheckoutSession::state($session);
                $changes = ['state' => $state, 'active_order_id' => in_array($state, ['completed', 'expired'], true) ? null : $order->getKey(),
                    'session_id' => $session['id'], 'checkout_url' => $state === 'open' ? $session['url'] : null,
                    'lease_token' => null, 'lease_until' => null, 'updated_at' => self::stored(self::now())];
                $this->update($order, $row, $changes);
                $expected = (object) [...(array) $row, ...$changes];
                if ($state === 'completed') {
                    app(PaidOrders::class)->confirm($order->getConnection(), (object) $order->getAttributes(), $expected, $session);
                    $order = $this->order($orderReference);
                }
                $expectedGraph = $this->graph($order);
                $this->audit($owner ? 'commerce.checkout_owner_recovered' : 'commerce.checkout_reconciled', $expected);
                $this->checked($orderReference, $attemptReference, [...$captured, 'row' => $expected, 'graph' => $expectedGraph], $owner, $password);

                return self::result($expected);
            });
        } catch (Throwable $failure) {
            // A failed provider call does not change certainty. Release only our unchanged claim, otherwise let its lease expire.
            if (is_array($captured)) {
                try {
                    $this->locks->run(function () use ($orderReference, $attemptReference, $captured, $key, $password): void {
                        $order = $this->checked($orderReference, $attemptReference, $captured, $key !== null, $password);
                        $this->update($order, $captured['row'], ['lease_token' => null, 'lease_until' => null]);
                    });
                } catch (Throwable) {
                    // No secret or original exception is retained in an error chain or a log.
                }
            }
            throw new CheckoutRefused($failure instanceof CheckoutRefused ? $failure->reason : 'unavailable');
        } finally {
            unset($password, $key);
        }
    }

    /** @param array<string,mixed> $captured */
    private function checked(string $orderReference, string $attemptReference, array $captured, bool $owner,
        #[SensitiveParameter] ?string $password): Order
    {
        if ($this->authority($owner, $password) !== $captured['identity']) {
            throw new CheckoutRefused('changed');
        }
        $order = $this->order($orderReference);
        $row = $this->find($order, $attemptReference);
        $this->binding($order, $row);
        if (! hash_equals($captured['graph'], $this->graph($order)) || $row->lease_token !== $captured['row']->lease_token
            || ($row->lease_until !== null && CheckoutAttempts::instant($row->lease_until)->lessThanOrEqualTo(self::now()))
            || (! $owner && ! hash_equals($captured['witness'], $this->witness()))) {
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
        if (! in_array($row->state, ['prepared', 'cancelled', 'paid'], true) || ! in_array($row->mode, ['test', 'live'], true)) {
            throw new CheckoutRefused('live');
        }

        return (new Order)->newFromBuilder((array) $row);
    }

    private function find(Order $order, string $reference): object
    {
        return $this->attempts->rows($order->getConnection(), (int) $order->org_id, (object) $order->getAttributes(), true)
            ->first(static fn (object $row): bool => $row->reference === $reference) ?? throw new CheckoutRefused;
    }

    private function binding(Order $order, object $row): void
    {
        if ($row->api_version !== StripeCheckout::API_VERSION || ! hash_equals($row->order_hash,
            CheckoutReview::orderHash($this->orders->record($order->getConnection(), (object) $order->getAttributes())))) {
            throw new CheckoutRefused('changed');
        }
    }

    private function graph(Order $order): string
    {
        $rows = $this->attempts->rows($order->getConnection(), (int) $order->org_id, (object) $order->getAttributes(), true);
        $record = $this->orders->record($order->getConnection(), (object) $order->getAttributes());
        // Inbox receipts own a separate lease and may progress while this attempt's provider read is in flight.
        unset($record['stripe_events']);

        return CheckoutAttempts::digest(['order' => $record,
            'attempts' => $rows->map(static fn (object $row): array => (array) $row)->all()]);
    }

    /** Fresh transaction-local proof plus a stable identity/password witness across the separate transactions.
     * @return array<string,mixed>|null
     */
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
        $passwordColumn = $actor->getAuthPasswordName();
        $raw = $actor->getConnection()->table($actor->getTable())->useWritePdo()->where($actor->getKeyName(), $actor->getKey())
            ->lockForUpdate()->first([$passwordColumn]);
        $hash = $raw->{$passwordColumn} ?? null;
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
        if ($order->getConnection()->table(CheckoutAttempts::TABLE)->where('id', $row->id)->where('org_id', $row->org_id)
            ->where('site_id', $row->site_id)->where('order_id', $order->getKey())->update($changes) !== 1) {
            throw new CheckoutRefused;
        }
    }

    private function audit(string $action, object $row): void
    {
        $model = (new CheckoutAttempt)->newFromBuilder((array) $row);
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

    /** @return array{reference:string,state:string,expiresAt:string} */
    private static function result(object $row): array
    {
        return ['reference' => $row->reference, 'state' => $row->state,
            'expiresAt' => CheckoutAttempts::instant($row->expires_at)->format('Y-m-d\TH:i:s\Z')];
    }

    private static function reference(string $value): void
    {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $value) !== 1) {
            throw new CheckoutRefused;
        }
    }

    private static function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->startOfSecond();
    }

    private static function stored(CarbonImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s');
    }
}
