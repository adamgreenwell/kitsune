<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament;

use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;
use Kitsune\Core\Media\MediaDelivery;
use Kitsune\Core\Media\MediaRefused;
use Kitsune\Core\Media\MediaVisibility;
use Kitsune\Core\Media\MediaVisibilityRefused;
use Kitsune\Core\Media\MediaWithdrawalRefused;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;

/**
 * *Make public* and *Make private* on a media entry's View and Edit pages — Adam, ADR-042 decision 32.
 *
 * @internal
 *
 * ⚠️ FOR WHOEVER MAY PUBLISH THE TYPE, ASKED OF THE POLICY, WHICH READS THE STORED ROW. Hidden from whoever may neither
 * update nor publish the entry; shown disabled, naming the permission, to whoever may only update it, as Upload is. The
 * switch asks again under its lock (`MediaVisibility`), so a page held open across a revoked grant changes nothing.
 *
 * ⚠️ NO TRANSACTION AROUND EITHER. A host panel's `databaseTransactions()` wraps every action in one, and making a file
 * public refuses inside one: its bytes are rewritten before its own commit, and an enclosing rollback would not restore
 * them.
 */
final class MediaVisibilityActions
{
    /**
     * The header actions for a media type's page; none on any other.
     *
     * @return list<Action>
     */
    public static function all(): array
    {
        return EntryResource::listsMedia() ? [self::makePublic(), self::makePrivate()] : [];
    }

    public static function makePublic(): Action
    {
        return Action::make('makePublic')
            ->label(__('kitsune::media.visibility.make_public'))
            ->icon(Heroicon::OutlinedGlobeAlt)
            ->databaseTransaction(false)
            ->visible(static fn (Entry $record): bool => self::visibilityOf($record) === 'private')
            ->authorize(static fn (Entry $record): bool => self::mayAct($record))
            ->disabled(static fn (Entry $record): bool => ! EntryResource::can('publish', $record))
            ->tooltip(static fn (Entry $record): ?string => EntryResource::can('publish', $record) ? null : self::needsPublish())
            ->modalHeading(static fn (Entry $record): string => __('kitsune::media.visibility.make_public_heading', ['title' => self::titleOf($record)]))
            ->modalDescription(static fn (Entry $record): ?string => $record->site_id === null ? __('kitsune::media.visibility.shared_public') : null)
            ->modalSubmitActionLabel(__('kitsune::media.visibility.make_public'))
            /*
             * ⚠️ DECISION 15's ACKNOWLEDGEMENT, AND NO FIELD UNLESS THE USER MAY ACT — as Upload's: Filament caches an
             * action's schema without asking whether it is disabled. Its words are Upload's statement, written once
             * (`lang/en/media.php`), and they claim what `MediaLocation::STRIPPED` strips and no more. Unticked is a
             * validation error, and the handler asks again.
             */
            ->schema(static fn (Entry $record): ?array => EntryResource::can('publish', $record) ? [
                Checkbox::make('public_confirmed')
                    ->label(__('kitsune::media.visibility.public_confirm'))
                    ->helperText(__('kitsune::media.visibility.public_warning'))
                    ->accepted()
                    ->validationMessages(['accepted' => __('kitsune::media.visibility.not_confirmed')]),
            ] : null)
            ->action(static fn (Entry $record, array $data) => self::publicOne($record, $data));
    }

    public static function makePrivate(): Action
    {
        return Action::make('makePrivate')
            ->label(__('kitsune::media.visibility.make_private'))
            ->icon(Heroicon::OutlinedLockClosed)
            ->databaseTransaction(false)
            ->visible(static fn (Entry $record): bool => self::visibilityOf($record) === 'public')
            ->authorize(static fn (Entry $record): bool => self::mayAct($record))
            ->disabled(static fn (Entry $record): bool => ! EntryResource::can('publish', $record))
            ->tooltip(static fn (Entry $record): ?string => EntryResource::can('publish', $record) ? null : self::needsPublish())
            ->requiresConfirmation()
            ->modalHeading(static fn (Entry $record): string => __('kitsune::media.visibility.make_private_heading', ['title' => self::titleOf($record)]))
            ->modalDescription(static fn (Entry $record): string => __('kitsune::media.visibility.private_warning')
                .($record->site_id === null ? ' '.__('kitsune::media.visibility.shared_private') : ''))
            ->modalSubmitActionLabel(__('kitsune::media.visibility.make_private'))
            ->action(static fn (Entry $record) => self::privateOne($record));
    }

    /**
     * The handler of *Make public*, callable from tests: a notification for every outcome but a failure that is not the
     * editor's to read, which Filament shows as its own (decision 7).
     *
     * @param  array<string, mixed>  $data
     */
    public static function publicOne(Entry $record, array $data): void
    {
        $title = self::titleOf($record);

        if (($data['public_confirmed'] ?? false) !== true) {
            Notification::make()->danger()
                ->title(e(__('kitsune::media.visibility.not_made_public', ['title' => $title])))
                ->body(e(__('kitsune::media.visibility.not_confirmed')))
                ->send();

            return;
        }

        try {
            $outcome = MediaVisibility::makePublic($record);
        } catch (MediaRefused|MediaVisibilityRefused $refused) {
            Notification::make()->danger()->persistent()
                ->title(e(__('kitsune::media.visibility.not_made_public', ['title' => $title])))
                ->body(e($refused->getMessage()))
                ->send();

            return;
        }

        if ($outcome === MediaVisibility::UNCHANGED) {
            Notification::make()->info()->title(e(__('kitsune::media.visibility.already_public', ['title' => $title])))->send();

            return;
        }

        $file = MediaDelivery::fileFor($record);

        if ($file !== null && MediaDelivery::servesDirectly($file)) {
            Notification::make()->success()->title(e(__('kitsune::media.visibility.made_public', ['title' => $title])))->send();

            return;
        }

        // Committed, and its publication failed after the commit: logged, and said here rather than claimed done.
        Notification::make()->warning()->persistent()
            ->title(e(__('kitsune::media.visibility.made_public_awaiting', ['title' => $title])))
            ->body(e(__('kitsune::media.visibility.made_public_awaiting_body', ['id' => $record->getKey()])))
            ->send();
    }

    /** The handler of *Make private*, callable from tests. */
    public static function privateOne(Entry $record): void
    {
        $title = self::titleOf($record);

        try {
            $outcome = MediaVisibility::makePrivate($record);
        } catch (MediaWithdrawalRefused|MediaVisibilityRefused $refused) {
            Notification::make()->danger()->persistent()
                ->title(e(__('kitsune::media.visibility.not_made_private', ['title' => $title])))
                ->body(e($refused->getMessage()))
                ->send();

            return;
        }

        if ($outcome === MediaVisibility::UNCHANGED) {
            Notification::make()->info()->title(e(__('kitsune::media.visibility.already_private', ['title' => $title])))->send();

            return;
        }

        Notification::make()->success()
            ->title(e(__('kitsune::media.visibility.made_private', ['title' => $title])))
            ->body(e(__('kitsune::media.visibility.made_private_body')))
            ->send();
    }

    /** Hidden from whoever may neither update nor publish it; Filament refuses to call an unauthorised action. */
    private static function mayAct(Entry $record): bool
    {
        return EntryResource::can('update', $record) || EntryResource::can('publish', $record);
    }

    /**
     * The file's visibility, read fresh on every render so the action shown flips once the switch lands — or null for a
     * trashed entry, whose file is private whatever it is set to, and which shows neither.
     */
    private static function visibilityOf(Entry $record): ?string
    {
        if ($record->trashed()) {
            return null;
        }

        return MediaDelivery::fileFor($record)?->visibility;
    }

    /** The disabled action's reason, naming the permission it lacks. */
    private static function needsPublish(): string
    {
        $type = app(EntryType::class);

        return __('kitsune::media.visibility.needs_publish', [
            'type' => $type->plural_name ?: $type->handle,
            'permission' => Permissions::forEntryType($type->handle, 'publish'),
        ]);
    }

    private static function titleOf(Entry $record): string
    {
        $title = $record->getAttribute('title');

        return is_string($title) && $title !== '' ? $title : '#'.$record->getKey();
    }
}
