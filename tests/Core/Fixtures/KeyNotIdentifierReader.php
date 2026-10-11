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
 * The skeleton's reader on a host whose guard identifies readers by another column than the key — here the address — so
 * a row found by its key is identified by something else. Laravel allows it; a page that looked a reader up by one and
 * named them by the other would name a row it never asked for.
 */
#[OrgScoped]
class KeyNotIdentifierReader extends Reader
{
    public function getAuthIdentifierName(): string
    {
        return 'email';
    }
}
