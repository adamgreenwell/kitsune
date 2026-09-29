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
use Illuminate\Filesystem\ReadThroughFilesystem;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\RefusingDisk;
use League\Flysystem\Filesystem;

/*
 * kitsune:media-reconcile — ADR-042 decision 5 (slice 5b: T77-T90, T104-T107, T109, T114, T119, T122, T126, T128;
 * slice 5c: T129-T133, T147-T150, T158, T159, T166, T167, T172, T175, T177-T180, T183).
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

/** Each copy opened since the log was last cleared, once, as [disk, path]. @return list<array{0: ?string, 1: ?string}> */
function reconcileOpened(): array
{
    return collect(RefusingDisk::$log)
        ->where('event', 'readStream')
        ->map(static fn (array $event): array => [$event['disk'], $event['path']])
        ->unique()
        ->values()
        ->all();
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
 * T77. Read-only: every kind of finding is listed, by asking each disk whether it holds the path ~~and nothing else~~ —
 * and opening only an extra row's copies, one byte each, the copy an `elsewhere` row names beside the file where it
 * belongs (T147), and the copy an `awaiting publication` row names on a disk that is neither private one while the
 * configured private disk holds the file too — the row a restored entry's set-aside copy leaves, tested by 'names a
 * set-aside copy once its entry is restored'; this case's awaiting row names the private disk, so nothing of it is opened
 * (Adam, decision 12, 2026-09-26); no lock is taken, no row changes, nothing is hashed, and the run fails while findings
 * remain.
 */
describe('a read-only run', function (): void {
    it('lists every kind of finding, asks whether each disk holds the path, opens only an extra copy, and changes nothing', function (): void {
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
            ->and(array_values(array_diff(array_unique(array_column(RefusingDisk::$log, 'event')), ['fileExists', 'readStream'])))->toBe([])
            // Every copy of the extra row, the served one included, and nothing else — the private copy's row is a finding.
            ->and(array_values(array_unique(array_column(array_filter(RefusingDisk::$log, static fn (array $e): bool => $e['event'] === 'readStream'), 'path'))))->toBe([$rows['extra'][1]])
            ->and(collect(RefusingDisk::$log)->where('event', 'readStream')->pluck('disk')->sort()->values()->all())->toBe(['old-cdn', 'public'])
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

        // ~~Read-only sees where the bytes are: an extra copy, prune's, and no finding.~~ Read-only opens both copies of a
        // file held twice, and fails on the one that cannot be read, as --force does (Adam, decision 12, 2026-09-26).
        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toStartWith('unreadable ')
            ->and(reconcileLine($output, $id))->toEndWith('— ['.MediaDisks::PRIVATE.'] cannot be read')
            ->and($output)->toContain('1 of 1 media row holds a copy that cannot be read')
            ->and($output)->not->toContain('Re-run with --force')
            ->and($exit)->toBe(1);

        // Forced, it reads them: off the web now, nothing answers for the unreadable copy, and the row fails until it can
        // be read — ~~a check that runs --force stays red where a read-only one does not~~ as the read-only check does.
        [$exit, $output] = reconcileRun(['--force' => true]);

        expect(reconcileLine($output, $id))->toContain('→ failed: ')
            ->and(reconcileLine($output, $id))->toContain('exists and cannot be read')
            ->and($exit)->toBe(1);
    });

    // ...and once its entry is restored, the row it becomes — awaiting publication, still naming the disk that cannot read
    // its copy, the private disk holding the file — is named read-only too: for a public target no copy is set aside,
    // so every --force refuses it until it can be read (review of slice 5c).
    it('names a set-aside copy once its entry is restored, and fails every run until it can be read', function (): void {
        reconcileDisk('local');
        [$id, $path] = reconcileFile('local', ['local' => 'stale', 'public' => RECONCILE_PNG], trashed: true);
        $this->disks['local']->unreadable = [$path];

        [, $output] = reconcileRun(['--force' => true]);

        expect(reconcileLine($output, $id))->toContain('→ set aside');

        Entry::withTrashed()->findOrFail($id)->restore();
        RefusingDisk::forgetLog();
        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toStartWith('awaiting publication ')
            ->and(reconcileLine($output, $id))->toEndWith('— [local] cannot be read')
            ->and(reconcileOpened())->toBe([['local', $path]])
            ->and($output)->toContain('make it readable')
            ->and($output)->not->toContain('Re-run with --force')
            ->and($exit)->toBe(1);

        [$exit, $output] = reconcileRun(['--force' => true]);

        expect(reconcileLine($output, $id))->toContain('exists and cannot be read')->and($exit)->toBe(1);

        $this->disks['local']->unreadable = [];
        [$exit] = reconcileRun(['--force' => true]);

        expect($exit)->toBe(0)->and(reconcileNamed($id))->toBe('public');
    });

    // ...and so is any `elsewhere` row's in that state: one naming core's private disk after the private disk moved,
    // its files copied ahead — --force repoints it, then keeps the copy it cannot read (review of slice 5c).
    it('names the copy an elsewhere row names beside the file where it belongs, whatever left it there', function (): void {
        reconcileDisk('host-private');
        config(['kitsune.media.disks.private' => 'host-private']);
        [$id, $path] = reconcileFile(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => RECONCILE_PNG, 'host-private' => RECONCILE_PNG], trashed: true, visibility: 'private');
        $this->disks[MediaDisks::PRIVATE]->unreadable = [$path];

        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toStartWith('elsewhere ')
            ->and(reconcileLine($output, $id))->toEndWith('— ['.MediaDisks::PRIVATE.'] cannot be read')
            ->and(reconcileOpened())->toContain([MediaDisks::PRIVATE, $path])
            ->and($exit)->toBe(1);

        [$exit, $output] = reconcileRun(['--force' => true]);

        expect(reconcileLine($output, $id))->toContain('→ settled, then kept')
            ->and(reconcileNamed($id))->toBe('host-private')
            ->and($exit)->toBe(1);
    });

    // ...and for a path that holds `]`, which ended the name the line's pattern took: the disk set aside, and that the row
    // still names it, are said all the same (review of slice 5c).
    it('names the disk set aside, and that the row still names it, for a path that holds a bracket', function (): void {
        reconcileDisk('local');
        [$id, $path] = reconcileFile('local', [], trashed: true);
        $bracketed = dirname($path).'/photo [1].png';
        DB::table('media_files')->where('entry_id', $id)->update(['path' => $bracketed]);
        Storage::disk('local')->put($bracketed, 'stale');
        Storage::disk('public')->put($bracketed, RECONCILE_PNG);
        $this->disks['local']->unreadable = [$bracketed];

        [$exit, $output] = reconcileRun(['--force' => true]);

        expect(reconcileLine($output, $id))->toContain('→ set aside: [local] cannot be read')
            ->and(reconcileLine($output, $id))->toContain('; the row still names [local]')
            ->and($exit)->toBe(1);
    });

    // ...and where the row is gone by the time the line is written — erased beside the run — the disk set aside is named
    // all the same, from custody's own words (review of slice 5c).
    it('names the disk set aside where the row is gone before the line is written', function (): void {
        reconcileDisk('local');
        [$id, $path] = reconcileFile('local', ['local' => 'stale', 'public' => RECONCILE_PNG], trashed: true);
        $this->disks['local']->unreadable = [$path];
        Event::listen(MessageLogged::class, static function (MessageLogged $logged) use ($id): void {
            if (str_contains($logged->message, 'exists and cannot be read, so it was left')) {
                DB::table('media_files')->where('entry_id', $id)->delete();
            }
        });

        [, $output] = reconcileRun(['--force' => true]);

        expect(reconcileLine($output, $id))->toContain('→ set aside: [local] cannot be read')
            ->and(reconcileLine($output, $id))->not->toContain('the row still names');
    });

    /*
     * ...and a copy the command cannot reach — in a directory its user may not search, as a private disk's are where the
     * web server wrote them — is one whose presence cannot be told, never an absent one: the check passed on a trashed
     * file's copy left on the web, and called a file missing that is there, advising its entry erased (review of 5c).
     */
    it('lists a row unknown where a copy it holds cannot be reached, never absent or missing', function (string $case): void {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            test()->markTestSkipped('root searches a directory whatever its mode');
        }

        reconcileDisk('host-private');
        config(['kitsune.media.disks.private' => 'host-private']);

        // A served read-through disk answers by its local halves' `is_file()`, which reads a refused stat as absence too.
        if (str_starts_with($case, 's-')) {
            reconcileDisk('s-primary');
            reconcileDisk('s-fallback');
            config(['filesystems.disks.s' => ['driver' => 'read-through', 'primary' => 's-primary', 'fallback' => 's-fallback', 'url' => 'https://s.example.test']]);
        }

        $served = $case === 'only' ? [] : [($case === 'on the web' ? 'public' : $case) => RECONCILE_PNG];
        [$id, $path] = reconcileFile('host-private', ['host-private' => RECONCILE_PNG, ...$served], trashed: true);
        $locked = $this->disks[$case === 'only' ? 'host-private' : array_key_first($served)]->root().'/'.dirname($path);
        chmod($locked, 0000);

        try {
            foreach ([[], ['--force' => true]] as $options) {
                [$exit, $output] = reconcileRun($options);

                expect(reconcileLine($output, $id))->toStartWith('unknown ')
                    ->and(reconcileLine($output, $id))->toContain('whether it exists cannot be told')
                    ->and($output)->not->toContain('erase the entry')
                    ->and($exit)->toBe(1);
            }
        } finally {
            chmod($locked, 0755);
        }

        expect(is_file($locked.'/'.basename($path)))->toBeTrue();
    })->with([
        'a trashed file\'s copy on the web' => 'on the web',
        'its only copy' => 'only',
        'a trashed file\'s copy on a served read-through disk\'s primary' => 's-primary',
        '...and on its fallback' => 's-fallback',
    ]);

    it('leaves a row on a named disk it cannot read, and fails every run until it can be read', function (): void {
        reconcileDisk('local');
        [$id, $path] = reconcileFile('local', ['local' => 'stale', 'public' => RECONCILE_PNG], trashed: true);
        $this->disks['local']->unreadable = [$path];

        // T147: before the set-aside, --force is what takes the file off the web, so read-only sends the row there.
        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toStartWith('exposed ')
            ->and($output)->toContain('Re-run with --force')
            // ...where the run fails on the copy, and the row is kept, set aside — not failed (review of slice 5c).
            ->and($output)->toContain('one that cannot be read named, and the run failed on it, its row kept or failed')
            ->and($exit)->toBe(1);

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

        // After it, no --force can read the copy the row names: read-only names it, and does not send the row there. It
        // opens that copy alone, on its own disk — not the one beside it where the row belongs.
        RefusingDisk::forgetLog();
        [$exit, $output] = reconcileRun();

        // Twice: once listed, once asked again at the end of the run, since it is a finding (review of slice 5c).
        expect(reconcileOpened())->toBe([['local', $path]])
            ->and(collect(RefusingDisk::$log)->where('event', 'readStream')->count())->toBe(2)
            ->and(reconcileLine($output, $id))->toStartWith('elsewhere ')
            ->and(reconcileLine($output, $id))->toEndWith('— [local] cannot be read')
            ->and($output)->toContain('make it readable')
            ->and($output)->not->toContain('Re-run with --force')
            ->and($exit)->toBe(1);

        // The copy is reconcile's alone: at the row's own path on the row's own disk, prune never lists it, and keeps the
        // row's other copy for reconcile — passing, whether or not the copy can be read.
        $pruned = Artisan::call('kitsune:media-prune', ['--force' => true]);

        expect(Artisan::output())->toContain('kept: kitsune:media-reconcile moves its row first')
            ->and($pruned)->toBe(0)
            ->and(file_get_contents($this->disks['local']->root().'/'.$path))->toBe('stale');

        // Readable again, it is a row --force can put right, and does.
        $this->disks['local']->unreadable = [];
        RefusingDisk::forgetLog();
        [$exit, $output] = reconcileRun();

        expect(reconcileOpened())->toBe([['local', $path]])
            ->and(collect(RefusingDisk::$log)->where('event', 'readStream')->count())->toBe(2)
            ->and(reconcileLine($output, $id))->toStartWith('elsewhere ')
            ->and(reconcileLine($output, $id))->not->toContain('cannot be read')
            ->and($output)->toContain('Re-run with --force')
            ->and($exit)->toBe(1);

        [$exit] = reconcileRun(['--force' => true]);

        expect($exit)->toBe(0)
            ->and(reconcileNamed($id))->toBe(MediaDisks::PRIVATE)
            ->and(is_file($this->disks['local']->root().'/'.$path))->toBeFalse();
    });

    // ...and on a disk named with digits alone, custody says the row still names it, not that prune will list the copy —
    // which prune never does, since it sits at the row's own path on the row's own disk (review of slice 5c).
    it('says a row still names a disk named with digits alone whose copy it set aside', function (): void {
        reconcileDisk('7');
        [$id, $path] = reconcileFile('7', ['7' => 'stale', 'public' => RECONCILE_PNG], trashed: true);
        $this->disks['7']->unreadable = [$path];

        [, $output] = reconcileRun(['--force' => true]);

        expect(reconcileLine($output, $id))->toContain('the row still names [7]')
            ->and($output)->toContain(sprintf('The row still names [7]; once it can be read, kitsune:media-reconcile --entry=%d --force settles it.', $id))
            ->and($output)->not->toContain('kitsune:media-prune lists it');
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
    // A disk named with what the console reads as a style is named as it is, in the refusal, the read-only warning and the
    // header, by prune's refusal too: stripped, the line named another disk than the one to fix (review of slice 5c).
    it('names a styled private disk the web serves as it is, read-only, forced and in prune', function (): void {
        reconcileDisk('v<info>ault', ['url' => 'https://v.example.test']);
        config(['kitsune.media.disks.private' => 'v<info>ault']);
        Storage::disk('public')->put('media/orphan.png', 'bytes');

        [$exit, $output] = reconcileRun();

        expect($output)->toContain('kitsune.media.disks.private names [v<info>ault], which the web serves')
            ->and($output)->toContain('on [v<info>ault] otherwise. Asking')
            ->and($exit)->toBe(0);

        [$exit, $output] = reconcileRun(['--force' => true]);

        expect($output)->toContain('kitsune.media.disks.private names [v<info>ault], which the web serves')
            ->and($exit)->toBe(1);

        $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);

        expect(Artisan::output())->toContain('kitsune.media.disks.private names [v<info>ault], which the web serves')
            ->and($exit)->toBe(1)
            ->and(Storage::disk('public')->exists('media/orphan.png'))->toBeTrue();
    });

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

        // Read-only, sent to --force, and not to a hand as a file held on a read-through disk: none holds it (5c).
        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $missing))->toStartWith('missing ')
            ->and($output)->toContain('1 of 1 media row disagrees with where its bytes are, and nothing was changed. Re-run with --force')
            ->and($output)->not->toContain('held on a read-through disk')
            ->and($exit)->toBe(1);

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

/*
 * A row path the disks read as another, or refuse — a doubled slash, a tab, written past `MediaFile` — is `misnamed`, a
 * finding, before any disk is asked, read-only and forced alike: asked about as the path the disks read, the check read
 * such a row as settled, or as a finding --force puts right, or as a disk that could not answer, while every forced run
 * refused it — copied, it would be written through the name the disks read, over any file another row keeps there
 * (review of slice 5c). Nothing moves, and each run says to correct the row.
 */
it('lists a row whose path the disks read as another as misnamed, and --force refuses it before any disk is asked', function (string $named, array $copies, bool $trashed, string $spelling): void {
    [$id, $path] = reconcileFile($named, $copies, trashed: $trashed);
    $written = dirname($path).$spelling.basename($path);
    DB::table('media_files')->where('entry_id', $id)->update(['path' => $written]);
    $held = reconcileHeld($path);
    $asked = static fn (): array => array_filter(RefusingDisk::$log, static fn (array $e): bool => str_contains((string) ($e['path'] ?? ''), basename($path)));

    foreach ([[], ['--force' => true], []] as $options) {
        RefusingDisk::forgetLog();
        [$exit, $output] = reconcileRun($options);

        expect(reconcileLine($output, $id))->toStartWith('misnamed ')
            // No disk was asked, so none is said to have failed to answer.
            ->and(reconcileLine($output, $id))->toContain('not asked — ')
            ->and(reconcileLine($output, $id))->not->toContain('held by')
            ->and($output)->toContain('correct media_files.path')
            ->and($output)->not->toContain('Re-run with --force')
            ->and($output)->not->toContain('make the disk reachable')
            ->and($output)->not->toContain('Every media row names the disk its state says')
            ->and($asked())->toBe([])
            ->and(reconcileHeld($path))->toBe($held)
            ->and($exit)->toBe(1);
    }
})->with([
    'settled, held twice — once green' => ['public', ['public' => RECONCILE_PNG, 'old-cdn' => RECONCILE_PNG], false, '//'],
    'awaiting publication — once sent to --force' => [MediaDisks::PRIVATE, [MediaDisks::PRIVATE => RECONCILE_PNG], false, '//'],
    'trashed on the public disk' => ['public', ['public' => RECONCILE_PNG], true, '//'],
    'a name the disks refuse — once a disk that could not answer' => ['public', ['public' => RECONCILE_PNG], false, "/\t"],
]);

/*
 * ...and a public disk whose driver cannot be built lists its rows as unknown, rather than dying before the first: Laravel's
 * refusal is an InvalidArgumentException, which the check of the configuration once let past (review of slice 5c).
 */
it('lists a row as unknown when the public disk\'s driver cannot be built, rather than dying', function (): void {
    [$id] = reconcileFile('public', ['public' => RECONCILE_PNG]);
    config([
        'filesystems.disks.store-missing' => ['driver' => 'not-installed'],
        'kitsune.media.disks.public' => 'store-missing',
    ]);

    [$exit, $output] = reconcileRun();

    expect($output)->toContain('Listing, read-only')
        ->and(reconcileLine($output, $id))->toStartWith('unknown ')
        ->and(reconcileLine($output, $id))->toContain('whether it exists cannot be told')
        ->and($exit)->toBe(1);
});

/*
 * T105, for the nesting a run remembers: Artisan reuses a command in a process, and whether a disk nests with the target
 * is asked again in each run — a stale answer passed an unreadable copy on a disk that no longer nests (review of 5c).
 */
it('asks again in each run whether a disk nests with the target, however many runs came before in the process', function (): void {
    $host = reconcileDisk('host', ['url' => 'https://host.example.test']);
    mkdir($host->root().'/media/site', 0777, true);
    config(['filesystems.disks.site' => ['driver' => 'local', 'root' => $host->root().'/media/site', 'url' => 'https://site.example.test']]);
    RefusingDisk::install('site', $host->root().'/media/site');
    config(['kitsune.media.disks.public' => 'site']);
    [$id, $path] = reconcileFile('site', ['site' => RECONCILE_PNG, 'host' => RECONCILE_PNG]);

    [$exit, $output] = reconcileRun(['--entry' => [(string) $id]]);

    expect(reconcileLine($output, $id))->toStartWith('extra ')->and($exit)->toBe(0);

    // [host] moves to a root of its own, where its copy cannot be read.
    $moved = reconcileDisk('host', ['url' => 'https://host.example.test']);
    Storage::disk('host')->put($path, RECONCILE_PNG);
    $moved->unreadable = [$path];

    [$exit, $output] = reconcileRun(['--entry' => [(string) $id]]);

    expect(reconcileLine($output, $id))->toStartWith('unreadable ')
        ->and(reconcileLine($output, $id))->toContain('[host] cannot be read')
        ->and($exit)->toBe(1);
});

/*
 * T126, for a read-through disk a driver of the host's own builds, whose half written inline lies inside the legacy
 * disk: prune scans it — served, or named by a row — and builds it, and so refuses to list while the two nest; the
 * warning says so, not "run kitsune:media-prune first" (review of slice 5c).
 */
it('warns that prune never sweeps a legacy disk a host-built disk it scans nests in', function (bool $served): void {
    $old = reconcileDisk('old');
    mkdir($old->root().'/media/sub', 0777, true);
    Storage::extend('mirror', static fn ($app) => $app['filesystem']->createReadThroughDriver(['driver' => 'read-through', 'primary' => ['driver' => 'local', 'root' => $old->root().'/media/sub'], 'fallback' => 'local']));
    config(['filesystems.disks.hostrt' => ['driver' => 'mirror', ...($served ? ['url' => 'https://hostrt.example.test'] : [])]]);
    reconcileFile('old', ['old' => RECONCILE_PNG]);

    if (! $served) {
        reconcileFile('hostrt', [MediaDisks::PRIVATE => RECONCILE_PNG]);
    }

    [, $output] = reconcileRun();

    expect($output)->toContain("[old]'s media directory nests with [hostrt]'s")
        ->and($output)->not->toContain('run kitsune:media-prune --force before');
})->with(['served by its own url' => true, 'named by a row' => false]);

/*
 * T126, for a disk inside the legacy one that cannot be built at all: prune compares it from its configuration alone and
 * still refuses to list, so the warning says the two nest — for the legacy disk, and for the unbuildable disk's own row
 * (review of slice 5c).
 */
it('warns that prune never sweeps a legacy disk a disk it cannot build nests in', function (bool $served): void {
    $old = reconcileDisk('old', $served ? ['url' => 'https://old.example.test'] : []);
    mkdir($old->root().'/media/host', 0777, true);
    // A read-only local disk needs a Flysystem package core does not install, so building it throws.
    config(['filesystems.disks.host-archive' => ['driver' => 'local', 'root' => $old->root().'/media/host', 'read-only' => true]]);
    reconcileFile('old', ['old' => RECONCILE_PNG]);

    if (! $served) {
        reconcileFile('host-archive', ['public' => RECONCILE_PNG]);
    }

    [, $output] = reconcileRun();

    expect($output)->toContain("[old]'s media directory nests with [host-archive]'s")
        ->and($output)->not->toContain('row names [old], which kitsune:media-prune sweeps');

    if (! $served) {
        expect($output)->toContain("[host-archive]'s media directory nests with [old]'s")
            ->and($output)->not->toContain('run kitsune:media-prune --force before');
    }
})->with(['served by its own url' => true, 'named by a row' => false]);

/*
 * T87, where another disk was skipped: a served alias of the public disk is skipped while nothing holds the file, and
 * a disk the row names whose root does not exist is still not built, read-only (review of slice 5c).
 */
it('builds no named disk without a root when another disk was skipped as the public disk', function (): void {
    config(['filesystems.disks.pubalias' => ['driver' => 'local', 'root' => $this->disks['public']->root()]]);
    RefusingDisk::install('pubalias', $this->disks['public']->root());
    $named = sys_get_temp_dir().'/kitsune-reconcile-rootless-named-'.bin2hex(random_bytes(4));
    $this->roots[] = $named;
    config(['filesystems.disks.rootless-named' => ['driver' => 'local', 'root' => $named]]);
    [$id] = reconcileFile('rootless-named', []);

    [, $output] = reconcileRun();

    expect(MediaDisks::servedDisks(config()))->toContain('pubalias')
        ->and(is_dir($named))->toBeFalse()
        ->and(reconcileLine($output, $id))->toStartWith('missing ');
});

/*
 * T87, for a disk of a host's own driver nothing uses: a read-only check and a read-only prune build none — one that
 * would create its root creates none, and one whose build recurses is never run — where both once did, on every run
 * (review of slice 5c, twice).
 */
it('builds no disk of a host\'s own driver that nothing uses, read-only', function (string $setup): void {
    $built = 0;
    $root = sys_get_temp_dir().'/kitsune-reconcile-host-unused-'.bin2hex(random_bytes(4));
    $this->roots[] = $root;
    Storage::extend('counting', static function ($app, array $config) use (&$built) {
        $built++;

        return $app['filesystem']->createLocalDriver($config);
    });
    // Built under the manager's own name: named after its half, Laravel refuses it before building anything.
    Storage::extend('mirror-self', static fn ($app) => $app['filesystem']->createReadThroughDriver(['driver' => 'read-through', 'primary' => 'host-self', 'fallback' => 'local']));
    config($setup === 'counting'
        ? ['filesystems.disks.host-archive' => ['driver' => 'counting', 'root' => $root]]
        : ['filesystems.disks.host-self' => ['driver' => 'mirror-self']]);
    [$id] = reconcileFile('public', ['public' => RECONCILE_PNG]);
    $limit = ini_get('memory_limit');
    // A missing guard fails the test, not by growing into a laptop's unlimited memory.
    ini_set('memory_limit', (string) (memory_get_usage(true) + 128 * 1024 * 1024));

    try {
        [$exit] = reconcileRun();
        $pruned = Artisan::call('kitsune:media-prune');
    } finally {
        ini_set('memory_limit', $limit);
    }

    expect($exit)->toBe(0)
        ->and($pruned)->toBe(0)
        ->and($built)->toBe(0)
        ->and(is_dir($root))->toBeFalse()
        ->and(reconcileNamed($id))->toBe('public');
})->with(['one that would create its root' => 'counting', 'one built over itself' => 'self']);

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

    it('fails on an entry that is not a whole number, as written', function (string $value): void {
        reconcileFile('public', ['public' => RECONCILE_PNG], trashed: true);

        [$exit, $output] = reconcileRun(['--entry' => [$value]]);

        expect($output)->toContain("--entry takes an entry id, a whole number: [{$value}] is not one")
            ->and($exit)->toBe(1);
    })->with(['9223372036854775808', '-9223372036854775809', '-0', '1.5', '+1', 'x', '']);

    // T178: an entry of zero or below, which SQLite's and PostgreSQL's signed ids hold and prune lists (T160), is one
    // --entry takes — the command prune and custody advise for it ran nothing (review of slice 5c).
    it('lists and settles an entry of zero or below', function (int $id): void {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            test()->markTestSkipped('MySQL\'s and MariaDB\'s ids are unsigned, so a negative one is out of range, and an explicit 0 into AUTO_INCREMENT takes the next id');
        }

        [$stored, $path] = reconcileFile('public', ['public' => RECONCILE_PNG], trashed: true);
        $entry = (array) DB::table('entries')->where('id', $stored)->first();
        DB::table('entries')->insert([...$entry, 'id' => $id]);
        DB::table('media_files')->where('entry_id', $stored)->update(['entry_id' => $id]);

        [$exit, $output] = reconcileRun(['--entry' => [(string) $id]]);

        expect(reconcileLine($output, $id))->toStartWith('exposed ')->and($exit)->toBe(1);

        [$exit] = reconcileRun(['--entry' => [(string) $id], '--force' => true]);

        expect($exit)->toBe(0)
            ->and(reconcileNamed($id))->toBe(MediaDisks::PRIVATE)
            ->and(reconcileHeld($path, ['public', MediaDisks::PRIVATE]))->toBe(['public' => null, MediaDisks::PRIVATE => $this->checksum]);
    })->with(['zero' => 0, 'minus one' => -1]);

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
 * statement, binding nothing; a finding settled by then does not fail the run. The count of rows each disk name holds,
 * made in PHP since slice 5c, is read in chunks too — every row at once is 46 MB at 100,000 rows — and counts every
 * chunk (T183, review of slice 5c).
 */
describe('reading at scale', function (): void {
    // T183: a row naming the legacy disk in each chunk — so a count of one chunk alone, first or last, says one row.
    it('counts the rows naming a disk across every chunk', function (): void {
        reconcileDisk('local');
        [$first] = reconcileFile('local', ['local' => RECONCILE_PNG], visibility: 'private');
        $entry = (array) DB::table('entries')->where('id', $first)->first();
        $file = (array) DB::table('media_files')->where('entry_id', $first)->first();
        unset($entry['id'], $file['id']);

        foreach (range(1, 500) as $n) {
            $id = DB::table('entries')->insertGetId([...$entry, 'title' => "Copy {$n}"]);
            DB::table('media_files')->insert([...$file, 'entry_id' => $id, 'disk' => MediaDisks::PRIVATE, 'path' => 'media/'.$this->org->id.'/2026/09/copy-'.$n.'.png']);
        }

        $last = DB::table('entries')->insertGetId([...$entry, 'title' => 'Last']);
        DB::table('media_files')->insert([...$file, 'entry_id' => $last, 'path' => 'media/'.$this->org->id.'/2026/09/last.png']);

        [, $listed] = reconcileRun();
        [, $one] = reconcileRun(['--entry' => [(string) $first]]);
        [, $both] = reconcileRun(['--entry' => [(string) $first, (string) $last]]);

        expect($listed)->toContain('2 rows name [local], which kitsune:media-prune sweeps for orphans only while a row names it')
            ->and($one)->not->toContain('rows naming [local]')
            ->and($one)->not->toContain('row naming [local]')
            ->and($both)->toContain('All 2 rows naming [local] are among these entries');
    });

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
        $counted = [];
        DB::listen(function ($query) use (&$reads, &$counted): void {
            if (preg_match('/^select .* from .media_files. left join/i', ltrim($query->sql)) === 1) {
                $reads[] = ['sql' => strtolower($query->sql), 'bindings' => $query->bindings];
            }

            if (preg_match('/^select\W+id\W+,\W+disk\W+from\W+media_files\W/i', ltrim($query->sql)) === 1) {
                $counted[] = strtolower($query->sql);
            }
        });

        [$exit] = reconcileRun();

        expect($counted)->toHaveCount(2)
            ->and($counted[0])->toContain('limit 500')
            ->and($counted[1])->toContain('limit 500')
            ->and($counted[1])->toMatch('/id.? > \?/');

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
            ->and($output)->toContain('1 of 1 media row was a finding when listed, and none still is')
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
        ->and($listing)->toContain('Re-run with --force')
        ->and($listing)->not->toContain('disk reaches the disk its file belongs on')
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
 * ...and whether or not the public disk has made the path's directory yet: the two names are one media directory, so
 * settle's copy writes the file where both reach it, and only the row moves. Before that directory existed, the check
 * and prune took the pair for two disks that overlap, and sent to a person a row --force settles (review of slice 5c).
 */
it('sends a row naming the public disk under another name to --force before its directory exists', function (): void {
    $root = $this->disks['public']->root();
    config(['filesystems.disks.media' => ['driver' => 'local', 'root' => $root, 'url' => 'https://media.example.test']]);
    RefusingDisk::install('media', $root);
    config(['kitsune.media.disks.public' => 'media']);
    [$id, $path] = reconcileFile('public', [MediaDisks::PRIVATE => RECONCILE_PNG]);
    exec('rm -rf '.escapeshellarg($root.'/media'));

    [$exit, $output] = reconcileRun();
    Artisan::call('kitsune:media-prune');
    $said = Artisan::output();

    expect($output)->toContain('Re-run with --force')
        ->and($output)->not->toContain('disk reaches the disk its file belongs on')
        ->and($said)->not->toContain('moves no row off it')
        ->and($exit)->toBe(1);

    [$exit] = reconcileRun(['--force' => true]);

    expect($exit)->toBe(0)
        ->and(reconcileNamed($id))->toBe('media')
        ->and(hash_file('sha256', $root.'/'.$path))->toBe($this->checksum);
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
    // Read-only, it is sent to a person, not to that --force (review of slice 5c).
    expect(reconcileNamed($id))->toBe('nested')
        ->and(reconcileRun(['--entry' => [(string) $id]])[1])->toContain("1 of 1 media row's disk reaches the disk its file belongs on")
        ->and(hash_file('sha256', $root.'/'.$path))->toBe($this->checksum)
        ->and(reconcileLine($output, $id))->toContain('→ failed')
        ->and($output)->not->toContain('run kitsune:media-prune --force first')
        ->and($output)->toContain('[nested]\'s media directory nests with [public]\'s: kitsune:media-prune never lists its orphans')
        ->and($exit)->toBe(1);
});

/*
 * ...and one whose directory at the path is a symlink to the public disk's own is one entry, though the two media
 * directories nest: settle's move-off skips it and only the row moves, so the check sends it to --force, not to a person
 * (review of slice 5c).
 */
it('sends a nested disk whose directory at the path is the public disk\'s own to --force', function (): void {
    $public = $this->disks['public']->root();
    $root = $public.'/media/sub';
    mkdir($root.'/media', 0777, true);
    config(['filesystems.disks.nested' => ['driver' => 'local', 'root' => $root]]);
    RefusingDisk::install('nested', $root);
    [$id, $path] = reconcileFile('nested', ['public' => RECONCILE_PNG]);
    $org = explode('/', $path)[1];
    symlink($public.'/media/'.$org, $root.'/media/'.$org);

    [$exit, $output] = reconcileRun();

    expect($output)->toContain('Re-run with --force')
        ->and($output)->not->toContain('disk reaches the disk its file belongs on')
        ->and($exit)->toBe(1);

    [$exit, $forced] = reconcileRun(['--force' => true]);

    expect($exit)->toBe(0)
        ->and(reconcileLine($forced, $id))->toContain('→ settled')
        ->and(reconcileNamed($id))->toBe('public')
        ->and(hash_file('sha256', $public.'/'.$path))->toBe($this->checksum);
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
    // A disk named with what the console reads as a style is named as it is: stripped, the warning told the operator to
    // prune a disk that does not exist (review of slice 5c).
    it('names a styled disk the last rows leave as it is', function (): void {
        reconcileDisk('le<info>gacy');
        [$id] = reconcileFile('le<info>gacy', ['le<info>gacy' => RECONCILE_PNG], visibility: 'private');

        expect(reconcileRun()[1])->toContain('1 row names [le<info>gacy], which kitsune:media-prune sweeps')
            ->and(reconcileRun(['--entry' => [(string) $id]])[1])->toContain('The one row naming [le<info>gacy] is among these entries: kitsune:media-prune sweeps [le<info>gacy]')
            ->and(reconcileRun(['--force' => true, '--entry' => [(string) $id]])[1])->toContain('The one row naming [le<info>gacy] is among these entries: this run moves the row off it');
    });

    // ...and so is the disk its media directory nests with, which prune never lists it through.
    it('names a styled disk the last rows leave, and the one it nests with, as they are', function (): void {
        $vault = reconcileDisk('v<info>ault');
        config(['kitsune.media.disks.private' => 'v<info>ault']);
        $inner = $vault->root().'/media/x';
        mkdir($inner, 0777, true);
        config(['filesystems.disks.le<info>gacy' => ['driver' => 'local', 'root' => $inner]]);
        $this->disks['le<info>gacy'] = RefusingDisk::install('le<info>gacy', $inner);
        reconcileFile('le<info>gacy', ['le<info>gacy' => RECONCILE_PNG], visibility: 'private');

        expect(reconcileRun()[1])->toContain('[le<info>gacy]\'s media directory nests with [v<info>ault]\'s');
    });

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

    // T177: counted by the name as written — MySQL and MariaDB group names under the column's collation, which folded
    // `LEGACY` into `legacy`, and the warning named the spelling no configuration has, or counted the other's rows (review
    // of slice 5c). Told apart on those two lanes; SQLite and PostgreSQL group by bytes.
    it('counts the rows naming a disk by the name as written, whatever the collation folds', function (string $first): void {
        $write = static function (string $disk) use (&$ids): void {
            [$id] = reconcileFile('local', ['local' => RECONCILE_PNG], visibility: 'private');
            DB::table('media_files')->where('entry_id', $id)->update(['disk' => $disk]);
            $ids[$disk] = $id;
        };
        $ids = [];
        reconcileDisk('legacy');
        reconcileDisk('local');

        foreach ($first === 'LEGACY' ? ['LEGACY', 'legacy'] : ['legacy', 'LEGACY'] as $disk) {
            $write($disk);
        }

        [, $listed] = reconcileRun();
        [, $entry] = reconcileRun(['--entry' => [(string) $ids['legacy']]]);

        expect($listed)->toContain('1 row names [legacy], which kitsune:media-prune sweeps for orphans only while a row names it')
            ->and($entry)->toContain('The one row naming [legacy] is among these entries')
            ->and($listed)->not->toContain('[LEGACY], which');
    })->with(['the unconfigured spelling first' => 'LEGACY', 'the configured spelling first' => 'legacy']);

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

/*
 * T129-T133, T148-T150, T158, T159, T166, T167, T172, T175, T179, T180 (T147 sits beside T79, in 'a forced run'). A copy that cannot be
 * read fails the check as it fails --force (Adam, decision 12, 2026-09-26): read-only opens each copy of an extra row but
 * one on a disk nesting with the target, and the copy an `elsewhere` row names beside the file where it belongs; a forced
 * row is asked again as it now is; and prune's --force fails on an extra row's copy it lists — until it can be read, when
 * all three pass and prune removes it. The `elsewhere` copy decision 6 left is reconcile's alone (T147), and so is a copy
 * its disk's listing leaves out; a copy on a read-through disk is opened by none, and kept for a hand to compare with the
 * target's copy. The check and prune advise taking it off only where custody would have removed it — a served copy of a
 * file kept off the web, or one on the disk its row names — and only once the file is where it belongs, checked against
 * the recorded checksum; nowhere else, since there it may be the only copy that matches (T167, and the tests after T179).
 */
describe('a copy that cannot be read', function (): void {
    /*
     * ...and where the private disk is core's own under another name — its directory, through a prefix — the file is one
     * copy, not two: counted twice, every private row read `extra`, an unreadable copy failed the check while a forced
     * prune passed and removed nothing, and every private orphan was listed twice, the second removal failing as unheld
     * (review of slice 5c). The check, a forced reconcile and a forced prune agree, and the orphan goes once.
     */
    it('counts a file once where the private disk is core\'s own under another name', function (): void {
        require_once dirname(__DIR__).'/Fixtures/PathPrefixedAdapter.php';
        $root = $this->disks[MediaDisks::PRIVATE]->root();
        config([
            'filesystems.disks.hp' => ['driver' => 'local', 'root' => dirname($root), 'prefix' => basename($root)],
            'kitsune.media.disks.private' => 'hp',
        ]);
        [$id, $path] = reconcileFile(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => RECONCILE_PNG], trashed: true);
        DB::table('media_files')->where('entry_id', $id)->update(['disk' => 'hp']);
        $orphan = dirname($path).'/orphan.png';
        file_put_contents($root.'/'.$orphan, 'bytes');
        $this->disks[MediaDisks::PRIVATE]->unreadable = [$path];

        foreach ([[], ['--force' => true]] as $options) {
            [$exit, $output] = reconcileRun($options);

            expect(reconcileLine($output, $id))->toBeNull()
                ->and($exit)->toBe(0);
        }

        $exit = Artisan::call('kitsune:media-prune');
        $output = Artisan::output();

        expect(substr_count($output, $orphan))->toBe(1)
            ->and($output)->not->toContain('Extra copies')
            ->and($output)->toContain('Not scanning ['.MediaDisks::PRIVATE.']: it is [hp], the private disk, under another name')
            ->and($exit)->toBe(0);

        $exit = Artisan::call('kitsune:media-prune', ['--force' => true]);

        expect(Artisan::output())->toContain('Removed 1 of 1 orphaned or leftover file and 0 of 0 extra copies.')
            ->and(is_file($root.'/'.$orphan))->toBeFalse()
            ->and(is_file($root.'/'.$path))->toBeTrue()
            ->and($exit)->toBe(0);
    });

    // T129: an unreadable copy on core's private disk beside a file settled on the host's private disk that matches.
    it('fails read-only, forced reconcile and forced prune alike, and none of them once it can be read', function (): void {
        reconcileDisk('host-private');
        config(['kitsune.media.disks.private' => 'host-private']);
        [$id, $path] = reconcileFile('host-private', ['host-private' => RECONCILE_PNG, MediaDisks::PRIVATE => RECONCILE_PNG], trashed: true);
        $this->disks[MediaDisks::PRIVATE]->unreadable = [$path];

        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toStartWith('unreadable ')
            ->and(reconcileLine($output, $id))->toEndWith('held by host-private, '.MediaDisks::PRIVATE.' — ['.MediaDisks::PRIVATE.'] cannot be read')
            ->and($output)->toContain('1 of 1 media row holds a copy that cannot be read, on the disk its line above names')
            ->and($output)->toContain('make it readable')
            ->and($output)->not->toContain('Re-run with --force')
            ->and($output)->not->toContain('disagree')
            ->and($exit)->toBe(1);

        // Settle reads the target alone, which matches, and finds nothing to do: asked again, the copy is still there.
        [$exit, $output] = reconcileRun(['--force' => true]);

        expect(reconcileLine($output, $id))->toContain('→ kept: ['.MediaDisks::PRIVATE.'] cannot be read, and every --force run leaves it and fails until it can be read')
            ->and(reconcileHeld($path, ['host-private', MediaDisks::PRIVATE]))->toBe(['host-private' => $this->checksum, MediaDisks::PRIVATE => $this->checksum])
            ->and($exit)->toBe(1);

        $pruned = Artisan::call('kitsune:media-prune', ['--force' => true]);

        expect(Artisan::output())->toContain('Could not remove ['.MediaDisks::PRIVATE.':'.$path.']')
            ->and($pruned)->toBe(1);

        $this->disks[MediaDisks::PRIVATE]->unreadable = [];

        [$exit, $output] = reconcileRun();
        expect(reconcileLine($output, $id))->toStartWith('extra ')->and($exit)->toBe(0);

        [$exit] = reconcileRun(['--force' => true]);
        expect($exit)->toBe(0);

        $pruned = Artisan::call('kitsune:media-prune', ['--force' => true]);
        expect($pruned)->toBe(0)
            ->and(reconcileHeld($path, ['host-private', MediaDisks::PRIVATE]))->toBe(['host-private' => $this->checksum, MediaDisks::PRIVATE => null]);
    });

    // T130: a served copy under a public target — the served disk is asked, and opened, like any other.
    it('fails on an unreadable copy on a served disk beside a live public file', function (): void {
        [$id, $path] = reconcileFile('public', ['public' => RECONCILE_PNG, 'old-cdn' => RECONCILE_PNG]);
        $this->disks['old-cdn']->unreadable = [$path];

        RefusingDisk::forgetLog();
        [$exit, $output] = reconcileRun();

        // Each copy opened twice — listed, then asked again as a finding — the target's too (review of slice 5c).
        expect(reconcileLine($output, $id))->toStartWith('unreadable ')
            ->and(reconcileLine($output, $id))->toEndWith('— [old-cdn] cannot be read')
            ->and(collect(RefusingDisk::$log)->where('event', 'readStream')->map(static fn (array $e): string => $e['disk'])->values()->all())->toBe(['public', 'old-cdn', 'public', 'old-cdn'])
            ->and($exit)->toBe(1);

        [$exit, $output] = reconcileRun(['--force' => true]);

        expect(reconcileLine($output, $id))->toContain('→ kept: [old-cdn] cannot be read')
            ->and(reconcileHeld($path))->toBe(['public' => $this->checksum, MediaDisks::PRIVATE => null, 'old-cdn' => $this->checksum])
            ->and($exit)->toBe(1);

        $pruned = Artisan::call('kitsune:media-prune', ['--force' => true]);

        expect(Artisan::output())->toContain('Could not remove [old-cdn:'.$path.']')
            ->and($pruned)->toBe(1);

        $this->disks['old-cdn']->unreadable = [];
        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toStartWith('extra ')->and($exit)->toBe(0);
    });

    // T172: the copy where the row belongs is opened too — one that cannot be read beside a readable extra fails the check,
    // as it fails every forced reconcile, whose keeper reads it first, and a forced prune that removes the extra copy
    // (review of slice 5c).
    it('fails on an unreadable copy where the row belongs, beside a readable extra', function (): void {
        [$id, $path] = reconcileFile('public', ['public' => RECONCILE_PNG, 'old-cdn' => RECONCILE_PNG]);
        $this->disks['public']->unreadable = [$path];

        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toStartWith('unreadable ')
            ->and(reconcileLine($output, $id))->toEndWith('held by public, old-cdn — [public] cannot be read')
            ->and($exit)->toBe(1);

        [$exit, $output] = reconcileRun(['--force' => true]);

        expect(reconcileLine($output, $id))->toContain('→ failed: Refusing to go on with ['.$path.'] on the [public] disk: it exists and cannot be read')
            ->and($exit)->toBe(1);

        $pruned = Artisan::call('kitsune:media-prune', ['--force' => true]);

        expect(Artisan::output())->toContain('Could not remove [old-cdn:'.$path.']')
            ->and($pruned)->toBe(1)
            ->and(reconcileHeld($path))->toBe(['public' => $this->checksum, MediaDisks::PRIVATE => null, 'old-cdn' => $this->checksum]);
    });

    // T131: whatever a forced row was listed as, it is asked again as it now is — here after its cleanup, and after settle
    // repointed it (review of slice 5c: asking only rows listed `unreadable` let both pass --force).
    it('fails a forced row that settles past a copy it did not read', function (string $listed): void {
        if ($listed === 'private copy') {
            [$id, $path] = reconcileFile('public', ['public' => RECONCILE_PNG, MediaDisks::PRIVATE => RECONCILE_PNG, 'old-cdn' => RECONCILE_PNG]);
            $this->disks['old-cdn']->unreadable = [$path];
            $unreadable = 'old-cdn';
            $named = 'public';
        } else {
            reconcileDisk('host-private');
            config(['kitsune.media.disks.private' => 'host-private']);
            [$id, $path] = reconcileFile('public', ['public' => RECONCILE_PNG, 'host-private' => RECONCILE_PNG, MediaDisks::PRIVATE => RECONCILE_PNG], trashed: true);
            $this->disks[MediaDisks::PRIVATE]->unreadable = [$path];
            $unreadable = MediaDisks::PRIVATE;
            $named = 'host-private';
        }

        [$exit, $output] = reconcileRun(['--force' => true]);

        expect(reconcileLine($output, $id))->toStartWith($listed.' ')
            ->and(reconcileLine($output, $id))->toContain('→ settled, then kept: ['.$unreadable.'] cannot be read')
            ->and(reconcileNamed($id))->toBe($named)
            ->and($exit)->toBe(1);

        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toStartWith('unreadable ')->and($exit)->toBe(1);
    })->with(['a private copy, cleaned up' => 'private copy', 'exposed, withdrawn and repointed' => 'exposed']);

    // T132: a disk nesting with the target is not opened by the check, and prune keeps its copy for a hand. --force reads it
    // only when the target's copy does not match the checksum and no copy hashed before it does, and then fails on it while
    // it cannot be read or is the copy kept, while the check passes (T166: the residue ADR-042 records).
    it('does not open a copy on a disk whose media directory nests with the target', function (): void {
        $host = reconcileDisk('host', ['url' => 'https://host.example.test']);
        mkdir($host->root().'/media/site', 0777, true);
        config(['filesystems.disks.site' => ['driver' => 'local', 'root' => $host->root().'/media/site', 'url' => 'https://site.example.test']]);
        RefusingDisk::install('site', $host->root().'/media/site');
        config(['kitsune.media.disks.public' => 'site']);
        [$id, $path] = reconcileFile('site', ['site' => RECONCILE_PNG, 'host' => RECONCILE_PNG]);
        $host->unreadable = [$path];

        [$exit, $output] = reconcileRun(['--entry' => [(string) $id]]);

        expect(reconcileLine($output, $id))->toStartWith('extra ')
            ->and(reconcileLine($output, $id))->toContain('held by site, host')
            ->and(array_filter(RefusingDisk::$log, static fn (array $e): bool => $e['event'] === 'readStream' && $e['disk'] === 'host'))->toBe([])
            ->and($exit)->toBe(0);

        [$exit] = reconcileRun(['--force' => true, '--entry' => [(string) $id]]);

        expect($exit)->toBe(0)
            ->and(is_file($host->root().'/'.$path))->toBeTrue();
    });

    // T166: that residue — a stale target's copy, and the nesting disk's that cannot be read, or that can and is the first
    // to match the checksum, which settle refuses to copy onto the target from (review of slice 5c).
    it('fails a forced run on a nesting disk\'s copy when the target\'s does not match, while the check passes', function (bool $readable): void {
        $host = reconcileDisk('host', ['url' => 'https://host.example.test']);
        mkdir($host->root().'/media/site', 0777, true);
        config(['filesystems.disks.site' => ['driver' => 'local', 'root' => $host->root().'/media/site', 'url' => 'https://site.example.test']]);
        RefusingDisk::install('site', $host->root().'/media/site');
        config(['kitsune.media.disks.public' => 'site']);
        [$id, $path] = reconcileFile('site', ['site' => 'stale', 'host' => RECONCILE_PNG]);
        $host->unreadable = $readable ? [] : [$path];

        [$exit, $output] = reconcileRun(['--entry' => [(string) $id]]);

        expect(reconcileLine($output, $id))->toStartWith('extra ')
            ->and(reconcileLine($output, $id))->toContain('held by site, host')
            ->and($exit)->toBe(0);

        foreach ([1, 2] as $run) {
            [$exit, $output] = reconcileRun(['--force' => true, '--entry' => [(string) $id]]);

            expect(reconcileLine($output, $id))->toContain($readable ? '→ failed: Refusing: the [site] and [host] disks' : '→ failed: Refusing to go on with ['.$path.'] on the [host] disk: it exists and cannot be read')
                ->and(reconcileNamed($id))->toBe('site')
                ->and(file_get_contents($host->root().'/media/site/'.$path))->toBe('stale')
                ->and(is_file($host->root().'/'.$path))->toBeTrue()
                ->and($exit)->toBe(1);
        }

        $pruned = Artisan::call('kitsune:media-prune', ['--force' => true]);

        expect(Artisan::output())->toContain('kept: its media directory nests with [site]\'s')
            ->and($pruned)->toBe(0);
    })->with(['its copy unreadable' => false, 'its copy readable, and the first to match' => true]);

    /*
     * T167. A copy on a read-through disk is never opened by the check, as one on a nesting disk is not: custody removes
     * no copy through one — its delete removes the file from both halves, only one of which was read — so prune keeps
     * it for a hand, and no step reads it to remove it; opened, a copy only its fallback holds would be copied into its
     * primary (review of slice 5c; ADR-042 decision 5, open for Adam). All three pass, and the copy stays.
     */
    it('opens no copy on a read-through disk, which prune keeps for a hand', function (string $built, string $half): void {
        $primary = reconcileDisk('s-primary');
        $fallback = reconcileDisk('s-fallback');

        if ($built === 'configured') {
            config(['filesystems.disks.s' => ['driver' => 'read-through', 'primary' => 's-primary', 'fallback' => 's-fallback', 'url' => 'https://s.example.test']]);
        } else {
            Storage::extend('mirror', static fn ($app) => $app['filesystem']->createReadThroughDriver(['driver' => 'read-through', 'primary' => 's-primary', 'fallback' => 's-fallback'], 's'));
            config(['filesystems.disks.s' => ['driver' => 'mirror', 'url' => 'https://s.example.test']]);
        }

        [$id, $path] = reconcileFile('public', ['public' => RECONCILE_PNG, $half => RECONCILE_PNG]);
        $this->disks[$half]->unreadable = [$path];
        RefusingDisk::forgetLog();

        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toStartWith('extra ')
            ->and(reconcileLine($output, $id))->toEndWith('held by public, s')
            ->and(array_filter(RefusingDisk::$log, static fn (array $e): bool => in_array($e['disk'], ['s-primary', 's-fallback'], true) && ($e['event'] === 'readStream' || $e['bytes'])))->toBe([])
            ->and(is_file($primary->root().'/'.$path))->toBe($half === 's-primary')
            ->and($exit)->toBe(0);

        [$exit] = reconcileRun(['--force' => true]);
        $pruned = Artisan::call('kitsune:media-prune', ['--force' => true]);

        // Listed, on its primary, and kept; on its fallback, which its listing leaves out, not listed at all.
        expect($exit)->toBe(0)
            ->and(str_contains(Artisan::output(), 'kept: [s] is a read-through disk, which custody removes no copy through'))->toBe($half === 's-primary')
            ->and($pruned)->toBe(0)
            ->and(reconcileHeld($path, [$half]))->toBe([$half => $this->checksum]);
    })->with(['configured as one' => 'configured', 'built by a driver of the host\'s own' => 'extended'])
        ->with(['on its primary' => 's-primary', 'on its fallback' => 's-fallback']);

    // T175: two served read-through disks over stores of their own are two places — compared as one, prune never scanned
    // the second — so prune lists the copy on each, and keeps both (review of slice 5c).
    it('lists the copy on each of two served read-through disks over stores of their own', function (): void {
        foreach (['s1', 's2'] as $name) {
            reconcileDisk("{$name}-primary");
            reconcileDisk("{$name}-fallback");
            config(["filesystems.disks.{$name}" => ['driver' => 'read-through', 'primary' => "{$name}-primary", 'fallback' => "{$name}-fallback", 'url' => "https://{$name}.example.test"]]);
        }

        [$id, $path] = reconcileFile('public', ['public' => RECONCILE_PNG, 's1-primary' => RECONCILE_PNG, 's2-primary' => RECONCILE_PNG]);

        [$exit, $output] = reconcileRun();
        $pruned = Artisan::call('kitsune:media-prune', ['--force' => true]);
        $listed = Artisan::output();

        expect(reconcileLine($output, $id))->toEndWith('held by public, s1, s2')
            ->and($exit)->toBe(0)
            ->and($listed)->not->toContain('Not scanning')
            ->and($listed)->toContain('kept: [s1] is a read-through disk')
            ->and($listed)->toContain('kept: [s2] is a read-through disk')
            ->and($pruned)->toBe(0);
    });

    // T179: a read-through public disk is refused, as an unsafe configuration is — read-only says so, and every forced
    // reconcile refuses, as a forced prune does before it removes anything (review of slice 5c; ADR-042 decision 5, open
    // for Adam).
    it('refuses a forced run, and says so read-only, while the public disk is a read-through one', function (string $built): void {
        reconcileDisk('rt-primary');
        reconcileDisk('rt-fallback');

        if ($built === 'configured') {
            config(['filesystems.disks.rt' => ['driver' => 'read-through', 'primary' => 'rt-primary', 'fallback' => 'rt-fallback', 'url' => 'https://rt.example.test']]);
        } else {
            Storage::extend('mirror', static fn ($app) => $app['filesystem']->createReadThroughDriver(['driver' => 'read-through', 'primary' => 'rt-primary', 'fallback' => 'rt-fallback'], 'rt'));
            config(['filesystems.disks.rt' => ['driver' => 'mirror', 'url' => 'https://rt.example.test']]);
        }

        reconcileFile('rt', ['rt-primary' => RECONCILE_PNG]);
        config(['kitsune.media.disks.public' => 'rt']);
        $refusal = 'Refusing: kitsune.media.disks.public names [rt], a read-through disk';

        [, $listed] = reconcileRun();
        [$exit, $forced] = reconcileRun(['--force' => true]);

        expect($listed)->toContain($refusal)
            ->and($listed)->toContain('kitsune:media-reconcile --force refuses until this is fixed')
            ->and($forced)->toContain($refusal)
            ->and($exit)->toBe(1);
    })->with(['configured as one' => 'configured', 'built by a driver of the host\'s own' => 'extended']);

    /*
     * ...and one a forced run's keeper reaches — the target's copy stale, the read-through disk's the only one that
     * matches — fails every forced reconcile, readable or not, while the check, which opens no copy there, and a forced
     * prune, which keeps it, pass; and neither tells anyone to remove that copy (the residue ADR-042 records).
     */
    it('fails every forced run when the only matching copy is on a read-through disk, and advises removing none', function (): void {
        $primary = reconcileDisk('s-primary');
        $fallback = reconcileDisk('s-fallback');
        config(['filesystems.disks.s' => ['driver' => 'read-through', 'primary' => 's-primary', 'fallback' => 's-fallback', 'url' => 'https://s.example.test']]);
        [$id, $path] = reconcileFile('public', ['public' => 'stale', 's-primary' => RECONCILE_PNG]);

        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toStartWith('extra ')->and($exit)->toBe(0);

        foreach ([1, 2] as $run) {
            RefusingDisk::forgetLog();
            [$exit, $output] = reconcileRun(['--force' => true]);

            expect(reconcileLine($output, $id))->toContain('on the [s] disk: it is a read-through disk')
                ->and(reconcileLine($output, $id))->toContain('It may be the only copy, or the only one that matches the checksum')
                ->and(array_filter(RefusingDisk::$log, static fn (array $e): bool => in_array($e['disk'], ['s-primary', 's-fallback'], true) && $e['bytes']))->toBe([])
                ->and($exit)->toBe(1);
        }

        $pruned = Artisan::call('kitsune:media-prune', ['--force' => true]);

        expect(Artisan::output())->toContain('kept: [s] is a read-through disk, which custody removes no copy through — compare it with [public]\'s copy by hand: it may be the only one that matches')
            ->and($pruned)->toBe(0)
            ->and(file_get_contents($primary->root().'/'.$path))->toBe(RECONCILE_PNG)
            ->and(is_file($fallback->root().'/'.$path))->toBeFalse();
    });

    // ...and a file whose only copy is on one is sent not to --force, which neither reads nor copies from it, but to a hand:
    // every forced run fails on it, and prune keeps it with the same word (the residue ADR-042 records).
    it('sends a file held only on a read-through disk to a hand, not to --force', function (): void {
        reconcileDisk('s-primary');
        reconcileDisk('s-fallback');
        config(['filesystems.disks.s' => ['driver' => 'read-through', 'primary' => 's-primary', 'fallback' => 's-fallback', 'url' => 'https://s.example.test']]);
        [$id, $path] = reconcileFile('public', ['s-primary' => RECONCILE_PNG]);

        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toEndWith('held by s')
            ->and($output)->toContain("1 of 1 media row's file is held on a read-through disk, which custody neither reads, copies from nor removes a copy through")
            ->and($output)->not->toContain('Re-run with --force')
            ->and($exit)->toBe(1);

        foreach ([1, 2] as $run) {
            RefusingDisk::forgetLog();
            [$exit, $output] = reconcileRun(['--force' => true]);

            expect(reconcileLine($output, $id))->toContain('on the [s] disk: it is a read-through disk')
                ->and(array_filter(RefusingDisk::$log, static fn (array $e): bool => in_array($e['disk'], ['s-primary', 's-fallback'], true) && $e['bytes']))->toBe([])
                ->and(reconcileHeld($path, ['public']))->toBe(['public' => null])
                ->and($exit)->toBe(1);
        }

        $pruned = Artisan::call('kitsune:media-prune', ['--force' => true]);

        expect(Artisan::output())->toContain('kept: [s] is a read-through disk, which custody neither reads nor removes a copy through, and [public] does not list the file')
            ->and(Artisan::output())->not->toContain('kept: the disk its row names does not hold the file')
            ->and($pruned)->toBe(0);
    });

    // ...and so is one whose row names another disk: prune says reconcile moves its row only from a readable copy on
    // another disk it asks, not that it moves the row first, and nothing of the target it never asked (review of 5c).
    it('sends a file held only on a read-through disk to a hand when its row names another disk', function (bool $trashed): void {
        reconcileDisk('s-primary');
        reconcileDisk('s-fallback');
        config(['filesystems.disks.s' => ['driver' => 'read-through', 'primary' => 's-primary', 'fallback' => 's-fallback', 'url' => 'https://s.example.test']]);
        [$id] = reconcileFile($trashed ? 'public' : MediaDisks::PRIVATE, ['s-primary' => RECONCILE_PNG], trashed: $trashed);

        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toEndWith('held by s')
            ->and($output)->toContain("1 of 1 media row's file is held on a read-through disk")
            ->and($output)->not->toContain('Re-run with --force')
            ->and($exit)->toBe(1);

        foreach ([1, 2] as $run) {
            [$exit, $output] = reconcileRun(['--force' => true]);

            expect(reconcileLine($output, $id))->toContain('on the [s] disk: it is a read-through disk')->and($exit)->toBe(1);
        }

        $pruned = Artisan::call('kitsune:media-prune', ['--force' => true]);
        $said = Artisan::output();

        // Trashed, the file belongs off the web, and the served copy must come off too: every forced run fails on it
        // until it does (review of slice 5c).
        expect($said)->toContain($trashed
            ? 'it belongs on ['.MediaDisks::PRIVATE.']: kept: [s] is a read-through disk the web serves, which custody neither reads nor removes a copy through, and the file belongs off the web — every kitsune:media-reconcile --force of its row fails on this copy until it is gone: copy the file to ['.MediaDisks::PRIVATE.'] by hand if it is not there'
            : 'it belongs on [public]: kept: [s] is a read-through disk, which custody neither reads nor removes a copy through — kitsune:media-reconcile --force moves its row only from a readable copy on another disk it asks; otherwise copy it to [public] by hand')
            ->and($said)->not->toContain('moves its row first')
            ->and($said)->not->toContain('does not list the file')
            ->and($pruned)->toBe(0);
    })->with(['live, its row naming the private disk' => false, 'trashed, its row naming the public one' => true]);

    // ...and a row naming a read-through disk that holds its copy is sent to a hand, not to --force, which cannot move it
    // off: custody neither reads nor removes a copy through that disk (review of slice 5c). Every forced run fails on it,
    // writing nothing there, until that copy is removed by hand (the residue ADR-042 records).
    it('fails every forced run on a row naming a read-through disk that holds its copy, until the copy is removed', function (): void {
        reconcileDisk('xp');
        $fallback = reconcileDisk('xf');
        config(['filesystems.disks.x' => ['driver' => 'read-through', 'primary' => 'xp', 'fallback' => 'xf']]);
        [$id, $path] = reconcileFile('x', [MediaDisks::PRIVATE => RECONCILE_PNG, 'xf' => RECONCILE_PNG], trashed: true);

        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toStartWith('elsewhere ')
            ->and($output)->toContain("1 of 1 media row's file is held on a read-through disk")
            ->and($output)->not->toContain('Re-run with --force')
            ->and($exit)->toBe(1);

        foreach ([1, 2] as $run) {
            [$exit, $output] = reconcileRun(['--force' => true]);

            expect(reconcileLine($output, $id))->toContain('on the [x] disk: it is a read-through disk')
                ->and(reconcileNamed($id))->toBe('x')
                ->and($exit)->toBe(1);
        }

        // Prune keeps the row's other copy with the same word, not "reconcile moves its row first" (review of slice 5c).
        foreach ([[], ['--force' => true]] as $options) {
            $pruned = Artisan::call('kitsune:media-prune', $options);
            $said = Artisan::output();

            expect($said)->toContain('its row names [x], it belongs on ['.MediaDisks::PRIVATE.']: kept: its row names [x], a read-through disk, which custody neither reads nor removes a copy through — while [x] holds the file, kitsune:media-reconcile --force cannot move its row')
                ->and($said)->not->toContain('moves its row first')
                ->and($pruned)->toBe(0)
                ->and(reconcileHeld($path, [MediaDisks::PRIVATE]))->toBe([MediaDisks::PRIVATE => $this->checksum]);
        }

        unlink($fallback->root().'/'.$path);
        [$exit] = reconcileRun(['--force' => true]);

        expect($exit)->toBe(0)->and(reconcileNamed($id))->toBe(MediaDisks::PRIVATE);
    });

    // ...and so is a live public one whose row names such a disk, beside the copy where it belongs: every forced run fails
    // on the move-off, and prune says so rather than send the row to reconcile (review of slice 5c).
    it('sends a public file whose row names a read-through disk holding it to a hand, and prune says so', function (string $built): void {
        $primary = reconcileDisk('xp');
        reconcileDisk('xf');

        if ($built === 'configured') {
            config(['filesystems.disks.x' => ['driver' => 'read-through', 'primary' => 'xp', 'fallback' => 'xf']]);
        } else {
            // Only the instance says it reads through, as reconcile and custody ask it (review of slice 5c).
            Storage::extend('mirror', static fn ($app) => $app['filesystem']->createReadThroughDriver(['driver' => 'read-through', 'primary' => 'xp', 'fallback' => 'xf'], 'x'));
            config(['filesystems.disks.x' => ['driver' => 'mirror']]);
        }

        [$id, $path] = reconcileFile('x', ['public' => RECONCILE_PNG, 'xp' => RECONCILE_PNG]);

        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toStartWith('awaiting publication ')
            ->and($output)->toContain("1 of 1 media row's file is held on a read-through disk")
            ->and($output)->not->toContain('Re-run with --force')
            ->and($exit)->toBe(1);

        [$exit, $output] = reconcileRun(['--force' => true]);

        expect(reconcileLine($output, $id))->toContain('on the [x] disk: it is a read-through disk')->and($exit)->toBe(1);

        $pruned = Artisan::call('kitsune:media-prune', ['--force' => true]);
        $said = Artisan::output();

        expect($said)->toContain('its row names [x], it belongs on [public]: kept: its row names [x], a read-through disk')
            ->and($said)->not->toContain('moves its row first')
            ->and($pruned)->toBe(0)
            ->and(is_file($primary->root().'/'.$path))->toBeTrue()
            ->and(reconcileHeld($path, ['public']))->toBe(['public' => $this->checksum]);
    })->with(['configured as one' => 'configured', 'built by a driver of the host\'s own' => 'extended']);

    // ...and a public file awaiting publication, readable on the disk its row names and held on a served read-through disk
    // too, goes to --force: the keeper takes the named disk's copy and prune keeps the read-through one, so neither
    // read-through kind claims it — not held only on read-through disks, nor a served copy of a file kept off the web
    // (review of slice 5c).
    it('sends a public file on the private disk and a served read-through disk to --force, which settles it', function (): void {
        $primary = reconcileDisk('s-primary');
        reconcileDisk('s-fallback');
        config(['filesystems.disks.s' => ['driver' => 'read-through', 'primary' => 's-primary', 'fallback' => 's-fallback', 'url' => 'https://s.example.test']]);
        [$id, $path] = reconcileFile(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => RECONCILE_PNG, 's-primary' => RECONCILE_PNG]);

        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toStartWith('awaiting publication ')
            ->and(reconcileLine($output, $id))->toEndWith('held by '.MediaDisks::PRIVATE.', s')
            ->and($output)->toContain('Re-run with --force')
            ->and($output)->not->toContain('held on a read-through disk')
            ->and($exit)->toBe(1);

        [$exit] = reconcileRun(['--force' => true]);

        expect($exit)->toBe(0)
            ->and(reconcileNamed($id))->toBe('public')
            ->and(reconcileHeld($path, ['public', MediaDisks::PRIVATE]))->toBe(['public' => $this->checksum, MediaDisks::PRIVATE => null])
            ->and(is_file($primary->root().'/'.$path))->toBeTrue()
            ->and(reconcileRun()[0])->toBe(0);
    });

    /*
     * ...and a row naming a read-through disk whose half is the public disk — what a host that pointed
     * `kitsune.media.disks.public` at such a disk's primary, as the refusal above says to, leaves its rows naming — goes
     * to neither --force nor "take its copy off": the move-off refuses a disk that reaches the one the file belongs on,
     * on every run, and a copy taken off it could be the file where it belongs (review of slice 5c). The check and prune
     * say to copy the file there if it is not, and correct the row.
     */
    it('sends a row naming a read-through disk over the public disk to a person, and advises removing nothing through it', function (array $copies, string $built): void {
        $old = reconcileDisk('old');

        // A local alias of the public disk's root, which one host-built variant reads through.
        config(['filesystems.disks.pubalias' => ['driver' => 'local', 'root' => $this->disks['public']->root()]]);
        $halves = match ($built) {
            'fallback' => ['old', 'public'],
            'inline' => [['driver' => 'local', 'root' => $this->disks['public']->root()], 'old'],
            'alias' => ['pubalias', 'old'],
            default => ['public', 'old'],
        };

        if ($built === 'configured') {
            config(['filesystems.disks.x' => ['driver' => 'read-through', 'primary' => 'public', 'fallback' => 'old']]);
        } else {
            // Its halves are the instance's: its configuration names none — the public disk as either half, written
            // inline, or reached as one place under another name (review of slice 5c).
            Storage::extend('mirror', static fn ($app) => $app['filesystem']->createReadThroughDriver(['driver' => 'read-through', 'primary' => $halves[0], 'fallback' => $halves[1]], 'x'));
            config(['filesystems.disks.x' => ['driver' => 'mirror']]);
        }

        [$id, $path] = reconcileFile('x', $copies);
        $held = reconcileHeld($path, ['public', 'old']);
        // A file only the public disk holds, which a disk reading through it would list again as its own.
        $stray = 'media/'.$this->org->id.'/stray.png';
        Storage::disk('public')->put($stray, RECONCILE_PNG);

        [$exit, $output] = reconcileRun();

        expect($output)->toContain("1 of 1 media row's disk reaches the disk its file belongs on, or cannot be told apart from it")
            ->and($output)->not->toContain('Re-run with --force')
            ->and($output)->not->toContain('held on a read-through disk')
            // Held by the disk the row names when its other half alone holds the file: never missing (review of 5c).
            ->and(reconcileLine($output, $id))->toStartWith('awaiting publication ')
            ->and(reconcileLine($output, $id))->toEndWith(isset($copies['public']) ? 'held by public' : 'held by x')
            // Prune does not sweep it, whatever names it, and --force moves no row off it (review of slice 5c).
            ->and($output)->not->toContain('sweeps for orphans')
            ->and($exit)->toBe(1);

        foreach ([1, 2] as $run) {
            [$exit, $output] = reconcileRun(['--force' => true]);

            // The move-off's own word, however the disk is built: never one bucket's, for two local disks (review of 5c).
            expect(reconcileLine($output, $id))->toContain('→ failed: ')
                ->and(str_contains((string) reconcileLine($output, $id), "the [public] and [x] disks cannot be told apart — a read-through disk reaches the other's files through a half"))->toBe(isset($copies['public']))
                ->and($output)->not->toContain('this run moves the row off it')
                ->and(reconcileNamed($id))->toBe('x')
                ->and(reconcileHeld($path, ['public', 'old']))->toBe($held)
                ->and($exit)->toBe(1);
        }

        foreach ([[], ['--force' => true]] as $options) {
            $pruned = Artisan::call('kitsune:media-prune', $options);
            $said = Artisan::output();

            expect($said)->toContain('Not scanning [x]: it is, or cannot be told apart from, [public]')
                ->and($said)->not->toContain('copy off')
                ->and($said)->not->toContain('moves its row first')
                ->and($said)->not->toContain('[x]  '.$stray)
                ->and($said)->toContain('[public]  '.$stray)
                ->and(str_contains($said, 'kept: its row names [x], which reaches [public]\'s files or cannot be told apart from it'))->toBe(isset($copies['public']))
                ->and($pruned)->toBe(0)
                ->and(reconcileHeld($path, ['public', 'old']))->toBe($held)
                ->and(is_dir($old->root()))->toBeTrue();
        }
    })->with([
        'its file on the public disk' => [['public' => RECONCILE_PNG]],
        'on the public disk and its other half' => [['public' => RECONCILE_PNG, 'old' => RECONCILE_PNG]],
        'on its other half alone' => [['old' => RECONCILE_PNG]],
    ])->with([
        'configured as one' => 'configured',
        'built by a driver of the host\'s own' => 'extended',
        'built so, the public disk its fallback' => 'fallback',
        'built so, a half written inline at the public disk\'s root' => 'inline',
        'built so, a half the public disk under another name' => 'alias',
    ]);

    // ...and one whose file is held nowhere is missing, as --force says: settle stops at the keeper, before the move-off
    // that would refuse it (review of slice 5c).
    it('lists a row naming a disk over the public disk as missing while no disk holds its file', function (): void {
        reconcileDisk('old');
        config(['filesystems.disks.x' => ['driver' => 'read-through', 'primary' => 'public', 'fallback' => 'old']]);
        [$id] = reconcileFile('x', []);

        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toStartWith('missing ')
            ->and($output)->not->toContain('disk reaches the disk its file belongs on')
            ->and($exit)->toBe(1);

        [$exit, $output] = reconcileRun(['--force' => true]);

        expect(reconcileLine($output, $id))->toContain('→ missing: ')
            ->and(reconcileNamed($id))->toBe('x')
            ->and($exit)->toBe(1);
    });

    /*
     * ...and one naming a disk that reaches the private disk: the kind comes before the read-through kinds and the
     * unreadable one, so a trashed file on a read-through disk over the private disk is not sent to "take it off through
     * the disk each half is" — its half is the private disk, which holds the only copy — and one on an alias of the
     * private disk that cannot be read is not sent to "make it readable", after which --force still refuses it (review
     * of slice 5c).
     */
    it('sends a trashed row naming a read-through disk over the private disk to a person, and advises removing nothing through it', function (): void {
        reconcileDisk('old');
        config(['filesystems.disks.x' => ['driver' => 'read-through', 'primary' => MediaDisks::PRIVATE, 'fallback' => 'old']]);
        [$id, $path] = reconcileFile('x', [MediaDisks::PRIVATE => RECONCILE_PNG], trashed: true);

        [$exit, $output] = reconcileRun();

        expect($output)->toContain("1 of 1 media row's disk reaches the disk its file belongs on, or cannot be told apart from it")
            ->and($output)->not->toContain('held on a read-through disk')
            ->and($output)->not->toContain('each half is')
            ->and($output)->not->toContain('Re-run with --force')
            ->and($exit)->toBe(1);

        foreach ([1, 2] as $run) {
            [$exit, $output] = reconcileRun(['--force' => true]);

            expect(reconcileLine($output, $id))->toContain('→ failed: ')
                ->and(reconcileNamed($id))->toBe('x')
                ->and(reconcileHeld($path, [MediaDisks::PRIVATE, 'old']))->toBe([MediaDisks::PRIVATE => $this->checksum, 'old' => null])
                ->and($exit)->toBe(1);
        }

        $pruned = Artisan::call('kitsune:media-prune');

        expect(Artisan::output())->toContain('its row names [x], which reaches ['.MediaDisks::PRIVATE.']\'s files or cannot be told apart from it')
            ->and($pruned)->toBe(0);
    });

    it('sends a row naming an alias of the private disk to a person even when its copy there cannot be read', function (): void {
        foreach (['vault' => 'e', 'alias' => 'f'] as $name => $endpoint) {
            $root = sys_get_temp_dir().'/kitsune-reconcile-'.$name.'-'.bin2hex(random_bytes(4));
            mkdir($root, 0777, true);
            $this->roots[] = $root;
            $config = ['driver' => 's3', 'bucket' => 'b', 'endpoint' => $endpoint, 'prefix' => 'p'];
            config(["filesystems.disks.{$name}" => $config]);
            $adapter = new RefusingDisk($root, $name);
            Storage::set($name, new FilesystemAdapter(new Filesystem($adapter), $adapter, $config));
            $this->disks[$name] = $adapter;
        }

        config(['kitsune.media.disks.private' => 'vault']);
        [$id, $path] = reconcileFile('alias', ['vault' => RECONCILE_PNG, 'alias' => RECONCILE_PNG], trashed: true);
        $this->disks['alias']->unreadable = [$path];

        [$exit, $output] = reconcileRun();

        expect($output)->toContain("1 of 1 media row's disk reaches the disk its file belongs on, or cannot be told apart from it")
            ->and($output)->not->toContain('make it readable')
            ->and($exit)->toBe(1);

        // Readable now, and still refused: the move-off refuses the overlap whatever that copy's state.
        $this->disks['alias']->unreadable = [];
        [$exit, $output] = reconcileRun(['--force' => true]);

        expect(reconcileLine($output, $id))->toContain('→ failed: ')
            ->and(reconcileNamed($id))->toBe('alias')
            ->and($exit)->toBe(1);
    });

    // ...and a row naming the disk its file belongs on is never one: --force settles it, whatever that disk's directory
    // holds (review of slice 5c).
    it('never counts a row naming the disk its file belongs on as one naming a disk that reaches it', function (bool $store): void {
        if ($store) {
            // An object store is compared by its configuration alone, and is one place with itself.
            $root = sys_get_temp_dir().'/kitsune-reconcile-pubstore-'.bin2hex(random_bytes(4));
            mkdir($root, 0777, true);
            $this->roots[] = $root;
            $config = ['driver' => 's3', 'bucket' => 'b', 'endpoint' => 'e', 'url' => 'https://pubstore.example.test'];
            config(['filesystems.disks.pubstore' => $config, 'kitsune.media.disks.public' => 'pubstore']);
            $adapter = new RefusingDisk($root, 'pubstore');
            Storage::set('pubstore', new FilesystemAdapter(new Filesystem($adapter), $adapter, $config));
            $this->disks['pubstore'] = $adapter;
        }

        $public = $store ? 'pubstore' : 'public';
        [$id, $path] = reconcileFile($public, [MediaDisks::PRIVATE => RECONCILE_PNG]);
        exec('rm -rf '.escapeshellarg($this->disks[$public]->root().'/media'));

        expect(is_dir(dirname($this->disks[$public]->root().'/'.$path)))->toBeFalse();

        [$exit, $output] = reconcileRun();
        $pruned = Artisan::call('kitsune:media-prune');

        expect($output)->toContain('Re-run with --force')
            ->and($output)->not->toContain('disk reaches the disk its file belongs on')
            ->and(Artisan::output())->not->toContain('which reaches ['.$public.']')
            ->and($exit)->toBe(1);

        [$exit] = reconcileRun(['--force' => true]);

        expect($exit)->toBe(0)->and(reconcileNamed($id))->toBe($public);
    })->with(['a local disk, its directory not made yet' => false, 'an object store' => true]);

    // ...and the check that a disk reaches the one a file belongs on builds nothing: a read-through disk whose other half
    // has no root yet — at any depth, however it is written — is not built to be compared, read-only (review of slice
    // 5c, twice).
    it('builds no half of a read-through disk it compares with the public disk, read-only', function (string $shape): void {
        $oldRoot = sys_get_temp_dir().'/kitsune-reconcile-absent-'.bin2hex(random_bytes(4));
        $this->roots[] = $oldRoot;
        reconcileDisk('old2');
        $old = ['driver' => 'local', 'root' => $oldRoot];
        config([
            'filesystems.disks.old' => $old,
            'filesystems.disks.y' => ['driver' => 'read-through', 'primary' => 'old', 'fallback' => 'old2'],
            'filesystems.disks.x' => ['driver' => 'read-through', ...match ($shape) {
                'named primary' => ['primary' => 'old', 'fallback' => 'public'],
                'inline primary' => ['primary' => $old, 'fallback' => 'public'],
                'inline fallback' => ['primary' => 'public', 'fallback' => $old],
                'nested' => ['primary' => 'public', 'fallback' => 'y'],
                'nested inline' => ['primary' => 'public', 'fallback' => ['driver' => 'read-through', 'primary' => 'old', 'fallback' => 'old2']],
                'scoped' => ['primary' => 'public', 'fallback' => ['driver' => 'scoped', 'disk' => 'old', 'prefix' => 'p']],
                default => ['primary' => 'public', 'fallback' => 'old'],
            }],
        ]);
        reconcileFile('x', ['public' => RECONCILE_PNG]);

        [, $output] = reconcileRun();

        expect($output)->toContain("1 of 1 media row's disk reaches the disk its file belongs on")
            ->and(is_dir($oldRoot))->toBeFalse();

        Artisan::call('kitsune:media-prune');

        expect(is_dir($oldRoot))->toBeFalse();
    })->with(['a named fallback' => 'named fallback', 'a named primary' => 'named primary', 'an inline primary' => 'inline primary', 'an inline fallback' => 'inline fallback', 'a half that reads through to it' => 'nested', 'an inline half that reads through to it' => 'nested inline', 'an inline half scoped over it' => 'scoped']);

    // ...and one built so over a half that cannot be told apart from the public disk — one bucket through another
    // endpoint — is one too (review of slice 5c).
    it('sends a row naming a host-built read-through disk over another endpoint of the public store to a person', function (): void {
        $root = sys_get_temp_dir().'/kitsune-reconcile-pubstore-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        $this->roots[] = $root;

        foreach (['pubstore' => 'e', 'alias' => 'f'] as $name => $endpoint) {
            $config = ['driver' => 's3', 'bucket' => 'b', 'endpoint' => $endpoint, 'prefix' => 'p', ...($name === 'pubstore' ? ['url' => 'https://pubstore.example.test'] : [])];
            config(["filesystems.disks.{$name}" => $config]);
            $adapter = new RefusingDisk($root, $name);
            Storage::set($name, new FilesystemAdapter(new Filesystem($adapter), $adapter, $config));
            $this->disks[$name] = $adapter;
        }

        reconcileDisk('old');
        config(['kitsune.media.disks.public' => 'pubstore']);
        Storage::extend('mirror', static fn ($app) => $app['filesystem']->createReadThroughDriver(['driver' => 'read-through', 'primary' => 'alias', 'fallback' => 'old'], 'x'));
        config(['filesystems.disks.x' => ['driver' => 'mirror']]);
        [$id] = reconcileFile('x', ['pubstore' => RECONCILE_PNG]);

        [$exit, $output] = reconcileRun();

        expect($output)->toContain("1 of 1 media row's disk reaches the disk its file belongs on, or cannot be told apart from it")
            ->and($output)->not->toContain('each half is')
            ->and($exit)->toBe(1);

        $pruned = Artisan::call('kitsune:media-prune');
        $said = Artisan::output();

        expect($said)->toContain('Not scanning [x]: it is, or cannot be told apart from, [pubstore]')
            ->and($said)->not->toContain('copy off')
            ->and($pruned)->toBe(0)
            ->and(reconcileNamed($id))->toBe('x');
    });

    // ...and one whose other half cannot say whether it holds the file, held nowhere else, is unknown, as --force finds
    // it: never missing and sent to --force (Adam, decision 9; review of slice 5c).
    it('lists a row naming a disk over the public disk as unknown when its other half cannot answer', function (): void {
        $old = reconcileDisk('old');
        config(['filesystems.disks.x' => ['driver' => 'read-through', 'primary' => 'public', 'fallback' => 'old']]);
        [$id, $path] = reconcileFile('x', []);
        $old->unknown = [$path];

        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toStartWith('unknown ')
            ->and(reconcileLine($output, $id))->toContain('whether it exists cannot be told')
            ->and($output)->not->toContain('Re-run with --force')
            ->and($output)->toContain('could not be asked about')
            ->and($exit)->toBe(1);

        [$exit, $output] = reconcileRun(['--force' => true]);

        expect(reconcileLine($output, $id))->toContain('→ failed: ')->and($exit)->toBe(1);
    });

    /*
     * ...and one whose only copy is on a served disk the survey skips as the public disk under another name is asked
     * there, as every --force's keeper asks it: a disk that cannot say is `unknown`; a read-through one that holds it is
     * sent to a person, as one held only on read-through disks is; and one that cannot be told apart from the public
     * disk — one store through two endpoints — is sent to a person too, since settle copies from none onto it. Each was
     * `missing` and sent to a --force that failed it (review of slice 5c).
     */
    it('asks a served disk it skipped as the public disk when nothing else holds the file', function (string $case): void {
        if ($case === 'unknown') {
            // Its other half cannot be built: a local disk with no root.
            config([
                'filesystems.disks.rt-rootless' => ['driver' => 'local'],
                'filesystems.disks.rt' => ['driver' => 'read-through', 'primary' => 'public', 'fallback' => 'rt-rootless', 'url' => 'https://rt.example.test'],
            ]);
            [$id] = reconcileFile('public', []);
        } elseif ($case === 'read-through') {
            reconcileDisk('p1');
            config(['filesystems.disks.d' => ['driver' => 'read-through', 'primary' => 'p1', 'fallback' => 'public', 'url' => 'https://d.example.test']]);
            [$id, $path] = reconcileFile('public', ['p1' => RECONCILE_PNG]);
        } else {
            $root = sys_get_temp_dir().'/kitsune-reconcile-store-'.bin2hex(random_bytes(4));
            mkdir($root, 0777, true);
            $this->roots[] = $root;

            foreach (['pubs' => 'e', 'alias' => 'f'] as $name => $endpoint) {
                $config = ['driver' => 's3', 'bucket' => 'b', 'endpoint' => $endpoint, 'prefix' => 'site', 'url' => "https://{$name}.example.test"];
                config(["filesystems.disks.{$name}" => $config]);
                $adapter = new RefusingDisk($root.'/'.$name, $name);
                Storage::set($name, new FilesystemAdapter(new Filesystem($adapter), $adapter, $config));
                $this->disks[$name] = $adapter;
            }

            [$id, $path] = reconcileFile('public', ['alias' => RECONCILE_PNG]);
            DB::table('media_files')->where('entry_id', $id)->update(['disk' => 'pubs']);
            config(['kitsune.media.disks.public' => 'pubs']);
            $this->disks['alias']->unreadable = $case === 'coinciding, unreadable' ? [$path] : [];
        }

        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->not->toContain('held by no disk custody asks')
            ->and($output)->not->toContain('Re-run with --force')
            ->and($output)->toContain(match ($case) {
                'unknown' => 'could not be asked about',
                'read-through' => 'held on a read-through disk',
                default => 'held only on a disk that cannot be told apart from the one it belongs on',
            })
            ->and($exit)->toBe(1);

        foreach ([1, 2] as $run) {
            [$exit, $output] = reconcileRun(['--force' => true]);

            expect(reconcileLine($output, $id))->toContain('→ failed: ')->and($exit)->toBe(1);
        }

        if ($case === 'read-through') {
            expect(is_file($this->disks['p1']->root().'/'.$path))->toBeTrue();
        }
    })->with(['a served one that cannot say' => 'unknown', 'a served read-through one' => 'read-through', 'one store through two endpoints' => 'coinciding', 'one store through two endpoints, unreadable' => 'coinciding, unreadable']);

    /*
     * ...and one that cannot say whether it holds the file, whatever else holds it: every --force's keeper asks each disk
     * it asks before it hashes anything, so the row is `unknown` read-only too — the check passed it, or sent it to a
     * --force that failed on that disk every run (Adam, decision 9; review of slice 5c).
     */
    it('lists a row unknown when a disk it skipped as the public disk cannot answer, whatever else holds the file', function (string $case): void {
        if ($case === 'read-through') {
            config([
                'filesystems.disks.rt-rootless' => ['driver' => 'local'],
                'filesystems.disks.rt' => ['driver' => 'read-through', 'primary' => 'public', 'fallback' => 'rt-rootless', 'url' => 'https://rt.example.test'],
            ]);
            [$id] = reconcileFile('public', ['public' => RECONCILE_PNG, 'old-cdn' => RECONCILE_PNG]);
        } else {
            $root = sys_get_temp_dir().'/kitsune-reconcile-store-'.bin2hex(random_bytes(4));
            mkdir($root, 0777, true);
            $this->roots[] = $root;

            foreach (['pubs' => 'e', 'alias' => 'f'] as $name => $endpoint) {
                $config = ['driver' => 's3', 'bucket' => 'b', 'endpoint' => $endpoint, 'prefix' => 'site', 'url' => "https://{$name}.example.test"];
                config(["filesystems.disks.{$name}" => $config]);
                $adapter = new RefusingDisk($root.'/'.$name, $name);
                Storage::set($name, new FilesystemAdapter(new Filesystem($adapter), $adapter, $config));
                $this->disks[$name] = $adapter;
            }

            [$id, $path] = reconcileFile('public', ['old-cdn' => RECONCILE_PNG]);

            if ($case === 'extra') {
                Storage::disk('pubs')->put($path, RECONCILE_PNG);
            }

            DB::table('media_files')->where('entry_id', $id)->update(['disk' => 'pubs']);
            config(['kitsune.media.disks.public' => 'pubs']);
            $this->disks['alias']->unknown = [$path];
        }

        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toStartWith('unknown ')
            ->and($output)->toContain('could not be asked about')
            ->and($output)->not->toContain('Re-run with --force')
            ->and($exit)->toBe(1);

        [$exit, $output] = reconcileRun(['--force' => true]);

        expect(reconcileLine($output, $id))->toContain('→ failed: ')->and($exit)->toBe(1);
    })->with(['its target holding the file, and another disk' => 'extra', 'its target holding none' => 'absent', 'a served read-through disk over the public disk' => 'read-through']);

    /*
     * ...and a served read-through disk a half of which — at any depth — has a root that cannot be created: building it
     * fails, as every --force's does, after creating any other half's missing root, so it is not built, and the row is
     * `unknown` wherever it is asked — as a disk skipped as the public disk, held elsewhere or nowhere, or under a private
     * target (review of slice 5c).
     */
    it('lists a row unknown, building nothing, when a read-through disk it asks has a half that cannot be built', function (string $blocked, string $where): void {
        if ($blocked === 'sealed' && function_exists('posix_geteuid') && posix_geteuid() === 0) {
            test()->markTestSkipped('root ignores a directory\'s mode');
        }

        [$id] = match ($where) {
            'held elsewhere' => reconcileFile(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => RECONCILE_PNG]),
            'held nowhere else' => reconcileFile('public', []),
            default => reconcileFile(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => RECONCILE_PNG], trashed: true, visibility: 'private'),
        };
        $base = sys_get_temp_dir().'/kitsune-reconcile-blocked-'.bin2hex(random_bytes(4));
        mkdir($base, 0777, true);
        $this->roots[] = $base;
        $root = match ($blocked) {
            'sealed' => (static function () use ($base): string {
                mkdir($base.'/sealed', 0555);

                return $base.'/sealed/root';
            })(),
            'link' => (static function () use ($base): string {
                symlink($base.'/nowhere', $base.'/root');

                return $base.'/root';
            })(),
            default => (static function () use ($base): string {
                file_put_contents($base.'/root', 'a file');

                return $base.'/root';
            })(),
        };
        config([
            'filesystems.disks.rt-blocked' => ['driver' => 'local', 'root' => $root],
            // A half that is itself read-through: a missing root that can be created before the one that cannot.
            'filesystems.disks.rt-mixed' => ['driver' => 'read-through', 'primary' => ['driver' => 'local', 'root' => $base.'/creatable'], 'fallback' => 'rt-blocked'],
            'filesystems.disks.rt' => ['driver' => 'read-through', 'primary' => 'public', 'fallback' => $blocked === 'nested' ? 'rt-mixed' : 'rt-blocked', 'url' => 'https://rt.example.test'],
        ]);

        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toStartWith('unknown ')
            ->and(reconcileLine($output, $id))->toContain('the [rt] disk reads through to a local disk whose root cannot be created')
            ->and($output)->toContain('could not be asked about')
            ->and($output)->not->toContain('Re-run with --force')
            ->and(is_dir($root))->toBeFalse()
            ->and(is_dir($base.'/creatable'))->toBeFalse()
            ->and($exit)->toBe(1);

        [$exit, $output] = reconcileRun(['--force' => true]);

        expect(reconcileLine($output, $id))->toContain('→ failed')->and($exit)->toBe(1);
    })->with([
        'a file where its root should be' => 'file',
        'a dangling link as its root' => 'link',
        'a root in a directory it may not write into' => 'sealed',
        'a half of a half, beside one that can be created' => 'nested',
    ])->with(['held elsewhere', 'held nowhere else', 'under a private target']);

    // ...but only under a public target: under a private one, a disk that cannot be told from the public disk is asked
    // whatever else holds the file, so a trashed file it still serves is exposed, never settled (review of slice 5c).
    it('asks a served disk that cannot be told from the public disk under a private target, whatever else holds the file', function (string $case): void {
        if ($case === 'read-through') {
            reconcileDisk('fb');
            config(['filesystems.disks.served' => ['driver' => 'read-through', 'primary' => 'public', 'fallback' => 'fb', 'url' => 'https://served.example.test']]);
            [$id] = reconcileFile(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => RECONCILE_PNG, 'fb' => RECONCILE_PNG], trashed: true);
        } else {
            $root = sys_get_temp_dir().'/kitsune-reconcile-store-'.bin2hex(random_bytes(4));
            mkdir($root, 0777, true);
            $this->roots[] = $root;

            foreach (['pubs' => 'e', 'served' => 'f'] as $name => $endpoint) {
                $config = ['driver' => 's3', 'bucket' => 'b', 'endpoint' => $endpoint, 'prefix' => 'site', 'url' => "https://{$name}.example.test"];
                config(["filesystems.disks.{$name}" => $config]);
                $adapter = new RefusingDisk($root.'/'.$name, $name);
                Storage::set($name, new FilesystemAdapter(new Filesystem($adapter), $adapter, $config));
                $this->disks[$name] = $adapter;
            }

            [$id] = reconcileFile(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => RECONCILE_PNG, 'served' => RECONCILE_PNG], trashed: true);
            config(['kitsune.media.disks.public' => 'pubs']);
        }

        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toStartWith('exposed ')
            ->and(reconcileLine($output, $id))->toContain('held by '.MediaDisks::PRIVATE.', served')
            ->and($exit)->toBe(1);
    })->with(['a served read-through disk over the public disk' => 'read-through', 'the public store through another endpoint' => 'coinciding']);

    /*
     * ...and a file held only on a disk nesting with the public disk goes to a person too: settle copies onto the public
     * disk from none that nests with it, and every --force refused it (review of slice 5c).
     */
    it('sends a file held only on a disk nesting with the public disk to a person', function (string $layout): void {
        if ($layout === 'public inside') {
            $host = reconcileDisk('host', ['url' => 'https://host.example.test']);
            mkdir($host->root().'/media/site', 0777, true);
            config(['filesystems.disks.site' => ['driver' => 'local', 'root' => $host->root().'/media/site', 'url' => 'https://site.example.test']]);
            RefusingDisk::install('site', $host->root().'/media/site');
            config(['kitsune.media.disks.public' => 'site']);
            [$id, $path] = reconcileFile('site', ['host' => RECONCILE_PNG]);
            // Stored on the public disk, which is [site] here: only [host] is to hold it.
            Storage::disk('site')->delete($path);
            [$public, $holder, $holderRoot] = ['site', 'host', $host->root()];
        } else {
            $root = $this->disks['public']->root().'/media/sub';
            mkdir($root, 0777, true);
            config(['filesystems.disks.nested' => ['driver' => 'local', 'root' => $root, 'url' => 'https://nested.example.test']]);
            RefusingDisk::install('nested', $root);
            [$id, $path] = reconcileFile(MediaDisks::PRIVATE, ['nested' => RECONCILE_PNG]);
            [$public, $holder, $holderRoot] = ['public', 'nested', $root];
        }

        $named = reconcileNamed($id);
        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toContain('held by '.$holder)
            ->and($output)->toContain('or whose media directory nests with it')
            ->and($output)->not->toContain('Re-run with --force')
            ->and($exit)->toBe(1);

        foreach ([1, 2] as $run) {
            [$exit, $output] = reconcileRun(['--force' => true]);

            expect(reconcileLine($output, $id))->toContain('→ failed: Refusing: the ['.$public.'] and ['.$holder.'] disks')
                ->and(reconcileNamed($id))->toBe($named)
                ->and(is_file($holderRoot.'/'.$path))->toBeTrue()
                ->and($exit)->toBe(1);
        }
    })->with(['the public disk inside a served one' => 'public inside', 'a served disk inside the public one' => 'nested']);

    // ...but one a driver of the host's own builds over stores of its own, holding nothing, is no overlap: --force moves
    // the row off it, and the check says so (review of slice 5c).
    it('sends a row naming a host-built read-through disk over stores of its own, holding nothing, to --force', function (): void {
        reconcileDisk('xp');
        reconcileDisk('xf');
        Storage::extend('mirror', static fn ($app) => $app['filesystem']->createReadThroughDriver(['driver' => 'read-through', 'primary' => 'xp', 'fallback' => 'xf'], 'x'));
        config(['filesystems.disks.x' => ['driver' => 'mirror']]);
        [$id] = reconcileFile('x', [MediaDisks::PRIVATE => RECONCILE_PNG]);

        [$exit, $output] = reconcileRun();

        expect($output)->toContain('Re-run with --force')
            ->and($output)->not->toContain('disk reaches the disk its file belongs on')
            ->and($exit)->toBe(1);

        [$exit] = reconcileRun(['--force' => true]);

        expect($exit)->toBe(0)->and(reconcileNamed($id))->toBe('public');
    });

    /*
     * ...and prune says the same of a trashed file's copy on a served read-through disk, whichever disk holds the file
     * where it belongs: it must come off, and every forced run fails on it until it does — not "compare it by hand", or
     * "copy it over", after which the file stays served. One the web does not serve keeps the old word: settle does not
     * sweep it (review of slice 5c).
     */
    it('tells prune to take a trashed file\'s served read-through copy off, and only a served one', function (bool $served, array $copies): void {
        reconcileDisk('s-primary');
        reconcileDisk('s-fallback');
        config(['filesystems.disks.s' => ['driver' => 'read-through', 'primary' => 's-primary', 'fallback' => 's-fallback', ...($served ? ['url' => 'https://s.example.test'] : [])]]);
        [$id] = reconcileFile(MediaDisks::PRIVATE, $copies, trashed: true);

        // Prune scans a disk the web does not serve only while a row names it.
        if (! $served) {
            reconcileFile('s', ['s-primary' => RECONCILE_PNG]);
        }

        [$exit, $output] = reconcileRun(['--force' => true, '--entry' => [(string) $id]]);

        expect(str_contains((string) reconcileLine($output, $id), 'on the [s] disk: it is a read-through disk'))->toBe($served || ! isset($copies[MediaDisks::PRIVATE]));

        $pruned = Artisan::call('kitsune:media-prune', ['--force' => true]);
        $said = Artisan::output();

        expect(str_contains($said, 'kept: [s] is a read-through disk the web serves, which custody neither reads nor removes a copy through, and the file belongs off the web — every kitsune:media-reconcile --force of its row fails on this copy until it is gone'))->toBe($served)
            ->and(str_contains($said, 'kept: [s] is a read-through disk, which custody'))->toBe(! $served)
            ->and($pruned)->toBe(0);
    })->with([
        'served, its only copy there' => [true, ['s-primary' => RECONCILE_PNG]],
        'served, beside the one where it belongs' => [true, [MediaDisks::PRIVATE => RECONCILE_PNG, 's-primary' => RECONCILE_PNG]],
        'not served, beside the one where it belongs' => [false, [MediaDisks::PRIVATE => RECONCILE_PNG, 's-primary' => RECONCILE_PNG]],
    ]);

    // ...but not when the disk its row names is core's private disk, pointed elsewhere and reading through: the move-off
    // leaves a private disk's copy, so --force settles the row, and neither prune nor the check sends it to a hand
    // (review of slice 5c).
    it('sends a row naming core\'s private disk that reads through to --force, which settles it', function (string $over): void {
        $vault = reconcileDisk('vault');
        $stores = ['vault' => $vault, 'kp' => reconcileDisk('kp'), 'kf' => reconcileDisk('kf')];
        config([
            'kitsune.media.disks.private' => 'vault',
            'filesystems.disks.'.MediaDisks::PRIVATE => ['driver' => 'read-through', 'primary' => $over, 'fallback' => 'kf'],
        ]);
        Storage::forgetDisk(MediaDisks::PRIVATE);

        expect(Storage::disk(MediaDisks::PRIVATE))->toBeInstanceOf(ReadThroughFilesystem::class);

        [$id, $path] = reconcileFile(MediaDisks::PRIVATE, ['vault' => RECONCILE_PNG, ...($over === 'kp' ? ['kp' => RECONCILE_PNG] : [])], trashed: true);

        Artisan::call('kitsune:media-prune');
        $said = Artisan::output();

        // Not one whose disk reaches the private disk, even when its half is that disk: the move-off leaves a private
        // disk's copy, so --force settles the row (review of slice 5c).
        expect($said)->toContain('its row names ['.MediaDisks::PRIVATE.'], it belongs on [vault]: kept: kitsune:media-reconcile moves its row first')
            ->and($said)->not->toContain('cannot move its row')
            ->and($said)->not->toContain('which reaches [vault]');

        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toStartWith('elsewhere ')
            ->and($output)->toContain('Re-run with --force')
            ->and($output)->not->toContain('held on a read-through disk')
            ->and($output)->not->toContain('disk reaches the disk its file belongs on')
            ->and($exit)->toBe(1);

        [$exit] = reconcileRun(['--force' => true]);

        // The move-off never touches a private disk.
        expect($exit)->toBe(0)
            ->and(reconcileNamed($id))->toBe('vault')
            ->and(is_file($stores[$over]->root().'/'.$path))->toBeTrue()
            ->and(reconcileRun()[0])->toBe(0);
    })->with(['over stores of its own' => 'kp', 'over the configured private disk itself' => 'vault']);

    // ...and a trashed file still on a served read-through disk is sent to a hand, not to --force: taking it off the web
    // removes that copy, which custody does through no read-through disk (review of slice 5c). Every forced run fails on
    // it, and the copy stays.
    it('sends a trashed file on a served read-through disk to a hand, not to --force', function (): void {
        $primary = reconcileDisk('s-primary');
        reconcileDisk('s-fallback');
        config(['filesystems.disks.s' => ['driver' => 'read-through', 'primary' => 's-primary', 'fallback' => 's-fallback', 'url' => 'https://s.example.test']]);
        [$id, $path] = reconcileFile(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => RECONCILE_PNG, 's-primary' => RECONCILE_PNG], trashed: true);

        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toStartWith('exposed ')
            ->and($output)->toContain("1 of 1 media row's file is held on a read-through disk")
            ->and($output)->not->toContain('Re-run with --force')
            ->and($exit)->toBe(1);

        [$exit, $output] = reconcileRun(['--force' => true]);

        expect(reconcileLine($output, $id))->toContain('on the [s] disk: it is a read-through disk')
            ->and(is_file($primary->root().'/'.$path))->toBeTrue()
            ->and($exit)->toBe(1);
    });

    // ...and a row that could not be asked about is unknown, whatever a read-through disk asked before the failing one
    // answered: sent to a hand to copy a file that disk may not be the only holder of, it would be sent the wrong way
    // (review of slice 5c).
    it('counts a row that could not be asked about as unknown, after a read-through disk answered', function (): void {
        reconcileDisk('s-primary');
        reconcileDisk('s-fallback');
        config(['filesystems.disks.s' => ['driver' => 'read-through', 'primary' => 's-primary', 'fallback' => 's-fallback']]);
        [$id, $path] = reconcileFile('s', ['s-primary' => RECONCILE_PNG]);
        $this->disks['old-cdn']->unknown = [$path];

        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toStartWith('unknown ')
            ->and($output)->toContain('1 of 1 media row could not be asked about')
            ->and($output)->not->toContain('held on a read-through disk')
            ->and($exit)->toBe(1);
    });

    /*
     * ...and a pair whose halves name each other is never built — Laravel's builder recurses until memory runs out, and no
     * catch can turn that into an answer. With a url of its own it once read as served, so every row whose file is kept off
     * the web asked it; it serves nothing, is asked nothing, and a row naming it is unknown (review of slice 5c).
     */
    it('asks no read-through cycle, and lists a row naming one as unknown', function (bool $named): void {
        config([
            'filesystems.disks.loop-a' => ['driver' => 'read-through', 'primary' => 'loop-b', 'fallback' => 'public', 'url' => 'https://loop.example.test'],
            'filesystems.disks.loop-b' => ['driver' => 'read-through', 'primary' => 'loop-a', 'fallback' => 'public'],
        ]);
        [$id] = reconcileFile($named ? 'loop-a' : MediaDisks::PRIVATE, [MediaDisks::PRIVATE => RECONCILE_PNG], trashed: true);
        $limit = ini_get('memory_limit');
        // A missing guard fails the test, not by growing into a laptop's unlimited memory.
        ini_set('memory_limit', (string) (memory_get_usage(true) + 128 * 1024 * 1024));

        try {
            [$exit, $output] = reconcileRun();

            expect(str_contains($output, 'Asking [public], ['.MediaDisks::PRIVATE.'], [old-cdn], and the disk each row names.'))->toBeTrue()
                ->and(str_contains($output, 'Every media row names the disk its state says, and that disk holds its file.'))->toBe(! $named)
                ->and(reconcileLine($output, $id) === null)->toBe(! $named)
                ->and(str_starts_with((string) reconcileLine($output, $id), 'unknown '))->toBe($named)
                ->and(str_contains((string) reconcileLine($output, $id), 'the [loop-a] disk is a read-through one whose halves name each other'))->toBe($named)
                ->and($exit)->toBe($named ? 1 : 0);

            // Forced, custody asks the row's own disk whether it holds the file — and answers without building it.
            [$exit, $output] = reconcileRun(['--force' => true]);

            expect($exit)->toBe($named ? 1 : 0)
                ->and(reconcileHeld((string) DB::table('media_files')->where('entry_id', $id)->value('path'), [MediaDisks::PRIVATE]))->toBe([MediaDisks::PRIVATE => $this->checksum]);

            if ($named) {
                expect(reconcileLine($output, $id))->toStartWith('unknown ')
                    ->and(reconcileLine($output, $id))->toContain('→ failed: ')
                    ->and(reconcileLine($output, $id))->toContain('whether it exists cannot be told');
            }
        } finally {
            ini_set('memory_limit', $limit);
        }
    })->with(['a row that names another disk' => false, 'a row that names it' => true]);

    // ...and one whose half has no root, or one that does not exist, is compared without building the half: a read-only
    // run over a public row, which compares it with the public disk and asks it nothing, creates no such root, and stops
    // on none (review of slice 5c). Asked, it would be built, and its halves with it (ADR-042).
    it('creates no read-through half\'s root it compares, read-only', function (bool $rooted): void {
        $fallback = sys_get_temp_dir().'/kitsune-reconcile-rt-missing-'.bin2hex(random_bytes(4));
        config([
            'filesystems.disks.rt-fallback' => ['driver' => 'local', ...($rooted ? ['root' => $fallback] : [])],
            'filesystems.disks.rt' => ['driver' => 'read-through', 'primary' => 'public', 'fallback' => 'rt-fallback', 'url' => 'https://rt.example.test'],
        ]);
        reconcileFile('public', ['public' => RECONCILE_PNG]);

        [$exit] = reconcileRun();

        expect($exit)->toBe(0)->and(is_dir($fallback))->toBeFalse();
    })->with(['a root that does not exist' => true, 'no root at all' => false]);

    // T180: the target's own copy is opened whatever else the row holds, since every forced run's keeper reads it first —
    // but beside a copy prune keeps for a hand, a forced prune reads neither, and passes (the residue ADR-042 records).
    it('fails reconcile, not prune, on the target\'s unreadable copy beside a copy prune keeps', function (): void {
        $host = reconcileDisk('host', ['url' => 'https://host.example.test']);
        mkdir($host->root().'/media/site', 0777, true);
        config(['filesystems.disks.site' => ['driver' => 'local', 'root' => $host->root().'/media/site', 'url' => 'https://site.example.test']]);
        $site = RefusingDisk::install('site', $host->root().'/media/site');
        config(['kitsune.media.disks.public' => 'site']);
        [$id, $path] = reconcileFile('site', ['site' => RECONCILE_PNG, 'host' => RECONCILE_PNG]);
        $site->unreadable = [$path];

        [$exit, $output] = reconcileRun(['--entry' => [(string) $id]]);

        expect(reconcileLine($output, $id))->toEndWith('held by site, host — [site] cannot be read')->and($exit)->toBe(1);

        [$exit, $output] = reconcileRun(['--force' => true, '--entry' => [(string) $id]]);

        expect(reconcileLine($output, $id))->toContain('→ failed: Refusing to go on with ['.$path.'] on the [site] disk: it exists and cannot be read')
            ->and($exit)->toBe(1);

        $pruned = Artisan::call('kitsune:media-prune', ['--force' => true]);

        expect(Artisan::output())->toContain('kept: its media directory nests with [site]\'s')
            ->and($pruned)->toBe(0)
            ->and(is_file($host->root().'/'.$path))->toBeTrue();
    });

    /*
     * ...but a row naming core's private disk under that alias is `elsewhere`: the disk it names is asked, its copy opened —
     * one file, which cannot be read, fails the check, as every --force fails on it — and once readable, --force repoints
     * the row, after which the file is counted once. Skipped as the private disk, the check sent it to a --force that
     * failed without repointing it (review of slice 5c).
     */
    it('asks core\'s private disk where a row names it under the private disk\'s alias', function (): void {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            test()->markTestSkipped('root reads a file whatever its mode');
        }

        require_once dirname(__DIR__).'/Fixtures/PathPrefixedAdapter.php';
        $root = $this->disks[MediaDisks::PRIVATE]->root();
        config([
            'filesystems.disks.hp' => ['driver' => 'local', 'root' => dirname($root), 'prefix' => basename($root)],
            'kitsune.media.disks.private' => 'hp',
        ]);
        [$id, $path] = reconcileFile(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => RECONCILE_PNG], trashed: true);
        chmod($root.'/'.$path, 0000);

        try {
            [$exit, $output] = reconcileRun();

            expect(reconcileLine($output, $id))->toStartWith('elsewhere ')
                ->and(reconcileLine($output, $id))->toEndWith('— ['.MediaDisks::PRIVATE.'] cannot be read')
                ->and($output)->toContain('make it readable')
                ->and($output)->not->toContain('Re-run with --force')
                ->and($exit)->toBe(1);

            [$exit] = reconcileRun(['--force' => true]);

            expect(reconcileNamed($id))->toBe(MediaDisks::PRIVATE)->and($exit)->toBe(1);
        } finally {
            chmod($root.'/'.$path, 0644);
        }

        [$exit] = reconcileRun(['--force' => true]);

        expect(reconcileNamed($id))->toBe('hp')->and($exit)->toBe(0);

        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toBeNull()->and($exit)->toBe(0);
    });

    /*
     * ...and where the two nest — one's media directory inside the other's, which `onePlace()` also reads as one place —
     * they are two directories, and a copy on core's disk at the row's path is another file: asked and counted, never
     * skipped as the private disk under another name; and prune refuses to list while they nest (review of slice 5c).
     */
    it('asks core\'s private disk where its media directory nests with the private disk\'s', function (string $inside): void {
        require_once dirname(__DIR__).'/Fixtures/PathPrefixedAdapter.php';
        $root = $this->disks[MediaDisks::PRIVATE]->root();

        if ($inside === 'the private disk inside core\'s') {
            config([
                'filesystems.disks.hp' => ['driver' => 'local', 'root' => dirname($root), 'prefix' => basename($root).'/media/h'],
                'kitsune.media.disks.private' => 'hp',
            ]);
            [$id, $path] = reconcileFile('hp', ['hp' => RECONCILE_PNG, MediaDisks::PRIVATE => 'a stale copy'], trashed: true);
            [$private, $inner, $outer] = ['hp', 'hp', MediaDisks::PRIVATE];
        } else {
            $vault = reconcileDisk('vault');
            $core = $vault->root().'/media/k';
            mkdir($core, 0777, true);
            config(['kitsune.media.disks.private' => 'vault', 'filesystems.disks.'.MediaDisks::PRIVATE.'.root' => $core]);
            $this->disks[MediaDisks::PRIVATE] = RefusingDisk::install(MediaDisks::PRIVATE, $core);
            [$id, $path] = reconcileFile('vault', ['vault' => RECONCILE_PNG, MediaDisks::PRIVATE => 'a stale copy'], trashed: true);
            [$private, $inner, $outer] = ['vault', MediaDisks::PRIVATE, 'vault'];
        }

        expect(MediaDisks::onePlace(app('config'), $private, MediaDisks::PRIVATE))->toBeTrue()
            ->and(MediaDisks::nested(app('config'), $private, MediaDisks::PRIVATE))->toBeTrue();

        [, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toStartWith('extra ')
            ->and(reconcileLine($output, $id))->toEndWith('held by '.$private.', '.MediaDisks::PRIVATE);

        $exit = Artisan::call('kitsune:media-prune');

        expect(Artisan::output())->toContain('Refusing to list: the media directory of ['.$inner.'] is inside ['.$outer.']\'s')
            ->and(Artisan::output())->not->toContain('Not scanning ['.MediaDisks::PRIVATE.']')
            ->and($exit)->toBe(1);
    })->with(['the private disk inside core\'s', 'core\'s inside the private disk']);

    /*
     * ...and where the private disk cannot be told from core's — a read-through one reaching it through a half — core's is
     * asked as a disk of its own, as prune scans it (T154): its copy counted and opened, and an unreadable one failing the
     * check (review of slice 5c).
     */
    it('counts core\'s private disk apart where a read-through private disk reaches it through a half', function (bool $readable): void {
        $primary = sys_get_temp_dir().'/kitsune-reconcile-rt-primary-'.bin2hex(random_bytes(4));
        mkdir($primary, 0777, true);
        $this->roots[] = $primary;
        config([
            'filesystems.disks.primary' => ['driver' => 'local', 'root' => $primary],
            'filesystems.disks.private-rt' => ['driver' => 'read-through', 'primary' => 'primary', 'fallback' => MediaDisks::PRIVATE, 'copy' => false],
            'kitsune.media.disks.private' => 'private-rt',
        ]);
        [$id, $path] = reconcileFile(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => RECONCILE_PNG], trashed: true);
        DB::table('media_files')->where('entry_id', $id)->update(['disk' => 'private-rt']);

        expect(MediaDisks::onePlace(app('config'), 'private-rt', MediaDisks::PRIVATE))->toBeNull();

        if (! $readable) {
            $this->disks[MediaDisks::PRIVATE]->unreadable = [$path];
        }

        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toStartWith($readable ? 'extra ' : 'unreadable ')
            ->and(reconcileLine($output, $id))->toEndWith('held by private-rt, '.MediaDisks::PRIVATE.($readable ? '' : ' — ['.MediaDisks::PRIVATE.'] cannot be read'))
            ->and($exit)->toBe($readable ? 0 : 1);
    })->with(['readable' => true, 'unreadable' => false]);

    /*
     * ...and under a public target, a served disk that cannot be told apart from the public disk — one bucket through two
     * endpoints — is asked by the check only whether it holds the path, as every forced run's keeper asks it, and read by
     * neither the check nor prune; the keeper reads it only when the target's copy does not match, and then every forced
     * reconcile of a row it settles — here one listed `extra`, because cdn2 holds the file too — fails on it while it
     * cannot be read, or while it is the copy the keeper keeps; a readable copy there that does not match — the target's
     * own stale one, reached through the other endpoint — is passed over, and the row settled from another disk that
     * holds a match (the residue ADR-042 records; review of slice 5c).
     */
    it('fails only forced reconcile on a disk it cannot tell from a stale public disk, while the keeper would keep it', function (string $alias): void {
        foreach (['pubs' => 'e', 'alias' => 'f'] as $name => $endpoint) {
            $root = sys_get_temp_dir().'/kitsune-reconcile-'.$name.'-'.bin2hex(random_bytes(4));
            mkdir($root, 0777, true);
            $this->roots[] = $root;
            $config = ['driver' => 's3', 'bucket' => 'b', 'endpoint' => $endpoint, 'prefix' => 'site', 'url' => "https://{$name}.example.test"];
            config(["filesystems.disks.{$name}" => $config]);
            $adapter = new RefusingDisk($root, $name);
            Storage::set($name, new FilesystemAdapter(new Filesystem($adapter), $adapter, $config));
            $this->disks[$name] = $adapter;
        }

        // Configured after the disk it cannot tell from the public one, so the keeper reaches that one first — and holding
        // the file too, so the row is `extra`, and a forced reconcile settles it at all.
        reconcileDisk('cdn2', ['url' => 'https://cdn2.example.test']);
        [$id, $path] = reconcileFile('public', ['alias' => $alias === 'stale' ? 'stale' : RECONCILE_PNG, 'cdn2' => RECONCILE_PNG]);
        Storage::disk('pubs')->put($path, 'stale');
        DB::table('media_files')->where('entry_id', $id)->update(['disk' => 'pubs']);
        config(['kitsune.media.disks.public' => 'pubs']);
        $this->disks['alias']->unreadable = $alias === 'unreadable' ? [$path] : [];

        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toStartWith('extra ')->and($exit)->toBe(0);

        if ($alias === 'stale') {
            [$exit, $output] = reconcileRun(['--force' => true]);

            expect(reconcileLine($output, $id))->toEndWith('→ settled')->and($exit)->toBe(0);

            [$exit, $output] = reconcileRun(['--force' => true]);

            expect(reconcileLine($output, $id))->toEndWith('→ nothing to do under the lock')
                ->and($exit)->toBe(0)
                ->and(Storage::disk('pubs')->get($path))->toBe(RECONCILE_PNG);

            return;
        }

        foreach ([1, 2] as $run) {
            [$exit, $output] = reconcileRun(['--force' => true]);

            expect(reconcileLine($output, $id))->toContain($alias === 'matching' ? 'the [pubs] and [alias] disks name one bucket through two endpoints' : 'on the [alias] disk: it exists and cannot be read')
                ->and($exit)->toBe(1);
        }

        $pruned = Artisan::call('kitsune:media-prune', ['--force' => true]);

        expect(Artisan::output())->toContain('Not scanning [alias]')
            ->and($pruned)->toBe(0)
            ->and(Storage::disk('pubs')->get($path))->toBe('stale')
            ->and(reconcileHeld($path, ['cdn2']))->toBe(['cdn2' => $this->checksum]);
    })->with(['its copy readable, and the one that matches' => 'matching', 'its copy unreadable' => 'unreadable', 'its copy readable and stale' => 'stale']);

    // ...and a row whose file only the public disk and that disk hold is settled for the check, which does not hash the
    // target's copy: no command reads the copy there, and the check, a forced reconcile and a forced prune all pass, the
    // stale target and all (review of slice 5c).
    it('reads no copy of a row only the public disk and a disk it cannot tell from it hold', function (): void {
        foreach (['pubs' => 'e', 'alias' => 'f'] as $name => $endpoint) {
            $root = sys_get_temp_dir().'/kitsune-reconcile-'.$name.'-'.bin2hex(random_bytes(4));
            mkdir($root, 0777, true);
            $this->roots[] = $root;
            $config = ['driver' => 's3', 'bucket' => 'b', 'endpoint' => $endpoint, 'prefix' => 'site', 'url' => "https://{$name}.example.test"];
            config(["filesystems.disks.{$name}" => $config]);
            $adapter = new RefusingDisk($root, $name);
            Storage::set($name, new FilesystemAdapter(new Filesystem($adapter), $adapter, $config));
            $this->disks[$name] = $adapter;
        }

        [$id, $path] = reconcileFile('public', ['alias' => RECONCILE_PNG]);
        Storage::disk('pubs')->put($path, 'stale');
        DB::table('media_files')->where('entry_id', $id)->update(['disk' => 'pubs']);
        config(['kitsune.media.disks.public' => 'pubs']);
        $this->disks['alias']->unreadable = [$path];

        foreach ([[], ['--force' => true], ['--force' => true, '--entry' => [(string) $id]]] as $options) {
            [$exit, $output] = reconcileRun($options);

            expect(reconcileLine($output, $id))->toBeNull()->and($exit)->toBe(0);
        }

        expect(Artisan::call('kitsune:media-prune', ['--force' => true]))->toBe(0)
            ->and(Storage::disk('pubs')->get($path))->toBe('stale');
    });

    // T133: a row with one copy where it belongs is not settled and not opened — so prune alone meets an unreadable copy
    // of it, when it finds another at its path on a disk only other rows name (the residue ADR-042 records).
    it('leaves a lone unreadable copy to prune, which fails on it', function (): void {
        reconcileDisk('local');
        reconcileFile('local', ['local' => RECONCILE_PNG]);
        [$id, $path] = reconcileFile('public', ['public' => RECONCILE_PNG, 'local' => RECONCILE_PNG]);
        $this->disks['public']->unreadable = [$path];

        [, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toBeNull()
            ->and(array_filter(RefusingDisk::$log, static fn (array $e): bool => $e['event'] === 'readStream'))->toBe([]);

        // Asked of that row alone, the check passes: the other row, which makes prune scan [local], is a finding of its own.
        [$exit, $alone] = reconcileRun(['--entry' => [(string) $id]]);

        expect($exit)->toBe(0)
            ->and($alone)->toContain('Every media row names the disk its state says, and that disk holds its file.')
            ->and(array_filter(RefusingDisk::$log, static fn (array $e): bool => $e['event'] === 'readStream'))->toBe([]);

        $listed = Artisan::call('kitsune:media-prune');
        $listing = Artisan::output();
        $pruned = Artisan::call('kitsune:media-prune', ['--force' => true]);
        $output = Artisan::output();

        expect($listing)->toContain('removed, once asked again under the lock')
            ->and($listed)->toBe(0)
            ->and($output)->toContain('Could not remove [local:'.$path.']: Refusing to go on with ['.$path.'] on the [public] disk: it exists and cannot be read')
            ->and(is_file($this->disks['local']->root().'/'.$path))->toBeTrue()
            ->and($pruned)->toBe(1);
    });

    // The closing line counts an unreadable copy apart from a row --force can put right, and sends only the latter there.
    it('says what settles each kind of finding left', function (): void {
        [$absent] = reconcileFile('public', [MediaDisks::PRIVATE => RECONCILE_PNG]);
        [$id, $path] = reconcileFile('public', ['public' => RECONCILE_PNG, 'old-cdn' => RECONCILE_PNG]);
        $this->disks['old-cdn']->unreadable = [$path];

        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $absent))->toStartWith('absent ')
            ->and($output)->toContain('1 of 2 media rows disagrees with where its bytes are, and nothing was changed. Re-run with --force')
            ->and($output)->toContain('1 of 2 media rows holds a copy that cannot be read')
            ->and($exit)->toBe(1);
    });

    // T148: a disk that cannot say whether it holds the file is a configuration to repair, not a row for --force.
    it('sends a row on a disk that cannot be asked to its configuration, not to --force', function (): void {
        [$id] = reconcileFile('gone', ['public' => RECONCILE_PNG]);

        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toStartWith('unknown ')
            ->and($output)->toContain('1 of 1 media row could not be asked about')
            ->and($output)->toContain('make the disk reachable, or its configuration whole')
            ->and($output)->not->toContain('Re-run with --force')
            ->and($exit)->toBe(1);
    });

    // T149: a copy that opens and then cannot be read is unreadable too — the first byte is read, not only the file opened.
    it('fails on a copy that opens and cannot be read', function (): void {
        [$id, $path] = reconcileFile('public', ['public' => RECONCILE_PNG, 'old-cdn' => RECONCILE_PNG]);
        $this->disks['old-cdn']->failReads = [$path];

        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toStartWith('unreadable ')
            ->and(reconcileLine($output, $id))->toEndWith('— [old-cdn] cannot be read')
            ->and($exit)->toBe(1);

        // And every --force fails on it alike, as it does on a copy that cannot be opened (T129).
        [$exit, $output] = reconcileRun(['--force' => true]);

        expect(reconcileLine($output, $id))->toContain('→ kept: [old-cdn] cannot be read')->and($exit)->toBe(1);

        $pruned = Artisan::call('kitsune:media-prune', ['--force' => true]);

        expect(Artisan::output())->toContain('Could not remove [old-cdn:'.$path.']')
            ->and($pruned)->toBe(1)
            ->and(reconcileHeld($path, ['old-cdn']))->toBe(['old-cdn' => $this->checksum]);
    });

    /*
     * T150. Asked again after it settles, a forced row whose re-read fails is that row's failure and the run goes on; one
     * erased before the re-read keeps what settle did; and read-only's recheck reads a copy again, so one made readable
     * while the run lists is not a failure (review of slice 5c).
     */
    it('fails only the row whose second look cannot be taken, and goes on', function (): void {
        [$first] = reconcileFile('public', ['public' => RECONCILE_PNG, MediaDisks::PRIVATE => RECONCILE_PNG]);
        [$next] = reconcileFile(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => RECONCILE_PNG]);
        $thrown = false;
        DB::connection()->beforeExecuting(function (string $sql) use ($first, &$thrown): void {
            if (! $thrown && preg_match('/left join.*in \('.$first.'\).*limit 1/is', $sql) === 1) {
                $thrown = true;

                throw new RuntimeException('the database went away');
            }
        });

        [$exit, $output] = reconcileRun(['--force' => true]);

        expect($thrown)->toBeTrue()
            ->and(reconcileLine($output, $first))->toContain('→ settled, then failed: asked again, it could not be told — the database went away')
            ->and(reconcileLine($output, $next))->toEndWith('→ settled')
            ->and($exit)->toBe(1);
    });

    it('keeps what settle did for a row erased before its second look', function (): void {
        [$id] = reconcileFile('public', ['public' => RECONCILE_PNG, MediaDisks::PRIVATE => RECONCILE_PNG]);
        $erased = false;
        DB::connection()->beforeExecuting(function (string $sql) use ($id, &$erased): void {
            if (! $erased && preg_match('/left join.*in \('.$id.'\).*limit 1/is', $sql) === 1) {
                $erased = true;
                DB::table('media_files')->where('entry_id', $id)->delete();
            }
        });

        [$exit, $output] = reconcileRun(['--force' => true]);

        expect($erased)->toBeTrue()
            ->and(reconcileLine($output, $id))->toEndWith('→ settled')
            ->and($exit)->toBe(0);
    });

    it('does not fail on a copy made readable before the end of the run', function (): void {
        [$id, $path] = reconcileFile('public', ['public' => RECONCILE_PNG, 'old-cdn' => RECONCILE_PNG]);
        $disk = $this->disks['old-cdn'];
        $disk->unreadable = [$path];
        $cleared = false;
        // The recheck: rows read from media_files joined to their entries with no limit. PostgreSQL's read of the path
        // index, before the listing, is a join with no limit too — but not from media_files.
        DB::listen(function ($query) use ($disk, &$cleared): void {
            $sql = strtolower($query->sql);

            if (! $cleared && preg_match('/\bfrom\W+media_files\b/', $sql) === 1 && str_contains($sql, 'left join') && ! str_contains($sql, 'limit')) {
                $cleared = true;
                $disk->unreadable = [];
            }
        });

        [$exit, $output] = reconcileRun();

        expect($cleared)->toBeTrue()
            ->and(reconcileLine($output, $id))->toStartWith('unreadable ')
            ->and($output)->toContain('1 of 1 media row was a finding when listed, and none still is')
            ->and($output)->not->toContain('disagreed')
            ->and($exit)->toBe(0);
    });

    it('says a finding that cleared while another stayed was one, not that it disagreed', function (): void {
        [$absent] = reconcileFile('public', [MediaDisks::PRIVATE => RECONCILE_PNG]);
        [$id, $path] = reconcileFile('public', ['public' => RECONCILE_PNG, 'old-cdn' => RECONCILE_PNG]);
        $disk = $this->disks['old-cdn'];
        $disk->unreadable = [$path];
        DB::listen(function ($query) use ($disk): void {
            if (preg_match('/\bfrom\W+media_files\b/i', $query->sql) === 1 && str_contains(strtolower($query->sql), 'left join') && ! str_contains(strtolower($query->sql), 'limit')) {
                $disk->unreadable = [];
            }
        });

        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $absent))->toStartWith('absent ')
            ->and($output)->toContain('1 more was a finding when listed and no longer is.')
            ->and($exit)->toBe(1);
    });

    // A second look a disk cannot answer fails the row, as one the database cannot does (review of slice 5c).
    it('fails a forced row whose second look a disk cannot answer', function (): void {
        reconcileDisk('host-private');
        config(['kitsune.media.disks.private' => 'host-private']);
        [$id, $path] = reconcileFile('host-private', ['host-private' => RECONCILE_PNG, MediaDisks::PRIVATE => RECONCILE_PNG], trashed: true);
        $this->disks[MediaDisks::PRIVATE]->unreadable = [$path];
        $set = false;
        DB::connection()->beforeExecuting(function (string $sql) use ($id, $path, &$set): void {
            if (! $set && preg_match('/left join.*in \('.$id.'\).*limit 1/is', $sql) === 1) {
                $set = true;
                $this->disks[MediaDisks::PRIVATE]->unknown = [$path];
            }
        });

        [$exit, $output] = reconcileRun(['--force' => true]);

        expect($set)->toBeTrue()
            ->and(reconcileLine($output, $id))->toContain('→ failed: asked again, it could not be told')
            ->and(reconcileLine($output, $id))->toContain('whether it exists cannot be told')
            ->and($exit)->toBe(1);
    });

    // T158: an `elsewhere` row whose disk does not hold the file is not opened there — it is sent to --force, which
    // repoints it (review of slice 5c).
    it('sends an elsewhere row held only where it belongs to --force, opening nothing', function (): void {
        reconcileDisk('local');
        [$id] = reconcileFile('local', [MediaDisks::PRIVATE => RECONCILE_PNG], trashed: true);

        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toStartWith('elsewhere ')
            ->and(reconcileLine($output, $id))->toEndWith('held by '.MediaDisks::PRIVATE)
            ->and($output)->toContain('Re-run with --force')
            ->and($output)->not->toContain('make it readable')
            ->and(collect(RefusingDisk::$log)->where('event', 'readStream')->all())->toBe([])
            ->and($exit)->toBe(1);
    });

    // T159: a row naming a local disk with no root is one no --force can settle — custody cannot build the disk — so it is
    // unknown, and sent to its configuration (Adam, decision 9; review of slice 5c).
    // ...and so is one naming a local disk whose root cannot be created: a file where it should be, a dangling link, or a
    // directory it may not write into — custody fails to build it on every --force (review of slice 5c).
    it('sends a row naming a local disk with no root to its configuration', function (?string $root): void {
        if (is_string($root) && str_starts_with($root, 'blocked:')) {
            $base = sys_get_temp_dir().'/kitsune-reconcile-blocked-'.bin2hex(random_bytes(4));
            mkdir($base, 0777, true);
            $this->roots[] = $base;

            $root = match (substr($root, 8)) {
                'file' => (static function () use ($base): string {
                    file_put_contents($base.'/root', 'a file');

                    return $base.'/root';
                })(),
                'link' => (static function () use ($base): string {
                    symlink($base.'/nowhere', $base.'/root');

                    return $base.'/root';
                })(),
                // Writable, but not searchable: a directory cannot be made in it.
                'nosearch' => (static function () use ($base): string {
                    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                        test()->markTestSkipped('root ignores a directory\'s mode');
                    }

                    mkdir($base.'/sealed');
                    chmod($base.'/sealed', 0666);

                    return $base.'/sealed/root';
                })(),
                default => (static function () use ($base): string {
                    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                        test()->markTestSkipped('root ignores a directory\'s mode');
                    }

                    mkdir($base.'/sealed', 0555);

                    return $base.'/sealed/root';
                })(),
            };
        }

        config(['filesystems.disks.emptyroot' => ['driver' => 'local', 'root' => $root]]);
        [$id] = reconcileFile('emptyroot', [MediaDisks::PRIVATE => RECONCILE_PNG], trashed: true, visibility: 'private');

        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toStartWith('unknown ')
            ->and($output)->toContain('make the disk reachable, or its configuration whole')
            ->and($output)->not->toContain('Re-run with --force')
            ->and($exit)->toBe(1);

        [$exit, $output] = reconcileRun(['--force' => true]);

        expect(reconcileLine($output, $id))->toContain('→ failed')
            ->and(reconcileLine($output, $id))->toContain('cannot be told')
            ->and(reconcileNamed($id))->toBe('emptyroot')
            ->and($exit)->toBe(1);
    })->with(['no root' => [null], 'an empty root' => [''], 'a file where its root should be' => ['blocked:file'], 'a dangling link as its root' => ['blocked:link'], 'a root in a directory it may not write into' => ['blocked:sealed'], 'a root in a directory it may not search' => ['blocked:nosearch']]);

    // ...and so is one whose configured disk it does not name cannot be built: custody asks both configured disks
    // whatever the row names (review of slice 5c).
    it('sends a row to its configuration when a configured disk it does not name cannot be built', function (): void {
        [$id] = reconcileFile(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => RECONCILE_PNG]);
        // An object store as the public disk, so the unsafe-configuration check does not build the private disk first.
        $store = sys_get_temp_dir().'/kitsune-reconcile-pubstore-'.bin2hex(random_bytes(4));
        mkdir($store, 0777, true);
        $this->roots[] = $store;
        $config = ['driver' => 's3', 'bucket' => 'b', 'endpoint' => 'e', 'url' => 'https://pubstore.example.test'];
        $adapter = new RefusingDisk($store, 'pubstore');
        Storage::set('pubstore', new FilesystemAdapter(new Filesystem($adapter), $adapter, $config));
        $base = sys_get_temp_dir().'/kitsune-reconcile-blocked-'.bin2hex(random_bytes(4));
        mkdir($base, 0777, true);
        $this->roots[] = $base;
        file_put_contents($base.'/root', 'a file');
        config([
            'filesystems.disks.pubstore' => $config,
            'filesystems.disks.host-private' => ['driver' => 'local', 'root' => $base.'/root'],
            'kitsune.media.disks.public' => 'pubstore',
            'kitsune.media.disks.private' => 'host-private',
        ]);

        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toStartWith('unknown ')
            ->and(reconcileLine($output, $id))->toContain('[host-private] disk is local and its root is missing or cannot be created')
            ->and($output)->toContain('make the disk reachable, or its configuration whole')
            ->and($output)->not->toContain('Re-run with --force')
            ->and($exit)->toBe(1);

        [$exit, $output] = reconcileRun(['--force' => true]);

        expect(reconcileLine($output, $id))->toContain('→ failed')
            ->and(reconcileNamed($id))->toBe(MediaDisks::PRIVATE)
            ->and($exit)->toBe(1);
    });

    // ...but not one whose root exists, with the prefix below it missing: Flysystem creates nothing, the disk is built,
    // and it holds nothing — whatever the root's mode (review of slice 5c).
    it('builds a local disk whose root exists though its prefix below it does not', function (bool $scoped): void {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            test()->markTestSkipped('root ignores a directory\'s mode');
        }

        require_once dirname(__DIR__).'/Fixtures/PathPrefixedAdapter.php';
        [$id] = reconcileFile(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => RECONCILE_PNG], trashed: true, visibility: 'private');
        $base = sys_get_temp_dir().'/kitsune-reconcile-sealed-'.bin2hex(random_bytes(4));
        mkdir($base, 0777, true);
        $this->roots[] = $base;
        chmod($base, 0555);
        config($scoped
            ? ['filesystems.disks.ro-base' => ['driver' => 'local', 'root' => $base], 'filesystems.disks.ro' => ['driver' => 'scoped', 'disk' => 'ro-base', 'prefix' => 'site']]
            : ['filesystems.disks.ro' => ['driver' => 'local', 'root' => $base, 'prefix' => 'site']]);
        DB::table('media_files')->where('entry_id', $id)->update(['disk' => 'ro']);

        [$exit, $output] = reconcileRun();

        expect(reconcileLine($output, $id))->toStartWith('elsewhere ')
            ->and(reconcileLine($output, $id))->toContain('held by '.MediaDisks::PRIVATE)
            ->and($output)->toContain('Re-run with --force')
            ->and($exit)->toBe(1);

        [$exit, $output] = reconcileRun(['--force' => true]);

        expect(reconcileLine($output, $id))->toContain('→ settled')
            ->and(reconcileNamed($id))->toBe(MediaDisks::PRIVATE)
            ->and(is_dir($base.'/site'))->toBeFalse()
            ->and($exit)->toBe(0);
    })->with(['a prefix of its own' => false, 'a scoped disk over it' => true]);
});
