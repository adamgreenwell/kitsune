<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Media\MediaBytes;
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

/*
 * What custody leaves, and what prune does with it — ADR-042 decision 5 (T20, T21).
 *
 * ⚠️ A FILE IS AN ORPHAN WHEN NO ROW NAMES ITS PATH, ON ANY DISK. Custody leaves verified copies at a row's own path on
 * disks the row does not name, and any one of them may be the only good copy: each is listed as kept, never deleted.
 * Asserted by the list each path is printed under, from the command's whole output, so a path listed in the wrong
 * table cannot pass for one listed in the right one.
 */
describe('what custody leaves', function (): void {
    beforeEach(function (): void {
        // A disk that was once the public one: local, with a url, so the web serves it — and no row names it.
        Storage::fake('old-cdn');
        config(['filesystems.disks.old-cdn' => ['driver' => 'local', 'root' => Storage::disk('old-cdn')->path(''), 'url' => 'https://cdn.example.test']]);

        $move = static function (MediaFile $file, string $named, array $holders, bool $trashed = false, string $visibility = 'public'): string {
            $bytes = (string) Storage::disk($file->disk)->get($file->path);
            Storage::disk($file->disk)->delete($file->path);

            foreach ($holders as $disk => $path) {
                Storage::disk($disk)->put($path ?? $file->path, $bytes);
            }

            DB::table('media_files')->where('id', $file->getKey())->update(['disk' => $named, 'visibility' => $visibility]);
            DB::table('entries')->where('id', $file->entry_id)->update(['deleted_at' => $trashed ? now() : null]);

            return $file->path;
        };

        $pub = 'public';
        $priv = MediaDisks::PRIVATE;
        $p = MediaBytes::PARTIAL;

        $this->paths = [
            // 1. A live row naming public, its only copy on the private disk: a withdrawal the database rolled back.
            'only on private' => $move(storedForPrune($this->imageType), $pub, [$priv => null]),
            // 2. A publication's complete public copy, the row still naming private: a commit that did not land.
            'published, uncommitted' => $move(storedForPrune($this->imageType), $priv, [$priv => null, $pub => null]),
            // 3. A restored file's private copy, beside the public one the row names.
            'restored' => $move(storedForPrune($this->imageType), $pub, [$pub => null, $priv => null]),
            // 4. A copy on a served disk the row does not name: a move-off that could not remove it.
            'left on a served disk' => $move(storedForPrune($this->imageType), $pub, [$pub => null, 'old-cdn' => null]),
        ];

        // 5. A partial beside a path a row names: custody was copying, and did not finish.
        $this->paths['partial of a row'] = $this->paths['restored'].$p;
        Storage::disk($priv)->put($this->paths['partial of a row'], 'half');

        // 6. A path no row names, on core's private disk.
        $this->paths['orphan'] = 'media/'.$this->org->getKey().'/2026/09/orphan.png';
        Storage::disk($priv)->put($this->paths['orphan'], 'bytes');

        // 7. A file on the served disk no row names at any path: the host's.
        $this->paths['the host\'s'] = 'media/host/owned.png';
        Storage::disk('old-cdn')->put($this->paths['the host\'s'], 'bytes');

        // 8. A partial whose path no row names.
        $this->paths['orphaned partial'] = 'media/'.$this->org->getKey().'/2026/09/gone.png'.$p;
        Storage::disk($priv)->put($this->paths['orphaned partial'], 'half');

        // 10. A trashed row still naming public: trashed before decision 5, or a withdrawal that could not finish.
        $this->paths['trashed on public'] = $move(storedForPrune($this->imageType), $pub, [$pub => null], trashed: true);
    });

    /** The command's whole output, cut at each list's heading. @return array<string, string> */
    function pruneSections(array $options = []): array
    {
        $exit = Artisan::call('kitsune:media-prune', $options);
        $output = Artisan::output();
        $sections = ['exit' => (string) $exit];
        $headings = ['orphans' => null, 'partials' => 'Leftover partial copies', 'kept' => 'Kept copies', 'awaiting' => 'Awaiting publication', 'exposed' => 'Trashed on a served disk'];
        $cuts = [];

        foreach ($headings as $name => $heading) {
            $cuts[$name] = $heading === null ? 0 : strpos($output, $heading);
        }

        $cuts = array_filter($cuts, static fn (int|false $at): bool => $at !== false);
        asort($cuts);
        $names = array_keys($cuts);

        foreach ($names as $i => $name) {
            $end = isset($names[$i + 1]) ? $cuts[$names[$i + 1]] : strlen($output);
            $sections[$name] = substr($output, $cuts[$name], $end - $cuts[$name]);
        }

        return $sections + array_fill_keys(array_keys($headings), '') + ['all' => $output];
    }

    it('lists each residue where it belongs, and removes nothing without --force', function (): void {
        $sections = pruneSections();

        foreach (['only on private', 'published, uncommitted', 'restored', 'left on a served disk'] as $case) {
            expect($sections['kept'])->toContain($this->paths[$case])
                ->and($sections['orphans'])->not->toContain($this->paths[$case]);
        }

        expect($sections['partials'])->toContain($this->paths['partial of a row'])
            ->and($sections['orphans'])->toContain($this->paths['orphan'])
            ->and($sections['orphans'])->toContain($this->paths['orphaned partial'])
            ->and($sections['awaiting'])->toContain($this->paths['published, uncommitted'])
            ->and($sections['exposed'])->toContain($this->paths['trashed on public'])
            ->and($sections['all'])->not->toContain($this->paths['the host\'s'])
            ->and($sections['all'])->toContain('nothing removed')
            ->and($sections['exit'])->toBe('0');

        Storage::disk(MediaDisks::PRIVATE)->assertExists($this->paths['orphan']);
        Storage::disk(MediaDisks::PRIVATE)->assertExists($this->paths['partial of a row']);
    });

    it('removes only the orphans and the leftover partials when forced', function (): void {
        $sections = pruneSections(['--force' => true]);

        expect($sections['exit'])->toBe('0')
            ->and($sections['all'])->toContain('Removed 3 of 3');

        Storage::disk(MediaDisks::PRIVATE)->assertExists($this->paths['only on private']);
        Storage::disk('public')->assertExists($this->paths['published, uncommitted']);
        Storage::disk(MediaDisks::PRIVATE)->assertExists($this->paths['restored']);
        Storage::disk('old-cdn')->assertExists($this->paths['left on a served disk']);
        Storage::disk('old-cdn')->assertExists($this->paths['the host\'s']);

        Storage::disk(MediaDisks::PRIVATE)->assertMissing($this->paths['partial of a row']);
        Storage::disk(MediaDisks::PRIVATE)->assertMissing($this->paths['orphan']);
        Storage::disk(MediaDisks::PRIVATE)->assertMissing($this->paths['orphaned partial']);
    });

    /** Deleting asks what withdrawing asks: two names for one place would make a "copy" the file itself. */
    it('deletes nothing while the media disks cannot keep a withdrawn file private', function (): void {
        config(['kitsune.media.disks.private' => 'public']);

        $sections = pruneSections(['--force' => true]);

        expect($sections['exit'])->toBe('1')
            ->and($sections['all'])->toContain('share their media/ directory');

        Storage::disk(MediaDisks::PRIVATE)->assertExists($this->paths['orphan']);
    });

    /*
     * T21. The listing is read without a lock, so each delete asks again with custody's lock held. The rival row is
     * written as the removal's own transaction begins — between the listing and the recheck.
     */
    it('keeps a file a row claimed since the listing', function (string $case): void {
        $claim = $case === 'orphan' ? $this->paths['orphan'] : substr($this->paths['orphaned partial'], 0, -strlen(MediaBytes::PARTIAL));
        $claimed = false;

        Event::listen(TransactionBeginning::class, function () use ($claim, &$claimed): void {
            if ($claimed) {
                return;
            }

            $claimed = true;
            $file = storedForPrune($this->imageType);
            DB::table('media_files')->where('id', $file->getKey())->update(['path' => $claim]);
        });

        $sections = pruneSections(['--force' => true]);

        expect($claimed)->toBeTrue()
            ->and($sections['all'])->toContain('a row claimed');

        Storage::disk(MediaDisks::PRIVATE)->assertExists($this->paths[$case]);
    })->with(['orphan', 'orphaned partial']);
});
