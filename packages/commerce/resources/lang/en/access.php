<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

return [
    'title' => 'Purchased access',
    'pass' => 'Access pass',
    'state_awaiting_payment' => 'Access starts after payment is confirmed.',
    'state_initializing' => 'Purchased access is waiting to be checked.',
    'state_awaiting_claim' => 'Sign in or register, then claim this order to receive its access.',
    'state_pending' => 'Purchased access is waiting to be processed.',
    'state_held' => 'Some purchased access needs review.',
    'state_processed' => 'Purchased access has been checked. Each pass is shown below.',
    'category_financial_hold' => 'The payment needs review before access can be awarded.',
    'category_unknown_terms' => 'An older order has no saved access terms. An operator must review it.',
    'category_unknown_reader' => 'The reader account could not be verified. An operator must review access.',
    'category_reader_guard' => 'Reader accounts need to be configured before access can be awarded.',
    'category_retryable' => 'Access is still waiting. It can be checked again.',
    'category_grant_refused' => 'Access could not be awarded. An operator must review it.',
    'effect_pending' => 'Waiting to be processed',
    'effect_held' => 'On hold for review',
    'effect_granted' => 'Pass awarded',
    'effect_unchanged' => 'Existing pass retained',
    'effect_revoked' => 'Pass was revoked and has not been restored',
    'effect_expired' => 'Pass expired before access was processed',
    'permanent' => 'Permanent access',
    'expires' => 'Pass ends (UTC)',
    'term_help' => 'Fixed-term passes run from payment confirmation, including time before a guest claims the order.',
    'recovery' => 'Owner access recovery',
    'recovery_help' => 'Check the saved payment and access work again. This keeps the original terms and reader, and does not restore a revoked pass. Orders with missing terms stay on hold.',
    'check' => 'Check purchased access',
    'checked' => 'Purchased access checked',
    'unavailable' => 'Purchased access could not be checked. Its saved history has been retained.',
];
