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
use Kitsune\Commerce\Models\Order;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Credentials\CredentialReader;
use Kitsune\Core\Credentials\FreshCredentialAuthentication;
use Kitsune\Core\Credentials\Secret;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Tenancy\Context;
use SensitiveParameter;
use Throwable;

/** Complete original-account financial refresh and durable selected-unit refund submission. @internal */
final class FinancialReconciler
{
    private const RETRY_HOURS = 23;

    public function __construct(private readonly OrderLocks $locks, private readonly Context $context,
        private readonly CredentialReader $credentials, private readonly StripeCheckout $stripe,
        private readonly StripeFinancialGraph $graphs, private readonly FinancialState $ledger) {}

    /** Successful financial states remain refreshable: refunds can fail late and disputes can be won late.
     * @return array{state:string,category:?string}
     */
    public function reconcile(string $orderReference): array
    {
        return $this->run($orderReference);
    }

    /** Only an already persisted, explicitly confirmed owner request can reach this submission door.
     * @return array{state:string,category:?string}
     */
    public function submit(string $orderReference, string $refundReference): array
    {
        return $this->run($orderReference, $refundReference);
    }

    /** The transient original-account key never rewrites configured credentials, mode or checkout witnesses.
     * @return array{state:string,category:?string}
     */
    public function recoverAsOwner(string $orderReference, Secret $key, #[SensitiveParameter] string $password,
        ?string $refundReference = null): array
    {
        try {
            return $this->run($orderReference, $refundReference, $key, $password);
        } finally {
            unset($key, $password);
        }
    }

    /** @return array{state:string,category:?string} */
    private function run(string $reference, ?string $refundReference = null, ?Secret $key = null,
        #[SensitiveParameter] ?string $password = null): array
    {
        $captured = null;
        $proof = null;
        $witness = null;
        $owner = $key !== null;
        try {
            $this->outside();
            self::reference($reference);
            if ($refundReference !== null) {
                self::reference($refundReference);
            }
            // Credential admission precedes the committed lease. A refused historical key must not make an
            // otherwise authorized explicit-owner recovery wait for a lease belonging to a call that made no I/O.
            $this->locks->run(function () use ($reference, $refundReference, $owner, $password): void {
                $this->authority($owner, $password);
                if ($owner) {
                    return;
                }
                $connection = (new Order)->getConnection();
                $order = $connection->table('commerce_orders')->useWritePdo()->where('org_id', $this->context->orgId())
                    ->where('site_id', $this->context->siteId())->where('reference', $reference)->lockForUpdate()->first() ?? throw new CheckoutRefused;
                $frame = $this->ledger->checked($connection, $order, true);
                $current = $this->witness();
                $expected = $this->checkoutWitness($order, (object) $frame['basis']['receipt']);
                if ($refundReference !== null) {
                    $matches = array_values(array_filter($frame['refunds'], static fn (object $row): bool => $row->reference === $refundReference));
                    if (count($matches) !== 1) {
                        throw new CheckoutRefused;
                    }
                    $expected = $matches[0]->credential_witness;
                }
                if ($expected !== null && ! hash_equals($expected, $current)) {
                    throw new CheckoutRefused('changed');
                }
            });
            $captured = $refundReference === null ? $this->ledger->claimGraph($reference)
                : $this->ledger->claimRefund($reference, $refundReference);
            [$proof, $witness, $secret] = $this->locks->run(function () use ($captured, $owner, $password, $key): array {
                $proof = $this->authority($owner, $password);
                $this->ledger->checkGraphClaim((new Order)->getConnection(), $captured['order'], $captured);
                $witness = $owner ? hash('sha256', "transient-financial-key\0".$key->reveal()) : $this->witness();
                $receipt = (object) $captured['basis']['receipt'];
                if (! $owner) {
                    $expected = isset($captured['refund']) ? $captured['credential_witness'] : $this->checkoutWitness($captured['order'], $receipt);
                    if ($expected !== null && ! hash_equals($expected, $witness)) {
                        throw new CheckoutRefused('changed');
                    }
                }

                return [$proof, $witness, $key ?? $this->credentials->secret(StripeCheckout::SLOT)];
            });
            $checkpoint = function () use (&$captured, $owner, $password, $proof, $witness): void {
                $this->locks->run(fn () => $this->checked($captured, $owner, $password, $proof, $witness));
            };
            $receipt = (object) $captured['basis']['receipt'];
            $checkpoint();
            if ($this->stripe->account($secret, $receipt->mode) !== $receipt->account_id) {
                throw new CheckoutRefused('changed');
            }
            $checkpoint();
            $hint = null;
            if ($refundReference !== null) {
                $hint = $this->locks->run(function () use ($captured, $owner, $password, $proof, $witness): ?string {
                    $this->checked($captured, $owner, $password, $proof, $witness);

                    return app(StripeEventInbox::class)->financialRefundForAttempt((new Order)->getConnection(),
                        (int) $captured['order']->org_id, $captured['order'], $captured['refund']);
                });
                if ($hint !== null && $captured['refund']->refund_id === null) {
                    $hinted = $this->stripe->retrieveRefund($secret, $hint, $receipt->mode);
                    $checkpoint();
                    if ($hinted['chargeId'] !== $receipt->charge_id || $hinted['paymentIntent'] !== $receipt->payment_intent_id
                        || $hinted['currency'] !== $receipt->currency || $hinted['amount'] !== $captured['request']['amount']
                        || $hinted['metadata'] !== $captured['request']['metadata']) {
                        throw new CheckoutRefused('changed');
                    }
                }
            }
            $graph = $this->graphs->retrieve($secret, $receipt, $checkpoint);
            if ($hint !== null && ! in_array($hint, array_column($graph['refunds'], 'id'), true)) {
                // A known signed identity is never converted back to an absent/unknown operation that can POST.
                throw new CheckoutRefused('operator');
            }
            if ($refundReference === null) {
                return $this->locks->run(function () use ($captured, $graph, $owner, $password, $proof, $witness): array {
                    $this->checked($captured, $owner, $password, $proof, $witness);
                    $this->ledger->finishGraph((new Order)->getConnection(), $captured['order'], $captured, $graph);
                    $this->admission($owner, $password, $proof, $witness);

                    return $this->result($captured['order']);
                });
            }
            $captured = $this->locks->run(function () use ($captured, $graph, $owner, $password, $proof, $witness): array {
                $this->checked($captured, $owner, $password, $proof, $witness);
                $next = $this->ledger->observeForRefund((new Order)->getConnection(), $captured['order'], $captured, $graph);
                $this->admission($owner, $password, $proof, $witness);

                return $next;
            });
            $checkpoint();
            $refund = $captured['refund'];
            $known = $refund->refund_id;
            if ($known !== null) {
                $current = $this->stripe->retrieveRefund($secret, $known, $receipt->mode);
                $checkpoint();
            } else {
                if (in_array($refund->state, ['failed', 'canceled', 'succeeded', 'held'], true)
                    || ($refund->first_submitted_at !== null && ! CheckoutAttempts::instant($refund->first_submitted_at)
                        ->addHours(self::RETRY_HOURS)->greaterThan(CarbonImmutable::now('UTC')))) {
                    throw new CheckoutRefused('operator');
                }
                // Identity and deadline must commit before the first possibly ambiguous money request.
                $captured = $this->locks->run(function () use ($captured, $owner, $password, $proof, $witness): array {
                    $this->checked($captured, $owner, $password, $proof, $witness);

                    return $this->ledger->submitting((new Order)->getConnection(), $captured['order'], $captured,
                        $captured['credential_witness'] ?? $witness);
                });
                $checkpoint();
                $current = $this->stripe->createRefund($secret, $captured['request'], $captured['idempotency_key'], $receipt->mode);
                $checkpoint();
            }

            return $this->locks->run(function () use ($captured, $current, $owner, $password, $proof, $witness): array {
                $this->checked($captured, $owner, $password, $proof, $witness);
                $this->ledger->finishRefund((new Order)->getConnection(), $captured['order'], $captured, $current);
                $this->admission($owner, $password, $proof, $witness);

                return $this->result($captured['order']);
            });
        } catch (Throwable $failure) {
            if (is_array($captured) && is_string($witness)) {
                try {
                    $this->locks->run(function () use ($captured, $owner, $password, $proof, $witness, $failure): void {
                        $this->checked($captured, $owner, $password, $proof, $witness);
                        $this->ledger->markUnknown((new Order)->getConnection(), $captured['order'], $captured,
                            $failure instanceof CheckoutRefused && in_array($failure->reason, ['operator', 'changed'], true) ? $failure->reason : 'unavailable');
                    });
                } catch (Throwable) {
                    // Changed custody is never overwritten; its existing bounded lease is allowed to expire.
                }
            }
            throw new CheckoutRefused($failure instanceof CheckoutRefused ? $failure->reason : 'unavailable');
        } finally {
            unset($key, $password, $secret);
        }
    }

    /** @param array<string,mixed> $captured
     * @param  array<string,mixed>|null  $proof
     */
    private function checked(array $captured, bool $owner, #[SensitiveParameter] ?string $password, ?array $proof, string $witness): void
    {
        $this->admission($owner, $password, $proof, $witness);
        $this->ledger->checkGraphClaim((new Order)->getConnection(), $captured['order'], $captured);
    }

    /** This final guard also runs after ledger audits have released their provider lease.
     * @param  array<string,mixed>|null  $proof
     */
    private function admission(bool $owner, #[SensitiveParameter] ?string $password, ?array $proof, string $witness): void
    {
        if ($this->authority($owner, $password) !== $proof || (! $owner && ! hash_equals($witness, $this->witness()))) {
            throw new CheckoutRefused('changed');
        }
    }

    /** @return array<string,mixed>|null */
    private function authority(bool $owner, #[SensitiveParameter] ?string $password): ?array
    {
        $connection = (new Order)->getConnection();
        $module = $connection->table('modules')->useWritePdo()->where('handle', 'kitsune/commerce')->where('is_enabled', true)->lockForUpdate()->first();
        if ($this->context->orgId() === null || $this->context->siteId() === null || $module === null
            || $module->version !== ModuleDiscovery::version('kitsune/commerce')) {
            throw new CheckoutRefused('setup');
        }
        if (! $owner) {
            return null;
        }
        $authentication = app(FreshCredentialAuthentication::class)->confirm($password);
        $actor = Permissions::currentUser();
        if (! $actor instanceof Model) {
            throw new CheckoutRefused;
        }
        $column = $actor->getAuthPasswordName();
        $row = $actor->getConnection()->table($actor->getTable())->useWritePdo()->where($actor->getKeyName(), $actor->getKey())
            ->lockForUpdate()->first([$column]);
        $hash = $row->{$column} ?? null;
        if (! is_string($hash)) {
            throw new CheckoutRefused;
        }
        $authentication->assertCurrent();

        return ['org' => $this->context->orgId(), 'site' => $this->context->siteId(), 'class' => $actor::class,
            'id' => (string) $actor->getKey(), 'password' => hash('sha256', $hash)];
    }

    private function witness(): string
    {
        $connection = (new Order)->getConnection();
        $org = $this->context->orgId() ?? throw new CheckoutRefused;

        return StripeMode::witness($connection, $org, StripeMode::current($connection, $org));
    }

    private function checkoutWitness(object $order, object $receipt): string
    {
        $row = (new Order)->getConnection()->table(CheckoutAttempts::TABLE)->useWritePdo()->where('id', $receipt->attempt_id)
            ->where('org_id', $order->org_id)->where('site_id', $order->site_id)->where('order_id', $order->id)->lockForUpdate()->first();
        if ($row === null || $row->account_id !== $receipt->account_id || $row->session_id !== $receipt->session_id
            || $row->api_version !== StripeCheckout::API_VERSION || $row->mode !== $order->mode
            || ! is_string($row->credential_witness) || preg_match('/\A[a-f0-9]{64}\z/D', $row->credential_witness) !== 1) {
            throw new CheckoutRefused('changed');
        }

        return $row->credential_witness;
    }

    /** @return array{state:string,category:?string} */
    private function result(object $order): array
    {
        $plan = $this->ledger->accessPlan((new Order)->getConnection(), $order, true);

        return ['state' => $plan['blocked'] ? 'held' : 'processed', 'category' => $plan['category']];
    }

    private function outside(): void
    {
        if ((new Order)->getConnection()->transactionLevel() !== 0 || (new Order)->getConnection()->getPdo()->inTransaction()) {
            throw new CheckoutRefused;
        }
    }

    private static function reference(string $value): void
    {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $value) !== 1) {
            throw new CheckoutRefused;
        }
    }
}
