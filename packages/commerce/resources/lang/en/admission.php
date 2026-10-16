<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

return [
    'title' => 'Live Stripe checkout',
    'help' => 'This setting applies to every Site in this Org. Live checkout can charge real money. Set the Org to live mode and install its live Stripe API key and webhook signing secret first, then verify the account and admit new checkouts here.',
    'not_admitted' => 'New live checkouts have not been admitted.',
    'admitted' => 'New live checkouts are admitted for the verified Stripe account.',
    'revoked' => 'New live checkouts are blocked. Existing purchases can still receive verified payment, refund and dispute updates.',
    'stale' => 'Admission needs confirmation again. The credentials, mode or commerce version changed.',
    'unavailable' => 'Admission status is unavailable. New live checkouts stay blocked.',
    'account' => 'Verified Stripe account',
    'password' => 'Your Kitsune password',
    'admit' => 'Verify account and admit live checkouts',
    'revoke' => 'Block new live checkouts',
    'secrets' => 'Replacing either Stripe secret requires a fresh admission. This action checks that the account can accept charges, receive payouts and has completed its details. It does not create a charge or validate webhook delivery.',
    'saved' => 'Live checkout admission updated',
    'refused' => 'Admission could not be updated. Check your password, current mode, credentials and account readiness, then reload before trying again.',
];
