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
 * Rows belong to one Org — users, billing, settings, shared media.
 *
 * ⚠️ Filament does not model Org at all, so these get NO framework scope
 * whatsoever. The scope Kitsune applies here is the only thing standing
 * between one customer's data and another's (ADR-021).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class OrgScoped {}
