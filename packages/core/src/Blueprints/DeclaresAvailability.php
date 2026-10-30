<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Blueprints;

use Kitsune\Core\Blueprints\Declarations\AvailabilityDeclaration;

/**
 * Opt into ADR-039's third key without making frozen older PHP definitions implement another required method.
 *
 * A first-party author symbol, on BlueprintDefinition's pre-v1.2 footing. An older definition has no decisions.
 * Targets already exist in the org being applied into; this capability creates neither sites nor site groups.
 */
interface DeclaresAvailability extends BlueprintDefinition
{
    /** @return list<AvailabilityDeclaration> sparse decisions on types this blueprint creates or previously created */
    public function availability(): array;
}
