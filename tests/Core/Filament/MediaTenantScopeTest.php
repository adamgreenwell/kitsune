<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Filament\Facades\Filament;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;
use Kitsune\Core\Filament\Resources\Entries\Pages\ListEntries;
use Kitsune\Core\Filament\Schemas\FieldValueRenderer;
use Kitsune\Core\Filament\Widgets\EntryCountsWidget;
use Kitsune\Core\Filament\Widgets\RecentEntriesWidget;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tenancy\Scopes\OrgMembershipScope;
use Kitsune\Core\Tenancy\Scopes\OrgScope;
use Kitsune\Core\Tenancy\Scopes\SiteScope;
use Kitsune\Core\Tests\Fixtures\PanelTenancy;
use Kitsune\Core\Tests\Fixtures\TestUser;

/*
 * The admin's site boundary, widened for shared media and nothing else — ADR-042 decision 2.
 *
 * ⚠️ UNDER FILAMENT'S OWN SCOPE, which no core test registered before `PanelTenancy`. Without it every assertion here
 * would measure `SiteScope` alone and pass whatever `EntryResource::scopeEloquentQueryToTenant()` did.
 *
 * ⚠️ THREE ANSWERS, COMPARED ROW BY ROW. `SiteScope` alone (the tenancy scope removed), the tenant rule alone
 * (`withoutScopeBecause()` removes Kitsune's scopes and leaves Filament's), and the two together, as the panel runs.
 * The tenant rule must never admit a row `SiteScope` refuses, and the rows where they differ are named, not skipped:
 * a shared entry of a type that is not media, and a shared file whose type is switched off at this site.
 */

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake(MediaDisks::PRIVATE);

    $this->org = Org::create(['slug' => 'golf', 'name' => 'Golf']);
    $this->rivalOrg = Org::create(['slug' => 'rival', 'name' => 'Rival']);

    $context = app(Context::class);
    $context->setOrg($this->org);
    $this->here = Site::create(['handle' => 'here', 'slug' => 'here', 'name' => 'Here', 'locale' => 'en']);
    $this->sibling = Site::create(['handle' => 'sibling', 'slug' => 'sibling', 'name' => 'Sibling', 'locale' => 'en']);
    $this->bare = Site::create(['handle' => 'bare', 'slug' => 'bare', 'name' => 'Bare', 'locale' => 'en']);

    $this->image = EntryType::create(['org_id' => null, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);
    $this->scan = EntryType::create(['org_id' => $this->org->id, 'handle' => 'scan', 'name' => 'Scan', 'plural_name' => 'Scans', 'is_media' => true]);
    $this->article = EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);

    // `scan` is off here (ADR-022); every media type is off at `bare`.
    EntryTypeAvailability::create(['entry_type_id' => $this->scan->id, 'scope_type' => 'site', 'scope_id' => $this->here->id, 'is_enabled' => false]);

    foreach ([$this->image, $this->scan] as $type) {
        EntryTypeAvailability::create(['entry_type_id' => $type->id, 'scope_type' => 'site', 'scope_id' => $this->bare->id, 'is_enabled' => false]);
    }

    $context->setSite($this->here);
    $this->mediaHere = Entry::create(['entry_type_id' => $this->image->id, 'title' => 'Media here', 'slug' => 'media-here']);
    $this->articleHere = Entry::create(['entry_type_id' => $this->article->id, 'title' => 'Article here', 'slug' => 'article-here']);
    $this->sharedMedia = Entry::create(['entry_type_id' => $this->image->id, 'site_id' => null, 'title' => 'Shared media']);
    $this->sharedArticle = Entry::create(['entry_type_id' => $this->article->id, 'site_id' => null, 'title' => 'Shared article']);
    $this->sharedOff = Entry::create(['entry_type_id' => $this->scan->id, 'site_id' => null, 'title' => 'Shared scan, off here']);
    $this->trashedShared = Entry::create(['entry_type_id' => $this->image->id, 'site_id' => null, 'title' => 'Trashed shared']);
    $this->trashedShared->delete();

    $context->setSite($this->sibling);
    $this->mediaSibling = Entry::create(['entry_type_id' => $this->image->id, 'title' => 'Media on the sibling', 'slug' => 'media-sibling']);

    $context->setOrg($this->rivalOrg);
    $this->rivalShared = Entry::create(['entry_type_id' => $this->image->id, 'site_id' => null, 'title' => 'Rival shared']);

    $context->forget();

    $this->panel = PanelTenancy::enter($this->here);
});

afterEach(function (): void {
    app(Context::class)->forget();
});

/** @return list<string> */
function titlesOf(iterable $entries): array
{
    $titles = [];

    foreach ($entries as $entry) {
        $titles[] = (string) $entry->title;
    }

    sort($titles);

    return $titles;
}

function siteScopeAlone(): Builder
{
    return Entry::query()->withoutGlobalScope(Filament::getCurrentPanel()->getTenancyScopeName());
}

/**
 * Whether the tenant rule's SQL carries the shared arm — `site_id IS NULL` — with Kitsune's own scopes taken off, since
 * `SiteScope` carries one of its own. By the column, because the soft-delete scope's `deleted_at IS NULL` is there too,
 * and allowing for each engine's quoting.
 */
function admitsSharedInSql(Builder $query): bool
{
    $sql = strtolower($query->withoutGlobalScopes([SiteScope::class, OrgScope::class, OrgMembershipScope::class])->toSql());

    return preg_match('/site_id["`\]]? is null/', $sql) === 1;
}

function tenantRuleAlone(callable $shape): array
{
    return titlesOf(Entry::withoutScopeBecause('comparing the tenant rule with SiteScope', fn ($query) => $shape($query)->get()));
}

it('admits exactly SiteScope\'s rows of the media types enabled here, and this site\'s own of every type', function (): void {
    $site = titlesOf(siteScopeAlone()->get());
    $tenant = tenantRuleAlone(fn ($query) => $query->whereIn('org_id', [$this->org->id, $this->rivalOrg->id]));
    $panel = titlesOf(Entry::query()->get());

    expect($site)->toBe(['Article here', 'Media here', 'Shared article', 'Shared media', 'Shared scan, off here'])
        ->and($tenant)->toBe(['Article here', 'Media here', 'Shared media'])
        ->and($panel)->toBe($tenant)
        /* Never wider: every row the tenant rule admits, SiteScope admits too. */
        ->and(array_diff($tenant, $site))->toBe([])
        /* And the rows where they differ are exactly the two named ones. */
        ->and(array_values(array_diff($site, $tenant)))->toBe(['Shared article', 'Shared scan, off here']);
});

it('agrees with SiteScope on a trashed shared file, when trashed rows are asked for', function (): void {
    expect(titlesOf(Entry::withTrashed()->where('title', 'Trashed shared')->get()))->toBe(['Trashed shared'])
        ->and(titlesOf(Entry::query()->where('title', 'Trashed shared')->get()))->toBe([]);
});

it('agrees on every shape the panel asks in, not only a plain list', function (): void {
    $byKey = fn (Entry $entry): array => titlesOf(Entry::query()->whereKey($entry->getKey())->get());

    expect($byKey($this->sharedMedia))->toBe(['Shared media'])
        ->and($byKey($this->mediaSibling))->toBe([])
        ->and($byKey($this->rivalShared))->toBe([])
        ->and($byKey($this->sharedArticle))->toBe([])
        ->and($byKey($this->sharedOff))->toBe([])
        ->and(titlesOf(Entry::query()->whereIn('type_handle', ['image'])->get()))->toBe(['Media here', 'Shared media'])
        ->and(Entry::query()->whereIn('type_handle', ['image'])->count())->toBe(2);
});

it('lists a media type\'s shared rows, and keeps any other type\'s list to this site in SQL as well as in rows', function (): void {
    app()->instance(EntryType::class, $this->image);
    $media = EntryResource::getEloquentQuery();

    app()->instance(EntryType::class, $this->article);
    $articles = EntryResource::getEloquentQuery();

    expect(titlesOf($media->get()))->toBe(['Media here', 'Shared media'])
        ->and(titlesOf($articles->get()))->toBe(['Article here'])
        /* The non-media list carries no OR for a shared row it could never hold, so it keeps its ordered read. */
        ->and(admitsSharedInSql($articles))->toBeFalse()
        ->and(admitsSharedInSql($media))->toBeTrue();
});

/**
 * A picker whose targets include no media type reads this site's rows alone — the widened rule could add none, and it
 * cost MySQL and MariaDB their site narrowing. One that may point at a media type keeps the widened rule.
 */
it('narrows a picker with no media target to this site, and keeps the widened rule for one with a media target', function (): void {
    // An owner, because the picker offers only what its user may view and nobody may view anything signed out.
    config(['auth.providers.users.model' => TestUser::class]);
    $owner = TestUser::create(['email' => 'owner@kitsune.test']);
    DB::table('org_user')->insert(['org_id' => $this->org->getKey(), 'user_id' => $owner->getKey()]);
    Role::create(['handle' => 'owner', 'name' => 'Owner', 'is_owner' => true])->assignTo($owner->getKey());
    Auth::login($owner);

    $pickerSql = function (array $targets): string {
        $storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'links_'.bin2hex(random_bytes(3)), 'type' => 'relation',
            'pii_class' => 'none', 'cardinality' => -1, 'settings' => ['targetTypes' => $targets],
        ]);
        $picker = FieldValueRenderer::formComponent(new FieldConfig($storage));

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            if (str_contains(strtolower($query->sql), 'from "entries"') || str_contains(strtolower($query->sql), 'from `entries`')) {
                $statements[] = strtolower($query->sql);
            }
        });

        $found = $picker->getSearchResults('here');

        return end($statements).' '.json_encode(array_values($found));
    };

    $articles = $pickerSql(['article']);
    $images = $pickerSql(['image']);

    /* `SiteScope` and soft deletes carry an `is null` each; only the widened rule adds a third. */
    expect(substr_count($articles, ' is null'))->toBe(2)
        ->and(substr_count($images, ' is null'))->toBe(3)
        ->and($articles)->toContain('Article here')
        ->and($images)->toContain('Media here');
});

/** A media list pages without a total, which would walk every site's files of the type; every other list keeps one. */
it('pages a media type\'s list without a total, and any other type\'s with one', function (): void {
    expect(EntryResource::paginationModeFor($this->image))->toBe(PaginationMode::Simple)
        ->and(EntryResource::paginationModeFor($this->article))->toBe(PaginationMode::Default)
        ->and(EntryResource::paginationModeFor(null))->toBe(PaginationMode::Default);
});

/**
 * And its "select all" means the page in view. Filament counts every selectable row on each render when bulk actions
 * exist, and reads the number off the paginator only when the paginator has one — a simple paginator does not, so the
 * list would run the very count its pagination mode exists to avoid. Asked of the table the list page builds.
 */
it('selects only the page in view on a media list, and every row on any other', function (): void {
    $selectsPageOnly = function (EntryType $type): bool {
        app()->instance(EntryType::class, $type);

        return EntryResource::table(Table::make(app(ListEntries::class)))->selectsCurrentPageOnly();
    };

    expect($selectsPageOnly($this->image))->toBeTrue()
        ->and($selectsPageOnly($this->article))->toBeFalse();
});

/** The media list names `org_id` itself — the prefix of the index that reads its page in order. */
it('gives the media list, and only the media list, its org conjunct', function (): void {
    /* The list's own top-level wheres, before any scope is applied — the scopes carry `org_id` inside their ORs. */
    $conjunct = fn (): array => array_values(array_filter(
        EntryResource::getEloquentQuery()->getQuery()->wheres,
        fn (array $where): bool => ($where['type'] ?? null) === 'Basic' && str_ends_with((string) $where['column'], 'org_id'),
    ));

    app()->instance(EntryType::class, $this->image);
    $media = $conjunct();

    app()->instance(EntryType::class, $this->article);
    $articles = $conjunct();

    expect($media)->toHaveCount(1)
        ->and($media[0]['value'])->toBe($this->org->id)
        ->and($articles)->toBe([]);
});

it('follows the site: a sibling sees the shared file and its own, never this site\'s', function (): void {
    PanelTenancy::moveTo($this->sibling);

    expect(titlesOf(Entry::query()->get()))->toBe(['Media on the sibling', 'Shared media', 'Shared scan, off here']);
});

it('admits no shared row at a site where every media type is off, and asks nothing of one', function (): void {
    PanelTenancy::moveTo($this->bare);

    expect(titlesOf(Entry::query()->get()))->toBe([])
        ->and(admitsSharedInSql(Entry::query()))->toBeFalse()
        /* The control: at a site with media enabled, the same query does carry the shared arm. */
        ->and((function (): bool {
            PanelTenancy::moveTo($this->here);

            return admitsSharedInSql(Entry::query());
        })())->toBeTrue();
});

it('stops admitting a shared file the moment its type is switched off here', function (): void {
    EntryTypeAvailability::create(['entry_type_id' => $this->image->id, 'scope_type' => 'site', 'scope_id' => $this->here->id, 'is_enabled' => false]);
    PanelTenancy::moveTo($this->here);

    expect(titlesOf(Entry::query()->get()))->toBe(['Article here', 'Media here']);
});

it('never admits another org\'s shared file, on a type both orgs share', function (): void {
    expect(Entry::query()->whereKey($this->rivalShared->getKey())->exists())->toBeFalse()
        ->and(Entry::withTrashed()->whereKey($this->rivalShared->getKey())->exists())->toBeFalse();
});

describe('creating in the panel', function (): void {
    it('keeps a shared upload shared, and a site-only one on this site', function (): void {
        app(Context::class)->setSite($this->here);

        $path = tempnam(sys_get_temp_dir(), 'kitsune-scope-');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

        $shared = MediaLibrary::store($path, 'shared.png', $this->image);
        $kept = MediaLibrary::store($path, 'kept.png', $this->image, siteOnly: true);

        @unlink($path);

        expect(DB::table('entries')->where('id', $shared->getKey())->value('site_id'))->toBeNull()
            ->and((int) DB::table('entries')->where('id', $kept->getKey())->value('site_id'))->toBe($this->here->id);
    });

    /** The control: Filament's stamp still applies to everything that is not a media type asking to be shared. */
    it('stamps this site on anything else created with no site', function (): void {
        app(Context::class)->setSite($this->here);

        $entry = Entry::create(['entry_type_id' => $this->article->id, 'site_id' => null, 'title' => 'Tried to share']);

        expect((int) DB::table('entries')->where('id', $entry->getKey())->value('site_id'))->toBe($this->here->id);
    });
});

describe('the dashboard', function (): void {
    /**
     * This site's own, not the org's shared files — decided on the measurements: with the widened rule the grouped count
     * read every file of the type on every site of the org. The media stat says so.
     */
    it('counts this site\'s own files on the dashboard, and not the org\'s shared ones', function (): void {
        $counts = EntryCountsWidget::countsByStatus(collect([$this->image, $this->article]));

        expect(array_sum($counts[$this->image->id] ?? []))->toBe(1)
            ->and(array_sum($counts[$this->article->id] ?? []))->toBe(1);
    });

    /** Recent keeps Filament's unwidened rule, for its ordered read; the shared file is listed on its type's page. */
    it('lists this site\'s own entries as recent, and not the org\'s shared files', function (): void {
        expect(titlesOf(RecentEntriesWidget::recentQuery(collect([$this->image, $this->article]))->get()))
            ->toBe(['Article here', 'Media here']);
    });
});

/*
 * A link is accepted or refused by the same rule — `EntryRelation` asks whether both ends are visible from here, and under
 * the panel "visible" is the widened scope.
 */
describe('linking to a shared file', function (): void {
    beforeEach(function (): void {
        $context = app(Context::class);
        $context->setOrg($this->org);

        $this->pictures = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'pictures', 'type' => 'relation', 'pii_class' => 'none',
            'cardinality' => -1, 'settings' => ['targetTypes' => ['image', 'scan']],
        ]);
        Field::create(['entry_type_id' => $this->article->id, 'field_storage_id' => $this->pictures->id, 'label' => 'Pictures']);
    });

    it('accepts a link to it from a second site of the org', function (): void {
        PanelTenancy::moveTo($this->sibling);
        $source = Entry::create(['entry_type_id' => $this->article->id, 'title' => 'On the sibling', 'slug' => 'on-the-sibling']);

        $source->syncFieldRelations($this->pictures, [$this->sharedMedia->id]);

        expect($source->linkedIdsForField($this->pictures))->toBe([$this->sharedMedia->id]);
    });

    it('refuses a link to one whose type is switched off here', function (): void {
        PanelTenancy::moveTo($this->here);

        expect(fn () => $this->articleHere->syncFieldRelations($this->pictures, [$this->sharedOff->id]))
            ->toThrow(RuntimeException::class, "Entry {$this->sharedOff->id} is not visible here, so it cannot be the target of a relation");

        expect($this->articleHere->linkedIdsForField($this->pictures))->toBe([]);
    });

    it('refuses a link to it from another org\'s site', function (): void {
        $context = app(Context::class);
        $context->setOrg($this->rivalOrg);
        $rivalSite = Site::create(['handle' => 'rival', 'slug' => 'rival-site', 'name' => 'Rival', 'locale' => 'en']);
        $rivalType = EntryType::create(['org_id' => $this->rivalOrg->id, 'handle' => 'story', 'name' => 'Story', 'plural_name' => 'Stories']);
        $rivalPictures = FieldStorage::create([
            'org_id' => $this->rivalOrg->id, 'handle' => 'pictures', 'type' => 'relation', 'pii_class' => 'none',
            'cardinality' => -1, 'settings' => ['targetTypes' => ['image']],
        ]);
        Field::create(['entry_type_id' => $rivalType->id, 'field_storage_id' => $rivalPictures->id, 'label' => 'Pictures']);

        PanelTenancy::moveTo($rivalSite);
        $story = Entry::create(['entry_type_id' => $rivalType->id, 'title' => 'Rival story', 'slug' => 'rival-story']);

        expect(fn () => $story->syncFieldRelations($rivalPictures, [$this->sharedMedia->id]))
            ->toThrow(RuntimeException::class, "Entry {$this->sharedMedia->id} is not visible here, so it cannot be the target of a relation");

        // The control: the rival's own shared file, on the same global type, links.
        $story->syncFieldRelations($rivalPictures, [$this->rivalShared->id]);

        expect($story->linkedIdsForField($rivalPictures))->toBe([$this->rivalShared->id]);
    });
});
