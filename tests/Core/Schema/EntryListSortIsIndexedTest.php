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
     * ⚠️ THE EXACT PREFIX THE QUERY USES, and a looser test than this was the first version — review found
     * it. It accepted any site-leading index containing the sort column anywhere, so `(site_id, status,
     * updated_at)` would have satisfied the gate while serving nothing: the list page constrains `site_id`
     * and `entry_type_id` and does not constrain `status`, so an engine cannot reach `updated_at` through
     * that index in order. An index is a safety net for the shape it compares — AGENTS.md invariant 4
     * records that lesson about a UNIQUE constraint, and it holds for a lookup index too.
     *
     * ⚠️ ADR-021: it also has to LEAD with `site_id`. One ending on the sort column but starting elsewhere
     * would serve a query that crossed sites, which is the one query this table must never make cheap.
     *
     * Trailing columns are permitted — `(site_id, entry_type_id, updated_at, id)` is a superset that serves
     * the same order — so this pins a prefix rather than the whole list.
     */
    $prefix = ['site_id', 'entry_type_id', $sort];

    $covering = $indexes->filter(fn (array $columns): bool => array_slice($columns, 0, 3) === $prefix);

    expect($covering)->not->toBeEmpty(
        'no index on `entries` begins ('.implode(', ', $prefix).'), which is the equality prefix the entry '
        .'list constrains followed by the column it orders by; without it the list page pays a full sort of '
        .'every row in the site on every request',
    );
});
