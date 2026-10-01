<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament;

use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\Enums\ActionStatus;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TrashedFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;
use Kitsune\Core\Media\MediaDelivery;
use Kitsune\Core\Media\MediaRefused;
use Kitsune\Core\Media\MediaVisibility;
use Kitsune\Core\Media\MediaVisibilityRefused;
use Kitsune\Core\Media\MediaWithdrawalRefused;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\MediaFile;
use Throwable;

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
 *
 * ⚠️ AND A SELECTION, ON A MEDIA LIST — Adam, decision 34. *Make selected public* and *Make selected private* call the
 * same door once a file, in the list's order, each committing — and, made public, publishing — before the next begins:
 * nothing holds two files' locks, and no transaction encloses them, for the reason above. At most `MOST_AT_ONCE` files,
 * fetched in one bounded query, or none; none started once the request's budget has passed, the first always tried. One
 * notification, Kitsune's alone, says what became of each file, escaped (decision 7). Who may is asked of the list's
 * type, as Upload asks it: a bulk action has no record, and the list holds only that type's entries.
 */
final class MediaVisibilityActions
{
    /** The most files one selection switches: the largest page a media list shows (Filament's options, 5 to 50). */
    public const MOST_AT_ONCE = 50;

    /** The longest, in seconds, a selection goes on starting files — and its budget where PHP sets no limit. */
    public const BUDGET_SECONDS = 15;

    // One file's outcome in a selection — never an array keyed by a fixed column, which `MediaFileImmutabilityTest` scans for.
    private const MADE = 'made';

    private const AWAITING = 'awaiting';

    private const ALREADY = 'already';

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
     * A media list's two, for a selection; none on any other list (decision 34).
     *
     * @return list<BulkAction>
     */
    public static function bulk(): array
    {
        return EntryResource::listsMedia() ? [self::makeSelectedPublic(), self::makeSelectedPrivate()] : [];
    }

    public static function makeSelectedPublic(): BulkAction
    {
        return self::selection(BulkAction::make('makeSelectedPublic'))
            ->label(__('kitsune::media.visibility.bulk.make_public'))
            ->icon(Heroicon::OutlinedGlobeAlt)
            ->modalHeading(static fn (Builder $selectedRecordsQuery): string => self::selectionHeading('public', self::countsOf($selectedRecordsQuery)[0]))
            ->modalDescription(static fn (Builder $selectedRecordsQuery): ?string => self::selectionNote('public', ...self::countsOf($selectedRecordsQuery)))
            ->modalSubmitActionLabel(__('kitsune::media.visibility.make_public'))
            /*
             * ⚠️ DECISION 15's ACKNOWLEDGEMENT, ONCE FOR THE SELECTION, AND NO FIELD UNLESS THE USER MAY PUBLISH — as the
             * page's: Filament caches an action's schema without asking whether it is disabled. The statement is still
             * written once (`lang/en/media.php`). Unticked is a validation error, and the handler asks again.
             */
            ->schema(static fn (): ?array => self::mayOnType('publish') ? [
                Checkbox::make('public_confirmed')
                    ->label(__('kitsune::media.visibility.bulk.public_confirm'))
                    ->helperText(__('kitsune::media.visibility.bulk.public_warning'))
                    ->accepted()
                    ->validationMessages(['accepted' => __('kitsune::media.visibility.bulk.not_confirmed')]),
            ] : null)
            ->action(static fn (BulkAction $action, Builder $selectedRecordsQuery, HasTable $livewire, array $data) => self::publicSelected($action, $selectedRecordsQuery, $data, self::keysSelected($livewire)));
    }

    public static function makeSelectedPrivate(): BulkAction
    {
        return self::selection(BulkAction::make('makeSelectedPrivate'))
            ->label(__('kitsune::media.visibility.bulk.make_private'))
            ->icon(Heroicon::OutlinedLockClosed)
            ->requiresConfirmation()
            ->modalHeading(static fn (Builder $selectedRecordsQuery): string => self::selectionHeading('private', self::countsOf($selectedRecordsQuery)[0]))
            ->modalDescription(static fn (Builder $selectedRecordsQuery): ?string => self::selectionNote('private', ...self::countsOf($selectedRecordsQuery)))
            ->modalSubmitActionLabel(__('kitsune::media.visibility.make_private'))
            ->action(static fn (BulkAction $action, Builder $selectedRecordsQuery, HasTable $livewire) => self::privateSelected($action, $selectedRecordsQuery, self::keysSelected($livewire)));
    }

    /**
     * The handler of *Make selected public*: nothing switched when more than `MOST_AT_ONCE` are selected.
     *
     * @internal
     *
     * @param  Builder<Entry>  $selected
     * @param  array<string, mixed>  $data
     * @param  ?int  $keys  how many the editor selected, where the selection is a list of keys
     */
    public static function publicSelected(BulkAction $action, Builder $selected, array $data, ?int $keys = null): void
    {
        // ⚠️ THE CLOCK FIRST, so a slow fetch spends the budget rather than extending it (Codex, #166).
        $until = self::deadline();
        $records = self::upTo($selected);
        $records === null ? self::refuseTooMany($action) : self::publicEach($action, $records, $data, $until, $keys);
    }

    /**
     * The handler of *Make selected private*: nothing switched when more than `MOST_AT_ONCE` are selected.
     *
     * @internal
     *
     * @param  Builder<Entry>  $selected
     * @param  ?int  $keys  how many the editor selected, where the selection is a list of keys
     */
    public static function privateSelected(BulkAction $action, Builder $selected, ?int $keys = null): void
    {
        // ⚠️ THE CLOCK FIRST, so a slow fetch spends the budget rather than extending it (Codex, #166).
        $until = self::deadline();
        $records = self::upTo($selected);
        $records === null ? self::refuseTooMany($action) : self::privateEach($action, $records, $until, $keys);
    }

    /**
     * Each record made public on its own, callable from tests, as `MediaDeletionNotice::deleteEach()` is.
     *
     * @internal
     *
     * @param  iterable<Model>  $records
     * @param  array<string, mixed>  $data
     * @param  ?CarbonInterface  $until  none started after it; null for no budget
     * @param  ?int  $keys  how many the editor selected; more than `$records` holds have left the list since
     */
    public static function publicEach(BulkAction $action, iterable $records, array $data, ?CarbonInterface $until = null, ?int $keys = null): void
    {
        // Asked again, as `publicOne()` asks: a request can carry anything.
        if (($data['public_confirmed'] ?? false) !== true) {
            $action->failure();

            Notification::make()->danger()
                ->title(e(__('kitsune::media.visibility.bulk.not_confirmed_title')))
                ->body(e(__('kitsune::media.visibility.bulk.not_confirmed')))
                ->send();

            return;
        }

        self::each($action, $records, 'public', $until, $keys);
    }

    /**
     * Each record made private on its own, callable from tests.
     *
     * @internal
     *
     * @param  iterable<Model>  $records
     * @param  ?CarbonInterface  $until  none started after it; null for no budget
     * @param  ?int  $keys  how many the editor selected; more than `$records` holds have left the list since
     */
    public static function privateEach(BulkAction $action, iterable $records, ?CarbonInterface $until = null, ?int $keys = null): void
    {
        self::each($action, $records, 'private', $until, $keys);
    }

    /**
     * The modal's heading, counting the selection.
     *
     * @internal
     */
    public static function selectionHeading(string $to, int $selected): string
    {
        return trans_choice($to === 'public' ? 'kitsune::media.visibility.bulk.make_public_heading' : 'kitsune::media.visibility.bulk.make_private_heading', $selected, ['count' => $selected]);
    }

    /**
     * The modal's description: what making a selection private does, how many of it are shared with every site, and the
     * bound, before it is submitted.
     *
     * @internal
     */
    public static function selectionNote(string $to, int $selected, int $shared): ?string
    {
        $public = $to === 'public';
        $sentences = $public ? [] : [__('kitsune::media.visibility.bulk.private_warning')];

        if ($shared > 0) {
            $sentences[] = match (true) {
                $selected === 1 => __($public ? 'kitsune::media.visibility.shared_public' : 'kitsune::media.visibility.shared_private'),
                $shared === $selected => __($public ? 'kitsune::media.visibility.bulk.shared_public_all' : 'kitsune::media.visibility.bulk.shared_private_all'),
                default => trans_choice($public ? 'kitsune::media.visibility.bulk.shared_public_some' : 'kitsune::media.visibility.bulk.shared_private_some', $shared, ['count' => $shared]),
            };
        }

        if ($selected > self::MOST_AT_ONCE) {
            $sentences[] = __('kitsune::media.visibility.bulk.too_many_note', ['max' => self::MOST_AT_ONCE, 'count' => $selected]);
        }

        return $sentences === [] ? null : implode(' ', $sentences);
    }

    /**
     * How many are selected, and how many of those are shared with every site — counted, never fetched. Laravel drops the
     * list's sort from a count (`setAggregate()`), as PostgreSQL needs beside `count(*)`.
     *
     * @internal
     *
     * @param  Builder<Entry>  $selected
     * @return array{int, int}
     */
    public static function countsOf(Builder $selected): array
    {
        return [(clone $selected)->count(), (clone $selected)->whereNull($selected->qualifyColumn('site_id'))->count()];
    }

    /**
     * The selection, loaded in the list's order — or null when it holds more than `$most`, fetching one more than that.
     *
     * ⚠️ REFUSED WHOLE, NEVER CUT: a selection cut to its first fifty would fetch the same fifty on every run, find them
     * already so, and never reach the rest. A page's checkbox bounds what the select-all box selects, and nothing on the
     * server: a page ticked after another, or a request written by hand, can select the whole list.
     *
     * @internal
     *
     * @param  Builder<Entry>  $selected
     * @return ?EloquentCollection<int, Entry>
     */
    public static function upTo(Builder $selected, int $most = self::MOST_AT_ONCE): ?EloquentCollection
    {
        $records = (clone $selected)->limit($most + 1)->get();

        return $records->count() > $most ? null : $records;
    }

    /**
     * How long a selection goes on starting files: half PHP's limit, at most `BUDGET_SECONDS`, and that where PHP sets
     * none — an Octane worker or an FPM pool set to 0 still has a timeout somewhere.
     *
     * @internal
     */
    public static function budgetSeconds(int $limit): float
    {
        return $limit > 0 ? min((float) self::BUDGET_SECONDS, $limit / 2) : (float) self::BUDGET_SECONDS;
    }

    /**
     * When the selection stops starting files: the budget from now, the handler's start. Half the limit leaves the
     * request's own work before the handler, and the file in hand when the budget passes, the other half.
     *
     * @internal
     */
    public static function deadline(): CarbonInterface
    {
        return now()->addMilliseconds((int) round(self::budgetSeconds((int) ini_get('max_execution_time')) * 1000));
    }

    /**
     * Whether a failure is a refusal an editor reads, as each page's handler catches them — every other is not theirs to
     * read (decision 7).
     *
     * @internal
     */
    public static function refusedIn(Throwable $failure, string $to): bool
    {
        return $failure instanceof MediaVisibilityRefused
            || ($to === 'public' ? $failure instanceof MediaRefused : $failure instanceof MediaWithdrawalRefused);
    }

    /**
     * Nothing changed: more than `MOST_AT_ONCE` selected.
     *
     * @internal
     */
    public static function refuseTooMany(BulkAction $action): void
    {
        $action->failure();

        Notification::make()->danger()->persistent()
            ->title(e(__('kitsune::media.visibility.bulk.too_many_title')))
            ->body(e(__('kitsune::media.visibility.bulk.too_many', ['max' => self::MOST_AT_ONCE])))
            ->send();
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

    /** What both selection actions share. */
    private static function selection(BulkAction $action): BulkAction
    {
        return $action
            // ⚠️ After `make()`, where a host panel's `configureUsing()` has already run: making public refuses inside one.
            ->databaseTransaction(false)
            // The handler's one notification is the only one: Filament's would count what it cannot see, unescaped.
            ->successNotification(null)
            ->failureNotification(null)
            ->authorize(static fn (): bool => self::mayOnType('update') || self::mayOnType('publish'))
            ->disabled(static fn (): bool => ! self::mayOnType('publish'))
            ->tooltip(static fn (): ?string => self::mayOnType('publish') ? null : self::needsPublish())
            ->hidden(static fn (HasTable $livewire): bool => self::onlyTheTrash($livewire))
            // Kept selected unless every file is as asked, so running it again goes on where it stopped.
            ->deselectRecordsAfterCompletion(static fn (BulkAction $action): bool => $action->getStatus() === ActionStatus::Success);
    }

    /** The list's type, asked as Upload asks it (`MediaUpload::may()`): false with none bound. */
    private static function mayOnType(string $action): bool
    {
        return app()->bound(EntryType::class)
            && Permissions::allows(Permissions::currentUser(), Permissions::forEntryType(app(EntryType::class)->handle, $action));
    }

    /** Not offered while the list shows only the trash, as *Delete selected* is not (Filament's `DeleteBulkAction`). */
    private static function onlyTheTrash(HasTable $livewire): bool
    {
        $state = $livewire->getTableFilterState(TrashedFilter::class) ?? [];

        return array_key_exists('value', $state) && ! $state['value'] && filled($state['value']);
    }

    /**
     * Each file through the switch on its own, and one notification saying what became of every one.
     *
     * @param  iterable<Model>  $records
     */
    private static function each(BulkAction $action, iterable $records, string $to, ?CarbonInterface $until, ?int $keys): void
    {
        $refused = $failed = $awaiting = [];
        $made = $already = $notTried = $count = 0;
        $tried = $reported = false;

        foreach ($records as $record) {
            /** @var Entry $record */
            $count++;

            /*
             * ⚠️ THE FIRST FILE IS ALWAYS TRIED, so running it again always goes on; past the budget no file is started,
             * and the one in hand finishes.
             */
            if ($notTried > 0 || ($tried && $until !== null && now()->greaterThanOrEqualTo($until))) {
                $notTried++;

                continue;
            }

            $tried = true;
            $title = self::titleOf($record);
            // What it was before — the list loaded it moments ago — tells a failed switch from one already so.
            $was = self::isAt($record->mediaFile, $to);

            try {
                $outcome = self::switchTo($record, $to);
            } catch (Throwable $failure) {
                if (self::refusedIn($failure, $to)) {
                    /*
                     * ⚠️ IN THE TRASH, MADE PRIVATE: *Everything* lists trashed entries beside live ones, and the switch
                     * refuses them both ways. One whose file is private is as asked; one set public is off the web while
                     * it is there, and published again by a restore, which the switch's "Restore it first" would do.
                     */
                    if ($to === 'private' && $failure instanceof MediaVisibilityRefused && $failure->reason === MediaVisibilityRefused::TRASHED) {
                        try {
                            $now = MediaDelivery::fileFor($record);
                        } catch (Throwable $unread) {
                            // Its row cannot be read: nothing is claimed of it (review of decision 34).
                            if (! $reported) {
                                report($unread);
                                $reported = true;
                            }

                            $failed[] = $title;

                            continue;
                        }

                        if ($now !== null && ! $now->isPublic()) {
                            $already++;

                            continue;
                        }

                        // ⚠️ AND ONE WITH NO FILE SAYS SO, not that a restore publishes it (review of decision 34).
                        $refused[] = $now === null
                            ? e(__('kitsune::media.visibility.bulk.refused_private_line', [
                                'title' => $title,
                                'reason' => (new MediaVisibilityRefused((int) $record->getKey(), MediaVisibilityRefused::NO_FILE, 'private'))->getMessage(),
                            ]))
                            : e(__('kitsune::media.visibility.bulk.trashed_private_line', ['title' => $title]));

                        continue;
                    }

                    $refused[] = e(__($to === 'public' ? 'kitsune::media.visibility.bulk.refused_public_line' : 'kitsune::media.visibility.bulk.refused_private_line', [
                        'title' => $title,
                        'reason' => $failure->getMessage(),
                    ]));

                    continue;
                }

                // As Filament does: the first is reported, and every other is likely the same.
                if (! $reported) {
                    report($failure);
                    $reported = true;
                }

                /*
                 * ⚠️ READ AGAIN, for a COMMIT that reported failure may have landed — the switch registers publication
                 * again for that — and the file is counted as what it is.
                 */
                $now = self::nowAt($record, $to);

                if ($now === null) {
                    $failed[] = $title;

                    continue;
                }

                $outcome = $was ? self::ALREADY : (($to === 'public' && ! MediaDelivery::servesDirectly($now)) ? self::AWAITING : self::MADE);
            }

            if ($outcome === self::MADE) {
                $made++;
            } elseif ($outcome === self::ALREADY) {
                $already++;
            } else {
                $awaiting[(int) $record->getKey()] = $title;
            }
        }

        $notDone = count($refused) + count($failed) + $notTried;
        $notDone === 0 ? $action->success() : $action->failure();

        /*
         * ⚠️ AND ANY SELECTED THAT HAVE LEFT THE LIST SINCE — trashed, or no longer matching its search — are said, never
         * dropped (review of decision 34). Filament fetches the selection through the list's filters, so they are not
         * here to switch; running it again would not reach them either, so they leave the selection as the rest do.
         */
        $gone = $keys === null ? 0 : max(0, $keys - $count);

        self::summary($to, $count, $notDone, $refused, $failed, $notTried, $awaiting, $made, $already, $gone)->send();
    }

    /**
     * How many keys the editor selected, or null where every record but those deselected is: that selection is the
     * list's own query, which nothing can have left.
     */
    private static function keysSelected(HasTable $livewire): ?int
    {
        if (! property_exists($livewire, 'selectedTableRecords') || ! property_exists($livewire, 'isTrackingDeselectedTableRecords')
            || $livewire->isTrackingDeselectedTableRecords !== false) {
            return null;
        }

        return count(array_unique(array_map(strval(...), (array) $livewire->selectedTableRecords)));
    }

    /** One file switched, and what it is now: made so, made public and not yet published, or already so. */
    private static function switchTo(Entry $record, string $to): string
    {
        if ($to === 'private') {
            return MediaVisibility::makePrivate($record) === MediaVisibility::UNCHANGED ? self::ALREADY : self::MADE;
        }

        if (MediaVisibility::makePublic($record) === MediaVisibility::UNCHANGED) {
            return self::ALREADY;
        }

        // ⚠️ READ AGAIN: the list loaded the file before the switch, which writes below the model.
        $file = MediaDelivery::fileFor($record);

        return $file !== null && MediaDelivery::servesDirectly($file) ? self::MADE : self::AWAITING;
    }

    /**
     * The file as it is now, where it is as asked — or null where it is not, or where that cannot be read, so nothing is
     * claimed of it.
     *
     * ⚠️ A FILE IN THE TRASH IS NEVER PUBLIC, whatever its row says, as the switch's own guard has it (review of decision
     * 34): its entry is read again, below the model, for one trashed since the list loaded.
     */
    private static function nowAt(Entry $record, string $to): ?MediaFile
    {
        try {
            $file = MediaDelivery::fileFor($record);

            if (! self::isAt($file, $to)) {
                return null;
            }

            $trashed = $record->getConnection()->table($record->getTable())->where($record->getKeyName(), $record->getKey())->value('deleted_at') !== null;

            return $to === 'public' && $trashed ? null : $file;
        } catch (Throwable) {
            return null;
        }
    }

    private static function isAt(?MediaFile $file, string $to): bool
    {
        return $file !== null && $file->isPublic() === ($to === 'public');
    }

    /**
     * The one notification: a title that answers whether it worked, and a line for every file not made so.
     *
     * @param  list<string>  $refused  escaped lines
     * @param  list<string>  $failed  titles
     * @param  array<int, string>  $awaiting  titles, by entry id
     * @param  int  $gone  selected, and no longer on the list
     */
    private static function summary(string $to, int $count, int $notDone, array $refused, array $failed, int $notTried, array $awaiting, int $made, int $already, int $gone): Notification
    {
        $key = static fn (string $name): string => "kitsune::media.visibility.bulk.{$name}_{$to}";
        $notification = Notification::make();
        // The first that applies: a title never claims more than happened, nor contradicts its colour.
        $says = match (true) {
            $count === 0 => 'none',
            $refused !== [] || $failed !== [] => 'refused',
            $notTried > 0 => 'not_tried',
            $awaiting !== [] => 'awaiting',
            $made > 0 => 'made',
            default => 'already',
        };

        $title = match ($says) {
            'none' => __('kitsune::media.visibility.bulk.none'),
            'refused', 'not_tried' => trans_choice($key('not_made'), $notDone, ['count' => $notDone]),
            'awaiting' => trans_choice('kitsune::media.visibility.bulk.made_public_awaiting', count($awaiting), ['count' => count($awaiting)]),
            'made' => trans_choice($key('made'), $made, ['count' => $made]),
            default => trans_choice($key('already'), $already, ['count' => $already]),
        };

        match (true) {
            $says === 'refused' => $notification->danger()->persistent(),
            // Not the colour of a selection done whole while some of it was not on the list to do.
            in_array($says, ['not_tried', 'awaiting'], true), $gone > 0 && $says !== 'none' => $notification->warning()->persistent(),
            $says === 'made' => $notification->success(),
            default => $notification->info(),
        };

        $lines = $refused;

        if ($failed !== []) {
            $lines[] = e(trans_choice($key('failed').'_line', count($failed), ['titles' => self::titles($failed)]));
        }

        if ($notTried > 0) {
            $lines[] = e(trans_choice('kitsune::media.visibility.bulk.not_tried', $notTried, ['count' => $notTried]));
        }

        if ($awaiting !== []) {
            $lines[] = e(trans_choice('kitsune::media.visibility.bulk.awaiting_line', count($awaiting), [
                'titles' => self::titles(array_values($awaiting)),
                'entries' => implode(' ', array_map(static fn (int $id): string => "--entry={$id}", array_keys($awaiting))),
            ]));
        }

        // Counted in the body wherever the title does not already say so.
        if ($made > 0 && $says !== 'made') {
            $lines[] = e(trans_choice($key('made').'_count', $made, ['count' => $made]));
        }

        if ($to === 'private' && $made > 0) {
            $lines[] = e(trans_choice('kitsune::media.visibility.bulk.made_private_body', $made));
        }

        if ($already > 0 && $says !== 'already') {
            $lines[] = e(trans_choice($key('already').'_count', $already, ['count' => $already]));
        }

        if ($gone > 0 && $says !== 'none') {
            $lines[] = e(trans_choice('kitsune::media.visibility.bulk.gone_line', $gone, ['count' => $gone]));
        }

        $notification->title(e($title));

        return $lines === [] ? $notification : $notification->body(implode('<br>', $lines));
    }

    /**
     * Titles, quoted and listed — escaped with the line they are in.
     *
     * @param  list<string>  $titles
     */
    private static function titles(array $titles): string
    {
        return implode(__('kitsune::media.visibility.bulk.list_separator'), array_map(
            static fn (string $title): string => __('kitsune::media.visibility.bulk.quoted', ['title' => $title]),
            $titles,
        ));
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
