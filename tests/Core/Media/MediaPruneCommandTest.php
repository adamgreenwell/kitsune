<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Filesystem\FilesystemAdapter;
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
use Kitsune\Core\Tests\Fixtures\RefusingDisk;
use League\Flysystem\Filesystem;

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
 * What custody leaves, and what prune does with it — ADR-042 decision 5 (T20, T21).
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
            ->and($said($sections['extras'], $this->paths['only on private']))->toContain('kept: the disk its row names does not hold the file')
            ->and($said($sections['extras'], $this->paths['published, uncommitted']))->toContain('kept: kitsune:media-reconcile moves its row first');

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

        $sections = pruneSections(['--force' => true]);

        expect($sections['exit'])->toBe('0')
            ->and($sections['all'])->toContain('Removed 3 of 3 orphaned or leftover files and 3 of 3 extra copies.');

        Storage::disk(MediaDisks::PRIVATE)->assertExists($this->paths['only on private']);
        Storage::disk('public')->assertExists($this->paths['published, uncommitted']);
        Storage::disk(MediaDisks::PRIVATE)->assertExists($this->paths['published, uncommitted']);
        Storage::disk('old-cdn')->assertExists($this->paths['the host\'s']);

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
     * T93. The listing chooses; the lock decides: a trash landing after the listing makes the private disk the one the
     * file belongs on, so its copy there is kept, and the run says so.
     */
    it('keeps an extra copy whose row a trash reached after the listing', function (): void {
        $restored = DB::table('media_files')->where('path', $this->paths['restored'])->value('entry_id');
        $landed = false;
        Event::listen(TransactionBeginning::class, function () use ($restored, &$landed): void {
            if (! $landed) {
                $landed = true;
                DB::table('entries')->where('id', $restored)->update(['deleted_at' => now()]);
            }
        });

        $sections = pruneSections(['--force' => true]);

        expect($landed)->toBeTrue()
            ->and(hash('sha256', (string) Storage::disk(MediaDisks::PRIVATE)->get($this->paths['restored'])))->toBe(hash('sha256', pruneFixtureBytes()))
            ->and($sections['all'])->not->toContain('Could not remove')
            ->and($sections['all'])->toContain('Kept ['.MediaDisks::PRIVATE.':'.$this->paths['restored'].'], entry '.$restored)
            ->and($sections['all'])->toContain('1 kept under the lock')
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

        expect($sections['all'])->toContain('Kept ['.MediaDisks::PRIVATE.':'.$file->path.']');
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
            ->and($sections['all'])->toContain('a row claimed');

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
