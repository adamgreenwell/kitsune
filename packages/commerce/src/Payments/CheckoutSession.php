<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Payments;

use Kitsune\Commerce\Carts\CartAmounts;

/** Exact immutable Session comparison shared by verified-event and explicitly authorized historical recovery. @internal */
final class CheckoutSession
{
    /** @param array<string,mixed> $session */
    public static function assertMatches(object $row, array $session, string $sessionId): void
    {
        $payload = CheckoutAttempts::request($row);
        $total = '0';
        foreach ($payload['line_items'] as $item) {
            $total = CartAmounts::add($total, $item['price_data']['unit_amount']);
        }
        if ($session['test'] !== StripeMode::isTest($row->mode) || ! StripeMode::session($session['id'], $row->mode) || $session['currency'] !== $row->currency || $session['amountTotal'] !== $total
            || $session['clientReference'] !== $payload['client_reference_id'] || $session['attemptReference'] !== $row->reference
            || $session['orderReference'] !== $payload['metadata']['kitsune_order'] || $session['expiresAt'] !== $payload['expires_at']
            || $session['id'] !== $sessionId || ($row->session_id !== null && $session['id'] !== $row->session_id)
            || $session['paymentStatus'] === 'no_payment_required'
            || ($session['status'] === 'open' && ($session['paymentStatus'] !== 'unpaid' || $session['url'] === null))
            || ($session['status'] === 'expired' && $session['paymentStatus'] !== 'unpaid')
            || ($session['paymentStatus'] === 'paid' && ($session['status'] !== 'complete' || $session['paymentIntent'] === null))) {
            throw new CheckoutRefused('changed');
        }
    }

    /** @param array<string,mixed> $session */
    public static function state(array $session): string
    {
        return match ($session['status']) {
            'open' => 'open', 'expired' => 'expired', 'complete' => $session['paymentStatus'] === 'paid' ? 'completed' : 'uncertain',
            default => throw new CheckoutRefused,
        };
    }
}
