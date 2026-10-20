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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Kitsune\Commerce\Carts\CartAmounts;
use Kitsune\Commerce\Models\CheckoutAttempt;
use Kitsune\Commerce\Models\Order;
use Kitsune\Commerce\Money\MinorUnits;
use Kitsune\Commerce\Orders\OrderAccess;
use Kitsune\Commerce\Orders\OrderJson;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Core\Audit\Auditor;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Credentials\CredentialReader;
use Kitsune\Core\Credentials\FreshCredentialAuthentication;
use Kitsune\Core\Credentials\Secret;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Readers\ReaderLinks;
use Kitsune\Core\Tenancy\Context;
use Throwable;

/** Durable explicitly admitted live or test Session orchestration. No provider I/O under a transaction, payment state, stock or access writes. @internal */
final class CheckoutWriter
{
    public function __construct(private readonly OrderLocks $locks, private readonly CheckoutReview $reviews,
        private readonly CheckoutAttempts $attempts, private readonly Context $context, private readonly Auditor $auditor,
        private readonly CredentialReader $credentials, private readonly StripeCheckout $stripe) {}

    /** @return array{reference:string,state:string,url:?string,expiresAt:string} */
    public function start(Request $request, mixed $reference, mixed $review): array
    {
        return $this->safe(function () use ($request, $reference, $review): array {
            $this->outside();
            $reference = self::reference($reference);
            $first = $this->locked(function () use ($request, $reference, $review): array {
                $order = $this->owned($request, $reference);
                $rows = $this->attempts->rows($order->getConnection(), (int) $order->org_id, (object) $order->getAttributes(), true);
                foreach ($rows as $row) {
                    if ($row->active_order_id !== null || $row->state === 'completed') {
                        return ['existing' => $row->reference];
                    }
                }
                $approved = $this->reviews->approve($request, $reference, $review);
                if (MinorUnits::integer($approved['record']['quoted_total_minor']) === '0') {
                    throw new CheckoutRefused('zero');
                }
                try {
                    StripeAmounts::assertCurrency($approved['record']['currency']);
                    StripeAmounts::toWire($approved['record']['quoted_total_minor'], $approved['record']['currency']);
                    foreach ($approved['record']['lines'] as $line) {
                        StripeAmounts::toWire($line['gross_minor'], $approved['record']['currency']);
                    }
                    if ($approved['record']['shipping'] !== null) {
                        StripeAmounts::toWire($approved['record']['shipping']['gross'], $approved['record']['currency']);
                    }
                } catch (StripeUnavailable) {
                    throw new CheckoutRefused('currency');
                }
                if ($rows->count() >= CheckoutAttempts::MAX_ATTEMPTS) {
                    throw new CheckoutRefused('limit');
                }

                return ['witness' => $this->witness(), 'orderHash' => $approved['orderHash'], 'mode' => $order->mode,
                    'account' => $order->mode === 'live' ? app(LiveStripeAdmission::class)->forNewCheckout() : null];
            });
            if (isset($first['existing'])) {
                return $this->operate($request, $reference, $first['existing']);
            }
            // Account discovery creates no Session and happens before claiming an immutable create request.
            $secret = $this->secret($first['witness']);
            $account = $this->stripe->account($secret, $first['mode']);
            $captured = $this->locked(function () use ($request, $reference, $review, $first, $account): array {
                $approved = $this->reviews->approve($request, $reference, $review);
                if (! hash_equals($first['orderHash'], $approved['orderHash']) || ! hash_equals($first['witness'], $this->witness())) {
                    throw new CheckoutRefused('changed');
                }
                $order = $approved['order'];
                if ($order->mode !== $first['mode'] || ($order->mode === 'live'
                    && ($account !== $first['account'] || app(LiveStripeAdmission::class)->forNewCheckout($account) !== $account))) {
                    throw new CheckoutRefused('changed');
                }
                $rows = $this->attempts->rows($order->getConnection(), (int) $order->org_id, (object) $order->getAttributes(), true);
                foreach ($rows as $row) {
                    if ($row->active_order_id !== null || $row->state === 'completed') {
                        return ['existing' => $row->reference];
                    }
                }
                if ($rows->count() >= CheckoutAttempts::MAX_ATTEMPTS) {
                    throw new CheckoutRefused('limit');
                }
                $now = self::now();
                $attempt = bin2hex(random_bytes(16));
                $site = $this->context->site() ?? throw new CheckoutRefused;
                $base = (ReaderLinks::origin($site) ?? throw new CheckoutRefused).($site->path_prefix ?? '');
                if ($order->mode === 'live' && parse_url($base, PHP_URL_SCHEME) !== 'https') {
                    throw new CheckoutRefused('setup');
                }
                // Stripe's remote minimum is 30m. Allow at most one setup minute, show exact expiry, never extend on retry.
                $expires = $now->addSeconds(1860);
                $payload = CheckoutAttempts::payload($approved['record'], $attempt, $expires->timestamp, $base);
                $data = ['org_id' => $order->org_id, 'site_id' => $order->site_id, 'order_id' => $order->getKey(),
                    'reference' => $attempt, 'active_order_id' => $order->getKey(), 'mode' => $order->mode, 'currency' => $approved['record']['currency'],
                    'account_id' => $account, 'api_version' => StripeCheckout::API_VERSION, 'credential_witness' => $first['witness'], 'state' => 'creating',
                    'request_payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'request_hash' => CheckoutAttempts::digest($payload),
                    'order_hash' => $approved['orderHash'], 'session_id' => null, 'checkout_url' => null,
                    'reviewed_at' => self::stored($now), 'expires_at' => self::stored($expires),
                    'lease_token' => bin2hex(random_bytes(32)), 'lease_until' => self::stored($now->addSeconds(30)),
                    'created_at' => self::stored($now), 'updated_at' => self::stored($now)];
                $id = $order->getConnection()->table(CheckoutAttempts::TABLE)->insertGetId($data);
                $expected = (object) [...$data, 'id' => $id];
                app(StripeEventInbox::class)->registerAttempt($order->getConnection(), $expected);
                $this->audit('commerce.checkout_started', $expected);
                $this->unchanged($expected, $order);
                $this->reviews->approve($request, $reference, $review);
                if (! hash_equals($first['witness'], $this->witness())) {
                    throw new CheckoutRefused('changed');
                }

                return ['row' => $expected];
            });
            if (isset($captured['existing'])) {
                return $this->operate($request, $reference, $captured['existing']);
            }

            return $this->perform($request, $reference, $captured['row'], 'create', true);
        });
    }

    /** @return array{reference:string,state:string,url:?string,expiresAt:string} */
    public function refresh(Request $request, mixed $reference, mixed $attempt): array
    {
        return $this->safe(fn (): array => $this->operate($request, self::reference($reference), self::reference($attempt)));
    }

    /** Expiry is an explicit POST. Replay an uncertain create first to recover its exact Session, never a new identity.
     * @return array{reference:string,state:string,url:?string,expiresAt:string}
     */
    public function expire(Request $request, mixed $reference, mixed $attempt): array
    {
        return $this->safe(function () use ($request, $reference, $attempt): array {
            $reference = self::reference($reference);
            $attempt = self::reference($attempt);
            $result = $this->operate($request, $reference, $attempt);

            return $result['state'] === 'open' ? $this->operate($request, $reference, $attempt, expire: true) : $result;
        });
    }

    /** A current org owner may reconcile a lost Session ID copied from the provider dashboard. It grants no buyer proof.
     * @return array{reference:string,state:string,url:?string,expiresAt:string}
     */
    public function operateAsOwner(mixed $reference, mixed $attempt, ?string $sessionId = null, bool $expire = false): array
    {
        return $this->safe(function () use ($reference, $attempt, $sessionId, $expire): array {
            $reference = self::reference($reference);
            $attempt = self::reference($attempt);
            if ($sessionId !== null && preg_match('/\Acs_(?:test|live)_[A-Za-z0-9_]{1,240}\z/D', $sessionId) !== 1) {
                throw new CheckoutRefused;
            }
            $result = $this->operate(null, $reference, $attempt, knownSession: $sessionId);

            return $expire && $result['state'] === 'open' ? $this->operate(null, $reference, $attempt, expire: true) : $result;
        });
    }

    /** @return array{reference:string,state:string,url:?string,expiresAt:string} */
    private function operate(?Request $request, string $reference, string $attempt, bool $expire = false, ?string $knownSession = null): array
    {
        $this->outside();
        $captured = $this->locked(function () use ($request, $reference, $attempt, $knownSession): object {
            $order = $this->owned($request, $reference);
            $row = $this->find($order, $attempt);
            if (in_array($row->state, ['completed', 'expired', 'rejected'], true)) {
                return $row;
            }
            if ($row->api_version !== StripeCheckout::API_VERSION
                || ! hash_equals($row->order_hash, CheckoutReview::orderHash(app(OrderReaderData::class)->record($order->getConnection(), (object) $order->getAttributes())))
                || ! hash_equals($row->credential_witness, $this->witness())) {
                throw new CheckoutRefused('changed');
            }
            if ($row->lease_until !== null && CheckoutAttempts::instant($row->lease_until)->greaterThan(self::now())) {
                throw new CheckoutRefused('busy');
            }
            if ($knownSession !== null && $row->session_id !== null && $row->session_id !== $knownSession) {
                throw new CheckoutRefused('changed');
            }
            if ($row->session_id === null && $knownSession === null && CheckoutAttempts::instant($row->created_at)->addHours(23)->lessThanOrEqualTo(self::now())) {
                throw new CheckoutRefused('operator');
            }
            $changes = ['lease_token' => bin2hex(random_bytes(32)), 'lease_until' => self::stored(self::now()->addSeconds(30)), 'updated_at' => self::stored(self::now())];
            $this->update($order, $row, $changes);

            return (object) [...(array) $row, ...$changes];
        });
        if (in_array($captured->state, ['completed', 'expired', 'rejected'], true)) {
            return self::result($captured);
        }
        $operation = $expire ? 'expire' : (($captured->session_id ?? $knownSession) === null ? 'create' : 'retrieve');

        return $this->perform($request, $reference, $captured, $operation, false, $knownSession);
    }

    /** @return array{reference:string,state:string,url:?string,expiresAt:string} */
    private function perform(?Request $request, string $reference, object $captured, string $operation, bool $first, ?string $knownSession = null): array
    {
        $this->outside();
        $this->locked(function () use ($request, $reference, $captured, $operation): void {
            $order = $this->owned($request, $reference);
            $this->unchanged($captured, $order);
            if ($operation === 'create' && $captured->mode === 'live') {
                app(LiveStripeAdmission::class)->forNewCheckout($captured->account_id);
            }
            $row = $this->find($order, $captured->reference);
            if ($row->lease_token !== $captured->lease_token || ! hash_equals($row->request_hash, $captured->request_hash)
                || ! hash_equals($row->order_hash, CheckoutReview::orderHash(app(OrderReaderData::class)->record($order->getConnection(), (object) $order->getAttributes())))
                || ! hash_equals($row->credential_witness, $this->witness())) {
                throw new CheckoutRefused('changed');
            }
        });
        $session = null;
        $rejected = false;
        try {
            $secret = $this->secret($captured->credential_witness);
            if (! $first && $this->stripe->account($secret, $captured->mode) !== $captured->account_id) {
                throw new CheckoutRefused('changed');
            }
            $this->locked(function () use ($request, $reference, $captured, $operation): void {
                $order = $this->owned($request, $reference);
                $this->unchanged($captured, $order);
                if (! hash_equals($captured->credential_witness, $this->witness())) {
                    throw new CheckoutRefused('changed');
                }
                if ($operation === 'create' && $captured->mode === 'live') {
                    app(LiveStripeAdmission::class)->forNewCheckout($captured->account_id);
                }
            });
            $idempotency = 'kitsune-checkout-'.$captured->org_id.'-'.$captured->site_id.'-'.$captured->reference;
            $session = match ($operation) {
                'create' => $this->stripe->create($secret, CheckoutAttempts::request($captured), $idempotency, $captured->mode),
                'retrieve' => $this->stripe->retrieve($secret, $captured->session_id ?? $knownSession ?? throw new CheckoutRefused, $captured->mode),
                'expire' => $this->stripe->expire($secret, $captured->session_id ?? throw new CheckoutRefused, $idempotency.'-expire', $captured->mode),
                default => throw new CheckoutRefused,
            };
            $this->matchSession($captured, $session, $knownSession);
        } catch (StripeUnavailable $failure) {
            $rejected = $first && $operation === 'create' && $failure->category === 'rejected';
            $session = null;
        } catch (Throwable) {
            $session = null;
        }

        return $this->locked(function () use ($request, $reference, $captured, $session, $rejected): array {
            $order = $this->owned($request, $reference);
            $this->unchanged($captured, $order);
            $row = $this->find($order, $captured->reference);
            if ($row->lease_token !== $captured->lease_token || ! hash_equals($row->request_hash, $captured->request_hash)
                || ! hash_equals($row->order_hash, CheckoutReview::orderHash(app(OrderReaderData::class)->record($order->getConnection(), (object) $order->getAttributes())))
                || ! hash_equals($row->credential_witness, $this->witness())) {
                throw new CheckoutRefused('changed');
            }
            $state = $session === null ? ($rejected ? 'rejected' : 'uncertain')
                : match ($session['status']) {
                    'open' => 'open', 'expired' => 'expired', 'complete' => $session['paymentStatus'] === 'paid' ? 'completed' : 'uncertain', default => throw new CheckoutRefused
                };
            $changes = ['state' => $state, 'active_order_id' => in_array($state, ['expired', 'completed', 'rejected'], true) ? null : $order->getKey(),
                'session_id' => $session['id'] ?? $row->session_id, 'checkout_url' => $state === 'open' ? $session['url'] : null,
                'lease_token' => null, 'lease_until' => null, 'updated_at' => self::stored(self::now())];
            $this->update($order, $row, $changes);
            $expected = (object) [...(array) $row, ...$changes];
            $this->audit('commerce.checkout_'.$state, $expected);
            $this->unchanged($expected, $order);
            $fresh = $this->owned($request, $reference);
            if (! hash_equals($row->order_hash, CheckoutReview::orderHash(app(OrderReaderData::class)->record($fresh->getConnection(), (object) $fresh->getAttributes())))
                || ! hash_equals($row->credential_witness, $this->witness())) {
                throw new CheckoutRefused('changed');
            }

            return self::result($expected);
        });
    }

    /** @param array<string,mixed> $session */
    private function matchSession(object $row, array $session, ?string $knownSession): void
    {
        $payload = CheckoutAttempts::request($row);
        $total = '0';
        foreach ($payload['line_items'] as $item) {
            $total = CartAmounts::add($total, $item['price_data']['unit_amount']);
        }
        if ($session['test'] !== StripeMode::isTest($row->mode) || ! StripeMode::session($session['id'], $row->mode) || $session['currency'] !== $row->currency || $session['amountTotal'] !== $total
            || $session['clientReference'] !== $payload['client_reference_id'] || $session['attemptReference'] !== $row->reference
            || $session['orderReference'] !== $payload['metadata']['kitsune_order'] || $session['expiresAt'] !== $payload['expires_at']
            || ($row->session_id !== null && $session['id'] !== $row->session_id) || ($knownSession !== null && $session['id'] !== $knownSession)
            || $session['paymentStatus'] === 'no_payment_required'
            || ($session['status'] === 'open' && ($session['paymentStatus'] !== 'unpaid' || $session['url'] === null))
            || ($session['status'] === 'expired' && $session['paymentStatus'] !== 'unpaid')
            || ($session['paymentStatus'] === 'paid' && ($session['status'] !== 'complete' || $session['paymentIntent'] === null))) {
            throw new CheckoutRefused;
        }
    }

    private function owned(?Request $request, string $reference): Order
    {
        if ($request === null) {
            $proof = app(FreshCredentialAuthentication::class)->confirm(null, requirePassword: false);
            $row = app(OrderReaderData::class)->find((new Order)->getConnection(), $this->context->orgId() ?? throw new CheckoutRefused,
                $this->context->siteId() ?? throw new CheckoutRefused, $reference, true) ?? throw new CheckoutRefused;
            app(OrderReaderData::class)->record((new Order)->getConnection(), $row);
            $proof->assertCurrent();
            $order = (new Order)->newFromBuilder((array) $row);
        } else {
            $order = app(OrderAccess::class)->owned($request, $reference) ?? throw new CheckoutRefused;
        }
        if (! in_array($order->getAttributes()['mode'], ['test', 'live'], true)) {
            throw new CheckoutRefused('live');
        }
        if ($order->getAttributes()['state'] !== 'prepared') {
            throw new CheckoutRefused('order_state');
        }

        return $order;
    }

    private function find(Order $order, string $reference): object
    {
        return $this->attempts->rows($order->getConnection(), (int) $order->org_id, (object) $order->getAttributes(), true)
            ->first(static fn (object $row): bool => $row->reference === $reference) ?? throw new CheckoutRefused;
    }

    /** A fresh primary read; host callbacks can replace credentials or mode between checks.
     * @phpstan-impure
     */
    private function witness(): string
    {
        $connection = (new Order)->getConnection();
        $org = $this->context->orgId() ?? throw new CheckoutRefused;
        $receipt = $connection->table('modules')->useWritePdo()->where('handle', 'kitsune/commerce')->where('is_enabled', true)->lockForUpdate()->first();
        if ($receipt === null || $receipt->version !== ModuleDiscovery::version('kitsune/commerce')) {
            throw new CheckoutRefused;
        }

        return StripeMode::witness($connection, $org, StripeMode::current($connection, $org));
    }

    private function secret(string $witness): Secret
    {
        // Current-mode reader stays unchanged. The witness deliberately refuses replacements and APP_KEY resealing.
        return $this->locked(function () use ($witness): Secret {
            $this->assertWitness($witness);
            $secret = $this->credentials->secret(StripeCheckout::SLOT);
            $this->assertWitness($witness);

            return $secret;
        });
    }

    /** The fresh check is repeated around host model retrieval hooks inside the same Org lock.
     * @phpstan-impure
     */
    private function assertWitness(string $witness): void
    {
        if (! hash_equals($witness, $this->witness())) {
            throw new CheckoutRefused('changed');
        }
    }

    /** @param array<string,mixed> $changes */
    private function update(Order $order, object $row, array $changes): void
    {
        if ($order->getConnection()->table(CheckoutAttempts::TABLE)->where('id', $row->id)->where('org_id', $row->org_id)
            ->where('site_id', $row->site_id)->where('order_id', $order->getKey())->update($changes) !== 1) {
            throw new CheckoutRefused;
        }
    }

    private function unchanged(object $expected, Order $order): void
    {
        $stored = $this->find($order, $expected->reference);
        foreach ((array) $expected as $column => $value) {
            if (($value === null) !== ($stored->{$column} === null) || ($value !== null && ($column === 'request_payload'
                ? ! OrderJson::same($value, $stored->{$column}) : (string) $value !== (string) $stored->{$column}))) {
                throw new CheckoutRefused;
            }
        }
    }

    private function audit(string $action, object $row): void
    {
        $model = (new CheckoutAttempt)->newFromBuilder((array) $row);
        $actor = Permissions::currentUser();
        $audit = $this->auditor->recordOrFail($action, $model);
        $saved = (new AuditLog)->getConnection()->table('audit_log')->useWritePdo()->where('id', $audit->getKey())->lockForUpdate()->first();
        foreach (['org_id' => $row->org_id, 'site_id' => $row->site_id, 'action' => $action,
            'target_type' => $model->getMorphClass(), 'target_id' => (string) $row->id,
            'actor_type' => $actor === null ? null : ($actor instanceof Model ? $actor->getMorphClass() : $actor::class),
            'actor_id' => $actor === null ? null : (string) $actor->getAuthIdentifier()] as $column => $value) {
            if ($saved === null || ($value === null) !== ($saved->{$column} === null) || ($value !== null && (string) $saved->{$column} !== (string) $value)) {
                throw new CheckoutRefused;
            }
        }
    }

    /** @return array{reference:string,state:string,url:?string,expiresAt:string} */
    private static function result(object $row): array
    {
        return ['reference' => $row->reference, 'state' => $row->state, 'url' => $row->state === 'open' ? $row->checkout_url : null,
            'expiresAt' => CheckoutAttempts::instant($row->expires_at)->format('Y-m-d\TH:i:s\Z')];
    }

    /** @template T
     * @param  Closure():T  $work
     * @return T
     */
    private function locked(Closure $work): mixed
    {
        return $this->locks->run(fn () => $work());
    }

    private function outside(): void
    {
        if ((new Order)->getConnection()->transactionLevel() !== 0) {
            throw new CheckoutRefused;
        }
    }

    /** @param Closure():array{reference:string,state:string,url:?string,expiresAt:string} $work
     * @return array{reference:string,state:string,url:?string,expiresAt:string}
     */
    private function safe(Closure $work): array
    {
        try {
            return $work();
        } catch (CheckoutRefused $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new CheckoutRefused;
        }
    }

    private static function reference(mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A[a-f0-9]{32}\z/D', $value) !== 1) {
            throw new CheckoutRefused;
        }

        return $value;
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
