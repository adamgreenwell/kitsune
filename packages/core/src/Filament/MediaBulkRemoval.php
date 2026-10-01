<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament;

use Carbon\CarbonInterface;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Media\MediaDelivery;
use Kitsune\Core\Media\MediaWithdrawalRefused;
use Kitsune\Core\Models\Entry;
use Throwable;

/**
 * A media list's *Delete selected*, *Restore selected* and *Delete selected forever*, at most fifty at a time within
 * the request's budget — Adam, ADR-042 decision 35.
 *
 * @internal
 *
 * ⚠️ FILAMENT'S OWN CLASSES, NEVER RE-AUTHORISED OR RE-HIDDEN. Each stays Filament's `DeleteBulkAction`,
 * `RestoreBulkAction` or `ForceDeleteBulkAction`, so who may is still the policy's `deleteAny`, `restoreAny` and
 * `forceDeleteAny`, and each is hidden by the trash filter as Filament hides it: never `authorize()`, `hidden()` or
 * `action()` here, which would replace them. `using()` replaces only the body that loads the whole selection.
 *
 * ⚠️ HANDED A QUERY, NEVER RECORDS, BOUNDED AND BUDGETED, as every selection on a media list is (`BulkSelection`): at
 * most fifty, fetched in one query of at most fifty-one, or none; none started once the budget has passed, the clock
 * taken before the fetch and the first that needs work always started.
 *
 * ⚠️ EACH ENTRY ON ITS OWN, outside any transaction, a host's included: each commits — and, restored, publishes; erased,
 * disposes — before the next begins. One around the loop would hold every trash's lock, on SQLite the database's write
 * lock, to its end, and put every publication and disposal after it, past the budget.
 *
 * ⚠️ ONE NOTIFICATION, KITSUNE'S ALONE, ESCAPED (decision 7), saying what became of every entry.
 */
final class MediaBulkRemoval
{
    public const DELETE = 'delete';

    public const RESTORE = 'restore';

    public const ERASE = 'erase';

    /**
     * A media list's three, bounded and budgeted (decision 35).
     *
     * @return list<BulkAction>
     */
    public static function bound(DeleteBulkAction $delete, RestoreBulkAction $restore, ForceDeleteBulkAction $erase): array
    {
        return [self::configure($delete, self::DELETE), self::configure($restore, self::RESTORE), self::configure($erase, self::ERASE)];
    }

    /**
     * The modal's words: what deleting forever takes with it, and the bound, before it is submitted — or null, for
     * Filament's own confirmation.
     *
     * @internal
     */
    public static function note(string $verb, int $selected): ?string
    {
        $sentences = $verb === self::ERASE ? [__('kitsune::trash.erase_warning_media')] : [];

        if ($selected > BulkSelection::MOST_AT_ONCE) {
            $sentences[] = __("kitsune::media.removal.{$verb}.too_many_note", ['max' => BulkSelection::MOST_AT_ONCE, 'count' => $selected]);
        }

        return $sentences === [] ? null : implode(' ', $sentences);
    }

    /**
     * The handler: nothing changed when more than `BulkSelection::MOST_AT_ONCE` are selected.
     *
     * @internal
     *
     * @param  Builder<Entry>  $selected
     * @param  ?list<string>  $keys  the keys the editor selected, where the selection is a list of keys
     * @param  ?Builder<Entry>  $list  the list's own query — its type, site and organisation — below its filters
     */
    public static function selected(BulkAction $action, Builder $selected, string $verb, ?array $keys = null, ?Builder $list = null): void
    {
        // ⚠️ THE CLOCK FIRST, so a slow fetch spends the budget rather than extending it (Codex, #166).
        $until = BulkSelection::deadline();
        $records = BulkSelection::upTo($selected);

        if ($records === null) {
            BulkSelection::refuse($action, __('kitsune::media.removal.too_many_title'), __("kitsune::media.removal.{$verb}.too_many", ['max' => BulkSelection::MOST_AT_ONCE]));

            return;
        }

        self::each($action, $records, $verb, $until, $keys, $list);
    }

    /**
     * Each entry removed on its own, and one notification saying what became of every one — callable from tests.
     *
     * @internal
     *
     * @param  iterable<Model>  $records
     * @param  ?CarbonInterface  $until  none started after it; null for no budget
     * @param  ?list<string>  $keys  the keys the editor selected; those not in `$records` have left the list since
     * @param  ?Builder<Entry>  $list  the list's own query, where those that left are read
     */
    public static function each(BulkAction $action, iterable $records, string $verb, ?CarbonInterface $until = null, ?array $keys = null, ?Builder $list = null): void
    {
        $budget = BulkSelection::within($until);
        $refused = $failed = $awaiting = $seen = [];
        $done = $already = $vanished = 0;
        $reported = false;

        foreach ($records as $record) {
            /** @var Entry $record */
            $seen[] = (string) $record->getKey();
            $title = self::titleOf($record);
            /*
             * ⚠️ WHERE IT IS NOW, read below the model, not where the list loaded it: another editor may have trashed,
             * restored or erased it since (review of decision 35; Codex, #167). Where that cannot be read, as loaded —
             * and the write's own guard asks again under its lock (`Entry::refuseIfTheRowMovedUnderneath()`).
             */
            $now = self::whereIs($record) ?? ($record->trashed() ? 'trashed' : 'live');

            // Erased since, by someone else: no longer on the list, and left as it is.
            if ($now === 'gone' && $verb !== self::ERASE) {
                $vanished++;

                continue;
            }

            // ⚠️ NO WRITE NEEDED — decision 31's mixed selection: counted, never held back by the budget, never the first.
            if (($verb === self::DELETE && $now === 'trashed') || ($verb === self::RESTORE && $now === 'live') || ($verb === self::ERASE && $now === 'gone')) {
                $already++;

                continue;
            }

            // ⚠️ DELETE FOREVER TAKES WHAT IS IN THE TRASH (decision 31): named, whatever the budget — naming writes nothing.
            if ($verb === self::ERASE && $now === 'live') {
                $refused[] = self::notTrashed($title);

                continue;
            }

            /*
             * ⚠️ AND A HOST'S `authorizeIndividualRecords()` ASKED, as Filament asks it of the records it loads — handed
             * a query, Filament loads none, so it asks nothing (review of decision 35). Named, whatever the budget.
             */
            if ($action->shouldAuthorizeIndividualRecords() && ($answer = $action->getIndividualRecordAuthorizationResponse($record))->denied()) {
                $refused[] = e(__("kitsune::media.removal.{$verb}.refused_line", [
                    'title' => $title,
                    'reason' => filled($answer->message()) ? $answer->message() : __("kitsune::media.removal.{$verb}.not_permitted"),
                ]));

                continue;
            }

            if (! $budget->starts()) {
                continue;
            }

            try {
                // Changed since the list loaded it: reloaded, so its own write starts from where it is.
                if ($now !== ($record->trashed() ? 'trashed' : 'live')) {
                    $record->refresh();
                }

                $ok = (bool) match ($verb) {
                    self::DELETE => $record->delete(),
                    self::RESTORE => $record->restore(),
                    default => $record->forceDelete(),
                };
            } catch (Throwable $failure) {
                // A restore withdraws nothing, so it has no refusal of its own: anything it throws is a failure.
                if ($verb !== self::RESTORE && $failure instanceof MediaWithdrawalRefused) {
                    $refused[] = e(__($verb === self::DELETE ? 'kitsune::media.delete.refused_line' : 'kitsune::trash.not_erased_line', [
                        'title' => $title,
                        'reason' => $failure->getMessage(),
                    ]));

                    continue;
                }

                /*
                 * ⚠️ RESTORED SINCE THE LIST LOADED IT: the erasure refused it under its own lock, as it refuses to erase
                 * any entry loaded from the trash that is no longer there (`Entry::refuseIfTheRowMovedUnderneath()`). It
                 * is live, and said so in decision 31's words; nothing went wrong, so nothing is reported.
                 */
                if ($verb === self::ERASE && self::isLive($record)) {
                    $refused[] = self::notTrashed($title);

                    continue;
                }

                // As Filament does: the first is reported, and every other is likely the same.
                if (! $reported) {
                    report($failure);
                    $reported = true;
                }

                // ⚠️ READ AGAIN, below the model: a COMMIT that reported failure may have landed.
                $ok = self::isNow($record, $verb);
            }

            // A model event that said no, or a failure that did not land: named, never claimed.
            if (! $ok) {
                $failed[] = $title;

                continue;
            }

            // ⚠️ RESTORED, AND NOT YET ON THE WEB: its publication runs after the commit and logs its failure (decision 5).
            if ($verb === self::RESTORE && self::awaitsPublication($record)) {
                $awaiting[(int) $record->getKey()] = $title;

                continue;
            }

            $done++;
        }

        /*
         * ⚠️ AND THE SELECTED KEYS THE LIST NO LONGER HOLDS, read where they are now, so a second run's view of the first
         * run's work is "already so", never "left as it was" (decision 34's review, its gone count).
         */
        $gone = $vanished;
        $left = $keys === null ? [] : array_values(array_diff($keys, $seen));

        if ($left !== []) {
            $now = $list === null ? null : self::whereNow($list, $left, $reported);
            $asked = $now === null ? 0 : match ($verb) {
                self::DELETE => $now['trashed'],
                self::RESTORE => $now['present'] - $now['trashed'],
                default => count($left) - $now['present'],
            };
            $already += $asked;
            $gone += count($left) - $asked;
        }

        $notTried = $budget->notTried();
        $notDone = count($refused) + count($failed) + $notTried;
        $notDone === 0 ? $action->success() : $action->failure();

        // Those erased since by someone else are not counted as on the list.
        self::summary($verb, count($seen) - $vanished, $notDone, $refused, $failed, $notTried, $awaiting, $done, $already, $gone)->send();
    }

    private static function configure(DeleteBulkAction|RestoreBulkAction|ForceDeleteBulkAction $action, string $verb): BulkAction
    {
        return BulkSelection::oneByOne($action)
            // Counted, never fetched; null falls back to Filament's own confirmation.
            ->modalDescription(static fn (Builder $selectedRecordsQuery): ?string => self::note($verb, BulkSelection::countOf($selectedRecordsQuery)))
            ->using(static fn (BulkAction $action, Builder $selectedRecordsQuery, HasTable $livewire) => self::selected(
                $action,
                $selectedRecordsQuery,
                $verb,
                BulkSelection::keysSelected($livewire),
                $livewire->getTable()->getQuery(),
            ));
    }

    /** A live entry in *Delete selected forever*, named as decision 31 names it, escaped. */
    private static function notTrashed(string $title): string
    {
        return e(__('kitsune::trash.not_erased_line', ['title' => $title, 'reason' => __('kitsune::trash.not_trashed')]));
    }

    /** Where the entry is now — live, trashed or gone — read below the model by the id the list loaded; null, where unread. */
    private static function whereIs(Entry $record): ?string
    {
        try {
            $row = $record->getConnection()->table($record->getTable())->where($record->getKeyName(), $record->getKey())->first(['deleted_at']);
        } catch (Throwable) {
            return null;
        }

        return $row === null ? 'gone' : ($row->deleted_at === null ? 'live' : 'trashed');
    }

    /** Whether the entry's row is there and out of the trash; not, where unread. */
    private static function isLive(Entry $record): bool
    {
        return self::whereIs($record) === 'live';
    }

    /** Whether the entry is now as the action asked; not, where unread. */
    private static function isNow(Entry $record, string $verb): bool
    {
        return self::whereIs($record) === match ($verb) {
            self::DELETE => 'trashed',
            self::RESTORE => 'live',
            default => 'gone',
        };
    }

    /** A restored public file its disk does not serve: its publication failed after the commit — or cannot be read. */
    private static function awaitsPublication(Entry $record): bool
    {
        try {
            $file = MediaDelivery::fileFor($record);

            return $file !== null && $file->isPublic() && ! MediaDelivery::servesDirectly($file);
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Where the selected keys the list no longer holds are now, through the list's own query — its type, site and
     * organisation, below its filters — so another site's or organisation's entry reads as absent, never as in the
     * trash. Null where that cannot be read: every one is then counted as gone, and none as already so.
     *
     * @param  Builder<Entry>  $list
     * @param  list<string>  $keys
     * @return ?array{trashed: int, present: int}
     */
    private static function whereNow(Builder $list, array $keys, bool &$reported): ?array
    {
        try {
            // ⚠️ IN ONE STATEMENT, so an entry trashed, restored or erased between two counts cannot make one exceed the
            // other (review of decision 35). At most the selection's own keys, so their rows rather than a count.
            $now = (clone $list)->withTrashed()->whereKey($keys)->toBase()->pluck($list->getModel()->getQualifiedDeletedAtColumn());

            return ['trashed' => $now->filter(static fn (mixed $at): bool => $at !== null)->count(), 'present' => $now->count()];
        } catch (Throwable $failure) {
            if (! $reported) {
                report($failure);
                $reported = true;
            }

            return null;
        }
    }

    /**
     * The one notification: a title that answers whether it worked, in a colour that does not contradict it, and a line
     * for every entry not removed.
     *
     * @param  list<string>  $refused  escaped lines
     * @param  list<string>  $failed  titles
     * @param  array<int, string>  $awaiting  titles, by entry id
     */
    private static function summary(string $verb, int $count, int $notDone, array $refused, array $failed, int $notTried, array $awaiting, int $done, int $already, int $gone): Notification
    {
        $key = static fn (string $name): string => "kitsune::media.removal.{$verb}.{$name}";
        $notification = Notification::make();
        // The first that applies.
        $says = match (true) {
            $count === 0 && $already === 0 => 'none',
            $refused !== [] || $failed !== [] => 'refused',
            $notTried > 0 => 'not_tried',
            $awaiting !== [] => 'awaiting',
            $done > 0 => 'done',
            default => 'already',
        };

        $title = match ($says) {
            'none' => __('kitsune::media.removal.none'),
            'refused', 'not_tried' => trans_choice($key('not_done'), $notDone, ['count' => $notDone]),
            'awaiting' => trans_choice('kitsune::media.removal.restore.awaiting', count($awaiting), ['count' => count($awaiting)]),
            'done' => trans_choice($key('done'), $done, ['count' => $done]),
            default => trans_choice($key('already'), $already, ['count' => $already]),
        };

        match (true) {
            $says === 'refused' => $notification->danger()->persistent(),
            // Not the colour of a selection done whole while some of it was not on the list to do.
            in_array($says, ['not_tried', 'awaiting'], true), $gone > 0 && $says !== 'none' => $notification->warning()->persistent(),
            $says === 'done' => $notification->success(),
            default => $notification->info(),
        };

        $lines = $refused;

        if ($failed !== []) {
            $lines[] = e(trans_choice($key('failed_line'), count($failed), ['titles' => BulkSelection::titles($failed)]));
        }

        if ($notTried > 0) {
            $lines[] = e(trans_choice('kitsune::media.removal.not_tried', $notTried, ['count' => $notTried]));
        }

        if ($awaiting !== []) {
            $lines[] = e(trans_choice('kitsune::media.selection.awaiting_line', count($awaiting), [
                'titles' => BulkSelection::titles(array_values($awaiting)),
                'entries' => implode(' ', array_map(static fn (int $id): string => "--entry={$id}", array_keys($awaiting))),
            ]));
        }

        // Counted in the body wherever the title does not already say so.
        if ($done > 0 && $says !== 'done') {
            $lines[] = e(trans_choice($key('done_count'), $done, ['count' => $done]));
        }

        if ($already > 0 && $says !== 'already') {
            $lines[] = e(trans_choice($key('already_count'), $already, ['count' => $already]));
        }

        if ($gone > 0 && $says !== 'none') {
            $lines[] = e(trans_choice('kitsune::media.removal.gone_line', $gone, ['count' => $gone]));
        }

        $notification->title(e($title));

        return $lines === [] ? $notification : $notification->body(implode('<br>', $lines));
    }

    private static function titleOf(Model $record): string
    {
        $title = $record->getAttribute('title');

        return is_string($title) && $title !== '' ? $title : '#'.$record->getKey();
    }
}
