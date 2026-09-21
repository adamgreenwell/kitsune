<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures\Modules\Smuggler\src;

use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Tenancy\Attributes\Unscoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;

require_once __DIR__.'/../stubs/smuggled.php';

/**
 * Declares exactly the class PSR-4 names and is honest about itself.
 *
 * The `require_once` defines another model from a file INSIDE the package but under no PSR-4 root, so the
 * sweep never walks it and the token scan never sees it — the scan reads declarations rather than following
 * includes. The verifier itself is what executes this file, when it calls `class_exists()` on the name the
 * path promised.
 *
 * ⚠️ The namespace mirrors the `src` directory on purpose: this fixture needs a PSR-4 root that is a
 * SUBDIRECTORY, so that `stubs/` is inside the package and outside the sweep. With the root at the package
 * top the stub would be walked and refused for declaring a class its path does not name — which is a
 * different rule, and the test would then pass for the wrong reason.
 */
#[Unscoped]
class Carrier extends Model
{
    use EnforcesScope;

    protected $table = 'carriers';
}
