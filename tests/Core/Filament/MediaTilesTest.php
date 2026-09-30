<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Filament\MediaTileColumn;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;
use Kitsune\Core\Filament\Resources\Entries\Pages\ListEntries;
use Kitsune\Core\Media\MediaDelivery;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\PanelTenancy;
use Kitsune\Core\Tests\Fixtures\TestUser;

/*
 * The media list's tiles — ADR-042 decision 6, laid out as the card grid Adam chose (decision 17).
 *
 * ⚠️ WHAT A TILE IS, NOT WHETHER A BROWSER FETCHED IT. Decision 6's promises are about requests — a public tile loads
 * from the host serving the admin, and a private one asks nothing until it is clicked — and only a browser makes them:
 * `e2e/media-tiles.spec.js` counts them. This file owns the decisions a tile is built from: the URL `MediaDelivery`
 * gives the admin, which kind of tile each file is, the HTML each kind is, and the grid the list is laid out as.
 */

const TILE_PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\x0f\0\0\x01\x01\0\x05\x18\xd8N\0\0\0\0IEND\xaeB`\x82";

const TILE_PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

function tiled(string $bytes, string $name, EntryType $type, string $visibility = 'private', ?string $title = null): Entry
{
    $source = tempnam(sys_get_temp_dir(), 'kitsune-tile-');
    file_put_contents($source, $bytes);

    try {
        return MediaLibrary::store($source, $name, $type, $visibility, $title);
    } finally {
        unlink($source);
    }
}

/** The entry again, as the list reads it: with its file loaded, or not. */
function asListed(Entry $entry): Entry
{
    return Entry::query()->with('mediaFile')->findOrFail($entry->getKey());
}

function tileHtml(Entry $entry): string
{
    return MediaTileColumn::make()->record(asListed($entry))->toEmbeddedHtml();
}

beforeEach(function (): void {
    config(['auth.providers.users.model' => TestUser::class, 'app.url' => 'http://localhost']);
    Storage::fake(MediaDisks::PRIVATE);
    Storage::fake('public', ['url' => 'http://localhost/storage']);

    $this->org = Org::create(['slug' => 'tiles', 'name' => 'Tiles']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'tiles-main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);

    $this->type = EntryType::create(['org_id' => $this->org->id, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);
    app()->instance(EntryType::class, $this->type);

    /** @var TestUser $user */
    $user = TestUser::create(['email' => 'tiles@kitsune.test']);
    DB::table('org_user')->insert(['org_id' => $this->org->getKey(), 'user_id' => $user->getKey()]);
    $this->role = $role = Role::create(['handle' => 'uploader', 'name' => 'Uploader']);
    DB::table('role_user')->insert(['role_id' => $role->getKey(), 'user_id' => $user->getKey()]);
    // `store()` creates an entry published, which is the acting user's to do (ADR-033).
    foreach (['view', 'create', 'publish'] as $action) {
        $role->grant(Permissions::forEntryType('image', $action));
    }
    $this->actingAs($user);
});

afterEach(fn () => app(Context::class)->forget());

/**
 * In Kitsune's panel at this site, with the route that authorises first registered as the panel registers it.
 *
 * ⚠️ THE SAME SHAPE AS THE REAL ROUTE — `admin/{tenant:slug}/media/{media}` — because the URL a tile is given is built
 * from it; `PanelTenancy` binds the panel without registering its routes, and the browser suite loads the real one.
 */
function inThePanel(Site $site): void
{
    PanelTenancy::enter($site);

    Route::get('/admin/{tenant:slug}/media/{media}', static fn (): string => '')->name((string) MediaDelivery::routeName());
    app('router')->getRoutes()->refreshNameLookups();
}

/*
 * ────────────────────────────────  The admin's URL for a file  ────────────────────────────────
 */

describe('the URL the admin loads a file from', function (): void {
    beforeEach(fn () => inThePanel($this->site));

    /*
     * ⚠️ AND `urlFor()` STAYS ABSOLUTE, which decision 6 decided in the same breath: it answers for consumers that are not
     * the admin, ADR-041's URL a CDN can cache. Without that half, making the admin's URL a path by changing `urlFor()`
     * itself would pass.
     */
    it('gives a public file its direct URL as a path, where urlFor() stays absolute on APP_URL', function (): void {
        $entry = tiled(TILE_PNG, 'logo.png', $this->type, 'public');
        $file = MediaDelivery::fileFor($entry);

        expect(MediaDelivery::adminUrlForFile($entry, $file))->toBe('/storage/'.$file->path)
            ->and(MediaDelivery::urlForFile($entry, $file))->toBe('http://localhost/storage/'.$file->path)
            ->and(MediaDelivery::urlFor($entry))->toBe('http://localhost/storage/'.$file->path);
    });

    /* An origin is compared as a browser compares one: host and scheme without regard to case, a default port as none. */
    it('reads APP_URL\'s origin as a browser would', function (string $appUrl, string $diskUrl): void {
        config(['app.url' => $appUrl]);
        Storage::fake('public', ['url' => $diskUrl]);
        $entry = tiled(TILE_PNG, 'logo.png', $this->type, 'public');
        $file = MediaDelivery::fileFor($entry);

        expect(MediaDelivery::adminUrlForFile($entry, $file))->toBe('/storage/'.$file->path);
    })->with([
        'the host in another case' => ['https://Example.test', 'https://example.TEST/storage'],
        'the default port written out' => ['https://example.test', 'https://example.test:443/storage'],
        'the default port written out on APP_URL' => ['http://example.test:80', 'http://example.test/storage'],
        'APP_URL with a trailing slash' => ['http://example.test/', 'http://example.test/storage'],
    ]);

    /*
     * ⚠️ A PUBLIC DISK SERVED FROM ANOTHER ORIGIN KEEPS ITS URL — a CDN, an object store, or the same host on another
     * scheme or port. Its path on the admin's host would answer 404, so the URL is the disk's own, whole.
     */
    it('keeps the whole URL of a public disk served from another origin', function (string $diskUrl): void {
        Storage::fake('public', ['url' => $diskUrl]);
        $entry = tiled(TILE_PNG, 'logo.png', $this->type, 'public');
        $file = MediaDelivery::fileFor($entry);

        expect(MediaDelivery::adminUrlForFile($entry, $file))->toBe($diskUrl.'/'.$file->path)
            ->and(MediaDelivery::adminUrlForFile($entry, $file))->toBe(MediaDelivery::urlForFile($entry, $file));
    })->with([
        'a CDN' => 'https://cdn.example.test/media',
        'another port' => 'http://localhost:8080/storage',
        'another scheme' => 'https://localhost/storage',
        'another scheme on the same port' => 'https://localhost:80/storage',
        'a host APP_URL\'s ends in' => 'http://notlocalhost/storage',
    ]);

    it('keeps a disk URL that is already a path', function (): void {
        Storage::fake('public');
        $entry = tiled(TILE_PNG, 'logo.png', $this->type, 'public');
        $file = MediaDelivery::fileFor($entry);

        expect(MediaDelivery::adminUrlForFile($entry, $file))->toBe('/storage/'.$file->path);
    });

    it('gives a private file the route that authorises first, as a path', function (): void {
        $entry = tiled(TILE_PNG, 'map.png', $this->type);
        $file = MediaDelivery::fileFor($entry);

        expect(MediaDelivery::adminUrlForFile($entry, $file))->toBe('/admin/tiles-main/media/'.$entry->getKey())
            ->and(MediaDelivery::urlForFile($entry, $file))->toBe('http://localhost/admin/tiles-main/media/'.$entry->getKey());
    });

    /* ⚠️ THE DISK DECIDES, NOT THE VISIBILITY (decision 5): a public file awaiting publication is on the private disk. */
    it('gives a public file its row places on another disk the route, not the public link', function (): void {
        $entry = tiled(TILE_PNG, 'logo.png', $this->type, 'public');
        DB::table('media_files')->where('entry_id', $entry->getKey())->update(['disk' => MediaDisks::PRIVATE]);
        $file = MediaDelivery::fileFor($entry);

        expect(MediaDelivery::servesDirectly($file))->toBeFalse()
            ->and(MediaDelivery::adminUrlForFile($entry, $file))->toBe('/admin/tiles-main/media/'.$entry->getKey());
    });
});

it('has no admin URL for a private file outside a panel', function (): void {
    $entry = tiled(TILE_PNG, 'map.png', $this->type);

    expect(MediaDelivery::adminUrlForFile($entry, MediaDelivery::fileFor($entry)))->toBeNull();
});

/*
 * ────────────────────────────────  What a tile is  ────────────────────────────────
 */

describe('a tile', function (): void {
    beforeEach(fn () => inThePanel($this->site));

    it('loads a file the web server serves with the page, from its path', function (): void {
        $entry = tiled(TILE_PNG, 'logo.png', $this->type, 'public');

        expect(MediaTileColumn::tileFor(asListed($entry)))->toBe([
            'kind' => MediaTileColumn::DIRECT, 'src' => '/storage/'.MediaDelivery::fileFor($entry)->path, 'label' => 'PNG',
        ]);
    });

    it('defers a file the route serves until it is clicked, public or private', function (string $visibility): void {
        $entry = tiled(TILE_PNG, 'map.png', $this->type, $visibility);

        if ($visibility === 'public') {
            DB::table('media_files')->where('entry_id', $entry->getKey())->update(['disk' => MediaDisks::PRIVATE]);
        }

        expect(MediaTileColumn::tileFor(asListed($entry)))->toBe([
            'kind' => MediaTileColumn::DEFERRED, 'src' => '/admin/tiles-main/media/'.$entry->getKey(), 'label' => 'PNG',
        ]);
    })->with(['private' => 'private', 'public, awaiting publication' => 'public']);

    /* ⚠️ ONLY WHAT DELIVERY SERVES INLINE IS DRAWN — everything else is a badge naming its type, and never fetched. */
    it('is a badge naming its type for a file delivery does not render, public or private', function (string $visibility): void {
        $entry = tiled(TILE_PDF, 'rules.pdf', $this->type, $visibility);

        expect(MediaTileColumn::tileFor(asListed($entry)))->toBe(['kind' => MediaTileColumn::BADGE, 'src' => null, 'label' => 'PDF']);
    })->with(['private' => 'private', 'public' => 'public']);

    it('says so for an entry with no file recorded', function (): void {
        $entry = tiled(TILE_PNG, 'gone.png', $this->type);
        DB::table('media_files')->where('entry_id', $entry->getKey())->delete();

        expect(MediaTileColumn::tileFor(asListed($entry)))
            ->toBe(['kind' => MediaTileColumn::MISSING, 'src' => null, 'label' => 'No file']);
    });
});

it('is a badge saying private for a private file outside a panel, with nothing to fetch', function (): void {
    $entry = tiled(TILE_PNG, 'map.png', $this->type);

    expect(MediaTileColumn::tileFor(asListed($entry)))->toBe(['kind' => MediaTileColumn::BADGE, 'src' => null, 'label' => 'Private']);
});

/*
 * ────────────────────────────────  A tile's HTML  ────────────────────────────────
 */

describe('a tile\'s HTML', function (): void {
    beforeEach(fn () => inThePanel($this->site));

    it('draws a direct tile as an image from its path, loaded lazily', function (): void {
        $entry = tiled(TILE_PNG, 'logo.png', $this->type, 'public');
        $html = tileHtml($entry);

        expect($html)->toContain('data-kitsune-tile="direct"')
            ->and($html)->toContain('<img src="/storage/'.MediaDelivery::fileFor($entry)->path.'" alt="" loading="lazy"')
            ->and($html)->not->toContain('http');
    });

    /*
     * ⚠️ NOTHING A BROWSER FETCHES UNTIL IT IS CLICKED. The route's path is only in `data-src`, and the one image is inside
     * a `<template>`, which a browser never loads from; `x-bind:src` makes it once `shown` is true.
     */
    it('draws a deferred tile as a button, with the route only where nothing fetches it', function (): void {
        $entry = tiled(TILE_PNG, 'map.png', $this->type);
        $html = tileHtml($entry);
        $route = '/admin/tiles-main/media/'.$entry->getKey();

        expect($html)->toContain('data-kitsune-tile="deferred"')
            ->and($html)->toContain('x-data="{ shown: false }" data-src="'.$route.'"')
            ->and($html)->toContain('<button type="button"')
            // No plain `src` anywhere: only `data-src`, which nothing fetches, and the template's `x-bind:src`.
            ->and($html)->not->toMatch('/\ssrc="/')
            ->and(substr_count($html, $route))->toBe(1)
            ->and(substr_count($html, '<img'))->toBe(1)
            ->and(strpos($html, '<img'))->toBeGreaterThan((int) strpos($html, '<template x-if="shown">'))
            ->and(strpos($html, '<img'))->toBeLessThan((int) strpos($html, '</template>'));
    });

    /* Its name begins with the words it shows, which is the name a voice user speaks (WCAG 2.5.3). */
    it('names the entry in the button\'s label, after the words it shows, escaped', function (): void {
        $entry = tiled(TILE_PNG, 'map.png', $this->type, title: '<b onclick="x()">Map</b> & "key"');

        expect(tileHtml($entry))->toContain('aria-label="Show preview of &quot;&lt;b onclick=&quot;x()&quot;&gt;Map&lt;/b&gt; &amp; &quot;key&quot;&quot;"')
            ->and(tileHtml($entry))->not->toContain('<b onclick');
    });

    it('draws a badge with no image, no button and nothing to fetch', function (): void {
        $html = tileHtml(tiled(TILE_PDF, 'rules.pdf', $this->type, 'public'));

        expect($html)->toContain('data-kitsune-tile="badge"')
            ->and($html)->toContain('>PDF</span>')
            ->and($html)->not->toContain('<img')
            ->and($html)->not->toContain('<button')
            ->and($html)->not->toContain('src=');
    });
});

/*
 * ────────────────────────────────  The list  ────────────────────────────────
 */

describe('the media list', function (): void {
    beforeEach(fn () => inThePanel($this->site));

    $table = fn (): Table => EntryResource::table(Table::make(app(ListEntries::class)));

    /*
     * ⚠️ NO RECORD LINK, because the whole card would be one `<a>` and a private tile's button would sit inside it; and
     * NO TYPE, STATUS OR FIELD COLUMNS, because a card draws every column not hidden — toggled off or not. The article
     * list is the control: it keeps its table, its link and its columns.
     */
    it('is a grid of tiles, titles and when each changed, with no record link, where an article list stays a table', function () use ($table): void {
        $entry = tiled(TILE_PNG, 'logo.png', $this->type, 'public');
        $media = $table();

        expect($media->getContentGrid())->toBe(['md' => 2, 'lg' => 3, 'xl' => 4])
            ->and(array_keys($media->getColumns()))->toBe(['media_tile', 'title', 'updated_at'])
            ->and($media->getColumn('media_tile'))->toBeInstanceOf(MediaTileColumn::class)
            // Set, to nothing: Filament gives a list page the record's URL only when the table has none of its own.
            ->and($media->hasCustomRecordUrl())->toBeTrue()
            ->and($media->getRecordUrl($entry))->toBeNull();

        app()->instance(EntryType::class, EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']));
        $articles = $table();

        expect($articles->getContentGrid())->toBeNull()
            ->and($articles->hasCustomRecordUrl())->toBeFalse()
            ->and($articles->getColumn('media_tile'))->toBeNull()
            ->and(array_keys($articles->getColumns()))->toContain('title', 'type_handle', 'status', 'updated_at');
    });

    /*
     * ⚠️ ONE QUERY FOR THE PAGE'S FILES, HOWEVER MANY TILES — the ADR asks for the list's query count to be constant.
     * The list's query as the grid modifies it, and every tile drawn from what it loaded, for two files and for six.
     */
    it('draws a page of tiles in the same number of queries whatever its size', function () use ($table): void {
        $queries = function (int $files) use ($table): int {
            // A list of its own for each size, so neither is measured with the other's rows in it.
            $type = EntryType::create(['org_id' => $this->org->id, 'handle' => "image{$files}", 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);
            app()->instance(EntryType::class, $type);

            foreach (['view', 'create', 'publish'] as $action) {
                $this->role->grant(Permissions::forEntryType($type->handle, $action));
            }

            foreach (range(1, $files) as $i) {
                tiled(TILE_PNG, "tile-{$i}.png", $type, $i % 2 === 0 ? 'public' : 'private');
            }

            $count = 0;
            DB::listen(function () use (&$count): void {
                $count++;
            });

            $records = $table()->applyQueryScopes(EntryResource::getEloquentQuery())->get();

            foreach ($records as $record) {
                MediaTileColumn::make()->record($record)->toEmbeddedHtml();
            }

            expect($records)->toHaveCount($files)
                ->and($records->every(fn (Entry $record): bool => $record->relationLoaded('mediaFile')))->toBeTrue();

            return $count;
        };

        expect($queries(6))->toBe($queries(2));
    });
});
