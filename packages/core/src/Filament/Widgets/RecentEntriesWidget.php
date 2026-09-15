<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Widgets;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Kitsune\Core\Filament\Panels\KitsunePanel;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;

/**
 * The entries most recently changed on this site, for picking up where the work was left.
 *
 * ⚠️ ONLY THE TYPES THE SIDEBAR OFFERS, from the same list. A dashboard row for a type the user is refused
 * at the URL would hand them its titles, which is the leak `Permissions::constrainToViewable()` exists to
 * stop in the relation picker.
 */
final class RecentEntriesWidget extends TableWidget
{
    /** A glance, not a second entry list: the full list is one click away from every row's type. */
    public const ROWS = 10;

    protected static ?int $sort = 2;

    /*
     * ⚠️ NOT LAZY, for the reason `EntryCountsWidget` records: a lazy widget queries in a second request, which
     * the page measurement in `kitsune:benchmark-admin` never issues.
     */
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recently updated')
            ->query(fn (): Builder => self::recentQuery(KitsunePanel::viewableTypesHere()))
            ->columns([
                // `dir="auto"` for the reason the entry list gives: titles in one org can be in several scripts.
                TextColumn::make('title')->extraAttributes(['dir' => 'auto']),
                TextColumn::make('type_handle')->badge()->label('Type'),
                TextColumn::make('status')->badge(),
                TextColumn::make('updated_at')->since()->label('Updated'),
            ])
            /*
             * ⚠️ THE VIEW PAGE, NOT THE EDIT PAGE. `view` is the grant that put the row here; a viewer who holds
             * nothing else would be sent to a form that refuses them.
             */
            ->recordUrl(fn (Entry $entry): string => EntryResource::getUrl('view', [
                'type' => $entry->type_handle,
                'record' => $entry,
            ]))
            ->paginated(false)
            ->emptyStateHeading('Nothing has been written yet');
    }

    /**
     * The most recently updated entries of these types on the current site, newest first.
     *
     * ⚠️ BY TYPE ID, NOT HANDLE, for the reason `EntryCountsWidget::countsByStatus()` records.
     *
     * @param  Collection<int, EntryType>  $types
     * @return Builder<Entry>
     */
    public static function recentQuery(Collection $types): Builder
    {
        return Entry::query()
            ->whereIn('entry_type_id', $types->map(fn (EntryType $type): int => (int) $type->getKey())->all())
            // The id breaks ties, so two entries saved in the same second keep one order between requests.
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::ROWS);
    }
}
