<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Filament\Panels\KitsunePanel;
use Kitsune\Core\Filament\Widgets\EntryCountsWidget;
use Kitsune\Core\Filament\Widgets\RecentEntriesWidget;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\TestUser;

/*
 * The dashboard's two widgets, and the list of types they share with the sidebar.
 *
 * ⚠️ A NEW INSTALL OPENED ONTO AN EMPTY PAGE, found by the alpha pass: Filament's dashboard with no widgets, a
 * heading and nothing under it. The widgets render through Livewire, which this suite cannot build (ADR-024), so
 * what is pinned here is what they render — which types, which counts, which rows. `e2e/dashboard.spec.js` and
 * `e2e/permissions.spec.js` pin the page.
 */

beforeEach(function (): void {
    config(['auth.providers.users.model' => TestUser::class]);

    $this->org = Org::create(['slug' => 'dash', 'name' => 'Dash']);
    app(Context::class)->setOrg($this->org);

    $this->site = Site::create(['org_id' => $this->org->id, 'handle' => 'main', 'slug' => 'dash-main', 'name' => 'Main']);
    $this->otherSite = Site::create(['org_id' => $this->org->id, 'handle' => 'other', 'slug' => 'dash-other', 'name' => 'Other']);
    app(Context::class)->setSite($this->site);

    $this->article = EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);
    $this->product = EntryType::create(['org_id' => $this->org->id, 'handle' => 'product', 'name' => 'Product', 'plural_name' => 'Products']);
    $this->podcast = EntryType::create(['org_id' => $this->org->id, 'handle' => 'podcast', 'name' => 'Podcast', 'plural_name' => 'Podcasts']);

    // Podcasts belong to the org and are turned off on this site (ADR-022).
    EntryTypeAvailability::create([
        'entry_type_id' => $this->podcast->id, 'scope_type' => 'site', 'scope_id' => $this->site->id, 'is_enabled' => false,
    ]);
});

afterEach(fn () => app(Context::class)->forget());

/** A member of the org holding one role. */
function dashboardUser(Org $org, bool $owner = false, array $grants = []): TestUser
{
    /** @var TestUser $user */
    $user = TestUser::create(['email' => 'dash'.mt_rand(1, 1_000_000_000).'@kitsune.test']);

    $role = Role::create([
        'org_id' => $org->getKey(), 'handle' => 'd'.mt_rand(1, 1_000_000_000), 'name' => 'Role', 'is_owner' => $owner,
    ]);

    foreach ($grants as $grant) {
        $role->grant($grant);
    }

    DB::table('role_user')->insert(['role_id' => $role->getKey(), 'user_id' => $user->getKey()]);
    DB::table('org_user')->insert(['org_id' => $org->getKey(), 'user_id' => $user->getKey()]);

    return $user;
}

/** An entry of this type on the site in context. */
function dashboardEntry(EntryType $type, string $status, string $title): Entry
{
    return Entry::create([
        'entry_type_id' => $type->getKey(),
        'title' => $title,
        'status' => $status,
        'published_at' => $status === 'published' ? now() : null,
    ]);
}

it('offers the types the site enables and the user may view', function (): void {
    $owner = dashboardUser($this->org, owner: true);
    $editor = dashboardUser($this->org, grants: [Permissions::forEntryType('article', 'view')]);

    $offered = fn (?TestUser $user): array => KitsunePanel::viewableTypes($this->site, $this->org->id, $user)
        ->pluck('handle')
        ->all();

    expect($offered($owner))->toBe(['article', 'product'])
        ->and($offered($editor))->toBe(['article'])
        ->and($offered(null))->toBe([]);
});

it('counts entries per type and status on this site alone, in one query', function (): void {
    dashboardEntry($this->article, 'published', 'First');
    dashboardEntry($this->article, 'published', 'Second');
    dashboardEntry($this->article, 'draft', 'Third');
    dashboardEntry($this->product, 'archived', 'Retired mower');

    // A type nobody asked about, on this site.
    $page = EntryType::create(['org_id' => $this->org->id, 'handle' => 'page', 'name' => 'Page', 'plural_name' => 'Pages']);
    dashboardEntry($page, 'published', 'About');

    // The same type on another site of the same org.
    app(Context::class)->setSite($this->otherSite);
    dashboardEntry($this->article, 'published', 'Elsewhere');
    app(Context::class)->setSite($this->site);

    // A global type the org's article shadows, sharing its handle: counted by handle, its entry would join the org's.
    $shadowed = EntryType::create(['org_id' => null, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);
    dashboardEntry($shadowed, 'published', 'Shadowed');

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $counts = EntryCountsWidget::countsByStatus(collect([$this->article, $this->product]));

    expect($queries)->toBe(1)
        ->and($counts)->toEqual([
            $this->article->id => ['published' => 2, 'draft' => 1],
            $this->product->id => ['archived' => 1],
        ]);
});

it('describes a type by the statuses it holds, in the order an entry moves through them', function (): void {
    expect(EntryCountsWidget::describe(['archived' => 1, 'published' => 4, 'draft' => 2]))
        ->toBe('2 draft, 4 published, 1 archived')
        ->and(EntryCountsWidget::describe(['published' => 3]))->toBe('3 published')
        ->and(EntryCountsWidget::describe([]))->toBe('None yet');
});

it('lists the newest entries of the offered types on this site, ten at most', function (): void {
    foreach (range(1, 12) as $i) {
        $this->travelTo(now()->addMinute());
        dashboardEntry($this->article, 'draft', "Article {$i}");
    }

    // The oldest, edited last: ordering by id instead of by `updated_at` would leave it at the bottom.
    $this->travelTo(now()->addMinute());
    Entry::query()->where('title', 'Article 1')->firstOrFail()->touch();

    // Newer than every article, and each excluded for its own reason: a type not offered, and another site.
    $this->travelTo(now()->addMinute());
    dashboardEntry($this->product, 'draft', 'Newest product');

    app(Context::class)->setSite($this->otherSite);
    $this->travelTo(now()->addMinute());
    dashboardEntry($this->article, 'draft', 'Newest elsewhere');
    app(Context::class)->setSite($this->site);

    $titles = RecentEntriesWidget::recentQuery(collect([$this->article]))->pluck('title')->all();

    expect($titles)->toBe(['Article 1', ...array_map(fn (int $i): string => "Article {$i}", range(12, 4))]);
});
