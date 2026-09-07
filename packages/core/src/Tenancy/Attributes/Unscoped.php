<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tenancy\Attributes;

use Attribute;

/**
 * Genuinely global: modules, system entry types, migrations.
 *
 * Must be applied deliberately. It is the only way to opt out, and it is
 * declared rather than defaulted so that opting out is visible in review.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Unscoped {}
