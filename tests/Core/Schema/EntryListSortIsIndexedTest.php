<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;

/*
 * The column the entry list sorts by must be indexed, and the index must lead with the scope key.
 *
 * ⚠️ THIS EXISTS BECAUSE A BENCHMARK MEASURED A QUERY THE APPLICATION DOES NOT ISSUE.
 * `kitsune:benchmark-storage` reported the list page comfortably inside the 200ms Phase 4 target at 100k
 * rows — probing `order by published_at`, which nothing in the admin orders by. The entry list orders by
 * `updated_at desc`, nothing indexed it, and the page paid a full sort of every row in the site on every
 * request. The list query alone, on SQLite at 100k entries, median of five:
 *
 *                      page 1      offset 99,990
 *   without the index  22.51 ms    153.65 ms
 *   with it             0.07 ms     16.65 ms
 *
 * ⚠️ AND IT IS COUPLED TO THE RESOURCE RATHER THAN TO A STRING. `EntryResource::DEFAULT_SORT` is what the
 * table sorts by and what this reads, so changing the sort fails this test until an index covers the new
 * one. A test naming `updated_at` itself would go quietly vacuous the day somebody sorted by something
 * else — which is exactly how the gap it closes was opened.
 */

it('indexes the column the entry list sorts by, behind the scope key', function (): void {
    $indexes = collect(Schema::getIndexes('entries'))
        ->map(fn (array $index): array => array_map(
            static fn (string $column): string => mb_strtolower($column),
            array_values((array) ($index['columns'] ?? [])),
        ));

    /*
     * ⚠️ Not vacuous: `getIndexes()` reports differently across the four engines, and an empty list would
     * make every assertion below pass. `entries` carries the unique slug index on all of them.
     */
    expect($indexes)->toContain(['site_id', 'entry_type_id', 'slug']);

    $sort = mb_strtolower(EntryResource::DEFAULT_SORT);

    /*
     * ⚠️ ADR-021: the index has to LEAD with `site_id`. One ending on the sort column but starting
     * elsewhere would serve a query that crossed sites, which is the one query this table must never make
     * cheap — and it would not serve the list page, whose first predicate is the scope key.
     */
    $covering = $indexes->filter(
        fn (array $columns): bool => ($columns[0] ?? null) === 'site_id' && in_array($sort, $columns, true),
    );

    expect($covering)->not->toBeEmpty(
        "no index on `entries` leads with site_id and covers the entry list's sort column ({$sort}); ".
        'the list page pays a full sort of every row in the site on every request',
    );
});
