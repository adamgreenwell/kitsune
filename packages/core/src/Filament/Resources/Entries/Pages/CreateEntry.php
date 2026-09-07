<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Resources\Entries\Pages;

use Filament\Resources\Pages\CreateRecord;
use Kitsune\Core\Filament\Concerns\InteractsWithEntryType;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;
use Kitsune\Core\Models\EntryType;

class CreateEntry extends CreateRecord
{
    use InteractsWithEntryType;

    protected static string $resource = EntryResource::class;

    /**
     * Stamp the entry type the URL already identified.
     *
     * The form supplies title, slug and status. entry_type_id is NOT NULL and
     * appears in no form field, so without this every create fails on a
     * database constraint — and type_handle, which Entry derives from the
     * type, would never be set either.
     *
     * The value is taken from the container instance bound by
     * IdentifyEntryType, which has already proved the type exists, belongs to
     * this org, and is enabled for this site. Re-reading {type} from the URL
     * here would be trusting user input a second time, after it has already
     * been validated once.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $type = app()->bound(EntryType::class) ? app(EntryType::class) : null;

        if ($type instanceof EntryType) {
            $data['entry_type_id'] = $type->getKey();
            $data['type_handle'] = $type->handle;
        }

        return $data;
    }

    /** @return array<int, mixed> */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
