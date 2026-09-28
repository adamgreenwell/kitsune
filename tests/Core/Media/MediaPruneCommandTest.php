<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Console\MediaPruneCommand;
use Kitsune\Core\Media\MediaBytes;
use Kitsune\Core\Media\MediaCustody;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\MediaFile;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\RefusingDisk;
use League\Flysystem\Filesystem;
use League\Flysystem\UnableToListContents;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;

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
    /*
     * ⚠️ AND THE CONFIGURATION NAMES THE FAKE'S ROOT. Prune asks the configuration whether core's private disk can hold
     * anything before it lists it (`MediaDisks::mayHold()`); left at the default, it answered from a directory in
     * vendor/ that exists only when an earlier test in the same process happened to build the real disk (review of 5b).
     */
    config(['filesystems.disks.'.MediaDisks::PRIVATE.'.root' => Storage::disk(MediaDisks::PRIVATE)->path('')]);
    Storage::fake('public');
    // And the public disk's, for the same reason: asked at its configured root, which a test before this one may or may
    // not have created — the tests that compare it with another disk passed only when one had (review of slice 5c).
    config(['filesystems.disks.public.root' => Storage::disk('public')->path('')]);

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

function pruneFixtureBytes(): string
{
    return (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    );
}

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

/*
 * T108. Never inside an open transaction: an erasure not yet committed has freed its path, the recheck would read that
 * as an orphan, and the rollback would bring the row back to no bytes — so neither the command nor a removal it runs
 * removes anything there (review of slice 5b).
 */
it('removes nothing inside an open transaction, and neither does any removal it runs', function (): void {
    $orphan = 'media/'.$this->org->getKey().'/2026/09/orphan.png';
    Storage::disk(MediaDisks::PRIVATE)->put($orphan, 'bytes');
    $exit = DB::transaction(fn (): int => Artisan::call('kitsune:media-prune', ['--force' => true]));

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('Refusing to prune inside an open transaction')
        ->and(fn () => DB::transaction(fn () => MediaCustody::removeOrphan(DB::connection(), MediaDisks::PRIVATE, $orphan)))
        ->toThrow(LogicException::class, 'inside an open transaction')
        ->and(fn () => DB::transaction(fn () => MediaCustody::removeTemp(DB::connection(), 1, MediaDisks::PRIVATE, $orphan)))
        ->toThrow(LogicException::class, 'inside an open transaction');

    Storage::disk(MediaDisks::PRIVATE)->assertExists($orphan);
});

/*
 * T110. With the private disk moved to a host's, a trashed file's copy left on core's private disk is an extra copy of
 * a row settled on the host's disk, and goes under that row's lock — a removal whose target is the private disk
 * (review of slice 5b).
 */
it('removes a trashed file\'s copy on core\'s private disk once the private disk has moved', function (): void {
    config([
        'filesystems.disks.host-private' => ['driver' => 'local', 'root' => storage_path('app/host-private')],
        'kitsune.media.disks.private' => 'host-private',
    ]);
    Storage::fake('host-private');
    $file = storedForPrune($this->imageType);
    DB::table('entries')->where('id', $file->entry_id)->update(['deleted_at' => now()]);
    Storage::disk(MediaDisks::PRIVATE)->put($file->path, pruneFixtureBytes());

    $this->artisan('kitsune:media-prune --force')
        ->expectsOutputToContain('and 1 of 1 extra copy.')
        ->assertSuccessful();

    expect($file->disk)->toBe('host-private')
        ->and(Storage::disk(MediaDisks::PRIVATE)->exists($file->path))->toBeFalse()
        ->and(hash('sha256', (string) Storage::disk('host-private')->get($file->path)))->toBe(hash('sha256', pruneFixtureBytes()));
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

/**
 * ⚠️ CORE'S OWN PRIVATE DISK IS ALWAYS KITSUNE'S TO SWEEP — Codex, #153. With the private disk pointed at a host's, a
 * copy disposal could not remove from `kitsune-private` would otherwise never be found once no row named it.
 */
it('removes an orphan from core\'s private disk after the private disk has moved', function (): void {
    config([
        'filesystems.disks.host-private' => ['driver' => 'local', 'root' => storage_path('app/host-private')],
        'kitsune.media.disks.private' => 'host-private',
    ]);
    Storage::fake('host-private');
    $orphan = 'media/'.$this->org->getKey().'/2026/09/left-by-disposal.png';
    Storage::disk(MediaDisks::PRIVATE)->put($orphan, 'bytes');

    $this->artisan('kitsune:media-prune --force')
        ->expectsOutputToContain('left-by-disposal.png')
        ->assertSuccessful();

    Storage::disk(MediaDisks::PRIVATE)->assertMissing($orphan);
});

/** Its control: never used, it has no root, and a read-only listing does not create one by building it. */
it('does not create core\'s private disk when the private disk has moved and it was never used', function (): void {
    $absent = sys_get_temp_dir().'/kitsune-prune-core-'.bin2hex(random_bytes(4));
    config([
        'filesystems.disks.host-private' => ['driver' => 'local', 'root' => storage_path('app/host-private')],
        'filesystems.disks.'.MediaDisks::PRIVATE => ['driver' => 'local', 'root' => $absent],
        'kitsune.media.disks.private' => 'host-private',
    ]);
    Storage::fake('host-private');
    Storage::forgetDisk(MediaDisks::PRIVATE);

    $this->artisan('kitsune:media-prune')->assertSuccessful();

    expect(is_dir($absent))->toBeFalse();
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
 * What custody leaves, and what prune does with it — ADR-042 decision 5 (T20, T21; slice 5c: T153, T174, T181; slice 5b:
 * T92-T97, T108, T110-T112, T115-T118, T120, T121, T123, T124, T127).
 *
 * ⚠️ A FILE IS AN ORPHAN WHEN NO ROW NAMES ITS PATH, ON ANY DISK. Custody leaves verified copies at a row's own path on
 * disks the row does not name, and any one of them may be the only good copy: each is listed as ~~kept, never deleted~~
 * an extra copy, and removed with --force only under its row's lock, once the disk the row names holds the copy kept
 * (slice 5b, T92-T97).
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
        // 7b. A partial beside it: the path it was written for names no row either, so it is the host's too.
        Storage::disk('old-cdn')->put($this->paths['the host\'s'].$p, 'half');

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
        $headings = ['orphans' => null, 'partials' => 'Leftover partial copies', 'extras' => 'Extra copies', 'awaiting' => 'Awaiting publication', 'exposed' => 'Trashed on a served disk'];
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
            expect($sections['extras'])->toContain($this->paths[$case])
                ->and($sections['orphans'])->not->toContain($this->paths[$case]);
        }

        // What --force would do with each, read from the listing alone.
        $said = static function (string $section, string $path): string {
            foreach (explode("\n", $section) as $line) {
                if (str_contains($line, $path) && ! str_contains($line, $path.MediaBytes::PARTIAL)) {
                    return $line;
                }
            }

            return '';
        };

        expect($said($sections['extras'], $this->paths['restored']))->toContain('removed, once asked again under the lock')
            ->and($said($sections['extras'], $this->paths['left on a served disk']))->toContain('removed, once asked again under the lock')
            ->and($said($sections['extras'], $this->paths['only on private']))->toContain('kept: the disk its row names does not hold the file — kitsune:media-reconcile --force settles it from the copies on the disks it asks, ['.MediaDisks::PRIVATE.'] among them')
            ->and($said($sections['extras'], $this->paths['published, uncommitted']))->toContain('kept: kitsune:media-reconcile moves its row first');

        expect($sections['partials'])->toContain($this->paths['partial of a row'])
            ->and($sections['orphans'])->toContain($this->paths['orphan'])
            ->and($sections['orphans'])->toContain($this->paths['orphaned partial'])
            ->and($sections['awaiting'])->toContain($this->paths['published, uncommitted'])
            ->and($sections['exposed'])->toContain($this->paths['trashed on public'])
            ->and($sections['all'])->not->toContain($this->paths['the host\'s'])
            ->and($sections['all'])->toContain('nothing removed')
            // On a table whose paths are unique, no warning that they are not.
            ->and($sections['all'])->not->toContain('is not unique')
            ->and($sections['exit'])->toBe('0');

        Storage::disk(MediaDisks::PRIVATE)->assertExists($this->paths['orphan']);
        Storage::disk(MediaDisks::PRIVATE)->assertExists($this->paths['partial of a row']);
    });

    /*
     * T92, where the disks custody asks cannot be told — the configured public disk is not configured: the copy's line
     * says so, and the report goes on to every list and the count, rather than stop at the first such line (review of
     * slice 5c).
     */
    it('keeps reporting when which disks custody asks cannot be told', function (bool $force): void {
        Storage::fake('archive');
        config(['filesystems.disks.archive' => ['driver' => 'local', 'root' => Storage::disk('archive')->path('')]]);
        // A private row whose only copy is on a disk another row names, and a live public row still on the private disk.
        $file = storedForPrune($this->imageType);
        DB::table('media_files')->where('id', $file->getKey())->update(['visibility' => 'private', 'disk' => MediaDisks::PRIVATE]);
        Storage::disk('archive')->put($file->path, (string) Storage::disk($file->disk)->get($file->path));
        Storage::disk($file->disk)->delete($file->path);
        $other = storedForPrune($this->imageType);
        DB::table('media_files')->where('id', $other->getKey())->update(['disk' => 'archive']);
        Storage::disk('archive')->put($other->path, (string) Storage::disk($other->disk)->get($other->path));
        $awaiting = storedForPrune($this->imageType);
        DB::table('media_files')->where('id', $awaiting->getKey())->update(['visibility' => 'public', 'disk' => MediaDisks::PRIVATE]);
        Storage::disk(MediaDisks::PRIVATE)->put($awaiting->path, pruneFixtureBytes());
        config(['kitsune.media.disks.public' => 'cdn']);

        $exit = Artisan::call('kitsune:media-prune', $force ? ['--force' => true] : []);
        $output = Artisan::output();

        expect($output)->toContain('Could not list [cdn]')
            ->and($output)->toContain('[archive]  '.$file->path.' — live, its row names ['.MediaDisks::PRIVATE.'], it belongs on ['.MediaDisks::PRIVATE.']: kept: the disk its row names does not hold the file, and whether custody asks [archive] cannot be told: a configured media disk cannot be read — run kitsune:media-prune again once it can be')
            ->and($output)->toContain('Awaiting publication')
            ->and($output)->toContain($awaiting->path)
            // Read-only, the count; forced, the refusal to remove anything while the disk cannot be read.
            ->and($output)->toContain($force ? 'Refusing to read the [cdn] disk' : 'listed and nothing removed')
            ->and(Storage::disk('archive')->exists($file->path))->toBeTrue()
            ->and($exit)->toBe(1);
    })->with(['read-only' => false, 'forced' => true]);

    /*
     * T92, for a copy on a disk custody never asks — one prune scans only because another row names it: reconcile never
     * sees it, and lists the row as missing, so its line says to copy it by hand (review of slice 5c).
     */
    it('says a copy on a disk custody does not ask is copied by hand', function (): void {
        Storage::fake('archive');
        config(['filesystems.disks.archive' => ['driver' => 'local', 'root' => Storage::disk('archive')->path('')]]);
        $file = storedForPrune($this->imageType);
        DB::table('media_files')->where('id', $file->getKey())->update(['visibility' => 'public', 'disk' => 'public']);
        Storage::disk($file->disk)->delete($file->path);
        Storage::disk('public')->delete($file->path);
        Storage::disk('archive')->put($file->path, pruneFixtureBytes());
        $other = storedForPrune($this->imageType);
        DB::table('media_files')->where('id', $other->getKey())->update(['disk' => 'archive']);
        Storage::disk('archive')->put($other->path, (string) Storage::disk($other->disk)->get($other->path));

        Artisan::call('kitsune:media-prune');
        $output = Artisan::output();

        expect(MediaCustody::asked(config(), 'public', 'public'))->not->toContain('archive')
            ->and($output)->toContain('[archive]  '.$file->path.' — live, its row names [public], it belongs on [public]: kept: the disk its row names does not hold the file, and custody does not ask [archive] — kitsune:media-reconcile --force settles it only from a copy on a disk it asks; otherwise copy this one to [public] by hand, checking it against the recorded checksum')
            ->and($output)->not->toContain('[archive] among them');
    });

    /*
     * T92. ~~Removes only the orphans and the leftover partials when forced.~~ And every extra copy of a settled file —
     * identical or differing — while it keeps the sole copy of a row on the wrong disk, and a publication's copy the
     * row does not name yet: those are kitsune:media-reconcile's.
     */
    it('removes the orphans, the leftover partials and the extra copies of settled files when forced', function (): void {
        // A live public file on the public disk, and a private copy that differs from it.
        $differing = (static function (EntryType $type): string {
            $file = storedForPrune($type);
            DB::table('media_files')->where('id', $file->getKey())->update(['visibility' => 'public', 'disk' => 'public']);
            Storage::disk('public')->put($file->path, pruneFixtureBytes());
            Storage::disk(MediaDisks::PRIVATE)->put($file->path, 'changed by hand');

            return $file->path;
        })($this->imageType);

        // A trashed file on the private disk, and copies left on the public disk and the served one: removing an extra
        // copy of a row whose target is the private disk takes the file off the web (review of slice 5b).
        $trashed = (static function (EntryType $type): string {
            $file = storedForPrune($type);
            DB::table('media_files')->where('id', $file->getKey())->update(['visibility' => 'public', 'disk' => MediaDisks::PRIVATE]);
            DB::table('entries')->where('id', $file->entry_id)->update(['deleted_at' => now()]);

            foreach ([MediaDisks::PRIVATE, 'public', 'old-cdn'] as $disk) {
                Storage::disk($disk)->put($file->path, pruneFixtureBytes());
            }

            return $file->path;
        })($this->imageType);

        $sections = pruneSections(['--force' => true]);

        expect($sections['exit'])->toBe('0')
            ->and($sections['all'])->toContain('Removed 3 of 3 orphaned or leftover files and 5 of 5 extra copies.')
            ->and(Storage::disk('public')->exists($trashed))->toBeFalse()
            ->and(Storage::disk('old-cdn')->exists($trashed))->toBeFalse()
            ->and(hash('sha256', (string) Storage::disk(MediaDisks::PRIVATE)->get($trashed)))->toBe(hash('sha256', pruneFixtureBytes()))
            // The differing copy's two hashes, on the console as in the log (review of slice 5b).
            ->and($sections['all'])->toContain('Media custody: removing the copy of ['.$differing.'] on ['.MediaDisks::PRIVATE.'], whose hash ['.hash('sha256', 'changed by hand').']')
            // Each row's state as it was read, carried in the integer an extra copy is held in (review of slice 5c).
            ->and($sections['extras'])->toMatch('/^  entry \d+  \[old-cdn\]  '.preg_quote($trashed, '/').' — trashed, its row names \['.preg_quote(MediaDisks::PRIVATE, '/').'\], it belongs on \['.preg_quote(MediaDisks::PRIVATE, '/').'\]: removed, once asked again under the lock$/m')
            ->and($sections['extras'])->toMatch('/^  entry \d+  \[old-cdn\]  '.preg_quote($this->paths['left on a served disk'], '/').' — live, its row names \[public\], it belongs on \[public\]: /m');

        Storage::disk(MediaDisks::PRIVATE)->assertExists($this->paths['only on private']);
        Storage::disk('public')->assertExists($this->paths['published, uncommitted']);
        Storage::disk(MediaDisks::PRIVATE)->assertExists($this->paths['published, uncommitted']);
        Storage::disk('old-cdn')->assertExists($this->paths['the host\'s']);
        Storage::disk('old-cdn')->assertExists($this->paths['the host\'s'].MediaBytes::PARTIAL);

        Storage::disk('public')->assertExists($this->paths['restored']);
        Storage::disk(MediaDisks::PRIVATE)->assertMissing($this->paths['restored']);
        Storage::disk('public')->assertExists($this->paths['left on a served disk']);
        Storage::disk('old-cdn')->assertMissing($this->paths['left on a served disk']);
        Storage::disk('public')->assertExists($differing);
        Storage::disk(MediaDisks::PRIVATE)->assertMissing($differing);

        Storage::disk(MediaDisks::PRIVATE)->assertMissing($this->paths['partial of a row']);
        Storage::disk(MediaDisks::PRIVATE)->assertMissing($this->paths['orphan']);
        Storage::disk(MediaDisks::PRIVATE)->assertMissing($this->paths['orphaned partial']);
    });

    /*
     * T93. The listing chooses; the lock decides: a trash landing after the listing withdraws the file to the private
     * disk and points the row there, so the copy listed as extra is now the file's own, and is kept — counted as no
     * longer extra, not as kept or failed. ~~Set by hand, `deleted_at` alone~~ — a state no trash leaves; the trash is a
     * real one, through the model (review of slice 5b).
     */
    it('keeps an extra copy a trash made the file\'s own after the listing', function (): void {
        $restored = (int) DB::table('media_files')->where('path', $this->paths['restored'])->value('entry_id');
        $landed = false;
        Event::listen(TransactionBeginning::class, function () use ($restored, &$landed): void {
            if (! $landed) {
                $landed = true;
                Entry::query()->findOrFail($restored)->delete();
            }
        });

        $sections = pruneSections(['--force' => true]);

        expect($landed)->toBeTrue()
            ->and(DB::table('media_files')->where('entry_id', $restored)->value('disk'))->toBe(MediaDisks::PRIVATE)
            ->and(hash('sha256', (string) Storage::disk(MediaDisks::PRIVATE)->get($this->paths['restored'])))->toBe(hash('sha256', pruneFixtureBytes()))
            ->and(Storage::disk('public')->exists($this->paths['restored']))->toBeFalse()
            ->and($sections['all'])->not->toContain('Could not remove')
            ->and($sections['all'])->not->toContain('Kept [')
            ->and($sections['all'])->toContain('1 no longer an extra copy under the lock — now the copy its row names.')
            // Its old wording, which also counted a copy gone since the listing — now `unheld` (review of slice 5c).
            ->and($sections['all'])->not->toContain('gone, or')
            ->and($sections['exit'])->toBe('0');
    });

    /*
     * T116. The other two outcomes the lock can find for an extra copy: its entry erased since the listing, and its row
     * no longer naming the disk its state says — a restore whose publication has not run (review of slice 5b).
     */
    it('reports an extra copy whose entry was erased, or whose row a restore unsettled, after the listing', function (string $landing, string $says): void {
        $restored = (int) DB::table('media_files')->where('path', $this->paths['restored'])->value('entry_id');
        // A trashed public file settled on the private disk, a copy of it left on the served one.
        $trashed = storedForPrune($this->imageType);
        DB::table('media_files')->where('id', $trashed->getKey())->update(['visibility' => 'public']);
        DB::table('entries')->where('id', $trashed->entry_id)->update(['deleted_at' => now()]);
        Storage::disk('old-cdn')->put($trashed->path, pruneFixtureBytes());
        $landed = false;
        Event::listen(TransactionBeginning::class, function () use ($landing, $restored, $trashed, &$landed): void {
            if (! $landed) {
                $landed = true;

                match ($landing) {
                    'erased' => DB::table('entries')->whereIn('id', [$restored, $trashed->entry_id])->delete(),
                    'restored' => DB::table('entries')->where('id', $trashed->entry_id)->update(['deleted_at' => null]),
                };
            }
        });

        $sections = pruneSections(['--force' => true]);

        expect($landed)->toBeTrue()
            ->and($sections['all'])->toContain($says)
            ->and($sections['all'])->not->toContain('Could not remove')
            ->and(Storage::disk('old-cdn')->exists($trashed->path))->toBeTrue()
            ->and($sections['exit'])->toBe('0');
    })->with([
        'erased' => ['erased', '2 whose entry was erased since the listing: its disposal removes the copies or logs why it could not'],
        'restored, unpublished' => ['restored', 'under the lock its row no longer named the disk its state says: kitsune:media-reconcile --entry='],
    ]);

    /*
     * T116b. The entry erased and its copies disposed before the extra-copy loop starts — at an orphan's lock — so the copy
     * is gone: a name present but not held is refused, and one gone lets the lock say its entry was erased (review of 5c).
     */
    it('reports an extra copy whose entry was erased and its copies disposed before the extra-copy loop', function (): void {
        $restored = (int) DB::table('media_files')->where('path', $this->paths['restored'])->value('entry_id');
        $trashed = storedForPrune($this->imageType);
        DB::table('media_files')->where('id', $trashed->getKey())->update(['visibility' => 'public']);
        DB::table('entries')->where('id', $trashed->entry_id)->update(['deleted_at' => now()]);
        Storage::disk('old-cdn')->put($trashed->path, pruneFixtureBytes());
        $landed = false;
        // The first transaction is an orphan's removal, which runs before the extra-copy loop.
        Event::listen(TransactionBeginning::class, function () use ($restored, $trashed, &$landed): void {
            if (! $landed) {
                $landed = true;
                DB::table('entries')->whereIn('id', [$restored, $trashed->entry_id])->delete();

                // As the force-delete's disposal would: every copy of both files, the listed extra copies among them.
                foreach ([MediaDisks::PRIVATE, 'public', 'old-cdn'] as $disk) {
                    Storage::disk($disk)->delete([$this->paths['restored'], $trashed->path]);
                }
            }
        });

        $sections = pruneSections(['--force' => true]);

        expect($landed)->toBeTrue()
            ->and($sections['all'])->toContain('2 whose entry was erased since the listing')
            ->and($sections['all'])->not->toContain('Could not remove')
            ->and($sections['exit'])->toBe('0');
    });

    /*
     * T94. The copy the row names differs from the checksum and the extra copy matches it: prune keeps it, and
     * reconcile rewrites the named copy from it, whose cleanup then removes it.
     */
    it('keeps an extra copy that alone matches, for reconcile to rewrite the named copy from', function (): void {
        $file = storedForPrune($this->imageType);
        DB::table('media_files')->where('id', $file->getKey())->update(['visibility' => 'public', 'disk' => 'public']);
        Storage::disk(MediaDisks::PRIVATE)->put($file->path, pruneFixtureBytes());
        Storage::disk('public')->put($file->path, 'changed by hand');

        $sections = pruneSections(['--force' => true]);

        // Why, and what settles it — custody's own words, on the console (review of slice 5b).
        expect($sections['all'])->toContain('Kept ['.MediaDisks::PRIVATE.':'.$file->path.'], entry '.$file->entry_id.' — custody says why')
            ->and($sections['all'])->toContain('[public], the disk the row names, does not hold the copy kept, which is on ['.MediaDisks::PRIVATE.']')
            ->and($sections['all'])->toContain('kitsune:media-reconcile --entry='.$file->entry_id.' --force rewrites [public] from it')
            ->and($sections['all'])->toContain('1 kept under the lock, each with its reason above.');
        Storage::disk(MediaDisks::PRIVATE)->assertExists($file->path);

        expect(Artisan::call('kitsune:media-reconcile', ['--force' => true, '--entry' => [(string) $file->entry_id]]))->toBe(0)
            ->and(hash('sha256', (string) Storage::disk('public')->get($file->path)))->toBe(hash('sha256', pruneFixtureBytes()));
        Storage::disk(MediaDisks::PRIVATE)->assertMissing($file->path);
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
            ->and($sections['all'])->toContain('kept: under the lock a row claimed its path — one committed since the listing, or one the database, or the disk, takes for the same path.');

        Storage::disk(MediaDisks::PRIVATE)->assertExists($this->paths[$case]);
    })->with(['orphan', 'orphaned partial']);
});

/*
 * T95. A served disk that is a media disk under another name is not scanned — every file there would read as an extra
 * copy of itself — and says so when it cannot be told apart; nor is a served disk whose root does not exist built.
 */
it('scans no served disk that is, or cannot be told from, a media disk, and builds none with no root', function (): void {
    $root = sys_get_temp_dir().'/kitsune-prune-store-'.bin2hex(random_bytes(4));
    mkdir($root, 0777, true);

    try {
        foreach (['public' => 'e', 'cdn' => 'f'] as $name => $endpoint) {
            $config = ['driver' => 's3', 'bucket' => 'media', 'endpoint' => $endpoint, 'prefix' => 'site', 'url' => "https://{$name}.example.test"];
            config(["filesystems.disks.{$name}" => $config]);
            $adapter = new RefusingDisk($root, $name);
            Storage::set($name, new FilesystemAdapter(new Filesystem($adapter), $adapter, $config));
        }

        $rootless = sys_get_temp_dir().'/kitsune-prune-rootless-'.bin2hex(random_bytes(4));
        config(['filesystems.disks.rootless-cdn' => ['driver' => 'local', 'root' => $rootless, 'url' => 'https://rootless.example.test']]);
        $file = storedForPrune($this->imageType);
        DB::table('media_files')->where('id', $file->getKey())->update(['visibility' => 'public', 'disk' => 'public']);
        Storage::disk(MediaDisks::PRIVATE)->delete($file->path);
        Storage::disk('public')->put($file->path, pruneFixtureBytes());

        foreach ([[], ['--force' => true]] as $options) {
            $exit = Artisan::call('kitsune:media-prune', $options);
            $output = Artisan::output();

            expect($output)->toContain('Not scanning [cdn]: it is, or cannot be told apart from, [public]')
                ->and(substr_count($output, 'Not scanning'))->toBe(1)
                ->and($output)->not->toContain('Extra copies')
                ->and(Storage::disk('public')->exists($file->path))->toBeTrue()
                ->and(is_dir($rootless))->toBeFalse()
                ->and($exit)->toBe(0);
        }
    } finally {
        exec('rm -rf '.escapeshellarg($root));
    }
});

/*
 * T174. A served read-through disk whose half is the public disk reaches the public disk's files, so it cannot be told
 * apart from it, and is not scanned: scanned, it listed the public file as its own extra copy, and removing that removed
 * the public file (review of slice 5c).
 */
it('scans no served read-through disk whose half is the public disk, and removes nothing through it', function (): void {
    $fallback = sys_get_temp_dir().'/kitsune-prune-rt-fallback-'.bin2hex(random_bytes(4));
    mkdir($fallback, 0777, true);

    try {
        config([
            'filesystems.disks.rt-fallback' => ['driver' => 'local', 'root' => $fallback],
            'filesystems.disks.rt' => ['driver' => 'read-through', 'primary' => 'public', 'fallback' => 'rt-fallback', 'url' => 'https://rt.example.test'],
        ]);
        $file = storedForPrune($this->imageType);
        DB::table('media_files')->where('id', $file->getKey())->update(['visibility' => 'public', 'disk' => 'public']);
        Storage::disk(MediaDisks::PRIVATE)->delete($file->path);
        Storage::disk('public')->put($file->path, pruneFixtureBytes());

        foreach ([[], ['--force' => true]] as $options) {
            $exit = Artisan::call('kitsune:media-prune', $options);
            $output = Artisan::output();

            expect($output)->toContain('Not scanning [rt]: it is, or cannot be told apart from, [public]')
                ->and($output)->not->toContain('Extra copies')
                ->and(Storage::disk('public')->exists($file->path))->toBeTrue()
                ->and($exit)->toBe(0);
        }
    } finally {
        exec('rm -rf '.escapeshellarg($fallback));
    }
});

/*
 * ...and a link the listing left out is not removed through a listed name that reaches it: on a disk configured
 * `links => skip`, `a\\b.png` is listed as `a/b.png`, where the link is (review of slice 5c).
 */
it('removes no link its listing skipped, through a name that reaches it', function (): void {
    $root = sys_get_temp_dir().'/kitsune-prune-links-'.bin2hex(random_bytes(4));
    mkdir($root.'/media/1/2026/09/a', 0777, true);

    try {
        config(['filesystems.disks.'.MediaDisks::PRIVATE => [...config('filesystems.disks.'.MediaDisks::PRIVATE), 'root' => $root, 'links' => 'skip']]);
        Storage::forgetDisk(MediaDisks::PRIVATE);
        file_put_contents($root.'/media/1/2026/09/a\\b.png', 'another file');
        file_put_contents($root.'/elsewhere.png', 'the host\'s');
        symlink($root.'/elsewhere.png', $root.'/media/1/2026/09/a/b.png');

        $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Could not remove ['.MediaDisks::PRIVATE.':media/1/2026/09/a/b.png]')
            ->and(is_link($root.'/media/1/2026/09/a/b.png'))->toBeTrue()
            ->and(is_file($root.'/media/1/2026/09/a\\b.png'))->toBeTrue()
            ->and($exit)->toBe(1);
    } finally {
        exec('rm -rf '.escapeshellarg($root));
    }
});

/*
 * Artisan reuses a command in a process: whether a disk reads through is asked again in each run, never remembered from
 * one before — a stale answer offered a read-through disk's copy for removal, which --force then refused (review of 5c).
 */
it('asks again in each run whether a disk reads through, however many runs came before in the process', function (): void {
    $roots = [sys_get_temp_dir().'/kitsune-prune-s-'.bin2hex(random_bytes(4)), sys_get_temp_dir().'/kitsune-prune-sp-'.bin2hex(random_bytes(4)), sys_get_temp_dir().'/kitsune-prune-sf-'.bin2hex(random_bytes(4))];

    foreach ($roots as $root) {
        mkdir($root, 0777, true);
    }

    try {
        $file = storedForPrune($this->imageType);
        config(['filesystems.disks.s' => ['driver' => 'local', 'root' => $roots[0], 'url' => 'https://s.example.test']]);
        Storage::disk('s')->put($file->path, pruneFixtureBytes());

        Artisan::call('kitsune:media-prune');

        expect(Artisan::output())->toContain('[s]  '.$file->path.' — live, its row names ['.MediaDisks::PRIVATE.'], it belongs on ['.MediaDisks::PRIVATE.']: removed, once asked again under the lock');

        config([
            'filesystems.disks.sp' => ['driver' => 'local', 'root' => $roots[1]],
            'filesystems.disks.sf' => ['driver' => 'local', 'root' => $roots[2]],
            'filesystems.disks.s' => ['driver' => 'read-through', 'primary' => 'sp', 'fallback' => 'sf', 'url' => 'https://s.example.test'],
        ]);
        Storage::forgetDisk('s');
        Storage::disk('sp')->put($file->path, pruneFixtureBytes());

        Artisan::call('kitsune:media-prune');
        $said = Artisan::output();

        expect($said)->toContain('kept: [s] is a read-through disk')
            ->and($said)->not->toContain('removed, once asked again under the lock');
    } finally {
        foreach ($roots as $root) {
            exec('rm -rf '.escapeshellarg($root));
        }
    }
});

/* ...and a read-through disk named with digits alone keeps its copy with the reason any other's gets (review of 5c). */
it('keeps an extra copy on a read-through disk named with digits alone', function (): void {
    $roots = [sys_get_temp_dir().'/kitsune-prune-7p-'.bin2hex(random_bytes(4)), sys_get_temp_dir().'/kitsune-prune-7f-'.bin2hex(random_bytes(4))];

    foreach ($roots as $root) {
        mkdir($root, 0777, true);
    }

    try {
        config([
            'filesystems.disks.7p' => ['driver' => 'local', 'root' => $roots[0]],
            'filesystems.disks.7f' => ['driver' => 'local', 'root' => $roots[1]],
            'filesystems.disks.7' => ['driver' => 'read-through', 'primary' => '7p', 'fallback' => '7f', 'url' => 'https://rt.example.test'],
        ]);
        $file = storedForPrune($this->imageType);
        Storage::disk('7p')->put($file->path, pruneFixtureBytes());

        foreach ([[], ['--force' => true]] as $options) {
            $exit = Artisan::call('kitsune:media-prune', $options);

            // Served, and the file kept off the web: the reason any other served read-through disk's copy gets — and the
            // heading promises no removal a line does not make (review of slice 5c).
            $said = Artisan::output();

            expect($said)->toContain('kept: [7] is a read-through disk the web serves, which custody neither reads nor removes a copy through, and the file belongs off the web')
                ->and($said)->toContain('every other copy is kept, and its line says what settles it:')
                ->and($said)->not->toContain("the rest are kitsune:media-reconcile's")
                ->and(is_file($roots[0].'/'.$file->path))->toBeTrue()
                ->and($exit)->toBe(0);
        }
    } finally {
        foreach ($roots as $root) {
            exec('rm -rf '.escapeshellarg($root));
        }
    }
});

/*
 * ...and an orphan or a leftover partial copy on one a row names fails every forced run, and says to remove it by hand
 * through the half that holds it: no row keeps it, so there is no copy to compare it with — delete()'s word for a row's
 * copy would send a person to one (review of slice 5c). Every file stays on its half.
 */
it('fails every forced run on an orphan or a partial copy on a read-through disk, and names no copy to compare', function (bool $served): void {
    $roots = [sys_get_temp_dir().'/kitsune-prune-rtp-'.bin2hex(random_bytes(4)), sys_get_temp_dir().'/kitsune-prune-rtf-'.bin2hex(random_bytes(4))];

    foreach ($roots as $root) {
        mkdir($root, 0777, true);
    }

    try {
        config([
            'filesystems.disks.rtp' => ['driver' => 'local', 'root' => $roots[0]],
            'filesystems.disks.rtf' => ['driver' => 'local', 'root' => $roots[1]],
            'filesystems.disks.rt' => ['driver' => 'read-through', 'primary' => 'rtp', 'fallback' => 'rtf', ...($served ? ['url' => 'https://rt.example.test'] : [])],
        ]);
        $file = storedForPrune($this->imageType);
        DB::table('media_files')->where('id', $file->getKey())->update(['disk' => 'rt']);
        Storage::disk('rtp')->put($file->path, pruneFixtureBytes());
        $orphan = 'media/'.$this->org->getKey().'/orphan.png';
        $partial = $file->path.MediaBytes::PARTIAL;

        foreach ([[$roots[0], $orphan], [$roots[0], $partial], [$roots[1], $orphan]] as [$root, $path]) {
            @mkdir(dirname($root.'/'.$path), 0777, true);
            file_put_contents($root.'/'.$path, 'bytes');
        }

        foreach ([1, 2] as $run) {
            $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);
            $output = Artisan::output();

            expect($output)->toContain('Could not remove [rt:'.$orphan.']: Refusing to go on with ['.$orphan.'] on the [rt] disk: it is on a read-through disk')
                ->and($output)->toContain('Could not remove [rt:'.$partial.']: Refusing to go on with ['.$partial.'] on the [rt] disk: it is on a read-through disk')
                ->and(substr_count($output, 'remove it by hand through the half that holds it'))->toBe(2)
                ->and($output)->not->toContain('compare it with the copy where the row belongs')
                ->and(is_file($roots[0].'/'.$orphan))->toBeTrue()
                ->and(is_file($roots[0].'/'.$partial))->toBeTrue()
                ->and(is_file($roots[1].'/'.$orphan))->toBeTrue()
                ->and(is_file($roots[0].'/'.$file->path))->toBeTrue()
                ->and($exit)->toBe(1);
        }
    } finally {
        foreach ($roots as $root) {
            exec('rm -rf '.escapeshellarg($root));
        }
    }
})->with(['served' => true, 'not served' => false]);

/*
 * An extra copy at a row path the disks read as another, or refuse — written past `MediaFile`: a zero-width space, or a
 * local name `y\..\x.png` the listing gives as the row's `y/../x.png` — is listed, since the listing and the row match
 * byte for byte, and kept with custody's word for that row, whatever disk the row names: correct its path. It was
 * offered as removable, and every forced run refused it; once, as though a disk were down (review of slice 5c).
 */
it('keeps an extra copy at a row path the disks read as another, saying to correct the row', function (string $spelling, bool $elsewhere): void {
    $file = storedForPrune($this->imageType);
    $named = dirname($file->path).'/'.$spelling;
    $roots = ['public' => rtrim(Storage::disk('public')->path(''), '/'), MediaDisks::PRIVATE => rtrim(Storage::disk(MediaDisks::PRIVATE)->path(''), '/')];

    if ($elsewhere) {
        $roots['old'] = sys_get_temp_dir().'/kitsune-prune-old-'.bin2hex(random_bytes(4));
        mkdir($roots['old'], 0777, true);
        config(['filesystems.disks.old' => ['driver' => 'local', 'root' => $roots['old']]]);
        unset($roots['public']);
    }

    DB::table('media_files')->where('id', $file->getKey())->update(['disk' => $elsewhere ? 'old' : 'public', 'visibility' => 'public', 'path' => $named]);

    // Written as the file system spells it: a backslash is a name's own character on a local disk.
    $written = str_replace('/', '\\', $spelling);

    foreach ($roots as $root) {
        @mkdir($root.'/'.dirname($file->path), 0777, true);
        file_put_contents($root.'/'.dirname($file->path).'/'.$written, pruneFixtureBytes());
    }

    try {
        foreach ([[], ['--force' => true], ['--force' => true]] as $options) {
            $exit = Artisan::call('kitsune:media-prune', $options);
            $output = Artisan::output();

            // The copy prune found is under the row's literal name, not at the path the disks read: moved there first, or
            // the row, corrected, would name nothing (review of slice 5c).
            expect($output)->toContain("kept: its row's path is not written as the disks read it, and this copy is under that literal name, which no disk reads or removes — move it by hand")
                ->and($output)->not->toContain('where the file is')
                ->and($output)->not->toContain('removed, once asked again under the lock')
                ->and($output)->not->toContain('moves its row first')
                ->and($output)->not->toContain('Could not remove')
                ->and($exit)->toBe(0);

            foreach ($roots as $root) {
                expect(is_file($root.'/'.dirname($file->path).'/'.$written))->toBeTrue();
            }
        }
    } finally {
        if ($elsewhere) {
            exec('rm -rf '.escapeshellarg($roots['old']));
        }
    }
})->with([
    'a name the disks refuse' => ['a'."\u{200B}".'b.png', false],
    'a name the disks read as another' => ['y/../x.png', false],
    'its row naming another disk' => ['a'."\u{200B}".'b.png', true],
]);

/*
 * ...and that word comes first, whatever disk the row names — one not configured, a read-through disk, or one over the
 * disk the file belongs on: settle refuses a misnamed row before any disk is asked, so no word about the disk the row
 * names is true of it (review of slice 5c).
 */
it('keeps a misnamed row\'s copy with the misnamed word, whatever disk the row names', function (string $disk, string $visibility, array $disks, int $exit): void {
    $roots = [];

    foreach ($disks as $name) {
        $roots[] = $root = sys_get_temp_dir().'/kitsune-prune-'.$name.'-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        config(["filesystems.disks.{$name}" => ['driver' => 'local', 'root' => $root]]);
    }

    config([
        'filesystems.disks.rt' => $visibility === 'public'
            ? ['driver' => 'read-through', 'primary' => 'public', 'fallback' => 'rtf']
            : ['driver' => 'read-through', 'primary' => 'rtp', 'fallback' => 'rtf'],
    ]);
    $file = storedForPrune($this->imageType);
    $privateRoot = rtrim(Storage::disk(MediaDisks::PRIVATE)->path(''), '/');
    $named = dirname($file->path).'/a'."\u{200B}".'b.png';
    rename($privateRoot.'/'.$file->path, $privateRoot.'/'.$named);
    DB::table('media_files')->where('id', $file->getKey())->update(['disk' => $disk, 'visibility' => $visibility, 'path' => $named]);
    $target = $visibility === 'public' ? 'public' : MediaDisks::PRIVATE;

    try {
        foreach ([[], ['--force' => true], ['--force' => true]] as $options) {
            $code = Artisan::call('kitsune:media-prune', $options);
            $output = Artisan::output();

            expect($output)->toContain('['.MediaDisks::PRIVATE.']  '.$named.' — live, its row names ['.$disk.'], it belongs on ['.$target."]: kept: its row's path is not written as the disks read it, and this copy is under that literal name")
                ->and($output)->not->toContain('which its row names, could not be listed —')
                ->and($output)->not->toContain('a read-through disk, which custody neither reads')
                ->and($output)->not->toContain('kitsune:media-reconcile --force moves no row off it')
                ->and($output)->not->toContain('moves its row first')
                ->and(is_file($privateRoot.'/'.$named))->toBeTrue()
                ->and($code)->toBe($exit);
        }
    } finally {
        foreach ($roots as $root) {
            exec('rm -rf '.escapeshellarg($root));
        }
    }
})->with([
    'a disk that is not configured' => ['gone', 'private', [], 1],
    'a read-through disk' => ['rt', 'private', ['rtp', 'rtf'], 0],
    'a read-through disk over the disk it belongs on' => ['rt', 'public', ['rtf'], 0],
]);

/*
 * On SQLite a value bound as bytes — an import's blob, a `CAST(… AS BLOB)` — is stored as a BLOB, which never equals a
 * TEXT parameter: prune's lookups by path, by visibility and by disk missed such a row, so it listed the row's own file
 * as an orphan, and --force removed it, where reading every row and comparing in PHP kept it. Each value is asked as
 * both, and so is the lock's recheck of an orphan (review of slice 5c).
 */
it('matches a row whose values SQLite stores as a BLOB, and removes none of its files', function (string $column): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        test()->markTestSkipped('only SQLite keeps a value\'s storage class apart from its column\'s type');
    }

    $file = storedForPrune($this->imageType);
    $privateRoot = rtrim(Storage::disk(MediaDisks::PRIVATE)->path(''), '/');

    if ($column === 'visibility') {
        // Live and public, still on the private disk: awaiting publication.
        DB::table('media_files')->where('id', $file->getKey())->update(['visibility' => 'public']);
    } elseif ($column === 'disk') {
        // Trashed, still on the public disk, which the web serves.
        Storage::disk('public')->put($file->path, pruneFixtureBytes());
        Storage::disk(MediaDisks::PRIVATE)->delete($file->path);
        DB::table('media_files')->where('id', $file->getKey())->update(['disk' => 'public']);
        DB::table('entries')->where('id', $file->entry_id)->update(['deleted_at' => now()]);
    }

    DB::update('update media_files set '.$column.' = cast('.$column.' as blob) where id = ?', [$file->getKey()]);

    expect(DB::selectOne('select typeof('.$column.') as type from media_files where id = ?', [$file->getKey()])->type)->toBe('blob');

    foreach ([[], ['--force' => true]] as $options) {
        $exit = Artisan::call('kitsune:media-prune', $options);
        $output = Artisan::output();

        expect($output)->toContain('No orphaned media files.')
            ->and($exit)->toBe(0);

        match ($column) {
            'visibility' => expect($output)->toContain('Awaiting publication')->and($output)->toContain($file->path),
            'disk' => expect($output)->toContain('Trashed on a served disk')->and($output)->toContain($file->path),
            default => expect(is_file($privateRoot.'/'.$file->path))->toBeTrue(),
        };
    }

    if ($column === 'path') {
        expect(MediaCustody::removeOrphan(DB::connection(), MediaDisks::PRIVATE, $file->path))->toBe(MediaCustody::CLAIMED)
            ->and(is_file($privateRoot.'/'.$file->path))->toBeTrue();
    }
})->with(['its path' => 'path', 'its visibility' => 'visibility', 'its disk' => 'disk']);

/*
 * A copy whose row names a disk that is not configured is kept as one whose row names a disk that could not be listed:
 * reconcile refuses the row until the disk can be asked, so prune does not say reconcile moves it first (review of 5c).
 */
it('keeps a copy whose row names a disk that could not be listed, and sends it to no reconcile', function (bool $force): void {
    $file = storedForPrune($this->imageType);
    DB::table('media_files')->where('id', $file->getKey())->update(['disk' => 'gone']);

    $exit = Artisan::call('kitsune:media-prune', $force ? ['--force' => true] : []);
    $output = Artisan::output();

    expect($output)->toContain('Could not list [gone]')
        ->and($output)->toContain('kept: [gone], which its row names, could not be listed — kitsune:media-reconcile --force moves its row only once that disk can be asked')
        ->and($output)->not->toContain('moves its row first')
        ->and(is_file(Storage::disk(MediaDisks::PRIVATE)->path($file->path)))->toBeTrue()
        ->and($exit)->toBe(1);
})->with(['read-only' => false, 'forced' => true]);

/*
 * ...and one whose target could not be listed is kept as one that could not be listed: nobody knows what the target
 * holds, so no hand copy is advised — one that could overwrite the target's own copy, which it does hold (review of 5c).
 */
it('keeps a read-through copy whose target could not be listed, and advises no hand copy', function (int $failing, bool $force): void {
    $roots = [sys_get_temp_dir().'/kitsune-prune-rtp-'.bin2hex(random_bytes(4)), sys_get_temp_dir().'/kitsune-prune-rtf-'.bin2hex(random_bytes(4))];

    foreach ($roots as $root) {
        mkdir($root, 0777, true);
    }

    try {
        config([
            'filesystems.disks.rtp' => ['driver' => 'local', 'root' => $roots[0]],
            'filesystems.disks.rtf' => ['driver' => 'local', 'root' => $roots[1]],
            'filesystems.disks.rt' => ['driver' => 'read-through', 'primary' => 'rtp', 'fallback' => 'rtf', 'url' => 'https://rt.example.test'],
        ]);
        $file = storedForPrune($this->imageType);
        $privateRoot = rtrim(Storage::disk(MediaDisks::PRIVATE)->path(''), '/');
        $private = RefusingDisk::install(MediaDisks::PRIVATE, $privateRoot);
        Storage::disk('rtp')->put($file->path, pruneFixtureBytes());
        $private->failListing = $failing;

        $exit = Artisan::call('kitsune:media-prune', $force ? ['--force' => true] : []);
        $output = Artisan::output();

        expect($output)->toContain('kept: ['.MediaDisks::PRIVATE.'] could not be listed — run kitsune:media-prune again once it can be')
            ->and($output)->not->toContain('does not list the file')
            ->and($output)->not->toContain('by hand')
            ->and(is_file($roots[0].'/'.$file->path))->toBeTrue()
            ->and(is_file($privateRoot.'/'.$file->path))->toBeTrue()
            ->and($exit)->toBe(1);
    } finally {
        foreach ($roots as $root) {
            exec('rm -rf '.escapeshellarg($root));
        }
    }
})->with(['the first listing' => 1, 'the second' => 2])->with(['read-only' => false, 'forced' => true]);

/*
 * T181. A pair that cannot be compared — a read-through disk whose half is not configured, or cannot be built — is left
 * out, and the other queued disks still decide: a served alias of the private disk behind one is still said, and not
 * scanned, and one of another served disk queued after it is left to that disk, its file listed once; the read-through
 * disk itself, compared with none, is scanned, and its listing fails the run rather than the comparison stopping it —
 * and one with no url, which Laravel could not build and so serves nothing, is neither (review of slice 5c).
 */
it('leaves out a pair it cannot compare, and lets the others decide', function (string $half): void {
    $roots = [sys_get_temp_dir().'/kitsune-prune-rtp-'.bin2hex(random_bytes(4)), sys_get_temp_dir().'/kitsune-prune-cdn-'.bin2hex(random_bytes(4))];
    mkdir($roots[0].'/media', 0777, true);
    mkdir($roots[1], 0777, true);
    file_put_contents($roots[0].'/media/a.png', 'a');

    try {
        config([
            'filesystems.disks.rtp' => ['driver' => 'local', 'root' => $roots[0], ...($half === 'unbuildable' ? ['permissions' => 'not an array'] : [])],
            'filesystems.disks.rt' => ['driver' => 'read-through', 'primary' => 'rtp', 'fallback' => $half === 'unbuildable' ? 'rtp-other' : 'gone', ...($half === 'unserved' ? [] : ['url' => 'https://rt.example.test'])],
            'filesystems.disks.rtp-other' => ['driver' => 'local', 'root' => $roots[0]],
            'filesystems.disks.dd' => ['driver' => 'local', 'root' => Storage::disk(MediaDisks::PRIVATE)->path(''), 'url' => 'https://dd.example.test'],
            // Queued after the disk that cannot be compared: an alias of another served disk is still left to it.
            'filesystems.disks.cdn' => ['driver' => 'local', 'root' => $roots[1], 'url' => 'https://cdn.example.test'],
            'filesystems.disks.cdn-origin' => ['driver' => 'local', 'root' => $roots[1], 'url' => 'https://origin.example.test'],
        ]);
        storedForPrune($this->imageType);
        $trashed = storedForPrune($this->imageType);
        DB::table('entries')->where('id', $trashed->entry_id)->update(['deleted_at' => now()]);
        Storage::disk('cdn')->put($trashed->path, pruneFixtureBytes());

        $exit = Artisan::call('kitsune:media-prune');
        $output = Artisan::output();

        expect($output)->toContain('Not scanning [dd]: it is, or cannot be told apart from, ['.MediaDisks::PRIVATE.']')
            ->and($output)->toContain('kitsune:media-reconcile --force refuses while the private disk is one of them.')
            ->and($output)->not->toContain('[dd]  media/')
            ->and(substr_count($output, $trashed->path))->toBe(1)
            ->and($output)->not->toContain('[cdn-origin]  media/')
            // A disk Laravel could not build, and nothing serves, is neither scanned nor a reason to stop.
            ->and(str_contains($output, 'Could not list [rt]'))->toBe($half !== 'unserved')
            ->and($exit)->toBe($half === 'unserved' ? 0 : 1)
            ->and(is_file($roots[0].'/media/a.png'))->toBeTrue();
    } finally {
        foreach ($roots as $root) {
            exec('rm -rf '.escapeshellarg($root));
        }
    }
})->with([
    'its fallback not configured' => 'unconfigured',
    'its primary not buildable' => 'unbuildable',
    'its fallback not configured, and no url' => 'unserved',
]);

/* ...and an alias of a private disk named with digits alone is said, as any other's is (review of slice 5c). */
it('says a served disk is the private disk under another name, when that is named with digits alone', function (): void {
    config([
        'filesystems.disks.7' => ['driver' => 'local', 'root' => Storage::disk(MediaDisks::PRIVATE)->path('')],
        'filesystems.disks.cdn' => ['driver' => 'local', 'root' => Storage::disk(MediaDisks::PRIVATE)->path(''), 'url' => 'https://cdn.example.test'],
        'kitsune.media.disks.private' => '7',
    ]);

    Artisan::call('kitsune:media-prune');
    $output = Artisan::output();

    expect($output)->toContain('Not scanning [cdn]: it is, or cannot be told apart from, [7]')
        ->and($output)->toContain('kitsune:media-reconcile --force refuses while the private disk is one of them.');
});

/* ...and one that cannot be told apart from a public disk named with digits alone is said, as any other's is (review of 5c). */
it('says a served disk cannot be told apart from a public disk named with digits alone', function (): void {
    $root = sys_get_temp_dir().'/kitsune-prune-store-'.bin2hex(random_bytes(4));
    mkdir($root, 0777, true);

    try {
        foreach (['9' => 'e', 'cdn' => 'f'] as $name => $endpoint) {
            $name = (string) $name;
            $config = ['driver' => 's3', 'bucket' => 'media', 'endpoint' => $endpoint, 'prefix' => 'site', 'url' => "https://n{$name}.example.test"];
            config(["filesystems.disks.{$name}" => $config]);
            $adapter = new RefusingDisk($root, $name);
            Storage::set($name, new FilesystemAdapter(new Filesystem($adapter), $adapter, $config));
        }

        config(['kitsune.media.disks.public' => '9']);
        $file = storedForPrune($this->imageType);
        DB::table('media_files')->where('id', $file->getKey())->update(['visibility' => 'public', 'disk' => '9']);
        Storage::disk(MediaDisks::PRIVATE)->delete($file->path);
        Storage::disk('9')->put($file->path, pruneFixtureBytes());

        foreach ([[], ['--force' => true]] as $options) {
            $exit = Artisan::call('kitsune:media-prune', $options);
            $output = Artisan::output();

            expect($output)->toContain('Not scanning [cdn]: it is, or cannot be told apart from, [9]')
                ->and(substr_count($output, 'Not scanning'))->toBe(1)
                ->and($output)->not->toContain('Extra copies')
                ->and(Storage::disk('9')->exists($file->path))->toBeTrue()
                ->and($exit)->toBe(0);
        }
    } finally {
        exec('rm -rf '.escapeshellarg($root));
    }
});

/* ...and an alias of the private disk that nothing serves is said without the refusal: reconcile repoints its rows. */
it('says an unserved disk a row names is the private disk under another name, and not that reconcile refuses', function (): void {
    config(['filesystems.disks.old-private' => ['driver' => 'local', 'root' => Storage::disk(MediaDisks::PRIVATE)->path('')]]);
    $file = storedForPrune($this->imageType);
    DB::table('media_files')->where('id', $file->getKey())->update(['disk' => 'old-private']);

    Artisan::call('kitsune:media-prune');
    $output = Artisan::output();

    expect(MediaDisks::servedDisks(config()))->not->toContain('old-private')
        ->and($output)->toContain('Not scanning [old-private]: it is, or cannot be told apart from, ['.MediaDisks::PRIVATE.']')
        ->and($output)->not->toContain('refuses while the private disk is one of them')
        ->and(Artisan::call('kitsune:media-reconcile', ['--force' => true]))->toBe(0)
        ->and(DB::table('media_files')->where('id', $file->getKey())->value('disk'))->toBe(MediaDisks::PRIVATE);
});

/*
 * ...and a `media` directory that is itself a link — media on another volume — is followed by the listing, as the disk's
 * root is: its files are removed like any others, a link below it still refused (review of slice 5c).
 */
it('removes orphans, partial and extra copies under a media directory that is a link', function (): void {
    $volumes = [sys_get_temp_dir().'/kitsune-prune-volume-'.bin2hex(random_bytes(4)), sys_get_temp_dir().'/kitsune-prune-pubvol-'.bin2hex(random_bytes(4))];
    $roots = [sys_get_temp_dir().'/kitsune-prune-linked-'.bin2hex(random_bytes(4)), sys_get_temp_dir().'/kitsune-prune-publinked-'.bin2hex(random_bytes(4))];

    try {
        foreach ([0, 1] as $i) {
            mkdir($volumes[$i], 0777, true);
            mkdir($roots[$i], 0777, true);
            symlink($volumes[$i], $roots[$i].'/media');
        }

        config([
            'filesystems.disks.'.MediaDisks::PRIVATE => [...config('filesystems.disks.'.MediaDisks::PRIVATE), 'root' => $roots[0]],
            'filesystems.disks.served' => ['driver' => 'local', 'root' => $roots[1], 'url' => 'https://served.example.test'],
        ]);
        Storage::forgetDisk(MediaDisks::PRIVATE);
        $kept = storedForPrune($this->imageType);
        $orphan = dirname($kept->path).'/orphan.png';
        Storage::disk(MediaDisks::PRIVATE)->put($orphan, 'orphan');
        Storage::disk(MediaDisks::PRIVATE)->put($kept->path.MediaBytes::PARTIAL, 'part');
        Storage::disk('served')->put($kept->path, pruneFixtureBytes());

        foreach ([1, 2] as $run) {
            $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);
            $output = Artisan::output();

            expect($output)->not->toContain('Could not remove')->and($exit)->toBe(0);

            if ($run === 1) {
                expect($output)->toContain('Removed 2 of 2 orphaned or leftover files and 1 of 1 extra copy');
            }
        }

        expect(is_file($volumes[0].'/'.substr($orphan, strlen('media/'))))->toBeFalse()
            ->and(is_file($volumes[0].'/'.substr($kept->path, strlen('media/')).MediaBytes::PARTIAL))->toBeFalse()
            ->and(is_file($volumes[1].'/'.substr($kept->path, strlen('media/'))))->toBeFalse()
            ->and(is_file($volumes[0].'/'.substr($kept->path, strlen('media/'))))->toBeTrue();
    } finally {
        foreach ([...$roots, ...$volumes] as $dir) {
            exec('rm -rf '.escapeshellarg($dir));
        }
    }
});

/*
 * ...and a read-through disk's half is never built to be compared: one whose root does not exist is compared at its
 * configured root, never created, and one with no root at all neither stops the run nor is created (review of slice 5c).
 */
it('builds no read-through half it compares, whose root does not exist', function (?bool $rooted): void {
    $fallback = sys_get_temp_dir().'/kitsune-prune-rt-missing-'.bin2hex(random_bytes(4));
    config([
        'filesystems.disks.rt-fallback' => ['driver' => 'local', ...($rooted ? ['root' => $fallback] : [])],
        'filesystems.disks.rt' => ['driver' => 'read-through', 'primary' => 'public', 'fallback' => 'rt-fallback', 'url' => 'https://rt.example.test'],
    ]);

    foreach ([[], ['--force' => true]] as $options) {
        $exit = Artisan::call('kitsune:media-prune', $options);

        expect(Artisan::output())->toContain('Not scanning [rt]')
            ->and(is_dir($fallback))->toBeFalse()
            ->and($exit)->toBe(0);
    }
})->with(['a root that does not exist' => true, 'no root at all' => false]);

/*
 * ...and a read-through disk whose halves name each other is refused as a cycle wherever it is compared, not followed
 * until memory runs out; unused, and so never built — Laravel could not build it either — it serves nothing, and stops
 * no run (review of slice 5c).
 */
it('refuses a read-through cycle where it is compared, and runs past one nothing uses', function (array $url): void {
    config([
        // With a url of its own it once read as served, and was built — which recursed until memory ran out (review of 5c).
        'filesystems.disks.loop-a' => ['driver' => 'read-through', 'primary' => 'loop-b', 'fallback' => 'public', ...$url],
        'filesystems.disks.loop-b' => ['driver' => 'read-through', 'primary' => 'loop-a', 'fallback' => 'public'],
    ]);
    storedForPrune($this->imageType);
    $limit = ini_get('memory_limit');
    // A missing guard fails the test, not by growing into a laptop's unlimited memory.
    ini_set('memory_limit', (string) (memory_get_usage(true) + 128 * 1024 * 1024));

    try {
        expect(fn () => MediaDisks::onePlace(config(), 'loop-a', 'public'))->toThrow(RuntimeException::class, 'its read-through disks form a cycle')
            ->and(fn () => MediaDisks::within(config(), 'loop-a', 'public', build: false))->toThrow(RuntimeException::class, 'its read-through disks form a cycle')
            ->and(MediaDisks::servedDisks(config()))->not->toContain('loop-a');

        $exit = Artisan::call('kitsune:media-prune');
        $output = Artisan::output();

        expect($output)->toContain('No orphaned media files.')
            ->and($output)->not->toContain('loop-a')
            ->and($exit)->toBe(0);
    } finally {
        ini_set('memory_limit', $limit);
    }
})->with(['with no url' => [[]], 'with a url of its own' => [['url' => 'https://loop.example.test']]]);

/* ...and one a row names is a listing that fails, saying why, never a run that recurses until memory runs out (5c). */
it('fails the listing of a read-through cycle a row names, and says why', function (): void {
    config([
        'filesystems.disks.loop-a' => ['driver' => 'read-through', 'primary' => 'loop-b', 'fallback' => 'public', 'url' => 'https://loop.example.test'],
        'filesystems.disks.loop-b' => ['driver' => 'read-through', 'primary' => 'loop-a', 'fallback' => 'public'],
    ]);
    $file = storedForPrune($this->imageType);
    DB::table('media_files')->where('id', $file->getKey())->update(['disk' => 'loop-a']);
    $limit = ini_get('memory_limit');
    ini_set('memory_limit', (string) (memory_get_usage(true) + 128 * 1024 * 1024));

    try {
        $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Could not list [loop-a]: ')
            ->and($output)->toContain('its read-through disks form a cycle')
            // Its row's other copy is kept as one whose row names a disk that could not be listed. A cycle is configured
            // as read-through, so the read-through arms, asked first, would claim it and advise taking a copy off through
            // halves never built (review of slice 5c).
            ->and($output)->toContain('kept: [loop-a], which its row names, could not be listed')
            ->and($output)->not->toContain('moves its row first')
            ->and(is_file(Storage::disk(MediaDisks::PRIVATE)->path($file->path)))->toBeTrue()
            ->and($exit)->toBe(1);
    } finally {
        ini_set('memory_limit', $limit);
    }
});

it('scans no served local disk that is the public disk under another name, and says nothing of it', function (): void {
    $file = storedForPrune($this->imageType);
    $rootless = sys_get_temp_dir().'/kitsune-prune-rootless-local-'.bin2hex(random_bytes(4));
    config([
        'filesystems.disks.public-alias' => ['driver' => 'local', 'root' => Storage::disk('public')->path(''), 'url' => 'https://alias.example.test'],
        // A local one whose root does not exist: asking whether it is the public disk would build it.
        'filesystems.disks.rootless-cdn' => ['driver' => 'local', 'root' => $rootless, 'url' => 'https://rootless.example.test'],
    ]);
    DB::table('media_files')->where('id', $file->getKey())->update(['visibility' => 'public', 'disk' => 'public']);
    Storage::disk(MediaDisks::PRIVATE)->delete($file->path);
    Storage::disk('public')->put($file->path, pruneFixtureBytes());

    $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);
    $output = Artisan::output();

    expect($output)->not->toContain('public-alias')
        ->and($output)->not->toContain('Extra copies')
        ->and(Storage::disk('public')->exists($file->path))->toBeTrue()
        ->and(is_dir($rootless))->toBeFalse()
        ->and($exit)->toBe(0);
});

/*
 * T111. A served disk that is another scanned disk under another name is not scanned, whichever disk it aliases — one a
 * row names, or another served disk — so each file is listed, and removed, once (review of slice 5b).
 */
it('lists a file once, however many served names reach the disk it is on', function (): void {
    $cdn = sys_get_temp_dir().'/kitsune-prune-cdn-'.bin2hex(random_bytes(4));
    $legacy = sys_get_temp_dir().'/kitsune-prune-legacy-'.bin2hex(random_bytes(4));
    mkdir($cdn, 0777, true);
    mkdir($legacy, 0777, true);

    try {
        config([
            'filesystems.disks.cdn' => ['driver' => 'local', 'root' => $cdn, 'url' => 'https://cdn.example.test'],
            'filesystems.disks.cdn-origin' => ['driver' => 'local', 'root' => $cdn, 'url' => 'https://origin.example.test'],
            'filesystems.disks.legacy' => ['driver' => 'local', 'root' => $legacy],
            'filesystems.disks.legacy-web' => ['driver' => 'local', 'root' => $legacy, 'url' => 'https://legacy.example.test'],
        ]);

        // A trashed file settled on the private disk, a stray copy of it on the served disk with two names.
        $trashed = storedForPrune($this->imageType);
        DB::table('entries')->where('id', $trashed->entry_id)->update(['deleted_at' => now()]);
        Storage::disk('cdn')->put($trashed->path, pruneFixtureBytes());

        // A private file whose row still names a legacy disk the web serves under another name.
        $legacyFile = storedForPrune($this->imageType);
        Storage::disk('legacy')->put($legacyFile->path, (string) Storage::disk(MediaDisks::PRIVATE)->get($legacyFile->path));
        Storage::disk(MediaDisks::PRIVATE)->delete($legacyFile->path);
        DB::table('media_files')->where('id', $legacyFile->getKey())->update(['disk' => 'legacy']);

        Artisan::call('kitsune:media-prune');
        $listed = Artisan::output();
        $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);
        $forced = Artisan::output();

        expect(substr_count($listed, $trashed->path))->toBe(1)
            ->and($listed)->not->toContain('legacy-web')
            ->and($listed)->not->toContain($legacyFile->path)
            ->and($forced)->toContain('and 1 of 1 extra copy.')
            ->and(is_file($cdn.'/'.$trashed->path))->toBeFalse()
            ->and(is_file($legacy.'/'.$legacyFile->path))->toBeTrue()
            ->and($exit)->toBe(0);
    } finally {
        exec('rm -rf '.escapeshellarg($cdn).' '.escapeshellarg($legacy));
    }
});

/*
 * T115. A disk a row names that is the public disk under another name is not scanned beside it — rows still naming
 * Laravel's `public` while the public disk is another name for its directory — so each file there is listed once, and
 * an orphan removed once (review of slice 5b).
 */
it('lists each file once when rows name the public disk under another name', function (): void {
    config([
        'filesystems.disks.media' => ['driver' => 'local', 'root' => Storage::disk('public')->path(''), 'url' => 'https://media.example.test'],
        'kitsune.media.disks.public' => 'media',
    ]);
    $orphan = 'media/'.$this->org->getKey().'/2026/09/orphan.png';
    Storage::disk('public')->put($orphan, 'bytes');
    $file = storedForPrune($this->imageType);
    Storage::disk('public')->put($file->path, pruneFixtureBytes());
    Storage::disk(MediaDisks::PRIVATE)->delete($file->path);
    DB::table('media_files')->where('id', $file->getKey())->update(['visibility' => 'public', 'disk' => 'public']);

    Artisan::call('kitsune:media-prune');
    $listed = Artisan::output();
    $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);
    $forced = Artisan::output();

    // Nothing listed on [public] itself: its rows' files are listed once, on [media], and awaiting publication.
    expect(substr_count($listed, $orphan))->toBe(1)
        // Neither an orphan on [public] nor an extra copy there; its row, awaiting publication, may name it.
        ->and($listed)->not->toMatch('/^  \[public\]  |^  entry \d+  \[public\]  \S+ — /m')
        ->and($listed)->toContain('kept: kitsune:media-reconcile moves its row first')
        ->and($forced)->toContain('Removed 1 of 1 orphaned or leftover file ')
        ->and(Storage::disk('public')->exists($orphan))->toBeFalse()
        ->and(hash('sha256', (string) Storage::disk('public')->get($file->path)))->toBe(hash('sha256', pruneFixtureBytes()))
        ->and($exit)->toBe(0);
});

/*
 * T117. The copy kept is on a disk only other rows name, which reconcile never asks: the advice is a hand copy, not a
 * reconcile that could not find it (Adam, decision 5; review of slice 5b).
 */
it('sends the operator to copy by hand a kept copy reconcile cannot reach', function (): void {
    $archive = sys_get_temp_dir().'/kitsune-prune-archive-'.bin2hex(random_bytes(4));
    mkdir($archive, 0777, true);

    try {
        config(['filesystems.disks.archive' => ['driver' => 'local', 'root' => $archive]]);
        // Another row names [archive], so prune lists it; custody, asked for this row, never does.
        $other = storedForPrune($this->imageType);
        Storage::disk('archive')->put($other->path, pruneFixtureBytes());
        Storage::disk(MediaDisks::PRIVATE)->delete($other->path);
        DB::table('media_files')->where('id', $other->getKey())->update(['disk' => 'archive']);

        $file = storedForPrune($this->imageType);
        DB::table('media_files')->where('id', $file->getKey())->update(['visibility' => 'public', 'disk' => 'public']);
        Storage::disk(MediaDisks::PRIVATE)->delete($file->path);
        Storage::disk('public')->put($file->path, 'changed by hand');
        Storage::disk('archive')->put($file->path, pruneFixtureBytes());

        $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Kept [archive:'.$file->path.'], entry '.$file->entry_id)
            ->and($output)->toContain('a disk kitsune:media-reconcile does not ask: copy it over [public] by hand')
            ->and($output)->not->toContain('--entry='.$file->entry_id.' --force rewrites')
            ->and(is_file($archive.'/'.$file->path))->toBeTrue()
            ->and($exit)->toBe(0);
    } finally {
        exec('rm -rf '.escapeshellarg($archive));
    }
});

/*
 * T118. Two media directories that nest are not two names for one: the outer disk would list the inner one's files
 * under longer paths as its own orphans, and remove a file a row names. Nothing is listed, and nothing removed (review
 * of slice 5b).
 */
it('lists nothing, and removes nothing, while a disk a row names nests inside the public disk', function (): void {
    $root = Storage::disk('public')->path('media/sub');
    mkdir($root, 0777, true);
    config(['filesystems.disks.nested' => ['driver' => 'local', 'root' => $root]]);
    $file = storedForPrune($this->imageType);
    Storage::disk('nested')->put($file->path, pruneFixtureBytes());
    Storage::disk(MediaDisks::PRIVATE)->delete($file->path);
    DB::table('media_files')->where('id', $file->getKey())->update(['visibility' => 'public', 'disk' => 'nested']);

    foreach ([[], ['--force' => true]] as $options) {
        $exit = Artisan::call('kitsune:media-prune', $options);

        expect(Artisan::output())->toContain('Refusing to list: the media directory of [nested] is inside [public]\'s')
            ->and($exit)->toBe(1)
            ->and(is_file($root.'/'.$file->path))->toBeTrue();
    }
});

/*
 * ...and so is a read-through disk whose fallback, written inline, lies inside the public disk's media directory: only
 * the loop over its places sees it — a named half is also asked by name — and asked of its primary alone, prune listed
 * the fallback's files as the public disk's orphans, and --force removed them (review of slice 5c).
 */
it('lists nothing, and removes nothing, while a read-through disk\'s inline fallback nests inside the public disk', function (): void {
    $primary = sys_get_temp_dir().'/kitsune-prune-arch-p-'.bin2hex(random_bytes(4));
    mkdir($primary, 0777, true);
    $archive = Storage::disk('public')->path('media/archive');
    mkdir($archive.'/media/1', 0777, true);
    file_put_contents($archive.'/media/1/archived.png', pruneFixtureBytes());

    try {
        config([
            'filesystems.disks.arch-p' => ['driver' => 'local', 'root' => $primary],
            'filesystems.disks.arch' => ['driver' => 'read-through', 'primary' => 'arch-p', 'fallback' => ['driver' => 'local', 'root' => $archive]],
        ]);

        foreach ([[], ['--force' => true]] as $options) {
            $exit = Artisan::call('kitsune:media-prune', $options);

            expect(Artisan::output())->toContain('Refusing to list: the media directory of [arch] is inside [public]\'s')
                ->and($exit)->toBe(1)
                ->and(is_file($archive.'/media/1/archived.png'))->toBeTrue();
        }
    } finally {
        exec('rm -rf '.escapeshellarg($primary));
    }
});

/*
 * ...and so is a read-through disk a driver of the host's own builds, whose half written inline lies inside the public
 * disk's media directory: only its instance names that half. Prune builds a disk it scans — one a row names, or one
 * served by its own url — to read its halves; read from its configuration alone it is one place of a driver nothing here
 * knows, nesting with nothing, and prune listed the half's file as the public disk's orphan, which --force removed —
 * a row's only file (review of slice 5c).
 */
it('lists nothing, and removes nothing, while a host-built read-through disk it scans has a half inside the public disk', function (bool $named): void {
    $half = Storage::disk('public')->path('media/sub');
    $fallback = sys_get_temp_dir().'/kitsune-prune-hostrt-f-'.bin2hex(random_bytes(4));
    mkdir($half, 0777, true);
    mkdir($fallback, 0777, true);

    try {
        Storage::extend('mirror', static fn ($app) => $app['filesystem']->createReadThroughDriver([
            'driver' => 'read-through',
            'primary' => ['driver' => 'local', 'root' => $half],
            'fallback' => ['driver' => 'local', 'root' => $fallback],
        ], 'hostrt'));
        config(['filesystems.disks.hostrt' => ['driver' => 'mirror', ...($named ? [] : ['url' => 'https://hostrt.example.test'])]]);

        if ($named) {
            // A row names the disk, and its only copy is on the half.
            $file = storedForPrune($this->imageType);
            $path = $file->path;
            Storage::disk(MediaDisks::PRIVATE)->delete($path);
            DB::table('media_files')->where('id', $file->getKey())->update(['visibility' => 'public', 'disk' => 'hostrt']);
        } else {
            $path = 'media/12/photo.jpg';
        }

        @mkdir(dirname($half.'/'.$path), 0777, true);
        file_put_contents($half.'/'.$path, pruneFixtureBytes());

        foreach ([[], ['--force' => true]] as $options) {
            $exit = Artisan::call('kitsune:media-prune', $options);

            expect(Artisan::output())->toContain('Refusing to list: the media directory of [hostrt] is inside [public]\'s')
                ->and($exit)->toBe(1)
                ->and(is_file($half.'/'.$path))->toBeTrue();
        }
    } finally {
        exec('rm -rf '.escapeshellarg($fallback));
    }
})->with(['named by a row' => true, 'served by its own url, named by none' => false]);

/*
 * T120-T121. Only a disk prune lists orphans on is refused for a disk nested inside it — every configured disk counted,
 * a host's that nothing names or serves included — while two nesting disks prune scans only for extra copies are each
 * scanned, since neither lists an orphan (review of slice 5b).
 */
it('scans two served disks that nest, since neither lists an orphan', function (): void {
    $root = sys_get_temp_dir().'/kitsune-prune-host-'.bin2hex(random_bytes(4));
    mkdir($root.'/media', 0777, true);

    try {
        config([
            'filesystems.disks.host-public' => ['driver' => 'local', 'root' => $root, 'url' => 'https://host.example.test'],
            'filesystems.disks.host-media' => ['driver' => 'local', 'root' => $root.'/media', 'url' => 'https://host.example.test/media'],
        ]);
        // A copy of a trashed file on the inner one, which is another directory, scanned for extra copies of its own.
        $file = storedForPrune($this->imageType);
        DB::table('entries')->where('id', $file->entry_id)->update(['deleted_at' => now()]);
        Storage::disk('host-media')->put($file->path, pruneFixtureBytes());

        $exit = Artisan::call('kitsune:media-prune');
        $output = Artisan::output();

        expect($output)->not->toContain('Refusing to list')
            ->and($output)->toMatch('/^  entry \d+  \[host-media\]  '.preg_quote($file->path, '/').' — /m')
            ->and($exit)->toBe(0);
    } finally {
        exec('rm -rf '.escapeshellarg($root));
    }
});

it('lists nothing while a disk nothing names or serves nests inside the public disk', function (): void {
    $root = Storage::disk('public')->path('media/host');
    mkdir($root, 0777, true);
    config(['filesystems.disks.host-archive' => ['driver' => 'local', 'root' => $root]]);
    Storage::disk('host-archive')->put('media/12/photo.jpg', 'the host\'s');

    $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);

    expect(Artisan::output())->toContain('Refusing to list: the media directory of [host-archive] is inside [public]\'s')
        ->and($exit)->toBe(1)
        ->and(is_file($root.'/media/12/photo.jpg'))->toBeTrue();
});

/*
 * T123. The public disk inside a served host disk's media directory is not refused: the host disk lists no orphans, so
 * it is scanned for extra copies — and one there, at a row's path, is kept for a hand, since custody takes a disk that
 * nests with the row's target for one place and refuses to remove it (review of slice 5b). Named with digits alone, the
 * disk came back an integer key, and the kept line stopped every run (review of slice 5c).
 */
it('scans a served disk the public disk nests inside, and keeps its copies for a hand', function (string $name): void {
    $host = sys_get_temp_dir().'/kitsune-prune-host-'.bin2hex(random_bytes(4));
    mkdir($host.'/media/site', 0777, true);

    try {
        config([
            "filesystems.disks.{$name}" => ['driver' => 'local', 'root' => $host, 'url' => 'https://host.example.test'],
            'filesystems.disks.site' => ['driver' => 'local', 'root' => $host.'/media/site', 'url' => 'https://site.example.test'],
            'kitsune.media.disks.public' => 'site',
        ]);
        $file = storedForPrune($this->imageType);
        DB::table('media_files')->where('id', $file->getKey())->update(['visibility' => 'public', 'disk' => 'site']);
        Storage::disk('site')->put($file->path, pruneFixtureBytes());
        Storage::disk(MediaDisks::PRIVATE)->delete($file->path);
        Storage::disk($name)->put($file->path, pruneFixtureBytes());
        // And one whose own disk does not hold it: the copy on the host may be its only one (review of 5b).
        $only = storedForPrune($this->imageType);
        DB::table('media_files')->where('id', $only->getKey())->update(['visibility' => 'public', 'disk' => 'site']);
        Storage::disk(MediaDisks::PRIVATE)->delete($only->path);
        Storage::disk($name)->put($only->path, pruneFixtureBytes());

        $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);
        $output = Artisan::output();

        expect($output)->not->toContain('Refusing to list')
            ->and($output)->toContain('kept: its media directory nests with [site]\'s — compare it with [site]\'s copy by hand')
            ->and($output)->toContain('kept: its media directory nests with [site]\'s, which does not list the file — copy it over by hand')
            ->and($output)->not->toContain('remove it by hand')
            ->and(is_file($host.'/'.$only->path))->toBeTrue()
            ->and($output)->not->toContain('removed, once asked again under the lock')
            ->and(is_file($host.'/'.$file->path))->toBeTrue()
            ->and($exit)->toBe(0);
    } finally {
        exec('rm -rf '.escapeshellarg($host));
    }
})->with(['a disk named as words are' => 'host', 'a disk named with digits alone' => '8']);

/*
 * T153. A nested copy whose target could not be listed the second time is kept as one that could not be listed, with no
 * advice to copy it by hand: nobody knows whether it is the only copy (review of slice 5c).
 */
it('keeps a nested copy whose target could not be listed again, and advises no hand copy', function (bool $force): void {
    $host = sys_get_temp_dir().'/kitsune-prune-host-'.bin2hex(random_bytes(4));
    mkdir($host.'/media/site', 0777, true);

    try {
        config([
            'filesystems.disks.host' => ['driver' => 'local', 'root' => $host, 'url' => 'https://host.example.test'],
            'filesystems.disks.site' => ['driver' => 'local', 'root' => $host.'/media/site', 'url' => 'https://site.example.test'],
            'kitsune.media.disks.public' => 'site',
        ]);
        $site = RefusingDisk::install('site', $host.'/media/site');
        $file = storedForPrune($this->imageType);
        DB::table('media_files')->where('id', $file->getKey())->update(['visibility' => 'public', 'disk' => 'site']);
        Storage::disk('site')->put($file->path, pruneFixtureBytes());
        Storage::disk(MediaDisks::PRIVATE)->delete($file->path);
        Storage::disk('host')->put($file->path, pruneFixtureBytes());
        // Listed once to find what is there, and once more for whether it holds this copy's path: that one fails.
        $site->failListing = 2;

        $exit = Artisan::call('kitsune:media-prune', $force ? ['--force' => true] : []);
        $output = Artisan::output();

        expect($output)->toContain('Could not list [site]')
            ->and($output)->toContain('kept: [site] could not be listed')
            ->and($output)->not->toContain('copy it over by hand')
            ->and(is_file($host.'/'.$file->path))->toBeTrue()
            ->and($exit)->toBe(1);
    } finally {
        exec('rm -rf '.escapeshellarg($host));
    }
})->with(['read-only' => false, 'forced' => true]);

/* T124. A configured local disk with no root cannot be built, and holds nothing: prune runs past it (review of 5b). */
it('runs past a configured local disk with no root', function (?string $root): void {
    config(['filesystems.disks.backups' => ['driver' => 'local', 'root' => $root]]);

    $exit = Artisan::call('kitsune:media-prune');

    expect(Artisan::output())->not->toContain('Refusing to list')
        ->and($exit)->toBe(0);
})->with(['no root' => [null], 'an empty root, as env() gives for a blank value' => ['']]);

/*
 * T127. A host's disk that nothing here names or serves is read from its configuration, not built: building one may need
 * a Flysystem package the install lacks — a read-only disk, a prefixed one — and prune stopped on every run (review of
 * slice 5b). Asserted as never built, not as a build that would throw: prune reads a disk it cannot build from its
 * configuration now, so a build that threw would pass unseen (review of slice 5c).
 */
it('runs past a host disk it could not build', function (array $extra): void {
    $root = sys_get_temp_dir().'/kitsune-prune-host-'.bin2hex(random_bytes(4));
    // Where the prefixed disk's own root resolves, so it can hold something and is asked at all.
    mkdir($root.'/archive', 0777, true);

    try {
        config(['filesystems.disks.host-archive' => ['driver' => 'local', 'root' => $root, ...$extra]]);

        $exit = Artisan::call('kitsune:media-prune');

        expect(Artisan::output())->not->toContain('Refusing to list')
            ->and($exit)->toBe(0)
            ->and((fn (): bool => array_key_exists('host-archive', $this->disks))->call(Storage::getFacadeRoot()))->toBeFalse();
    } finally {
        exec('rm -rf '.escapeshellarg($root));
    }
})->with(['read-only' => [['read-only' => true]], 'prefixed' => [['prefix' => 'archive']]]);

/*
 * ...and one a driver of the host's own builds, which nothing names or serves, is not built to ask whether it nests: only
 * a disk prune scans is, as the scan builds it anyway — building one created its root, and one whose halves led back to
 * it recursed until memory ran out (review of slice 5c).
 */
it('builds no host disk nothing names or serves to ask whether it nests', function (): void {
    $built = 0;
    $root = sys_get_temp_dir().'/kitsune-prune-host-unused-'.bin2hex(random_bytes(4));
    Storage::extend('mirror-counted', static function ($app) use (&$built, $root) {
        $built++;

        return $app['filesystem']->createReadThroughDriver(['driver' => 'read-through', 'primary' => ['driver' => 'local', 'root' => $root], 'fallback' => 'local']);
    });
    config(['filesystems.disks.host-mirror' => ['driver' => 'mirror-counted']]);

    try {
        $exit = Artisan::call('kitsune:media-prune');

        expect($built)->toBe(0)
            ->and(is_dir($root))->toBeFalse()
            ->and($exit)->toBe(0);
    } finally {
        exec('rm -rf '.escapeshellarg($root));
    }
});

/* ...and one it could not build that nests inside the public disk is still seen to nest: read, not skipped. */
it('still refuses a host disk it could not build that nests inside the public disk', function (): void {
    $root = Storage::disk('public')->path('media/host');
    mkdir($root, 0777, true);
    config(['filesystems.disks.host-archive' => ['driver' => 'local', 'root' => $root, 'read-only' => true]]);
    file_put_contents($root.'/photo.jpg', 'the host\'s');

    $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);

    expect(Artisan::output())->toContain('Refusing to list: the media directory of [host-archive] is inside [public]\'s')
        ->and(is_file($root.'/photo.jpg'))->toBeTrue()
        ->and($exit)->toBe(1);
});

/* T112. A disk that could not be listed was not asked: its rows' extra copies say so, not that it lacks the file. */
it('says a copy is kept because the disk its row names could not be listed, not because it lacks the file', function (): void {
    $file = storedForPrune($this->imageType);
    DB::table('media_files')->where('id', $file->getKey())->update(['visibility' => 'public', 'disk' => 'public']);
    Storage::disk('public')->put($file->path, pruneFixtureBytes());
    $root = Storage::disk('public')->path('');
    $adapter = new class($root) extends League\Flysystem\Local\LocalFilesystemAdapter
    {
        public function listContents(string $path, bool $deep): iterable
        {
            throw UnableToListContents::atLocation($path, $deep, new RuntimeException('the store timed out'));
        }
    };
    Storage::set('public', new LocalFilesystemAdapter(new Filesystem($adapter), $adapter, ['driver' => 'local', 'root' => $root]));

    $exit = Artisan::call('kitsune:media-prune');
    $output = Artisan::output();

    expect($output)->toContain('Could not list [public]')
        // Once: a disk that could not be listed is not listed again for whether it holds a copy (review of slice 5c).
        ->and(substr_count($output, 'Could not list [public]'))->toBe(1)
        ->and($output)->toContain('kept: [public] could not be listed')
        ->and($output)->not->toContain('does not hold the file')
        ->and($exit)->toBe(1);
});

/* T96. A read-only run hashes nothing: it lists, and says what --force would do with each extra copy. */
it('hashes nothing without --force, and says what --force would do', function (): void {
    $roots = [];

    try {
        foreach (['public', MediaDisks::PRIVATE] as $name) {
            $roots[$name] = sys_get_temp_dir().'/kitsune-prune-read-'.bin2hex(random_bytes(4));
            mkdir($roots[$name], 0777, true);
            RefusingDisk::install($name, $roots[$name]);
        }

        $file = storedForPrune($this->imageType);
        DB::table('media_files')->where('id', $file->getKey())->update(['visibility' => 'public', 'disk' => 'public']);
        Storage::disk(MediaDisks::PRIVATE)->delete($file->path);
        Storage::disk('public')->put($file->path, pruneFixtureBytes());
        Storage::disk(MediaDisks::PRIVATE)->put($file->path, pruneFixtureBytes());
        RefusingDisk::forgetLog();

        Artisan::call('kitsune:media-prune');
        $output = Artisan::output();

        expect(array_filter(RefusingDisk::$log, static fn (array $entry): bool => in_array($entry['event'], ['checksum', 'read', 'readStream'], true)))->toBe([])
            ->and($output)->toContain('With --force')
            ->and($output)->toContain('1 removable extra copy listed and nothing removed');
    } finally {
        foreach ($roots as $root) {
            exec('rm -rf '.escapeshellarg($root));
        }
    }
});

/* T97. Extra copies alone are reason enough for a forced run to act: no orphan and no partial need be there. */
it('removes extra copies when there is nothing else to remove', function (): void {
    $file = storedForPrune($this->imageType);
    DB::table('media_files')->where('id', $file->getKey())->update(['visibility' => 'public', 'disk' => 'public']);
    Storage::disk(MediaDisks::PRIVATE)->delete($file->path);
    Storage::disk('public')->put($file->path, pruneFixtureBytes());
    Storage::disk(MediaDisks::PRIVATE)->put($file->path, pruneFixtureBytes());

    $this->artisan('kitsune:media-prune --force')
        ->expectsOutputToContain('Removed 0 of 0 orphaned or leftover files and 1 of 1 extra copy.')
        ->assertSuccessful();

    Storage::disk(MediaDisks::PRIVATE)->assertMissing($file->path);
    Storage::disk('public')->assertExists($file->path);
});

/**
 * MySQL and MariaDB keep an AUTO_INCREMENT an explicit id raised when the test's transaction rolls back, so every later
 * test's entries would take ids past it; PostgreSQL's sequence is not raised, and SQLite's is rolled back.
 */
function pruneWithoutKeptAutoIncrement(): void
{
    // On MySQL and MariaDB a positive explicit id raises AUTO_INCREMENT past the rollback, for every later test; a negative
    // one is out of range for BIGINT UNSIGNED; and an explicit 0 into AUTO_INCREMENT takes the next id.
    if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        test()->markTestSkipped('an explicit entry id raises AUTO_INCREMENT past the rollback, a negative one is out of range for BIGINT UNSIGNED, and 0 takes the next id');
    }
}

/**
 * Rows copied from one stored file, each naming a path of its own on a disk — the table at scale without an upload each.
 *
 * @param  array<string, mixed>  $row  columns to set on each copy
 * @return list<string> their paths
 */
function pruneRows(MediaFile $template, int $count, string $disk, string $name, array $row = []): array
{
    $entry = (array) DB::table('entries')->where('id', $template->entry_id)->first();
    $file = (array) DB::table('media_files')->where('id', $template->getKey())->first();
    unset($entry['id'], $file['id']);
    $paths = [];

    foreach (range(1, $count) as $n) {
        $id = DB::table('entries')->insertGetId([...$entry, 'title' => "{$name} {$n}"]);
        $paths[] = $path = sprintf('media/%d/2026/09/%s-%04d.png', $template->org_id ?? 1, $name, $n);
        DB::table('media_files')->insert([...$file, 'entry_id' => $id, 'disk' => $disk, 'path' => $path, ...$row]);
    }

    return $paths;
}

/*
 * T135-T142, T144, T146, T151, T152, T154-T157, T160-T165, T169, T171, T176, T182. Prune reads rows in batches (Adam, decision 11, 2026-09-26): the disks rows name, then the rows naming
 * a batch of listed files, as each disk lists them — holding what it lists and no row besides — and lists exactly what it
 * listed when it held every row.
 */
describe('reading in batches', function (): void {
    beforeEach(function (): void {
        $this->root = sys_get_temp_dir().'/kitsune-prune-batch-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        config(['filesystems.disks.'.MediaDisks::PRIVATE.'.root' => $this->root]);
        $this->private = RefusingDisk::install(MediaDisks::PRIVATE, $this->root);
    });

    afterEach(function (): void {
        exec('rm -rf '.escapeshellarg($this->root));
    });

    // T135: three batches of one disk, every kind of file across their edges — asked of the table as each is listed.
    it('classifies a disk\'s files a batch at a time, as it lists them, and bounds every read of the table', function (): void {
        $org = $this->org->getKey();
        $template = storedForPrune($this->imageType);
        $claimed = pruneRows($template, 700, MediaDisks::PRIVATE, 'claimed');
        $extras = pruneRows($template, 20, 'public', 'extra', ['visibility' => 'public']);
        $orphans = array_map(static fn (int $n): string => sprintf('media/%d/2026/09/orphan-%04d.png', $org, $n), range(1, 300));
        $partials = array_map(static fn (string $path): string => $path.MediaBytes::PARTIAL, array_slice($claimed, 0, 30));

        foreach ([...$claimed, ...$orphans, ...$partials, ...$extras] as $path) {
            Storage::disk(MediaDisks::PRIVATE)->put($path, 'bytes');
        }

        foreach ($extras as $path) {
            Storage::disk('public')->put($path, 'bytes');
        }

        // 1 + 700 + 300 + 30 + 20 = 1,051 files on core's private disk: three batches.
        $this->private->logListing = true;
        RefusingDisk::forgetLog();
        $reads = [];
        DB::listen(function ($query) use (&$reads): void {
            if (preg_match('/\bfrom\W+media_files\b/i', $query->sql) === 1) {
                $reads[] = ['sql' => strtolower($query->sql), 'bindings' => count($query->bindings)];
            }

            if (preg_match('/path\W{0,2} in \(/i', $query->sql) === 1) {
                RefusingDisk::note('lookup');
            }
        });

        $exit = Artisan::call('kitsune:media-prune');
        $output = Artisan::output();

        // From the private disk's first listed file on: the public disk was listed, and asked, before it.
        $listed = 0;
        $lookups = [];

        foreach (array_slice(RefusingDisk::$log, (int) array_search('list', array_column(RefusingDisk::$log, 'event'), true)) as $event) {
            match ($event['event']) {
                'list' => $listed++,
                'lookup' => $lookups[] = $listed,
                default => null,
            };
        }

        expect($lookups)->toBe([MediaPruneCommand::BATCH, 2 * MediaPruneCommand::BATCH, 1051])
            ->and($output)->toContain('300 orphaned files, 30 leftover partial copies and 20 removable extra copies listed and nothing removed')
            ->and($output)->not->toContain($claimed[699].' ')
            ->and($exit)->toBe(0);

        foreach ([...$orphans, ...$partials, ...$extras] as $path) {
            expect($output)->toContain($path);
        }

        // Every read of the table is a batch: a limit, or the lookup of a batch's paths — at most two keys a listed file,
        // each asked twice on SQLite, as TEXT and as a BLOB (review of slice 5c).
        $perFile = DB::connection()->getDriverName() === 'sqlite' ? 4 : 2;

        foreach ($reads as $read) {
            expect(str_contains($read['sql'], 'limit') || (preg_match('/path\W{0,2} in \(/', $read['sql']) === 1 && $read['bindings'] <= $perFile * MediaPruneCommand::BATCH))
                ->toBeTrue($read['sql']);
        }
    });

    // T136: a listing that fails part-way, after a whole batch was classified, contributes nothing.
    it('lists nothing from a disk whose listing fails after a batch was classified, and removes nothing', function (): void {
        $orphans = array_map(fn (int $n): string => sprintf('media/%d/2026/09/orphan-%04d.png', $this->org->getKey(), $n), range(1, MediaPruneCommand::BATCH + 100));

        foreach ($orphans as $path) {
            Storage::disk(MediaDisks::PRIVATE)->put($path, 'bytes');
        }

        $this->private->failListingAfter = MediaPruneCommand::BATCH + 50;

        $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Could not list ['.MediaDisks::PRIVATE.']')
            ->and($output)->toContain('No orphaned media files.')
            ->and($output)->not->toContain('orphan-')
            ->and($exit)->toBe(1)
            ->and(count(array_filter($orphans, fn (string $path): bool => is_file($this->root.'/'.$path))))->toBe(count($orphans));
    });

    // T137: the table's failure is the table's, not a disk that could not be listed.
    it('lets a lookup that fails fail the run, rather than report a disk it could not list', function (): void {
        Storage::disk(MediaDisks::PRIVATE)->put('media/1/2026/09/orphan.png', 'bytes');
        DB::connection()->beforeExecuting(static function (string $query): void {
            if (preg_match('/path\W{0,2} in \(/i', $query) === 1) {
                throw new RuntimeException('the lookup failed');
            }
        });

        expect(fn () => Artisan::call('kitsune:media-prune'))->toThrow(RuntimeException::class, 'the lookup failed');
    });

    // T138: by disk in the order they are scanned, and by path within each — however the disk itself lists.
    it('lists and removes by disk in scan order and by path within each, whatever order a disk lists in', function (): void {
        $publicRoot = sys_get_temp_dir().'/kitsune-prune-batch-public-'.bin2hex(random_bytes(4));
        mkdir($publicRoot, 0777, true);

        try {
            $public = RefusingDisk::install('public', $publicRoot);
            $public->reverseListing = true;
            $this->private->reverseListing = true;
            $template = storedForPrune($this->imageType);
            [$e, $f] = pruneRows($template, 2, 'public', 'extra', ['visibility' => 'public']);
            [$p1, $p2] = array_map(static fn (string $path): string => $path.MediaBytes::PARTIAL, pruneRows($template, 2, MediaDisks::PRIVATE, 'partial'));

            foreach ([$e, $f] as $path) {
                Storage::disk('public')->put($path, 'bytes');
                Storage::disk(MediaDisks::PRIVATE)->put($path, 'bytes');
            }

            foreach ([$p1, $p2] as $path) {
                Storage::disk(MediaDisks::PRIVATE)->put($path, 'half');
            }

            $at = fn (string $name): string => 'media/'.$this->org->getKey().'/2026/09/'.$name.'.png';

            foreach (['b', 'd'] as $name) {
                Storage::disk('public')->put($at($name), 'bytes');
            }

            foreach (['a', 'c'] as $name) {
                Storage::disk(MediaDisks::PRIVATE)->put($at($name), 'bytes');
            }

            Artisan::call('kitsune:media-prune');
            $output = Artisan::output();
            $order = static fn (array $paths): array => array_map(static fn (string $path): int|false => strpos($output, $path), $paths);

            $expected = [$at('b'), $at('d'), $at('a'), $at('c'), $p1, $p2, $e, $f];

            expect($order($expected))->toBe(collect($order($expected))->sort()->values()->all());

            RefusingDisk::forgetLog();
            Artisan::call('kitsune:media-prune', ['--force' => true]);

            // Custody's own clearing of a partial copy beside a path it removes is not a removal of prune's.
            expect(array_values(array_column(array_filter(RefusingDisk::$log, static fn (array $event): bool => $event['event'] === 'delete'
                && (! str_ends_with((string) $event['path'], MediaBytes::PARTIAL) || in_array($event['path'], [$p1, $p2], true))), 'path')))
                ->toBe($expected);
        } finally {
            exec('rm -rf '.escapeshellarg($publicRoot));
        }
    });

    // T139: a file is claimed by a row naming exactly its path, whatever the engine's collation matches it to.
    it('claims a file only for a row naming exactly its path, on every engine', function (): void {
        $file = storedForPrune($this->imageType);
        $upper = (string) preg_replace_callback('#[^/]+$#', static fn (array $name): string => strtoupper($name[0]), $file->path);
        DB::table('media_files')->where('id', $file->getKey())->update(['path' => $upper]);
        // Beside the row's own path, with a trailing space: another name, even on a volume that folds case.
        Storage::disk(MediaDisks::PRIVATE)->put($upper.' ', 'bytes');

        $exit = Artisan::call('kitsune:media-prune');
        $output = Artisan::output();

        expect($output)->toContain($file->path)
            ->and($output)->toContain('2 orphaned files')
            ->and($output)->not->toContain('Extra copies')
            ->and($exit)->toBe(0);
    });

    // T140: two disks rows name that differ only in case are two disks, as the disks' own lookup has them.
    it('keeps apart two disks rows name that differ only in case', function (): void {
        Storage::fake('legacy');
        $a = storedForPrune($this->imageType);
        $b = storedForPrune($this->imageType);
        DB::table('media_files')->where('id', $a->getKey())->update(['disk' => 'legacy']);
        DB::table('media_files')->where('id', $b->getKey())->update(['disk' => 'LEGACY']);
        Storage::disk('legacy')->put('media/1/2026/09/left-behind.png', 'bytes');

        $exit = Artisan::call('kitsune:media-prune');
        $output = Artisan::output();

        expect($output)->toContain('left-behind.png')
            ->and($output)->toContain('Could not list [LEGACY]')
            ->and($exit)->toBe(1);
    });

    // T141: a name PostgreSQL cannot hold — not UTF-8, which MySQL and MariaDB cannot hold either, or holding a NUL, which
    // they can but no row names — is never sent to the table but on SQLite, and is matched to no row.
    it('lists a file whose name no row can hold or name, and classifies the rest of its batch', function (): void {
        $kept = storedForPrune($this->imageType);
        $bad = 'media/'.$this->org->getKey().'/2026/09/caf'."\xe9".'.png';
        $nul = 'media/'.$this->org->getKey().'/2026/09/a'."\0".'b.png';
        $this->private->phantoms = [$bad, $bad.MediaBytes::PARTIAL, $nul];
        $sent = [];
        DB::listen(function ($query) use (&$sent): void {
            foreach ($query->bindings as $binding) {
                if (is_string($binding) && (! mb_check_encoding($binding, 'UTF-8') || str_contains($binding, "\0"))) {
                    $sent[] = $binding;
                }
            }
        });
        $cdnRoot = sys_get_temp_dir().'/kitsune-prune-batch-cdn-'.bin2hex(random_bytes(4));
        mkdir($cdnRoot, 0777, true);

        try {
            config(['filesystems.disks.old-cdn' => ['driver' => 'local', 'root' => $cdnRoot, 'url' => 'https://cdn.example.test']]);
            RefusingDisk::install('old-cdn', $cdnRoot)->phantoms = ['media/host/'."\xff".'.png'];

            $exit = Artisan::call('kitsune:media-prune');
            $output = Artisan::output();

            expect($output)->toContain($bad)
                ->and($output)->toContain('3 orphaned files, 0 leftover partial copies')
                ->and($output)->not->toContain('media/host/')
                ->and($output)->not->toContain($kept->path)
                // Never sent where the engine cannot hold it; SQLite compares bytes within a storage class, and is asked.
                ->and($sent === [])->toBe(DB::connection()->getDriverName() !== 'sqlite')
                ->and($exit)->toBe(0);
        } finally {
            exec('rm -rf '.escapeshellarg($cdnRoot));
        }
    });

    /*
     * T171. A name Flysystem reads as another path — `.`, `..` or `//` in an object store's key, or in a local file's
     * whose backslashes the listing gives as `/` — is listed, and removed on no run: removing it removed the path it is
     * read as, a row's only file, or a file outside the media directory (review of slice 5c).
     */
    it('removes no file whose name every disk reads as another path, and fails on it', function (): void {
        $kept = storedForPrune($this->imageType);
        $name = basename($kept->path);
        $beside = dirname($this->root.'/'.$kept->path).'/y\\..\\'.$name;
        $outside = $this->root.'/media/a\\..\\..\\host-file.txt';
        file_put_contents($beside, 'another file');
        file_put_contents($outside, 'another file');
        file_put_contents($this->root.'/host-file.txt', 'the host\'s');
        $listed = [dirname($kept->path).'/y/../'.$name, 'media/a/../../host-file.txt'];

        $exit = Artisan::call('kitsune:media-prune');
        $output = Artisan::output();

        expect($output)->toContain('['.MediaDisks::PRIVATE.']  '.$listed[0])
            ->and($output)->toContain('['.MediaDisks::PRIVATE.']  '.$listed[1])
            ->and($exit)->toBe(0);

        $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);
        $output = Artisan::output();

        foreach ($listed as $path) {
            expect($output)->toContain('Could not remove ['.MediaDisks::PRIVATE.':'.$path.']: Refusing to go on with ['.$path.'] on the ['.MediaDisks::PRIVATE.'] disk: every disk reads its name as another path');
        }

        expect($output)->toContain('Removed 0 of 2 orphaned or leftover files')
            ->and(is_file($this->root.'/'.$kept->path))->toBeTrue()
            ->and(is_file($this->root.'/host-file.txt'))->toBeTrue()
            ->and(is_file($beside))->toBeTrue()
            ->and(is_file($outside))->toBeTrue()
            ->and($exit)->toBe(1);
    });

    /*
     * ...and a local name whose backslashes alone the listing changed, which reads as itself and holds nothing: removing
     * it removed nothing and said it had, on every run. An orphan or a partial copy, it fails every forced run, and stays
     * for a hand (review of slice 5c).
     */
    it('removes no file listed under a name the disk does not hold it by, and fails on it', function (string $kind): void {
        $kept = storedForPrune($this->imageType);
        $held = $this->root.'/'.$kept->path;

        if ($kind === 'extra') {
            // A public row settled on public: the backslash name is listed as its path, an extra copy on core's disk.
            DB::table('media_files')->where('id', $kept->getKey())->update(['disk' => 'public', 'visibility' => 'public']);
            Storage::disk('public')->put($kept->path, pruneFixtureBytes());
            Storage::disk(MediaDisks::PRIVATE)->delete($kept->path);
            $held = Storage::disk('public')->path($kept->path);
        }

        $name = match ($kind) {
            'orphan' => dirname($kept->path).'/x\\y.png',
            'partial' => 'media/'.str_replace('/', '\\', substr($kept->path, strlen('media/'))).MediaBytes::PARTIAL,
            default => 'media/'.str_replace('/', '\\', substr($kept->path, strlen('media/'))),
        };
        file_put_contents($this->root.'/'.$name, 'another file');
        $listed = str_replace('\\', '/', $name);

        $exit = Artisan::call('kitsune:media-prune');

        expect(Artisan::output())->toContain('['.MediaDisks::PRIVATE.']  '.$listed)->and($exit)->toBe(0);

        foreach ([1, 2] as $run) {
            $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);
            $output = Artisan::output();

            expect($output)->toContain('Could not remove ['.MediaDisks::PRIVATE.':'.$listed.']: Refusing to go on with ['.$listed.'] on the ['.MediaDisks::PRIVATE.'] disk: the disk holds no file under the name it was listed by')
                ->and($output)->toContain($kind === 'extra' ? '0 of 1 extra copy' : 'Removed 0 of 1 orphaned or leftover file')
                ->and(is_file($this->root.'/'.$name))->toBeTrue()
                ->and(is_file($held))->toBeTrue()
                ->and($exit)->toBe(1);
        }
    })->with(['an orphan' => 'orphan', 'a partial copy' => 'partial', 'an extra copy' => 'extra']);

    /*
     * ...and a name no disk can be asked about as itself is refused for that, before any disk is asked of it: one
     * Flysystem refuses, or one whose normalized path holds nothing, was told it was gone since the listing, or met the
     * path's own refusal first (review of slice 5c).
     */
    it('refuses a listed name no disk can name as itself, and says why', function (string $name, string $why): void {
        $this->private->phantoms = [$name];

        $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);

        expect(Artisan::output())->toContain('Could not remove ['.MediaDisks::PRIVATE.':'.$name.']: Refusing to go on with ['.$name.'] on the ['.MediaDisks::PRIVATE.'] disk: '.$why)
            ->and($exit)->toBe(1);
    })->with([
        'a zero-width space' => ['media/1/zw'."\u{200b}".'.png', 'every disk refuses its name'],
        'a tab' => ['media/1/tab'."\t".'.png', 'every disk refuses its name'],
        'an empty segment over nothing' => ['media/9//nothing.png', 'every disk reads its name as another path'],
    ]);

    /*
     * ...nor one reached through a link the listing skipped on a directory above it: `a\\b.png`, listed as `a/b.png`,
     * reached a file outside the media directory through `a` (review of slice 5c).
     */
    it('removes nothing through a directory link its listing skipped', function (string $kind): void {
        $kept = storedForPrune($this->imageType);
        $outside = sys_get_temp_dir().'/kitsune-prune-outside-'.bin2hex(random_bytes(4));
        mkdir($outside, 0777, true);

        try {
            config(['filesystems.disks.'.MediaDisks::PRIVATE.'.links' => 'skip']);
            Storage::forgetDisk(MediaDisks::PRIVATE);
            $dir = dirname($kept->path);

            if ($kind === 'extra') {
                // A public row settled on public: the name through the link is its path, an extra copy on core's disk.
                DB::table('media_files')->where('id', $kept->getKey())->update(['disk' => 'public', 'visibility' => 'public']);
                Storage::disk('public')->put($kept->path, pruneFixtureBytes());
            }

            $leaf = match ($kind) {
                'orphan' => 'b.png',
                'partial' => basename($kept->path).MediaBytes::PARTIAL,
                default => basename($kept->path),
            };
            // For a partial or extra copy, the row's own name through the link: its directory is the linked one.
            $linked = $kind === 'orphan' ? $dir.'/a' : $dir;
            file_put_contents($outside.'/'.$leaf, 'the host\'s');

            if ($kind === 'orphan') {
                symlink($outside, $this->root.'/'.$linked);
                file_put_contents($this->root.'/'.$dir.'/a\\'.$leaf, 'another file');
            } else {
                $parent = dirname($this->root.'/'.$dir);
                rename($this->root.'/'.$dir, $parent.'/kept');
                symlink($outside, $this->root.'/'.$dir);
                file_put_contents($parent.'/'.basename($dir).'\\'.$leaf, 'another file');
            }

            foreach ([1, 2] as $run) {
                $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);

                expect(Artisan::output())->toContain('the disk holds no file under the name it was listed by')
                    ->and(is_file($outside.'/'.$leaf))->toBeTrue()
                    ->and($exit)->toBe(1);
            }
        } finally {
            exec('rm -rf '.escapeshellarg($outside));
        }
    })->with(['an orphan' => 'orphan', 'a partial copy' => 'partial', 'an extra copy' => 'extra']);

    // ...and a partial copy's name is asked whether any disk can name it as itself before any disk is asked, as an orphan's
    // is: its row path written straight to the table, one the unique-path migration refused (review of slice 5c).
    it('refuses a partial copy\'s name no disk can name as itself, and says why', function (string $rowPath, string $why): void {
        $kept = storedForPrune($this->imageType);
        DB::table('media_files')->where('id', $kept->getKey())->update(['path' => $rowPath]);
        $partial = $rowPath.MediaBytes::PARTIAL;
        $this->private->phantoms = [$partial];

        Artisan::call('kitsune:media-prune');

        expect(Artisan::output())->toContain('entry '.$kept->entry_id.'  ['.MediaDisks::PRIVATE.']  '.$partial);

        $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);

        expect(Artisan::output())->toContain('Could not remove ['.MediaDisks::PRIVATE.':'.$partial.']: Refusing to go on with ['.$partial.'] on the ['.MediaDisks::PRIVATE.'] disk: '.$why)
            ->and($exit)->toBe(1);
    })->with([
        'an empty segment' => ['media/9//x.png', 'every disk reads its name as another path'],
        'a parent segment' => ['media/9/y/../x.png', 'every disk reads its name as another path'],
        'a zero-width space' => ['media/1/zw'."\u{200b}".'.png', 'every disk refuses its name'],
    ]);

    /*
     * T176. A listed name the volume reaches as a row's own file — in another case, or another Unicode normalization — is
     * kept: the lookup under the lock compares bytes on SQLite and PostgreSQL, found no row, and the row's own file was
     * removed (review of slice 5c). Made on any volume: renamed where the volume folds the two, and where it does not, a
     * hard link — two names for one file either way.
     */
    it('keeps a name the volume reaches as a row\'s own file', function (string $form): void {
        // ...its row's path stored as a BLOB too, which a lookup by each spelling asks for as well (review of slice 5c).
        $blob = str_ends_with($form, ' blob');
        $form = $blob ? substr($form, 0, -strlen(' blob')) : $form;

        if ($blob && DB::connection()->getDriverName() !== 'sqlite') {
            test()->markTestSkipped('only SQLite keeps a value\'s storage class apart from its column\'s type');
        }

        $kept = storedForPrune($this->imageType);
        $dir = dirname($kept->path);
        $cafe = 'caf'."\u{e9}".'.png';
        // The row's spelling — a host's import may have written any of these — and the name the disk holds instead.
        [$path, $variant] = match ($form) {
            'case' => [$kept->path, $dir.'/'.strtoupper(basename($kept->path))],
            'nfd name' => [$dir.'/'.$cafe, $dir.'/'.Normalizer::normalize($cafe, Normalizer::FORM_D)],
            'nfc name' => [$dir.'/'.Normalizer::normalize($cafe, Normalizer::FORM_D), $dir.'/'.$cafe],
            default => [$dir.'/'.$cafe, $dir.'/'.Normalizer::normalize(mb_strtoupper($cafe), Normalizer::FORM_D)],
        };

        if ($path !== $kept->path) {
            rename($this->root.'/'.$kept->path, $this->root.'/'.$path);
            DB::table('media_files')->where('id', $kept->getKey())->update(['path' => $path]);
        }

        $folds = @stat($this->root.'/'.$variant) !== false;
        $folds ? rename($this->root.'/'.$path, $this->root.'/'.$variant) : link($this->root.'/'.$path, $this->root.'/'.$variant);

        // A volume that reaches the two as one file may keep the name as first written — case-sensitive APFS does, for a
        // rename between normalizations: then no name is listed but the row's, and there is nothing to ask.
        if (! in_array(basename($variant), scandir(dirname($this->root.'/'.$variant)) ?: [], true)) {
            test()->markTestSkipped('this volume keeps the name as first written');
        }

        if ($blob) {
            DB::update('update media_files set path = cast(path as blob) where id = ?', [$kept->getKey()]);
        }

        $exit = Artisan::call('kitsune:media-prune');

        // Listed as an orphan, by count: the console prints a name in NFC, whatever the disk holds it as.
        expect(Artisan::output())->toContain('1 orphaned file, 0 leftover partial copies')->and($exit)->toBe(0);

        $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);

        expect(Artisan::output())->toContain('1 kept: under the lock a row claimed its path')
            ->and(is_file($this->root.'/'.$variant))->toBeTrue()
            ->and(is_file($this->root.'/'.$path))->toBeTrue()
            ->and($exit)->toBe(0);
    })->with([
        'another case' => 'case',
        'the name in NFD, the row in NFC' => 'nfd name',
        'the name in NFC, the row in NFD' => 'nfc name',
        'the name upper case in NFD, the row lower case in NFC' => 'upper nfd name',
        'another case, the row\'s path a BLOB' => 'case blob',
    ]);

    // ...a partial copy's the same, by the path it was written for — where the volume folds the two, the one case in
    // which the name is listed as an orphan rather than a partial copy of the row.
    it('keeps a partial copy\'s name the volume reaches under the row\'s own spelling', function (): void {
        $kept = storedForPrune($this->imageType);
        $partial = $kept->path.MediaBytes::PARTIAL;
        $variant = dirname($kept->path).'/'.strtoupper(basename($kept->path)).MediaBytes::PARTIAL;
        file_put_contents($this->root.'/'.$partial, 'part');

        if (@stat($this->root.'/'.$variant) === false) {
            test()->markTestSkipped('this volume does not fold case');
        }

        rename($this->root.'/'.$partial, $this->root.'/'.$variant);

        $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);

        expect(Artisan::output())->toContain('1 kept: under the lock a row claimed its path')
            ->and(is_file($this->root.'/'.$variant))->toBeTrue()
            ->and($exit)->toBe(0);
    });

    // ...and a name that is another file — beside the row's own on a volume that does not fold the two — is not kept for
    // spelling alike: on SQLite and PostgreSQL it goes, while MySQL and MariaDB's collation claims it (review of 5c).
    it('removes another file whose name differs from a row\'s only in case, where the volume keeps them apart', function (): void {
        $kept = storedForPrune($this->imageType);
        $variant = dirname($kept->path).'/'.strtoupper(basename($kept->path));

        if (@stat($this->root.'/'.$variant) !== false) {
            test()->markTestSkipped('this volume folds case: the two names are one file');
        }

        file_put_contents($this->root.'/'.$variant, 'another file');
        $collates = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);

        $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);

        expect(Artisan::output())->toContain($collates ? '1 kept: under the lock a row claimed its path' : 'Removed 1 of 1 orphaned or leftover file')
            ->and(is_file($this->root.'/'.$variant))->toBe($collates)
            ->and(is_file($this->root.'/'.$kept->path))->toBeTrue()
            ->and($exit)->toBe(0);
    });

    /*
     * T142. Whether the disk an extra copy belongs on holds its path is read from that disk's own listing, taken once more
     * for the paths prune holds — once however many disks hold a path, and only where the answer changes what is said;
     * one that cannot be listed then fails the run, and is never said to lack the file (review of slice 5c, twice).
     */
    it('lists nothing again for a row that names another disk than the one its file belongs on', function (): void {
        $legacyRoot = sys_get_temp_dir().'/kitsune-prune-batch-legacy-'.bin2hex(random_bytes(4));
        $publicRoot = sys_get_temp_dir().'/kitsune-prune-batch-public-'.bin2hex(random_bytes(4));
        mkdir($legacyRoot, 0777, true);
        mkdir($publicRoot, 0777, true);

        try {
            config(['filesystems.disks.legacy' => ['driver' => 'local', 'root' => $legacyRoot]]);
            $legacy = RefusingDisk::install('legacy', $legacyRoot);
            // The disk the file belongs on, which the guard decides whether to list again — watched, and holding the path.
            $public = RefusingDisk::install('public', $publicRoot);
            $file = storedForPrune($this->imageType);
            DB::table('media_files')->where('id', $file->getKey())->update(['disk' => 'legacy', 'visibility' => 'public']);
            Storage::disk('legacy')->put($file->path, 'bytes');
            Storage::disk('public')->put($file->path, 'bytes');
            $legacy->unknown = [$file->path];
            RefusingDisk::forgetLog();

            $exit = Artisan::call('kitsune:media-prune');
            $output = Artisan::output();

            expect(substr_count($output, 'kept: kitsune:media-reconcile moves its row first'))->toBe(2)
                ->and($output)->not->toContain('removed, once asked again under the lock')
                ->and($public->listings)->toBe(1)
                ->and(array_filter(RefusingDisk::$log, static fn (array $event): bool => $event['event'] === 'fileExists'))->toBe([])
                ->and($exit)->toBe(0);
        } finally {
            exec('rm -rf '.escapeshellarg($legacyRoot).' '.escapeshellarg($publicRoot));
        }
    });

    /*
     * ...and the disk's own orphans and partial copies are as its first listing left them: none, when that failed; listed
     * in full, and with --force removed, when only the second did — which says it is the second (review of slice 5c).
     */
    it('fails, and keeps the copy, when the disk it belongs on cannot be listed, first or again', function (bool $force, int $failing): void {
        $publicRoot = sys_get_temp_dir().'/kitsune-prune-batch-public-'.bin2hex(random_bytes(4));
        mkdir($publicRoot, 0777, true);

        try {
            $public = RefusingDisk::install('public', $publicRoot);
            $file = storedForPrune($this->imageType);
            DB::table('media_files')->where('id', $file->getKey())->update(['disk' => 'public', 'visibility' => 'public']);
            Storage::disk('public')->put($file->path, 'bytes');
            $orphan = 'media/'.$this->org->getKey().'/2026/09/orphan-on-public.png';
            $partial = $file->path.MediaBytes::PARTIAL;
            Storage::disk('public')->put($orphan, 'bytes');
            Storage::disk('public')->put($partial, 'bytes');
            $public->failListing = $failing;

            $exit = Artisan::call('kitsune:media-prune', $force ? ['--force' => true] : []);
            $output = Artisan::output();

            expect($output)->toContain($failing === 1 ? 'Could not list [public]: ' : 'Could not list [public] again, to ask whether it holds the extra copies whose rows name it: ')
                // Listed no more after the first failure than before it: never a disk that has already failed.
                ->and($public->listings)->toBe($failing)
                ->and($output)->toContain('kept: [public] could not be listed')
                ->and($output)->not->toContain('does not hold the file')
                ->and(str_contains($output, '[public]  '.$orphan))->toBe($failing === 2)
                ->and(str_contains($output, $partial))->toBe($failing === 2)
                ->and(str_contains($output, 'Removed 2 of 2 orphaned or leftover files'))->toBe($force && $failing === 2)
                ->and(is_file($publicRoot.'/'.$orphan))->toBe(! $force || $failing === 1)
                ->and(is_file($publicRoot.'/'.$partial))->toBe(! $force || $failing === 1)
                ->and($exit)->toBe(1)
                ->and(is_file($this->root.'/'.$file->path))->toBeTrue();
        } finally {
            exec('rm -rf '.escapeshellarg($publicRoot));
        }
    })->with(['read-only' => false, 'forced' => true])->with(['the first listing' => 1, 'the second' => 2]);

    it('lists the disk a copy belongs on once more for a path held on several others', function (): void {
        $publicRoot = sys_get_temp_dir().'/kitsune-prune-batch-public-'.bin2hex(random_bytes(4));
        mkdir($publicRoot, 0777, true);
        Storage::fake('old-cdn');
        config(['filesystems.disks.old-cdn' => ['driver' => 'local', 'root' => Storage::disk('old-cdn')->path(''), 'url' => 'https://cdn.example.test']]);

        try {
            $public = RefusingDisk::install('public', $publicRoot);
            $file = storedForPrune($this->imageType);
            DB::table('media_files')->where('id', $file->getKey())->update(['disk' => 'public', 'visibility' => 'public']);
            Storage::disk('public')->put($file->path, 'bytes');
            Storage::disk('old-cdn')->put($file->path, 'bytes');
            RefusingDisk::forgetLog();

            Artisan::call('kitsune:media-prune');

            expect(Artisan::output())->toContain('2 removable extra copies listed')
                ->and($public->listings)->toBe(2)
                ->and(array_filter(RefusingDisk::$log, static fn (array $event): bool => $event['event'] === 'fileExists'))->toBe([]);
        } finally {
            exec('rm -rf '.escapeshellarg($publicRoot));
        }
    });

    /*
     * T154. A `read-through` disk answers whether it holds a file from its fallback too, and lists its primary alone: asked,
     * it said it held the copy on core's private disk — its fallback — and --force removed the only copy. Read from its
     * listing, it holds none (review of slice 5c).
     */
    it('keeps the copy a read-through private disk reads through to, for its listing does not hold it', function (bool $force): void {
        $primary = sys_get_temp_dir().'/kitsune-prune-batch-primary-'.bin2hex(random_bytes(4));
        mkdir($primary, 0777, true);

        try {
            $file = storedForPrune($this->imageType);
            config([
                'filesystems.disks.primary' => ['driver' => 'local', 'root' => $primary],
                'filesystems.disks.private-rt' => ['driver' => 'read-through', 'primary' => 'primary', 'fallback' => MediaDisks::PRIVATE, 'copy' => false],
                'kitsune.media.disks.private' => 'private-rt',
            ]);
            DB::table('media_files')->where('id', $file->getKey())->update(['disk' => 'private-rt']);

            expect(Storage::disk('private-rt')->fileExists($file->path))->toBeTrue();

            $exit = Artisan::call('kitsune:media-prune', $force ? ['--force' => true] : []);
            $output = Artisan::output();

            expect($output)->toContain('kept: the disk its row names does not hold the file')
                ->and($output)->not->toContain('removed, once asked again under the lock')
                ->and(is_file($this->root.'/'.$file->path))->toBeTrue()
                ->and($exit)->toBe(0);
        } finally {
            exec('rm -rf '.escapeshellarg($primary));
        }
    })->with(['read-only' => false, 'forced' => true]);

    // T155: the two row lists decide in PHP, byte for byte — a row the engine's collation alone matches to `public`, or to a
    // served disk, is listed under neither, as when every row was compared in memory (review of slice 5c).
    it('lists no row whose visibility or disk matches only under the engine\'s collation', function (): void {
        Storage::fake('old-cdn');
        config(['filesystems.disks.old-cdn' => ['driver' => 'local', 'root' => Storage::disk('old-cdn')->path(''), 'url' => 'https://cdn.example.test']]);
        $live = storedForPrune($this->imageType);
        DB::table('media_files')->where('id', $live->getKey())->update(['visibility' => 'PUBLIC']);
        $trashed = storedForPrune($this->imageType);
        DB::table('media_files')->where('id', $trashed->getKey())->update(['disk' => 'OLD-CDN']);
        DB::table('entries')->where('id', $trashed->entry_id)->update(['deleted_at' => now()]);
        $folds = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);

        Artisan::call('kitsune:media-prune');
        $output = Artisan::output();

        // Where the engine folds case the narrowing query returns both rows, and only the exact test keeps them out.
        expect(DB::table('media_files')->where('id', $live->getKey())->where('visibility', 'public')->exists())->toBe($folds)
            ->and(DB::table('media_files')->where('id', $trashed->getKey())->whereIn('disk', ['old-cdn'])->exists())->toBe($folds)
            ->and($output)->not->toContain('Awaiting publication')
            ->and($output)->not->toContain('Trashed on a served disk');
    });

    /*
     * T156-T157. An extra copy's entry is held in an integer beside its flags: an id too large to hold there, either way
     * from zero, is refused, rather than read back as another entry's, and the largest that fits is read back as itself
     * (review of slice 5c).
     */
    it('refuses an extra copy whose entry id is too large to hold, and removes nothing', function (int $big): void {
        pruneWithoutKeptAutoIncrement();
        $file = storedForPrune($this->imageType);
        $entry = (array) DB::table('entries')->where('id', $file->entry_id)->first();
        DB::table('entries')->insert([...$entry, 'id' => $big]);
        DB::table('media_files')->where('id', $file->getKey())->update(['entry_id' => $big, 'disk' => 'public', 'visibility' => 'public']);
        Storage::disk('public')->put($file->path, 'bytes');

        expect(fn () => Artisan::call('kitsune:media-prune', ['--force' => true]))->toThrow(RuntimeException::class, "Refusing to list entry {$big}'s extra copy")
            ->and(is_file($this->root.'/'.$file->path))->toBeTrue();
    })->with(['one past the largest' => MediaPruneCommand::LARGEST_ENTRY + 1, 'one below the smallest' => -MediaPruneCommand::LARGEST_ENTRY - 2]);

    it('reads back the largest entry id it can hold as itself', function (): void {
        pruneWithoutKeptAutoIncrement();
        $file = storedForPrune($this->imageType);
        $largest = MediaPruneCommand::LARGEST_ENTRY;
        $entry = (array) DB::table('entries')->where('id', $file->entry_id)->first();
        DB::table('entries')->insert([...$entry, 'id' => $largest]);
        DB::table('media_files')->where('id', $file->getKey())->update(['entry_id' => $largest, 'disk' => 'public', 'visibility' => 'public']);
        Storage::disk('public')->put($file->path, 'bytes');

        $exit = Artisan::call('kitsune:media-prune');

        expect(Artisan::output())->toMatch('/^  entry '.$largest.'  \['.preg_quote(MediaDisks::PRIVATE, '/').'\]  '.preg_quote($file->path, '/').' — live, its row names \[public\]/m')
            ->and($exit)->toBe(0);
    });

    // T160: an entry id the integer holds is read back as itself, zero and below included, down to the smallest it holds
    // — only one it cannot hold is refused.
    it('lists an extra copy of entry zero or below', function (int $id): void {
        pruneWithoutKeptAutoIncrement();
        $file = storedForPrune($this->imageType);
        $entry = (array) DB::table('entries')->where('id', $file->entry_id)->first();
        DB::table('entries')->insert([...$entry, 'id' => $id]);
        DB::table('media_files')->where('id', $file->getKey())->update(['entry_id' => $id, 'disk' => 'public', 'visibility' => 'public']);
        Storage::disk('public')->put($file->path, 'bytes');

        $exit = Artisan::call('kitsune:media-prune');

        expect(Artisan::output())->toMatch('/^  entry '.$id.'  \['.preg_quote(MediaDisks::PRIVATE, '/').'\]  '.preg_quote($file->path, '/').' — live, its row names \[public\]/m')
            ->and($exit)->toBe(0);
    })->with(['zero' => 0, 'minus one' => -1, 'the smallest it holds' => -MediaPruneCommand::LARGEST_ENTRY - 1]);

    // T161-T162: up to 256 disks among the rows of extra copies, each read back as itself; a 257th is refused, rather than
    // read back as another disk, and another entry (review of slice 5c).
    it('refuses an extra copy whose row names a 257th disk, and removes nothing', function (): void {
        $file = storedForPrune($this->imageType);
        $paths = [];

        foreach (range(0, 256) as $k) {
            [$paths[]] = pruneRows($file, 1, "gone-{$k}", "far{$k}");
            Storage::disk(MediaDisks::PRIVATE)->put(end($paths), 'bytes');
        }

        expect(fn () => Artisan::call('kitsune:media-prune', ['--force' => true]))
            ->toThrow(RuntimeException::class, 'rows of extra copies name more than 256 disks');

        foreach ($paths as $path) {
            expect(is_file($this->root.'/'.$path))->toBeTrue();
        }
    });

    it('reads back the 256th disk among the rows of extra copies as itself', function (): void {
        $file = storedForPrune($this->imageType);
        $paths = [];

        foreach (range(0, 255) as $k) {
            [$paths[$k]] = pruneRows($file, 1, "gone-{$k}", "far{$k}");
            Storage::disk(MediaDisks::PRIVATE)->put($paths[$k], 'bytes');
        }

        Artisan::call('kitsune:media-prune');
        $output = Artisan::output();

        foreach ($paths as $k => $path) {
            expect($output)->toMatch('/\['.preg_quote(MediaDisks::PRIVATE, '/').'\]  '.preg_quote($path, '/').' — live, its row names \[gone-'.$k.'\]/m');
        }
    });

    // T163: a second listing that fails part-way — after a batch holding the path — contributes nothing, as a first does.
    it('keeps a copy whose target fails part-way through its second listing', function (bool $force): void {
        $publicRoot = sys_get_temp_dir().'/kitsune-prune-batch-public-'.bin2hex(random_bytes(4));
        mkdir($publicRoot, 0777, true);

        try {
            $public = RefusingDisk::install('public', $publicRoot);
            $file = storedForPrune($this->imageType);
            DB::table('media_files')->where('id', $file->getKey())->update(['disk' => 'public', 'visibility' => 'public']);
            // The copy kept, byte for byte, so nothing but the guard stands between the private copy and its removal.
            Storage::disk('public')->put($file->path, pruneFixtureBytes());

            // Listed after the row's path in descending order: a whole batch holding it is yielded, then the listing fails.
            foreach (range(1, MediaPruneCommand::BATCH + 1) as $n) {
                Storage::disk('public')->put(sprintf('media/0/filler-%04d.png', $n), 'x');
            }

            $public->reverseListing = true;
            $public->failListingAfter = MediaPruneCommand::BATCH;
            $public->failListingAfterOn = 2;

            $exit = Artisan::call('kitsune:media-prune', $force ? ['--force' => true] : []);
            $output = Artisan::output();

            expect($output)->toContain('Could not list [public]')
                ->and($output)->toContain('kept: [public] could not be listed')
                ->and($output)->not->toContain('removed, once asked again under the lock')
                ->and($public->listings)->toBe(2)
                ->and($exit)->toBe(1)
                ->and(is_file($this->root.'/'.$file->path))->toBeTrue();
        } finally {
            exec('rm -rf '.escapeshellarg($publicRoot));
        }
    })->with(['read-only' => false, 'forced' => true]);

    // T164: a public or private disk named with digits alone is listed a second time like any other.
    it('lists a disk named with digits alone again as the disk a copy belongs on', function (): void {
        $root = sys_get_temp_dir().'/kitsune-prune-batch-5-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);

        try {
            config([
                'filesystems.disks.5' => ['driver' => 'local', 'root' => $root, 'url' => 'https://five.example.test'],
                'kitsune.media.disks.public' => '5',
            ]);
            $file = storedForPrune($this->imageType);
            DB::table('media_files')->where('id', $file->getKey())->update(['disk' => '5', 'visibility' => 'public']);
            Storage::disk('5')->put($file->path, pruneFixtureBytes());

            $listed = Artisan::call('kitsune:media-prune');

            expect(Artisan::output())->toMatch('/^  entry \d+  \['.preg_quote(MediaDisks::PRIVATE, '/').'\]  '.preg_quote($file->path, '/').' — live, its row names \[5\], it belongs on \[5\]: removed, once asked again under the lock$/m')
                ->and($listed)->toBe(0);

            $forced = Artisan::call('kitsune:media-prune', ['--force' => true]);

            expect(Artisan::output())->toContain('Removed 0 of 0 orphaned or leftover files and 1 of 1 extra copy.')
                ->and($forced)->toBe(0)
                ->and(is_file($this->root.'/'.$file->path))->toBeFalse()
                ->and(is_file($root.'/'.$file->path))->toBeTrue();
        } finally {
            exec('rm -rf '.escapeshellarg($root));
        }
    });

    // T165: the second listing holds only the paths prune holds, however many settled files the disk lists — prune's memory
    // does not grow with them (review of slice 5c).
    it('holds nothing more for a second listing over more settled files', function (): void {
        $file = storedForPrune($this->imageType);
        DB::table('media_files')->where('id', $file->getKey())->update(['disk' => 'public', 'visibility' => 'public']);
        Storage::disk('public')->put($file->path, pruneFixtureBytes());
        $peak = function (int $settled) use ($file): int {
            foreach (pruneRows($file, $settled, 'public', 'settled-'.$settled, ['visibility' => 'public']) as $path) {
                Storage::disk('public')->put($path, 'x');
            }

            gc_collect_cycles();
            $before = memory_get_usage();
            memory_reset_peak_usage();
            Artisan::call('kitsune:media-prune', [], new NullOutput);

            return memory_get_peak_usage() - $before;
        };

        // The first run in a process peaks higher on its own, by some 0.4 MB: warmed up, it is not counted (review of 5c).
        $peak(1);
        $few = $peak(2_000);
        $many = $peak(10_000);

        // Each settled path held costs some 0.05 KB in a list and some 0.1 KB as a key: 0.5 to 1 MB more over the second
        // run's 10,000. As built, the difference reads about 0.
        expect($many - $few)->toBeLessThan(250_000);
    });

    /*
     * T169. What prune holds for an extra copy is its path and one integer, flags and all, whatever disk it is on: set by
     * key, on the last disk listed too — whose table the scan still shared — and looked for in the second listing by the
     * path the table already holds. Measured on SQLite, PHP 8.4: some 197 B an added copy as built; about 238 B with the
     * flags set by value, and about 270 B with the last disk's table copied, or a key made for each (review of slice 5c).
     */
    it('holds each extra copy once, and no more, on the last disk listed', function (): void {
        $file = storedForPrune($this->imageType);
        DB::table('media_files')->where('id', $file->getKey())->update(['disk' => 'public', 'visibility' => 'public']);
        Storage::disk('public')->put($file->path, pruneFixtureBytes());
        $peak = function (int $copies) use ($file): int {
            foreach (pruneRows($file, $copies, 'public', 'extra-'.$copies, ['visibility' => 'public']) as $path) {
                Storage::disk('public')->put($path, 'x');
                Storage::disk(MediaDisks::PRIVATE)->put($path, 'x');
            }

            gc_collect_cycles();
            $before = memory_get_usage();
            memory_reset_peak_usage();
            Artisan::call('kitsune:media-prune', [], new NullOutput);

            return memory_get_peak_usage() - $before;
        };

        // The first run in a process peaks higher on its own: warmed up, it is not counted.
        $peak(1);
        $few = $peak(2_000);
        $many = $peak(8_000);

        expect(($many - $few) / 8_000)->toBeLessThan(220);
    });

    // ...and on SQLite, which holds any bytes, a row naming such a path claims its file.
    it('claims a file whose name only SQLite can hold for the row naming it there', function (): void {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('only SQLite holds a path that is not UTF-8');
        }

        $file = storedForPrune($this->imageType);
        $bad = 'media/'.$this->org->getKey().'/2026/09/caf'."\xe9".'.png';
        DB::table('media_files')->where('id', $file->getKey())->update(['path' => $bad]);
        Storage::disk(MediaDisks::PRIVATE)->delete($file->path);
        $this->private->phantoms = [$bad];

        $exit = Artisan::call('kitsune:media-prune');

        expect(Artisan::output())->toContain('No orphaned media files.')
            ->and($exit)->toBe(0);
    });

    // T151: a disk named with digits alone is a disk like any other — listed, and its files removed.
    it('lists and removes on a disk named with digits alone', function (): void {
        $root = sys_get_temp_dir().'/kitsune-prune-batch-7-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);

        try {
            config(['filesystems.disks.7' => ['driver' => 'local', 'root' => $root]]);
            $file = storedForPrune($this->imageType);
            $claimed = storedForPrune($this->imageType);
            DB::table('media_files')->where('id', $claimed->getKey())->update(['disk' => '7']);
            Storage::disk('7')->put($claimed->path, 'bytes');
            Storage::disk('7')->put($claimed->path.MediaBytes::PARTIAL, 'half');
            Storage::disk('7')->put('media/'.$this->org->getKey().'/2026/09/orphan-on-7.png', 'bytes');
            // A copy of a file settled on core's private disk: an extra copy, on [7].
            Storage::disk('7')->put($file->path, pruneFixtureBytes());

            $listed = Artisan::call('kitsune:media-prune');
            $listing = Artisan::output();
            $forced = Artisan::call('kitsune:media-prune', ['--force' => true]);

            expect($listing)->toContain('orphan-on-7.png')
                ->and($listing)->toContain($claimed->path.MediaBytes::PARTIAL)
                ->and($listing)->toMatch('/^  entry \d+  \[7\]  '.preg_quote($file->path, '/').' — /m')
                ->and($listed)->toBe(0)
                ->and(Artisan::output())->toContain('Removed 2 of 2 orphaned or leftover files and 1 of 1 extra copy.')
                ->and($forced)->toBe(0)
                ->and(is_file($root.'/'.$file->path))->toBeFalse()
                ->and(is_file($root.'/'.$claimed->path))->toBeTrue();
        } finally {
            exec('rm -rf '.escapeshellarg($root));
        }
    });

    // T182: a disk only a row past the first batch names is swept too — the disks rows name are read from every batch
    // (review of slice 5c).
    it('sweeps a disk that only a row past the first batch names', function (): void {
        $legacyRoot = sys_get_temp_dir().'/kitsune-prune-batch-legacy-'.bin2hex(random_bytes(4));
        mkdir($legacyRoot, 0777, true);

        try {
            config(['filesystems.disks.legacy' => ['driver' => 'local', 'root' => $legacyRoot]]);
            $template = storedForPrune($this->imageType);
            // The template's row and BATCH more on the private disk: the first batch names no other disk.
            pruneRows($template, MediaPruneCommand::BATCH, MediaDisks::PRIVATE, 'early');
            [$late] = pruneRows($template, 1, 'legacy', 'late');
            Storage::disk('legacy')->put($late, 'bytes');
            $orphan = 'media/'.$this->org->getKey().'/2026/09/left-behind.png';
            Storage::disk('legacy')->put($orphan, 'bytes');

            $exit = Artisan::call('kitsune:media-prune');
            $output = Artisan::output();

            expect($output)->toContain('  [legacy]  '.$orphan)
                ->and($output)->not->toContain('  [legacy]  '.$late.PHP_EOL)
                ->and($exit)->toBe(0);
        } finally {
            exec('rm -rf '.escapeshellarg($legacyRoot));
        }
    });

    // T152: the disks rows name are scanned in the order their first rows were written — here the opposite of their
    // entries' order — which decides which of two names for one directory lists its files.
    it('scans the disks rows name in the order their first rows were written', function (): void {
        $shared = sys_get_temp_dir().'/kitsune-prune-batch-shared-'.bin2hex(random_bytes(4));
        mkdir($shared, 0777, true);

        try {
            config([
                'filesystems.disks.disk-a' => ['driver' => 'local', 'root' => $shared],
                'filesystems.disks.disk-b' => ['driver' => 'local', 'root' => $shared],
            ]);
            $first = storedForPrune($this->imageType);
            $second = storedForPrune($this->imageType);
            $spare = storedForPrune($this->imageType);
            DB::table('media_files')->where('id', $spare->getKey())->delete();
            // The lowest id, and the highest entry: written first, for the last entry.
            DB::table('media_files')->where('id', $first->getKey())->update(['disk' => 'disk-b', 'entry_id' => $spare->entry_id]);
            DB::table('media_files')->where('id', $second->getKey())->update(['disk' => 'disk-a']);

            foreach ([$first, $second] as $file) {
                Storage::disk('disk-a')->put($file->path, pruneFixtureBytes());
                Storage::disk(MediaDisks::PRIVATE)->delete($file->path);
            }

            $orphan = 'media/'.$this->org->getKey().'/2026/09/orphan.png';
            Storage::disk('disk-a')->put($orphan, 'bytes');

            Artisan::call('kitsune:media-prune');
            $output = Artisan::output();

            expect($output)->toContain('  [disk-b]  '.$orphan)
                ->and($output)->not->toContain('  [disk-a]  '.$orphan)
                ->and($output)->toMatch('/^  entry '.$second->entry_id.'  \[disk-b\]  .* its row names \[disk-a\]/m')
                ->and($output)->not->toContain('Not scanning');
        } finally {
            exec('rm -rf '.escapeshellarg($shared));
        }
    });

    // T146: each list of rows prints a row before its pass reads the next batch — never collected, then printed.
    it('prints rows awaiting publication before it reads their next batch', function (): void {
        $template = storedForPrune($this->imageType);
        pruneRows($template, MediaPruneCommand::BATCH + 1, MediaDisks::PRIVATE, 'awaiting', ['visibility' => 'public']);
        $events = [];
        $output = new class($events) extends BufferedOutput
        {
            /** @param  list<string>  $events */
            public function __construct(private array &$events)
            {
                parent::__construct();
            }

            protected function doWrite(string $message, bool $newline): void
            {
                if (str_starts_with($message, '  entry ')) {
                    $this->events[] = 'line';
                }

                parent::doWrite($message, $newline);
            }
        };
        DB::listen(function ($query) use (&$events): void {
            if (preg_match('/\bfrom\W+media_files\b/i', $query->sql) === 1 && str_contains($query->sql, 'visibility') && str_contains(strtolower($query->sql), 'limit')) {
                $events[] = 'chunk';
            }
        });

        Artisan::call('kitsune:media-prune', [], $output);
        $second = array_keys($events, 'chunk', true)[1] ?? null;

        expect($second)->not->toBeNull()
            ->and(array_search('line', $events, true))->toBeLessThan($second)
            ->and(array_keys($events, 'line', true))->toHaveCount(MediaPruneCommand::BATCH + 1);
    });

    // T144: the two lists of rows print a line a row — no table, which would need every row first (T146: as they are read).
    it('prints rows awaiting publication and rows trashed on a served disk a line each', function (): void {
        Storage::fake('old-cdn');
        config(['filesystems.disks.old-cdn' => ['driver' => 'local', 'root' => Storage::disk('old-cdn')->path(''), 'url' => 'https://cdn.example.test']]);
        $awaiting = storedForPrune($this->imageType);
        DB::table('media_files')->where('id', $awaiting->getKey())->update(['visibility' => 'public']);
        $trashed = storedForPrune($this->imageType);
        DB::table('media_files')->where('id', $trashed->getKey())->update(['disk' => 'old-cdn']);
        DB::table('entries')->where('id', $trashed->entry_id)->update(['deleted_at' => now()]);
        Storage::disk('old-cdn')->put($trashed->path, 'bytes');

        Artisan::call('kitsune:media-prune');
        $output = Artisan::output();
        $after = static fn (string $heading): string => (string) strstr(substr($output, (int) strpos($output, $heading)), "\n");

        expect(explode("\n", trim($after('Awaiting publication')))[0])->toBe(trim(sprintf('entry %d  [%s]  %s', $awaiting->entry_id, MediaDisks::PRIVATE, $awaiting->path)))
            ->and(explode("\n", trim($after('Trashed on a served disk')))[0])->toBe(trim(sprintf('entry %d  [old-cdn]  %s', $trashed->entry_id, $trashed->path)))
            ->and(substr($output, (int) strpos($output, 'Awaiting publication')))->not->toContain('+--')
            ->and(substr($output, (int) strpos($output, 'Awaiting publication')))->not->toContain('| ');

        DB::table('media_files')->where('id', $awaiting->getKey())->update(['visibility' => 'private']);
        DB::table('entries')->where('id', $trashed->entry_id)->update(['deleted_at' => null]);
        DB::table('media_files')->where('id', $trashed->getKey())->update(['disk' => MediaDisks::PRIVATE]);
        Artisan::call('kitsune:media-prune');

        expect(Artisan::output())->not->toContain('Awaiting publication')
            ->and(Artisan::output())->not->toContain('Trashed on a served disk');
    });
});
