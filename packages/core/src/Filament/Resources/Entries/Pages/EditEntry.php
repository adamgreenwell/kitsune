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
use Kitsune\Core\Filament\Resources\Entries\RelationManagers\RevisionsRelationManager;

class EditEntry extends EditRecord
{
    use InteractsWithEntryType;

    // Relation fields are rows in entry_relations, not attributes, so they are
    // carried separately and written after the entry exists (ADR-015).
    use SyncsFieldRelations {
        afterSave as syncRelationsAfterSave;
    }

    protected static string $resource = EntryResource::class;

    /** @return array<int, mixed> */
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    /**
     * Writes the relations, then tells the History below the form that the entry was saved.
     *
     * ⚠️ THE HISTORY IS ITS OWN LIVEWIRE COMPONENT, SO A SAVE DID NOT REDRAW IT. The save recorded its version and the
     * table under the form went on listing the versions from before it until the page was reloaded, so an editor
     * checking that their save was kept saw no sign of it. Found by the alpha's local smoke test; `revisions.spec.js`
     * reloaded before it counted, which is what hid it.
     *
     * ⚠️ AFTER the relation sync, because that is where a form save's one revision is reconciled (#59).
     */
    protected function afterSave(): void
    {
        $this->syncRelationsAfterSave();

        $this->dispatch(RevisionsRelationManager::ENTRY_SAVED);
    }
}
