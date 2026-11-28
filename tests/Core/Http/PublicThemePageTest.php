<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Kitsune\Core\Http\Middleware\ResolveSiteFromRequest;
use Kitsune\Core\Http\Middleware\SetSiteLocale;
use Kitsune\Core\Media\MediaDelivery;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Media\MediaVisibility;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRelation;
use Kitsune\Core\Models\EntryTranslationGroup;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Models\SiteEntryRedirect;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture;
use Kitsune\Core\Themes\PublicEntryRedirects;
use Kitsune\Core\Themes\PublicThemePage;
use Kitsune\Core\Themes\SiteMenus;
use Kitsune\Core\Themes\ThemeRegistry;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/kitsune-themes-'.bin2hex(random_bytes(8));
    foreach (['journal', 'stone', 'broken'] as $theme) {
        File::ensureDirectoryExists($this->directory.'/'.$theme.'/entries');
        File::put($this->directory.'/'.$theme.'/theme.json', '{"entry_types":["article","brief"]}');
    }

    $layout = '<main lang="{{ $locale }}" dir="{{ $direction }}">{{ $site->name }}';
    File::put($this->directory.'/journal/home.blade.php', $layout.' journal</main>');
    File::put($this->directory.'/stone/home.blade.php', $layout.' stone</main>');
    File::put($this->directory.'/journal/entries/article.blade.php', $layout.' article: {{ $entry->title }}</main>');
    File::put($this->directory.'/journal/entry.blade.php', $layout.' default: {{ $entry->title }}</main>');

    app()->instance(ThemeRegistry::class, new ThemeRegistry($this->directory));
    Route::get('/host-owned', fn () => 'host page');
    Route::fallback(function (Request $request, Context $context, PublicThemePage $themes) {
        abort_if($context->site() === null, 404);

        return $themes->render($request, $context->site(), 'site.home') ?? response('Welcome placeholder');
    })->middleware([ResolveSiteFromRequest::class, SetSiteLocale::class])->name('site.home');

    $this->context = app(Context::class);
    $this->org = Org::create(['name' => 'News', 'slug' => 'news']);
    $this->context->setOrg($this->org);
    $this->en = Site::create([
        'handle' => 'en', 'slug' => 'news-en', 'name' => 'English', 'locale' => 'en', 'base_url' => '/news', 'theme' => 'journal',
    ]);
    $this->ar = Site::create([
        'handle' => 'ar', 'slug' => 'news-ar', 'name' => 'Arabic', 'locale' => 'ar', 'base_url' => '/news/ar', 'theme' => 'stone',
    ]);

    $this->otherOrg = Org::create(['name' => 'Magazine', 'slug' => 'magazine']);
    $this->context->setOrg($this->otherOrg);
    $this->other = Site::create([
        'handle' => 'en', 'slug' => 'magazine-en', 'name' => 'Magazine', 'locale' => 'en', 'base_url' => '/magazine', 'theme' => 'journal',
    ]);
    $this->context->forget();
});

afterEach(function (): void {
    $this->context->forget();
    File::deleteDirectory($this->directory);
});

it('renders each reader’s theme response afresh and prevents retention on home and entry pages', function (): void {
    EntitlementFixture::declareReaders();
    $first = EntitlementFixture::reader($this->org, 'first-theme@example.test');
    $second = EntitlementFixture::reader($this->org, 'second-theme@example.test');
    $third = EntitlementFixture::reader($this->otherOrg, 'third-theme@example.test');
    $view = '<main>{{ $site->name }} · {{ $locale }} · {{ app(\Kitsune\Core\Auth\ReaderGuard::class)->current() ?? "guest" }}</main>';
    File::put($this->directory.'/journal/home.blade.php', $view);
    File::put($this->directory.'/stone/home.blade.php', $view);
    File::put($this->directory.'/journal/entries/article.blade.php', $view);

    $this->context->setSite($this->en);
    $article = EntryType::create(['handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);
    Entry::create(['entry_type_id' => $article->id, 'title' => 'Story', 'slug' => 'story', 'status' => 'published']);
    $this->context->forget();

    $read = function (string $path, string $expectation): void {
        $this->get($path)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeaderMissing('ETag')
            ->assertSee('<main>'.$expectation.'</main>', false);
    };

    $read('/news', 'English · en · guest');
    EntitlementFixture::signIn($first);
    $read('/news', 'English · en · '.$first->getKey());
    $read('/news/article/story', 'English · en · '.$first->getKey());
    $read('/magazine', 'Magazine · en · guest');

    EntitlementFixture::signIn($second);
    $read('/news', 'English · en · '.$second->getKey());
    $read('/news/article/story', 'English · en · '.$second->getKey());
    $read('/news/ar', 'Arabic · ar · '.$second->getKey());

    EntitlementFixture::signIn($third);
    $read('/magazine', 'Magazine · en · '.$third->getKey());
    $read('/news', 'English · en · guest');
});

it('renders ordered local menu groups and pins through the selected theme', function (): void {
    $view = <<<'BLADE'
        <main lang="{{ $locale }}" dir="{{ $direction }}">
            @foreach ($menu('main') as $link)
                <a href="{{ $link['path'] }}">{{ $link['label'] }}</a>
                @foreach ($link['children'] as $child)
                    <a href="{{ $child['path'] }}">{{ $child['label'] }}</a>
                @endforeach
            @endforeach
        </main>
        BLADE;
    foreach (['journal', 'stone'] as $theme) {
        File::put($this->directory.'/'.$theme.'/home.blade.php', $view);
        File::put($this->directory.'/'.$theme.'/entry.blade.php', $view);
    }

    $menus = app(SiteMenus::class);
    $group = '115d5120-ba2f-4bb0-8888-9262df5870ce';
    $this->context->setSite($this->en);
    $type = EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);
    $english = Entry::create([
        'entry_type_id' => $type->id, 'title' => 'Story', 'slug' => 'story', 'status' => 'published', 'translation_group' => $group,
    ]);
    $menus->save($this->en, 'main', 'Main', [
        ['target' => 'home', 'label' => 'Start'],
        ['target' => 'entry:'.$english->id, 'label' => 'English story'],
    ], null);

    $this->context->setSite($this->ar);
    $arabic = Entry::create([
        'entry_type_id' => $type->id, 'title' => 'Arabic story', 'slug' => 'arabic-story', 'status' => 'published', 'translation_group' => $group,
    ]);
    $arabicItems = [
        ['target' => 'home', 'label' => 'Arabic start', 'children' => [['target' => 'group:'.$group, 'label' => 'Arabic story']]],
    ];
    $menus->save($this->ar, 'main', 'Arabic navigation', $arabicItems, null);
    $this->context->forget();
    $this->get('/news')->assertOk()->assertSeeInOrder(['Start', 'English story'])->assertDontSee('Arabic story');
    $this->get('/news/ar')->assertOk()->assertSee('dir="rtl"', false)->assertSeeInOrder(['Arabic start', 'Arabic story'])
        ->assertSee('href="/news/ar/article/arabic-story"', false)->assertDontSee('English story');
    $this->get('/magazine')->assertOk()->assertDontSee('English story')->assertDontSee('Arabic story');

    $this->context->setSite($this->ar);
    $arabic->update(['status' => 'draft']);
    $this->get('/news/ar')->assertOk()->assertSee('Arabic start')->assertDontSee('Arabic story');
    $this->context->setSite($this->ar);
    $arabic->forceDelete();
    expect($menus->save($this->ar, 'main', 'Updated navigation', $arabicItems, 1)->version)->toBe(2);
});

it('rejects a stale menu edit and accepts only local targets', function (): void {
    $menus = app(SiteMenus::class);
    $this->context->setSite($this->en);
    $items = [['target' => 'home', 'label' => 'Original']];
    expect($menus->save($this->en, 'main', 'Main', $items, null)->version)->toBe(1);
    $current = $menus->save($this->en, 'main', 'Revised', $items, 1);
    expect($current->version)->toBe(2);
    expect(fn () => $menus->save($this->en, 'main', 'Stale', $items, 1))->toThrow(ValidationException::class)
        ->and(fn () => $menus->save($this->en, 'main', 'Missing target', [['target' => 'entry:999999', 'label' => 'Missing']], 2))
        ->toThrow(ValidationException::class)
        ->and(fn () => $menus->remove($this->en, $current, 1))->toThrow(ValidationException::class);
    $this->context->setSite($this->ar);
    expect(fn () => $menus->save($this->en, 'main', 'Other Site', $items, 2))
        ->toThrow(NotFoundHttpException::class);
});

it('discovers usable themes and applies independent Site choices across two organisations', function (): void {
    expect(array_column(app(ThemeRegistry::class)->all(), 'handle'))->toBe(['journal', 'stone']);
    $this->get('/news')->assertOk()->assertSee('<main lang="en" dir="ltr">English journal', false);
    $this->get('/news/ar')->assertOk()->assertSee('<main lang="ar" dir="rtl">Arabic stone', false);
    $this->get('/magazine')->assertOk()->assertSee('<main lang="en" dir="ltr">Magazine journal', false);
    $this->get('/unspecified')->assertNotFound();
    $this->get('/host-owned')->assertOk()->assertSee('host page');
});

it('renders a published exact-Site entry via the type template or its theme default', function (): void {
    $this->context->setSite($this->en);
    foreach (['article', 'brief', 'private-note'] as $handle) {
        $type = EntryType::create(['org_id' => $this->org->id, 'handle' => $handle, 'name' => $handle, 'plural_name' => $handle]);
        Entry::create(['entry_type_id' => $type->id, 'title' => $handle.' public title', 'slug' => 'lead', 'status' => 'published']);
        if ($handle === 'article') {
            Entry::create(['entry_type_id' => $type->id, 'title' => 'Draft', 'slug' => 'draft', 'status' => 'draft']);
        }
    }

    $this->context->forget();
    $this->get('/news/article/lead')->assertOk()->assertSee('article: article public title');
    $this->get('/news/article/LEAD')->assertNotFound();
    $this->get('/NEWS/article/lead')->assertOk()->assertSee('article: article public title');
    $this->get('/NEWS/AR')->assertOk()->assertSee('Arabic stone');
    $this->get('/news/brief/lead')->assertOk()->assertSee('default: brief public title');
    $this->get('/news/private-note/lead')->assertNotFound();
    $this->get('/news/article/draft')->assertNotFound();
    $this->get('/magazine/article/lead')->assertNotFound();
    $this->get('/news/article/lead/extra')->assertNotFound();
});

it('selects and clears a theme through the scoped operator command', function (): void {
    $this->artisan('kitsune:theme', ['action' => 'select', 'theme' => 'stone', '--org' => 'magazine', '--site' => 'en'])
        ->assertSuccessful();
    $this->get('/magazine')->assertOk()->assertSee('Magazine stone');
    $this->get('/news')->assertOk()->assertSee('English journal');

    $this->artisan('kitsune:theme', ['action' => 'select', 'theme' => 'broken', '--org' => 'news', '--site' => 'en'])
        ->assertFailed();
    $this->get('/news')->assertOk()->assertSee('English journal');

    $this->artisan('kitsune:theme', ['action' => 'clear', '--org' => 'news', '--site' => 'en'])
        ->assertSuccessful();
    $this->get('/news')->assertOk()->assertSee('Welcome placeholder');
    $this->get('/news/ar')->assertOk()->assertSee('Arabic stone');
});

it('renders the eligible local member and removes it when unpublished without falling back to a sibling', function (): void {
    File::put($this->directory.'/journal/entries/article.blade.php', '<h1>{{ $entry->title }}</h1>@foreach ($related["links"] as $link)<p>{{ $link->title }}</p>@endforeach');

    $this->context->setSite($this->en);
    $type = EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);
    $storage = FieldStorage::create([
        'org_id' => $this->org->id, 'handle' => 'links', 'type' => 'relation', 'pii_class' => 'none', 'cardinality' => -1,
    ]);
    $type->fields()->create(['field_storage_id' => $storage->id, 'label' => 'Links']);
    $uuid = '115d5120-ba2f-4bb0-8888-9262df5870ce';
    $english = Entry::create([
        'entry_type_id' => $type->id, 'translation_group' => $uuid, 'title' => 'English version', 'slug' => 'related', 'status' => 'published',
    ]);
    $group = EntryTranslationGroup::query()->sole();

    $this->context->setSite($this->ar);
    $this->ar->update(['theme' => 'journal']);
    $source = Entry::create(['entry_type_id' => $type->id, 'title' => 'Source story', 'slug' => 'source', 'status' => 'published']);
    $arabic = Entry::create([
        'entry_type_id' => $type->id, 'translation_group' => $uuid, 'title' => 'Arabic version', 'slug' => 'related', 'status' => 'published',
    ]);
    $pin = Entry::create(['entry_type_id' => $type->id, 'title' => 'Exact pin', 'slug' => 'pin', 'status' => 'published']);
    $source->syncFieldRelations($storage, [$pin->id]);
    EntryRelation::create([
        'org_id' => $this->org->id, 'source_entry_id' => $source->id, 'field_storage_id' => $storage->id,
        'target_group_id' => $group->id, 'ordering' => 1,
    ]);

    $this->context->forget();
    $this->get('/news/ar/article/source')->assertOk()->assertSeeInOrder(['Source story', 'Exact pin', 'Arabic version'])
        ->assertDontSee($english->title);

    $this->context->setSite($this->ar);
    $arabic->update(['status' => 'draft']);
    $this->context->forget();

    $this->get('/news/ar/article/source')->assertOk()->assertSee('Exact pin')
        ->assertDontSee($arabic->title)->assertDontSee($english->title);
});

it('exposes entry links only when the public fallback and the current Site own their destinations', function (): void {
    File::delete($this->directory.'/journal/entry.blade.php');
    File::put($this->directory.'/journal/entries/article.blade.php', '<h1>{{ $entryPath }}</h1>'
        .'@foreach ($related["links"] as $link)<a href="{{ $relatedPaths[$link->id] }}">{{ $link->title }}</a>@endforeach');
    Route::get('/news/article/hosted', fn () => 'Host page');

    $this->context->setSite($this->en);
    $article = EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);
    $brief = EntryType::create(['org_id' => $this->org->id, 'handle' => 'brief', 'name' => 'Brief', 'plural_name' => 'Briefs']);
    $entry = fn (EntryType $type, string $slug, string $title): Entry => Entry::create([
        'entry_type_id' => $type->id, 'title' => $title, 'slug' => $slug, 'status' => 'published',
    ]);
    $source = $entry($article, 'source', 'Source');
    $open = $entry($article, 'open', 'Open link');
    $hosted = $entry($article, 'hosted', 'Hidden by host route');
    $occupied = $entry($article, 'occupied', 'Hidden by nested Site');
    $noTemplate = $entry($brief, 'brief', 'No template');
    $storage = FieldStorage::create([
        'org_id' => $this->org->id, 'handle' => 'links', 'type' => 'relation', 'pii_class' => 'none', 'cardinality' => -1,
    ]);
    $article->fields()->create(['field_storage_id' => $storage->id, 'label' => 'Links']);
    $source->syncFieldRelations($storage, [$hosted->id, $open->id, $occupied->id, $noTemplate->id]);
    Site::create([
        'handle' => 'nested', 'slug' => 'news-nested', 'name' => 'Nested news',
        'locale' => 'en', 'base_url' => '/news/article/occupied', 'theme' => 'journal',
    ]);
    $this->context->forget();

    $this->get('/news/article/source')->assertOk()
        ->assertSee('/news/article/source')
        ->assertSee('<a href="/news/article/open">Open link</a>', false)
        ->assertDontSee('Hidden by host route')
        ->assertDontSee('Hidden by nested Site')
        ->assertDontSee('No template');
    $this->get('/news/article/hosted')->assertOk()->assertSee('Host page');
    $this->get('/news/article/occupied')->assertOk()->assertSee('Nested news');
    $this->get('/news/brief/brief')->assertNotFound();
});

it('keeps private media out of public content routes and rendered relations', function (): void {
    File::put($this->directory.'/journal/theme.json', '{"entry_types":["article","private_asset"]}');
    File::put($this->directory.'/journal/entries/article.blade.php',
        '<h1>{{ $entry->title }}</h1>{{ $richText("body") }}@foreach ($related["links"] as $link)<p>{{ $link->title }}</p>@endforeach');
    Storage::fake(MediaDisks::PRIVATE);
    Storage::fake('public');
    $this->context->setSite($this->en);
    $type = EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);
    $assetType = EntryType::create([
        'org_id' => $this->org->id, 'handle' => 'private_asset', 'name' => 'Asset', 'plural_name' => 'Assets', 'is_media' => true,
    ]);
    $storage = FieldStorage::create([
        'org_id' => $this->org->id, 'handle' => 'links', 'type' => 'relation', 'pii_class' => 'none', 'cardinality' => -1,
    ]);
    $type->fields()->create(['field_storage_id' => $storage->id, 'label' => 'Links']);
    $body = FieldStorage::create([
        'org_id' => $this->org->id, 'handle' => 'body', 'type' => 'rich_text', 'pii_class' => 'none', 'cardinality' => 1,
    ]);
    $type->fields()->create(['field_storage_id' => $body->id, 'label' => 'Body']);
    $source = Entry::create(['entry_type_id' => $type->id, 'title' => 'Published story', 'slug' => 'story', 'status' => 'published']);

    $file = $this->directory.'/fixture.png';
    File::put($file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jXWQAAAAASUVORK5CYII=', true));
    $media = MediaLibrary::store($file, 'fixture.png', $assetType, 'private', 'Private asset title', siteOnly: true);
    $source->syncFieldRelations($storage, [$media->id]);
    $source->update(['values' => ['body' => '<p>Look <img data-kitsune-media="'.$media->id.'" alt="Detail"> here.</p>']]);

    $this->context->forget();
    $this->get('/news/private_asset/'.$media->slug)->assertNotFound();
    $this->get('/news/article/story')->assertOk()->assertSee('Published story')->assertDontSee('Private asset title')
        ->assertSee('Look Detail here.')->assertDontSee('<img', false);

    $this->context->setSite($this->en);
    MediaVisibility::makePublic($media);
    $url = MediaDelivery::urlFor($media->fresh());
    expect($url)->not->toBeNull();
    $this->context->forget();
    $this->get('/news/article/story')->assertOk()->assertSee('src="'.e($url).'"', false);

    $this->context->setSite($this->en);
    $source->syncFieldRelations($storage, []);
    $this->context->forget();
    $this->get('/news/article/story')->assertOk()->assertSee('Look Detail here.')->assertDontSee('<img', false);
});

it('redirects multiple former addresses to the current entry in one hop', function (): void {
    $request = Request::create('https://localhost/news');
    $redirects = app(PublicEntryRedirects::class);
    $this->context->setSite($this->en);
    $type = EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);
    $entry = Entry::create(['entry_type_id' => $type->id, 'title' => 'Renamed entry', 'slug' => 'original', 'status' => 'published']);

    DB::transaction(function () use ($entry, $redirects, $request): void {
        $entry->update(['slug' => 'intermediate']);
        $redirects->record($request, $this->en, $entry, 'article', 'original', 'site.home');
    });
    DB::transaction(function () use ($entry, $redirects, $request): void {
        $entry->update(['slug' => 'current']);
        $redirects->record($request, $this->en, $entry, 'article', 'intermediate', 'site.home');
    });
    expect($redirects->record($request, $this->en, $entry, 'article', 'intermediate', 'site.home'))
        ->toBeInstanceOf(SiteEntryRedirect::class);
    expect(SiteEntryRedirect::query()->count())->toBe(2);
    $this->context->forget();

    foreach (['original', 'intermediate'] as $former) {
        $response = $this->get('/news/article/'.$former.'?preview=1')->assertStatus(302)
            ->assertHeader('Location', '/news/article/current');
        expect($response->headers->get('Cache-Control'))->toContain('no-store');
    }
    $this->get('/news/article/current')->assertOk()->assertSee('Renamed entry');
    $this->get('/NEWS/article/original')->assertStatus(302)->assertHeader('Location', '/news/article/current');
    $this->get('/news/ar/article/original')->assertNotFound();
    $this->get('/magazine/article/original')->assertNotFound();
});

it('keeps routes and reclaimed addresses ahead of redirects and stops routing an unpublished entry', function (): void {
    Route::get('/news/article/reserved', fn () => 'Host owns this address');
    $request = Request::create('https://localhost/news');
    $redirects = app(PublicEntryRedirects::class);
    $this->context->setSite($this->en);
    $type = EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);
    $original = Entry::create(['entry_type_id' => $type->id, 'title' => 'Original', 'slug' => 'latest', 'status' => 'published']);
    $redirects->record($request, $this->en, $original, 'article', 'former', 'site.home');

    expect(fn () => $redirects->record($request, $this->en, $original, 'article', 'reserved', 'site.home'))
        ->toThrow(ValidationException::class)
        ->and(fn () => $redirects->record($request, $this->en, $original, 'article', 'latest', 'site.home'))
        ->toThrow(ValidationException::class);

    $replacement = Entry::create(['entry_type_id' => $type->id, 'title' => 'Replacement', 'slug' => 'former', 'status' => 'published']);
    $this->context->forget();
    $this->get('/news/article/reserved')->assertOk()->assertSee('Host owns this address');
    $this->get('/news/article/former')->assertOk()->assertSee('Replacement')->assertDontSee('Original');

    $this->context->setSite($this->en);
    $replacement->update(['status' => 'draft']);
    $this->get('/news/article/former')->assertStatus(302)->assertHeader('Location', '/news/article/latest');
    $this->context->setSite($this->en);
    $replacement->forceDelete();
    $original->update(['status' => 'draft']);
    $this->get('/news/article/former')->assertNotFound();
});

it('keeps a reclaimed address with its latest published owner after that entry is renamed', function (): void {
    $request = Request::create('https://localhost/admin/news-en');
    $redirects = app(PublicEntryRedirects::class);
    $this->context->setSite($this->en);
    $type = EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);
    $earlier = Entry::create(['entry_type_id' => $type->id, 'title' => 'Earlier', 'slug' => 'earlier', 'status' => 'published']);
    $redirects->record($request, $this->en, $earlier, 'article', 'reused', 'site.home');
    $reclaim = Entry::create(['entry_type_id' => $type->id, 'title' => 'Reclaimed entry', 'slug' => 'reused', 'status' => 'published']);

    $redirects->preserveOnSave($request, $this->en, $reclaim, function (Entry $locked): Entry {
        $locked->update(['slug' => 'replacement']);

        return $locked;
    }, 'site.home');

    expect($reclaim->fresh()->slug)->toBe('replacement')
        ->and(SiteEntryRedirect::query()->sole()->target_entry_id)->toBe($reclaim->id);
    expect(fn () => $redirects->record($request, $this->en, $earlier, 'article', 'reused', 'site.home'))
        ->toThrow(ValidationException::class);
    $this->get('/news/article/reused')->assertStatus(302)->assertHeader('Location', '/news/article/replacement');
});

it('refuses foreign redirect targets and keeps an address local when its target moves', function (): void {
    $request = Request::create('https://localhost/news');
    $redirects = app(PublicEntryRedirects::class);
    $this->context->setSite($this->other);
    $rivalType = EntryType::create(['org_id' => $this->otherOrg->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);
    $rival = Entry::create(['entry_type_id' => $rivalType->id, 'title' => 'Rival', 'slug' => 'rival', 'status' => 'published']);
    $this->context->setSite($this->en);
    $type = EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);
    $target = Entry::create(['entry_type_id' => $type->id, 'title' => 'Local', 'slug' => 'local', 'status' => 'published']);

    expect(fn () => $redirects->record($request, $this->en, $rival, 'article', 'foreign', 'site.home'))
        ->toThrow(ValidationException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('site_entry_redirects')->insert([
            'site_id' => $this->en->id, 'org_id' => $this->org->id, 'target_entry_id' => $rival->id,
            'source_type' => 'article', 'source_slug' => 'foreign', 'source_key' => SiteEntryRedirect::sourceKey('article', 'foreign'),
        ])))->toThrow(QueryException::class);
    $redirects->record($request, $this->en, $target, 'article', 'local-old', 'site.home');
    DB::table('entries')->where('id', $target->id)->update(['site_id' => $this->ar->id]);
    $this->get('/news/article/local-old')->assertNotFound();
    $this->get('/news/ar/article/local-old')->assertNotFound();

    DB::table('entries')->where('id', $target->id)->delete();
    expect(DB::table('site_entry_redirects')->count())->toBe(0);
});
