<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Shipping;

use RuntimeException;

/** No provider body, address, credential or chained transport exception may leave the rate client. @internal */
final class ShippingUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Shipping rates are unavailable. Please try again.');
    }
}
