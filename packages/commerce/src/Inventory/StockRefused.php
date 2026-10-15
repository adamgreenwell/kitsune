<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Inventory;

use RuntimeException;

/** No stored values or chained input/database exceptions leave stock custody. @internal */
final class StockRefused extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The stock change or history is unavailable. Review the current balance and try again.');
    }
}
