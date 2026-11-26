<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Http\Middleware\ResolveSiteFromRequest;
use Kitsune\Core\Http\Middleware\SetSiteLocale;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRelation;
use Kitsune\Core\Models\EntryTranslationGroup;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Themes\PublicThemePage;
use Kitsune\Core\Themes\ThemeRegistry;

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

        return $themes->render($request, $context->site()) ?? response('Welcome placeholder');
    })->middleware([ResolveSiteFromRequest::class, SetSiteLocale::class]);

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

it('keeps private media out of public content routes and rendered relations', function (): void {
    File::put($this->directory.'/journal/theme.json', '{"entry_types":["article","private_asset"]}');
    File::put($this->directory.'/journal/entries/article.blade.php',
        '<h1>{{ $entry->title }}</h1>@foreach ($related["links"] as $link)<p>{{ $link->title }}</p>@endforeach');
    Storage::fake(MediaDisks::PRIVATE);
    $this->context->setSite($this->en);
    $type = EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);
    $assetType = EntryType::create([
        'org_id' => $this->org->id, 'handle' => 'private_asset', 'name' => 'Asset', 'plural_name' => 'Assets', 'is_media' => true,
    ]);
    $storage = FieldStorage::create([
        'org_id' => $this->org->id, 'handle' => 'links', 'type' => 'relation', 'pii_class' => 'none', 'cardinality' => -1,
    ]);
    $type->fields()->create(['field_storage_id' => $storage->id, 'label' => 'Links']);
    $source = Entry::create(['entry_type_id' => $type->id, 'title' => 'Published story', 'slug' => 'story', 'status' => 'published']);

    $file = $this->directory.'/fixture.txt';
    File::put($file, "Private media fixture bytes.\n");
    $media = MediaLibrary::store($file, 'fixture.txt', $assetType, 'private', 'Private asset title', siteOnly: true);
    $source->syncFieldRelations($storage, [$media->id]);

    $this->context->forget();
    $this->get('/news/private_asset/'.$media->slug)->assertNotFound();
    $this->get('/news/article/story')->assertOk()->assertSee('Published story')->assertDontSee('Private asset title');
});
