<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Filament\Http\Middleware\Authenticate;
use Filament\Tables\Table;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

/** A tile's script — its frame's `x-data` — as Alpine reads it, with the attribute's escaping undone. */
function tileScript(string $html): string
{
    preg_match('/<div class="kitsune-media-tile"[^>]* x-data="([^"]*)"/', $html, $match);

    return html_entity_decode($match[1] ?? '', ENT_QUOTES | ENT_HTML5);
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
        // A browser reads a URL with no scheme with the page's; the admin page is on APP_URL's scheme (Codex, #159).
        'a protocol-relative disk URL on APP_URL\'s host' => ['https://example.test', '//example.test/storage'],
        'a protocol-relative disk URL, on http' => ['http://example.test', '//example.test/storage'],
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
        'a protocol-relative CDN' => '//cdn.example.test/media',
        'protocol-relative on another port' => '//localhost:8080/storage',
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

    /* The trash lists a trashed entry, and the route resolves live ones alone: its tile fetches nothing (decision 31). */
    it('is a badge saying so for a trashed entry, public or private, with nothing to fetch', function (string $visibility): void {
        $entry = tiled(TILE_PNG, 'old.png', $this->type, $visibility);
        $entry->delete();

        expect(MediaTileColumn::tileFor(Entry::withTrashed()->with('mediaFile')->findOrFail($entry->getKey())))
            ->toBe(['kind' => MediaTileColumn::BADGE, 'src' => null, 'label' => 'In the trash']);
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
     * ⚠️ NOTHING A BROWSER FETCHES UNTIL IT IS PRESSED. The route's path is only in `data-src`, and the one image is inside
     * a `<template>`, which a browser never loads from; `x-bind:src` makes it once the answer's bytes are there.
     */
    it('draws a deferred tile as a button, with the route only where nothing fetches it', function (): void {
        $entry = tiled(TILE_PNG, 'map.png', $this->type);
        $html = tileHtml($entry);
        $route = '/admin/tiles-main/media/'.$entry->getKey();

        expect($html)->toContain('data-kitsune-tile="deferred"')
            ->and($html)->toContain('" data-src="'.$route.'"')
            ->and($html)->toContain('<button type="button"')
            // No plain `src` anywhere: only `data-src`, which nothing fetches, and the template's `x-bind:src`.
            ->and($html)->not->toMatch('/\ssrc="/')
            ->and(substr_count($html, $route))->toBe(1)
            ->and(substr_count($html, '<img'))->toBe(1)
            ->and(strpos($html, '<img'))->toBeGreaterThan((int) strpos($html, '<template x-if="src">'))
            ->and(strpos($html, '<img'))->toBeLessThan((int) strpos($html, '</template>'))
            // The image is drawn inside the button, so the button stays where focus is (decision 39).
            ->and(strpos($html, '<template x-if="src">'))->toBeGreaterThan((int) strpos($html, '<button'))
            ->and(strpos($html, '</template>'))->toBeLessThan((int) strpos($html, '</button>'));
    });

    /*
     * ⚠️ ONE `fetch()`, BEHIND THE BUTTON'S CLICK, AND NOTHING THAT RUNS ON ITS OWN (decision 6). An `init()` or an
     * `x-init` would run on every render, sort and page change; a press while a request is out, or once shown, asks
     * nothing.
     */
    it('asks nothing until pressed: one fetch, behind its button\'s click, and no init', function (): void {
        $html = tileHtml(tiled(TILE_PNG, 'map.png', $this->type));
        $script = tileScript($html);

        expect(substr_count($script, 'fetch('))->toBe(1)
            ->and($script)->not->toContain('init(')
            ->and($html)->not->toContain('x-init')
            ->and($script)->toContain("if (this.state === 'loading' || this.state === 'shown') return;")
            ->and($html)->toContain('x-on:click.prevent.stop="show()"');
    });

    /*
     * ⚠️ AN AJAX REQUEST THAT FOLLOWS NO REDIRECT AND NAMES NO ACCEPT (decision 40). Signed out, that is a 401 and nowhere
     * recorded to land; an image's Accept would be the redirect, and the file recorded — `a private tile's request, signed
     * out`, below, holds the middleware to both.
     */
    it('asks as an Ajax request that follows no redirect and names no Accept', function (): void {
        $script = tileScript(tileHtml(tiled(TILE_PNG, 'map.png', $this->type)));

        expect($script)->toContain("redirect: 'manual'")
            ->and($script)->toContain("headers: { 'X-Requested-With': 'XMLHttpRequest' }")
            ->and(strtolower($script))->not->toContain('accept');
    });

    /*
     * ⚠️ A REASON ONLY FOR THE ROUTE'S OWN REFUSAL, WHICH IS JSON (review, decision 39). A gateway's 403 page, basic
     * auth's 401 or a proxy's 404 would otherwise be said as Kitsune's reason: "You may no longer see this file." to
     * an editor who still holds the grant. The map is read only inside the check.
     */
    it('gives a refusal its reason only when the answer is the route\'s own JSON', function (): void {
        $script = tileScript(tileHtml(tiled(TILE_PNG, 'map.png', $this->type)));
        $check = "if ((response.headers.get('Content-Type') ?? '').startsWith('application/json')) {";
        $map = "state = { 401: 'signedout', 403: 'refused', 404: 'gone' }[response.status] ?? 'failed';";

        expect(substr_count($script, $check))->toBe(1)
            ->and(substr_count($script, $map))->toBe(1)
            ->and(strpos($script, $map))->toBeGreaterThan((int) strpos($script, $check))
            ->and(substr($script, (int) strpos($script, $check), (int) strpos($script, $map) - (int) strpos($script, $check)))
            ->not->toContain('}')
            // Anything else stays what the state starts as: something that went wrong.
            ->and($script)->toContain("let state = 'failed';");
    });

    /* ⚠️ A CARD MOVED LATER IS TORN DOWN AND STARTED AGAIN, ON THE SAME DATA: an answer from before lands on nothing. */
    it('writes an answer onto its tile only while the request is still the tile\'s, and drops it when the tile goes', function (): void {
        $script = tileScript(tileHtml(tiled(TILE_PNG, 'map.png', $this->type)));
        $destroy = substr($script, (int) strpos($script, 'destroy() {'));

        expect($script)->toContain('if (request === this.request) this.src = URL.createObjectURL(blob);')
            ->and($script)->toContain('if (request === this.request) {')
            ->and($script)->toContain('destroy() {')
            ->and($destroy)->toContain('this.request?.abort();')
            ->and($destroy)->toContain('if (this.src) URL.revokeObjectURL(this.src);');
    });

    /* ⚠️ SHOWN ONCE DRAWN: bytes that are not an image fail to draw, and are never said to be shown first. */
    it('says it is shown only once its image has loaded', function (): void {
        $html = tileHtml(tiled(TILE_PNG, 'map.png', $this->type));
        $script = tileScript($html);

        expect(substr_count($script, "this.state = 'shown'"))->toBe(1)
            ->and(strpos($script, "this.state = 'shown'"))->toBeGreaterThan((int) strpos($script, 'drawn(event) {'))
            ->and($html)->toContain('x-on:load="drawn($event)" x-on:error="drawn($event)"')
            ->and($html)->toContain('x-show="state === &#039;shown&#039;"');
    });

    /* ⚠️ NEVER HIDDEN AND NEVER `disabled`, so keyboard focus never falls to the page (decision 39, measured). */
    it('keeps its button whatever it shows, so focus stays on it', function (): void {
        $html = tileHtml(tiled(TILE_PNG, 'map.png', $this->type));
        preg_match('/<button[^>]*>/', $html, $button);

        expect($button[0])->not->toContain('x-show')
            ->and($button[0])->not->toContain('x-if')
            // Neither as an attribute nor bound by Alpine — `x-bind:disabled`, `:hidden` — which would drop focus as well.
            ->and($button[0])->not->toMatch('/[\s:]disabled[\s=>]/')
            ->and($button[0])->not->toMatch('/[\s:]hidden[\s=>]/')
            ->and(substr_count($button[0], 'x-bind:style'))->toBe(1)
            ->and($button[0])->toContain('x-bind:style="{ cursor: state === &#039;shown&#039; ? &#039;default&#039; : &#039;pointer&#039; }"')
            ->and($button[0])->toContain('x-bind:aria-disabled="state === &#039;shown&#039;"')
            ->and($html)->toContain('<span x-show="state !== &#039;shown&#039;">Show preview</span>')
            ->and(strpos($html, '>Show preview</span>'))->toBeLessThan((int) strpos($html, '</button>'));
    });

    /*
     * ⚠️ EVERY STATE THE SCRIPT CAN BE IN HAS ITS WORDS, from the lang file — read back by the line as `dataset[state]`,
     * so a state with no attribute of its name would say nothing at all. The line is beside the button, never in it,
     * and the button names it as its description.
     */
    it('says every answer in a status line beside its button, in the lang file\'s words, which the button names as its description', function (): void {
        $entry = tiled(TILE_PNG, 'map.png', $this->type);
        $html = tileHtml($entry);
        $script = tileScript($html);

        preg_match('/<p id="([^"]+)" role="status"[^>]*>(.*)<\/p>/s', $html, $line);
        preg_match_all("/state = '(\\w+)'|\\d{3}: '(\\w+)'|state: '(\\w+)'/", $script, $states);
        preg_match_all('/ data-(\w+)="([^"]*)"/', $line[2], $words);
        $said = array_values(array_diff(array_unique(array_filter(array_merge(...array_slice($states, 1)))), ['idle']));
        sort($said);
        $named = $words[1];
        sort($named);

        preg_match('/<p id="[^"]+" role="status"[^>]*>/', $html, $open);
        $between = substr($html, (int) strpos($html, '</button>'), (int) strpos($html, '<p id=') - (int) strpos($html, '</button>'));

        expect($line)->not->toBeEmpty()
            ->and(strpos($html, 'role="status"'))->toBeGreaterThan((int) strpos($html, '</button>'))
            // In the page from the start, empty: a live region added with its words is not reliably read.
            ->and($open[0])->not->toMatch('/[\s:](x-show|x-if|x-cloak|hidden)[\s=>]/')
            ->and($between)->not->toContain('<template')
            ->and($html)->toContain('aria-describedby="'.$line[1].'"')
            ->and($line[1])->toBe('kitsune-media-tile-'.$entry->getKey())
            ->and($line[0])->toContain('dir="auto"')
            ->and($line[2])->toContain('x-text=')
            ->and($html)->not->toContain('x-html')
            ->and($said)->toBe(['failed', 'gone', 'loading', 'refused', 'shown', 'signedout'])
            ->and($named)->toBe($said)
            ->and(array_combine($words[1], $words[2]))->toBe(array_combine(
                $words[1],
                array_map(fn (string $state): string => e(__("kitsune::media.tile.status.{$state}")), $words[1]),
            ))
            ->and($line[2])->not->toContain('kitsune::')
            ->and($line[2])->toContain('<template x-if="state === &#039;signedout&#039;"><span> <a href="" class="fi-link"')
            ->and($line[2])->toContain('>Sign in again</a>');

        // The words are the ones the decision names, so a slip in the lang file is caught here too.
        expect(__('kitsune::media.tile.status.refused'))->toBe('You may no longer see this file.')
            ->and(__('kitsune::media.tile.status.gone'))->toBe('This file is no longer available here.')
            ->and(__('kitsune::media.tile.status.signedout'))->toBe('You are signed out.');
    });

    /* ⚠️ NO VALUE OF PHP'S IS WRITTEN INTO THE SCRIPT: a title reaches a tile as escaped text, never as code. */
    it('writes nothing of the entry\'s into a private tile\'s script', function (): void {
        $hostile = tiled(TILE_PNG, 'map.png', $this->type, title: '<b onclick="x()">Map</b> & "key"\'); alert(1); (\'');
        $plain = tiled(TILE_PNG, 'other.png', $this->type, title: 'Plain');

        expect(tileScript(tileHtml($hostile)))->toBe(tileScript(tileHtml($plain)))
            ->and(tileScript(tileHtml($hostile)))->not->toContain('Map')
            ->and(tileScript(tileHtml($hostile)))->not->toContain('/media/'.$hostile->getKey())
            ->and(tileScript(tileHtml($plain)))->not->toContain('Plain')
            ->and(tileScript(tileHtml($plain)))->not->toContain('/media/'.$plain->getKey());
    });

    /*
     * ⚠️ A PRIVATE TILE'S FRAME LETS ITS FOCUS RING OUT, and its button never shrinks below its words, measured at 200 %
     * text; a public tile and a badge keep clipping, as they did.
     */
    it('lets a private tile\'s ring out, and keeps its button and words in the square at any text size', function (): void {
        $private = tileHtml(tiled(TILE_PNG, 'map.png', $this->type));
        $public = tileHtml(tiled(TILE_PNG, 'logo.png', $this->type, 'public'));
        $badge = tileHtml(tiled(TILE_PDF, 'rules.pdf', $this->type));
        $frame = fn (string $html): string => preg_match('/<div class="kitsune-media-tile"[^>]* style="([^"]*)"/', $html, $m) === 1 ? $m[1] : '';
        preg_match('/<button[^>]* style="([^"]*)"/', $private, $button);
        preg_match('/<img x-bind:src="src"[^>]* style="([^"]*)"/', $private, $image);

        expect($frame($private))->not->toContain('overflow:hidden')
            ->and($frame($private))->toContain('aspect-ratio:1/1')
            ->and($button[1])->toContain('flex:1 0 auto')
            ->and($button[1])->toContain('overflow:hidden;border-radius:inherit')
            ->and($image[1])->toStartWith('position:absolute;inset:0;')
            ->and($frame($public))->toContain('overflow:hidden')
            ->and($frame($badge))->toContain('overflow:hidden');
    });

    /*
     * ⚠️ A PUBLIC IMAGE THAT DOES NOT LOAD IS AN AMBER BADGE SAYING SO, caught whether it failed before Alpine started —
     * `x-init` asks the image — or after, and taken back if it loads after all (decisions 39 and 42).
     */
    it('draws a public tile with an amber badge for an image that did not load, before Alpine starts or after, and takes it back if it loads', function (): void {
        $html = tileHtml(tiled(TILE_PNG, 'logo.png', $this->type, 'public'));
        preg_match('/<img[^>]*>/', $html, $image);
        preg_match('/<span class="fi-badge[^"]*"[^>]*>[^<]*<\/span>/', $html, $badge);

        expect($html)->toContain('x-data="{ failed: false }"')
            ->and($image[0])->toContain('x-init="failed = $el.complete &amp;&amp; $el.naturalWidth === 0"')
            ->and($image[0])->toContain('x-on:error="failed = true"')
            ->and($image[0])->toContain('x-on:load="failed = false"')
            ->and($image[0])->toContain('x-bind:style="{ visibility: failed ? &#039;hidden&#039; : &#039;visible&#039; }"')
            ->and($image[0])->not->toContain('x-show')
            ->and($badge[0])->toContain('x-show="failed" style="display:none;position:absolute">Did not load</span>')
            ->and($badge[0])->toContain('fi-color-warning')
            ->and($html)->not->toContain('x-cloak')
            ->and(substr_count($html, '<img'))->toBe(1);
    });

    /* Decision 28: the title under a tile names it. A refusal is said by the line, a failed public image by its badge. */
    it('keeps every image\'s alt empty', function (): void {
        foreach ([tileHtml(tiled(TILE_PNG, 'logo.png', $this->type, 'public')), tileHtml(tiled(TILE_PNG, 'map.png', $this->type))] as $html) {
            preg_match_all('/<img[^>]*>/', $html, $images);

            expect($images[0])->toHaveCount(1)
                ->and(substr_count($images[0][0], 'alt='))->toBe(1)
                ->and($images[0][0])->toContain(' alt=""');
        }
    });

    /* Its name begins with the words it shows, which is the name a voice user speaks (WCAG 2.5.3). */
    it('names the entry in the button\'s label, after the words it shows, escaped', function (): void {
        $entry = tiled(TILE_PNG, 'map.png', $this->type, title: '<b onclick="x()">Map</b> & "key"');

        expect(tileHtml($entry))->toContain('aria-label="Show preview of &quot;&lt;b onclick=&quot;x()&quot;&gt;Map&lt;/b&gt; &amp; &quot;key&quot;&quot;"')
            ->and(tileHtml($entry))->not->toContain('<b onclick');
    });

    it('draws a badge with no image, no button, no script and nothing to fetch', function (): void {
        $html = tileHtml(tiled(TILE_PDF, 'rules.pdf', $this->type, 'public'));

        expect($html)->toContain('data-kitsune-tile="badge"')
            ->and($html)->toContain('>PDF</span>')
            // Gray is Filament's badge with no colour classes; amber is only for an image that did not load.
            ->and($html)->not->toContain('fi-color-warning')
            ->and($html)->not->toContain('<img')
            ->and($html)->not->toContain('<button')
            ->and($html)->not->toContain('src=')
            // No Alpine attribute at all — `flex-direction` in its style is not one.
            ->and($html)->not->toMatch('/\sx-[a-z]/');
    });
});

/*
 * ────────────────────────────────  A private tile's request, signed out  ────────────────────────────────
 */

/*
 * ⚠️ WHERE A SIGNED-OUT PRESS SENDS THE EDITOR ONCE SIGNED IN AGAIN — decision 40, through Filament's own `Authenticate`
 * on a route shaped like the real one. Main's `<img>` asked as an image asks: redirected to sign in, with the file
 * recorded as where to land, and signed in again the editor was shown the bare image, outside the admin (measured). The
 * tile's request is answered 401, and records nothing. The browser suite asserts what the page sends and receives; the
 * session is this file's to read.
 */
describe('a private tile\'s request, signed out', function (): void {
    beforeEach(function (): void {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        PanelTenancy::enter($this->site)->login();
        Auth::logout();
        Route::middleware(['web', Authenticate::class])->get('/admin/{tenant}/media/{media}', static fn (): string => 'bytes');
        Route::middleware('web')->get('/admin/login', static fn (): string => 'sign in')->name('filament.admin.auth.login');
        app('router')->getRoutes()->refreshNameLookups();
    });

    $image = 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8';

    it('is answered 401 and records nowhere to land, asked as the tile asks', function (): void {
        $response = $this->get('/admin/tiles-main/media/7', ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => '*/*']);

        $response->assertStatus(401);

        expect($response->headers->has('Location'))->toBeFalse()
            ->and(session()->has('url.intended'))->toBeFalse();
    });

    it('is redirected to sign in and records the file, asked as an image asks — the control', function () use ($image): void {
        $response = $this->get('/admin/tiles-main/media/7', ['Accept' => $image]);

        $response->assertRedirect('/admin/login');

        expect(session('url.intended'))->toBe('http://localhost/admin/tiles-main/media/7');
    });

    /* ⚠️ WHY THE SCRIPT NAMES NO ACCEPT: the header alone is not enough, with an image's Accept beside it. */
    it('is redirected as well when an Ajax request names an image\'s Accept — the second control', function () use ($image): void {
        $response = $this->get('/admin/tiles-main/media/7', ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => $image]);

        $response->assertRedirect('/admin/login');

        expect(session('url.intended'))->toBe('http://localhost/admin/tiles-main/media/7');
    });

    it('reads the tile\'s request as expecting JSON, and an image\'s as not', function () use ($image): void {
        $tile = Request::create('/x', 'GET', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => '*/*']);
        $img = Request::create('/x', 'GET', server: ['HTTP_ACCEPT' => $image]);

        expect($tile->expectsJson())->toBeTrue()
            ->and($img->expectsJson())->toBeFalse();
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
     * list is the control: it keeps its table and its columns. Its link, and a trashed row's lack of one, are
     * `EntryTrashTest`'s.
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
