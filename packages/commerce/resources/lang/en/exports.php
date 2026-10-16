<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

return [
    'title' => 'Download financial history',
    'help' => 'Download checked order and payment history for this site. Personal billing fields are excluded by default. Delivery contact details are handled separately.',
    'format' => 'File format', 'jsonl' => 'JSON Lines', 'csv' => 'CSV', 'limit' => 'Orders per download',
    'cursor' => 'Continuation cursor',
    'cursor_help' => 'If a download includes a next cursor, paste it here to continue with the same billing choice. Either file format can be used.',
    'billing' => 'Include policy-permitted retained billing fields',
    'billing_help' => 'Only fields allowed by each order’s recorded retention policy can be included. Removed or expired fields remain absent.',
    'download' => 'Download this page',
    'unavailable' => 'The export could not complete. Review its scope, format, cursor and retained order data, then retry.',
];
