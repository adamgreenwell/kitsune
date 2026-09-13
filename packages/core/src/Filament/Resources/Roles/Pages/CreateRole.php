<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Resources\Roles\Pages;

use Filament\Resources\Pages\CreateRecord;
use Kitsune\Core\Filament\Resources\Roles\Concerns\SyncsRolePermissions;
use Kitsune\Core\Filament\Resources\Roles\RoleResource;

class CreateRole extends CreateRecord
{
    use SyncsRolePermissions;

    protected static string $resource = RoleResource::class;
}
