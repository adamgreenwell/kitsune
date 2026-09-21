<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures\Modules\NoModels;

/** Not a model. A module may legitimately ship none, and then its scoping list is empty. */
class Helper
{
    public function nothing(): void {}
}
