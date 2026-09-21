<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Fixture\Module;

use Filament\Resources\Resource;

/** A module's own Filament resource, added through ADR-038's `@internal` seam. */
final class FixtureThingResource extends Resource
{
    protected static ?string $model = FixtureThing::class;

    /** The module gates its own resource; core's permission vocabulary cannot name its subject. */
    public static function canViewAny(): bool
    {
        return true;
    }
}
