<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Orders;

use Illuminate\Database\Connection;
use Kitsune\Commerce\Payments\PaidOrders;

/** Distinct authorities for a completed purchase. A free receipt is never a provider payment. @internal */
final class CompletionFacts
{
    public static function completed(object $order): bool
    {
        return in_array($order->state, ['paid', 'completed_free'], true);
    }

    public static function receipt(Connection $connection, object $order, bool $lock = false): ?object
    {
        return $order->state === 'completed_free'
            ? app(FreeOrders::class)->receipt($connection, $order, $lock)
            : app(PaidOrders::class)->receipt($connection, $order, $lock);
    }

    /** Raw, nonrecursive privacy commitment; its callers check all scope stamps. */
    public static function raw(Connection $connection, object $order, bool $lock = false): ?object
    {
        $table = $order->state === 'completed_free' ? FreeOrders::TABLE : PaidOrders::RECEIPTS;
        $rows = $connection->table($table)->useWritePdo()->where('order_id', $order->id)->limit(2)
            ->when($lock, fn ($q) => $q->lockForUpdate())->get();
        if ($rows->count() > 1) {
            throw new OrderRefused;
        }
        $row = $rows->first();
        if ($row !== null && ((string) $row->org_id !== (string) $order->org_id || (string) $row->site_id !== (string) $order->site_id)) {
            throw new OrderRefused;
        }

        return $row;
    }

    public static function instant(object $order, object $receipt): string
    {
        return $order->state === 'completed_free' ? $receipt->completed_at : $receipt->verified_at;
    }
}
