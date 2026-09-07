<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Resources\Entries\RelationManagers;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRevision;

/**
 * Version history, with a restore.
 *
 * ⚠️ A RelationManager COMPONENT, not a page. A page registers its own route
 * under `/c/{type}/{record}/…`, which means another reserved type handle and
 * another URL that has to carry `{type}` (ADR-012). Spike #10 cleared both
 * shapes; this one needs neither, so it uses the shape that adds no routes.
 */
class RevisionsRelationManager extends RelationManager
{
    protected static string $relationship = 'revisionHistory';

    protected static ?string $title = 'History';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('created_at')->label('Saved')->dateTime()->sortable(),
                TextColumn::make('title'),
                TextColumn::make('status')->badge(),
                TextColumn::make('note')->label('Note')->placeholder('—'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('restore')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->requiresConfirmation()
                    ->modalDescription('This adds a new version matching the one you picked. Nothing in the history is removed.')
                    ->action(function (EntryRevision $record) {
                        /** @var Entry $entry */
                        $entry = $this->getOwnerRecord();

                        $entry->restoreRevision($record);

                        Notification::make()
                            ->title('Restored')
                            ->body('The entry now matches that version, and the versions after it are still here.')
                            ->success()
                            ->send();

                        // ⚠️ Redirect, so the form reloads from the database.
                        //
                        // Without it the restore lands in the database while
                        // the form above still holds the PRE-restore values —
                        // and the next "Save changes" writes them back,
                        // silently undoing the restore the user just watched
                        // succeed. Found by clicking it.
                        return redirect(EntryResource::getUrl('edit', [
                            'type' => $entry->type_handle,
                            'record' => $entry,
                        ]));
                    }),
            ])
            // Deliberately no delete: erasure goes through redactField(), which
            // replaces a value in place so the history of WHAT CHANGED WHEN
            // survives an erasure of WHAT IT SAID (ADR-020). Deleting a
            // revision destroys both.
            ->toolbarActions([]);
    }
}
