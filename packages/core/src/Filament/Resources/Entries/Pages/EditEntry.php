<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Resources\Entries\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Kitsune\Core\Filament\Concerns\InteractsWithEntryType;
use Kitsune\Core\Filament\Concerns\SyncsFieldRelations;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;

class EditEntry extends EditRecord
{
    use InteractsWithEntryType;

    // Relation fields are rows in entry_relations, not attributes, so they are
    // carried separately and written after the entry exists (ADR-015).
    use SyncsFieldRelations;

    protected static string $resource = EntryResource::class;

    /** @return array<int, mixed> */
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
