<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\RefusingDisk;
use League\Flysystem\Filesystem;

/*
 * kitsune:media-reconcile — ADR-042 decision 5, slice 5b (T77-T90, T104-T107, T109, T114, T119, T122, T126, T128).
 *
 * ⚠️ FROM THE DISKS, THE ROWS AND THE OUTPUT AS THEY ARE AFTERWARDS. Each case sets a row and its disks by hand, runs the
 * command as an operator would, and reads what each disk holds by hash, what the row names, the line the command printed
 * for that entry, and its exit code. Every disk is a `RefusingDisk`, so what a read-only run asked is in its log.
 */

const RECONCILE_PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\x0f\0\0\x01\x01\0\x05\x18\xd8N\0\0\0\0IEND\xaeB`\x82";

beforeEach(function (): void {
    $this->roots = [];
    $this->disks = [];

    // `old-cdn` was once a public disk: a local one with a url, so the web serves it.
    foreach (['public', MediaDisks::PRIVATE, 'old-cdn'] as $name) {
        reconcileDisk($name, $name === 'old-cdn' ? ['url' => 'https://cdn.example.test'] : null);
    }

    $this->org = Org::create(['slug' => 'reconcile', 'name' => 'Reconcile']);
    app(Context::class)->setOrg($this->org);
    app(Context::class)->setSite(Site::create(['handle' => 'main', 'slug' => 'reconcile-main', 'name' => 'Main', 'locale' => 'en']));
    $this->image = EntryType::create(['org_id' => $this->org->id, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);

    $this->checksum = hash('sha256', RECONCILE_PNG);
});

afterEach(function (): void {
    app(Context::class)->forget();

    foreach ($this->roots ?? [] as $root) {
        exec('chmod -R u+rwx '.escapeshellarg($root).' 2>/dev/null; rm -rf '.escapeshellarg($root));
    }
});

/**
 * A local disk of its own, logged; configured too when `$config` is given — a url for a served disk, [] for a plain one.
 *
 * @param  array<string, mixed>|null  $config
 */
function reconcileDisk(string $name, ?array $config = []): RefusingDisk
{
    $root = sys_get_temp_dir().'/kitsune-reconcile-'.$name.'-'.bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    test()->roots = [...(test()->roots ?? []), $root];

    if ($config !== null) {
        config(["filesystems.disks.{$name}" => ['driver' => 'local', 'root' => $root, ...$config]]);
    }

    $disk = RefusingDisk::install($name, $root);
    test()->disks = [...(test()->disks ?? []), $name => $disk];

    return $disk;
}

/**
 * A stored file, then set by hand: the row's disk and visibility, its entry trashed or not, and what each disk holds.
 *
 * @param  array<string, string>  $copies  disk => bytes
 * @return array{0: int, 1: string} the entry's id and the file's path
 */
function reconcileFile(string $named, array $copies, bool $trashed = false, string $visibility = 'public'): array
{
    $source = tempnam(sys_get_temp_dir(), 'kitsune-reconcile-');
    file_put_contents($source, RECONCILE_PNG);
    $entry = MediaLibrary::store($source, 'photo.png', test()->image, 'public');
    unlink($source);

    $path = (string) DB::table('media_files')->where('entry_id', $entry->id)->value('path');
    Storage::disk('public')->delete($path);

    DB::table('media_files')->where('entry_id', $entry->id)->update(['disk' => $named, 'visibility' => $visibility]);
    DB::table('entries')->where('id', $entry->id)->update(['deleted_at' => $trashed ? now() : null]);

    foreach ($copies as $disk => $bytes) {
        Storage::disk($disk)->put($path, $bytes);
    }

    RefusingDisk::forgetLog();

    return [(int) $entry->id, $path];
}

/** What each of these disks holds at the path: its bytes' hash, or null. @return array<string, ?string> */
function reconcileHeld(string $path, array $disks = ['public', MediaDisks::PRIVATE, 'old-cdn']): array
{
    $held = [];

    foreach ($disks as $disk) {
        $file = test()->disks[$disk]->root().'/'.$path;
        $held[$disk] = is_file($file) ? hash_file('sha256', $file) : null;
    }

    return $held;
}

function reconcileNamed(int $entryId): ?string
{
    $disk = DB::table('media_files')->where('entry_id', $entryId)->value('disk');

    return is_string($disk) ? $disk : null;
}

/** @return array{int, string} the exit code and everything printed */
function reconcileRun(array $options = []): array
{
    $exit = Artisan::call('kitsune:media-reconcile', $options);

    return [$exit, Artisan::output()];
}

/** The line printed for an entry, or null when it was not listed. */
function reconcileLine(string $output, int $entryId): ?string
{
    foreach (explode("\n", $output) as $line) {
        if (preg_match('/\sentry '.$entryId.'\s/', $line) === 1) {
            return trim($line);
        }
    }

    return null;
}

/*
 * T77. Read-only: every kind of finding is listed, by asking each disk whether it holds the path and nothing else; no
 * lock is taken, no row changes, and the run fails while findings remain.
 */
describe('a read-only run', function (): void {
    it('lists every kind of finding, asks only whether each disk holds the path, and changes nothing', function (): void {
        reconcileDisk('local');
        $rows = [
            'unknown' => reconcileFile('gone', ['public' => RECONCILE_PNG]),
            'missing' => reconcileFile('public', []),
            'exposed' => reconcileFile('public', ['public' => RECONCILE_PNG], trashed: true),
            'awaiting publication' => reconcileFile(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => RECONCILE_PNG]),
            'elsewhere' => reconcileFile('local', ['local' => RECONCILE_PNG], visibility: 'private'),
            'absent' => reconcileFile('public', [MediaDisks::PRIVATE => RECONCILE_PNG]),
            'private copy' => reconcileFile('public', ['public' => RECONCILE_PNG, MediaDisks::PRIVATE => RECONCILE_PNG]),
            'extra' => reconcileFile('public', ['public' => RECONCILE_PNG, 'old-cdn' => RECONCILE_PNG]),
        ];
        [$settled] = reconcileFile('public', ['public' => RECONCILE_PNG]);
        $before = DB::table('media_files')->orderBy('entry_id')->get(['entry_id', 'disk'])->map(fn (object $row): array => (array) $row)->all();
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });
        RefusingDisk::forgetLog();

        [$exit, $output] = reconcileRun();

        foreach ($rows as $label => [$id]) {
            expect(reconcileLine($output, $id))->toStartWith($label.' ');
        }

        expect(reconcileLine($output, $settled))->toBeNull()
            ->and($exit)->toBe(1)
            ->and(array_values(array_unique(array_column(RefusingDisk::$log, 'event'))))->toBe(['fileExists'])
            ->and(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'for update') || str_starts_with(ltrim($sql), 'update')))->toBe([])
            ->and(DB::table('media_files')->orderBy('entry_id')->get(['entry_id', 'disk'])->map(fn (object $row): array => (array) $row)->all())->toBe($before)
            ->and($output)->toContain('Asking [public], ['.MediaDisks::PRIVATE.'], [old-cdn], and the disk each row names.')
            ->and($output)->toContain('1 row names [local], which kitsune:media-prune sweeps for orphans only while a row names it')
            ->and($output)->not->toContain('names [gone]')
            ->and(strpos($output, '1 row names [local]'))->toBeLessThan(strpos($output, 'entry '.$rows['unknown'][0].' '))
            ->and($output)->toContain('nothing was changed');
    });

    it('succeeds when every row names the disk its state says, and that disk holds its file', function (): void {
        reconcileFile('public', ['public' => RECONCILE_PNG]);
        reconcileFile(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => RECONCILE_PNG], trashed: true);

        [$exit, $output] = reconcileRun();

        expect($exit)->toBe(0)
            ->and($output)->toContain('Every media row names the disk its state says');
    });
});

/*
 * T78. --force leaves every residue slice 5a can leave where its row's state says, named by its row, and nowhere else
 * custody's steps remove from.
 */
describe('a forced run', function (): void {
    it('settles every residue', function (): void {
        reconcileDisk('local');
        $r1 = reconcileFile('public', [MediaDisks::PRIVATE => RECONCILE_PNG]);
        $r3empty = reconcileFile(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => RECONCILE_PNG]);
        $r3full = reconcileFile(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => RECONCILE_PNG, 'public' => RECONCILE_PNG]);
        $r4same = reconcileFile('public', ['public' => RECONCILE_PNG, MediaDisks::PRIVATE => RECONCILE_PNG]);
        $r4differs = reconcileFile('public', ['public' => RECONCILE_PNG, MediaDisks::PRIVATE => 'changed by hand']);
        $r6 = reconcileFile('public', ['public' => RECONCILE_PNG], trashed: true);
        $r7private = reconcileFile('local', ['local' => RECONCILE_PNG], visibility: 'private');
        $r7trashed = reconcileFile('local', ['local' => RECONCILE_PNG], trashed: true);
        $r7public = reconcileFile('local', ['local' => RECONCILE_PNG]);
        $stray = reconcileFile(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => RECONCILE_PNG, 'old-cdn' => RECONCILE_PNG], trashed: true);

        [$exit, $output] = reconcileRun(['--force' => true]);

        $public = ['public' => $this->checksum, MediaDisks::PRIVATE => null, 'old-cdn' => null];
        $private = ['public' => null, MediaDisks::PRIVATE => $this->checksum, 'old-cdn' => null];

        foreach ([$r1, $r3empty, $r3full, $r4same, $r4differs, $r7public] as [$id, $path]) {
            expect(reconcileHeld($path))->toBe($public)
                ->and(reconcileNamed($id))->toBe('public')
                ->and(reconcileLine($output, $id))->toContain('→ settled');
        }

        foreach ([$r6, $r7private, $r7trashed, $stray] as [$id, $path]) {
            expect(reconcileHeld($path))->toBe($private)
                ->and(reconcileNamed($id))->toBe(MediaDisks::PRIVATE)
                ->and(reconcileLine($output, $id))->toContain('→ settled');
        }

        foreach ([$r7private, $r7trashed, $r7public] as [, $path]) {
            expect(is_file($this->disks['local']->root().'/'.$path))->toBeFalse();
        }

        expect($output)->toContain('3 rows name [local]: this run moves them off it, after which kitsune:media-prune no longer sweeps it');

        // The differing private copy is named with both hashes, on the console as in the log.
        expect($output)->toContain('Media custody: removing the copy of ['.$r4differs[1].'] on ['.MediaDisks::PRIVATE.'], whose hash ['.hash('sha256', 'changed by hand').']')
            ->and($output)->toContain('differs from the kept copy\'s ['.$this->checksum.']')
            ->and($exit)->toBe(0);
    });

    /** Core's private disk after a host moved the private disk: the row moves to the host's, and core's copy stays prune's. */
    it('moves a row off core\'s private disk once the private disk has moved', function (): void {
        reconcileDisk('host-private');
        config(['kitsune.media.disks.private' => 'host-private']);
        [$id, $path] = reconcileFile(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => RECONCILE_PNG], visibility: 'private');

        [$exit] = reconcileRun(['--force' => true]);

        expect(reconcileNamed($id))->toBe('host-private')
            ->and(reconcileHeld($path, ['host-private', MediaDisks::PRIVATE]))->toBe(['host-private' => $this->checksum, MediaDisks::PRIVATE => $this->checksum])
            ->and($exit)->toBe(0);

        // Read-only, afterwards: core's copy is an extra copy — prune's — and not a finding.
        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toStartWith('extra ')
            ->and($exit)->toBe(0);
    });

    /*
     * T79. Decision 6 through the command: a trashed file taken off the web past a copy it cannot read — set aside, left
     * untouched, and the run fails, because the row is reported (Adam, decisions 6 and 7, 2026-09-25).
     */
    it('takes a trashed file off the web past a copy it cannot read, and says so', function (): void {
        reconcileDisk('host-private');
        config(['kitsune.media.disks.private' => 'host-private']);
        [$id, $path] = reconcileFile('public', ['public' => 'changed by hand', MediaDisks::PRIVATE => RECONCILE_PNG], trashed: true);
        $this->disks[MediaDisks::PRIVATE]->unreadable = [$path];

        [$exit, $output] = reconcileRun(['--force' => true]);

        expect(reconcileLine($output, $id))->toStartWith('exposed ')
            ->and(reconcileLine($output, $id))->toContain('→ set aside: ['.MediaDisks::PRIVATE.'] cannot be read, left untouched')
            ->and($output)->toContain('on ['.MediaDisks::PRIVATE.'] exists and cannot be read')
            ->and(reconcileHeld($path, ['public', 'host-private', MediaDisks::PRIVATE]))->toBe([
                'public' => null,
                'host-private' => hash('sha256', 'changed by hand'),
                MediaDisks::PRIVATE => $this->checksum,
            ])
            ->and($exit)->toBe(1);

        // Read-only sees where the bytes are: an extra copy, prune's, and no finding.
        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toStartWith('extra ')
            ->and($exit)->toBe(0);

        // Forced, it reads them: off the web now, nothing answers for the unreadable copy, and the row fails until it can
        // be read — a check that runs --force stays red where a read-only one does not.
        [$exit, $output] = reconcileRun(['--force' => true]);

        expect(reconcileLine($output, $id))->toContain('→ failed: ')
            ->and(reconcileLine($output, $id))->toContain('exists and cannot be read')
            ->and($exit)->toBe(1);
    });

    it('leaves a row on a named disk it cannot read, and fails every run until it can be read', function (): void {
        reconcileDisk('local');
        [$id, $path] = reconcileFile('local', ['local' => 'stale', 'public' => RECONCILE_PNG], trashed: true);
        $this->disks['local']->unreadable = [$path];

        [$exit, $output] = reconcileRun(['--force' => true]);

        expect(reconcileLine($output, $id))->toContain('→ set aside')
            ->and(reconcileLine($output, $id))->toContain('the row still names [local]')
            ->and(reconcileNamed($id))->toBe('local')
            ->and(reconcileHeld($path, ['public', MediaDisks::PRIVATE]))->toBe(['public' => null, MediaDisks::PRIVATE => $this->checksum])
            ->and(file_get_contents($this->disks['local']->root().'/'.$path))->toBe('stale')
            ->and($exit)->toBe(1);

        // Off the web now, so nothing answers for the unreadable copy: the next forced run refuses the row.
        [$exit, $output] = reconcileRun(['--force' => true]);

        expect(reconcileLine($output, $id))->toContain('→ failed: ')
            ->and(reconcileLine($output, $id))->toContain('exists and cannot be read')
            ->and($exit)->toBe(1);

        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toStartWith('elsewhere ')
            ->and($exit)->toBe(1);
    });

    /*
     * T80. The listing only chooses which rows to lock: a restore landing after it is published, and its private copy
     * cleaned up — while a trash landing after it is withdrawn, and nothing reaches the public disk.
     */
    it('settles a row as a restore left it after the listing', function (): void {
        [$id, $path] = reconcileFile(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => RECONCILE_PNG, 'old-cdn' => RECONCILE_PNG], trashed: true);
        $landed = false;
        Event::listen(TransactionBeginning::class, function () use ($id, &$landed): void {
            if (! $landed) {
                $landed = true;
                DB::table('entries')->where('id', $id)->update(['deleted_at' => null]);
            }
        });

        [$exit] = reconcileRun(['--force' => true]);

        expect($landed)->toBeTrue()
            ->and(reconcileHeld($path)['public'])->toBe($this->checksum)
            ->and(reconcileHeld($path)[MediaDisks::PRIVATE])->toBeNull()
            ->and(reconcileNamed($id))->toBe('public')
            ->and($exit)->toBe(0);
    });

    it('writes nothing to the public disk for a row a trash reached after the listing', function (): void {
        [$id, $path] = reconcileFile(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => RECONCILE_PNG]);
        $landed = false;
        Event::listen(TransactionBeginning::class, function () use ($id, &$landed): void {
            if (! $landed) {
                $landed = true;
                DB::table('entries')->where('id', $id)->update(['deleted_at' => now()]);
            }
        });

        reconcileRun(['--force' => true]);

        // Written to, that is: withdrawal clears a partial beside each served path whether or not one is there.
        expect($landed)->toBeTrue()
            ->and(array_filter(RefusingDisk::$log, static fn (array $entry): bool => $entry['disk'] === 'public'
                && in_array($entry['event'], ['write', 'writeStream', 'copy', 'move'], true)))->toBe([])
            ->and(reconcileNamed($id))->toBe(MediaDisks::PRIVATE);
    });

    /*
     * T81. A publication that lands between the listing's presence checks makes the row read as missing: under --force
     * that is asked again under the lock, where the file is found.
     */
    it('asks a row the listing found missing again, under the lock', function (): void {
        [$id, $path] = reconcileFile(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => RECONCILE_PNG]);
        $private = $this->disks[MediaDisks::PRIVATE];
        // The listing asks the public disk first, then the private one: the publication lands between the two.
        $private->onOperation(1, function () use ($id, $path, $private): void {
            Storage::disk('public')->put($path, RECONCILE_PNG);
            @unlink($private->root().'/'.$path);
            DB::table('media_files')->where('entry_id', $id)->update(['disk' => 'public']);
        }, 'any');

        [$exit, $output] = reconcileRun(['--force' => true]);

        expect(reconcileLine($output, $id))->toStartWith('missing ')
            ->and(reconcileLine($output, $id))->toContain('→ nothing to do under the lock')
            // Rows, settled, nothing to do: a row custody did not touch is not counted as settled.
            ->and($output)->toMatch('/\\|\\s*missing\\s*\\|\\s*1\\s*\\|\\s*0\\s*\\|\\s*1\\s*\\|/')
            ->and($exit)->toBe(0);
    });

    /*
     * T90. A cleanup that keeps a copy is kept, and the run says so: the public copy changed between the publication and
     * its cleanup, so the private copy alone matches the checksum and stays.
     */
    it('fails a run whose cleanup kept a copy', function (): void {
        [$id, $path] = reconcileFile(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => RECONCILE_PNG]);
        $changed = false;
        Event::listen(TransactionCommitted::class, function () use ($path, &$changed): void {
            if (! $changed && Storage::disk('public')->exists($path)) {
                $changed = true;
                Storage::disk('public')->put($path, 'changed by hand');
            }
        });

        [$exit, $output] = reconcileRun(['--force' => true]);

        expect($changed)->toBeTrue()
            ->and(reconcileLine($output, $id))->toContain('→ kept')
            ->and($output)->toContain('kitsune:media-reconcile --entry='.$id.' --force rewrites [public] from it')
            ->and(reconcileHeld($path)[MediaDisks::PRIVATE])->toBe($this->checksum)
            ->and($exit)->toBe(1);
    });
});

/*
 * T82. --force refuses before it reads a row: inside an open transaction, and while the configured disks cannot keep the
 * promise — the private disk one place with the public one, or reached by an object store the web serves.
 */
describe('refusals', function (): void {
    function reconcileRefused(array $output): void
    {
        [$exit, $text] = $output;
        $lines = array_values(array_filter(explode("\n", trim($text)), static fn (string $line): bool => trim($line) !== ''));

        expect($exit)->toBe(1)
            ->and($lines)->toHaveCount(1)
            ->and($text)->not->toContain(' entry ')
            ->and(array_filter(RefusingDisk::$log, static fn (array $entry): bool => $entry['event'] === 'fileExists'))->toBe([]);
    }

    it('refuses inside an open transaction', function (): void {
        reconcileFile('public', ['public' => RECONCILE_PNG], trashed: true);

        reconcileRefused(DB::transaction(fn (): array => reconcileRun(['--force' => true])));
    });

    it('refuses while the private disk is the public one', function (): void {
        reconcileFile('public', ['public' => RECONCILE_PNG], trashed: true);
        config(['kitsune.media.disks.private' => 'public']);

        reconcileRefused(reconcileRun(['--force' => true]));
    });

    it('refuses while an object store the web serves reaches the private disk, and says so when read-only', function (): void {
        $root = sys_get_temp_dir().'/kitsune-reconcile-store-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        $this->roots[] = $root;

        foreach (['store-private' => null, 'store-cdn' => 'https://store.example.test'] as $name => $url) {
            $config = ['driver' => 's3', 'bucket' => 'media', 'endpoint' => 'e', 'prefix' => 'private', 'url' => $url];
            config(["filesystems.disks.{$name}" => $config]);
            $adapter = new RefusingDisk($root, $name);
            Storage::set($name, new FilesystemAdapter(new Filesystem($adapter), $adapter, $config));
        }

        config(['kitsune.media.disks.private' => 'store-private']);
        [$id] = reconcileFile('store-private', ['store-private' => RECONCILE_PNG], visibility: 'private');

        reconcileRefused(reconcileRun(['--force' => true]));

        [$exit, $output] = reconcileRun();

        expect($output)->toContain('kitsune.media.disks.private names [store-private], and [store-cdn], which the web serves')
            ->and($output)->toContain('kitsune:media-reconcile --force refuses until this is fixed')
            ->and(reconcileLine($output, $id))->toStartWith('exposed ')
            ->and($exit)->toBe(1);
    });
});

/*
 * T83. One row's failure is that row's: it is reported, naming the disk and the path, and the run goes on; a row on a
 * disk that is not configured fails on its own; a file no disk holds is missing. Each fails the run.
 */
describe('failures', function (): void {
    it('reports a row that fails and goes on to the next', function (): void {
        [$failing, $failingPath] = reconcileFile('public', ['public' => RECONCILE_PNG], trashed: true);
        [$next, $nextPath] = reconcileFile('public', ['public' => RECONCILE_PNG], trashed: true);
        $this->disks[MediaDisks::PRIVATE]->onOperation(1, static function (): void {
            throw new RuntimeException('the disk refused the write');
        });

        [$exit, $output] = reconcileRun(['--force' => true]);

        // The failure's own words name the disk and the path, not only the listing before them.
        expect(explode('→ failed: ', (string) reconcileLine($output, $failing))[1] ?? '')->toContain('['.$failingPath.'] on the ['.MediaDisks::PRIVATE.'] disk')
            ->and(reconcileNamed($failing))->toBe('public')
            ->and(reconcileHeld($failingPath)['public'])->toBe($this->checksum)
            ->and(reconcileLine($output, $next))->toContain('→ settled')
            ->and(reconcileHeld($nextPath))->toBe(['public' => null, MediaDisks::PRIVATE => $this->checksum, 'old-cdn' => null])
            ->and($exit)->toBe(1);
    });

    it('fails a row on a disk that is not configured, on its own, and reads it as unknown', function (): void {
        [$gone] = reconcileFile('gone', ['public' => RECONCILE_PNG], trashed: true);
        [$next, $nextPath] = reconcileFile('public', ['public' => RECONCILE_PNG], trashed: true);

        [$exit, $output] = reconcileRun(['--force' => true]);

        expect(reconcileLine($output, $gone))->toStartWith('unknown ')
            ->and(reconcileLine($output, $gone))->toContain('→ failed: ')
            ->and(reconcileHeld($nextPath)[MediaDisks::PRIVATE])->toBe($this->checksum)
            ->and($exit)->toBe(1);
    });

    it('fails a run that leaves a file no disk holds', function (): void {
        [$missing] = reconcileFile('public', []);
        [$next, $nextPath] = reconcileFile('public', ['public' => RECONCILE_PNG], trashed: true);

        [$exit, $output] = reconcileRun(['--force' => true]);

        expect(reconcileLine($output, $missing))->toContain('→ missing: no disk custody asks holds its file — kitsune:media-prune lists a copy at its path on a disk another row names')
            ->and(reconcileHeld($nextPath)[MediaDisks::PRIVATE])->toBe($this->checksum)
            ->and($exit)->toBe(1);
    });
});

/*
 * T104. Whether a configured disk holds a path cannot be told — an object store's failure — so the row is unknown, never
 * missing, and a forced run fails it.
 */
it('reads a presence check that fails as unknown, never absent', function (): void {
    [$id, $path] = reconcileFile('public', ['public' => RECONCILE_PNG], trashed: true);
    $this->disks['public']->unknown = [$path];

    [$exit, $output] = reconcileRun();

    expect(reconcileLine($output, $id))->toStartWith('unknown ')
        ->and($exit)->toBe(1);

    [$exit, $output] = reconcileRun(['--force' => true]);

    expect(reconcileLine($output, $id))->toContain('→ failed: ')
        ->and(reconcileLine($output, $id))->toContain('whether it exists cannot be told')
        ->and(reconcileHeld($path)[MediaDisks::PRIVATE])->toBeNull()
        ->and($exit)->toBe(1);
});

/* T105. Artisan reuses a command in a process: each forced run prints each custody warning once, whatever ran before. */
it('prints each custody warning once, however many forced runs came before in the process', function (): void {
    [$first] = reconcileFile('public', ['public' => RECONCILE_PNG, MediaDisks::PRIVATE => 'changed by hand']);
    [$second, $path] = reconcileFile('public', ['public' => RECONCILE_PNG, MediaDisks::PRIVATE => 'changed another way']);

    reconcileRun(['--force' => true, '--entry' => [(string) $first]]);
    [, $output] = reconcileRun(['--force' => true, '--entry' => [(string) $second]]);

    expect(substr_count($output, 'Media custody: removing the copy of ['.$path.']'))->toBe(1);
});

/*
 * T106. A configuration no disk can be resolved from: read-only says what --force would refuse, and lists every row as
 * unknown, rather than dying before the first.
 */
it('lists every row as unknown when the configuration cannot be read, rather than failing before the first', function (): void {
    [$id] = reconcileFile('public', ['public' => RECONCILE_PNG], trashed: true);
    config(['filesystems.disks.broken' => ['driver' => 'scoped', 'disk' => 'nowhere', 'prefix' => 'x']]);

    [$exit, $output] = reconcileRun();

    expect($output)->toContain('kitsune:media-reconcile --force refuses until this is fixed')
        ->and(reconcileLine($output, $id))->toStartWith('unknown ')
        ->and($exit)->toBe(1);
});

/* T85. Every org's rows, with no context set: a console asking on an operator's behalf has no org to narrow by. */
it('lists and settles another org\'s trashed file with no context set', function (): void {
    $rival = Org::create(['slug' => 'reconcile-rival', 'name' => 'Rival']);
    app(Context::class)->setOrg($rival);
    app(Context::class)->setSite(Site::create(['handle' => 'main', 'slug' => 'reconcile-rival-main', 'name' => 'Main', 'locale' => 'en']));
    $this->image = EntryType::create(['org_id' => $rival->id, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);
    [$id, $path] = reconcileFile('public', ['public' => RECONCILE_PNG], trashed: true);
    app(Context::class)->forget();

    [$exit, $output] = reconcileRun(['--force' => true]);

    expect(reconcileLine($output, $id))->toStartWith('exposed ')
        ->and(reconcileHeld($path)['public'])->toBeNull()
        ->and($exit)->toBe(0);
});

/* T86. --entry limits the run to those entries; an id no media file carries fails it before anything is listed. */
describe('--entry', function (): void {
    it('lists and settles only the entries asked for', function (): void {
        [$asked, $askedPath] = reconcileFile('public', ['public' => RECONCILE_PNG], trashed: true);
        [$other, $otherPath] = reconcileFile('public', ['public' => RECONCILE_PNG], trashed: true);

        [$exit, $output] = reconcileRun(['--force' => true, '--entry' => [(string) $asked]]);

        expect(reconcileLine($output, $asked))->not->toBeNull()
            ->and(reconcileLine($output, $other))->toBeNull()
            ->and(reconcileHeld($askedPath)['public'])->toBeNull()
            ->and(reconcileHeld($otherPath)['public'])->toBe($this->checksum)
            ->and($exit)->toBe(0);
    });

    it('fails on an entry that is not a positive whole number, as written', function (string $value): void {
        reconcileFile('public', ['public' => RECONCILE_PNG], trashed: true);

        [$exit, $output] = reconcileRun(['--entry' => [$value]]);

        expect($output)->toContain("--entry takes an entry id, a positive whole number: [{$value}] is not one")
            ->and($exit)->toBe(1);
    })->with(['0', '9223372036854775808', '1.5', 'x']);

    it('fails on an entry that has no media file, listing nothing', function (): void {
        [$id] = reconcileFile('public', ['public' => RECONCILE_PNG], trashed: true);

        [$exit, $output] = reconcileRun(['--entry' => [(string) $id, '999999']]);

        expect($output)->toContain('No media file for entry 999999')
            ->and(reconcileLine($output, $id))->toBeNull()
            ->and($exit)->toBe(1);
    });
});

/* T87. A read-only run builds no disk whose root does not exist: it holds nothing, and building it would create it. */
it('builds no disk whose root does not exist', function (): void {
    reconcileDisk('host-private');
    $core = sys_get_temp_dir().'/kitsune-reconcile-rootless-core-'.bin2hex(random_bytes(4));
    $cdn = sys_get_temp_dir().'/kitsune-reconcile-rootless-cdn-'.bin2hex(random_bytes(4));
    config([
        'kitsune.media.disks.private' => 'host-private',
        'filesystems.disks.'.MediaDisks::PRIVATE.'.root' => $core,
        'filesystems.disks.rootless-cdn' => ['driver' => 'local', 'root' => $cdn, 'url' => 'https://rootless.example.test'],
    ]);
    Storage::forgetDisk(MediaDisks::PRIVATE);
    reconcileFile('public', ['public' => RECONCILE_PNG], trashed: true);
    // And a row naming a local disk that was configured and never used — ADR-041's `local` on a fresh host, say.
    $named = sys_get_temp_dir().'/kitsune-reconcile-rootless-named-'.bin2hex(random_bytes(4));
    config(['filesystems.disks.rootless-named' => ['driver' => 'local', 'root' => $named]]);
    [$id] = reconcileFile('rootless-named', [], visibility: 'private');

    [, $output] = reconcileRun();

    expect(is_dir($core))->toBeFalse()
        ->and(is_dir($cdn))->toBeFalse()
        ->and(is_dir($named))->toBeFalse()
        ->and(reconcileLine($output, $id))->toStartWith('missing ');
});

/*
 * T88-T89. Rows are read in chunks, and the findings asked again at the end in slices whose ids are written into the
 * statement, binding nothing; a finding settled by then does not fail the run.
 */
describe('reading at scale', function (): void {
    it('reads in chunks, and asks the findings again in slices that bind nothing', function (): void {
        [$first] = reconcileFile('public', []);
        $entry = (array) DB::table('entries')->where('id', $first)->first();
        $file = (array) DB::table('media_files')->where('entry_id', $first)->first();
        unset($entry['id'], $file['id']);

        foreach (range(1, 500) as $n) {
            $id = DB::table('entries')->insertGetId([...$entry, 'title' => "Copy {$n}"]);
            DB::table('media_files')->insert([...$file, 'entry_id' => $id, 'path' => 'media/'.$this->org->id.'/2026/09/copy-'.$n.'.png']);
        }

        $reads = [];
        DB::listen(function ($query) use (&$reads): void {
            if (preg_match('/^select .* from .media_files. left join/i', ltrim($query->sql)) === 1) {
                $reads[] = ['sql' => strtolower($query->sql), 'bindings' => $query->bindings];
            }
        });

        [$exit] = reconcileRun();

        $chunks = array_values(array_filter($reads, static fn (array $read): bool => str_contains($read['sql'], 'limit 500')));
        $rechecks = array_values(array_filter($reads, static fn (array $read): bool => ! str_contains($read['sql'], 'limit')));

        expect($chunks)->toHaveCount(2)
            ->and($chunks[1]['sql'])->toMatch('/entry_id.? > \?/')
            ->and($rechecks)->toHaveCount(2)
            ->and($rechecks[0]['bindings'])->toBe([])
            ->and($rechecks[1]['bindings'])->toBe([])
            ->and($exit)->toBe(1);
    });

    it('does not count a finding settled before the end of the run', function (): void {
        [$id, $path] = reconcileFile('public', ['public' => RECONCILE_PNG], trashed: true);
        $settled = false;
        // The listing asks the private disk, the public one, then `old-cdn`: by then it has seen the file on the public
        // disk, and a withdrawal lands before the end of the run.
        $this->disks['old-cdn']->onOperation(1, function () use ($id, $path, &$settled): void {
            $settled = true;
            Storage::disk(MediaDisks::PRIVATE)->put($path, RECONCILE_PNG);
            Storage::disk('public')->delete($path);
            DB::table('media_files')->where('entry_id', $id)->update(['disk' => MediaDisks::PRIVATE]);
        }, 'any');

        [$exit, $output] = reconcileRun();

        expect($settled)->toBeTrue()
            ->and(reconcileLine($output, $id))->toStartWith('exposed ')
            ->and($output)->toContain('1 of 1 media row disagreed with where its bytes were when listed, and none still does')
            ->and($output)->not->toContain('Re-run with --force')
            ->and($exit)->toBe(0);
    });
});

/*
 * T107. A row naming the public disk under another name — Laravel's `public`, while `kitsune.media.disks.public` names
 * another disk at the same directory — is listed as awaiting publication, because delivery trusts the name; forced, only
 * the row moves: the copy there is the public disk's own, and nothing is copied or deleted (review of slice 5b).
 */
it('repoints a row naming the public disk under another name, and moves no byte', function (): void {
    $root = $this->disks['public']->root();
    config(['filesystems.disks.media' => ['driver' => 'local', 'root' => $root, 'url' => 'https://media.example.test']]);
    RefusingDisk::install('media', $root);
    config(['kitsune.media.disks.public' => 'media']);
    [$id, $path] = reconcileFile('public', ['public' => RECONCILE_PNG]);

    [$listed, $listing] = reconcileRun();
    RefusingDisk::forgetLog();
    [$exit, $output] = reconcileRun(['--force' => true]);

    expect(reconcileLine($listing, $id))->toStartWith('awaiting publication ')
        ->and($listed)->toBe(1)
        // Prune sweeps that directory as [media], whatever names it: no warning that it would stop.
        ->and($listing)->not->toContain('row names [public]')
        ->and($output)->not->toContain('row names [public]')
        ->and(reconcileNamed($id))->toBe('media')
        ->and(hash_file('sha256', $root.'/'.$path))->toBe($this->checksum)
        ->and(array_filter(RefusingDisk::$log, static fn (array $event): bool => $event['bytes']))->toBe([])
        ->and($output)->not->toContain('failed')
        ->and($exit)->toBe(0);
});

/*
 * T114. Only the same file is the target's own. A named disk whose directory merely nests inside the public disk's holds
 * another file at the path — one the web may serve — so the move-off refuses as it did, and the row still names it
 * (review of slice 5b: the first fix for T107 repointed it on `onePlace()` alone, and stranded the copy).
 */
it('refuses, and repoints nothing, when the named disk only nests inside the public disk', function (): void {
    $root = $this->disks['public']->root().'/media/sub';
    mkdir($root, 0777, true);
    config(['filesystems.disks.nested' => ['driver' => 'local', 'root' => $root]]);
    RefusingDisk::install('nested', $root);
    [$id, $path] = reconcileFile('nested', ['nested' => RECONCILE_PNG, 'public' => RECONCILE_PNG]);

    [$exit, $output] = reconcileRun(['--force' => true, '--entry' => [(string) $id]]);

    // And no advice to prune first, which refuses while the two nest: the nesting itself is named (review of 5b).
    expect(reconcileNamed($id))->toBe('nested')
        ->and(hash_file('sha256', $root.'/'.$path))->toBe($this->checksum)
        ->and(reconcileLine($output, $id))->toContain('→ failed')
        ->and($output)->not->toContain('run kitsune:media-prune --force first')
        ->and($output)->toContain('[nested]\'s media directory nests with [public]\'s: kitsune:media-prune never lists its orphans')
        ->and($exit)->toBe(1);
});

/*
 * T122. A disk nested inside the public disk is another directory, and the survey asks it: a row whose only copy is
 * there is awaiting publication, held by that disk — not missing (review of slice 5b).
 */
it('asks a disk nested inside the public disk, rather than taking it for the public disk', function (): void {
    $root = $this->disks['public']->root().'/media/sub';
    mkdir($root, 0777, true);
    config(['filesystems.disks.nested' => ['driver' => 'local', 'root' => $root]]);
    RefusingDisk::install('nested', $root);
    [$id] = reconcileFile('nested', ['nested' => RECONCILE_PNG]);

    [, $output] = reconcileRun(['--entry' => [(string) $id]]);

    expect(reconcileLine($output, $id))->toStartWith('awaiting publication ')
        ->and(reconcileLine($output, $id))->toContain('held by nested');
});

/*
 * T119. One file under two entries is not one entry: a named disk whose copy is a hard link to the target's file keeps
 * that entry on a disk the web may still serve, so the move-off refuses and the row still names it — never repointed
 * with the entry left behind (review of slice 5b).
 */
it('refuses, and repoints nothing, when the named copy is a hard link to the target\'s file', function (): void {
    $old = reconcileDisk('old');
    [$id, $path] = reconcileFile('old', ['old' => RECONCILE_PNG], trashed: true);
    $private = $this->disks[MediaDisks::PRIVATE]->root().'/'.$path;
    @mkdir(dirname($private), 0777, true);
    link($old->root().'/'.$path, $private);

    [$exit, $output] = reconcileRun(['--force' => true, '--entry' => [(string) $id]]);

    expect(reconcileNamed($id))->toBe('old')
        ->and(is_file($old->root().'/'.$path))->toBeTrue()
        ->and(reconcileLine($output, $id))->toContain('→ failed')
        ->and($exit)->toBe(1);
});

/*
 * T109. The warning before a run moves the last rows off a disk prune then stops sweeping for orphans: a former public
 * disk the web still serves included, and a run over the entries that are every row naming it — never one that leaves
 * a row behind (review of slice 5b).
 */
describe('the disks a run moves the last rows off', function (): void {
    it('warns of a served disk, which prune scans for extra copies only once no row names it', function (): void {
        reconcileFile('old-cdn', ['old-cdn' => RECONCILE_PNG]);

        [, $listed] = reconcileRun();
        [, $forced] = reconcileRun(['--force' => true]);

        expect($listed)->toContain('1 row names [old-cdn], which kitsune:media-prune sweeps for orphans only while a row names it')
            ->and($forced)->toContain('1 row names [old-cdn]: this run moves the row off it, after which kitsune:media-prune no longer sweeps it');
    });

    it('warns a run over entries only when they are every row naming the disk', function (): void {
        reconcileDisk('local');
        [$first] = reconcileFile('local', ['local' => RECONCILE_PNG], visibility: 'private');
        [$second] = reconcileFile('local', ['local' => RECONCILE_PNG], visibility: 'private');

        [, $one] = reconcileRun(['--entry' => [(string) $first]]);
        [, $both] = reconcileRun(['--entry' => [(string) $first, (string) $second]]);
        [, $forced] = reconcileRun(['--force' => true, '--entry' => [(string) $first, (string) $second]]);

        expect($one)->not->toContain('[local]')
            ->and($both)->toContain('All 2 rows naming [local] are among these entries: kitsune:media-prune sweeps [local] for orphans only while a row names it')
            ->and($forced)->toContain('All 2 rows naming [local] are among these entries: this run moves them off it');
    });

    // T126: a host's disk inside the legacy one stops prune while a row names it — so no advice to prune first.
    it('names a host disk nested inside a legacy disk rather than advising a prune that refuses', function (): void {
        $old = reconcileDisk('old');
        mkdir($old->root().'/media/host', 0777, true);
        config(['filesystems.disks.host-inner' => ['driver' => 'local', 'root' => $old->root().'/media/host']]);
        reconcileFile('old', ['old' => RECONCILE_PNG], visibility: 'private');

        [, $output] = reconcileRun();

        expect($output)->toContain('[old]\'s media directory nests with [host-inner]\'s: kitsune:media-prune never lists its orphans')
            ->and($output)->not->toContain('run kitsune:media-prune --force before');
    });

    // T128: nor for a legacy disk inside another disk a row names — past a disk entry that is no disk at all.
    it('names a legacy disk nested inside another a row names, past a disk entry it cannot read', function (): void {
        // Configured before the others, so the scan meets it first.
        config(['filesystems.disks.aaa-broken' => 'not a disk']);
        $outer = reconcileDisk('outer');
        mkdir($outer->root().'/media/inner', 0777, true);
        config(['filesystems.disks.inner' => ['driver' => 'local', 'root' => $outer->root().'/media/inner']]);
        RefusingDisk::install('inner', $outer->root().'/media/inner');
        reconcileFile('outer', ['outer' => RECONCILE_PNG], visibility: 'private');
        reconcileFile('inner', ['inner' => RECONCILE_PNG], visibility: 'private');

        [, $output] = reconcileRun();

        expect($output)->toContain('[inner]\'s media directory nests with [outer]\'s: kitsune:media-prune never lists its orphans')
            ->and($output)->not->toContain('row names [inner], which kitsune:media-prune sweeps');
    });

    it('never warns of the configured disks or core\'s own', function (): void {
        reconcileFile('public', ['public' => RECONCILE_PNG]);
        reconcileFile(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => RECONCILE_PNG], trashed: true);

        [, $output] = reconcileRun();

        expect($output)->not->toContain('sweeps for orphans');
    });
});
