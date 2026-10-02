<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament;

use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Media\MediaWithdrawalRefused;
use Throwable;

/**
 * One entry's delete forever, for the entry lists' row action — ADR-042 decision 31. A selection's restore and delete
 * forever are `MediaBulkRemoval`'s, on every list (decisions 35 and 36).
 *
 * @internal
 *
 * ⚠️ DELETE FOREVER TAKES WHAT IS IN THE TRASH, AND NOTHING ELSE. Filament shows the row's *Delete forever* on a trashed
 * entry alone, and its own would erase whatever it is handed outright, past the trash an editor could still take it back
 * from. So an entry that is not trashed is named and left, as a refusal is; a live entry is moved to the trash first,
 * which is the only way into it.
 *
 * ⚠️ AND A REFUSED ERASURE IS SAID, NEVER A 500 — as a refused trash is (`MediaDeletionNotice`). An erasure withdraws
 * every copy the web serves before it commits, and one that cannot is refused with its rows intact
 * (`MediaWithdrawalRefused`); the editor is told which entry stayed and why. Anything else propagates as Filament would
 * have it. Both are escaped as text before Filament sees them (decision 7).
 *
 * Authorisation is Filament's, through the policy: `restore` and `forceDelete` resolve against `delete`, as
 * `architecture.md` publishes, and their bulk abilities with them.
 */
final class EntryTrash
{
    /** One record's erasure, for `ForceDeleteAction::using()`: false, and a notification, when it is not made. */
    public static function forceDeleteOne(Model $record): bool
    {
        if (! self::isTrashed($record)) {
            self::notify(e(__('kitsune::trash.not_erased', ['title' => self::titleOf($record)])), e(__('kitsune::trash.not_trashed')));

            return false;
        }

        try {
            return (bool) $record->forceDelete();
        } catch (Throwable $refused) {
            // The erasure reaches custody through the builder, which a model's signature does not declare.
            if (! $refused instanceof MediaWithdrawalRefused) {
                throw $refused;
            }

            self::notify(e(__('kitsune::trash.not_erased', ['title' => self::titleOf($record)])), e($refused->getMessage()));

            return false;
        }
    }

    private static function isTrashed(Model $record): bool
    {
        return method_exists($record, 'trashed') && $record->trashed();
    }

    private static function notify(string $title, string $body): void
    {
        Notification::make()->danger()->title($title)->body($body)->persistent()->send();
    }

    private static function titleOf(Model $record): string
    {
        $title = $record->getAttribute('title');

        return is_string($title) && $title !== '' ? $title : '#'.$record->getKey();
    }
}
