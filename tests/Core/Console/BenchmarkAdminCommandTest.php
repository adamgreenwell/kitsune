<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Console\BenchmarkAdminCommand;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\PanelTenancy;
use Kitsune\Core\Tests\Fixtures\PanelUser;
use Kitsune\Core\Tests\Fixtures\TestUser;

/*
 * ⚠️ THE COMMAND'S REQUESTS ARE NOT EXERCISED HERE, AND THAT IS A LIMIT RATHER THAN A CHOICE. It issues real
 * requests to a Filament panel, and this layer cannot see anything that only exists once a page renders —
 * ADR-024's division of labour. What IS here is the logic worth guarding, and it is here because that logic was
 * wrong first: how a page's summary is read, and — under `PanelTenancy`, which registers the panel — which type
 * the command chooses to measure.
 *
 * The first version calculated the deep page as `seeded / 25`. Both halves were wrong — the list is scoped
 * to one entry type, so the corpus is smaller than the seed, and Filament's table pages at TEN. It
 * measured page 8 of 20, labelled it "last page", and reported the number without hesitating.
 */

it('reads the page size and the corpus out of the table summary', function (): void {
    // The line Filament renders, reduced to what survives strip_tags.
    $summary = BenchmarkAdminCommand::paginationSummary(
        '<div>Showing 1 to 10 of 99,998 results</div><nav>5 10 25 50</nav>',
    );

    expect($summary)->toBe([10, 99998]);
});

it('reads a page that is not the first', function (): void {
    // Page size comes from the range rather than from a constant, so a deep page reports it too.
    expect(BenchmarkAdminCommand::paginationSummary('Showing 51 to 75 of 400 results'))->toBe([25, 400]);
});

it('reports nothing rather than a guess when the summary is absent', function (): void {
    /*
     * ⚠️ THE POINT OF THE NULL. A failed parse omits the deep-page row and says so; a parser that fell
     * back to a default page size would put the measurement at the wrong depth and print it in the same
     * table as the ones that are right, which is how a number becomes a false claim.
     */
    expect(BenchmarkAdminCommand::paginationSummary('<p>No entries yet.</p>'))->toBeNull()
        ->and(BenchmarkAdminCommand::paginationSummary(''))->toBeNull()
        ->and(BenchmarkAdminCommand::paginationSummary('Showing some of many results'))->toBeNull();
});

it('reports nothing when the range is impossible', function (): void {
    // `to` before `from` yields a page size of zero or less, which would make the last page infinite.
    expect(BenchmarkAdminCommand::paginationSummary('Showing 10 to 1 of 400 results'))->toBeNull();
});

/*
 * ⚠️ AND NEVER A MEDIA TYPE, whose create page answers 404 (ADR-042 decision 3): the command would seed its rows and time a
 * refusal as the create page. Asked of the predicate here, and of the choice the command makes with it below.
 */
it('measures a type the user may view, and never a media type', function (): void {
    config(['auth.providers.users.model' => TestUser::class]);
    $org = Org::create(['slug' => 'bench', 'name' => 'Bench']);
    app(Context::class)->setOrg($org);
    $user = TestUser::create(['email' => 'bench@kitsune.test']);
    DB::table('org_user')->insert(['org_id' => $org->getKey(), 'user_id' => $user->getKey()]);
    $role = Role::create(['handle' => 'bench', 'name' => 'Bench']);
    DB::table('role_user')->insert(['role_id' => $role->getKey(), 'user_id' => $user->getKey()]);
    $role->grant(Permissions::forEntryType('image', 'view'));
    $role->grant(Permissions::forEntryType('article', 'view'));

    $image = EntryType::create(['org_id' => $org->id, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);
    $article = EntryType::create(['org_id' => $org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);
    $page = EntryType::create(['org_id' => $org->id, 'handle' => 'page', 'name' => 'Page', 'plural_name' => 'Pages']);

    try {
        expect(BenchmarkAdminCommand::measurable($image, $user))->toBeFalse()
            ->and(BenchmarkAdminCommand::measurable($article, $user))->toBeTrue()
            // The control on the other half: a type the user may not view.
            ->and(BenchmarkAdminCommand::measurable($page, $user))->toBeFalse();
    } finally {
        app(Context::class)->forget();
    }
});

/* The choice itself: a media type the user may view, listed first, is passed over for the type after it. */
it('chooses a type it can measure when a media type the user may view is listed first', function (): void {
    config(['auth.providers.users.model' => PanelUser::class]);
    $org = Org::create(['slug' => 'bench', 'name' => 'Bench']);
    app(Context::class)->setOrg($org);
    $site = Site::create(['handle' => 'main', 'slug' => 'bench-main', 'name' => 'Main', 'locale' => 'en']);
    $user = PanelUser::create(['email' => 'bench@kitsune.test']);
    $user->reachableSiteIds = [(int) $site->getKey()];
    DB::table('org_user')->insert(['org_id' => $org->getKey(), 'user_id' => $user->getKey()]);
    $role = Role::create(['handle' => 'bench', 'name' => 'Bench']);
    DB::table('role_user')->insert(['role_id' => $role->getKey(), 'user_id' => $user->getKey()]);
    $role->grant(Permissions::forEntryType('image', 'view'));
    $role->grant(Permissions::forEntryType('article', 'view'));
    EntryType::create(['org_id' => $org->id, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true, 'ordering' => 0]);
    EntryType::create(['org_id' => $org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles', 'ordering' => 1]);
    // `siteFor()` asks for the default panel, which the registered one must be marked as.
    PanelTenancy::enter($site)->default();

    try {
        [$chosen, $type] = (new ReflectionMethod(BenchmarkAdminCommand::class, 'fixture'))->invoke(app(BenchmarkAdminCommand::class), $user);

        // The control: the media type really is the first the user may view.
        expect(EntryType::visibleFor($site)->first()?->handle)->toBe('image')
            ->and($chosen?->getKey())->toBe($site->getKey())
            ->and($type?->handle)->toBe('article');
    } finally {
        app(Context::class)->forget();
    }
});
