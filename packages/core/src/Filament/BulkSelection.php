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
use Filament\Actions\Enums\ActionStatus;
use Filament\Notifications\Notification;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Kitsune\Core\Models\Entry;

/**
 * What every selection on a media list shares — its five bulk actions, ADR-042 decisions 34 and 35.
 *
 * @internal
 *
 * ⚠️ HANDED A QUERY, NEVER RECORDS. A closure on a bulk action that takes `$records` or `$selectedRecords` has Filament
 * load the whole selection — sorted and eager-loaded, with no limit — before it is called: Filament resolves a parameter
 * by its name before its type. Take `Builder $selectedRecordsQuery`, and fetch through `upTo()`.
 *
 * ⚠️ REFUSED WHOLE, NEVER CUT. A selection cut to its first fifty would fetch the same fifty on every run, find them
 * already as asked, and never reach the rest. A page's checkbox bounds what the select-all box selects, and nothing on
 * the server: a page ticked after another, or a request written by hand, can select the whole list.
 *
 * ⚠️ THE CLOCK BEFORE THE FETCH (Codex, #166), AND THE FIRST THAT NEEDS WORK ALWAYS STARTED, so a slow fetch spends the
 * budget rather than extending it, and running it again always goes on.
 */
final class BulkSelection
{
    /** The most one selection on a media list acts on: the largest page a list shows (Filament's options, 5 to 50). */
    public const MOST_AT_ONCE = 50;

    /** The longest, in seconds, a selection goes on starting records — and its budget where PHP sets no limit. */
    public const BUDGET_SECONDS = 15;

    private int $notTried = 0;

    private bool $tried = false;

    private function __construct(private readonly ?CarbonInterface $until) {}

    /** A run's budget, from a deadline: null for none. */
    public static function within(?CarbonInterface $until): self
    {
        return new self($until);
    }

    /**
     * Whether the next record that needs work is started: the first always; none once the deadline has passed, nor any
     * after one that was not, so a selection is never worked out of the list's order. The one in hand finishes.
     */
    public function starts(): bool
    {
        if ($this->notTried > 0 || ($this->tried && $this->until !== null && now()->greaterThanOrEqualTo($this->until))) {
            $this->notTried++;

            return false;
        }

        return $this->tried = true;
    }

    /** How many were not started. */
    public function notTried(): int
    {
        return $this->notTried;
    }

    /**
     * What every selection action sets, after `make()`, where a host panel's `configureUsing()` has already run.
     *
     * @template T of BulkAction
     *
     * @param  T  $action
     * @return T
     */
    public static function oneByOne(BulkAction $action): BulkAction
    {
        return $action
            // ⚠️ One transaction around a selection would hold every record's lock — on SQLite the write lock — to its end,
            // and put every publication and disposal after it, past the budget; making a file public refuses inside one.
            ->databaseTransaction(false)
            // The handler's one notification is the only one: handed a query, Filament counts nothing, and would say
            // "Deleted" over a refusal.
            ->successNotification(null)
            ->failureNotification(null)
            // Kept selected unless every record is as asked, so running it again goes on where it stopped.
            ->deselectRecordsAfterCompletion(static fn (BulkAction $action): bool => $action->getStatus() === ActionStatus::Success);
    }

    /**
     * The selection, loaded in the list's order — or null when it holds more than `$most`, fetching one more than that.
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
     * How many are selected — counted, never fetched. Laravel drops the list's sort from a count (`setAggregate()`), as
     * PostgreSQL needs beside `count(*)`.
     *
     * @param  Builder<Entry>  $selected
     */
    public static function countOf(Builder $selected): int
    {
        return (clone $selected)->count();
    }

    /**
     * How long a selection goes on starting records: half PHP's limit, at most `BUDGET_SECONDS`, and that where PHP sets
     * none — an Octane worker or an FPM pool set to 0 still has a timeout somewhere.
     */
    public static function budgetSeconds(int $limit): float
    {
        return $limit > 0 ? min((float) self::BUDGET_SECONDS, $limit / 2) : (float) self::BUDGET_SECONDS;
    }

    /**
     * When a selection stops starting records: the budget from now, the handler's start. Half the limit leaves the other
     * half for the request's own work before the handler, and for the record in hand when the budget passes.
     */
    public static function deadline(): CarbonInterface
    {
        return now()->addMilliseconds((int) round(self::budgetSeconds((int) ini_get('max_execution_time')) * 1000));
    }

    /**
     * The keys the editor selected, unique and as strings — or null where every record but those deselected is: that
     * selection is the list's own query, which nothing can have left.
     *
     * @return ?list<string>
     */
    public static function keysSelected(HasTable $livewire): ?array
    {
        if (! property_exists($livewire, 'selectedTableRecords') || ! property_exists($livewire, 'isTrackingDeselectedTableRecords')
            || $livewire->isTrackingDeselectedTableRecords !== false) {
            return null;
        }

        return array_values(array_unique(array_map(strval(...), (array) $livewire->selectedTableRecords)));
    }

    /** Nothing changed: one danger, persistent notification, its words escaped (decision 7), and a failure. */
    public static function refuse(BulkAction $action, string $title, string $body): void
    {
        $action->failure();

        Notification::make()->danger()->persistent()->title(e($title))->body(e($body))->send();
    }

    /**
     * Titles, quoted and listed — escaped with the line they are in.
     *
     * @param  list<string>  $titles
     */
    public static function titles(array $titles): string
    {
        return implode(__('kitsune::media.selection.list_separator'), array_map(
            static fn (string $title): string => __('kitsune::media.selection.quoted', ['title' => $title]),
            $titles,
        ));
    }
}
