<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\MediaFile;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\RefusingDisk;

/*
 * Byte disposal — ADR-041: a soft-deleted media entry keeps its bytes because a restore must work, and a
 * force-deleted one loses them.
 *
 * ⚠️ ASKED OF THE BUILDER, NOT OF A MODEL EVENT, and the bulk case below is why. `media_files.entry_id`
 * cascades, so the row goes inside the database where no PHP runs; and a hook on `Entry` would miss
 * `Entry::query()->forceDelete()`, which dispatches nothing. That shape has been found eight times in this
 * codebase, so it is tested from both paths rather than the one that feels like the real one.
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

    foreach (glob(sys_get_temp_dir().'/kitsune-disp-*') ?: [] as $leftover) {
        @unlink($leftover);
    }
});

function aStoredImage(EntryType $type, string $name = 'photo.png'): Entry
{
    $path = tempnam(sys_get_temp_dir(), 'kitsune-disp-');
    file_put_contents($path, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    ));

    return MediaLibrary::store($path, $name, $type);
}

/** ⚠️ A restore must work, so the bytes stay while the entry is only trashed. */
it('keeps the bytes when a media entry is soft-deleted', function (): void {
    $entry = aStoredImage($this->imageType);
    $media = MediaFile::query()->where('entry_id', $entry->getKey())->firstOrFail();

    $entry->delete();

    expect($entry->fresh()?->trashed() ?? Entry::withTrashed()->whereKey($entry->getKey())->exists())->toBeTrue();

    Storage::disk($media->disk)->assertExists($media->path);
});

it('restores a soft-deleted media entry with its bytes intact', function (): void {
    $entry = aStoredImage($this->imageType);
    $media = MediaFile::query()->where('entry_id', $entry->getKey())->firstOrFail();

    $entry->delete();
    Entry::withTrashed()->whereKey($entry->getKey())->restore();

    Storage::disk($media->disk)->assertExists($media->path);
    expect(MediaFile::query()->where('entry_id', $entry->getKey())->exists())->toBeTrue();
});

it('removes the bytes when a media entry is force-deleted through the instance', function (): void {
    $entry = aStoredImage($this->imageType);
    $media = MediaFile::query()->where('entry_id', $entry->getKey())->firstOrFail();

    Storage::disk($media->disk)->assertExists($media->path);

    $entry->forceDelete();

    Storage::disk($media->disk)->assertMissing($media->path);
    expect(DB::table('media_files')->count())->toBe(0);
});

/**
 * ⚠️ THE PATH A MODEL EVENT WOULD MISS. `Entry::query()->forceDelete()` dispatches nothing, so a `deleting`
 * hook never runs — which is why disposal is asked of the builder both paths arrive at.
 */
it('removes the bytes when entries are force-deleted in bulk', function (): void {
    $first = aStoredImage($this->imageType, 'one.png');
    $second = aStoredImage($this->imageType, 'two.png');

    $files = MediaFile::query()->get(['disk', 'path']);

    expect($files)->toHaveCount(2);

    Entry::query()->whereIn('id', [$first->getKey(), $second->getKey()])->forceDelete();

    foreach ($files as $file) {
        Storage::disk($file->disk)->assertMissing($file->path);
    }

    expect(DB::table('media_files')->count())->toBe(0);
});

/** An entry with no media at all must not be disturbed by a path that looks for some. */
it('force-deletes an ordinary entry with no media without complaint', function (): void {
    $type = EntryType::create([
        'org_id' => $this->org->getKey(), 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles',
    ]);

    $entry = Entry::create(['entry_type_id' => $type->getKey(), 'title' => 'Plain', 'slug' => 'plain']);

    $entry->forceDelete();

    expect(DB::table('entries')->count())->toBe(0);
});

/**
 * ⚠️ A DISK THAT REFUSES MUST NOT KEEP A FORCE-DELETE FROM COMPLETING. The operator asked for the row to go
 * and the row is the record; an unreachable object store or a read-only mount leaves an orphan, which
 * `kitsune:media-prune` is the repair for. What it must not do is pass silently, so it is logged.
 *
 * On a disk that refuses the delete itself: one that cannot be built at all now refuses the erasure before it
 * commits, because withdrawal cannot tell where the file is (ADR-042 decision 5).
 */
it('completes the force-delete even when the bytes cannot be removed', function (): void {
    $root = sys_get_temp_dir().'/kitsune-disp-refusing-'.bin2hex(random_bytes(4));
    mkdir($root);
    $private = RefusingDisk::install(MediaDisks::PRIVATE, $root);

    try {
        $entry = aStoredImage($this->imageType);
        $media = MediaFile::query()->where('entry_id', $entry->getKey())->firstOrFail();
        $private->failDeletes = true;
        Log::spy();

        $entry->forceDelete();

        expect(DB::table('entries')->count())->toBe(0)
            ->and(DB::table('media_files')->count())->toBe(0)
            ->and(is_file($root.'/'.$media->path))->toBeTrue();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, $media->path))->atLeast()->once();
    } finally {
        exec('rm -rf '.escapeshellarg($root));
    }
});

/** Only the entries being deleted lose their bytes — a sibling's file is not collateral. */
it('leaves another entry\'s bytes alone', function (): void {
    $doomed = aStoredImage($this->imageType, 'doomed.png');
    $keeper = aStoredImage($this->imageType, 'keeper.png');

    $keeperFile = MediaFile::query()->where('entry_id', $keeper->getKey())->firstOrFail();

    $doomed->forceDelete();

    Storage::disk($keeperFile->disk)->assertExists($keeperFile->path);
    expect(MediaFile::query()->count())->toBe(1);
});

/** ⚠️ AND A ROW STILL NAMING `local` IS DISPOSED OF THERE, for the reason delivery serves it there. */
it('removes the bytes of a row that still names local from local', function (): void {
    Storage::fake('local');

    $entry = aStoredImage($this->imageType);
    $media = MediaFile::query()->where('entry_id', $entry->getKey())->firstOrFail();

    Storage::disk('local')->put($media->path, Storage::disk(MediaDisks::PRIVATE)->get($media->path));
    Storage::disk(MediaDisks::PRIVATE)->delete($media->path);
    DB::table('media_files')->where('id', $media->getKey())->update(['disk' => 'local']);

    $entry->forceDelete();

    Storage::disk('local')->assertMissing($media->path);
    expect(DB::table('media_files')->count())->toBe(0);
});
