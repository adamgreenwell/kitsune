<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Payments;

use Closure;
use Kitsune\Commerce\Carts\CartAmounts;
use Kitsune\Core\Credentials\Secret;

/** Bounded original-payment reads. Snapshot events and embedded refund subsets never establish financial state.
 * @internal
 *
 * @phpstan-import-type Charge from StripeCheckout
 * @phpstan-import-type Refund from StripeCheckout
 * @phpstan-import-type Dispute from StripeCheckout
 *
 * @phpstan-type Graph array{charge:Charge,refunds:list<Refund>,disputes:list<Dispute>,digest:string}
 */
final class StripeFinancialGraph
{
    public function __construct(private readonly StripeCheckout $stripe) {}

    /** The caller verifies account identity and fences local custody before/after each network operation.
     * The finite search must finish; absent objects in a truncated list never authorize access or another POST.
     *
     * @param  Closure():void  $checkpoint
     * @return Graph
     */
    public function retrieve(Secret $secret, object $receipt, Closure $checkpoint): array
    {
        $checkpoint();
        $charge = $this->stripe->retrieveCharge($secret, $receipt->charge_id);
        $this->chargeProof($charge, $receipt);
        $checkpoint();
        $refunds = [];
        $cursor = null;
        for ($pageNumber = 0; $pageNumber < 2; $pageNumber++) {
            $page = $this->stripe->listRefunds($secret, $receipt->charge_id, $cursor);
            $checkpoint();
            foreach ($page['refunds'] as $refund) {
                $this->parentProof($refund, $receipt);
                if (isset($refunds[$refund['id']])) {
                    throw new CheckoutRefused('changed');
                }
                $refunds[$refund['id']] = $refund;
            }
            if (! $page['hasMore']) {
                break;
            }
            $cursor = $page['next'];
        }
        if ($page['hasMore']) {
            throw new CheckoutRefused('operator');
        }
        $disputes = [];
        $cursor = null;
        for ($pageNumber = 0; $pageNumber < 2; $pageNumber++) {
            $page = $this->stripe->listDisputes($secret, $receipt->charge_id, $cursor);
            $checkpoint();
            foreach ($page['disputes'] as $dispute) {
                $this->parentProof($dispute, $receipt);
                if (isset($disputes[$dispute['id']])) {
                    throw new CheckoutRefused('changed');
                }
                $disputes[$dispute['id']] = $dispute;
            }
            if (! $page['hasMore']) {
                break;
            }
            $cursor = $page['next'];
        }
        if ($page['hasMore']) {
            throw new CheckoutRefused('operator');
        }
        $current = $this->stripe->retrieveCharge($secret, $receipt->charge_id);
        $this->chargeProof($current, $receipt);
        $checkpoint();
        if ($charge !== $current || ($charge['disputed'] && $disputes === [])) {
            throw new CheckoutRefused('changed');
        }
        // Amount equality is not imposed on disputes: issuers may dispute a partial or larger amount.
        $total = '0';
        foreach ($refunds as $refund) {
            if (! in_array($refund['status'], ['failed', 'canceled'], true)) {
                $total = CartAmounts::add($total, $refund['amount']);
            }
        }
        if (AllocationAmounts::compare($total, $charge['captured']) > 0
            || AllocationAmounts::compare($charge['refunded'], $total) > 0) {
            throw new CheckoutRefused('changed');
        }
        ksort($refunds);
        ksort($disputes);
        $graph = ['charge' => $charge, 'refunds' => array_values($refunds), 'disputes' => array_values($disputes)];

        return [...$graph, 'digest' => CheckoutAttempts::digest($graph)];
    }

    /** @param Charge $charge */
    private function chargeProof(array $charge, object $receipt): void
    {
        if ($charge['id'] !== $receipt->charge_id || $charge['paymentIntent'] !== $receipt->payment_intent_id
            || $charge['currency'] !== $receipt->currency || $charge['amount'] !== StripeAmounts::toWire($receipt->amount_minor, $receipt->currency)
            || $charge['captured'] !== $charge['amount'] || ! $charge['paid'] || ! $charge['capturedFully'] || $charge['status'] !== 'succeeded') {
            throw new CheckoutRefused('changed');
        }
    }

    /** @param Refund|Dispute $child */
    private function parentProof(array $child, object $receipt): void
    {
        if ($child['chargeId'] !== $receipt->charge_id || $child['paymentIntent'] !== $receipt->payment_intent_id
            || $child['currency'] !== $receipt->currency) {
            throw new CheckoutRefused('changed');
        }
    }
}
