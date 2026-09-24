<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\MediaFile;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/*
 * The repair path for two best-effort gaps this system has on purpose — ADR-041.
 *
 * `MediaLibrary` writes bytes before rows, so a crash between them leaves a file nothing references.
 * `MediaDisposal` removes bytes after rows and reports rather than throws, so a disk that refuses leaves the
 * same residue. Both were chosen over the alternative — an entry pointing at a file that does not exist — and
 * both are recoverable only because the table can say what it knows about.
 */

beforeEach(function (): void {
    Storage::fake(MediaDisks::PRIVATE);
    Storage::fake('public');

    $this->org = Org::create(['slug' => 'acme', 'name' => 'Acme']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);

    $this->imageType = EntryType::create([
        'org_id' => $this->org->getKey(), 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true,
    ]);
});

afterEach(function (): void {
    app(Context::class)->forget();

    foreach (glob(sys_get_temp_dir().'/kitsune-prune-*') ?: [] as $leftover) {
        @unlink($leftover);
    }
});

function storedForPrune(EntryType $type): MediaFile
{
    $path = tempnam(sys_get_temp_dir(), 'kitsune-prune-');
    file_put_contents($path, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    ));

    $entry = MediaLibrary::store($path, 'kept.png', $type);

    return MediaFile::query()->where('entry_id', $entry->getKey())->firstOrFail();
}

it('reports nothing when every file is claimed by a row', function (): void {
    storedForPrune($this->imageType);

    $this->artisan('kitsune:media-prune')
        ->expectsOutputToContain('No orphaned media files')
        ->assertSuccessful();
});

/** ⚠️ READ-ONLY WITHOUT `--force`: media has no revision history and no undo, so a default that deleted would be wrong. */
it('lists an orphan without removing it', function (): void {
    Storage::disk(MediaDisks::PRIVATE)->put('media/'.$this->org->getKey().'/2026/09/orphan.png', 'bytes');

    $this->artisan('kitsune:media-prune')
        ->expectsOutputToContain('orphan.png')
        ->expectsOutputToContain('nothing removed')
        ->assertSuccessful();

    Storage::disk(MediaDisks::PRIVATE)->assertExists('media/'.$this->org->getKey().'/2026/09/orphan.png');
});

it('removes an orphan when forced', function (): void {
    $orphan = 'media/'.$this->org->getKey().'/2026/09/orphan.png';
    Storage::disk(MediaDisks::PRIVATE)->put($orphan, 'bytes');

    $this->artisan('kitsune:media-prune --force')
        ->expectsOutputToContain('Removed 1 of 1')
        ->assertSuccessful();

    Storage::disk(MediaDisks::PRIVATE)->assertMissing($orphan);
});

/**
 * ⚠️ IT ASKS THE DATABASE, NEVER THE FILENAME. A claimed file is one a `media_files` row names — not one that
 * looks recent, or sits where the command expects. Anything cleverer is a heuristic, and a heuristic that
 * deletes is a bug waiting for an operator whose layout differs.
 */
it('never removes a file a row claims, even alongside orphans', function (): void {
    $kept = storedForPrune($this->imageType);
    $orphan = 'media/'.$this->org->getKey().'/2026/09/orphan.png';
    Storage::disk($kept->disk)->put($orphan, 'bytes');

    $this->artisan('kitsune:media-prune --force')->assertSuccessful();

    Storage::disk($kept->disk)->assertExists($kept->path);
    Storage::disk($kept->disk)->assertMissing($orphan);
});

/** The residue disposal deliberately leaves behind is exactly what this finds. */
it('finds the orphan a refused disposal leaves', function (): void {
    $media = storedForPrune($this->imageType);
    $path = $media->path;
    $disk = $media->disk;

    /* The row goes without the bytes — the state a disk that refused deletion leaves. */
    MediaFile::query()->whereKey($media->getKey())->delete();

    $this->artisan('kitsune:media-prune --force')->assertSuccessful();

    Storage::disk($disk)->assertMissing($path);
});

it('looks at both the public and the private disk', function (): void {
    Storage::disk('public')->put('media/1/2026/09/pub.png', 'bytes');
    Storage::disk(MediaDisks::PRIVATE)->put('media/1/2026/09/priv.png', 'bytes');

    $this->artisan('kitsune:media-prune')
        ->expectsOutputToContain('pub.png')
        ->expectsOutputToContain('priv.png')
        ->assertSuccessful();
});

/**
 * ⚠️ A ROW STILL NAMING `local` KEEPS `local` IN THE SWEEP. ADR-042 moved private media to core's own disk and left
 * those rows valid, so their orphans — a refused disposal's residue — are still there to be found.
 */
it('looks at a disk a row still names, though no longer configured', function (): void {
    Storage::fake('local');

    $media = storedForPrune($this->imageType);
    DB::table('media_files')->where('id', $media->getKey())->update(['disk' => 'local']);
    Storage::disk('local')->put($media->path, 'bytes');
    Storage::disk('local')->put('media/1/2026/09/left-behind.png', 'bytes');

    $this->artisan('kitsune:media-prune')
        ->expectsOutputToContain('left-behind.png')
        ->assertSuccessful();

    Storage::disk('local')->assertExists($media->path);
});

/** The control: a disk nothing names and nothing is configured to use is the host's, and is left alone. */
it('leaves alone a disk no row names', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('media/host-owned.png', 'bytes');

    $this->artisan('kitsune:media-prune --force')
        ->doesntExpectOutputToContain('host-owned.png')
        ->assertSuccessful();

    Storage::disk('local')->assertExists('media/host-owned.png');
});
