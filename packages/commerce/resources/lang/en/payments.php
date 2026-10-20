<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

return [
    'credential_label' => 'Stripe secret key', 'credential_help' => 'Paste the key for this mode. Hosted Checkout currently supports test mode only. The key is never shown again.',
    'webhook_credential_label' => 'Stripe webhook signing secret', 'webhook_credential_help' => 'Use the signing secret for this store’s snapshot webhook endpoint and this mode. It is never shown again.',
    'webhook_previous_label' => 'Previous Stripe webhook signing secret', 'webhook_previous_help' => 'Optional explicit overlap during a signing-secret rotation. Clear it when Stripe stops signing with that secret.',
    'updates' => 'Stripe updates', 'update_pending' => 'Waiting to check', 'update_processing' => 'Checking',
    'update_processed' => 'Checked', 'update_retryable' => 'Check needs another try', 'update_blocked' => 'Owner recovery needed',
    'update_erased' => 'Order details removed', 'update_received' => 'Received',
    'title' => 'Checkout', 'skip' => 'Skip to checkout content', 'navigation' => 'Store navigation', 'cart' => 'Cart', 'account' => 'Your account',
    'mode_test' => 'Test mode. No real money moves. Verified test payments can change stock and purchased access in this installation.',
    'mode_live' => 'Live mode. This checkout can charge real money.',
    'awaiting_confirmation' => 'Awaiting verified payment confirmation. The store can reconcile this checkout; do not start another payment.',
    'review_help' => 'Review the current prices, tax and delivery before opening Stripe checkout. Changed or expired prices require a fresh order review.',
    'review' => 'Review checkout', 'commitment' => 'The exact amounts below will be fixed for this Stripe Session for about 30 minutes. Stock is not reserved.',
    'continue' => 'Continue to Stripe checkout', 'total' => 'Total', 'order' => 'Back to order', 'orders' => 'Your orders',
    'history' => 'Checkout history', 'history_help' => 'Checkout completion alone does not confirm payment. Purchased access follows the current access check.', 'attempt' => 'Checkout reference', 'state' => 'Status', 'expires' => 'Session expiry (UTC)',
    'creating' => 'Starting checkout', 'open' => 'Checkout is open', 'uncertain' => 'Checkout outcome needs checking',
    'expired' => 'Checkout expired', 'rejected' => 'Checkout was not created', 'completed' => 'Checkout completed',
    'resume' => 'Check and resume checkout', 'expire' => 'Expire checkout',
    'pending_help' => 'Check the existing attempt before trying again. The order cannot be cancelled or erased while this checkout is open or its outcome is unknown.',
    'returned' => 'You returned from Stripe. Check the checkout below to verify its status. Returning here does not confirm payment.',
    'back_from_stripe' => 'You returned from Stripe without cancelling its Session. Check or expire the checkout below.',
    'session_expired' => 'Your session expired. Reload this page and try again.',
    'review_refused' => 'The order could not be reviewed for checkout. Check that its products, prices, delivery and payment credentials are still available.',
    'unavailable' => 'Checkout status is unavailable. Reload this page or ask the store to check the existing attempt. Do not create a replacement while the outcome is unknown.',
    'zero' => 'No-cost checkout is not available yet. This order has not been fulfilled or charged.',
    'live' => 'New live checkouts are not currently admitted. Please contact the store; existing purchases can still be checked.',
    'currency' => 'This currency or amount is not supported by the current Stripe checkout. The reviewed total has not changed.',
    'changed' => 'The order, prices, delivery or payment configuration changed. Review again before starting another checkout. An existing unresolved attempt still needs checking.',
    'review_expired' => 'This checkout review expired. Review the current order again before continuing.',
    'busy' => 'This checkout is already being checked. Wait a moment, then check the same attempt again.',
    'operator' => 'This checkout needs the store’s help to recover its original Session. Do not start a replacement.',
    'setup' => 'Stripe checkout is not configured or its key is unavailable. Please contact the store.',
    'limit' => 'This order has reached its checkout attempt limit. Please contact the store.',
    'pending' => 'Check or expire the existing checkout before cancelling or erasing this order.',
    'operator_help' => 'Reconcile the saved Session with Stripe. If its ID was lost, copy the exact Session ID from this account’s Stripe Dashboard. Current mode, keys and account must still match.',
    'session_id' => 'Session ID (only if missing)', 'expire_after_recovery' => 'Expire the open Session after checking it', 'recover' => 'Check Session',
    'recovered' => 'Checkout status checked',
    'historical_recover' => 'Recover with this checkout’s original Stripe account',
    'historical_help' => 'An owner can check the original Session after a key or mode change. Enter a key for its original Stripe account and mode and your Kitsune password. These entries are used once and are not saved. This does not change the store’s current payment settings.',
    'historical_key' => 'Secret key for the original Stripe account and mode', 'owner_password' => 'Your Kitsune password',
];
