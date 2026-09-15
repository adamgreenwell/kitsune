<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Kitsune\Core\Filament\Widgets\RecentEntriesWidget;

/*
 * The column the dashboard's recent entries are ordered by must be indexed directly behind the scope key.
 *
 * ⚠️ THE ENTRY LIST'S INDEX DOES NOT SERVE THIS QUERY, which is why it has a test of its own. The list asks for one
 * type, so `(site_id, entry_type_id, updated_at)` hands it rows in order. The widget asks for every type the reader
 * is offered, so the same index yields one ordered run per type and the engine sorts them together — every entry
 * on the site, on every dashboard load. On SQLite at 100k entries, median of five requests:
 *
 *   without (site_id, updated_at)   23.92 ms
 *   with it                          0.18 ms
 *
 * ⚠️ COUPLED TO `RecentEntriesWidget::SORT`, for the reason `EntryListSortIsIndexedTest` is coupled to the
 * resource: a test naming `updated_at` would go quietly vacuous the day the widget ordered by something else.
 */

it('indexes the column recent entries are ordered by, directly behind the scope key', function (): void {
    $indexes = collect(Schema::getIndexes('entries'))
        ->map(fn (array $index): array => array_map(
            static fn (string $column): string => mb_strtolower($column),
            array_values((array) ($index['columns'] ?? [])),
        ));

    // ⚠️ Not vacuous, for the reason the list's test gives: an empty index list would pass everything below.
    expect($indexes)->toContain(['site_id', 'entry_type_id', 'slug']);

    /*
     * ⚠️ `site_id` AND THEN THE SORT, WITH NOTHING BETWEEN. The query constrains `site_id` by equality and the types
     * by a list, so a column between them — `entry_type_id` included — puts the order out of the engine's reach.
     * Leading with `site_id` is ADR-021's rule: an index that led with the sort would make a cross-site query cheap.
     */
    $prefix = ['site_id', mb_strtolower(RecentEntriesWidget::SORT)];

    $covering = $indexes->filter(fn (array $columns): bool => array_slice($columns, 0, 2) === $prefix);

    expect($covering)->not->toBeEmpty(
        'no index on `entries` begins ('.implode(', ', $prefix).'), which is the equality the dashboard\'s recent '
        .'entries constrain followed by the column they are ordered by; without it every dashboard load sorts '
        .'every entry on the site',
    );
});
