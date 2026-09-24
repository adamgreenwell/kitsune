<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament;

use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Media\MediaWithdrawalRefused;
use Throwable;

/**
 * A delete the admin could not make because its file could not leave the web, shown as a notification naming the
 * entry — ADR-042 decision 5, in decision 7's words.
 *
 * @internal
 *
 * ⚠️ NEVER A 500. A refused trash is Kitsune keeping its promise that a trashed file is off the web, and the editor who
 * asked needs to know which entry stayed and why. Filament's `DeleteAction` does not catch at all, and its bulk
 * action catches every exception into a count; both are given the delete through `using()`, so Filament's own
 * authorisation, confirmation and counts are untouched.
 *
 * ⚠️ ESCAPED AS TEXT (decision 7). Filament passes a notification's title and body through `sanitizeHtml()`, which
 * keeps markup: an entry titled with a tag would render it. The title and the refusal are escaped here, before
 * Filament sees them. Only `MediaWithdrawalRefused` is shown as written — it names the entry and the disk and never
 * a path; anything else propagates as Filament would have it.
 */
final class MediaDeletionNotice
{
    /** One record's delete, for `DeleteAction::using()`: false, and a notification, when it is refused. */
    public static function deleteOne(Model $record): bool
    {
        try {
            return (bool) $record->delete();
        } catch (Throwable $refused) {
            // The delete reaches custody through the builder, which a model's signature does not declare.
            if (! $refused instanceof MediaWithdrawalRefused) {
                throw $refused;
            }

            Notification::make()
                ->danger()
                ->title(e(__('kitsune::media.delete.refused', ['title' => self::titleOf($record)])))
                ->body(e($refused->getMessage()))
                ->persistent()
                ->send();

            return false;
        }
    }

    /**
     * Each record's delete on its own, for `DeleteBulkAction::using()` — one refused leaves the rest deleted — and one
     * notification naming every entry that stayed.
     *
     * @param  iterable<Model>  $records
     */
    public static function deleteEach(DeleteBulkAction $action, iterable $records): void
    {
        $refusals = [];
        $reported = false;

        foreach ($records as $record) {
            try {
                $record->delete() || $action->reportBulkProcessingFailure();
            } catch (MediaWithdrawalRefused $refused) {
                $action->reportBulkProcessingFailure();
                $refusals[] = e(__('kitsune::media.delete.refused_line', ['title' => self::titleOf($record), 'reason' => $refused->getMessage()]));
            } catch (Throwable $failure) {
                $action->reportBulkProcessingFailure();

                // As Filament does: the first is reported, and the rest would have been halted by it anyway.
                if (! $reported) {
                    report($failure);
                    $reported = true;
                }
            }
        }

        if ($refusals === []) {
            return;
        }

        Notification::make()
            ->danger()
            ->title(e(trans_choice('kitsune::media.delete.refused_bulk', count($refusals), ['count' => count($refusals)])))
            ->body(implode('<br>', $refusals))
            ->persistent()
            ->send();
    }

    private static function titleOf(Model $record): string
    {
        $title = $record->getAttribute('title');

        return is_string($title) && $title !== '' ? $title : '#'.$record->getKey();
    }
}
