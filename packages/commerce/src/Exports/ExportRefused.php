<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Exports;

use RuntimeException;

/** Private order, SQL and caller input never enter a failed export's diagnostics. @internal */
final class ExportRefused extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Commerce history could not be exported. Check its scope, options and retained data before retrying.');
    }
}
