<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Privacy;

use RuntimeException;

/** Never retains an operator's reason, billing data, or a database exception. @internal */
final class RetentionRefused extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The retention policy could not be verified or changed.');
    }
}
