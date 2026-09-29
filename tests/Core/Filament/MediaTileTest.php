<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Filament\Tables\Table;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;
use Kitsune\Core\Filament\Resources\Entries\Pages\ListEntries;
use Kitsune\Core\Filament\Tables\MediaTileColumn;
use Kitsune\Core\Http\Controllers\MediaDownloadController;
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
 * The media list's tiles — ADR-042 decision 6, with Adam's answers of 2026-09-29.
 *
 * ⚠️ THE TABLE'S OWN QUERY AND THE COLUMN'S OWN MARKUP, read as the list builds them: the columns and query scopes of
 * `EntryResource::table()`, over the rows `getEloquentQuery()` returns inside the panel's tenancy. The browser suite
 * (`e2e/media-tiles.spec.js`) shows what this cannot — that a public tile loads from the admin's own host, and that the
 * list asks the route for nothing until a tile is clicked.
 *
 * ⚠️ THE PUBLIC DISK'S URL IS MADE ABSOLUTE ON `APP_URL`, as a real install's is. The suite's own is relative already, so
 * "a path with no host" would hold with no helper at all.
 */

const MEDIA_TILE_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

/** A file stored through the library, as an upload stores one. */
function aTile(EntryType $type, string $title, string $visibility, string $bytes = '', string $name = 'photo.png'): Entry
{
    $source = tempnam(sys_get_temp_dir(), 'kitsune-tile-');
    file_put_contents($source, $bytes === '' ? base64_decode(MEDIA_TILE_PNG) : $bytes);

    try {
        return MediaLibrary::store($source, $name, $type, $visibility, $title, true);
    } finally {
        unlink($source);
    }
}

/**
 * The media list's rows as the table reads them, and each row's tile as the column renders it.
 *
 * @return array<string, array{html: string, clickDisabled: bool}>
 */
function tilesByTitle(bool $isResolvingRecord = false): array
{
    $table = EntryResource::table(Table::make(app(ListEntries::class)));
    $column = $table->getColumn('media_tile');
    $records = $table->applyQueryScopes(EntryResource::getEloquentQuery(), $isResolvingRecord)->limit(25)->get();

    $tiles = [];

    foreach ($records as $record) {
        $column->record($record);
        $tiles[(string) $record->title] = ['html' => $column->toEmbeddedHtml(), 'clickDisabled' => $column->isClickDisabled()];
    }

    return $tiles;
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
    $role = Role::create(['handle' => 'tiler', 'name' => 'Tiler']);
    DB::table('role_user')->insert(['role_id' => $role->getKey(), 'user_id' => $user->getKey()]);

    foreach (['view', 'create', 'publish'] as $action) {
        $role->grant(Permissions::forEntryType('image', $action));
    }

    $this->actingAs($user);

    // The panel's tenancy, and its media route by the name the panel gives it: without both a private file has no URL.
    PanelTenancy::enter($this->site);
    Route::get('/admin/{tenant}/media/{media}', MediaDownloadController::class)->where('media', '[0-9]+')->name('filament.admin.media.download');
    app('router')->getRoutes()->refreshNameLookups();
});

afterEach(fn () => app(Context::class)->forget());

it('shows a public image from the admin\'s own host, and loads it with the page', function (): void {
    $entry = aTile($this->type, 'Public logo', 'public');
    $path = DB::table('media_files')->where('entry_id', $entry->getKey())->value('path');

    $tile = tilesByTitle()['Public logo'];

    expect($tile['html'])->toContain('<img src="/storage/'.$path.'"')
        ->and($tile['html'])->toContain('width="1" height="1"')
        // Its type in its place if it will not load — noticed when it fails, and when it failed before anybody listened.
        ->and($tile['html'])->toContain('x-on:error="failed = true"')
        ->and($tile['html'])->toContain('x-init="if ($el.complete &amp;&amp; $el.naturalWidth === 0) failed = true"')
        ->and($tile['html'])->toContain('<span x-show="failed" x-cloak>')
        ->and($tile['html'])->toContain(__('kitsune::media.tile.unavailable'))
        // No URL names a host — `APP_URL`'s least of all. (The fallback icon's SVG names its namespace, which is not one.)
        ->and($tile['html'])->not->toContain('localhost')
        ->and(preg_match('/src="(https?:)?\/\//', $tile['html']))->toBe(0)
        ->and($tile['html'])->not->toContain('/admin/')
        // No tile is a link — an image with no words of its own would be one with no name; the row's title is the link.
        ->and($tile['clickDisabled'])->toBeTrue();
});

it('shows a private image as a placeholder that fetches through the route only when clicked', function (): void {
    $entry = aTile($this->type, 'Private photo', 'private');

    $tile = tilesByTitle()['Private photo'];

    expect($tile['html'])->toContain('wire:ignore')
        ->and($tile['html'])->toContain('x-on:click="load()"')
        ->and($tile['html'])->toContain('\/admin\/tiles-main\/media\/'.$entry->getKey())
        ->and($tile['html'])->toContain('X-Requested-With')
        // Nothing starts it but the click: no `x-init`, and an `init()` that only shows what the page already asked for.
        ->and($tile['html'])->not->toContain('x-init')
        ->and(str($tile['html'])->between('init() {', 'load() {')->toString())->not->toContain('this.load(')
        ->and(str($tile['html'])->between('init() {', 'load() {')->toString())->not->toContain('this.ask(')
        // No `src` to load with the page: only the one Alpine binds once the file has been fetched.
        ->and(preg_match('/(?<![:\w-])src="/', $tile['html']))->toBe(0)
        ->and($tile['html'])->not->toContain('/storage/')
        // Its button is not put inside the row's link.
        ->and($tile['clickDisabled'])->toBeTrue();
});

/* Each row its own file: with a file-less entry first, entry ids and file row ids no longer coincide. */
it('shows each row its own file, whatever the ids', function (): void {
    Entry::create(['entry_type_id' => $this->type->id, 'title' => 'No bytes', 'status' => 'draft']);
    $private = aTile($this->type, 'Private photo', 'private');
    $public = aTile($this->type, 'Public logo', 'public');
    $path = DB::table('media_files')->where('entry_id', $public->getKey())->value('path');

    $tiles = tilesByTitle();

    expect(DB::table('media_files')->where('entry_id', $public->getKey())->value('id'))->not->toBe($public->getKey())
        ->and($tiles['Public logo']['html'])->toContain('<img src="/storage/'.$path.'"')
        ->and($tiles['Private photo']['html'])->toContain('x-on:click="load()"')
        ->and($tiles['Private photo']['html'])->toContain('\/admin\/tiles-main\/media\/'.$private->getKey())
        ->and($tiles['Private photo']['html'])->not->toContain('/storage/')
        ->and($tiles['No bytes']['html'])->toBe('<div class="fi-ta-image"></div>');
});

/* A private file is never served directly, whatever disk its row names. */
it('shows a private image on the public disk as a private one', function (): void {
    $entry = aTile($this->type, 'Stray', 'private');
    DB::table('media_files')->where('entry_id', $entry->getKey())->update(['disk' => 'public']);

    $tile = tilesByTitle()['Stray'];

    expect($tile['html'])->toContain('x-on:click="load()"')
        ->and($tile['html'])->not->toContain('/storage/')
        ->and(preg_match('/(?<![:\w-])src="/', $tile['html']))->toBe(0);
});

/*
 * A panel with no media route — a host that dropped the tenancy `KitsunePanel::apply()` sets up — has no URL for a
 * private file: its tile shows its type, and the list does not throw.
 */
it('shows a private image as its type when the panel has no media route', function (): void {
    aTile($this->type, 'Private photo', 'private');
    app('router')->setRoutes(new RouteCollection);

    $tile = tilesByTitle()['Private photo'];

    expect($tile['html'])->toContain('No preview: image/png')
        ->and($tile['html'])->not->toContain('x-on:click')
        ->and($tile['html'])->not->toContain('fetch(')
        ->and($tile['html'])->not->toContain('/admin/');
});

/* The disk decides, not the visibility (decision 5): a public file awaiting publication is delivered as private. */
it('shows a public image still on the private disk as a private one', function (): void {
    $entry = aTile($this->type, 'Awaiting publication', 'public');
    DB::table('media_files')->where('entry_id', $entry->getKey())->update(['disk' => MediaDisks::PRIVATE]);

    $tile = tilesByTitle()['Awaiting publication'];

    expect($tile['html'])->toContain('x-on:click="load()"')
        ->and($tile['html'])->not->toContain('/storage/')
        ->and($tile['clickDisabled'])->toBeTrue();
});

/*
 * ⚠️ ONLY THE TYPES THE PAGE MAY RENDER (Adam, decision 18), public or private: everything else is its type, and no
 * request. SVG included — a private one is sent as an attachment, and one list decides both — and a public file this
 * application does not serve (decision 19).
 */
it('shows every other file as its type, and names no URL for it', function (string $case): void {
    [$visibility, $mime, $name, $bytes] = match ($case) {
        'a private text file', 'a public text file' => [str_starts_with($case, 'a private') ? 'private' : 'public', 'text/plain', 'notes.txt', "plain words\n"],
        default => [str_contains($case, 'private') ? 'private' : 'public', 'image/png', 'photo.png', ''],
    };
    $entry = aTile($this->type, 'The file', $visibility, $bytes, $name);

    if (str_contains($case, 'SVG')) {
        // The library stores an SVG only through a bound sanitiser; the row's type is what the tile reads.
        DB::table('media_files')->where('entry_id', $entry->getKey())->update(['mime' => $mime = 'image/svg+xml']);
    } elseif ($case === 'a public image on a CDN') {
        Storage::fake('public', ['url' => 'https://cdn.example.test/storage']);
    }

    $tile = tilesByTitle()['The file'];

    expect($tile['html'])->toContain('No preview: '.$mime)
        ->and($tile['html'])->not->toContain('/storage/')
        ->and($tile['html'])->not->toContain('/admin/')
        ->and($tile['html'])->not->toContain('media\/')
        ->and($tile['html'])->not->toContain('src=')
        ->and($tile['html'])->not->toContain('fetch(')
        ->and($tile['clickDisabled'])->toBeTrue();
})->with([
    'a private text file' => 'a private text file',
    'a public text file' => 'a public text file',
    'a private SVG' => 'a private SVG',
    'a public SVG' => 'a public SVG',
    'a public image on a CDN' => 'a public image on a CDN',
]);

it('escapes a title wherever it appears', function (): void {
    aTile($this->type, '<script>alert("x")</script>&\'', 'private');

    $html = tilesByTitle()['<script>alert("x")</script>&\'']['html'];

    expect($html)->not->toContain('<script>')
        ->and($html)->toContain('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;&amp;&#039;');
});

/*
 * ⚠️ THE SAME MARKUP ON EVERY RENDER. A tile is `wire:ignore`, so one whose row keeps its place is never touched; but a
 * sort, search, upload or page change can make a row anew, and its tile finds the file this page already fetched only
 * by the route URL it was kept under. A URL that changed between renders would show that tile as its placeholder
 * again, and showing it would ask the route again under `no-store`.
 */
it('renders a tile the same way every time', function (): void {
    aTile($this->type, 'Private photo', 'private');
    aTile($this->type, 'Public logo', 'public');

    $first = tilesByTitle();

    // A Livewire update comes seconds or minutes after the page: nothing a tile is kept by may depend on when it rendered.
    $this->travel(5)->minutes();

    expect(tilesByTitle())->toBe($first);
});

/*
 * ⚠️ ONE STATEMENT FOR THE PAGE'S FILES, AND NONE WHILE THE TILES RENDER — decision 6's "constant rather than one per
 * row", as far as the tiles go. Read with the page's keys; never lazily, one row at a time; and not at all when Filament
 * resolves a single record for an action, which shows no tile.
 */
it('reads the page\'s files in one statement, and nothing while the tiles render', function (): void {
    foreach (['One', 'Two', 'Three', 'Four'] as $title) {
        aTile($this->type, $title, $title === 'Two' ? 'public' : 'private');
    }

    aTile($this->type, 'Five', 'private', "plain words\n", 'notes.txt');

    $table = EntryResource::table(Table::make(app(ListEntries::class)));
    $column = $table->getColumn('media_tile');
    $reads = [];
    DB::listen(static function ($query) use (&$reads): void {
        $reads[] = $query->sql;
    });

    $records = $table->applyQueryScopes(EntryResource::getEloquentQuery())->get();
    $whileReading = count(array_filter($reads, static fn (string $sql): bool => str_contains($sql, 'media_files')));
    $reads = [];

    foreach ($records as $record) {
        $column->record($record);
        $column->toEmbeddedHtml();
        $column->isClickDisabled();
    }

    // Nothing at all while they render — a query for any table, not only this one.
    expect($records)->toHaveCount(5)
        ->and($whileReading)->toBe(1)
        ->and($reads)->toBe([]);

    $reads = [];
    $table->applyQueryScopes(EntryResource::getEloquentQuery(), isResolvingRecord: true)->get();

    expect(array_filter($reads, static fn (string $sql): bool => str_contains($sql, 'media_files')))->toBe([]);
});

/* A row whose file was not read shows nothing, rather than asking for it. */
it('asks for no file a row was not given', function (): void {
    $entry = aTile($this->type, 'Private photo', 'private');
    $column = MediaTileColumn::make('media_tile');
    $reads = 0;
    DB::listen(static function ($query) use (&$reads): void {
        $reads += str_contains($query->sql, 'media_files') ? 1 : 0;
    });

    $column->record(Entry::query()->findOrFail($entry->getKey()));

    expect($column->toEmbeddedHtml())->toBe('<div class="fi-ta-image"></div>')
        ->and($reads)->toBe(0);
});

it('offers no tiles, and reads no files, on any other type\'s list', function (): void {
    app()->instance(EntryType::class, EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']));
    Entry::create(['entry_type_id' => app(EntryType::class)->id, 'title' => 'A note', 'status' => 'draft']);

    $table = EntryResource::table(Table::make(app(ListEntries::class)));
    $reads = 0;
    DB::listen(static function ($query) use (&$reads): void {
        $reads += str_contains($query->sql, 'media_files') ? 1 : 0;
    });

    $records = $table->applyQueryScopes(EntryResource::getEloquentQuery())->get();

    expect($table->getColumn('media_tile')->isHidden())->toBeTrue()
        ->and($records)->toHaveCount(1)
        ->and($reads)->toBe(0);
});
