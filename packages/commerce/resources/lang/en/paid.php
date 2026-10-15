<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

return [
    'paid' => 'Test payment confirmed',
    'test_help' => 'This is a test payment. No real money has moved.',
    'amount' => 'Confirmed amount',
    'verified' => 'Confirmed at (UTC)',
    'allocations' => 'Product allocation',
    'allocated' => 'Stock allocated',
    'held' => 'Allocation on hold',
    'held_disputed' => 'The payment is disputed. Product allocation needs review.',
    'held_external_refund' => 'A refund already exists for this payment. Product allocation needs review.',
    'awarded' => 'Allocated units',
    'unavailable' => 'Unavailable units',
    'refund' => 'Refund',
    'amount_refund' => 'Refund amount',
    'owed' => 'Still owed',
    'reason_unavailable_items' => 'Unavailable products',
    'reason_cancelled_before_confirmation' => 'Order cancelled before payment confirmation',
    'state_owed' => 'Refund owed',
    'state_submitting' => 'Refund submission in progress',
    'state_uncertain' => 'Refund outcome needs checking',
    'state_unknown' => 'Refund outcome needs checking',
    'state_held' => 'Refund needs operator review',
    'state_pending' => 'Refund pending',
    'state_requires_action' => 'Refund needs action',
    'state_succeeded' => 'Refund completed',
    'state_failed' => 'Refund failed — still owed',
    'state_canceled' => 'Refund cancelled — still owed',
    'state_cancelled' => 'Refund cancelled — still owed',
    'item_net_minor' => 'Products before tax',
    'item_tax_minor' => 'Product tax',
    'shipping_net_minor' => 'Shipping before tax',
    'shipping_tax_minor' => 'Shipping tax',
    'history' => 'Refund history',
    'refund_actions' => 'Owner refund recovery',
    'reconcile' => 'Check or submit refund',
    'retry' => 'Authorize another refund attempt',
    'recovery_help' => 'Use the original Stripe account’s test key and your current password to check this refund. The key is used for this action only.',
    'retry_help' => 'A new attempt is allowed only after the previous refund is verified failed or cancelled. Earlier attempts remain in history.',
    'unrepresentable_refund' => 'This refund needs operator review because its exact amount cannot be submitted in the payment provider’s currency units. It remains owed.',
    'retained' => 'Cancellation and erasure are unavailable for this paid test order. Its payment and refund history is retained. You can still download your order data.',
    'completed_retained' => 'A completed test checkout needs payment reconciliation before cancellation or erasure is available. You can still download your order data.',
];
