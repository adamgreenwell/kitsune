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
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Kitsune\Core\Filament\Concerns\InteractsWithEntryType;
use Kitsune\Core\Filament\MediaUpload;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;

class ListEntries extends ListRecords
{
    use InteractsWithEntryType;
    use RestrictsFileUploadsToSchemaComponents;

    protected static string $resource = EntryResource::class;

    /**
     * A media type's files arrive through Upload, and nothing else: its create page answers 404, because a media entry
     * without bytes is the broken state ADR-042 exists to avoid (decision 3).
     *
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [EntryResource::listsMedia() ? MediaUpload::action() : CreateAction::make()];
    }
}
