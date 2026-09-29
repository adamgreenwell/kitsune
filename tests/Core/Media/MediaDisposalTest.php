<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaDisposal;
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

/*
 * A served read-through disk is asked only whether it holds the file: one that holds nothing is passed, with nothing said
 * — every erasure logged it as a copy still on the web — and one whose half holds the file keeps it, and says so, since
 * custody removes no copy through such a disk (review of slice 5c).
 */
it('asks a served read-through disk only whether it holds the file', function (bool $holds): void {
    $roots = [sys_get_temp_dir().'/kitsune-disp-rtp-'.bin2hex(random_bytes(4)), sys_get_temp_dir().'/kitsune-disp-rtf-'.bin2hex(random_bytes(4))];

    foreach ($roots as $root) {
        mkdir($root, 0777, true);
    }

    try {
        config([
            'filesystems.disks.rtp' => ['driver' => 'local', 'root' => $roots[0]],
            'filesystems.disks.rtf' => ['driver' => 'local', 'root' => $roots[1]],
            'filesystems.disks.rt' => ['driver' => 'read-through', 'primary' => 'rtp', 'fallback' => 'rtf', 'url' => 'https://rt.example.test'],
        ]);
        $path = 'media/1/2026/09/gone.png';

        if ($holds) {
            Storage::disk('rtp')->put($path, 'a copy');
        }

        Log::spy();

        expect(MediaDisposal::remove(DB::connection(), [['entry_id' => 999999, 'disk' => MediaDisks::PRIVATE, 'path' => $path]]))->toBe($holds ? 0 : 1)
            ->and(is_file($roots[0].'/'.$path))->toBe($holds);

        $holds
            ? Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, '[rt:'.$path.']') && str_contains($message, 'it is on a read-through disk'))->once()
            : Log::shouldNotHaveReceived('warning');
    } finally {
        foreach ($roots as $root) {
            exec('rm -rf '.escapeshellarg($root));
        }
    }
})->with(['one that holds nothing' => false, 'one whose half holds the file' => true]);

/*
 * An erasure whose row names a read-through disk whose halves name each other never builds it: disposal ran after the
 * commit and built it, and Laravel's builder recursed until memory ran out — a fatal error no catch can answer, the
 * request dead and nothing said. The rest is disposed of, and a warning names the disk, sending no one to prune, which
 * refuses it too (review of slice 5c).
 */
it('erases an entry whose row names a read-through cycle without building it, and says so', function (): void {
    config([
        'filesystems.disks.loop-a' => ['driver' => 'read-through', 'primary' => 'loop-b', 'fallback' => 'public'],
        'filesystems.disks.loop-b' => ['driver' => 'read-through', 'primary' => 'loop-a', 'fallback' => 'public'],
    ]);
    $entry = aStoredImage($this->imageType);
    $media = MediaFile::query()->where('entry_id', $entry->getKey())->firstOrFail();
    DB::table('media_files')->where('id', $media->getKey())->update(['disk' => 'loop-a', 'visibility' => 'private']);
    Log::spy();
    $limit = ini_get('memory_limit');
    // A missing guard fails the test, not by growing into a laptop's unlimited memory.
    ini_set('memory_limit', (string) (memory_get_usage(true) + 128 * 1024 * 1024));

    try {
        $entry->forceDelete();
    } finally {
        ini_set('memory_limit', $limit);
    }

    expect(DB::table('entries')->count())->toBe(0)
        ->and(DB::table('media_files')->count())->toBe(0);
    Storage::disk(MediaDisks::PRIVATE)->assertMissing($media->path);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'did not ask [loop-a] for ['.$media->path.']')
        && str_contains($message, 'its read-through disks name each other')
        && ! str_contains($message, 'kitsune:media-prune removes'))->once();
});

/*
 * ...and one the web does not serve, which the erased row named: its row is gone, so the warning points at no copy to
 * compare it with, and at no prune, which refuses every orphan on a read-through disk — it says to remove the file by
 * hand through the half that holds it, as prune then does (review of slice 5c).
 */
it('says an erased row\'s copy on a read-through disk is removed by hand, not by prune', function (): void {
    $roots = [sys_get_temp_dir().'/kitsune-disp-rtp-'.bin2hex(random_bytes(4)), sys_get_temp_dir().'/kitsune-disp-rtf-'.bin2hex(random_bytes(4))];

    foreach ($roots as $root) {
        mkdir($root, 0777, true);
    }

    try {
        config([
            'filesystems.disks.rtp' => ['driver' => 'local', 'root' => $roots[0]],
            'filesystems.disks.rtf' => ['driver' => 'local', 'root' => $roots[1]],
            'filesystems.disks.rt' => ['driver' => 'read-through', 'primary' => 'rtp', 'fallback' => 'rtf'],
        ]);
        $erased = aStoredImage($this->imageType, 'erased.png');
        $kept = aStoredImage($this->imageType, 'kept.png');
        $media = MediaFile::query()->where('entry_id', $erased->getKey())->firstOrFail();
        Storage::disk('rtp')->put($media->path, Storage::disk(MediaDisks::PRIVATE)->get($media->path));
        Storage::disk(MediaDisks::PRIVATE)->delete($media->path);
        // A second row names the disk, so prune still sweeps it.
        DB::table('media_files')->whereIn('entry_id', [$erased->getKey(), $kept->getKey()])->update(['disk' => 'rt', 'visibility' => 'private']);
        Log::spy();

        $erased->forceDelete();

        expect(DB::table('entries')->where('id', $erased->getKey())->exists())->toBeFalse();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, '[rt:'.$media->path.']')
            && str_contains($message, 'no row keeps it')
            && str_contains($message, 'refuses it too')
            && ! str_contains($message, 'kitsune:media-prune` removes it')
            && ! str_contains($message, 'where the row belongs'))->once();

        $pruned = Artisan::call('kitsune:media-prune', ['--force' => true]);

        expect(Artisan::output())->toContain('Could not remove [rt:'.$media->path.']')
            ->and($pruned)->toBe(1)
            ->and(is_file($roots[0].'/'.$media->path))->toBeTrue();
    } finally {
        foreach ($roots as $root) {
            exec('rm -rf '.escapeshellarg($root));
        }
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

/*
 * T113. A disposal that could not run says where the bytes are, not where the row pointed: the erasure took every
 * served copy off the web, so the public disk is not said to hold one; prune sweeps the configured disks and core's
 * private disk whatever names them; and a disk that is none of those only while a row names it (review of slice 5b).
 */
it('says where the bytes are when a disposal could not run', function (string $disk, string $says, bool $byHand, bool $committed = true): void {
    Log::spy();
    $armed = true;
    DB::connection()->beforeExecuting(function (string $query) use (&$armed): void {
        if ($armed && str_contains($query, 'media_files')) {
            $armed = false;

            throw new RuntimeException('the lock wait timed out');
        }
    });

    MediaDisposal::remove(DB::connection(), [['entry_id' => 999999, 'disk' => $disk, 'path' => 'media/1/2026/09/gone.png']], $committed);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'disposal could not run — the lock wait timed out')
            && str_contains($message, $says)
            && str_contains($message, 'remove it by hand') === $byHand
            && ! str_contains($message, 'does not serve'))
        ->once();
})->with([
    'core\'s private disk' => [MediaDisks::PRIVATE, 'removes what is left on the configured media disks and core\'s private disk;', false],
    'the public disk' => ['public', 'a copy on any other disk the web serves stays until it is removed by hand', false],
    'a disk that is none of those' => ['local', 'and on [local] while any row names it', true],
    // From the force-delete's failure path it may not have committed: nothing is claimed about where the bytes went.
    'a force-delete that reported failure' => ['public', 'may not have committed: if the entry is still there, kitsune:media-reconcile --entry=999999', false, false],
]);
