<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Carts;

use RuntimeException;

/** Maintenance may have committed earlier batches; never echo row payloads or claim that nothing changed. @internal */
final class CartPruneUnavailable extends RuntimeException
{
    public function __construct(public readonly ?string $state = null)
    {
        parent::__construct('Cart cleanup could not finish. Enable the current commerce module and review its retained '
            .'cart custody before retrying. Earlier cart removals may already have committed.'
            .($state !== null ? ' The database refused (SQLSTATE '.$state.'); its message is not repeated.' : ''));
    }
}
