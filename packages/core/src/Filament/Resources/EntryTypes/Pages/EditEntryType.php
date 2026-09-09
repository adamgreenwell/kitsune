<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Resources\EntryTypes\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Kitsune\Core\Filament\Resources\EntryTypes\EntryTypeResource;
use Kitsune\Core\Models\EntryType;

class EditEntryType extends EditRecord
{
    protected static string $resource = EntryTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // A global system type belongs to every org, so no single org may
            // delete it. Until RBAC lands (Phase 3) the ownership test is the
            // guard.
            //
            // ⚠️ `org_id !== null` was the test here, which is weaker than
            // ownership: it asks whether SOMEBODY owns the row, not whether
            // WE do. The resource now gates the page itself, so reaching this
            // means the record is already owned — the test stays anyway,
            // because a guard that depends on a caller having run another
            // guard is the shape this project keeps finding bypassed.
            DeleteAction::make()->visible(fn (EntryType $record): bool => EntryTypeResource::ownsRecord($record)),
        ];
    }
}
