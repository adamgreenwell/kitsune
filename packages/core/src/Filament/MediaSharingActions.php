<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;
use Kitsune\Core\Filament\Resources\Entries\Pages\EditEntry;
use Kitsune\Core\Media\MediaDelivery;
use Kitsune\Core\Media\MediaVisibility;
use Kitsune\Core\Media\MediaVisibilityRefused;
use Kitsune\Core\Models\Entry;
use Throwable;

/** @internal Org-owner actions for changing a stored file's sharing (ADR-042). */
final class MediaSharingActions
{
    /** @return list<Action> */
    public static function all(): array
    {
        return EntryResource::listsMedia() ? [self::make(true), self::make(false)] : [];
    }

    private static function make(bool $share): Action
    {
        $label = __('kitsune::media.sharing.'.($share ? 'share' : 'keep'));

        return Action::make($share ? 'shareFileWithOrg' : 'keepFileToSite')
            ->label($label)
            ->authorize(static function (): bool {
                $user = Permissions::currentUser();

                return $user !== null && Permissions::isOwner($user);
            })
            ->visible(static fn (Entry $record): bool => ! $record->trashed() && ($record->site_id !== null) === $share
                && MediaDelivery::fileFor($record) !== null)
            ->disabled(static fn (Entry $record): bool => MediaReaderActions::isOffered($record))
            ->tooltip(static fn (Entry $record): ?string => MediaReaderActions::isOffered($record)
                ? __('kitsune::media.sharing.active_offer') : null)
            ->databaseTransaction(false)
            ->requiresConfirmation()
            ->modalHeading($label)
            ->modalDescription(__('kitsune::media.sharing.'.($share ? 'share_description' : 'keep_description')))
            ->modalSubmitActionLabel($label)
            ->action(static function (Entry $record, EditEntry $livewire, Action $action) use ($share): void {
                try {
                    $result = MediaVisibility::changeSharing($record, $share);
                } catch (MediaVisibilityRefused $failure) {
                    $action->failure();
                    Notification::make()->warning()->title(__('kitsune::media.sharing.refused'))
                        ->body(__('kitsune::media.sharing.'.($failure->reason === MediaVisibilityRefused::ACTIVE_READER_OFFER
                            ? 'active_offer' : 'unavailable')))->send();

                    return;
                } catch (Throwable $failure) {
                    report($failure);
                    $action->failure();
                    Notification::make()->danger()->title(__('kitsune::media.sharing.refused'))->send();

                    return;
                }

                $record->refresh();
                $livewire->refreshFormData(['slug']);
                $action->success();
                Notification::make()->success()
                    ->title(__('kitsune::media.sharing.'.($result === MediaVisibility::UNCHANGED
                        ? 'unchanged' : ($share ? 'shared' : 'kept'))))->send();
            });
    }
}
