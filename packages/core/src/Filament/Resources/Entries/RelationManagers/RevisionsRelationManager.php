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
use Livewire\Attributes\On;

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

    /** Dispatched by `EditEntry` once a save has written the entry and reconciled its version. */
    public const ENTRY_SAVED = 'kitsune-entry-saved';

    /**
     * Redraws the table when the form above it saves — see `EditEntry::afterSave()`.
     */
    #[On(self::ENTRY_SAVED)]
    public function refreshAfterSave(): void
    {
        $this->flushCachedTableRecords();
    }

    /**
     * Would restoring this version publish the entry on behalf of somebody who may not publish?
     *
     * Asks the model, so the button and the guard cannot disagree — see
     * `Entry::restoreWouldPublishWithoutPermission()`.
     */
    private function wouldNeedPublishPermission(EntryRevision $revision): bool
    {
        $entry = $this->getOwnerRecord();

        return $entry instanceof Entry && $entry->restoreWouldPublishWithoutPermission($revision);
    }

    /**
     * May the acting user change the entry this history belongs to?
     *
     * ⚠️ A RESTORE IS AN EDIT, AND THIS COMPONENT IS RENDERED WHERE NOBODY ASKED WHETHER THE USER MAY EDIT.
     * `ViewRecord` renders a resource's relation managers, so somebody holding only `entry.{type}.view` opened
     * the History on the view page and restored a version — `Entry::restoreRevision()` saves the way every
     * model write does, without consulting a policy, and a custom `Action` in a relation manager gets no
     * default authorization (Filament infers one only for its own named actions). Review found it.
     *
     * `EntryResource::canEdit()` is the question the edit page asks before it renders at all, so a user who
     * may reach the form may restore and one who may not, may not — one rule, not a second copy of it.
     */
    private function mayEditOwner(): bool
    {
        $entry = $this->getOwnerRecord();

        return $entry instanceof Entry && EntryResource::canEdit($entry);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('created_at')->label('Saved')->dateTime()->sortable(),
                // Same reasoning as the related-records table: a revision list shows
                // titles as they were, and those carry whatever script they were written
                // in. Found by enumerating every `TextColumn::make('title')` rather than
                // by fixing the one that was reported.
                TextColumn::make('title')->extraAttributes(['dir' => 'auto']),
                TextColumn::make('status')->badge(),
                TextColumn::make('note')->label('Note')->placeholder('—'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('restore')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->requiresConfirmation()
                    ->modalDescription('This adds a new version matching the one you picked. Nothing in the history is removed.')
                    /*
                     * ⚠️ HIDDEN, NOT DISABLED, which is the opposite of the choice below and for the opposite
                     * reason: there, one version is restorable and another is not; here, nothing on any row is
                     * the user's to do. And it is ENFORCED, not cosmetic — Filament treats an unauthorized action
                     * as hidden and a hidden one as disabled, and refuses to mount or call a disabled action, so a
                     * hand-built Livewire request reaches the same answer as the missing button.
                     */
                    ->authorize(fn (): bool => $this->mayEditOwner())
                    /*
                     * ⚠️ A MODEL GUARD THE PANEL STILL OFFERS IS A 500, which review said plainly. Restoring a
                     * version that was PUBLISHED publishes the entry, and `Entry::restoreRevision()` refuses
                     * that without `entry.{type}.publish` — so without this the editor confirms a modal and
                     * gets a server error instead of an answer.
                     *
                     * ⚠️ DISABLED RATHER THAN HIDDEN, and the same predicate as the guard. Hiding the row's
                     * only action would leave somebody comparing two versions and finding one of them
                     * inexplicably inert; the tooltip says which permission is missing. The predicate lives on
                     * the model — one copy, asked here and enforced there.
                     */
                    ->disabled(fn (EntryRevision $record): bool => $this->wouldNeedPublishPermission($record))
                    ->tooltip(fn (EntryRevision $record): ?string => $this->wouldNeedPublishPermission($record)
                        ? 'This version was published, so restoring it would publish the entry — which needs '
                          .'the publish permission for this type.'
                        : null)
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
