<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Blueprints;

use Kitsune\Core\Blueprints\Declarations\SeedEntryDeclaration;

/**
 * Optional first-party seed content, separately requested and receipted — ADR-039, #205.
 *
 * Schema apply never calls this capability. Released definitions remain loadable with
 * their original four methods. No third-party or 0.x stability promise is made.
 */
interface DeclaresSeedContent extends BlueprintDefinition
{
    /** @return list<SeedEntryDeclaration> */
    public function seedContent(): array;
}
