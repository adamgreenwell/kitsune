<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Widgets;

use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;
use Kitsune\Core\Filament\Icons;
use Kitsune\Core\Filament\Panels\KitsunePanel;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;
use Kitsune\Core\Filament\Resources\EntryTypes\EntryTypeResource;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;

/**
 * How much content this site holds, one stat per entry type, each linking to that type's list.
 *
 * ⚠️ THE SIDEBAR'S TYPES, FROM THE SIDEBAR'S LIST. A count for a type the user is refused at the URL tells
 * them how much sits behind it, and a second copy of the filter is a second place for it to be forgotten —
 * so this asks `KitsunePanel::viewableTypesHere()` rather than working the answer out again.
 */
final class EntryCountsWidget extends StatsOverviewWidget
{
    use RestrictsFileUploadsToSchemaComponents;

    protected static ?int $sort = 1;

    /*
     * ⚠️ NOT LAZY. Filament widgets are lazy by default: the page renders a placeholder and each widget fetches
     * itself in a second Livewire request. `kitsune:benchmark-admin` measures the page GET, so a lazy widget's
     * queries would be missing from the dashboard row it reports, and that row would describe a page nobody
     * sees. One grouped query is cheaper than the round trip that would hide it.
     */
    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $types = KitsunePanel::viewableTypesHere();

        if ($types->isEmpty()) {
            return [self::nothingToCount()];
        }

        $counts = self::countsByStatus($types);

        return $types
            ->map(function (EntryType $type) use ($counts): Stat {
                $byStatus = $counts[(int) $type->getKey()] ?? [];

                return Stat::make($type->plural_name, array_sum($byStatus))
                    ->description(self::describe($byStatus).($type->is_media ? ' · '.__('kitsune::media.dashboard.site_own') : ''))
                    // Through `Icons::orFallback()`, for the reason navigation gives: stored icon names are data.
                    ->icon(Icons::orFallback($type->icon))
                    ->url(EntryResource::getUrl('index', ['type' => $type->handle]));
            })
            ->all();
    }

    /**
     * Entries per type and status on the current site, in one query however many types there are.
     *
     * ⚠️ BY TYPE ID, NOT HANDLE. A global type and an org type may share a handle, and the list each stat links
     * to filters by the type `IdentifyEntryType` resolved — see `EntryResource::getEloquentQuery()`. Counting
     * by handle would add the shadowed type's entries to a number whose list does not show them.
     *
     * ⚠️ THIS SITE'S OWN ROWS, AND NOT THE ORG'S SHARED FILES — decided by Adam on the measurements, ADR-042
     * decision 2. With the widened rule this one grouped query read every file of every media type the org holds on
     * every site: at 290k rows it took 216 ms on SQLite and 163 ms on MySQL, against 15 ms and 52 ms for this site's
     * own. So it keeps Filament's unwidened rule, as `RecentEntriesWidget` does, and a media type's stat says so;
     * its list, which does admit the shared files, pages without a total for the same reason.
     *
     * @param  Collection<int, EntryType>  $types
     * @return array<int, array<string, int>> entry type id => status => entries
     */
    public static function countsByStatus(Collection $types): array
    {
        $rows = EntryResource::onlyThisSitesRows(Entry::query())
            ->whereIn('entry_type_id', $types->map(fn (EntryType $type): int => (int) $type->getKey())->all())
            ->toBase()
            ->select(['entry_type_id', 'status'])
            ->selectRaw('count(*) as aggregate')
            ->groupBy(['entry_type_id', 'status'])
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row->entry_type_id][(string) $row->status] = (int) $row->aggregate;
        }

        return $counts;
    }

    /**
     * A type's entries by status, in the order an entry moves through them, leaving out the statuses it has none of.
     *
     * @param  array<string, int>  $byStatus
     */
    public static function describe(array $byStatus): string
    {
        $parts = [];

        foreach (Entry::STATUSES as $status) {
            if (($byStatus[$status] ?? 0) > 0) {
                $parts[] = $byStatus[$status].' '.$status;
            }
        }

        return $parts === [] ? 'None yet' : implode(', ', $parts);
    }

    /**
     * What the widget says when there is no type to count.
     *
     * ⚠️ AN EMPTY WIDGET IS THE DEFECT THIS CLASS EXISTS TO FIX, so the empty case says something too: where to
     * make a type, to somebody who may, and that nothing is shared with them, to somebody who may not.
     */
    private static function nothingToCount(): Stat
    {
        if (EntryTypeResource::canViewAny()) {
            return Stat::make('Entry types', 0)
                ->description('None yet. Create one to start adding content.')
                ->url(EntryTypeResource::getUrl('index'));
        }

        return Stat::make('Content', 0)->description('No entry type on this site is shared with you yet.');
    }
}
