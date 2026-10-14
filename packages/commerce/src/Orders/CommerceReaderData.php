<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Orders;

use Illuminate\Database\Connection;
use Kitsune\Commerce\Carts\CartReaderData;
use Kitsune\Core\Readers\Contracts\ModuleReaderData;

/** All installed commerce reader data, independently of runtime enablement. @internal */
final class CommerceReaderData implements ModuleReaderData
{
    public function export(Connection $connection, int $orgId, #[\SensitiveParameter] string $reader): array
    {
        return (new CartReaderData)->export($connection, $orgId, $reader)
            + (new OrderReaderData)->export($connection, $orgId, $reader);
    }

    public function erase(Connection $connection, int $orgId, #[\SensitiveParameter] string $reader): int
    {
        return (new OrderReaderData)->erase($connection, $orgId, $reader)
            + (new CartReaderData)->erase($connection, $orgId, $reader);
    }
}
