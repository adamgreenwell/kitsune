<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

return [
    'test_mode' => 'Test mode — no payment has been taken.', 'live_mode' => 'Live mode — no payment has been taken.',
    'skip' => 'Skip to order content', 'navigation' => 'Store navigation', 'cart' => 'Cart', 'account' => 'Your account',
    'orders' => 'Your orders', 'empty' => 'No saved orders for this browser or signed-in reader.', 'previous' => 'Previous page', 'next' => 'Next page',
    'throttled' => 'Too many access link requests. Please try again later.',
    'title' => 'Your order', 'checkout' => 'Prepare an order', 'intro' => 'Check your details, then review tax and delivery before preparing an order.',
    'email' => 'Email for order access', 'billing' => 'Billing details', 'delivery' => 'Delivery details', 'different_delivery' => 'Use a different delivery address',
    'delivery_help' => 'Leave this unchecked to use billing details for delivery.', 'name' => 'Name', 'line1' => 'Address line 1', 'line2' => 'Address line 2',
    'city' => 'City', 'region_label' => 'State or region code', 'postal_code' => 'Postal code', 'country' => 'Country code (two uppercase letters)',
    'review' => 'Review tax and delivery', 'review_title' => 'Review your order', 'quote_help' => 'This quote lasts 15 minutes. Preparing an order does not take payment or reserve stock.',
    'net' => 'Items before tax', 'tax' => 'Tax', 'gross' => 'Items including tax', 'shipping' => 'Delivery option', 'shipping_help' => 'Delivery prices below include the configured shipping tax.',
    'digital' => 'No physical delivery', 'prepare' => 'Prepare order', 'prepared' => 'Prepared — awaiting payment', 'cancelled' => 'Cancelled', 'reference' => 'Order reference',
    'state' => 'Status', 'total' => 'Quoted total', 'unpaid' => 'No payment has been taken. This order does not grant access or reserve stock.',
    'cancel' => 'Cancel this order', 'export' => 'Download my order data', 'erase' => 'Erase this unpaid order and its personal data',
    'erase_help' => 'Erasure permanently removes this unpaid order, its details and access links.', 'send_link' => 'Email me an access link',
    'link_help' => 'If your link has expired or already been used, request a fresh one at the order’s saved email address.',
    'access_title' => 'Open your order', 'access_help' => 'Continue to securely open the order linked in your email.', 'access' => 'Continue to my order',
    'back' => 'Back to cart', 'session_expired' => 'Your session expired. Reload the cart and try again.',
    'invalid' => 'Check your email, name and address fields. Country and region fields use codes.',
    'setup' => 'Ordering is not fully configured for these products or this destination. Please contact the store.',
    'mail' => 'Order email is unavailable. Please contact the store or try again later.', 'region' => 'Enter a state or region code, such as CA, rather than its full name.',
    'tax' => 'The store has no tax rate configured for this destination. Please contact the store.',
    'stale' => 'The cart, prices or delivery settings changed. Review a fresh quote before preparing the order.',
    'expired' => 'This quote expired. Return to the cart and review a fresh quote.', 'existing' => 'This cart already has a prepared order. Use its access link, or cancel it before preparing another.',
    'unavailable' => 'The order or delivery quote is unavailable. Please check your cart and try again shortly.',
    'link_invalid' => 'This access link is expired, already used or unavailable. You can request a fresh link below.',
    'link_sent' => 'If the order is available, an access link will be sent to its saved email address.',
    'shippo_label' => 'Shippo API token', 'shippo_help' => 'Paste the Shippo token for this mode. Used only for shipping quotes; never shown again.',
];
