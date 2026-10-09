<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use App\Models\Reader;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;

/**
 * The skeleton's reader, handing its address back in capitals — as a host's own accessor, or a column it fills from
 * another system, could. Core's links are filed under the normalised address, so core must normalise what it is given.
 */
#[OrgScoped]
class ShoutingReader extends Reader
{
    public function readerEmail(): string
    {
        return strtoupper(parent::readerEmail());
    }
}
