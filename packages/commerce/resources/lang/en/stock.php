<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

return [
    'retained_title' => 'Retained stock history',
    'retained_help' => 'Stock movements across this org’s sites, including removed products. Historical references do not restore products or show a current balance. Reasons and staff IDs remain as recorded; buyer details are not included.',
    'filter_sku' => 'Exact historical SKU',
    'filter_product' => 'Historical product reference',
    'filter_from' => 'From date (UTC)',
    'filter_to' => 'Through date (UTC)',
    'filter_help' => 'SKU and product references match exactly. Dates include the whole UTC day. Results show 25 movements per page, newest first.',
    'apply_filters' => 'Apply filters',
    'clear_filters' => 'Clear filters',
    'invalid_filters' => 'Enter a valid SKU, product reference and UTC date range. The previous filters are still applied.',
    'retained_empty' => 'No retained stock movements match the applied filters.',
    'product_reference' => 'Product reference',
    'movement_reference' => 'Movement reference',
    'allocated_quantity' => 'Allocated quantity',
    'source' => 'Source',
    'order_reference' => 'Order reference',
    'line_reference' => 'Line reference',
    'not_applicable' => 'Not applicable',
    'title' => 'Stock and history',
    'adjust_and_history' => 'Stock and history',
    'shared_help' => 'Stock is shared by every site selling this product. An adjustment changes their balance too.',
    'empty' => 'No products are available for stock adjustments on this site.',
    'open' => 'Adjust stock',
    'back' => 'All stock',
    'balance' => 'Current shared balance',
    'tracking' => 'Stock tracking',
    'quantity' => 'New stock quantity',
    'reason' => 'Reason for adjustment',
    'reason_help' => 'Explain the stock change, without customer or other personal information. This reason is kept in stock history.',
    'save' => 'Save adjustment',
    'reload' => 'Reload current balance',
    'saved' => 'Stock adjusted',
    'refused' => 'Stock could not be adjusted. Enter a different valid balance and a reason. If stock or your access changed, reload the current balance before trying again.',
    'unavailable' => 'Stock data is unavailable. Reload and try again.',
    'history' => 'Recent stock history',
    'history_help' => 'The most recent 50 changes are shown. Stock history is shared across this org’s sites.',
    'no_history' => 'No stock changes have been recorded yet.',
    'time' => 'Time (UTC)',
    'kind' => 'Change',
    'kind_manual' => 'Manual adjustment',
    'kind_sale' => 'Paid order',
    'kind_free_order' => 'Free order',
    'kind_initial' => 'Opening balance',
    'before' => 'Before',
    'after' => 'After',
    'actor' => 'Staff ID',
    'system' => 'System',
];
