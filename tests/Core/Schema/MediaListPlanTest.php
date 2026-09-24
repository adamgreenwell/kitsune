<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\PanelTenancy;

/*
 * A media type's list reads its page in order, by its plan — ADR-042's "the shape that keeps an ordered index read
 * pinned by its plan rather than by an index's existence".
 *
 * ⚠️ THE STATEMENT THE PANEL SENDS, NOT ONE WRITTEN HERE. It is composed by `EntryResource::getEloquentQuery()` under
 * Filament's own scope (`PanelTenancy`), with the order Filament's table adds — the default sort, then the key as its
 * tiebreak — so a change to either the widened rule or the list's conjunct is what this sees.
 *
 * ⚠️ ASKED IN A WAY EACH ENGINE CAN ANSWER ON A HANDFUL OF ROWS. A planner facing a tiny table picks a scan and a sort
 * whatever indexes exist, so the question is put as "can this index deliver this statement in order": PostgreSQL is
 * told not to scan or sort where it has another way, MySQL and MariaDB are handed the index, and SQLite, which plans
 * without statistics, is asked as it is. The unforced plans at 290k rows are ADR-042's measurement; this keeps the shape
 * that made them possible from quietly going away.
 *
 * ⚠️ WITH ITS CONTROL: `SiteScope`'s OR without the list's conjunct must sort on every engine, or this could not fail.
 */

const MEDIA_LIST_INDEX = 'entries_org_id_entry_type_id_updated_at_index';

beforeEach(function (): void {
    $this->org = Org::create(['slug' => 'plans', 'name' => 'Plans']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'plans-main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);

    $this->image = EntryType::create(['org_id' => $this->org->id, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);

    Entry::create(['entry_type_id' => $this->image->id, 'title' => 'Here', 'slug' => 'here']);
    Entry::create(['entry_type_id' => $this->image->id, 'site_id' => null, 'title' => 'Shared']);

    app(Context::class)->forget();
    PanelTenancy::enter($this->site);
});

afterEach(fn () => app(Context::class)->forget());

/** The list's page 1, as Filament's table sends it. */
function mediaListPage(): Builder
{
    app()->instance(EntryType::class, test()->image);

    return EntryResource::getEloquentQuery()
        ->orderBy('updated_at', 'desc')
        ->orderBy((new Entry)->getQualifiedKeyName(), 'desc')
        ->limit(11)
        ->toBase();
}

/** Whether this engine can deliver the statement in order through the media list's index, with no sort of its own. */
function readsInOrder(Builder $query): bool
{
    $driver = DB::connection()->getDriverName();

    if (in_array($driver, ['mysql', 'mariadb'], true)) {
        $query = $query->forceIndex(MEDIA_LIST_INDEX);
    }

    $sql = $query->toSql();
    $bindings = $query->getBindings();

    return match ($driver) {
        'sqlite' => (function () use ($sql, $bindings): bool {
            $plan = implode(' | ', array_map(fn ($row) => (string) $row->detail, DB::select('EXPLAIN QUERY PLAN '.$sql, $bindings)));

            return str_contains($plan, MEDIA_LIST_INDEX) && ! str_contains($plan, 'TEMP B-TREE');
        })(),
        'pgsql' => DB::transaction(function () use ($sql, $bindings): bool {
            DB::statement('SET LOCAL enable_seqscan = off');
            DB::statement('SET LOCAL enable_bitmapscan = off');
            DB::statement('SET LOCAL enable_sort = off');

            $plan = (string) DB::selectOne('EXPLAIN (FORMAT JSON) '.$sql, $bindings)->{'QUERY PLAN'};

            /*
             * An `Incremental Sort` whose presorted key is `updated_at` sorts only rows sharing one timestamp, for the key
             * tiebreak; a plain `Sort` sorts every row the page could come from.
             */
            return str_contains($plan, MEDIA_LIST_INDEX) && ! preg_match('/"Node Type":\s*"Sort"/', $plan);
        }),
        'mysql', 'mariadb' => collect(DB::select('EXPLAIN '.$sql, $bindings))
            ->every(fn ($row): bool => ! str_contains((string) ($row->Extra ?? ''), 'filesort')
                && ! str_contains((string) ($row->Extra ?? ''), 'temporary')),
    };
}

it('reads a media list\'s page in order through its index', function (): void {
    expect(readsInOrder(mediaListPage()))->toBeTrue();
});

/**
 * The control: the same page with both scopes and without the list's `org_id` — `SiteScope`'s OR and the widened rule
 * are all there is, and every engine has to sort. Were this true, the assertion above could not fail.
 */
it('sorts when the list loses its org conjunct', function (): void {
    $without = Entry::query()
        ->where('entry_type_id', $this->image->id)
        ->orderBy('updated_at', 'desc')
        ->orderBy((new Entry)->getQualifiedKeyName(), 'desc')
        ->limit(11)
        ->toBase();

    expect(readsInOrder($without))->toBeFalse();
});
