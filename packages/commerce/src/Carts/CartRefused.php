<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Carts;

use RuntimeException;

/** A safe refusal carries no submitted identifier, quantity, session secret or reader. @internal */
final class CartRefused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('The cart change could not be accepted.');
    }
}
