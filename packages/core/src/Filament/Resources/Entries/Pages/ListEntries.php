<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Resources\Entries\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Kitsune\Core\Filament\Concerns\InteractsWithEntryType;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;

class ListEntries extends ListRecords
{
    use InteractsWithEntryType;

    protected static string $resource = EntryResource::class;

    /** @return array<int, mixed> */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
