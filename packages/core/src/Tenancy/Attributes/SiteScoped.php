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
 * Rows belong to one Site. Filament's tenancy scopes these automatically;
 * Kitsune adds its own scope so the guarantee does not depend on a Resource
 * existing, or on the request arriving through Filament at all.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class SiteScoped {}
