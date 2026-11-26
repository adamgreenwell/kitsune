<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Resources\Entries\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\Response;
use Kitsune\Core\Auth\EntryListAuthorization;
use Kitsune\Core\Filament\Concerns\InteractsWithEntryType;
use Kitsune\Core\Filament\MediaUpload;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;
use Kitsune\Core\Models\Entry;

class ListEntries extends ListRecords
{
    use InteractsWithEntryType;
    use RestrictsFileUploadsToSchemaComponents;

    protected static string $resource = EntryResource::class;

    public function content(Schema $schema): Schema
    {
        $schema = parent::content($schema);
        if (! EntryResource::listsMedia()) {
            return $schema;
        }

        return $schema->components(array_map(
            static fn ($component) => $component::class === EmbeddedTable::class ? MediaEntriesTable::make() : $component,
            $schema->getComponents(withHidden: true, withOriginalKeys: true),
        ));
    }

    public function getDefaultActionAuthorizationResponse(Action $action): ?Response
    {
        // The media list trashes through a bulk action. These four default record controls use the displayed rows.
        $ability = match ($action::class) {
            ViewAction::class => 'view',
            EditAction::class => 'update',
            RestoreAction::class => 'restore',
            ForceDeleteAction::class => 'forceDelete',
            default => null,
        };

        if ($ability === null || ! ($record = $action->getRecord()) instanceof Entry) {
            return parent::getDefaultActionAuthorizationResponse($action);
        }

        return app(EntryListAuthorization::class)->forControl(
            $record, $ability, fn (): ?Response => parent::getDefaultActionAuthorizationResponse($action),
        );
    }

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
