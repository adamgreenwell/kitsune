<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
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
use Kitsune\Core\Media\MediaCustodyFailure;
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
 * ...and one a row claims by its very name between the listing and the lock is counted kept, never removed: the closing
 * line said it had removed a file it had not (review of slice 5c). The rival row is written as the removal's own
 * transaction begins.
 */
it('counts as kept, not removed, a partial copy a row claims by its very name under the lock', function (): void {
    $file = storedForPrune($this->imageType);
    $partial = MediaBytes::partial($file->path);
    Storage::disk(MediaDisks::PRIVATE)->put($partial, 'half');
    $claimed = false;

    Event::listen(TransactionBeginning::class, function () use ($partial, &$claimed): void {
        if ($claimed) {
            return;
        }

        $claimed = true;
        $other = storedForPrune($this->imageType);
        DB::table('media_files')->where('id', $other->getKey())->update(['path' => $partial]);
    });

    $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);

    expect($claimed)->toBeTrue()
        ->and(Artisan::output())->toContain('Removed 0 of 1 orphaned or leftover file and 0 of 0 extra copies. 1 kept: under the lock a row claimed its path')
        ->and($exit)->toBe(0);
    Storage::disk(MediaDisks::PRIVATE)->assertExists($partial);
});

/*
 * A partial copy's name a row gives byte for byte — a row written past `MediaFile`, which refuses one — is that row's
 * file: removed as another row's partial copy, the row lost its only file (review of slice 5c). Under the lock it is kept.
 */
it('keeps under the lock a partial copy whose very name a row gives', function (): void {
    $file = storedForPrune($this->imageType);
    $other = storedForPrune($this->imageType);
    $partial = MediaBytes::partial($file->path);
    DB::table('media_files')->where('id', $other->getKey())->update(['disk' => MediaDisks::PRIVATE, 'path' => $partial]);
    Storage::disk(MediaDisks::PRIVATE)->put($partial, 'the other row\'s only file');

    expect(MediaCustody::removeTemp(DB::connection(), (int) $file->entry_id, MediaDisks::PRIVATE, $partial))->toBe(MediaCustody::CLAIMED);

    Storage::disk(MediaDisks::PRIVATE)->assertExists($partial);
});

/*
 * ...and prune lists it as that row's copy, never another row's partial copy: the table decides, byte for byte, before
 * the suffix does — a file on a disk the row does not name is its extra copy, and one on the disk it names is its own.
 */
it('lists a file whose very name a row gives as that row\'s, never another row\'s partial copy', function (string $named): void {
    Storage::fake('legacy');
    config(['filesystems.disks.legacy' => ['driver' => 'local', 'root' => Storage::disk('legacy')->path('')]]);
    $file = storedForPrune($this->imageType);
    $other = storedForPrune($this->imageType);
    $partial = MediaBytes::partial($file->path);
    Storage::disk($other->disk)->delete($other->path);
    DB::table('media_files')->where('id', $other->getKey())->update(['disk' => $named, 'path' => $partial, 'visibility' => 'private']);
    // Its only copy on core's private disk, and — where the row names that disk — a stale one on the public disk.
    Storage::disk(MediaDisks::PRIVATE)->put($partial, pruneFixtureBytes());
    $stale = $named === MediaDisks::PRIVATE ? 'public' : null;

    if ($stale !== null) {
        Storage::disk($stale)->put($partial, pruneFixtureBytes());
    }

    expect(Artisan::call('kitsune:media-prune'))->toBe(0);
    $output = Artisan::output();
    $extras = (string) strstr($output, 'Extra copies');

    expect($output)->not->toContain('Leftover partial copies')
        ->and($output)->not->toContain('Copies a row reaches under another spelling')
        ->and($output)->toContain('No orphaned media files')
        ->and($extras)->toContain('entry '.$other->entry_id.' ')
        ->and($extras)->toContain($stale === null ? 'kept: kitsune:media-reconcile moves its row first' : 'removed, once asked again under the lock');

    expect(Artisan::call('kitsune:media-prune', ['--force' => true]))->toBe(0);

    Storage::disk(MediaDisks::PRIVATE)->assertExists($partial);
    Storage::disk($file->disk)->assertExists($file->path);

    if ($stale !== null) {
        Storage::disk($stale)->assertMissing($partial);
    }
})->with(['a row naming another disk' => 'legacy', 'a row naming the disk that holds it, with a stale copy on another' => MediaDisks::PRIVATE]);

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
    it('keeps a file a row claimed since the listing', function (string $case, bool $named): void {
        // A row may give a partial copy's own name, byte for byte, where it is written past `MediaFile` (review of 5c).
        $claim = $named || $case === 'orphan' ? $this->paths[$case] : substr($this->paths[$case], 0, -strlen(MediaBytes::PARTIAL));
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
            ->and($sections['all'])->toContain('kept: under the lock a row claimed its path — one committed since the listing, or one the database takes for the same path.');

        Storage::disk(MediaDisks::PRIVATE)->assertExists($this->paths[$case]);
    })->with([
        'an orphan' => ['orphan', false],
        'an orphaned partial, by its path' => ['orphaned partial', false],
        '...and by its name' => ['orphaned partial', true],
    ]);
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
 * Decision 25. A disk whose root cannot be looked at may hold what prune lists — a copy custody could not remove from a
 * served disk, the one an operator most needs to see, above all — so it is not taken to hold nothing, as one whose root
 * is not there is (T95): it is not built, which would try to create its root, and the run says it could not be listed,
 * and fails. A served disk, a disk a row names, each configured disk (Codex, #160), and core's own private disk where the
 * private disk points elsewhere.
 *
 * ⚠️ A LOOP OF LINKS ON THE WAY TO THE ROOT stands for every refusal that is not an absence: root, which runs this suite
 * here, cannot see past it, where it ignores a directory's mode. The control is a root that is not there — which a disk
 * a row names and a configured one are still built with, and listed, creating it, as before.
 */
it('fails a run that cannot look at a disk\'s root, where one whose root is not there holds nothing', function (string $which, bool $looped, bool $force): void {
    $base = sys_get_temp_dir().'/kitsune-prune-unrooted-'.bin2hex(random_bytes(4));
    mkdir($base);
    symlink($base.'/loop', $base.'/loop');
    $root = $base.($looped ? '/loop/cdn' : '/missing/cdn');

    try {
        $disk = match ($which) {
            'served' => (static function () use ($root): string {
                config(['filesystems.disks.unrooted' => ['driver' => 'local', 'root' => $root, 'url' => 'https://unrooted.example.test']]);

                return 'unrooted';
            })(),
            'named by a row' => (function () use ($root): string {
                config(['filesystems.disks.unrooted' => ['driver' => 'local', 'root' => $root]]);
                DB::table('media_files')->where('id', storedForPrune($this->imageType)->getKey())->update(['disk' => 'unrooted']);

                return 'unrooted';
            })(),
            'the configured public disk', 'the configured private disk' => (static function () use ($root, $which): string {
                $which = $which === 'the configured public disk' ? 'public' : 'private';
                config([
                    "filesystems.disks.host-{$which}" => ['driver' => 'local', 'root' => $root],
                    "kitsune.media.disks.{$which}" => "host-{$which}",
                ]);

                return "host-{$which}";
            })(),
            'core\'s private disk' => (static function () use ($root): string {
                Storage::fake('host-private');
                config([
                    'filesystems.disks.host-private.root' => Storage::disk('host-private')->path(''),
                    'kitsune.media.disks.private' => 'host-private',
                    'filesystems.disks.'.MediaDisks::PRIVATE.'.root' => $root,
                ]);

                return MediaDisks::PRIVATE;
            })(),
        };

        $exit = Artisan::call('kitsune:media-prune', $force ? ['--force' => true] : []);
        $output = Artisan::output();

        expect(str_contains($output, "Could not list [{$disk}]: Refusing to go on with [/] on the [{$disk}] disk: whether the disk holds anything cannot be told, because its root cannot be looked at"))->toBe($looped)
            ->and($output)->not->toContain($base)
            ->and(file_exists($base.'/missing'))->toBe(! $looped && ! in_array($which, ['served', 'core\'s private disk'], true))
            ->and($exit)->toBe($looped ? 1 : 0);
    } finally {
        exec('rm -rf '.escapeshellarg($base));
    }
})->with(['served', 'named by a row', 'the configured public disk', 'the configured private disk', 'core\'s private disk'])
    ->with(['cannot be looked at' => true, 'the control: not there' => false])
    ->with(['read-only' => false, 'forced' => true]);

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
                ->and($output)->not->toContain('which its row names, could not be built —')
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

    // ...and its extra copy is the row's extra copy, listed as one it removes — not a copy the row reaches under another
    // spelling, which the pass over the table would take it for were the batch's lookup blind to the BLOB (review of 5c).
    $extra = $column === 'path beside an extra copy';
    $column = $extra ? 'path' : $column;
    $file = storedForPrune($this->imageType);
    $privateRoot = rtrim(Storage::disk(MediaDisks::PRIVATE)->path(''), '/');

    if ($extra) {
        Storage::disk('public')->put($file->path, (string) Storage::disk(MediaDisks::PRIVATE)->get($file->path));
    }

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
            ->and($output)->not->toContain('Copies a row reaches')
            ->and($exit)->toBe(0);

        if ($extra && $options === []) {
            expect($output)->toContain('[public]  '.$file->path.' — live, its row names ['.MediaDisks::PRIVATE.'], it belongs on ['.MediaDisks::PRIVATE.']: removed, once asked again under the lock');
        }

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
})->with(['its path' => 'path', 'its path, beside an extra copy' => 'path beside an extra copy', 'its visibility' => 'visibility', 'its disk' => 'disk']);

/*
 * A copy whose row names a disk that is not configured is kept as one whose row names a disk that could not be built:
 * reconcile refuses the row until the disk can be asked, so prune does not say reconcile moves it first (review of 5c).
 */
it('keeps a copy whose row names a disk that could not be built, and sends it to no reconcile', function (bool $force): void {
    $file = storedForPrune($this->imageType);
    DB::table('media_files')->where('id', $file->getKey())->update(['disk' => 'gone']);

    $exit = Artisan::call('kitsune:media-prune', $force ? ['--force' => true] : []);
    $output = Artisan::output();

    expect($output)->toContain('Could not list [gone]')
        ->and($output)->toContain('kept: [gone], which its row names, could not be built — kitsune:media-reconcile --force moves its row only once that disk can be asked')
        ->and($output)->not->toContain('moves its row first')
        ->and(is_file(Storage::disk(MediaDisks::PRIVATE)->path($file->path)))->toBeTrue()
        ->and($exit)->toBe(1);
})->with(['read-only' => false, 'forced' => true]);

/*
 * ...but one built whose listing alone failed — a link under Laravel's default link handling, which refuses to list it —
 * is a disk reconcile asks as any other: the line said a forced reconcile would not move the row, and it did, at once
 * (review of slice 5c). Every volume makes it.
 */
it('sends a copy whose row names a disk whose listing alone failed to a reconcile that moves the row first', function (): void {
    $legacy = sys_get_temp_dir().'/kitsune-prune-legacy-'.bin2hex(random_bytes(4));
    mkdir($legacy.'/media/shared', 0777, true);

    try {
        config(['filesystems.disks.legacy' => ['driver' => 'local', 'root' => $legacy]]);
        $file = storedForPrune($this->imageType);
        $bytes = (string) Storage::disk($file->disk)->get($file->path);
        Storage::disk($file->disk)->delete($file->path);
        Storage::disk('legacy')->put($file->path, $bytes);
        Storage::disk('public')->put($file->path, $bytes);
        symlink($legacy.'/'.$file->path, $legacy.'/media/shared/link.png');
        DB::table('media_files')->where('id', $file->getKey())->update(['disk' => 'legacy', 'visibility' => 'public']);

        Artisan::call('kitsune:media-prune');
        $output = Artisan::output();

        expect($output)->toContain('Could not list [legacy]')
            ->and($output)->toContain('entry '.$file->entry_id.'  [public]  '.$file->path.' — live, its row names [legacy], it belongs on [public]: kept: kitsune:media-reconcile moves its row first')
            ->and($output)->not->toContain('only once that disk can be asked');

        expect(Artisan::call('kitsune:media-reconcile', ['--force' => true]))->toBe(0)
            ->and(DB::table('media_files')->where('id', $file->getKey())->value('disk'))->toBe('public');
    } finally {
        exec('rm -rf '.escapeshellarg($legacy));
    }
});

/*
 * ...and a disk that failed its first listing, scanned last, leaves no failure behind: each target is listed again from a
 * clean slate, and an extra copy whose row names a disk that listed is still removable (review of slice 5c).
 */
it('removes an extra copy beside a disk scanned last whose listing failed', function (bool $force): void {
    $kept = storedForPrune($this->imageType);
    DB::table('media_files')->where('id', $kept->getKey())->update(['visibility' => 'public', 'disk' => 'public']);
    Storage::disk('public')->put($kept->path, (string) Storage::disk(MediaDisks::PRIVATE)->get($kept->path));
    $gone = storedForPrune($this->imageType);
    DB::table('media_files')->where('id', $gone->getKey())->update(['disk' => 'gone']);

    $exit = Artisan::call('kitsune:media-prune', $force ? ['--force' => true] : []);
    $output = Artisan::output();

    expect($output)->toContain('Could not list [gone]')
        ->and($output)->not->toContain('Could not list [public] again')
        ->and($output)->not->toContain('kept: [public] could not be listed')
        ->and($output)->toContain('removed, once asked again under the lock')
        ->and(str_contains($output, 'and 1 of 1 extra copy'))->toBe($force)
        ->and(is_file(Storage::disk(MediaDisks::PRIVATE)->path($kept->path)))->toBe(! $force)
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
            ->and($output)->toContain('kept: [loop-a], which its row names, could not be built')
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

/**
 * A stored file whose media_files id is not its entry's, so a line or a claim carrying the one where the other belongs is
 * seen (review of #155's fix): a fresh table numbers both alike.
 */
function storedApartForPrune(EntryType $type): MediaFile
{
    $file = storedForPrune($type);
    $id = (int) $file->getKey() + 100;
    DB::table('media_files')->where('id', $file->getKey())->update(['id' => $id]);
    $moved = MediaFile::query()->findOrFail($id);

    expect((int) $moved->entry_id)->not->toBe($id);

    return $moved;
}

/*
 * T135-T142, T144, T146, T151, T152, T154-T157, T160-T165, T169, T171, T176, T182. Prune reads rows in batches (Adam, decision 11, 2026-09-26): the disks rows name, then the rows naming
 * a batch of listed files, as each disk lists them — holding what it lists and no row besides — and lists what it listed
 * when it held every row, but for what ADR-042 decision 11's list of what prune prints that is not unchanged names: among
 * them a name a row reaches under another spelling, which it listed as an orphan and the pass over the table now keeps
 * under a heading of its own, lists as that row's partial copy, or lists nowhere (T176; Codex, #155).
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

    // T139: a file is claimed by a row naming exactly its path, whatever the engine's collation matches it to — an accent
    // MySQL's and MariaDB's collations ignore, and no volume folds, so the check is the same on every volume (review of
    // the fix for Codex, #155, where a case the laptop's volume folds made the row's own file its claim).
    it('claims a file only for a row naming exactly its path, on every engine', function (): void {
        $file = storedForPrune($this->imageType);
        $dir = dirname($file->path);
        rename($this->root.'/'.$file->path, $this->root.'/'.$dir.'/cafe.png');
        $row = $dir.'/caf'."\u{e9}".'.png';
        DB::table('media_files')->where('id', $file->getKey())->update(['path' => $row]);
        // Beside the row's own path, with a trailing space: another name, on every volume.
        Storage::disk(MediaDisks::PRIVATE)->put($row.' ', 'bytes');

        $exit = Artisan::call('kitsune:media-prune');
        $output = Artisan::output();

        expect($output)->toContain($dir.'/cafe.png')
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
     * T176. A listed name the volume reaches as a row's own file — however the row spells it: another case, the other
     * Unicode normalization or the two mixed in one name, a character the volume folds to one inside ASCII, a length the
     * fold changes, a directory spelt otherwise — is that row's file, and is listed nowhere. The recheck under the lock
     * compares bytes on SQLite and PostgreSQL, found no row, and the row's only file was removed (review of slice 5c);
     * asked by the listed name's spellings, then by pattern, it missed the mixed-case row an import writes (Codex, #155)
     * and then the rest. So the volume is asked, by stat. Made only where the volume folds the two; skipped elsewhere.
     */
    it('lists nowhere a name the volume reaches as a row\'s own file', function (string $form): void {
        $blob = str_ends_with($form, ' blob');
        $form = $blob ? substr($form, 0, -strlen(' blob')) : $form;

        if ($blob && DB::connection()->getDriverName() !== 'sqlite') {
            test()->markTestSkipped('only SQLite keeps a value\'s storage class apart from its column\'s type');
        }

        $kept = storedForPrune($this->imageType);
        $dir = dirname($kept->path);
        $cafe = 'caf'."\u{e9}";
        $nfd = static fn (string $name): string => (string) Normalizer::normalize($name, Normalizer::FORM_D);
        // The row's spelling, and the name the disk holds its file under.
        [$row, $name] = match ($form) {
            'case' => [$kept->path, $dir.'/'.strtoupper(basename($kept->path))],
            'nfd name' => [$dir.'/'.$cafe.'.png', $dir.'/'.$nfd($cafe).'.png'],
            'nfc name' => [$dir.'/'.$nfd($cafe).'.png', $dir.'/'.$cafe.'.png'],
            'upper nfd name' => [$dir.'/'.$cafe.'.png', $dir.'/'.$nfd(mb_strtoupper($cafe.'.png'))],
            'mixed case' => [$dir.'/KeptPhoto.PNG', $dir.'/keptphoto.png'],
            'accented, upper' => [$dir.'/'.mb_strtoupper($cafe).'.png', $dir.'/'.$cafe.'.png'],
            'accented, upper, the row in NFD' => [$dir.'/'.$nfd(mb_strtoupper($cafe)).'.png', $dir.'/'.$cafe.'.png'],
            'kelvin' => [$dir."/\u{212A}eptphoto.png", $dir.'/keptphoto.png'],
            'long s' => [$dir."/\u{17F}unphoto.png", $dir.'/sunphoto.png'],
            'greek question mark' => [$dir."/kept\u{37E}photo.png", $dir.'/kept;photo.png'],
            'varia' => [$dir."/kept\u{1FEF}photo.png", $dir.'/kept`photo.png'],
            'sharp s' => [$dir.'/STRASSE.png', $dir."/stra\u{DF}e.png"],
            'dotless i' => [$dir."/\u{131}lik-PHOTO.png", $dir."/\u{131}lik-photo.png"],
            'mixed forms' => [$dir.'/'.$cafe.'-'.$nfd($cafe).'.png', $dir.'/'.$cafe.'-'.$cafe.'.png'],
            default => [strtoupper($dir).'/0123', $dir.'/0123'],
        };
        rename($this->root.'/'.$kept->path, $this->root.'/'.$name);
        $file = @stat($this->root.'/'.$name);
        $reached = @stat($this->root.'/'.$row);

        // A volume that reaches the two as one file may keep the name as first written — case-sensitive APFS does, for a
        // rename between normalizations — and one that folds neither is asked nothing here.
        if (! in_array(basename($name), scandir(dirname($this->root.'/'.$name)) ?: [], true)) {
            test()->markTestSkipped('this volume keeps the name as first written');
        }

        if (! is_array($file) || ! is_array($reached) || $file['ino'] !== $reached['ino']) {
            test()->markTestSkipped('this volume does not fold the two');
        }

        DB::table('media_files')->where('id', $kept->getKey())->update(['path' => $row]);

        if ($blob) {
            DB::update('update media_files set path = cast(path as blob) where id = ?', [$kept->getKey()]);
        }

        $exit = Artisan::call('kitsune:media-prune');
        $output = Artisan::output();

        expect($output)->toContain('No orphaned media files.')
            ->and($output)->not->toContain('Copies a row reaches')
            ->and($exit)->toBe(0);

        $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);

        expect(Artisan::output())->toContain('No orphaned media files.')
            ->and(is_file($this->root.'/'.$name))->toBeTrue()
            ->and($exit)->toBe(0);
    })->with([
        'another case' => 'case',
        'the name in NFD, the row in NFC' => 'nfd name',
        'the name in NFC, the row in NFD' => 'nfc name',
        'the name upper case in NFD, the row lower case in NFC' => 'upper nfd name',
        'an import\'s mixed case (Codex, #155)' => 'mixed case',
        'an accented name in upper case' => 'accented, upper',
        'an accented name in upper case, the row in NFD' => 'accented, upper, the row in NFD',
        'the Kelvin sign for k' => 'kelvin',
        'the long s for s' => 'long s',
        'the Greek question mark for ;' => 'greek question mark',
        'the varia for a backtick' => 'varia',
        'STRASSE for straße' => 'sharp s',
        'a dotless i' => 'dotless i',
        'NFC and NFD mixed in one name' => 'mixed forms',
        'a directory in another case, over a name with no cased letter' => 'directory',
        'another case, the row\'s path a BLOB' => 'case blob',
    ]);

    // ...but a hard link is another entry for the file — beside it, in another directory, under another accent, or in
    // another case where the volume keeps the two apart — which removing leaves the row's own: an orphan, on any volume.
    // MySQL and MariaDB's collation claims it under the lock where it differs only in case or accents (review of the fix).
    it('removes a name that is only another entry for a row\'s file', function (string $where): void {
        $kept = storedForPrune($this->imageType);
        $dir = dirname($kept->path);

        if ($where === 'accent') {
            rename($this->root.'/'.$kept->path, $this->root.'/'.$dir.'/caf'."\u{e8}".'.png');
            DB::table('media_files')->where('id', $kept->getKey())->update(['path' => $dir.'/caf'."\u{e8}".'.png']);
            $kept->path = $dir.'/caf'."\u{e8}".'.png';
        }

        $link = match ($where) {
            'beside' => $dir.'/other.png',
            'elsewhere' => 'media/'.$this->org->getKey().'/2026/10/else.png',
            'accent' => $dir.'/caf'."\u{e9}".'.png',
            default => $dir.'/'.strtoupper(basename($kept->path)),
        };

        if ($where === 'case' && @stat($this->root.'/'.$link) !== false) {
            test()->markTestSkipped('this volume folds case: the two names are one entry');
        }

        @mkdir(dirname($this->root.'/'.$link), 0777, true);
        link($this->root.'/'.$kept->path, $this->root.'/'.$link);
        $collates = in_array($where, ['accent', 'case'], true) && in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);

        $exit = Artisan::call('kitsune:media-prune');

        expect(Artisan::output())->toContain('1 orphaned file')->and($exit)->toBe(0);

        $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);

        expect(Artisan::output())->toContain($collates ? '1 kept: under the lock a row claimed its path' : 'Removed 1 of 1 orphaned or leftover file')
            ->and(is_file($this->root.'/'.$link))->toBe($collates)
            ->and(hash_file('sha256', $this->root.'/'.$kept->path))->toBe(hash('sha256', pruneFixtureBytes()))
            ->and($exit)->toBe(0);
    })->with(['beside it' => 'beside', 'in another directory' => 'elsewhere', 'under another accent' => 'accent', 'in another case, where the volume keeps them apart' => 'case']);

    // ...and a row's path that reaches its file through a link the listing skips — a month's directory, or the row's own
    // name — lists that file nowhere: the listing's name for it is an orphan's no longer. A hard link to it elsewhere is
    // still another entry (review of the fix).
    it('lists nowhere a file a row\'s path reaches through a link the listing skips', function (string $shape): void {
        $root = sys_get_temp_dir().'/kitsune-prune-links-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);

        try {
            config(['filesystems.disks.'.MediaDisks::PRIVATE => ['driver' => 'local', 'root' => $root, 'links' => 'skip']]);
            Storage::forgetDisk(MediaDisks::PRIVATE);
            $kept = storedForPrune($this->imageType);
            $org = 'media/'.$this->org->getKey();
            $bytes = hash_file('sha256', $root.'/'.$kept->path);

            if ($shape === 'directory') {
                // The month's directory a link to another, whose file the listing gives under the other's name.
                rename($root.'/'.dirname($kept->path), $root.'/'.$org.'/real');
                symlink($root.'/'.$org.'/real', $root.'/'.dirname($kept->path));
                $row = $kept->path;
                $removed = null;
            } else {
                // The row's name a link to b/target.png, which has a hard link at c/tlink.png.
                mkdir($root.'/'.$org.'/b', 0777, true);
                mkdir($root.'/'.$org.'/c', 0777, true);
                rename($root.'/'.$kept->path, $root.'/'.$org.'/b/target.png');
                link($root.'/'.$org.'/b/target.png', $root.'/'.$org.'/c/tlink.png');
                symlink($root.'/'.$org.'/b/target.png', $root.'/'.$org.'/c/sym.png');
                $row = $org.'/c/sym.png';
                $removed = $org.'/c/tlink.png';
                DB::table('media_files')->where('id', $kept->getKey())->update(['path' => $row]);
            }

            $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);
            $output = Artisan::output();

            expect($output)->toContain($removed === null ? 'No orphaned media files.' : 'Removed 1 of 1 orphaned or leftover file')
                // Listed nowhere, not as another disk's row's copy (review of slice 5c).
                ->and($output)->not->toContain('Copies a row reaches')
                ->and(hash_file('sha256', $root.'/'.$row))->toBe($bytes)
                ->and($removed === null || ! file_exists($root.'/'.$removed))->toBeTrue()
                ->and($exit)->toBe(0);
        } finally {
            exec('rm -rf '.escapeshellarg($root));
        }
    })->with(['through its month\'s directory' => 'directory', 'through its own name, beside a hard link elsewhere' => 'name']);

    /*
     * ...and where a row's other spelling and a hard link share a directory, which entry the row reaches cannot be told:
     * both are kept, and listed nowhere where the row names that disk. A hard link in another directory is still removed
     * (review of the fix). The same read keeps a row's own file, spelt otherwise, wherever that file has a hard link: only a
     * volume that folds — case, or normalization, which a case-sensitive APFS volume still folds — makes it; on one that
     * folds nothing, every name a row reaches is one its directory holds byte for byte (review of slice 5c).
     */
    it('keeps what a row reaches under another spelling beside a hard link, and removes one elsewhere', function (string $fold): void {
        $sole = storedForPrune($this->imageType);
        $pair = storedForPrune($this->imageType);
        $dir = dirname($sole->path);
        // The files in NFC; the rows in upper case, or in NFD.
        rename($this->root.'/'.$sole->path, $this->root.'/'.$dir.'/sol'."\u{e9}".'.png');
        rename($this->root.'/'.$pair->path, $this->root.'/'.$dir.'/t1'."\u{e9}".'.png');
        [$soleRow, $pairRow] = $fold === 'case'
            ? [$dir.'/SOL'."\u{c9}".'.PNG', $dir.'/T1'."\u{c9}".'.PNG']
            : [$dir.'/sole'."\u{301}".'.png', $dir.'/t1e'."\u{301}".'.png'];

        if (@stat($this->root.'/'.$soleRow) === false) {
            test()->markTestSkipped('this volume does not fold '.$fold);
        }

        @mkdir($this->root.'/media/'.$this->org->getKey().'/2026/10', 0777, true);
        link($this->root.'/'.$dir.'/sol'."\u{e9}".'.png', $this->root.'/media/'.$this->org->getKey().'/2026/10/sole-link.png');
        link($this->root.'/'.$dir.'/t1'."\u{e9}".'.png', $this->root.'/'.$dir.'/t2.png');
        DB::table('media_files')->where('id', $sole->getKey())->update(['path' => $soleRow]);
        DB::table('media_files')->where('id', $pair->getKey())->update(['path' => $pairRow]);

        $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);

        expect(Artisan::output())->toContain('Removed 1 of 1 orphaned or leftover file')
            ->and(is_file($this->root.'/'.$dir.'/sol'."\u{e9}".'.png'))->toBeTrue()
            ->and(file_exists($this->root.'/media/'.$this->org->getKey().'/2026/10/sole-link.png'))->toBeFalse()
            ->and(is_file($this->root.'/'.$dir.'/t1'."\u{e9}".'.png'))->toBeTrue()
            ->and(is_file($this->root.'/'.$dir.'/t2.png'))->toBeTrue()
            ->and($exit)->toBe(0);
    })->with(['in another case' => 'case', 'in another normalization' => 'normalization']);

    /*
     * ...and beside a row's partial path spelt otherwise, only a name custody could have written — one ending in the partial
     * suffix — is taken for that row's leftover partial copy: a hard link of it under another name, which the volume
     * cannot tell from the entry the partial path reaches, stays an orphan, asked again under the lock by its own name
     * (review of slice 5c). Only a volume that folds makes it.
     */
    it('takes only a partial copy by its name for a row\'s partial path, and leaves a hard link of it an orphan', function (string $fold): void {
        $kept = storedForPrune($this->imageType);
        $dir = dirname($kept->path);
        rename($this->root.'/'.$kept->path, $this->root.'/'.$dir.'/sol'."\u{e9}".'.png');
        $partial = $dir.'/sol'."\u{e9}".'.png'.MediaBytes::PARTIAL;
        file_put_contents($this->root.'/'.$partial, 'part');
        link($this->root.'/'.$partial, $this->root.'/'.$dir.'/unrelated.bin');
        $row = $fold === 'case' ? $dir.'/SOL'."\u{c9}".'.PNG' : $dir.'/sole'."\u{301}".'.png';

        if (@stat($this->root.'/'.$row.MediaBytes::PARTIAL) === false) {
            test()->markTestSkipped('this volume does not fold '.$fold);
        }

        DB::table('media_files')->where('id', $kept->getKey())->update(['path' => $row]);

        Artisan::call('kitsune:media-prune');
        $output = Artisan::output();
        $orphans = strpos($output, 'Orphaned media files');
        $partials = strpos($output, 'Leftover partial copies');
        $link = strpos($output, '  ['.MediaDisks::PRIVATE.']  '.$dir.'/unrelated.bin');

        expect($orphans)->not->toBeFalse()
            ->and($partials)->not->toBeFalse()
            ->and($link)->not->toBeFalse()
            ->and($link)->toBeGreaterThan((int) $orphans)
            ->and($link)->toBeLessThan((int) $partials)
            ->and($output)->toContain('entry '.$kept->entry_id.'  ['.MediaDisks::PRIVATE.']  '.$partial)
            ->and($output)->not->toContain('entry '.$kept->entry_id.'  ['.MediaDisks::PRIVATE.']  '.$dir.'/unrelated.bin');

        $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);

        expect(Artisan::output())->toContain('Removed 2 of 2 orphaned or leftover files')
            ->and(is_file($this->root.'/'.$dir.'/sol'."\u{e9}".'.png'))->toBeTrue()
            ->and($exit)->toBe(0);
    })->with(['in another case' => 'case', 'in another normalization' => 'normalization']);

    /*
     * ...and so where a link at a row's partial path reaches another listed file: that file, under a name custody never
     * writes, stays an orphan, and is not listed as the row's partial copy (review of slice 5c). Reached through a link the
     * listing skips, so every volume makes it.
     */
    it('takes only a partial copy by its name where a link at a row\'s partial path reaches another listed file', function (): void {
        config(['filesystems.disks.'.MediaDisks::PRIVATE => ['driver' => 'local', 'root' => $this->root, 'links' => 'skip']]);
        Storage::forgetDisk(MediaDisks::PRIVATE);
        $kept = storedForPrune($this->imageType);
        $dir = dirname($kept->path);
        file_put_contents($this->root.'/'.$dir.'/unrelated.bin', 'x');
        symlink($this->root.'/'.$dir.'/unrelated.bin', $this->root.'/'.$kept->path.MediaBytes::PARTIAL);
        // A partial copy listed, so the pass asks each row's partial path.
        file_put_contents($this->root.'/'.$dir.'/x.png'.MediaBytes::PARTIAL, 'part');

        $exit = Artisan::call('kitsune:media-prune');
        $output = Artisan::output();
        $orphans = strpos($output, 'Orphaned media files');
        $link = strpos($output, '  ['.MediaDisks::PRIVATE.']  '.$dir.'/unrelated.bin');

        expect($orphans)->not->toBeFalse()
            ->and($link)->toBeGreaterThan((int) $orphans)
            ->and($output)->not->toContain('entry '.$kept->entry_id.'  ['.MediaDisks::PRIVATE.']  '.$dir.'/unrelated.bin')
            ->and($output)->not->toContain('Leftover partial copies')
            ->and($exit)->toBe(0);
    });

    // ...and custody's own removal of an extra copy asks the partial name beside it as absent too: the copy was removed,
    // and the run failed saying whether the partial was there could not be told (review of slice 5c).
    it('removes an extra copy of a row whose partial name the volume refuses as too long', function (): void {
        $kept = storedForPrune($this->imageType);
        $long = 'media/'.str_repeat('a', 245).'.png';
        @mkdir($this->root.'/media', 0777, true);
        rename($this->root.'/'.$kept->path, $this->root.'/'.$long);
        DB::table('media_files')->where('id', $kept->getKey())->update(['path' => $long]);
        Storage::disk('public')->put($long, 'stale');

        $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);

        expect(Artisan::output())->toContain('Removed 0 of 0 orphaned or leftover files and 1 of 1 extra copy.')
            ->and(Storage::disk('public')->exists($long))->toBeFalse()
            ->and(is_file($this->root.'/'.$long))->toBeTrue()
            ->and($exit)->toBe(0);
    });

    // ...and so is one PHP refuses, its path `PHP_MAXPATHLEN - 1` bytes or more, which it reports as EIO: a row's path
    // under directories deep enough failed every run on a stray partial copy. Only SQLite's column holds such a path.
    it('reads a row\'s partial path longer than PHP takes as absent', function (): void {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            test()->markTestSkipped('only SQLite holds a path longer than 255 characters');
        }

        $kept = storedForPrune($this->imageType);
        $dir = 'media';

        while (strlen($this->root.'/'.$dir) < PHP_MAXPATHLEN - 200) {
            $dir .= '/'.str_repeat('d', 150);
        }

        mkdir($this->root.'/'.$dir, 0777, true);
        // The row's own path short of the limit, and its partial path past it.
        $row = $dir.'/'.str_repeat('f', PHP_MAXPATHLEN - 10 - strlen($this->root.'/'.$dir));
        rename($this->root.'/'.$kept->path, $this->root.'/'.$row);
        DB::table('media_files')->where('id', $kept->getKey())->update(['path' => $row]);
        $stray = dirname($kept->path).'/stray.png'.MediaBytes::PARTIAL;
        file_put_contents($this->root.'/'.$stray, 'part');

        $exit = Artisan::call('kitsune:media-prune');

        expect(Artisan::output())->toContain('  ['.MediaDisks::PRIVATE.']  '.$stray)->and($exit)->toBe(0);

        $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);

        expect(is_file($this->root.'/'.$stray))->toBeFalse()
            ->and(is_file($this->root.'/'.$row))->toBeTrue()
            ->and($exit)->toBe(0);
    });

    // ...and a row's own path so refused fails the run, read-only and forced, and nothing is removed: whether its file is
    // there cannot be told, as the volume says. APFS counts a name's characters and ext4 its bytes, so the name is 300
    // ASCII characters, which only SQLite's column holds (review of slice 5c).
    it('fails the run on a row\'s own path the volume refuses as too long', function (bool $force): void {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            test()->markTestSkipped('only SQLite holds a path longer than 255 characters');
        }

        $kept = storedForPrune($this->imageType);
        DB::table('media_files')->where('id', $kept->getKey())->update(['path' => 'media/'.str_repeat('a', 300).'.png']);
        $stray = dirname($kept->path).'/stray.png';
        file_put_contents($this->root.'/'.$stray, 'x');

        expect(fn () => Artisan::call('kitsune:media-prune', $force ? ['--force' => true] : []))
            ->toThrow(MediaCustodyFailure::class, 'whether it exists cannot be told');
        expect(is_file($this->root.'/'.$stray))->toBeTrue()
            ->and(is_file($this->root.'/'.$kept->path))->toBeTrue();
    })->with(['read-only' => false, 'forced' => true]);

    /*
     * ...and a partial copy the listing found is asked too: where a row's own path reaches it — through a link the listing
     * skips, or in a spelling written past `MediaFile` — it is that row's file, never the partial copy another row's lock
     * removes, which cost the row its only file (review of slice 5c). A link every volume makes; the spelling only a
     * folding one. A partial copy no row's own path reaches is still its row's, and removed.
     */
    it('takes a listed partial copy a row\'s own path reaches for that row\'s file', function (string $case): void {
        config(['filesystems.disks.'.MediaDisks::PRIVATE => ['driver' => 'local', 'root' => $this->root, 'links' => 'skip']]);
        Storage::forgetDisk(MediaDisks::PRIVATE);
        [$a, $b, $c] = [storedForPrune($this->imageType), storedForPrune($this->imageType), storedForPrune($this->imageType)];
        // B's only file under A's partial name; C's partial copy a real one, which no row's own path reaches; and a partial
        // copy no row's path is written for, an orphan, so the pass asks each row's partial path too — which never takes a
        // partial copy from the row the listing gave it.
        $partial = $a->path.MediaBytes::PARTIAL;
        rename($this->root.'/'.$b->path, $this->root.'/'.$partial);
        $alone = $case === 'alone';

        if (! $alone) {
            file_put_contents($this->root.'/'.$c->path.MediaBytes::PARTIAL, 'half');
            file_put_contents($this->root.'/'.dirname($a->path).'/gone.png'.MediaBytes::PARTIAL, 'half');
        }

        if ($case === 'spelling') {
            $row = $a->path.'.KITSUNE-PARTIAL';

            if (@stat($this->root.'/'.$row) === false) {
                test()->markTestSkipped('this volume does not fold case');
            }
        } else {
            $row = dirname($a->path).'/link.png';
            symlink($this->root.'/'.$partial, $this->root.'/'.$row);
        }

        $named = $case === 'another disk' ? 'legacy' : MediaDisks::PRIVATE;
        config(['filesystems.disks.legacy' => ['driver' => 'local', 'root' => $this->root.'-legacy']]);
        DB::table('media_files')->where('id', $b->getKey())->update(['path' => $row, 'disk' => $named]);

        try {
            $exit = Artisan::call('kitsune:media-prune');
            $output = Artisan::output();

            expect($output)->not->toContain('entry '.$a->entry_id.'  ['.MediaDisks::PRIVATE.']  '.$partial)
                ->and($output)->toContain($named === 'legacy'
                    ? 'entry '.$b->entry_id.'  ['.MediaDisks::PRIVATE.']  '.$partial.' — its row names [legacy] '.$row
                    : ($alone ? 'No orphaned media files.' : 'Leftover partial copies'))
                ->and($exit)->toBe(0);

            // Alone, the disk's only partial copy taken for the row's own file leaves no heading behind it; beside C's, C's
            // stays C's.
            if ($alone) {
                expect($output)->not->toContain('Leftover partial copies');
            } else {
                expect($output)->toContain('entry '.$c->entry_id.'  ['.MediaDisks::PRIVATE.']  '.$c->path.MediaBytes::PARTIAL);
            }

            $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);

            expect(Artisan::output())->toContain($alone ? 'No orphaned media files.' : 'Removed 2 of 2 orphaned or leftover files and')
                ->and(is_file($this->root.'/'.$partial))->toBeTrue()
                ->and(file_exists($this->root.'/'.$c->path.MediaBytes::PARTIAL))->toBeFalse()
                ->and($exit)->toBe(0);
        } finally {
            exec('rm -rf '.escapeshellarg($this->root.'-legacy'));
        }
    })->with([
        'through a link the listing skips' => 'link',
        '...the disk\'s only partial copy' => 'alone',
        '...for a row naming another disk' => 'another disk',
        'in a spelling a folding volume reads as the name' => 'spelling',
    ]);

    /*
     * ...and a row's partial path the volume refuses as too long is absent — custody never wrote one — where a row's own
     * path, 255 characters and legal, made every run fail on a stray partial copy it had nothing to do with; the row's own
     * path so refused still fails the run (review of slice 5c).
     */
    it('reads a row\'s partial path the volume refuses as too long as absent', function (): void {
        $kept = storedForPrune($this->imageType);
        $long = 'media/'.str_repeat('a', 245).'.png';
        rename($this->root.'/'.$kept->path, $this->root.'/'.$long);
        DB::table('media_files')->where('id', $kept->getKey())->update(['path' => $long]);
        $stray = dirname($kept->path).'/stray.png'.MediaBytes::PARTIAL;
        file_put_contents($this->root.'/'.$stray, 'part');

        $exit = Artisan::call('kitsune:media-prune');

        expect(Artisan::output())->toContain('  ['.MediaDisks::PRIVATE.']  '.$stray)
            ->and($exit)->toBe(0);

        $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);

        expect(Artisan::output())->toContain('Removed 1 of 1 orphaned or leftover file and')
            ->and(is_file($this->root.'/'.$stray))->toBeFalse()
            ->and(is_file($this->root.'/'.$long))->toBeTrue()
            ->and($exit)->toBe(0);
    });

    // ...and a row's path written past `MediaFile` in a form the disks read as another — the insert-path gap — reaches the
    // file it is read as, which is that row's, and listed nowhere: `--force` removed the row's only file (Codex, #155).
    it('lists nowhere the file a row\'s path the disks read as another reaches', function (string $spelt): void {
        $kept = storedForPrune($this->imageType);
        $dir = dirname($kept->path);
        $row = $spelt === 'slash' ? $dir.'//'.basename($kept->path) : $dir.'/y/../'.basename($kept->path);
        DB::table('media_files')->where('id', $kept->getKey())->update(['path' => $row]);

        $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);
        $output = Artisan::output();

        // Listed nowhere, not as another disk's row's copy (review of slice 5c).
        expect($output)->toContain('No orphaned media files.')
            ->and($output)->not->toContain('Copies a row reaches')
            ->and(is_file($this->root.'/'.$kept->path))->toBeTrue()
            ->and($exit)->toBe(0);
    })->with(['a doubled slash' => 'slash', 'a parent segment' => 'parent']);

    // ...and one a row naming another disk reaches is listed as a copy under another spelling, and kept, its line saying
    // what settles it: a misnamed row's path corrected, or — where settle removes the row's copies there — a forced
    // reconcile, through the volume's fold (Codex, #155; review of the fix).
    it('lists a copy a row reaches under another spelling, on a disk it does not name, and keeps it', function (string $spelt): void {
        $kept = storedApartForPrune($this->imageType);
        $dir = dirname($kept->path);
        $name = $dir.'/keptphoto.png';
        rename($this->root.'/'.$kept->path, $this->root.'/'.$name);
        $row = $spelt === 'fold' ? $dir.'/KeptPhoto.PNG' : $dir.'//keptphoto.png';

        if ($spelt === 'fold' && @stat($this->root.'/'.$row) === false) {
            test()->markTestSkipped('this volume does not fold case');
        }

        DB::table('media_files')->where('id', $kept->getKey())->update(['disk' => 'public', 'visibility' => 'public', 'path' => $row]);

        $exit = Artisan::call('kitsune:media-prune');
        $output = Artisan::output();

        expect($output)->toContain('Copies a row reaches under another spelling, on a disk it does not name')
            // The public disk holds nothing: the check lists the row absent before it would a private copy (review of 5c).
            ->and($output)->toContain('entry '.$kept->entry_id.'  ['.MediaDisks::PRIVATE.']  '.$name.' — its row names [public] '.$row.': '.($spelt === 'fold'
                ? 'kitsune:media-reconcile --force removes it, or refuses it as coinciding where the two are one file, left for a hand, and the check lists the row absent'
                : 'its row\'s path is not written as the disks read it — correct media_files.path to the path its file is under, as the disks read it'))
            ->and($output)->toContain('No orphaned media files.')
            ->and($exit)->toBe(0);

        $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);

        expect(is_file($this->root.'/'.$name))->toBeTrue()->and($exit)->toBe(0);
    })->with(['in another case' => 'fold', 'with a doubled slash' => 'slash']);

    /*
     * ...and a copy on a private disk of a file that belongs on the web names the label the check gives its row, in the
     * check's own order — `awaiting publication` where the row names another disk, `absent` where the public disk does not
     * hold its file, `private copy` otherwise — and a forced reconcile removes it, as its line says (review of slice 5c):
     * on core's disk, the configured private disk or not, and on a host's disk configured private. Reached through a link
     * the private disk's listing skips, so every volume makes it.
     */
    it('names the label the check gives a copy on the private disk a public row reaches under another name', function (string $label, string $where): void {
        $other = sys_get_temp_dir().'/kitsune-prune-other-'.bin2hex(random_bytes(4));
        mkdir($other, 0777, true);

        try {
            if ($where === 'host') {
                // The host's disk, configured private, holds the copy; core's disk is empty.
                config(['filesystems.disks.'.MediaDisks::PRIVATE => ['driver' => 'local', 'root' => $other]]);
                config(['filesystems.disks.host-private' => ['driver' => 'local', 'root' => $this->root, 'links' => 'skip'], 'kitsune.media.disks.private' => 'host-private']);
                Storage::forgetDisk(MediaDisks::PRIVATE);
            } else {
                config(['filesystems.disks.'.MediaDisks::PRIVATE => ['driver' => 'local', 'root' => $this->root, 'links' => 'skip']]);
                Storage::forgetDisk(MediaDisks::PRIVATE);
            }

            $kept = storedForPrune($this->imageType);
            $row = dirname($kept->path, 2).'/lnk/'.basename($kept->path);
            symlink($this->root.'/'.dirname($kept->path), $this->root.'/'.dirname($row));
            $named = $label === 'awaiting publication' ? 'legacy' : 'public';
            $copy = $where === 'host' ? 'host-private' : MediaDisks::PRIVATE;

            if ($where === 'core beside host') {
                // Core's disk holds the copy while a host's disk is the configured private one.
                config(['filesystems.disks.host-private' => ['driver' => 'local', 'root' => $other], 'kitsune.media.disks.private' => 'host-private']);
            }

            if ($label === 'awaiting publication') {
                config(['filesystems.disks.legacy' => ['driver' => 'local', 'root' => $other.'/legacy']]);
                Storage::disk('legacy')->put($row, pruneFixtureBytes());
            } elseif ($label === 'private copy') {
                Storage::disk('public')->put($row, pruneFixtureBytes());
            }

            DB::table('media_files')->where('id', $kept->getKey())->update(['disk' => $named, 'visibility' => 'public', 'path' => $row]);

            $exit = Artisan::call('kitsune:media-prune');

            expect(Artisan::output())->toContain('entry '.$kept->entry_id.'  ['.$copy.']  '.$kept->path.' — its row names ['.$named.'] '.$row.': kitsune:media-reconcile --force removes it, or refuses it as coinciding where the two are one file, left for a hand, and the check lists the row '.$label)
                ->and($exit)->toBe(0);

            $exit = Artisan::call('kitsune:media-reconcile');

            expect(Artisan::output())->toContain(str_pad($label, 20).' entry '.$kept->entry_id.'  live  ['.$row.']  names '.$named)
                ->and($exit)->toBe(1);

            Artisan::call('kitsune:media-reconcile', ['--force' => true]);

            expect(file_exists($this->root.'/'.$kept->path))->toBeFalse();
        } finally {
            exec('rm -rf '.escapeshellarg($other));
        }
    })->with([
        'private copy' => ['private copy', 'core'],
        'absent' => ['absent', 'core'],
        'awaiting publication' => ['awaiting publication', 'core'],
        'private copy, on core\'s disk beside a host\'s private disk' => ['private copy', 'core beside host'],
        'absent, on core\'s disk beside a host\'s private disk' => ['absent', 'core beside host'],
        'private copy, on a host\'s disk configured private' => ['private copy', 'host'],
        'absent, on a host\'s disk configured private' => ['absent', 'host'],
    ]);

    /*
     * ...and where the disk its row names stands in the way of the forced reconcile the line would name — it could not be
     * listed, it reaches the target's files, or it reads through — the line says so, as an extra copy's does, and never
     * that reconcile takes the copy off or removes it (review of slice 5c). Reached through a link the listing skips, so
     * every volume makes it.
     */
    it('says what stands in the way where reconcile cannot settle a copy under another spelling', function (string $case): void {
        $other = sys_get_temp_dir().'/kitsune-prune-other-'.bin2hex(random_bytes(4));
        mkdir($other, 0777, true);

        try {
            $kept = storedForPrune($this->imageType);
            $lnk = dirname($kept->path, 2).'/lnk';
            $row = $lnk.'/'.basename($kept->path);

            if ($case === 'unlisted, on the web') {
                // The web lists the private file's copy under the month; the row names a disk nothing configures.
                config(['filesystems.disks.public' => ['driver' => 'local', 'root' => $other, 'url' => 'https://web.example.test', 'links' => 'skip']]);
                Storage::forgetDisk('public');
                Storage::disk('public')->put($kept->path, pruneFixtureBytes());
                symlink($other.'/'.dirname($kept->path), $other.'/'.$lnk);
                Storage::disk(MediaDisks::PRIVATE)->delete($kept->path);
                [$copy, $named, $visibility] = ['public', 'ghost', 'private'];
            } else {
                config(['filesystems.disks.'.MediaDisks::PRIVATE => ['driver' => 'local', 'root' => $this->root, 'links' => 'skip']]);
                Storage::forgetDisk(MediaDisks::PRIVATE);
                symlink($this->root.'/'.dirname($kept->path), $this->root.'/'.$lnk);
                [$copy, $visibility] = [MediaDisks::PRIVATE, 'public'];
                $named = match ($case) {
                    'unlisted' => 'ghost',
                    default => 'rt',
                };

                if ($case === 'overlapping') {
                    // A read-through disk over the public one: one place with it, which reconcile moves no row off.
                    config(['filesystems.disks.rt-fallback' => ['driver' => 'local', 'root' => $other.'/f'], 'filesystems.disks.rt' => ['driver' => 'read-through', 'primary' => 'public', 'fallback' => 'rt-fallback']]);
                } elseif ($case === 'read-through') {
                    // A read-through disk over two others, whose primary holds the file at the row's path.
                    config([
                        'filesystems.disks.rt-primary' => ['driver' => 'local', 'root' => $other.'/p'],
                        'filesystems.disks.rt-fallback' => ['driver' => 'local', 'root' => $other.'/f'],
                        'filesystems.disks.rt' => ['driver' => 'read-through', 'primary' => 'rt-primary', 'fallback' => 'rt-fallback'],
                    ]);
                    Storage::disk('rt-primary')->put($row, pruneFixtureBytes());
                } elseif ($case === 'read-through, built') {
                    // The same, built by a driver of the host's own, which only its instance says reads through.
                    Storage::extend('mirror', static fn ($app) => $app['filesystem']->createReadThroughDriver([
                        'driver' => 'read-through',
                        'primary' => ['driver' => 'local', 'root' => $other.'/p'],
                        'fallback' => ['driver' => 'local', 'root' => $other.'/f'],
                    ], 'rt'));
                    config(['filesystems.disks.rt' => ['driver' => 'mirror']]);
                    mkdir($other.'/p/'.dirname($row), 0777, true);
                    mkdir($other.'/f', 0777, true);
                    file_put_contents($other.'/p/'.$row, pruneFixtureBytes());
                }
            }

            DB::table('media_files')->where('id', $kept->getKey())->update(['disk' => $named, 'visibility' => $visibility, 'path' => $row]);

            $exit = Artisan::call('kitsune:media-prune');
            $output = Artisan::output();

            expect($output)->toContain('entry '.$kept->entry_id.'  ['.$copy.']  '.$kept->path.' — its row names ['.$named.'] '.$row.': '.match ($case) {
                'overlapping' => 'kept: its row names [rt], which reaches [public]\'s files or cannot be told apart from it — kitsune:media-reconcile --force moves no row off it, and removes no copy through it',
                'read-through', 'read-through, built' => 'kept: its row names [rt], a read-through disk, which custody neither reads nor removes a copy through — while [rt] holds the file, kitsune:media-reconcile --force cannot move its row',
                default => 'kept: [ghost], which its row names, could not be built — kitsune:media-reconcile --force settles its row only once that disk can be asked: run kitsune:media-prune again once it can be',
            })
                ->and($output)->not->toContain('kitsune:media-reconcile --force removes it')
                ->and($output)->not->toContain('takes it off')
                ->and($exit)->toBe(str_starts_with($case, 'unlisted') ? 1 : 0);
        } finally {
            exec('rm -rf '.escapeshellarg($other));
        }
    })->with([
        'a public file\'s copy on core\'s disk, its row naming a disk that could not be listed' => 'unlisted',
        'a private file\'s copy on the web, its row naming a disk that could not be listed' => 'unlisted, on the web',
        'a public file\'s copy on core\'s disk, its row naming a read-through disk over the public one' => 'overlapping',
        'a public file\'s copy on core\'s disk, its row naming a read-through disk over two others' => 'read-through',
        'a public file\'s copy on core\'s disk, its row naming a read-through disk a host\'s driver builds' => 'read-through, built',
    ]);

    /*
     * ...and where the copy has another name beside it, a hard link, and the row's path spelt otherwise may reach either,
     * settle removes only the name it reaches: the line says so, and that the other stays until the next run lists it as
     * an orphan — never that reconcile takes this very copy off (review of slice 5c). Only a volume that folds makes it; in
     * another normalization, the case-sensitive one does too.
     */
    it('says a forced reconcile removes only the name the row\'s path reaches, where a hard link sits beside it', function (string $case): void {
        $other = sys_get_temp_dir().'/kitsune-prune-other-'.bin2hex(random_bytes(4));
        mkdir($other, 0777, true);

        try {
            $kept = storedForPrune($this->imageType);
            $dir = dirname($kept->path);
            $nfc = $dir.'/caf'."\u{e9}".'.png';
            $nfd = $dir.'/cafe'."\u{301}".'.png';

            if ($case === 'on the web') {
                // A private row on core's disk; the web holds the file under the other normalization, and a hard link.
                config(['filesystems.disks.public' => ['driver' => 'local', 'root' => $other, 'url' => 'https://web.example.test']]);
                Storage::forgetDisk('public');
                rename($this->root.'/'.$kept->path, $this->root.'/'.$nfd);
                Storage::disk('public')->put($nfc, pruneFixtureBytes());
                [$copy, $root, $named, $label, $stays] = ['public', $other, MediaDisks::PRIVATE, 'exposed', 'stays on the web'];
            } else {
                // A public row on public; core's disk holds the file under the other normalization, and a hard link.
                config(['filesystems.disks.public' => ['driver' => 'local', 'root' => $other, 'url' => 'https://web.example.test']]);
                Storage::forgetDisk('public');
                Storage::disk('public')->put($nfd, pruneFixtureBytes());
                rename($this->root.'/'.$kept->path, $this->root.'/'.$nfc);
                [$copy, $root, $named, $label, $stays] = [MediaDisks::PRIVATE, $this->root, 'public', 'private copy', 'stays'];
            }

            link($root.'/'.$nfc, $root.'/'.$dir.'/other.png');

            if (@stat($root.'/'.$nfd) === false || stat($root.'/'.$nfd)['ino'] !== stat($root.'/'.$nfc)['ino']) {
                test()->markTestSkipped('this volume does not fold normalization');
            }

            DB::table('media_files')->where('id', $kept->getKey())->update(['disk' => $named, 'visibility' => $case === 'on the web' ? 'private' : 'public', 'path' => $nfd]);

            $exit = Artisan::call('kitsune:media-prune');

            expect(Artisan::output())->toContain('entry '.$kept->entry_id.'  ['.$copy.']  '.$dir.'/other.png — its row names ['.$named.'] '.$nfd.': '.($case === 'on the web' ? 'on the web, and its file belongs off it — ' : '').'this file has another name beside it here, and the volume does not say which of them its row\'s path reaches: kitsune:media-reconcile --force removes that one, or refuses it as coinciding where the two are one file, left for a hand, and the check lists the row '.$label.'; where it is not this one, this copy '.$stays.', and the next run lists it as an orphan, which --force removes')
                ->and($exit)->toBe(0);

            Artisan::call('kitsune:media-reconcile', ['--force' => true]);

            // The name the row's path reaches gone, the hard link beside it left: the next run lists it as an orphan.
            expect(is_file($root.'/'.$dir.'/other.png'))->toBeTrue();

            Artisan::call('kitsune:media-prune');

            expect(Artisan::output())->toContain('  ['.$copy.']  '.$dir.'/other.png');
        } finally {
            exec('rm -rf '.escapeshellarg($other));
        }
    })->with(['on the web', 'private copy']);

    /*
     * ...and on the disk its file belongs on, where the line says to make the spellings one, the other name of the file
     * beside it is said to be one the next run lists as an orphan, not as an extra copy (review of slice 5c). Only a volume
     * that folds makes it; in another normalization, the case-sensitive one does too.
     */
    it('says the other name beside a copy on the disk its file belongs on is one the next run lists as an orphan', function (): void {
        $other = sys_get_temp_dir().'/kitsune-prune-other-'.bin2hex(random_bytes(4));
        mkdir($other, 0777, true);

        try {
            config(['filesystems.disks.legacy' => ['driver' => 'local', 'root' => $other]]);
            $kept = storedForPrune($this->imageType);
            $dir = dirname($kept->path);
            $nfc = $dir.'/caf'."\u{e9}".'.png';
            $nfd = $dir.'/cafe'."\u{301}".'.png';
            rename($this->root.'/'.$kept->path, $this->root.'/'.$nfc);
            link($this->root.'/'.$nfc, $this->root.'/'.$dir.'/other.png');

            if (@stat($this->root.'/'.$nfd) === false || stat($this->root.'/'.$nfd)['ino'] !== stat($this->root.'/'.$nfc)['ino']) {
                test()->markTestSkipped('this volume does not fold normalization');
            }

            // A private row naming another disk: core's disk, where its file belongs, holds it under the other spelling.
            DB::table('media_files')->where('id', $kept->getKey())->update(['disk' => 'legacy', 'path' => $nfd]);

            $exit = Artisan::call('kitsune:media-prune');
            $output = Artisan::output();

            foreach ([$nfc, $dir.'/other.png'] as $listed) {
                expect($output)->toContain('entry '.$kept->entry_id.'  ['.MediaDisks::PRIVATE.']  '.$listed.' — its row names [legacy] '.$nfd.': make the row\'s path and this name one spelling, with the name [legacy] holds its file under where it holds one — rename the files, or correct media_files.path — and the next run lists this one as an extra copy; any other name of this file here the next run lists as an orphan');
            }

            expect($output)->not->toContain('kitsune:media-reconcile --force removes')->and($exit)->toBe(0);
        } finally {
            exec('rm -rf '.escapeshellarg($other));
        }
    });

    /*
     * ...and where the copy is the very file the target holds at the row's path — a hard link across the two roots — a
     * forced reconcile refuses it as coinciding, and removes nothing: the line says so, as an extra copy's does (review of
     * slice 5c). Reached through a link the listing skips, so every volume makes it.
     */
    it('says a forced reconcile refuses a copy under another spelling that is the very file the target holds', function (): void {
        $other = sys_get_temp_dir().'/kitsune-prune-other-'.bin2hex(random_bytes(4));
        mkdir($other, 0777, true);

        try {
            config(['filesystems.disks.'.MediaDisks::PRIVATE => ['driver' => 'local', 'root' => $this->root, 'links' => 'skip']]);
            config(['filesystems.disks.public' => ['driver' => 'local', 'root' => $other, 'url' => 'https://web.example.test']]);
            Storage::forgetDisk([MediaDisks::PRIVATE, 'public']);
            $kept = storedForPrune($this->imageType);
            $row = dirname($kept->path, 2).'/lnk/'.basename($kept->path);
            symlink($this->root.'/'.dirname($kept->path), $this->root.'/'.dirname($row));
            // Public's name at the row's path is a hard link of core's copy: one file, reached through two disks.
            mkdir($other.'/'.dirname($row), 0777, true);
            link($this->root.'/'.$kept->path, $other.'/'.$row);
            DB::table('media_files')->where('id', $kept->getKey())->update(['disk' => 'public', 'visibility' => 'public', 'path' => $row]);

            $exit = Artisan::call('kitsune:media-prune');

            expect(Artisan::output())->toContain('entry '.$kept->entry_id.'  ['.MediaDisks::PRIVATE.']  '.$kept->path.' — its row names [public] '.$row.': kitsune:media-reconcile --force removes it, or refuses it as coinciding where the two are one file, left for a hand, and the check lists the row private copy')
                ->and($exit)->toBe(0);

            $exit = Artisan::call('kitsune:media-reconcile', ['--force' => true]);

            expect(Artisan::output())->toContain('the same file, reached through two disks')
                ->and(is_file($this->root.'/'.$kept->path))->toBeTrue()
                ->and($exit)->toBe(1);
        } finally {
            exec('rm -rf '.escapeshellarg($other));
        }
    });

    /*
     * ...and a read-through disk whose primary is local lists a local directory: it is asked by stat through that primary,
     * as a local disk is, never by key as an object store — a row's file there, reached through a link the listing skips,
     * was listed as an orphan no row keeps, to be removed by hand (review of slice 5c). A primary that is itself a
     * read-through disk over a local one is followed down, as `MediaBytes::listsLocally()` says. Every volume makes it.
     */
    it('asks a read-through disk over a local primary by stat, and lists nowhere the file a row reaches there', function (int $depth): void {
        $other = sys_get_temp_dir().'/kitsune-prune-other-'.bin2hex(random_bytes(4));

        try {
            mkdir($other.'/p', 0777, true);
            mkdir($other.'/f', 0777, true);
            mkdir($other.'/f2', 0777, true);
            config([
                'filesystems.disks.rtp' => ['driver' => 'local', 'root' => $other.'/p', 'links' => 'skip'],
                'filesystems.disks.rtf' => ['driver' => 'local', 'root' => $other.'/f'],
                'filesystems.disks.rt' => ['driver' => 'read-through', 'primary' => 'rtp', 'fallback' => 'rtf'],
                'filesystems.disks.rtf2' => ['driver' => 'local', 'root' => $other.'/f2'],
                'filesystems.disks.rt2' => ['driver' => 'read-through', 'primary' => 'rt', 'fallback' => 'rtf2'],
            ]);
            $kept = storedForPrune($this->imageType);
            $dir = dirname($kept->path);
            // The row's name on the primary a link to real.png beside it, which the listing gives in its place.
            mkdir($other.'/p/'.$dir, 0777, true);
            file_put_contents($other.'/p/'.$dir.'/real.png', pruneFixtureBytes());
            symlink($other.'/p/'.$dir.'/real.png', $other.'/p/'.$kept->path);
            Storage::disk(MediaDisks::PRIVATE)->delete($kept->path);
            DB::table('media_files')->where('id', $kept->getKey())->update(['disk' => $depth === 1 ? 'rt' : 'rt2']);
            DB::table('entries')->where('id', $kept->entry_id)->update(['deleted_at' => now()]);

            foreach ([[], ['--force' => true]] as $options) {
                $exit = Artisan::call('kitsune:media-prune', $options);
                $output = Artisan::output();

                expect($output)->toContain('No orphaned media files.')
                    ->and($output)->not->toContain('read-through disk')
                    ->and($exit)->toBe(0);
            }

            expect(is_file($other.'/p/'.$dir.'/real.png'))->toBeTrue();
        } finally {
            exec('rm -rf '.escapeshellarg($other));
        }
    })->with(['one level' => 1, 'two levels' => 2]);

    /*
     * ...and a name below something that is not a directory — a stray file where a row's directory would be — is absent,
     * as the volume says: PHP reports that ENOTDIR as EIO, and the run failed on every such row, read-only too, so the
     * stray itself was never listed (review of slice 5c). Every volume makes it.
     */
    it('lists a stray file where a row\'s directory would be, and fails nothing for the rows below it', function (): void {
        $kept = storedForPrune($this->imageType);
        $org = 'media/'.$this->org->getKey();
        // A regular file on public where the row's media/<org> directory would be.
        Storage::disk('public')->put($org, 'a stray');

        foreach ([[], ['--force' => true]] as $n => $options) {
            $exit = Artisan::call('kitsune:media-prune', $options);
            $output = Artisan::output();

            expect($output)->toContain($n === 0 ? '  [public]  '.$org : 'Removed 1 of 1 orphaned or leftover file')
                ->and($exit)->toBe(0);
        }

        expect(Storage::disk('public')->exists($org))->toBeFalse()
            ->and(is_file($this->root.'/'.$kept->path))->toBeTrue();
    });

    /*
     * ...and a stat the volume refuses for a reason other than absence — I/O, permission, a network mount reconnecting —
     * fails the run before anything is removed, never read as "nothing there": the row's file, spelt otherwise, was left an
     * orphan and removed once the error cleared (review of slice 5c). A directory made unsearchable stands in for it, at
     * the pass's read of the table, or at the listing's lookup, before the listed names are asked.
     */
    it('fails the run, removing nothing, where the volume refuses a stat for a reason other than absence', function (string $side): void {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            test()->markTestSkipped('root searches a directory whatever its mode');
        }

        $kept = storedForPrune($this->imageType);
        $dir = dirname($kept->path);
        $file = $dir.'/keptphoto.png';
        rename($this->root.'/'.$kept->path, $this->root.'/'.$file);
        DB::table('media_files')->where('id', $kept->getKey())->update(['path' => $dir.'//keptphoto.png']);
        $locked = false;
        DB::listen(function (QueryExecuted $query) use ($side, $dir, &$locked): void {
            $pass = preg_match('/^select\W+id\W+,\W+entry_id\W+,\W+disk\W+,\W+path\W+from\W+media_files\W/i', $query->sql) === 1;
            $lookup = preg_match('/left join\W+entries\W/i', $query->sql) === 1 && ! $pass;

            if (! $locked && (($side === 'row' && $pass) || ($side === 'listed' && $lookup))) {
                $locked = chmod($this->root.'/'.$dir, 0000);
            }
        });

        try {
            expect(fn () => Artisan::call('kitsune:media-prune', ['--force' => true]))->toThrow(MediaCustodyFailure::class, 'whether it exists cannot be told');
        } finally {
            chmod($this->root.'/'.$dir, 0755);
        }

        expect($locked)->toBeTrue()
            ->and(is_file($this->root.'/'.$file))->toBeTrue();
    })->with(['at a row\'s path' => 'row', 'at a listed name' => 'listed']);

    /*
     * ...and every name is printed as it is: one holding what the console reads as a style — `<error>`, `<fg=red>` — was
     * stripped of it, and the line named another file than the one listed, or than --force removes (review of slice 5c).
     */
    it('prints each name as it is, whatever the console would read in it as a style', function (): void {
        $kept = storedForPrune($this->imageType);
        $dir = dirname($kept->path);
        file_put_contents($this->root.'/'.$dir.'/a<error>bc.png', 'bytes');
        file_put_contents($this->root.'/'.$dir.'/x<fg=red>y.png', 'bytes');
        // A row whose path holds a style, on public, with an extra copy on core's disk.
        $styled = $dir.'/r<info>ow.png';
        rename($this->root.'/'.$kept->path, $this->root.'/'.$styled);
        Storage::disk('public')->put($styled, pruneFixtureBytes());
        DB::table('media_files')->where('id', $kept->getKey())->update(['disk' => 'public', 'visibility' => 'public', 'path' => $styled]);

        Artisan::call('kitsune:media-prune');
        $output = Artisan::output();

        expect($output)->toContain('  ['.MediaDisks::PRIVATE.']  '.$dir.'/a<error>bc.png')
            ->and($output)->toContain('  ['.MediaDisks::PRIVATE.']  '.$dir.'/x<fg=red>y.png')
            ->and($output)->toContain(MediaPruneCommand::rowLine((object) ['entry_id' => $kept->entry_id, 'disk' => MediaDisks::PRIVATE, 'path' => $styled]));

        Artisan::call('kitsune:media-reconcile');

        expect(Artisan::output())->toContain('['.$styled.']');

        // ...a partial copy's line, and a row awaiting publication's.
        $partial = storedForPrune($this->imageType);
        $partialName = $dir.'/p<error>q.png';
        rename($this->root.'/'.$partial->path, $this->root.'/'.$partialName);
        DB::table('media_files')->where('id', $partial->getKey())->update(['path' => $partialName]);
        file_put_contents($this->root.'/'.$partialName.MediaBytes::PARTIAL, 'part');
        $awaiting = storedForPrune($this->imageType);
        $awaitingName = $dir.'/w<fg=red>a.png';
        rename($this->root.'/'.$awaiting->path, $this->root.'/'.$awaitingName);
        DB::table('media_files')->where('id', $awaiting->getKey())->update(['visibility' => 'public', 'path' => $awaitingName]);

        Artisan::call('kitsune:media-prune');
        $output = Artisan::output();

        expect($output)->toContain(MediaPruneCommand::rowLine((object) ['entry_id' => $partial->entry_id, 'disk' => MediaDisks::PRIVATE, 'path' => $partialName.MediaBytes::PARTIAL]))
            ->and($output)->toContain(MediaPruneCommand::rowLine((object) ['entry_id' => $awaiting->entry_id, 'disk' => MediaDisks::PRIVATE, 'path' => $awaitingName]));

        // ...and a removal that fails names the file it failed on.
        $this->private->failDeletes = true;
        Artisan::call('kitsune:media-prune', ['--force' => true]);

        expect(Artisan::output())->toContain('Could not remove ['.MediaDisks::PRIVATE.':'.$dir.'/a<error>bc.png]');

        // ...and reconcile's --entry refusal names what it was given.
        Artisan::call('kitsune:media-reconcile', ['--entry' => ['1<error>']]);

        expect(Artisan::output())->toContain('[1<error>] is not one');
    });

    // ...and every disk's name too: in a failed removal, a nesting refused and a disk not scanned (review of slice 5c).
    it('prints each disk\'s name as it is, whatever the console would read in it as a style', function (): void {
        $styled = sys_get_temp_dir().'/kitsune-prune-styled-'.bin2hex(random_bytes(4));
        mkdir($styled, 0777, true);

        try {
            config(['filesystems.disks.d<info>x' => ['driver' => 'local', 'root' => $styled]]);
            $disk = RefusingDisk::install('d<info>x', $styled);
            $kept = storedForPrune($this->imageType);
            DB::table('media_files')->where('id', $kept->getKey())->update(['disk' => 'd<info>x']);
            $orphan = dirname($kept->path).'/orphan.png';
            Storage::disk('d<info>x')->put($orphan, 'bytes');
            $disk->failDeletes = true;

            Artisan::call('kitsune:media-prune', ['--force' => true]);

            expect(Artisan::output())->toContain('Could not remove [d<info>x:'.$orphan.']');

            // A disk whose media directory is inside the public disk's.
            $inside = Storage::disk('public')->path('media/n');
            mkdir($inside, 0777, true);
            config(['filesystems.disks.d<info>x' => ['driver' => 'local', 'root' => $inside]]);
            Storage::forgetDisk('d<info>x');
            Artisan::call('kitsune:media-prune');

            expect(Artisan::output())->toContain('the media directory of [d<info>x] is inside [public]\'s');

            // A disk a row names that is core's private disk under another name.
            config(['filesystems.disks.d<info>x' => ['driver' => 'local', 'root' => $styled]]);
            config(['filesystems.disks.a<info>b' => ['driver' => 'local', 'root' => $this->root]]);
            Storage::forgetDisk('d<info>x');
            DB::table('media_files')->where('id', $kept->getKey())->update(['disk' => 'a<info>b']);
            Artisan::call('kitsune:media-prune');

            expect(Artisan::output())->toContain('Not scanning [a<info>b]: it is, or cannot be told apart from, ['.MediaDisks::PRIVATE.']');
        } finally {
            exec('rm -rf '.escapeshellarg($styled));
        }
    });

    /*
     * ...and where the private disk cannot be compared with core's — a read-through or scoped disk over one not configured
     * — core's is scanned, and its orphan listed: only a private disk that is core's under another name leaves it out
     * (review of slice 5c).
     */
    it('scans core\'s private disk when the private disk cannot be compared with it', function (array $hp): void {
        config(['filesystems.disks.hp' => $hp, 'kitsune.media.disks.private' => 'hp']);
        $orphan = 'media/'.$this->org->getKey().'/2026/09/orphan.png';
        @mkdir(dirname($this->root.'/'.$orphan), 0777, true);
        file_put_contents($this->root.'/'.$orphan, 'bytes');

        Artisan::call('kitsune:media-prune');
        $output = Artisan::output();

        expect($output)->toContain('['.MediaDisks::PRIVATE.']  '.$orphan)
            ->and($output)->not->toContain('Not scanning ['.MediaDisks::PRIVATE.']');
    })->with([
        'a read-through disk over one not configured' => [['driver' => 'read-through', 'primary' => 'public', 'fallback' => 'nowhere']],
        'a scoped disk over one not configured' => [['driver' => 'scoped', 'disk' => 'nowhere', 'prefix' => 'x']],
    ]);

    /*
     * ...and a row naming core's private disk while it reads through and a host's disk is the private one is no row on a
     * read-through disk reconcile cannot move: custody asks core's disk as the private one, and settle takes a copy on the
     * web off as for any private file (review of slice 5c). Reached through a link the web's listing skips.
     */
    it('says reconcile takes a copy on the web off for a row naming core\'s private disk while it reads through', function (): void {
        $other = sys_get_temp_dir().'/kitsune-prune-other-'.bin2hex(random_bytes(4));

        try {
            foreach (['vault', 'kp', 'kf', 'web'] as $root) {
                mkdir($other.'/'.$root, 0777, true);
            }

            config([
                'filesystems.disks.vault' => ['driver' => 'local', 'root' => $other.'/vault'],
                'kitsune.media.disks.private' => 'vault',
            ]);
            $kept = storedForPrune($this->imageType);
            config([
                'filesystems.disks.kp' => ['driver' => 'local', 'root' => $other.'/kp'],
                'filesystems.disks.kf' => ['driver' => 'local', 'root' => $other.'/kf'],
                'filesystems.disks.'.MediaDisks::PRIVATE => ['driver' => 'read-through', 'primary' => 'kp', 'fallback' => 'kf'],
                'filesystems.disks.public' => ['driver' => 'local', 'root' => $other.'/web', 'url' => 'https://web.example.test', 'links' => 'skip'],
            ]);
            Storage::forgetDisk([MediaDisks::PRIVATE, 'public']);
            $row = dirname($kept->path, 2).'/lnk/'.basename($kept->path);
            Storage::disk('public')->put($kept->path, pruneFixtureBytes());
            symlink($other.'/web/'.dirname($kept->path), $other.'/web/'.dirname($row));
            DB::table('media_files')->where('id', $kept->getKey())->update(['disk' => MediaDisks::PRIVATE, 'path' => $row]);

            Artisan::call('kitsune:media-prune');
            $output = Artisan::output();

            expect($output)->toContain('entry '.$kept->entry_id.'  [public]  '.$kept->path.' — its row names ['.MediaDisks::PRIVATE.'] '.$row.': on the web, and its file belongs off it — kitsune:media-reconcile --force takes it off')
                ->and($output)->not->toContain('a read-through disk, which custody neither reads nor removes a copy through');
        } finally {
            exec('rm -rf '.escapeshellarg($other));
        }
    });

    /*
     * ...and where the disk that holds the copy is itself a read-through disk over a local primary, settle cannot take it
     * off: custody neither reads nor removes a copy through one, so every forced reconcile of the row fails on it, and the
     * line says what settles it, as an extra copy's does (review of slice 5c). Reached through a link the listing skips.
     */
    it('says a forced reconcile fails on a copy under another spelling on a read-through disk, and what settles it', function (string $case): void {
        $other = sys_get_temp_dir().'/kitsune-prune-other-'.bin2hex(random_bytes(4));

        try {
            foreach (['vault', 'kp', 'kf', 'legacy'] as $root) {
                mkdir($other.'/'.$root, 0777, true);
            }

            if ($case === 'exposed') {
                config([
                    'filesystems.disks.kp' => ['driver' => 'local', 'root' => $other.'/kp', 'links' => 'skip'],
                    'filesystems.disks.kf' => ['driver' => 'local', 'root' => $other.'/kf'],
                    'filesystems.disks.rtw' => ['driver' => 'read-through', 'primary' => 'kp', 'fallback' => 'kf', 'url' => 'https://rtw.example.test'],
                ]);
                [$copy, $named] = ['rtw', MediaDisks::PRIVATE];
                // A row of its own on the served disk, so prune lists it.
                $own = storedForPrune($this->imageType);
                mkdir($other.'/kp/'.dirname($own->path), 0777, true);
                file_put_contents($other.'/kp/'.$own->path, pruneFixtureBytes());
                Storage::disk($own->disk)->delete($own->path);
                DB::table('media_files')->where('id', $own->getKey())->update(['disk' => 'rtw']);
            } else {
                config([
                    'filesystems.disks.vault' => ['driver' => 'local', 'root' => $other.'/vault'],
                    'filesystems.disks.legacy' => ['driver' => 'local', 'root' => $other.'/legacy'],
                    'kitsune.media.disks.private' => 'vault',
                ]);
                [$copy, $named] = [MediaDisks::PRIVATE, $case === 'awaiting publication' ? 'legacy' : 'public'];
            }

            $kept = storedForPrune($this->imageType);
            $row = dirname($kept->path, 2).'/lnk/'.basename($kept->path);
            // The row's file where it names it; the read-through disk's primary lists its copy under the month, lnk/ a link
            // to it.
            if ($case !== 'absent') {
                Storage::disk($named)->put($row, pruneFixtureBytes());
            }

            @mkdir($other.'/kp/'.dirname($kept->path), 0777, true);
            file_put_contents($other.'/kp/'.$kept->path, pruneFixtureBytes());
            symlink($other.'/kp/'.dirname($kept->path), $other.'/kp/'.dirname($row));
            Storage::disk($kept->disk)->delete($kept->path);
            DB::table('media_files')->where('id', $kept->getKey())->update(['disk' => $named, 'path' => $row, 'visibility' => $case === 'exposed' ? 'private' : 'public']);

            if ($case !== 'exposed') {
                config([
                    'filesystems.disks.kp' => ['driver' => 'local', 'root' => $other.'/kp', 'links' => 'skip'],
                    'filesystems.disks.kf' => ['driver' => 'local', 'root' => $other.'/kf'],
                    'filesystems.disks.'.MediaDisks::PRIVATE => ['driver' => 'read-through', 'primary' => 'kp', 'fallback' => 'kf'],
                ]);
                Storage::forgetDisk(MediaDisks::PRIVATE);
            }

            $exit = Artisan::call('kitsune:media-prune');
            $output = Artisan::output();
            $target = $case === 'exposed' ? MediaDisks::PRIVATE : 'public';

            expect($output)->toContain('entry '.$kept->entry_id.'  ['.$copy.']  '.$kept->path.' — its row names ['.$named.'] '.$row.': kept: ['.$copy.'] is a read-through disk, which custody neither reads nor removes a copy through, and '.($case === 'exposed' ? 'the web serves it, and its file belongs off it' : 'its file belongs on the web, off this disk').' — every kitsune:media-reconcile --force of its row fails on this copy until it is gone, and the check lists the row '.$case.': copy the file to ['.$target.'] by hand if it is not there')
                ->and($output)->not->toContain('takes it off')
                ->and($output)->not->toContain('removes it')
                ->and($exit)->toBe(0);

            expect(Artisan::call('kitsune:media-reconcile', ['--force' => true]))->toBe(1)
                ->and(Artisan::output())->toContain('it is a read-through disk')
                ->and(is_file($other.'/kp/'.$kept->path))->toBeTrue();
        } finally {
            exec('rm -rf '.escapeshellarg($other));
        }
    })->with([
        'a private file\'s copy on a served one' => 'exposed',
        'a public file\'s copy on core\'s private disk' => 'private copy',
        '...its row naming another disk' => 'awaiting publication',
        '...the public disk not holding it' => 'absent',
    ]);

    /*
     * ...and where the row's own name on the copy's disk is a link to the copy — a link at the last component, which the
     * listing skips — settle removes the link, not the copy: the line says so, and that the next run lists the copy as an
     * orphan, which a forced prune then removes (review of slice 5c). Every volume makes it.
     */
    it('says a forced reconcile removes the link, not the copy, where the row\'s own name there is a link to it', function (string $case): void {
        $other = sys_get_temp_dir().'/kitsune-prune-other-'.bin2hex(random_bytes(4));
        mkdir($other, 0777, true);

        try {
            $kept = storedForPrune($this->imageType);
            $dir = dirname($kept->path);

            if ($case === 'exposed') {
                // A private file on core's disk; the web holds a copy, and the row's name there is a link to it.
                config(['filesystems.disks.public' => ['driver' => 'local', 'root' => $other, 'url' => 'https://web.example.test', 'links' => 'skip']]);
                Storage::forgetDisk('public');
                Storage::disk('public')->put($dir.'/real.png', pruneFixtureBytes());
                symlink($other.'/'.$dir.'/real.png', $other.'/'.$kept->path);
                [$copy, $root, $label] = ['public', $other, 'exposed'];
            } else {
                // A public file on public; core's disk holds a copy, and the row's name there is a link to it.
                config(['filesystems.disks.'.MediaDisks::PRIVATE => ['driver' => 'local', 'root' => $this->root, 'links' => 'skip']]);
                Storage::forgetDisk(MediaDisks::PRIVATE);
                rename($this->root.'/'.$kept->path, $this->root.'/'.$dir.'/real.png');
                symlink($this->root.'/'.$dir.'/real.png', $this->root.'/'.$kept->path);
                Storage::disk('public')->put($kept->path, pruneFixtureBytes());
                DB::table('media_files')->where('id', $kept->getKey())->update(['disk' => 'public', 'visibility' => 'public']);
                [$copy, $root, $label] = [MediaDisks::PRIVATE, $this->root, 'private copy'];
            }

            $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);

            expect(Artisan::output())->toContain('entry '.$kept->entry_id.'  ['.$copy.']  '.$dir.'/real.png — its row names ['.($case === 'exposed' ? MediaDisks::PRIVATE : 'public').'] '.$kept->path.': '.($case === 'exposed' ? 'on the web, and its file belongs off it — ' : '').'its row\'s path on this disk is a link to this copy, so kitsune:media-reconcile --force removes the link, not this copy, or refuses it as coinciding where the two are one file, left for a hand, and the check lists the row '.$label.'; the next run lists this copy as an orphan, which --force removes')
                ->and(is_file($root.'/'.$dir.'/real.png'))->toBeTrue()
                ->and($exit)->toBe(0);

            $exit = Artisan::call('kitsune:media-reconcile');

            expect(Artisan::output())->toContain(str_pad($label, 20).' entry '.$kept->entry_id)->and($exit)->toBe(1);

            Artisan::call('kitsune:media-reconcile', ['--force' => true]);

            // The link gone, the copy left: the next run lists it as an orphan, and a forced one removes it.
            expect(is_link($root.'/'.$kept->path))->toBeFalse()
                ->and(is_file($root.'/'.$dir.'/real.png'))->toBeTrue();

            Artisan::call('kitsune:media-prune');

            expect(Artisan::output())->toContain('  ['.$copy.']  '.$dir.'/real.png');

            Artisan::call('kitsune:media-prune', ['--force' => true]);

            expect(file_exists($root.'/'.$dir.'/real.png'))->toBeFalse();
        } finally {
            exec('rm -rf '.escapeshellarg($other));
        }
    })->with(['a private file\'s copy on the web' => 'exposed', 'a public file\'s copy on core\'s disk' => 'private copy']);

    /*
     * ...and one neither the web nor settle takes off — a private file's copy on core's disk while the configured private
     * disk is the host's, or a public file's copy on another served disk — says to make the spellings one, after which it
     * is an extra copy: never that reconcile removes it, or takes it off the web (review of slice 5c). So does one on the
     * disk its file belongs on — a trashed public file's on the private disk, a public file's on the public disk its row
     * does not name — where a forced reconcile would point the row at it under a name other than its path, which no run
     * then shows (review of slice 5c). Reached through a link the listing skips, so every volume makes it.
     */
    it('says to make the spellings one where neither the web nor settle takes a copy off', function (string $case): void {
        $other = sys_get_temp_dir().'/kitsune-prune-other-'.bin2hex(random_bytes(4));
        mkdir($other, 0777, true);

        try {
            $kept = storedForPrune($this->imageType);
            $lnk = dirname($kept->path, 2).'/lnk';
            $row = $lnk.'/'.basename($kept->path);

            if (in_array($case, ['private', 'trashed'], true)) {
                config(['filesystems.disks.'.MediaDisks::PRIVATE => ['driver' => 'local', 'root' => $this->root, 'links' => 'skip']]);
                Storage::forgetDisk(MediaDisks::PRIVATE);
                symlink($this->root.'/'.dirname($kept->path), $this->root.'/'.$lnk);
            }

            if ($case === 'private') {
                // Core's disk lists the file under the month; the host's, configured private, holds it at the row's path.
                config(['filesystems.disks.host-private' => ['driver' => 'local', 'root' => $other], 'kitsune.media.disks.private' => 'host-private']);
                Storage::disk('host-private')->put($row, pruneFixtureBytes());
                [$named, $copy, $listed] = ['host-private', MediaDisks::PRIVATE, $kept->path];
                DB::table('media_files')->where('id', $kept->getKey())->update(['disk' => $named, 'path' => $row]);
            } elseif ($case === 'trashed') {
                // A trashed public file belongs on core's disk, which lists it under the month; its row names public.
                [$named, $copy, $listed] = ['public', MediaDisks::PRIVATE, $kept->path];
                DB::table('media_files')->where('id', $kept->getKey())->update(['disk' => $named, 'visibility' => 'public', 'path' => $row]);
                DB::table('entries')->where('id', $kept->entry_id)->update(['deleted_at' => now()]);
            } elseif ($case === 'target') {
                // A public file belongs on public, which lists it under the month; its row names core's disk.
                config(['filesystems.disks.public' => ['driver' => 'local', 'root' => $other, 'url' => 'https://web.example.test', 'links' => 'skip']]);
                Storage::forgetDisk('public');
                Storage::disk('public')->put($kept->path, pruneFixtureBytes());
                symlink($other.'/'.dirname($kept->path), $other.'/'.$lnk);
                Storage::disk(MediaDisks::PRIVATE)->delete($kept->path);
                [$named, $copy, $listed] = [MediaDisks::PRIVATE, 'public', $kept->path];
                DB::table('media_files')->where('id', $kept->getKey())->update(['visibility' => 'public', 'path' => $row]);
            } else {
                // A served disk another row names lists the copy under the month; public holds the file at the row's path.
                config(['filesystems.disks.cdn' => ['driver' => 'local', 'root' => $other, 'url' => 'https://cdn.example.test', 'links' => 'skip']]);
                $second = storedForPrune($this->imageType);
                Storage::disk('cdn')->put($second->path, pruneFixtureBytes());
                DB::table('media_files')->where('id', $second->getKey())->update(['disk' => 'cdn', 'visibility' => 'public']);
                Storage::disk(MediaDisks::PRIVATE)->delete($second->path);
                Storage::disk('cdn')->put($kept->path, pruneFixtureBytes());
                symlink($other.'/'.dirname($kept->path), $other.'/'.$lnk);
                Storage::disk('public')->put($row, pruneFixtureBytes());
                Storage::disk(MediaDisks::PRIVATE)->delete($kept->path);
                [$named, $copy, $listed] = ['public', 'cdn', $kept->path];
                DB::table('media_files')->where('id', $kept->getKey())->update(['disk' => $named, 'visibility' => 'public', 'path' => $row]);
            }

            foreach ([[], ['--force' => true]] as $options) {
                $exit = Artisan::call('kitsune:media-prune', $options);
                $output = Artisan::output();

                expect($output)->toContain('entry '.$kept->entry_id.'  ['.$copy.']  '.$listed.' — its row names ['.$named.'] '.$row.': make the row\'s path and this name one spelling, with the name ['.$named.'] holds its file under where it holds one — rename the files, or correct media_files.path — and the next run lists this one as an extra copy')
                    ->and($output)->not->toContain('kitsune:media-reconcile --force removes it')
                    ->and($output)->not->toContain('takes it off')
                    ->and($exit)->toBe(0);
            }

            expect(Storage::disk($copy)->exists($listed))->toBeTrue();
        } finally {
            exec('rm -rf '.escapeshellarg($other));
        }
    })->with([
        'a private file\'s copy on core\'s disk' => 'private',
        'a public file\'s copy on another served disk' => 'served',
        'a trashed public file\'s copy on the private disk it belongs on' => 'trashed',
        'a public file\'s copy on the public disk it belongs on, its row naming core\'s' => 'target',
    ]);

    // ...and one on the web, of a file kept off it, says so: a forced reconcile takes it off through the fold, which prune
    // does not — where before the pass, on SQLite and PostgreSQL, --force removed it as an orphan (review of #155's fix) —
    // and through a link the public disk's listing skips, on any volume (review of slice 5c).
    it('says a copy on the web a private row reaches under another spelling is taken off by reconcile', function (string $spelt): void {
        $kept = storedApartForPrune($this->imageType);
        $dir = dirname($kept->path);

        if ($spelt === 'link') {
            $publicRoot = sys_get_temp_dir().'/kitsune-prune-web-'.bin2hex(random_bytes(4));
            mkdir($publicRoot.'/'.$dir, 0777, true);
            config(['filesystems.disks.public' => ['driver' => 'local', 'root' => $publicRoot, 'url' => 'https://web.example.test', 'links' => 'skip']]);
            Storage::forgetDisk('public');
            // The row reads the file under lnk/ on core's disk; the web lists its copy under the month, lnk/ a link to it.
            $row = dirname($dir).'/lnk/'.basename($kept->path);
            mkdir($this->root.'/'.dirname($row));
            rename($this->root.'/'.$kept->path, $this->root.'/'.$row);
            DB::table('media_files')->where('id', $kept->getKey())->update(['path' => $row]);
            copy($this->root.'/'.$row, $publicRoot.'/'.$kept->path);
            symlink($publicRoot.'/'.$dir, $publicRoot.'/'.dirname($row));
            [$listed, $file] = [$kept->path, $this->root.'/'.$row];
        } else {
            $publicRoot = rtrim(Storage::disk('public')->path(''), '/');
            $row = $kept->path;
            $listed = $dir.'/'.strtoupper(basename($kept->path));
            @mkdir($publicRoot.'/'.$dir, 0777, true);
            copy($this->root.'/'.$kept->path, $publicRoot.'/'.$listed);
            $file = $this->root.'/'.$kept->path;

            if (@stat($publicRoot.'/'.$kept->path) === false) {
                test()->markTestSkipped('this volume does not fold case');
            }
        }

        try {
            $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);

            expect(Artisan::output())->toContain('entry '.$kept->entry_id.'  [public]  '.$listed.' — its row names ['.MediaDisks::PRIVATE.'] '.$row.': on the web, and its file belongs off it — kitsune:media-reconcile --force takes it off, or refuses it as coinciding where the two are one file, left for a hand, and the check lists the row exposed')
                ->and(is_file($publicRoot.'/'.$listed))->toBeTrue()
                ->and($exit)->toBe(0);

            $exit = Artisan::call('kitsune:media-reconcile');

            expect(Artisan::output())->toContain('exposed')->and($exit)->toBe(1);

            Artisan::call('kitsune:media-reconcile', ['--force' => true]);

            expect(file_exists($publicRoot.'/'.$listed))->toBeFalse()
                ->and(is_file($file))->toBeTrue();
        } finally {
            if ($spelt === 'link') {
                exec('rm -rf '.escapeshellarg($publicRoot));
            }
        }
    })->with(['in another case' => 'fold', 'through a link the listing skips' => 'link']);

    /*
     * ...and an extra copy whose row names the disk it belongs on, which reaches the row's path but lists its file under
     * another name, cannot be told from the row's own: said so, never offered for removal (review of #155's fix). Where
     * settle removes the row's copies there — a served disk's under a private target, a private disk's under a public one —
     * reconcile finds the row's own through the fold or the link, and the line says its --force takes this one off, and
     * that a file kept off the web is on it; elsewhere, to check the two by hand (review of slice 5c).
     */
    it('keeps an extra copy the disk its row names reaches under another name, saying what settles it', function (string $spelt, string $case): void {
        $other = sys_get_temp_dir().'/kitsune-prune-other-'.bin2hex(random_bytes(4));
        mkdir($other, 0777, true);

        $served = sys_get_temp_dir().'/kitsune-prune-served-'.bin2hex(random_bytes(4));
        mkdir($served, 0777, true);

        try {
            if ($case === 'by hand') {
                config(['filesystems.disks.host-private' => ['driver' => 'local', 'root' => $other, 'links' => 'skip'], 'kitsune.media.disks.private' => 'host-private']);
            }

            $kept = storedApartForPrune($this->imageType);
            $dir = dirname($kept->path);

            if ($spelt === 'fold') {
                // Core's disk holds the file under the upper-case name; public, the row's exact spelling.
                $public = rtrim(Storage::disk('public')->path(''), '/');
                $upper = $dir.'/'.strtoupper(basename($kept->path));
                @mkdir($public.'/'.$dir, 0777, true);
                copy($this->root.'/'.$kept->path, $public.'/'.$kept->path);
                rename($this->root.'/'.$kept->path, $this->root.'/'.$upper);

                if (@stat($this->root.'/'.$kept->path) === false) {
                    test()->markTestSkipped('this volume does not fold case');
                }

                [$target, $copy, $row] = [MediaDisks::PRIVATE, 'public', $kept->path];
            } else {
                // The disk the file belongs on lists it under the month, lnk/ a link to it; the copy is at lnk/ elsewhere.
                $row = dirname($dir).'/lnk/'.basename($kept->path);
                [$target, $copy, $holder] = match ($case) {
                    'exposed' => [MediaDisks::PRIVATE, 'public', $this->root],
                    'private copy' => ['public', MediaDisks::PRIVATE, $other],
                    'on the web by hand' => ['public', 'cdn', $other],
                    default => ['host-private', MediaDisks::PRIVATE, $other],
                };

                if ($case === 'on the web by hand') {
                    // Another served disk, which prune scans only for extra copies, holds the public file's copy.
                    config(['filesystems.disks.cdn' => ['driver' => 'local', 'root' => $served, 'url' => 'https://cdn.example.test']]);
                }

                if ($case === 'exposed') {
                    config(['filesystems.disks.'.MediaDisks::PRIVATE => ['driver' => 'local', 'root' => $this->root, 'links' => 'skip']]);
                    Storage::forgetDisk(MediaDisks::PRIVATE);
                } elseif (in_array($case, ['private copy', 'on the web by hand'], true)) {
                    config(['filesystems.disks.public' => ['driver' => 'local', 'root' => $other, 'url' => 'https://web.example.test', 'links' => 'skip']]);
                    Storage::forgetDisk('public');
                    Storage::disk('public')->put($kept->path, pruneFixtureBytes());
                    Storage::disk(MediaDisks::PRIVATE)->delete($kept->path);
                }

                symlink($holder.'/'.$dir, $holder.'/'.dirname($row));
                Storage::disk($copy)->put($row, pruneFixtureBytes());
                DB::table('media_files')->where('id', $kept->getKey())->update(['disk' => $target, 'path' => $row, 'visibility' => $target === 'public' ? 'public' : 'private']);
            }

            $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);

            expect(Artisan::output())->toContain('entry '.$kept->entry_id.'  ['.$copy.']  '.$row.' — live, its row names ['.$target.'], it belongs on ['.$target.']: kept: ['.$target.'] reaches the file at its row\'s path but does not list it under that name — a spelling the volume folds, a link its listing skips, or a read-through disk\'s fallback — so this copy cannot be told from the row\'s own: '.match ($case) {
                'exposed' => 'it is on the web, and its file belongs off it — the check lists the row exposed, and kitsune:media-reconcile --force takes it off, or refuses it as coinciding where the two are one file, left for a hand',
                'private copy' => 'the check lists the row private copy, and kitsune:media-reconcile --force removes it, or refuses it as coinciding where the two are one file, left for a hand',
                default => 'check by hand whether the two are one file before taking this one off',
            })
                ->and(Storage::disk($copy)->exists($row))->toBeTrue()
                ->and($exit)->toBe(0);

            if (in_array($case, ['by hand', 'on the web by hand'], true)) {
                return;
            }

            $exit = Artisan::call('kitsune:media-reconcile');

            expect(Artisan::output())->toContain(str_pad($case, 20).' entry '.$kept->entry_id)->and($exit)->toBe(1);

            Artisan::call('kitsune:media-reconcile', ['--force' => true]);

            expect(Storage::disk($copy)->exists($row))->toBeFalse();
        } finally {
            exec('rm -rf '.escapeshellarg($other).' '.escapeshellarg($served));
        }
    })->with([
        'on the web, in another case' => ['fold', 'exposed'],
        'on the web, through a link the listing skips' => ['link', 'exposed'],
        'a private copy, through a link the listing skips' => ['link', 'private copy'],
        'on core\'s disk beside a host\'s private disk, through a link the listing skips' => ['link', 'by hand'],
        'a public file\'s, on another served disk, through a link the listing skips' => ['link', 'on the web by hand'],
    ]);

    // ...and where the disk its row names cannot say whether it reaches the path, the copy keeps the line the listing gives
    // it, and the report goes on: asked only to choose the line (review of slice 5c).
    it('keeps an extra copy with its line, and goes on, when the disk its row names cannot say whether it reaches the path', function (bool $force): void {
        $kept = storedForPrune($this->imageType);
        Storage::disk('public')->put($kept->path, (string) file_get_contents($this->root.'/'.$kept->path));
        unlink($this->root.'/'.$kept->path);
        $this->private->unknown = [$kept->path];
        RefusingDisk::forgetLog();

        $exit = Artisan::call('kitsune:media-prune', $force ? ['--force' => true] : []);

        expect(Artisan::output())->toContain('entry '.$kept->entry_id.'  [public]  '.$kept->path.' — live, its row names ['.MediaDisks::PRIVATE.'], it belongs on ['.MediaDisks::PRIVATE.']: kept: the disk its row names does not hold the file')
            // Asked, so the test does not pass because the arm was never reached.
            ->and(array_filter(RefusingDisk::$log, static fn (array $event): bool => $event['event'] === 'fileExists' && $event['path'] === $kept->path))->not->toBe([])
            ->and(Storage::disk('public')->exists($kept->path))->toBeTrue()
            ->and($exit)->toBe(0);
    })->with(['read-only' => false, 'forced' => true]);

    // ...and what the pass adds on a disk the listing found none on keeps the order the disks are scanned in: a partial
    // copy listed, and removed by --force, before a disk scanned after it, and a copy under another spelling listed so,
    // though the row that reaches the later disk's was read first (review of slice 5c).
    it('lists and removes what the pass adds in the order the disks are scanned', function (): void {
        $publicRoot = sys_get_temp_dir().'/kitsune-prune-first-'.bin2hex(random_bytes(4));
        mkdir($publicRoot, 0777, true);

        try {
            RefusingDisk::install('public', $publicRoot);
            // x first: the pass's first claim is then on core's disk, which is scanned after public.
            $x = storedForPrune($this->imageType);
            $a = storedForPrune($this->imageType);
            $b = storedForPrune($this->imageType);
            $y = storedForPrune($this->imageType);
            $dir = dirname($a->path);
            rename($this->root.'/'.$a->path, $this->root.'/'.$dir.'/a.png');
            rename($this->root.'/'.$b->path, $this->root.'/'.$dir.'/b.png');
            // a's partial copy on public, scanned first, reached only by the pass; b's on core's disk, found by the listing.
            mkdir($publicRoot.'/'.$dir, 0777, true);
            file_put_contents($publicRoot.'/'.$dir.'/a.png'.MediaBytes::PARTIAL, 'part');
            file_put_contents($this->root.'/'.$dir.'/b.png'.MediaBytes::PARTIAL, 'part');
            DB::table('media_files')->where('id', $a->getKey())->update(['path' => $dir.'//a.png']);
            DB::table('media_files')->where('id', $b->getKey())->update(['path' => $dir.'/b.png']);
            // x, read before every other row, names public and reaches core's disk's x.png; y names core's and reaches public's.
            rename($this->root.'/'.$x->path, $this->root.'/'.$dir.'/x.png');
            rename($this->root.'/'.$y->path, $publicRoot.'/'.$dir.'/y.png');
            DB::table('media_files')->where('id', $x->getKey())->update(['disk' => 'public', 'visibility' => 'public', 'path' => $dir.'//x.png']);
            DB::table('media_files')->where('id', $y->getKey())->update(['path' => $dir.'//y.png']);

            Artisan::call('kitsune:media-prune');
            $output = Artisan::output();
            $first = strpos($output, '[public]  '.$dir.'/a.png'.MediaBytes::PARTIAL);
            $spelt = strpos($output, '[public]  '.$dir.'/y.png —');

            expect($output)->toContain('No orphaned media files.')
                ->and($first)->not->toBeFalse()
                ->and($first)->toBeLessThan((int) strpos($output, '['.MediaDisks::PRIVATE.']  '.$dir.'/b.png'.MediaBytes::PARTIAL))
                ->and($spelt)->not->toBeFalse()
                ->and($spelt)->toBeLessThan((int) strpos($output, '['.MediaDisks::PRIVATE.']  '.$dir.'/x.png —'));

            RefusingDisk::forgetLog();
            $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);
            $deletes = array_values(array_map(
                static fn (array $event): string => $event['disk'].':'.$event['path'],
                array_filter(RefusingDisk::$log, static fn (array $event): bool => $event['event'] === 'delete'),
            ));

            expect($deletes)->toBe(['public:'.$dir.'/a.png'.MediaBytes::PARTIAL, MediaDisks::PRIVATE.':'.$dir.'/b.png'.MediaBytes::PARTIAL])
                ->and($exit)->toBe(0);
        } finally {
            exec('rm -rf '.escapeshellarg($publicRoot));
        }
    });

    // ...and one a row's partial path reaches is that row's leftover partial copy, removed under its lock (Codex, #155).
    it('lists a partial copy a row\'s partial path reaches under another spelling as that row\'s, and removes it', function (string $spelt): void {
        $kept = storedApartForPrune($this->imageType);
        $dir = dirname($kept->path);
        rename($this->root.'/'.$kept->path, $this->root.'/'.$dir.'/keptphoto.png');
        file_put_contents($this->root.'/'.$dir.'/keptphoto.png'.MediaBytes::PARTIAL, 'part');
        $row = $spelt === 'fold' ? $dir.'/KeptPhoto.PNG' : $dir.'//keptphoto.png';

        if ($spelt === 'fold' && @stat($this->root.'/'.$row.MediaBytes::PARTIAL) === false) {
            test()->markTestSkipped('this volume does not fold case');
        }

        DB::table('media_files')->where('id', $kept->getKey())->update(['path' => $row]);

        $exit = Artisan::call('kitsune:media-prune');
        $output = Artisan::output();

        expect($output)->toContain('Leftover partial copies')
            ->and($output)->toContain('entry '.$kept->entry_id.'  ['.MediaDisks::PRIVATE.']  '.$dir.'/keptphoto.png'.MediaBytes::PARTIAL)
            ->and($output)->toContain('No orphaned media files.')
            ->and($exit)->toBe(0);

        $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);

        expect(Artisan::output())->toContain('Removed 1 of 1 orphaned or leftover file')
            ->and(file_exists($this->root.'/'.$dir.'/keptphoto.png'.MediaBytes::PARTIAL))->toBeFalse()
            ->and(is_file($this->root.'/'.$dir.'/keptphoto.png'))->toBeTrue()
            ->and($exit)->toBe(0);
    })->with(['in another case' => 'fold', 'with a doubled slash' => 'slash']);

    // ...and a row's own path wins over another disk's, and over a partial copy's, whichever row is read first — on a
    // volume that folds, and, spelt with a doubled slash and a dot segment, on one that folds nothing (review of 5c).
    it('takes a row\'s own path over another disk\'s row and over a partial copy\'s', function (string $case, bool $ownFirst, string $spelt): void {
        // MySQL's and MariaDB's collations refuse two paths that differ only in case at the index.
        if ($case === 'disk' && $spelt === 'fold' && in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            test()->markTestSkipped('the index refuses two paths the collation compares equal');
        }

        $first = storedForPrune($this->imageType);
        $second = storedForPrune($this->imageType);
        [$own, $weak] = $ownFirst ? [$first, $second] : [$second, $first];
        $dir = dirname($first->path);
        Storage::disk(MediaDisks::PRIVATE)->delete($weak->path);
        $listed = $case === 'disk' ? $dir.'/keptphoto.png' : $dir.'/keep.png'.MediaBytes::PARTIAL;
        rename($this->root.'/'.$own->path, $this->root.'/'.$listed);

        if ($spelt === 'fold' && @stat($this->root.'/'.strtoupper($listed)) === false && @stat($this->root.'/'.$dir.'/KeptPhoto.PNG') === false) {
            test()->markTestSkipped('this volume does not fold case');
        }

        // One row claims it less — another disk's spelling, or a partial path — and the other, its own, read first or last.
        DB::table('media_files')->where('id', $weak->getKey())->update(match (true) {
            $case === 'disk' => ['disk' => 'public', 'visibility' => 'public', 'path' => $spelt === 'fold' ? $dir.'/KEPTPHOTO.PNG' : $dir.'/./keptphoto.png'],
            default => ['path' => $spelt === 'fold' ? $dir.'/KEEP.png' : $dir.'/./keep.png'],
        });
        DB::table('media_files')->where('id', $own->getKey())->update(['path' => match (true) {
            $spelt === 'slash' => $dir.'//'.basename($listed),
            $case === 'disk' => $dir.'/KeptPhoto.PNG',
            default => $dir.'/KEEP.PNG'.strtoupper(MediaBytes::PARTIAL),
        }]);

        $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);
        $output = Artisan::output();

        expect($output)->toContain('No orphaned media files.')
            ->and($output)->not->toContain('Copies a row reaches')
            ->and($output)->not->toContain('Leftover partial copies')
            ->and(is_file($this->root.'/'.$listed))->toBeTrue()
            ->and($exit)->toBe(0);
    })->with([
        'over another disk\'s row, read last' => ['disk', false, 'fold'],
        'over another disk\'s row, read first' => ['disk', true, 'fold'],
        'over a partial copy\'s, read last' => ['partial', false, 'fold'],
        'over a partial copy\'s, read first' => ['partial', true, 'fold'],
        'over another disk\'s row, read last, spelt with slashes' => ['disk', false, 'slash'],
        'over another disk\'s row, read first, spelt with slashes' => ['disk', true, 'slash'],
        'over a partial copy\'s, read last, spelt with slashes' => ['partial', false, 'slash'],
        'over a partial copy\'s, read first, spelt with slashes' => ['partial', true, 'slash'],
    ]);

    // ...and a row naming another disk wins over a partial copy's, whichever is read first: kept, never removed as a partial
    // — on a volume that folds, and on one that folds nothing (review of slice 5c).
    it('takes another disk\'s row over a partial copy\'s', function (bool $otherFirst, string $spelt): void {
        $first = storedForPrune($this->imageType);
        $second = storedForPrune($this->imageType);
        [$other, $partial] = $otherFirst ? [$first, $second] : [$second, $first];
        $dir = dirname($first->path);
        $listed = $dir.'/keepd.png'.MediaBytes::PARTIAL;
        rename($this->root.'/'.$first->path, $this->root.'/'.$listed);
        Storage::disk(MediaDisks::PRIVATE)->delete($second->path);

        if ($spelt === 'fold' && @stat($this->root.'/'.$dir.'/KEEPD.PNG'.strtoupper(MediaBytes::PARTIAL)) === false) {
            test()->markTestSkipped('this volume does not fold case');
        }

        DB::table('media_files')->where('id', $other->getKey())->update(['disk' => 'public', 'visibility' => 'public', 'path' => $spelt === 'fold'
            ? $dir.'/KEEPD.PNG'.strtoupper(MediaBytes::PARTIAL)
            : $dir.'//keepd.png'.MediaBytes::PARTIAL]);
        DB::table('media_files')->where('id', $partial->getKey())->update(['path' => $spelt === 'fold' ? $dir.'/KEEPD.png' : $dir.'/./keepd.png']);

        $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Copies a row reaches under another spelling')
            ->and($output)->not->toContain('Leftover partial copies')
            ->and(is_file($this->root.'/'.$listed))->toBeTrue()
            ->and($exit)->toBe(0);
    })->with([
        'the other disk\'s row read first' => [true, 'fold'],
        'the partial copy\'s row read first' => [false, 'fold'],
        'the other disk\'s row read first, spelt with slashes' => [true, 'slash'],
        'the partial copy\'s row read first, spelt with slashes' => [false, 'slash'],
    ]);

    // ...and a directory is the same directory by what the volume says, not by its spelling: a row naming the month's
    // directory in another case reaches its file there, as does one spelt with a doubled slash under a disk root reached
    // through a link — a release's `current`, which resolving the row's name, and not the listed one's, spells otherwise —
    // while a hard link elsewhere is still another entry (review of slice 5c).
    it('reaches a file through a directory spelt otherwise, and removes a hard link elsewhere', function (string $spelt): void {
        $link = sys_get_temp_dir().'/kitsune-prune-current-'.bin2hex(random_bytes(4));

        try {
            if ($spelt === 'root') {
                symlink($this->root, $link);
                $this->private = RefusingDisk::install(MediaDisks::PRIVATE, $link);
            }

            $kept = storedForPrune($this->imageType);
            $dir = dirname($kept->path);
            rename($this->root.'/'.$kept->path, $this->root.'/'.$dir.'/sole.png');
            $row = $spelt === 'fold' ? strtoupper($dir).'/sole.png' : $dir.'//sole.png';
            $file = @stat($this->root.'/'.$dir.'/sole.png');
            $reached = @stat($this->root.'/'.$row);

            if (! is_array($file) || ! is_array($reached) || $file['ino'] !== $reached['ino']) {
                test()->markTestSkipped('this volume does not fold case');
            }

            // Two hard links, listed before the file itself: its own name is the third of its inode's (review of 5c).
            $hardLinks = ['media/'.$this->org->getKey().'/2026/01/sole-link.png', 'media/'.$this->org->getKey().'/2026/02/sole-link.png'];

            foreach ($hardLinks as $hardLink) {
                @mkdir(dirname($this->root.'/'.$hardLink), 0777, true);
                link($this->root.'/'.$dir.'/sole.png', $this->root.'/'.$hardLink);
            }

            DB::table('media_files')->where('id', $kept->getKey())->update(['path' => $row]);

            $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);

            expect(Artisan::output())->toContain('Removed 2 of 2 orphaned or leftover files')
                ->and(hash_file('sha256', $this->root.'/'.$dir.'/sole.png'))->toBe(hash('sha256', pruneFixtureBytes()))
                ->and(file_exists($this->root.'/'.$hardLinks[0]))->toBeFalse()
                ->and(file_exists($this->root.'/'.$hardLinks[1]))->toBeFalse()
                ->and($exit)->toBe(0);
        } finally {
            @unlink($link);
        }
    })->with(['in another case' => 'fold', 'with a doubled slash, under a linked root' => 'root']);

    // ...and on a disk named with digits alone, whose name PHP would make an integer key (review of #155's fix).
    it('asks a disk named with digits alone whether a row reaches a listed name', function (): void {
        $root = sys_get_temp_dir().'/kitsune-prune-seven-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);

        try {
            config(['filesystems.disks.7' => ['driver' => 'local', 'root' => $root]]);
            $kept = storedForPrune($this->imageType);
            $dir = dirname($kept->path);
            @mkdir($root.'/'.$dir, 0777, true);
            rename($this->root.'/'.$kept->path, $root.'/'.$dir.'/keptphoto.png');
            DB::table('media_files')->where('id', $kept->getKey())->update(['disk' => '7', 'path' => $dir.'//keptphoto.png']);

            foreach ([[], ['--force' => true]] as $options) {
                $exit = Artisan::call('kitsune:media-prune', $options);

                expect(Artisan::output())->toContain('No orphaned media files.')->and($exit)->toBe(0);
            }

            expect(is_file($root.'/'.$dir.'/keptphoto.png'))->toBeTrue();
        } finally {
            exec('rm -rf '.escapeshellarg($root));
        }
    });

    // ...and past the pass's first batch: a row read in its second batch still reaches its file (review of #155's fix).
    it('lists nowhere a name only a row past the pass\'s first batch reaches', function (): void {
        $template = storedForPrune($this->imageType);
        pruneRows($template, MediaPruneCommand::BATCH, MediaDisks::PRIVATE, 'early');
        [$late] = pruneRows($template, 1, MediaDisks::PRIVATE, 'late');
        DB::table('media_files')->where('path', $late)->update(['path' => dirname($late).'//'.basename($late)]);
        Storage::disk(MediaDisks::PRIVATE)->put($late, 'the late row\'s only file');

        foreach ([[], ['--force' => true]] as $options) {
            $exit = Artisan::call('kitsune:media-prune', $options);
            $output = Artisan::output();

            // Listed nowhere, not as another disk's row's copy (review of slice 5c).
            expect($output)->toContain('No orphaned media files.')
                ->and($output)->not->toContain('Copies a row reaches')
                ->and($exit)->toBe(0);
        }

        expect(is_file($this->root.'/'.$late))->toBeTrue();
    });

    // ...and what the pass adds is listed in path order with what the listing found: partial copies, and copies under
    // another spelling, whichever row the pass read first (review of #155's fix).
    it('lists what the pass adds in path order', function (): void {
        $b = storedForPrune($this->imageType);
        $a = storedForPrune($this->imageType);
        $z = storedForPrune($this->imageType);
        $y = storedForPrune($this->imageType);
        $dir = dirname($a->path);

        foreach (['a' => $a, 'b' => $b, 'z' => $z, 'y' => $y] as $name => $file) {
            rename($this->root.'/'.$file->path, $this->root.'/'.$dir.'/'.$name.'.png');
        }

        file_put_contents($this->root.'/'.$dir.'/a.png'.MediaBytes::PARTIAL, 'part');
        file_put_contents($this->root.'/'.$dir.'/b.png'.MediaBytes::PARTIAL, 'part');
        // a's partial reached only by the pass, b's by the listing; z and y copies under another spelling, z read first.
        DB::table('media_files')->where('id', $a->getKey())->update(['path' => $dir.'//a.png']);
        DB::table('media_files')->where('id', $b->getKey())->update(['path' => $dir.'/b.png']);
        DB::table('media_files')->where('id', $z->getKey())->update(['disk' => 'public', 'visibility' => 'public', 'path' => $dir.'//z.png']);
        DB::table('media_files')->where('id', $y->getKey())->update(['disk' => 'public', 'visibility' => 'public', 'path' => $dir.'//y.png']);

        Artisan::call('kitsune:media-prune');
        $output = Artisan::output();

        $heading = strpos($output, 'Leftover partial copies');
        $passAdded = strpos($output, $dir.'/a.png'.MediaBytes::PARTIAL);

        // a's partial copy printed, among the partial copies, before b's (review of slice 5c).
        expect($heading)->not->toBeFalse()
            ->and($passAdded)->not->toBeFalse()
            ->and($passAdded)->toBeGreaterThan((int) $heading)
            ->and($passAdded)->toBeLessThan((int) strpos($output, $dir.'/b.png'.MediaBytes::PARTIAL))
            ->and(strpos($output, $dir.'/y.png —'))->toBeLessThan((int) strpos($output, $dir.'/z.png —'))
            ->and(strpos($output, $dir.'/y.png —'))->not->toBeFalse();
    });

    // ...and the rows the new list prints are read 500 at a time, and one gone since the pass is said so (review of #155's
    // fix).
    it('reads the rows of copies under another spelling in batches, and says so of one gone since', function (bool $gone): void {
        $template = storedForPrune($this->imageType);
        $count = $gone ? 1 : MediaPruneCommand::BATCH + 1;
        $paths = pruneRows($template, $count, 'public', 'spelt', ['visibility' => 'public']);

        foreach ($paths as $path) {
            Storage::disk(MediaDisks::PRIVATE)->put($path, 'bytes');
            DB::table('media_files')->where('path', $path)->update(['path' => dirname($path).'//'.basename($path)]);
        }

        $reads = [];
        $deleted = false;
        DB::listen(static function (QueryExecuted $query) use (&$reads, &$deleted, $gone): void {
            if (preg_match('/where\W+media_files\W+\W*id\W+in\s*\(/i', $query->sql) === 1) {
                $reads[] = count($query->bindings);
            }

            // The row gone once the pass has read it, before the list is printed.
            if ($gone && ! $deleted && preg_match('/^select\W+id\W+,\W+entry_id\W+,\W+disk\W+,\W+path\W+from\W+media_files\W/i', $query->sql) === 1) {
                $deleted = true;
                DB::table('media_files')->where('path', 'like', '%//spelt%')->delete();
            }
        });

        $exit = Artisan::call('kitsune:media-prune');
        $output = Artisan::output();

        if ($gone) {
            expect($output)->toContain('  ['.MediaDisks::PRIVATE.']  '.$paths[0].' — its row is gone since the listing: the next run asks again');
        } else {
            expect(substr_count($output, ' — its row names [public] '))->toBe($count)
                ->and($reads)->toBe([MediaPruneCommand::BATCH, 1]);
        }

        expect($exit)->toBe(0);
    })->with(['more than a batch' => false, 'a row gone since the pass' => true]);

    // ...and a failure of the pass's read fails the run, never read as "no row" — nor said to be a disk not listed.
    it('fails the run, removing nothing, when the pass over the table fails', function (): void {
        $kept = storedForPrune($this->imageType);
        $orphan = dirname($kept->path).'/left-behind.png';
        file_put_contents($this->root.'/'.$orphan, 'bytes');
        DB::listen(static function (QueryExecuted $query): void {
            if (preg_match('/^select\W+id\W+,\W+entry_id\W+,\W+disk\W+,\W+path\W+from\W+media_files\W/i', $query->sql) === 1) {
                throw new RuntimeException('the pass could not read the table');
            }
        });

        expect(fn () => Artisan::call('kitsune:media-prune', ['--force' => true]))->toThrow(RuntimeException::class, 'the pass could not read the table')
            ->and(is_file($this->root.'/'.$orphan))->toBeTrue()
            ->and(is_file($this->root.'/'.$kept->path))->toBeTrue();
    });

    // ...and a row's path no stat can take — one the disks refuse, or one that leaves the disk — reaches nothing, and stops
    // nothing: the orphan beside it is removed.
    it('asks nothing of a row path the disks refuse, and removes the orphan beside it', function (): void {
        $kept = storedForPrune($this->imageType);
        $other = storedForPrune($this->imageType);
        $dir = dirname($kept->path);
        DB::table('media_files')->where('id', $other->getKey())->update(['path' => $dir.'/zw'."\u{200b}".'.png']);
        DB::table('media_files')->where('id', $kept->getKey())->update(['path' => 'media/../../outside.png']);
        Storage::disk(MediaDisks::PRIVATE)->delete($other->path);
        rename($this->root.'/'.$kept->path, $this->root.'/'.$dir.'/left-behind.png');

        $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);

        expect(Artisan::output())->toContain('Removed 1 of 1 orphaned or leftover file')
            ->and(file_exists($this->root.'/'.$dir.'/left-behind.png'))->toBeFalse()
            ->and($exit)->toBe(0);
    });

    // ...and only a local disk is asked by stat: an object store compares keys byte for byte, and another spelling is another
    // key — listed on every engine, and removed but where MySQL's and MariaDB's collation claims it under the lock; a row's
    // path written in a form the disks read as the key itself still reaches it (review of #155's fix).
    it('asks an object store by the key a row\'s path is read as, never by stat', function (string $spelt): void {
        $kept = storedForPrune($this->imageType);
        $dir = dirname($kept->path);
        $store = sys_get_temp_dir().'/kitsune-prune-store-'.bin2hex(random_bytes(4));
        mkdir($store.'/'.$dir, 0777, true);
        // A store named with digits alone, whose name PHP makes an integer key (review of slice 5c).
        $name = $spelt === 'digits' ? '7' : 'store';

        try {
            // The row's file on the store alone.
            Storage::disk(MediaDisks::PRIVATE)->delete($kept->path);
            file_put_contents($store.'/'.$dir.'/keptphoto.png', 'bytes');
            // No local root, as a real object store's configuration names none: stat of a key it reads reaches nothing (5c).
            $config = ['driver' => 's3', 'bucket' => 'b', 'endpoint' => 'e', 'url' => 'https://store.example.test'];
            $adapter = new RefusingDisk($store, $name);
            config(['filesystems.disks.'.$name => $config, 'kitsune.media.disks.public' => $name]);
            Storage::set($name, new FilesystemAdapter(new Filesystem($adapter), $adapter, $config));
            DB::table('media_files')->where('id', $kept->getKey())->update(['disk' => $name, 'visibility' => 'public', 'path' => $spelt === 'case' ? $dir.'/KeptPhoto.PNG' : $dir.'//keptphoto.png']);
            $collates = $spelt === 'case' && in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);

            $exit = Artisan::call('kitsune:media-prune');
            $output = Artisan::output();

            // The store's own row's file, never another's copy under another spelling (review of slice 5c).
            expect($output)->toContain($spelt === 'case' ? '1 orphaned file' : 'No orphaned media files.')
                ->and($output)->not->toContain('Copies a row reaches')
                ->and($exit)->toBe(0);

            $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);

            expect(file_exists($store.'/'.$dir.'/keptphoto.png'))->toBe($spelt !== 'case' || $collates)->and($exit)->toBe(0);
        } finally {
            exec('rm -rf '.escapeshellarg($store));
        }
    })->with(['another case, another key' => 'case', 'a doubled slash, read as the key' => 'slash', 'a doubled slash, on a store named with digits' => 'digits']);

    /*
     * ...and by that key an object store's name is claimed as a row's own, as another disk's row's copy under another
     * spelling, or as a row's partial copy, the lower kind winning whichever row is read first — and a row's path the
     * disks refuse, elsewhere in the table, reaches nothing and stops nothing (review of slice 5c).
     */
    it('claims an object store\'s key as each kind of row reaches it', function (string $case): void {
        $kept = storedForPrune($this->imageType);
        $dir = dirname($kept->path);
        $store = sys_get_temp_dir().'/kitsune-prune-store-'.bin2hex(random_bytes(4));
        mkdir($store.'/'.$dir, 0777, true);

        try {
            // No local root, as a real object store's configuration names none: stat of a key it reads reaches nothing (5c).
            $config = ['driver' => 's3', 'bucket' => 'b', 'endpoint' => 'e', 'url' => 'https://store.example.test'];
            $adapter = new RefusingDisk($store, 'store');
            config(['filesystems.disks.store' => $config, 'kitsune.media.disks.public' => 'store']);
            Storage::set('store', new FilesystemAdapter(new Filesystem($adapter), $adapter, $config));
            Storage::disk(MediaDisks::PRIVATE)->delete($kept->path);
            file_put_contents($store.'/'.$dir.'/keptphoto.png', 'bytes');

            if (in_array($case, ['store first', 'store last'], true)) {
                // One row names the store, the other core's disk; both read as the store's key.
                $other = storedForPrune($this->imageType);
                Storage::disk(MediaDisks::PRIVATE)->delete($other->path);
                [$storeRow, $privateRow] = $case === 'store first' ? [$kept, $other] : [$other, $kept];
                DB::table('media_files')->where('id', $storeRow->getKey())->update(['disk' => 'store', 'visibility' => 'public', 'path' => $dir.'//keptphoto.png']);
                DB::table('media_files')->where('id', $privateRow->getKey())->update(['path' => $dir.'/./keptphoto.png']);
            } elseif ($case === 'partial') {
                file_put_contents($store.'/'.$dir.'/keptphoto.png'.MediaBytes::PARTIAL, 'part');
                DB::table('media_files')->where('id', $kept->getKey())->update(['disk' => 'store', 'visibility' => 'public', 'path' => $dir.'//keptphoto.png']);
            } elseif ($case === 'exact') {
                // A row's own partial copy, its path written as the disks read it: the listing's, which the pass never asks
                // of a store — asked by key, a partial path took it from its row, and it was listed nowhere (review of 5c).
                file_put_contents($store.'/'.$dir.'/keptphoto.png'.MediaBytes::PARTIAL, 'part');
                DB::table('media_files')->where('id', $kept->getKey())->update(['disk' => 'store', 'visibility' => 'public', 'path' => $dir.'/keptphoto.png']);
            } elseif ($case === 'refused') {
                // An orphan on the store, and a row elsewhere whose path the disks refuse.
                file_put_contents($store.'/'.$dir.'/orphan.png', 'bytes');
                DB::table('media_files')->where('id', $kept->getKey())->update(['disk' => 'store', 'visibility' => 'public']);
                rename($store.'/'.$dir.'/keptphoto.png', $store.'/'.$kept->path);
                $refused = storedForPrune($this->imageType);
                Storage::disk(MediaDisks::PRIVATE)->delete($refused->path);
                DB::table('media_files')->where('id', $refused->getKey())->update(['path' => 'media/../../outside.png']);
            } else {
                // The row names core's disk; its path, read as the disks read it, is the store's key.
                DB::table('media_files')->where('id', $kept->getKey())->update(['path' => $dir.'//keptphoto.png']);
            }

            $exit = Artisan::call('kitsune:media-prune');
            $output = Artisan::output();

            expect($exit)->toBe(0);

            match ($case) {
                'partial', 'exact' => expect($output)->toContain('No orphaned media files.')
                    ->toContain('Leftover partial copies')
                    ->toContain(sprintf('entry %d  [store]  %s/keptphoto.png%s', $kept->entry_id, $dir, MediaBytes::PARTIAL)),
                'refused' => expect($output)->toContain('  [store]  '.$dir.'/orphan.png')->not->toContain('Copies a row reaches'),
                'other disk' => expect($output)->toContain('No orphaned media files.')
                    ->toContain(sprintf('entry %d  [store]  %s/keptphoto.png — its row names [%s] %s//keptphoto.png: its row\'s path is not written as the disks read it', $kept->entry_id, $dir, MediaDisks::PRIVATE, $dir)),
                default => expect($output)->toContain('No orphaned media files.')->not->toContain('Copies a row reaches'),
            };

            $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);

            expect($exit)->toBe(0)
                ->and(file_exists($store.'/'.$dir.'/keptphoto.png'.MediaBytes::PARTIAL))->toBeFalse()
                ->and(file_exists($store.'/'.$dir.'/orphan.png'))->toBeFalse()
                ->and(is_file($store.'/'.($case === 'refused' ? $kept->path : $dir.'/keptphoto.png')))->toBeTrue();
        } finally {
            exec('rm -rf '.escapeshellarg($store));
        }
    })->with([
        'a row naming another disk' => 'other disk',
        'a row\'s partial copy' => 'partial',
        'a row\'s partial copy, its path as the disks read it' => 'exact',
        'its own row read first, another disk\'s last' => 'store first',
        'its own row read last, another disk\'s first' => 'store last',
        'beside a row path the disks refuse' => 'refused',
    ]);

    // ...and another file whose name only looks like a row's claims nothing: the row must reach this very entry.
    it('removes a file whose name only looks like another row\'s', function (): void {
        $other = storedForPrune($this->imageType);
        $dir = dirname($other->path);
        rename($this->root.'/'.$other->path, $this->root.'/'.$dir.'/cafx.png');
        DB::table('media_files')->where('id', $other->getKey())->update(['path' => $dir.'/cafx.png']);
        file_put_contents($this->root.'/'.$dir.'/caf'."\u{e9}".'.png', 'another file');

        $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);

        expect(Artisan::output())->toContain('Removed 1 of 1 orphaned or leftover file')
            ->and(file_exists($this->root.'/'.$dir.'/caf'."\u{e9}".'.png'))->toBeFalse()
            ->and(is_file($this->root.'/'.$dir.'/cafx.png'))->toBeTrue()
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

            expect($output)->toContain('kept: [private-rt] reaches the file at its row\'s path but does not list it under that name — a spelling the volume folds, a link its listing skips, or a read-through disk\'s fallback — so this copy cannot be told from the row\'s own: check by hand whether the two are one file before taking this one off')
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

    /*
     * ...and the pass holds each claim's row or entry whole, apart from its kind: packed beside it, an id past ±2^61 came
     * back as another — a partial copy listed under an entry that does not exist and removed under that entry's lock, not
     * its own, and a copy under another spelling said to have lost its row (review of slice 5c). Reached through a
     * doubled slash, so every volume makes it.
     */
    it('reads back a claim\'s row or entry past what one integer beside its kind would hold', function (string $kind, int $id): void {
        pruneWithoutKeptAutoIncrement();
        $kept = storedForPrune($this->imageType);
        $dir = dirname($kept->path);
        rename($this->root.'/'.$kept->path, $this->root.'/'.$dir.'/keptphoto.png');

        if ($kind === 'partial') {
            $entry = (array) DB::table('entries')->where('id', $kept->entry_id)->first();
            DB::table('entries')->insert([...$entry, 'id' => $id]);
            DB::table('media_files')->where('id', $kept->getKey())->update(['entry_id' => $id, 'path' => $dir.'//keptphoto.png']);
            file_put_contents($this->root.'/'.$dir.'/keptphoto.png'.MediaBytes::PARTIAL, 'part');
        } else {
            DB::table('media_files')->where('id', $kept->getKey())->update(['id' => $id, 'disk' => 'public', 'visibility' => 'public', 'path' => $dir.'//keptphoto.png']);
        }

        $exit = Artisan::call('kitsune:media-prune');
        $output = Artisan::output();

        if ($kind === 'spelt') {
            expect($output)->toContain('entry '.$kept->entry_id.'  ['.MediaDisks::PRIVATE.']  '.$dir.'/keptphoto.png — its row names [public] '.$dir.'//keptphoto.png: ')
                ->and($output)->not->toContain('gone since')
                ->and($exit)->toBe(0);

            return;
        }

        expect($output)->toContain('entry '.$id.'  ['.MediaDisks::PRIVATE.']  '.$dir.'/keptphoto.png'.MediaBytes::PARTIAL)->and($exit)->toBe(0);

        $locked = [];
        DB::listen(static function (QueryExecuted $query) use (&$locked): void {
            if (preg_match('/from\W+entries\W+where\W+id\W/i', $query->sql) === 1) {
                $locked[] = $query->bindings;
            }
        });

        $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);

        expect(Artisan::output())->toContain('Removed 1 of 1 orphaned or leftover file')
            ->and(array_map(static fn (array $bindings): int => (int) $bindings[0], $locked))->toContain($id)
            ->and(file_exists($this->root.'/'.$dir.'/keptphoto.png'.MediaBytes::PARTIAL))->toBeFalse()
            ->and($exit)->toBe(0);
    })->with([
        'a partial copy\'s entry past 2^61' => ['partial', (1 << 61) + 7],
        'a partial copy\'s entry below -2^61' => ['partial', -(1 << 61) - 7],
        'another disk\'s row past 2^61' => ['spelt', (1 << 61) + 7],
        'another disk\'s row below -2^61' => ['spelt', -(1 << 61) - 7],
    ]);

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
            // put() answers false on a failed write and throws nothing: fewer files seeded would read as a flatter peak.
            $written = 0;

            foreach (pruneRows($file, $settled, 'public', 'settled-'.$settled, ['visibility' => 'public']) as $path) {
                $written += (int) Storage::disk('public')->put($path, 'x');
            }

            expect($written)->toBe($settled);
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

    // ...and the pass that asks the volume (Codex, #155) holds no row it reads: one orphan sets it off over every row, and
    // prune's peak does not grow with the rows it reads (review of slice 5c).
    it('holds no row the pass reads, however many rows the table holds', function (): void {
        $file = storedForPrune($this->imageType);
        $stray = 'media/'.$this->org->getKey().'/2026/09/stray.bin';
        Storage::disk(MediaDisks::PRIVATE)->put($stray, 'x');
        // The pass's reads of the table, as the harness counts them: a peak that stays flat because the pass never ran
        // would pass as one that held nothing (review of slice 5c).
        $passed = 0;
        DB::listen(static function (QueryExecuted $query) use (&$passed): void {
            $passed += preg_match('/^select\W+id\W+,\W+entry_id\W+,\W+disk\W+,\W+path\W+from\W+media_files\W/i', $query->sql) === 1 ? 1 : 0;
        });
        $peak = function (int $rows) use ($file, &$passed): int {
            // Rows whose files no disk holds: the pass alone reads them, and stats each path.
            pruneRows($file, $rows, 'public', 'passed-'.$rows, ['visibility' => 'public']);
            $passed = 0;
            gc_collect_cycles();
            $before = memory_get_usage();
            memory_reset_peak_usage();
            Artisan::call('kitsune:media-prune', [], new NullOutput);
            $peak = memory_get_peak_usage() - $before;

            // It read every row: a read a batch, and a last one short or empty.
            expect($passed)->toBe(intdiv(DB::table('media_files')->count(), MediaPruneCommand::BATCH) + 1);

            return $peak;
        };

        $peak(1);
        $few = $peak(2_000);
        $many = $peak(10_000);

        // As built the difference reads about 0; a pass keeping each row it reads adds some 0.75 KB a row, 7.5 MB here.
        expect($many - $few)->toBeLessThan(250_000);
    });

    /*
     * ...and a listed name the pass claims as its row's own file costs it what an int does in its maps: one name of a file,
     * as most are, is held as an int, never a list — measured on SQLite, some 237 B a name as built and 453 B were each a
     * list, which would have taken (O')'s pass from about 20 MB to about 41 MB over 100,000 names (review of slice 5c).
     */
    it('holds a listed name the pass claims as an int, not a list', function (): void {
        $file = storedForPrune($this->imageType);
        $entry = (array) DB::table('entries')->where('id', $file->entry_id)->first();
        $row = (array) DB::table('media_files')->where('id', $file->getKey())->first();
        unset($entry['id'], $row['id']);
        $peak = function (int $names, string $tag) use ($entry, $row): int {
            foreach (range(1, $names) as $n) {
                // Each row spelt with a doubled slash over its file: every listed name one the pass claims as its own.
                $path = sprintf('media/%d/2027/%02d/%s-%05d.bin', $row['org_id'] ?? 1, $n % 100, $tag, $n);
                $id = DB::table('entries')->insertGetId([...$entry, 'title' => "{$tag} {$n}"]);
                DB::table('media_files')->insert([...$row, 'entry_id' => $id, 'disk' => MediaDisks::PRIVATE, 'path' => dirname($path).'//'.basename($path)]);
                @mkdir($this->root.'/'.dirname($path), 0777, true);
                file_put_contents($this->root.'/'.$path, 'x');
            }

            gc_collect_cycles();
            $before = memory_get_usage();
            memory_reset_peak_usage();
            Artisan::call('kitsune:media-prune', [], new NullOutput);

            return memory_get_peak_usage() - $before;
        };

        $peak(1, 'warm');
        $few = $peak(2_000, 'few');
        $many = $peak(8_000, 'many');

        // 8,000 names more: some 1.9 MB as built, some 3.6 MB with each a list.
        expect(($many - $few) / 8_000)->toBeLessThan(330);

        Artisan::call('kitsune:media-prune');

        expect(Artisan::output())->toContain('No orphaned media files.');
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
            // put() answers false on a failed write and throws nothing: fewer copies seeded would read as a lower slope.
            $written = 0;

            foreach (pruneRows($file, $copies, 'public', 'extra-'.$copies, ['visibility' => 'public']) as $path) {
                $written += (int) Storage::disk('public')->put($path, 'x');
                $written += (int) Storage::disk(MediaDisks::PRIVATE)->put($path, 'x');
            }

            expect($written)->toBe(2 * $copies);
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

        // ...and prune listed every copy seeded, outside the measure: a slope over copies it never listed means nothing.
        Artisan::call('kitsune:media-prune');

        expect(Artisan::output())->toContain('and 10002 removable extra copies listed and nothing removed');
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
