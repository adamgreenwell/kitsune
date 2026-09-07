<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Resources\Entries\Pages;

use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Kitsune\Core\Filament\Concerns\InteractsWithEntryType;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;

/**
 * Spike #10: a ManageRelatedRecords PAGE under the {type} route parameter.
 *
 * The 2026-09-07 relation-manager spike cleared RelationManager COMPONENTS,
 * which register no routes of their own. This is the other construct — a
 * resource page, which does register a route — and was the last untested
 * corner of ADR-012's URL contract.
 */
class ManageEntryRelations extends ManageRelatedRecords
{
    use InteractsWithEntryType;

    protected static string $resource = EntryResource::class;

    protected static string $relationship = 'related';

    protected static ?string $title = 'Related entries';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('type_handle')->badge()->label('Type'),
            ])
            ->headerActions([AttachAction::make()])
            ->recordActions([DetachAction::make()]);
    }
}
