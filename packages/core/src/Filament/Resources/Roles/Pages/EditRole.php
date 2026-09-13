<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Resources\Roles\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Kitsune\Core\Filament\Resources\Roles\Concerns\SyncsRolePermissions;
use Kitsune\Core\Filament\Resources\Roles\RoleResource;

class EditRole extends EditRecord
{
    use SyncsRolePermissions;

    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        /*
         * ⚠️ The delete is offered and `Role` decides. Taking away an org's last held owner role is refused
         * by the model rather than by hiding the button (#84) — an org that loses its last owner cannot get
         * one back, and a guard that only exists in a form is bypassed by the API and the console.
         */
        return [DeleteAction::make()];
    }
}
