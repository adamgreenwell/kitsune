<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament;

use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Media\MediaWithdrawalRefused;
use Throwable;

/**
 * The trash's restore and delete forever, for the entry lists — ADR-042 decision 31.
 *
 * @internal
 *
 * ⚠️ DELETE FOREVER TAKES WHAT IS IN THE TRASH, AND NOTHING ELSE. Filament's filter can list live and trashed entries
 * together, and its bulk action would erase a live one outright, past the trash an editor could still take it back from.
 * So an entry that is not trashed is named and left, as a refusal is; a live entry is moved to the trash first, which is
 * the only way into it.
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

    /**
     * Each trashed record erased on its own, for `ForceDeleteBulkAction::using()` — one refused leaves the rest erased —
     * and one notification naming every entry that stayed.
     *
     * @param  iterable<Model>  $records
     */
    public static function forceDeleteEach(BulkAction $action, iterable $records): void
    {
        $kept = [];
        $erased = 0;
        $other = 0;
        $reported = false;

        foreach ($records as $record) {
            if (! self::isTrashed($record)) {
                $action->reportBulkProcessingFailure();
                $kept[] = e(__('kitsune::trash.not_erased_line', ['title' => self::titleOf($record), 'reason' => __('kitsune::trash.not_trashed')]));

                continue;
            }

            try {
                if ($record->forceDelete()) {
                    $erased++;
                } else {
                    $other++;
                    $action->reportBulkProcessingFailure();
                }
            } catch (MediaWithdrawalRefused $refused) {
                $action->reportBulkProcessingFailure();
                $kept[] = e(__('kitsune::trash.not_erased_line', ['title' => self::titleOf($record), 'reason' => $refused->getMessage()]));
            } catch (Throwable $failure) {
                $other++;
                $action->reportBulkProcessingFailure();

                // As Filament does: the first is reported, and the rest would have been halted by it anyway.
                if (! $reported) {
                    report($failure);
                    $reported = true;
                }
            }
        }

        if ($kept === []) {
            return;
        }

        $count = count($kept);

        if ($erased > 0) {
            $kept[] = e(trans_choice('kitsune::trash.erased_bulk', $erased, ['count' => $erased]));
        }

        // This notice instead of Filament's when every failure is named here; any other keeps Filament's count.
        if ($other === 0) {
            $action->failureNotification(null);
        }

        self::notify(e(trans_choice('kitsune::trash.not_erased_bulk', $count, ['count' => $count])), implode('<br>', $kept));
    }

    /**
     * Each trashed record restored on its own, for `RestoreBulkAction::using()`. A live one in the selection — the filter
     * lists both together — is where a restore would leave it, and is left: restoring it would save it again for
     * nothing, an audit row and a revision's worth of nothing.
     *
     * @param  iterable<Model>  $records
     */
    public static function restoreEach(BulkAction $action, iterable $records): void
    {
        $reported = false;

        foreach ($records as $record) {
            if (! self::isTrashed($record) || ! method_exists($record, 'restore')) {
                continue;
            }

            try {
                $record->restore() || $action->reportBulkProcessingFailure();
            } catch (Throwable $failure) {
                $action->reportBulkProcessingFailure();

                if (! $reported) {
                    report($failure);
                    $reported = true;
                }
            }
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
