<?php

declare(strict_types=1);

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

return [
    'title' => 'Checkout settings', 'settings_section' => 'Prices and shipping',
    'settings_help' => 'These choices apply to every site in your organisation. Tax rates are entered and maintained by you.',
    'tax_mode' => 'Product prices', 'shipping_tax_mode' => 'Carrier shipping prices',
    'inclusive' => 'Include tax', 'exclusive' => 'Add tax at checkout', 'choose' => 'Choose an option',
    'shipping_tax_class' => 'Shipping tax class', 'tax_class_help' => 'Use a short code such as standard or shipping. Add a matching tax rule for each destination you sell to.',
    'ship_from_name' => 'Dispatch name', 'origin' => 'Dispatch address',
    'origin_help' => 'Required for physical products. Live Shippo quotes currently support domestic deliveries only; missing rates never become free shipping.',
    'line1' => 'Address line 1', 'line2' => 'Address line 2', 'city' => 'City', 'region' => 'State or region', 'postal_code' => 'Postal code', 'country' => 'Country code',
    'save_settings' => 'Save checkout settings', 'settings_saved' => 'Checkout settings saved',
    'rates_section' => 'Tax rules', 'rates_help' => 'Enter the rates that apply to your business. A region-specific rule takes precedence over the country-wide * rule. Add an explicit 0% rule where appropriate; a missing rule prevents checkout.',
    'country' => 'Country code', 'tax_region' => 'Region code or *', 'tax_class' => 'Tax class', 'percentage' => 'Tax percentage',
    'save_tax' => 'Save tax rule', 'new_tax' => 'New tax rule', 'edit_tax' => 'Edit tax rule', 'remove_tax' => 'Remove tax rule',
    'tax_saved' => 'Tax rule saved', 'tax_removed' => 'Tax rule removed', 'remove_tax_confirm' => 'Remove this tax rule? Destinations without a matching rule cannot check out.',
    'empty_rates' => 'No tax rules yet.', 'products_section' => 'Product tax and delivery',
    'products_help' => 'Configure each shared SKU before it can be ordered. Product descriptions and availability stay with each site.',
    'edit_policy' => 'Configure delivery', 'policy_heading' => 'Delivery for :sku', 'delivery_kind' => 'Delivery',
    'digital' => 'Digital — no parcel', 'physical' => 'Physical — ship a parcel',
    'packing_help' => 'Each SKU uses its own parcels. Enter the outside dimensions of one packed box, its empty packaging weight, the weight of one item and how many items fit. Partial boxes use the same dimensions.',
    'weight_grams' => 'Item weight (g)', 'length_mm' => 'Parcel length (mm)', 'width_mm' => 'Parcel width (mm)', 'height_mm' => 'Parcel height (mm)',
    'tare_grams' => 'Empty packaging weight (g)', 'units_per_parcel' => 'Items per parcel',
    'save_policy' => 'Save product delivery', 'remove_policy' => 'Remove product delivery', 'remove_policy_confirm' => 'Remove this product’s delivery configuration? It cannot be ordered until configured again.',
    'policy_saved' => 'Product delivery saved', 'policy_removed' => 'Product delivery removed', 'empty_products' => 'No shared products yet.',
    'refused' => 'The change could not be saved. Check the values and reload if someone else changed this configuration.',
    'orders_title' => 'Orders', 'orders_help' => 'Orders retain the prices, tax, delivery and product details quoted at the time. Payment, allocation and refund status are shown for each order.',
    'reference' => 'Order reference', 'state' => 'Status', 'total' => 'Quoted total', 'created' => 'Created', 'view_order' => 'View order', 'empty_orders' => 'No orders on this site yet.',
    'prepared' => 'Awaiting payment', 'cancelled' => 'Cancelled', 'mode' => 'Payment mode', 'test' => 'Test mode', 'live' => 'Live mode',
    'paid' => 'Test payment confirmed',
    'back_orders' => 'Back to orders', 'contact_email' => 'Contact email',
    'billing' => 'Billing details', 'delivery_address' => 'Delivery details', 'items' => 'Items', 'sku' => 'SKU', 'product' => 'Product', 'quantity' => 'Quantity',
    'net' => 'Before tax', 'tax' => 'Tax', 'shipping' => 'Shipping before tax', 'payment' => 'Payment', 'not_charged' => 'Not charged',
    'cancel_order' => 'Cancel unpaid order', 'cancel_confirm' => 'Cancel this unpaid order? No payment or stock movement will occur.',
    'erase_order' => 'Erase unpaid order', 'erase_confirm' => 'Permanently erase this unpaid order, its billing and delivery details, and its access links?',
    'order_cancelled' => 'Order cancelled', 'order_erased' => 'Order erased', 'order_refused' => 'The order is unavailable or could not be changed. Reload and try again.',
];
