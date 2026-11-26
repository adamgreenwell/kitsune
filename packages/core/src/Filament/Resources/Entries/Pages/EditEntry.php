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
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Filament\Concerns\InteractsWithEntryType;
use Kitsune\Core\Filament\Concerns\SyncsFieldRelations;
use Kitsune\Core\Filament\MediaDeletionNotice;
use Kitsune\Core\Filament\MediaReaderActions;
use Kitsune\Core\Filament\MediaSharingActions;
use Kitsune\Core\Filament\MediaVisibilityActions;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Schema\RecordedRevisions;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Themes\PublicEntryRedirects;

class EditEntry extends EditRecord
{
    use InteractsWithEntryType;
    use RestrictsFileUploadsToSchemaComponents;

    // Relation fields are rows in entry_relations, not attributes, so they are
    // carried separately and written after the entry exists (ADR-015).
    use SyncsFieldRelations;

    protected static string $resource = EntryResource::class;

    public const REMEMBER_ADDRESS = 'remember_former_address';

    protected function afterFill(): void
    {
        $record = $this->getRecord();
        $this->data[self::REMEMBER_ADDRESS] = $record instanceof Entry && self::canRememberAddress($record);
    }

    public static function canRememberAddress(?Entry $entry): bool
    {
        $site = app(Context::class)->site();
        // Filament can render this schema with an unsaved record supplied by another entry page.
        $handle = $entry?->getAttribute('type_handle');

        return $entry !== null && $site !== null && is_string($handle) && $handle !== ''
            && Permissions::allows(Permissions::currentUser(), Permissions::forEntryType($handle, 'publish'))
            && app(PublicEntryRedirects::class)->supports($entry, $site);
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $remember = ($data[self::REMEMBER_ADDRESS] ?? false) === true;
        unset($data[self::REMEMBER_ADDRESS]);
        $site = app(Context::class)->site();
        if (! $remember || $site === null || ! $record instanceof Entry || ! self::canRememberAddress($record)) {
            return parent::handleRecordUpdate($record, $data);
        }

        try {
            return app(PublicEntryRedirects::class)->preserveOnSave(request(), $site, $record, function (Entry $entry) use ($data): Entry {
                parent::handleRecordUpdate($entry, $data);

                return $entry;
            }, 'site.home');
        } catch (ValidationException $exception) {
            $messages = $exception->errors()['former_address'] ?? null;
            if ($messages === null) {
                throw $exception;
            }

            app(RecordedRevisions::class)->take($record->id);
            $record->refresh();

            throw ValidationException::withMessages(['data.'.self::REMEMBER_ADDRESS => $messages]);
        }
    }

    /**
     * A refused delete — its file could not leave the web — is a notification naming the entry, not a 500
     * (`MediaDeletionNotice`, ADR-042 decision 5). A media entry's file is made public or private here, as on View
     * (decision 32), and offered to readers (ADR-041 decision 1).
     *
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [...MediaVisibilityActions::all(), ...MediaReaderActions::all(), ...MediaSharingActions::all(), DeleteAction::make()->using(MediaDeletionNotice::deleteOne(...))];
    }
}
