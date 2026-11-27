<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Resources\Entries\Pages;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Illuminate\Support\Facades\Gate;
use Kitsune\Core\Filament\Concerns\FillsFieldRelations;
use Kitsune\Core\Filament\Concerns\InteractsWithEntryType;
use Kitsune\Core\Filament\MediaReaderActions;
use Kitsune\Core\Filament\MediaVisibilityActions;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Relations\EntryRelationResolver;

class ViewEntry extends ViewRecord
{
    use FillsFieldRelations;
    use InteractsWithEntryType;
    use RestrictsFileUploadsToSchemaComponents;

    protected static string $resource = EntryResource::class;

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $resolver = app(EntryRelationResolver::class);

        return $this->fillFieldRelations($data, static function (Entry $record, array $storages) use ($resolver): array {
            // Edit keeps unavailable links so unrelated saves preserve them. View only describes readable targets.
            $related = $resolver->resolveMany(
                [$record],
                $storages,
                false,
                static fn (Entry $entry): bool => Gate::allows('view', $entry),
            )[$record->id] ?? [];

            $byStorage = [];
            foreach ($related as $storageId => $relations) {
                $byStorage[$storageId] = array_map(
                    static fn (array $relation): string => ($relation['group'] === null ? 'pin-link:' : 'group-link:').$relation['link'],
                    $relations,
                );
            }

            return $byStorage;
        });
    }

    /**
     * A media entry's file is made public or private here, as on Edit (ADR-042 decision 32), and offered to readers
     * (ADR-041 decision 1).
     *
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [...MediaVisibilityActions::all(), ...MediaReaderActions::all(), EditAction::make()];
    }
}
