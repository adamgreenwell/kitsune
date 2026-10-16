<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Orders;

use Closure;
use Illuminate\Database\Connection;
use Kitsune\Commerce\Carts\CartReaderData;
use Kitsune\Core\Readers\Contracts\ModuleReaderData;
use Kitsune\Core\Readers\Contracts\ModuleReaderErasureVerifier;
use RuntimeException;

/** All installed commerce reader data, independently of runtime enablement. @internal */
final class CommerceReaderData implements ModuleReaderData, ModuleReaderErasureVerifier
{
    private readonly OrderReaderData $orders;

    public function __construct(?OrderReaderData $orders = null)
    {
        $this->orders = $orders ?? new OrderReaderData;
    }

    public function export(Connection $connection, int $orgId, #[\SensitiveParameter] string $reader): array
    {
        return (new CartReaderData)->export($connection, $orgId, $reader)
            + $this->orders->export($connection, $orgId, $reader);
    }

    public function erase(Connection $connection, int $orgId, #[\SensitiveParameter] string $reader): int
    {
        // Capture and minimize order custody before deleting carts: their FK may otherwise null a purchase
        // reference before its checked financial/privacy commitment can be frozen. Retained parents count zero.
        return $this->orders->erase($connection, $orgId, $reader)
            + (new CartReaderData)->erase($connection, $orgId, $reader);
    }

    public function erasureVerification(Connection $connection, int $orgId, #[\SensitiveParameter] string $reader): Closure
    {
        $orders = $this->orders->erasureVerification($connection, $orgId, $reader);

        return static function () use ($connection, $orgId, $reader, $orders): void {
            $orders();
            if ((new CartReaderData)->export($connection, $orgId, $reader) !== ['carts' => []]) {
                throw new RuntimeException('Erased cart data reappeared.');
            }
        };
    }
}
