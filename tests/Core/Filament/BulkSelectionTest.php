<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Filament\Actions\BulkAction;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Filament\BulkSelection;
use Kitsune\Core\Filament\Resources\Entries\Pages\ListEntries;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/*
 * What every bounded selection shares — ADR-042 decisions 34, 35 and 36. Each part on its own; the actions that use them
 * are `MediaVisibilityBulkTest`'s and `MediaBulkRemovalTest`'s.
 */

beforeEach(function (): void {
    $org = Org::create(['slug' => 'shared-rules', 'name' => 'Shared rules']);
    app(Context::class)->setOrg($org);
    app(Context::class)->setSite(Site::create(['handle' => 'main', 'slug' => 'shared-rules-main', 'name' => 'Main', 'locale' => 'en']));
    $this->type = EntryType::create(['org_id' => $org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);
    session()->forget('filament.notifications');
});

afterEach(fn () => app(Context::class)->forget());

/* The gate: the first always; none once the deadline has passed, nor after one that was not; always with no deadline. */
it('starts the first however late, none once the budget has passed, and none after one it did not start', function (): void {
    $late = BulkSelection::within(now()->subSecond());

    expect($late->starts())->toBeTrue()
        ->and($late->starts())->toBeFalse()
        ->and($late->notTried())->toBe(1);

    $this->freezeTime();
    $budget = BulkSelection::within(now()->addSeconds(10));

    expect($budget->starts())->toBeTrue()
        ->and($budget->starts())->toBeTrue();

    $this->travel(10)->seconds();
    expect($budget->starts())->toBeFalse();

    // Once one was not started, none after it is, whatever the clock says.
    $this->travel(-20)->seconds();
    expect($budget->starts())->toBeFalse()
        ->and($budget->notTried())->toBe(2);

    $none = BulkSelection::within(null);
    $this->travel(3600)->seconds();

    expect($none->starts())->toBeTrue()
        ->and($none->starts())->toBeTrue()
        ->and($none->notTried())->toBe(0);
});

/* The keys the page selected, unique and as strings — or none where every record but those deselected is selected. */
it('reads the page\'s selected keys, and none while it tracks the deselected', function (): void {
    $page = app(ListEntries::class);
    $page->selectedTableRecords = [1, '1', 2];

    expect(BulkSelection::keysSelected($page))->toBe(['1', '2']);

    $page->isTrackingDeselectedTableRecords = true;

    expect(BulkSelection::keysSelected($page))->toBeNull();
});

/* Nothing changed: a failure, and one danger notice that stays, escaped. */
it('refuses with one persistent danger notice, escaped, and a failure', function (): void {
    $action = BulkAction::make('probe');

    BulkSelection::refuse($action, '<b>Title</b>', '<i>Body</i>');

    $notices = array_values((array) session('filament.notifications', []));

    expect($notices)->toHaveCount(1)
        ->and($notices[0]['status'])->toBe('danger')
        ->and($notices[0]['duration'])->toBe('persistent')
        ->and($notices[0]['title'])->toBe('&lt;b&gt;Title&lt;/b&gt;')
        ->and($notices[0]['body'])->toBe('&lt;i&gt;Body&lt;/i&gt;')
        ->and($action->getStatus()->name)->toBe('Failure');
});

/* Counted in one query, without the list's sort, and the list's query left as it was. */
it('counts a selection in one query without its sort, leaving the query as it was', function (): void {
    foreach (['A', 'B', 'C'] as $title) {
        Entry::create(['entry_type_id' => $this->type->id, 'title' => $title, 'status' => 'draft']);
    }

    $query = Entry::query()->orderByDesc('updated_at');
    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    expect(BulkSelection::countOf($query))->toBe(3)
        ->and($queries)->toHaveCount(1)
        ->and($queries[0])->toContain('count(')
        ->and($queries[0])->not->toContain('order by')
        ->and($query->getQuery()->orders)->not->toBeEmpty();
});

/* Titles quoted and listed in the selection's own words. */
it('quotes and lists titles', function (): void {
    expect(BulkSelection::titles(['A', 'B']))->toBe('"A", "B"');

    app('translator')->addLines(['media.selection.quoted' => '«:title»', 'media.selection.list_separator' => ' / '], 'en', 'kitsune');

    expect(BulkSelection::titles(['A', 'B']))->toBe('«A» / «B»');
});
