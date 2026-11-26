<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Relations;

use Kitsune\Core\Models\EntryTranslationGroup;
use Kitsune\Core\Tenancy\ScopedBuilder;
use RuntimeException;

/** @extends ScopedBuilder<EntryTranslationGroup> */
final class EntryTranslationGroupBuilder extends ScopedBuilder
{
    public function delete(): never
    {
        throw new RuntimeException('A translation group can only be removed with its Org.');
    }

    public function forceDelete(): never
    {
        $this->delete();
    }
}
