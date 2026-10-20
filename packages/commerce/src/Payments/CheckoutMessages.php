<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Payments;

/** Only these workflow categories may become buyer/operator copy; exception messages are never rendered. @internal */
final class CheckoutMessages
{
    public static function key(string $reason): string
    {
        return match ($reason) {
            'zero', 'live', 'order_state', 'currency', 'changed', 'busy', 'operator', 'setup', 'limit', 'pending' => $reason,
            'stale' => 'changed', 'expired' => 'review_expired',
            default => 'unavailable',
        };
    }
}
