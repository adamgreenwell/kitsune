<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Example\ScopeNotes;

use Kitsune\Core\Modules\ModuleServiceProvider;

final class ScopeNotesServiceProvider extends ModuleServiceProvider
{
    public function migrationPath(): string
    {
        return __DIR__.'/../database/migrations';
    }
}
