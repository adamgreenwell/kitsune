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
    'title' => 'Test checkout', 'skip' => 'Skip to checkout content', 'navigation' => 'Store navigation', 'cart' => 'Cart', 'account' => 'Your account',
    'test_only' => 'Test mode only. No real money moves. A completed test does not fulfil this order, change stock or grant access.',
    'review_help' => 'Review the current prices, tax and delivery before opening Stripe test checkout. Changed or expired prices require a fresh order review.',
    'review' => 'Review test checkout', 'commitment' => 'The exact amounts below will be fixed for this Stripe test Session for about 30 minutes. Stock is not reserved.',
    'continue' => 'Continue to Stripe test checkout', 'total' => 'Test total', 'order' => 'Back to order',
    'history' => 'Test checkout history', 'attempt' => 'Checkout reference', 'state' => 'Status', 'expires' => 'Session expiry (UTC)',
    'creating' => 'Starting test checkout', 'open' => 'Test checkout is open', 'uncertain' => 'Test checkout outcome needs checking',
    'expired' => 'Test checkout expired', 'rejected' => 'Test checkout was not created', 'completed' => 'Test checkout completed — order remains unpaid',
    'resume' => 'Check and resume test checkout', 'expire' => 'Expire test checkout',
    'pending_help' => 'Check the existing attempt before trying again. The order cannot be cancelled or erased while this test checkout is open or its outcome is unknown.',
    'returned' => 'You returned from Stripe. Check the test checkout below to verify its status. Returning here does not confirm payment.',
    'back_from_stripe' => 'You returned from Stripe without cancelling its Session. Check or expire the test checkout below.',
    'session_expired' => 'Your session expired. Reload this page and try again.',
    'review_refused' => 'The order could not be reviewed for test checkout. Check that its products, prices, delivery and test credentials are still available.',
    'unavailable' => 'Test checkout could not be completed. Check the existing attempt or ask the store to recover it. Do not create a replacement while the outcome is unknown.',
    'zero' => 'No-cost checkout is not available yet. This order has not been fulfilled or charged.',
    'live' => 'Live payments are not available yet. This checkout supports test mode only.',
    'currency' => 'This currency or amount is not supported by the current Stripe test checkout. The reviewed total has not changed.',
    'changed' => 'The order, prices, delivery or payment configuration changed. Review again before starting another checkout. An existing unresolved attempt still needs checking.',
    'review_expired' => 'This checkout review expired. Review the current order again before continuing.',
    'busy' => 'This test checkout is already being checked. Wait a moment, then check the same attempt again.',
    'operator' => 'This test checkout needs the store’s help to recover its original Session. Do not start a replacement.',
    'setup' => 'Stripe test checkout is not configured or its key is unavailable. Please contact the store.',
    'limit' => 'This order has reached its checkout attempt limit. Please contact the store.',
    'pending' => 'Check or expire the existing test checkout before cancelling or erasing this order.',
    'operator_help' => 'Reconcile the saved test Session with Stripe. If its ID was lost, copy the exact Session ID from this account’s Stripe Dashboard. Current mode, keys and account must still match.',
    'session_id' => 'Session ID (only if missing)', 'expire_after_recovery' => 'Expire the open test Session after checking it', 'recover' => 'Check test Session',
    'recovered' => 'Test checkout status checked',
    'historical_recover' => 'Recover with this checkout’s original Stripe account',
    'historical_help' => 'An owner can check the original test Session after a key or mode change. Enter a test key for its original Stripe account and your Kitsune password. These entries are used once and are not saved. This does not change the store’s current payment settings.',
    'historical_key' => 'Test secret key for the original Stripe account', 'owner_password' => 'Your Kitsune password',
];
