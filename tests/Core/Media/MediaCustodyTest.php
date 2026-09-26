<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Media\MediaBytes;
use Kitsune\Core\Media\MediaCustody;
use Kitsune\Core\Media\MediaCustodyFailure;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaKeeper;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\RefusingDisk;
use League\Flysystem\Filesystem;

/*
 * Custody of a file's bytes, asked directly — ADR-042 decision 5 (T14-T19; slice 5b: T61, T63-T68, T72,
 * T98-T100, T102).
 *
 * ⚠️ ON ROWS WRITTEN WITH `DB::table`, NOTHING WIRED TO A DELETE. What these pin is custody's own rule — when it may
 * run, which copy it keeps, where it puts the file and what it removes — so the fixtures set a row and the disks by
 * hand, and every disk is a `RefusingDisk` whose log is what the assertions read.
 */

const CUSTODY_PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\x0f\0\0\x01\x01\0\x05\x18\xd8N\0\0\0\0IEND\xaeB`\x82";

beforeEach(function (): void {
    $this->roots = [];
    $this->disks = [];

    // `old-cdn` was once a public disk: a local one with a url, so the web serves it.
    foreach (['public', MediaDisks::PRIVATE, 'old-cdn'] as $name) {
        $root = sys_get_temp_dir().'/kitsune-custody-'.$name.'-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        $this->roots[] = $root;
        $this->disks[$name] = RefusingDisk::install($name, $root);
    }

    config(['filesystems.disks.old-cdn' => ['driver' => 'local', 'root' => $this->disks['old-cdn']->root(), 'url' => 'https://cdn.example.test']]);

    $org = Org::create(['slug' => 'custody', 'name' => 'Custody']);
    app(Context::class)->setOrg($org);
    app(Context::class)->setSite(Site::create(['handle' => 'main', 'slug' => 'custody-main', 'name' => 'Main', 'locale' => 'en']));
    $this->image = EntryType::create(['org_id' => $org->id, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);

    $this->checksum = hash('sha256', CUSTODY_PNG);
});

afterEach(function (): void {
    app(Context::class)->forget();

    foreach ($this->roots as $root) {
        exec('rm -rf '.escapeshellarg($root));
    }
});

/**
 * A stored file, then set by hand: the row's disk, its entry trashed or not, and which disks hold which bytes.
 *
 * @param  array<string, string>  $copies  disk => bytes
 * @return array{0: int, 1: string} the entry's id and the file's path
 */
function custodyFile(string $named, array $copies, bool $trashed = false, string $visibility = 'public'): array
{
    $source = tempnam(sys_get_temp_dir(), 'kitsune-custody-');
    file_put_contents($source, CUSTODY_PNG);
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

/** @return list<string> "event disk:path" for every operation that changed bytes, in order */
function custodyByteOperations(): array
{
    return array_values(array_map(
        static fn (array $entry): string => $entry['event'].' '.$entry['disk'].':'.$entry['path'],
        array_filter(RefusingDisk::$log, static fn (array $entry): bool => $entry['bytes']),
    ));
}

/** What each disk holds at the path: its bytes' hash, or null. */
function custodyCopies(string $path): array
{
    $copies = [];

    foreach (['public', MediaDisks::PRIVATE, 'old-cdn'] as $disk) {
        $copies[$disk] = Storage::disk($disk)->exists($path) ? hash('sha256', (string) Storage::disk($disk)->get($path)) : null;
    }

    return $copies;
}

/** Point the private disk at a host disk of its own, leaving core's private disk configured and asked. */
function custodyHostPrivate(): RefusingDisk
{
    $root = sys_get_temp_dir().'/kitsune-custody-host-private-'.bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    // Read and written back whole: `test()` is a proxy, and an array appended through it is appended to a copy.
    test()->roots = [...test()->roots, $root];
    config([
        'filesystems.disks.host-private' => ['driver' => 'local', 'root' => $root],
        'kitsune.media.disks.private' => 'host-private',
    ]);
    $disk = RefusingDisk::install('host-private', $root);
    test()->disks = [...test()->disks, 'host-private' => $disk];

    return $disk;
}

/** A legacy `local` disk, as ADR-041 left private files on: local, unserved, a root of its own. */
function custodyLocal(): RefusingDisk
{
    $root = sys_get_temp_dir().'/kitsune-custody-local-'.bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    test()->roots = [...test()->roots, $root];
    config(['filesystems.disks.local' => ['driver' => 'local', 'root' => $root]]);
    $disk = RefusingDisk::install('local', $root);
    test()->disks = [...test()->disks, 'local' => $disk];

    return $disk;
}

/** @return list<string> every operation one disk saw, in order */
function custodyEvents(string $disk): array
{
    return array_values(array_map(
        static fn (array $entry): string => $entry['event'],
        array_filter(RefusingDisk::$log, static fn (array $entry): bool => $entry['disk'] === $disk),
    ));
}

/**
 * Make a disk's copy read as absent at its nth operation of any kind, and be there again at a later one — an object
 * store's momentary 404, a sync tool rewriting the file.
 */
function custodyFlap(RefusingDisk $disk, string $path, int $away, int $back): void
{
    $file = $disk->root().'/'.$path;
    $disk->onOperation($away, static function () use ($file): void {
        rename($file, $file.'.away');
    }, 'any');
    $disk->onOperation($back, static function () use ($file): void {
        rename($file.'.away', $file);
    }, 'any');
}

function custodyRow(int $entryId): stdClass
{
    return DB::table('media_files')->where('entry_id', $entryId)->first();
}

/*
 * T14. When a job runs: at once where nothing is left to commit, after the outermost commit otherwise, never after a
 * rollback — and without recursing, which the suite's own wrapper transaction would provoke if level alone decided.
 */
describe('whenOutermost', function (): void {
    it('runs a job at once where nothing is left to commit', function (): void {
        $runs = 0;

        MediaCustody::whenOutermost(DB::connection(), function () use (&$runs): void {
            $runs++;
        });

        expect($runs)->toBe(1);
    });

    it('runs it after the outermost commit, not an inner one', function (): void {
        $runs = 0;
        $job = function () use (&$runs): void {
            $runs++;
        };

        DB::transaction(function () use ($job, &$runs): void {
            MediaCustody::whenOutermost(DB::connection(), $job);

            DB::transaction(function () use ($job, &$runs): void {
                MediaCustody::whenOutermost(DB::connection(), $job);
            });

            expect($runs)->toBe(0);
        });

        expect($runs)->toBe(2);
    });

    /** ⚠️ ON ITS OWN CONNECTION'S TRANSACTION: Laravel's `afterCommit()` would hand it to the other one, and lose it. */
    it('keeps it through a rollback on another connection opened inside', function (): void {
        config(['database.connections.custody-other' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        $runs = 0;

        DB::transaction(function () use (&$runs): void {
            try {
                DB::connection('custody-other')->transaction(function () use (&$runs): void {
                    MediaCustody::whenOutermost(DB::connection(), function () use (&$runs): void {
                        $runs++;
                    });

                    throw new RuntimeException('the other connection rolls back');
                });
            } catch (RuntimeException) {
            }

            expect($runs)->toBe(0);
        });

        expect($runs)->toBe(1);
    });

    it('never runs it when the transaction rolls back', function (): void {
        $runs = 0;

        try {
            DB::transaction(function () use (&$runs): void {
                MediaCustody::whenOutermost(DB::connection(), function () use (&$runs): void {
                    $runs++;
                });

                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }

        expect($runs)->toBe(0);
    });
});

/*
 * T15. Bytes moved inside a transaction would stay moved when it rolled back.
 */
it('refuses to settle inside an open transaction, before asking any disk anything', function (): void {
    // A file that has to move: the row names public, and the bytes are on the private disk.
    [$id] = custodyFile('public', [MediaDisks::PRIVATE => CUSTODY_PNG]);

    expect(fn () => DB::transaction(fn () => MediaCustody::settle(DB::connection(), $id)))
        ->toThrow(LogicException::class, 'inside an open transaction');

    expect(RefusingDisk::$log)->toBe([]);
});

/*
 * T16. A settle that fails rolls back its own transaction, and the rollback drains again: the queue must already be
 * empty by then, or the drain settles the same entry without end.
 */
it('settles each queued entry once, even when settling it fails and rolls back', function (): void {
    // A withdrawal the database rolled back: the row names public again, and the bytes are on the private disk.
    [$id] = custodyFile('public', [MediaDisks::PRIVATE => CUSTODY_PNG]);
    $this->disks['public']->failWrites = true;
    Log::spy();

    // The rollback that would drain again, counted, so the test shows the path it guards is taken.
    $rollbacks = 0;
    Event::listen(TransactionRolledBack::class, function () use (&$rollbacks): void {
        $rollbacks++;
    });

    MediaCustody::queue(DB::getDefaultConnection(), [$id]);
    MediaCustody::drain(DB::connection());

    expect($rollbacks)->toBeGreaterThanOrEqual(1);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'could not be put back'))->once();
    expect(array_filter(custodyByteOperations(), fn (string $operation): bool => str_starts_with($operation, 'writeStream public:')))->toHaveCount(1);
});

/*
 * The queue drains on the rollback that leaves nothing on the connection to commit — through the listener core
 * registers, so a withdrawal the database rolled back is put back without anyone asking.
 */
it('puts a rolled-back withdrawal\'s file back once nothing is left to commit', function (): void {
    [$id, $path] = custodyFile('public', [MediaDisks::PRIVATE => CUSTODY_PNG]);
    MediaCustody::queue(DB::getDefaultConnection(), [$id]);

    DB::transaction(function () use ($path): void {
        try {
            DB::transaction(fn () => throw new RuntimeException('the withdrawal rolls back'));
        } catch (RuntimeException) {
        }

        // The enclosing transaction could still roll back: nothing moves yet.
        expect(custodyCopies($path)['public'])->toBeNull();
    });

    expect(custodyCopies($path)['public'])->toBe($this->checksum);
});

/*
 * T17. The keeper, asked directly: the recorded checksum first, then the named copy (Adam's 2b), then the configured
 * order; and a copy that cannot be read is a failure, never an absence.
 */
describe('the keeper', function (): void {
    /** The named disk holds a match too, so hashing it first would keep it — and cost a second hash. */
    it('keeps the target\'s copy when it matches, for one hash', function (): void {
        [$id] = custodyFile('old-cdn', ['public' => CUSTODY_PNG, 'old-cdn' => CUSTODY_PNG]);

        $keeper = MediaCustody::keeper(custodyRow($id), 'public', 'old-cdn', ['public', 'old-cdn', MediaDisks::PRIVATE]);

        expect($keeper->mode)->toBe(MediaKeeper::MATCH)
            ->and($keeper->disk)->toBe('public')
            ->and($keeper->targetHolds)->toBeTrue()
            ->and(array_values(array_filter(RefusingDisk::$log, fn (array $entry): bool => $entry['event'] === 'checksum')))->toHaveCount(1);
    });

    it('keeps the named copy when none matches, over another that differs from both', function (): void {
        [$id] = custodyFile('old-cdn', ['old-cdn' => 'changed by hand', 'public' => 'changed again']);

        $keeper = MediaCustody::keeper(custodyRow($id), MediaDisks::PRIVATE, 'old-cdn', [MediaDisks::PRIVATE, 'old-cdn', 'public']);

        expect($keeper->mode)->toBe(MediaKeeper::NAMED)
            ->and($keeper->disk)->toBe('old-cdn')
            ->and($keeper->expected)->toBe(hash('sha256', 'changed by hand'))
            ->and($keeper->targetHolds)->toBeFalse();
    });

    it('keeps the first copy in the configured order when the named disk holds none, and the copies agree', function (): void {
        [$id] = custodyFile('old-cdn', ['public' => 'changed by hand', MediaDisks::PRIVATE => 'changed by hand']);

        $keeper = MediaCustody::keeper(custodyRow($id), MediaDisks::PRIVATE, 'old-cdn', [MediaDisks::PRIVATE, 'old-cdn', 'public']);

        expect($keeper->mode)->toBe(MediaKeeper::FIRST)
            ->and($keeper->expected)->toBe(hash('sha256', 'changed by hand'))
            ->and($keeper->targetHolds)->toBeTrue();
    });

    /** ⚠️ ADAM'S ORDER, PINNED (decision 5, 2026-09-25): the configured public disk before the private one, then the served, the target last. */
    it('keeps the first copy in Adam\'s order when the copies differ, never the target\'s first', function (): void {
        [$id] = custodyFile('gone', ['public' => 'first', MediaDisks::PRIVATE => 'target', 'old-cdn' => 'served']);

        $keeper = MediaCustody::keeper(custodyRow($id), MediaDisks::PRIVATE, 'gone', [MediaDisks::PRIVATE, 'public', 'old-cdn']);

        expect($keeper->mode)->toBe(MediaKeeper::FIRST)
            ->and($keeper->disk)->toBe('public')
            ->and($keeper->expected)->toBe(hash('sha256', 'first'));
    });

    /** Publication with the named disk empty: the target's own differing copy is the last choice, not the first. */
    it('keeps another copy over the target\'s own when the copies differ', function (): void {
        [$id] = custodyFile('gone', ['public' => 'target', MediaDisks::PRIVATE => 'private']);

        $keeper = MediaCustody::keeper(custodyRow($id), 'public', 'gone', ['public', MediaDisks::PRIVATE, 'old-cdn']);

        expect($keeper->mode)->toBe(MediaKeeper::FIRST)
            ->and($keeper->disk)->toBe(MediaDisks::PRIVATE)
            ->and($keeper->targetHolds)->toBeFalse();
    });

    /** With the target public, the configured private disk comes before the other served disks. */
    it('keeps the private copy over a served disk\'s when the target is public and the copies differ', function (): void {
        [$id] = custodyFile('gone', [MediaDisks::PRIVATE => 'private', 'old-cdn' => 'served']);

        $keeper = MediaCustody::keeper(custodyRow($id), 'public', 'gone', ['public', MediaDisks::PRIVATE, 'old-cdn']);

        expect($keeper->mode)->toBe(MediaKeeper::FIRST)
            ->and($keeper->disk)->toBe(MediaDisks::PRIVATE);
    });

    /*
     * T63. Core's private disk comes after the configured private disk and before the served disks, and every other disk
     * asked after those (Adam, decision 5, 2026-09-25). The order once left it out, so a copy held only there fell
     * through to a target that held nothing, whose hash was then read before it was taken.
     */
    it('orders core\'s private disk after the configured private disk, and every disk asked before the target', function (): void {
        custodyHostPrivate();
        $root = sys_get_temp_dir().'/kitsune-custody-other-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        $this->roots[] = $root;
        RefusingDisk::install('other', $root);
        [$id, $path] = custodyFile('gone', ['host-private' => 'private', MediaDisks::PRIVATE => 'core', 'old-cdn' => 'served', 'other' => 'other']);
        // The named disk is gone from the configuration and holds nothing, so it is not asked, as T17 does.
        $asked = ['public', 'host-private', MediaDisks::PRIVATE, 'old-cdn', 'other'];
        $kept = function () use ($id, $asked): string {
            $keeper = MediaCustody::keeper(custodyRow($id), 'public', 'gone', $asked);

            expect($keeper->mode)->toBe(MediaKeeper::FIRST);

            return (string) $keeper->disk;
        };

        expect($kept())->toBe('host-private');
        Storage::disk('host-private')->delete($path);
        expect($kept())->toBe(MediaDisks::PRIVATE);
        Storage::disk(MediaDisks::PRIVATE)->delete($path);
        expect($kept())->toBe('old-cdn');
        Storage::disk('old-cdn')->delete($path);
        expect($kept())->toBe('other');
    });

    it('keeps a copy held only on core\'s private disk once the private disk has moved', function (): void {
        custodyHostPrivate();
        [$id] = custodyFile('public', [MediaDisks::PRIVATE => 'core'], trashed: true);

        $keeper = MediaCustody::keeper(custodyRow($id), 'host-private', 'public', MediaCustody::asked(config(), 'host-private', 'public'));

        expect($keeper->mode)->toBe(MediaKeeper::FIRST)
            ->and($keeper->disk)->toBe(MediaDisks::PRIVATE)
            ->and($keeper->expected)->toBe(hash('sha256', 'core'))
            ->and($keeper->targetHolds)->toBeFalse();
    });

    it('says so when no disk holds the file', function (): void {
        [$id] = custodyFile('public', []);

        expect(MediaCustody::keeper(custodyRow($id), 'public', 'public', ['public', MediaDisks::PRIVATE])->mode)->toBe(MediaKeeper::MISSING);
    });

    it('fails on a copy it cannot read, rather than reading it as absent', function (): void {
        [$id, $path] = custodyFile('old-cdn', ['old-cdn' => 'changed by hand', MediaDisks::PRIVATE => CUSTODY_PNG]);
        $this->disks['old-cdn']->unreadable = [$path];

        expect(fn () => MediaCustody::keeper(custodyRow($id), 'public', 'old-cdn', ['public', 'old-cdn', MediaDisks::PRIVATE]))
            ->toThrow(MediaCustodyFailure::class, 'cannot be read');
    });
});

/*
 * T18. Settling: publication, move-off, the private target, and the rows it leaves alone.
 */
describe('settle', function (): void {
    it('publishes a file named on a former public disk, and moves it off that disk', function (): void {
        [$id, $path] = custodyFile('old-cdn', ['old-cdn' => CUSTODY_PNG]);

        expect(MediaCustody::settle(DB::connection(), $id, publication: true))->toBe(MediaCustody::SETTLED)
            ->and(custodyCopies($path))->toBe(['public' => $this->checksum, MediaDisks::PRIVATE => null, 'old-cdn' => null])
            ->and(custodyRow($id)->disk)->toBe('public');
    });

    it('publishes a file named on the private disk, and leaves the private copy', function (): void {
        [$id, $path] = custodyFile(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => CUSTODY_PNG]);

        expect(MediaCustody::settle(DB::connection(), $id, publication: true))->toBe(MediaCustody::SETTLED)
            ->and(custodyCopies($path))->toBe(['public' => $this->checksum, MediaDisks::PRIVATE => $this->checksum, 'old-cdn' => null])
            ->and(custodyRow($id)->disk)->toBe('public');
    });

    it('publishes the matching copy when the named one differs, and says what it removed', function (): void {
        [$id, $path] = custodyFile('old-cdn', ['old-cdn' => 'changed by hand', MediaDisks::PRIVATE => CUSTODY_PNG]);
        Log::spy();

        MediaCustody::settle(DB::connection(), $id, publication: true);

        expect(custodyCopies($path))->toBe(['public' => $this->checksum, MediaDisks::PRIVATE => $this->checksum, 'old-cdn' => null]);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, hash('sha256', 'changed by hand'))
            && str_contains($message, $this->checksum))->once();
    });

    it('says what it overwrote when the target held a copy that differs', function (): void {
        [$id, $path] = custodyFile(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => CUSTODY_PNG, 'public' => 'changed by hand']);
        Log::spy();

        MediaCustody::settle(DB::connection(), $id, publication: true);

        expect(custodyCopies($path)['public'])->toBe($this->checksum);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_starts_with($message, 'Media custody: overwriting')
            && str_contains($message, hash('sha256', 'changed by hand'))
            && str_contains($message, $this->checksum))->once();
    });

    it('publishes nothing for an entry trashed since, and touches no byte', function (): void {
        [$id, $path] = custodyFile(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => CUSTODY_PNG], trashed: true);

        expect(MediaCustody::settle(DB::connection(), $id, publication: true))->toBe(MediaCustody::UNCHANGED)
            ->and(custodyByteOperations())->toBe([])
            ->and(custodyCopies($path)['public'])->toBeNull();
    });

    it('touches no byte for an entry that is gone', function (): void {
        [$id] = custodyFile('public', ['public' => CUSTODY_PNG]);
        DB::table('media_files')->where('entry_id', $id)->delete();

        expect(MediaCustody::settle(DB::connection(), $id))->toBe(MediaCustody::GONE)
            ->and(custodyByteOperations())->toBe([]);
    });

    /** And names where the kept copy is now: the private disk, not the public one it has just emptied. */
    it('puts a trashed file on the private disk and empties every disk the web serves', function (): void {
        [$id, $path] = custodyFile('public', ['public' => CUSTODY_PNG, 'old-cdn' => 'changed by hand'], trashed: true);
        Log::spy();

        expect(MediaCustody::settle(DB::connection(), $id))->toBe(MediaCustody::SETTLED)
            ->and(custodyCopies($path))->toBe(['public' => null, MediaDisks::PRIVATE => $this->checksum, 'old-cdn' => null])
            ->and(custodyRow($id)->disk)->toBe(MediaDisks::PRIVATE);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_starts_with($message, 'Media custody: removing')
            && str_contains($message, 'on [old-cdn]')
            && str_contains($message, 'which ['.MediaDisks::PRIVATE.'] holds'))->once();
    });

    /*
     * T61. The public disk and the disk the row names are one bucket and key prefix through two endpoints — one store
     * under two names, or two stores; nothing in the configuration says which. An object store is written at the key
     * itself, so a copy onto the source under another name, or a move-off from it, could destroy the file: settle refuses
     * before its first byte.
     */
    it('refuses to copy onto a disk it cannot tell from the source, before any byte', function (): void {
        foreach (['public' => 'e', 'alias' => 'f'] as $name => $endpoint) {
            $root = sys_get_temp_dir().'/kitsune-custody-store-'.$name.'-'.bin2hex(random_bytes(4));
            mkdir($root, 0777, true);
            $this->roots[] = $root;
            $config = ['driver' => 's3', 'bucket' => 'media', 'endpoint' => $endpoint, 'prefix' => 'site', 'url' => $name === 'public' ? 'https://media.example.test' : null];
            config(["filesystems.disks.{$name}" => $config]);
            $adapter = new RefusingDisk($root, $name);
            Storage::set($name, new FilesystemAdapter(new Filesystem($adapter), $adapter, $config));
        }

        [$id, $path] = custodyFile('alias', ['alias' => CUSTODY_PNG]);

        expect(fn () => MediaCustody::settle(DB::connection(), $id))->toThrow(
            RuntimeException::class,
            'the [public] and [alias] disks name one bucket through two endpoints',
        );

        expect(custodyByteOperations())->toBe([])
            ->and(Storage::disk('alias')->get($path))->toBe(CUSTODY_PNG)
            ->and(Storage::disk('public')->exists($path))->toBeFalse()
            ->and(custodyRow($id)->disk)->toBe('alias');
    });

    /*
     * T64. Custody asks core's private disk wherever the private disk points, so a copy a disposal or a move left there
     * is found — and never asks a disk whose root does not exist, because building it would create the directory.
     */
    it('publishes from core\'s private disk when the private disk has moved and it alone holds the file', function (): void {
        custodyHostPrivate();
        [$id, $path] = custodyFile('host-private', [MediaDisks::PRIVATE => CUSTODY_PNG]);

        expect(MediaCustody::settle(DB::connection(), $id, publication: true))->toBe(MediaCustody::SETTLED)
            ->and(Storage::disk('public')->get($path))->toBe(CUSTODY_PNG)
            ->and(custodyRow($id)->disk)->toBe('public');
    });

    it('builds no disk whose root does not exist', function (): void {
        custodyHostPrivate();
        $core = sys_get_temp_dir().'/kitsune-custody-rootless-core-'.bin2hex(random_bytes(4));
        $cdn = sys_get_temp_dir().'/kitsune-custody-rootless-cdn-'.bin2hex(random_bytes(4));
        config([
            'filesystems.disks.'.MediaDisks::PRIVATE.'.root' => $core,
            'filesystems.disks.rootless-cdn' => ['driver' => 'local', 'root' => $cdn, 'url' => 'https://rootless.example.test'],
        ]);
        Storage::forgetDisk(MediaDisks::PRIVATE);
        [$id, $path] = custodyFile('public', ['public' => CUSTODY_PNG], trashed: true);

        expect(MediaCustody::settle(DB::connection(), $id))->toBe(MediaCustody::SETTLED)
            ->and(Storage::disk('host-private')->get($path))->toBe(CUSTODY_PNG)
            ->and(is_dir($core))->toBeFalse()
            ->and(is_dir($cdn))->toBeFalse();
    });

    /*
     * T65. The cost of asking it: a copy there that cannot be read stops a publication and a compensation that reach it,
     * as any unreadable copy does, and each says which disk.
     */
    it('refuses a publication and a compensation while core\'s private disk holds a copy it cannot read', function (string $step): void {
        custodyHostPrivate();
        [$id, $path] = custodyFile('host-private', ['host-private' => 'changed by hand', MediaDisks::PRIVATE => CUSTODY_PNG]);
        $this->disks[MediaDisks::PRIVATE]->unreadable = [$path];
        Log::spy();

        if ($step === 'publication') {
            MediaCustody::publish(DB::connection(), [$id]);
        } else {
            MediaCustody::queue(DB::connection()->getName(), [$id]);
            MediaCustody::drain(DB::connection());
        }

        expect(custodyByteOperations())->toBe([])
            ->and(custodyRow($id)->disk)->toBe('host-private')
            ->and(Storage::disk('public')->exists($path))->toBeFalse();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, "entry {$id}")
            && str_contains($message, MediaDisks::PRIVATE)
            && str_contains($message, 'cannot be read'))->once();
    })->with(['publication', 'compensation']);

    /*
     * T68. The served disks are emptied before the named disk is moved off, so a named disk that refuses its delete
     * cannot keep a trashed file on the web; the row, moved last, still names it.
     */
    it('takes a trashed file off every served disk before it moves off the named disk', function (): void {
        custodyLocal()->failDeletes = true;
        [$id, $path] = custodyFile('local', ['local' => CUSTODY_PNG, 'public' => CUSTODY_PNG], trashed: true);

        expect(fn () => MediaCustody::settle(DB::connection(), $id))->toThrow(MediaCustodyFailure::class, 'could not be deleted');

        expect(Storage::disk('public')->exists($path))->toBeFalse()
            ->and(custodyCopies($path)[MediaDisks::PRIVATE])->toBe($this->checksum)
            ->and(custodyRow($id)->disk)->toBe('local');
    });

    /** A publication that fails is logged, never thrown — and the log does not claim a commit that was never made. */
    it('logs a publication it could not make, as not published', function (): void {
        [$id, $path] = custodyFile(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => CUSTODY_PNG]);
        $this->disks['public']->failWrites = true;
        Log::spy();

        MediaCustody::publish(DB::connection(), [$id]);

        expect(custodyRow($id)->disk)->toBe(MediaDisks::PRIVATE)
            ->and(custodyCopies($path)['public'])->toBeNull();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, "entry {$id}: publication failed")
            && str_contains($message, 'so it is not published')
            && ! str_contains($message, 'whether the commit landed'))->once();
    });
});

/*
 * T72. Prune's removal of an extra copy: only while the row names the disk its state says, and that disk holds the copy
 * kept; never the target itself; and never before every copy it would remove has been read.
 */
describe('removeExtra', function (): void {
    it('removes an extra copy of a settled file', function (): void {
        [$id, $path] = custodyFile('public', ['public' => CUSTODY_PNG, 'old-cdn' => CUSTODY_PNG]);

        expect(MediaCustody::removeExtra(DB::connection(), $id, 'old-cdn'))->toBe(MediaCustody::SETTLED)
            ->and(custodyCopies($path))->toBe(['public' => $this->checksum, MediaDisks::PRIVATE => null, 'old-cdn' => null]);
    });

    it('keeps everything while the row does not name the disk its state says', function (): void {
        // Published, not yet committed: the row still names the private disk, and the public copy is the target's.
        [$id, $path] = custodyFile(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => CUSTODY_PNG, 'public' => CUSTODY_PNG]);

        expect(MediaCustody::removeExtra(DB::connection(), $id, MediaDisks::PRIVATE))->toBe(MediaCustody::UNSETTLED)
            ->and(custodyCopies($path))->toBe(['public' => $this->checksum, MediaDisks::PRIVATE => $this->checksum, 'old-cdn' => null]);
    });

    it('never removes the target\'s own copy', function (): void {
        [$id, $path] = custodyFile('public', ['public' => CUSTODY_PNG]);

        expect(MediaCustody::removeExtra(DB::connection(), $id, 'public'))->toBe(MediaCustody::UNCHANGED)
            ->and(custodyCopies($path)['public'])->toBe($this->checksum);
    });

    it('says the entry is gone, and touches nothing', function (): void {
        [$id, $path] = custodyFile('public', ['public' => CUSTODY_PNG, 'old-cdn' => CUSTODY_PNG]);
        DB::table('entries')->where('id', $id)->delete();

        expect(MediaCustody::removeExtra(DB::connection(), $id, 'old-cdn'))->toBe(MediaCustody::GONE)
            ->and(custodyByteOperations())->toBe([]);
    });

    it('fails on a copy it cannot read, and removes nothing', function (): void {
        [$id, $path] = custodyFile('public', ['public' => CUSTODY_PNG, 'old-cdn' => 'changed by hand']);
        $this->disks['old-cdn']->unreadable = [$path];

        expect(fn () => MediaCustody::removeExtra(DB::connection(), $id, 'old-cdn'))->toThrow(MediaCustodyFailure::class, 'exists and cannot be read');

        expect(custodyByteOperations())->toBe([]);
    });

    it('refuses inside an open transaction', function (): void {
        [$id, $path] = custodyFile('public', ['public' => CUSTODY_PNG, 'old-cdn' => CUSTODY_PNG]);

        expect(fn () => DB::transaction(fn () => MediaCustody::removeExtra(DB::connection(), $id, 'old-cdn')))
            ->toThrow(LogicException::class, 'inside an open transaction');

        expect(custodyCopies($path)['old-cdn'])->toBe($this->checksum);
    });

    /** The third write reads every copy before it removes the first: a copy it cannot read keeps the others too. */
    it('reads every copy before it removes any', function (): void {
        custodyHostPrivate();
        [$id, $path] = custodyFile('public', ['public' => CUSTODY_PNG, 'host-private' => CUSTODY_PNG, MediaDisks::PRIVATE => 'stale']);
        $this->disks[MediaDisks::PRIVATE]->unreadable = [$path];

        expect(fn () => MediaCustody::cleanUp(DB::connection(), $id))->toThrow(MediaCustodyFailure::class, 'exists and cannot be read');

        expect(custodyByteOperations())->toBe([])
            ->and(Storage::disk('host-private')->get($path))->toBe(CUSTODY_PNG);
    });
});

/*
 * T66-T67. An unreadable copy and a file on the web — Adam, decision 6, 2026-09-25.
 *
 * ⚠️ SET ASIDE ONLY TO TAKE A FILE OFF THE WEB, AND NEVER TOUCHED. While settle withdraws to the private disk a file a
 * served disk still holds, a copy that exists and cannot be read, on a disk neither served nor the target, is left where
 * it is and the file is taken off the web from a readable copy. Everywhere else the rule stands: a copy that cannot be
 * read is never taken for absent, and the step refuses. Each refusal is asserted from the byte log and the row, so a
 * refusal after a byte moved, or one another guard made, cannot pass for it.
 */
describe('unreadable copies and exposure', function (): void {
    // T66(a)
    it('withdraws a trashed file past an unreadable copy on core\'s private disk, and never touches it', function (): void {
        custodyHostPrivate();
        [$id, $path] = custodyFile('public', ['public' => 'changed by hand', MediaDisks::PRIVATE => CUSTODY_PNG], trashed: true);
        $this->disks[MediaDisks::PRIVATE]->unreadable = [$path];
        Log::spy();

        expect(MediaCustody::settle(DB::connection(), $id))->toBe(MediaCustody::SET_ASIDE)
            ->and(Storage::disk('host-private')->get($path))->toBe('changed by hand')
            ->and(Storage::disk('public')->exists($path))->toBeFalse()
            ->and(custodyRow($id)->disk)->toBe('host-private')
            ->and(file_get_contents($this->disks[MediaDisks::PRIVATE]->root().'/'.$path))->toBe(CUSTODY_PNG)
            ->and(custodyEvents(MediaDisks::PRIVATE))->toBe(['fileExists', 'fileExists', 'checksum']);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, "entry {$id}: the copy of [{$path}] on [".MediaDisks::PRIVATE.'] exists and cannot be read')
            && str_contains($message, 'decision 6'))->once();
    });

    // T66(b): the keeper matched before it reached the named disk, so the named disk's first hash is its move-off.
    it('leaves the row on a named disk it cannot read, having taken the file off the web', function (): void {
        custodyLocal();
        [$id, $path] = custodyFile('local', ['local' => 'stale', MediaDisks::PRIVATE => CUSTODY_PNG, 'public' => CUSTODY_PNG], trashed: true);
        $this->disks['local']->unreadable = [$path];

        expect(MediaCustody::settle(DB::connection(), $id))->toBe(MediaCustody::SET_ASIDE)
            ->and(custodyCopies($path))->toBe(['public' => null, MediaDisks::PRIVATE => $this->checksum, 'old-cdn' => null])
            ->and(custodyRow($id)->disk)->toBe('local')
            ->and(file_get_contents($this->disks['local']->root().'/'.$path))->toBe('stale')
            ->and(custodyEvents('local'))->toBe(['fileExists', 'fileExists', 'fileExists', 'checksum']);
    });

    // T66(c): nothing matches, and the named disk cannot be read, so the first readable copy in Adam's order is kept.
    it('keeps the first readable copy when the named one cannot be read and nothing matches', function (): void {
        custodyLocal();
        [$id, $path] = custodyFile('local', ['local' => 'stale', 'public' => 'changed by hand'], trashed: true);
        $this->disks['local']->unreadable = [$path];
        Log::spy();

        expect(MediaCustody::settle(DB::connection(), $id))->toBe(MediaCustody::SET_ASIDE)
            ->and(custodyCopies($path))->toBe(['public' => null, MediaDisks::PRIVATE => hash('sha256', 'changed by hand'), 'old-cdn' => null])
            ->and(custodyRow($id)->disk)->toBe('local')
            ->and(file_get_contents($this->disks['local']->root().'/'.$path))->toBe('stale')
            ->and(custodyEvents('local'))->toBe(['fileExists', 'fileExists', 'checksum']);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'so the copy on [public] is kept')
            && str_contains($message, 'Unreadable, and set aside: [local]'))->once();
    });

    // T67(i)
    it('refuses when no disk the web serves holds the file', function (): void {
        custodyHostPrivate();
        custodyLocal();
        [$id, $path] = custodyFile('local', ['local' => 'stale', MediaDisks::PRIVATE => CUSTODY_PNG], trashed: true);
        $this->disks['local']->unreadable = [$path];

        expect(fn () => MediaCustody::settle(DB::connection(), $id))->toThrow(MediaCustodyFailure::class, 'exists and cannot be read');

        expect(custodyByteOperations())->toBe([])
            ->and(custodyRow($id)->disk)->toBe('local');
    });

    // T67(ii)
    it('refuses a publication, whose target is public, on any copy it cannot read', function (string $how): void {
        [$id, $path] = custodyFile(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => CUSTODY_PNG, 'old-cdn' => CUSTODY_PNG]);
        $this->disks[MediaDisks::PRIVATE]->unreadable = [$path];
        Log::spy();

        if ($how === 'publish') {
            MediaCustody::publish(DB::connection(), [$id]);

            Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, "entry {$id}: publication failed")
                && str_contains($message, 'exists and cannot be read'))->once();
        } else {
            expect(fn () => MediaCustody::settle(DB::connection(), $id, publication: true))->toThrow(MediaCustodyFailure::class, 'exists and cannot be read');
        }

        expect(custodyByteOperations())->toBe([])
            ->and(custodyRow($id)->disk)->toBe(MediaDisks::PRIVATE);
    })->with(['publish', 'settle']);

    // T67(iii)
    it('refuses on an unreadable copy on a disk the web serves', function (): void {
        [$id, $path] = custodyFile('public', ['public' => CUSTODY_PNG, 'old-cdn' => 'changed by hand'], trashed: true);
        $this->disks['public']->unreadable = [$path];

        expect(fn () => MediaCustody::settle(DB::connection(), $id))->toThrow(MediaCustodyFailure::class, 'exists and cannot be read');

        expect(custodyByteOperations())->toBe([])
            ->and(custodyCopies($path)[MediaDisks::PRIVATE])->toBeNull()
            ->and(custodyRow($id)->disk)->toBe('public');
    });

    // T67(iv)
    it('refuses on an unreadable copy on the target, and never overwrites it', function (): void {
        [$id, $path] = custodyFile('public', ['public' => CUSTODY_PNG, MediaDisks::PRIVATE => 'stale'], trashed: true);
        $this->disks[MediaDisks::PRIVATE]->unreadable = [$path];

        expect(fn () => MediaCustody::settle(DB::connection(), $id))->toThrow(MediaCustodyFailure::class, 'exists and cannot be read');

        // Refused by the keeper, at the target's own hash, before any other copy was read — not by a later re-read.
        expect(custodyByteOperations())->toBe([])
            ->and(custodyEvents('public'))->toBe(['fileExists'])
            ->and(file_get_contents($this->disks[MediaDisks::PRIVATE]->root().'/'.$path))->toBe('stale');
    });

    // T67(v)
    it('refuses on a disk whose presence cannot be told, and sets nothing aside', function (): void {
        [$id, $path] = custodyFile('gone', ['public' => CUSTODY_PNG], trashed: true);

        expect(fn () => MediaCustody::settle(DB::connection(), $id))->toThrow(MediaCustodyFailure::class, 'whether it exists cannot be told');

        expect(custodyByteOperations())->toBe([])
            ->and(Storage::disk('public')->get($path))->toBe(CUSTODY_PNG)
            ->and(custodyRow($id)->disk)->toBe('gone');
    });

    // T67(v), where it can happen: a disk that answered the presence pass and cannot say at the hash (review of 5b).
    it('sets nothing aside when a disk that answered cannot tell whether it holds the copy by the time it is read', function (): void {
        $local = custodyLocal();
        [$id, $path] = custodyFile('local', ['local' => 'stale', 'public' => CUSTODY_PNG], trashed: true);
        $local->onOperation(2, static function () use ($local, $path): void {
            $local->unknown = [$path];
        }, 'any');

        expect(fn () => MediaCustody::settle(DB::connection(), $id))->toThrow(MediaCustodyFailure::class, 'whether it exists cannot be told');

        expect(custodyByteOperations())->toBe([])
            ->and(Storage::disk('public')->get($path))->toBe(CUSTODY_PNG)
            ->and(custodyRow($id)->disk)->toBe('local');
    });

    // T67(vii-a): present at the presence pass, gone at its hash — no served copy was read, so nothing answers for it.
    it('refuses when the served copy is gone by the time it is read', function (): void {
        custodyHostPrivate();
        custodyLocal();
        [$id, $path] = custodyFile('local', ['local' => 'stale', MediaDisks::PRIVATE => 'differs', 'public' => CUSTODY_PNG], trashed: true);
        $this->disks['local']->unreadable = [$path];
        $public = $this->disks['public'];
        $public->onOperation(2, static function () use ($public, $path): void {
            @unlink($public->root().'/'.$path);
        }, 'any');

        expect(fn () => MediaCustody::settle(DB::connection(), $id))->toThrow(MediaCustodyFailure::class, 'exists and cannot be read');

        expect(custodyByteOperations())->toBe([])
            ->and(custodyRow($id)->disk)->toBe('local');
    });

    // T67(vii-b): as (vii-a) with a matching copy elsewhere — the keeper matches, and settle removed no served copy.
    it('refuses, rather than setting a copy aside, when it removed no served copy', function (): void {
        custodyHostPrivate();
        custodyLocal();
        [$id, $path] = custodyFile('local', ['local' => 'stale', MediaDisks::PRIVATE => CUSTODY_PNG, 'public' => CUSTODY_PNG], trashed: true);
        $this->disks['local']->unreadable = [$path];
        $public = $this->disks['public'];
        $public->onOperation(2, static function () use ($public, $path): void {
            @unlink($public->root().'/'.$path);
        }, 'any');

        expect(fn () => MediaCustody::settle(DB::connection(), $id))->toThrow(MediaCustodyFailure::class, 'exists and cannot be read');

        // The sweep clears a partial beside each served path whether or not one is there; no copy of the file is deleted.
        expect(array_filter(custodyByteOperations(), static fn (string $operation): bool => str_starts_with($operation, 'delete')
            && ! str_ends_with($operation, MediaBytes::PARTIAL)))->toBe([])
            ->and(custodyRow($id)->disk)->toBe('local')
            ->and(file_get_contents($this->disks['local']->root().'/'.$path))->toBe('stale');
    });

    // T67(vii-c): the keeper matched on the target before it read the named disk, whose first read is the move-off's.
    it('refuses at the move-off when it removed no served copy', function (): void {
        custodyLocal();
        [$id, $path] = custodyFile('local', ['local' => 'stale', MediaDisks::PRIVATE => CUSTODY_PNG, 'public' => CUSTODY_PNG], trashed: true);
        $this->disks['local']->unreadable = [$path];
        $public = $this->disks['public'];
        // Seen at the presence pass; gone when the sweep asks.
        $public->onOperation(2, static function () use ($public, $path): void {
            @unlink($public->root().'/'.$path);
        }, 'any');

        expect(fn () => MediaCustody::settle(DB::connection(), $id))->toThrow(MediaCustodyFailure::class, 'exists and cannot be read');

        expect(custodyRow($id)->disk)->toBe('local')
            ->and(file_get_contents($this->disks['local']->root().'/'.$path))->toBe('stale');
    });

    /*
     * T102. Core's private disk keeps no row: prune sweeps it wherever the private disk points and lists a copy there at a
     * row's path, so the row moves to where it belongs though the copy there was set aside.
     */
    it('moves the row off core\'s private disk though its copy there was set aside', function (): void {
        custodyHostPrivate();
        [$id, $path] = custodyFile(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => 'stale', 'public' => CUSTODY_PNG], trashed: true);
        $this->disks[MediaDisks::PRIVATE]->unreadable = [$path];
        Log::spy();

        expect(MediaCustody::settle(DB::connection(), $id))->toBe(MediaCustody::SET_ASIDE)
            ->and(custodyRow($id)->disk)->toBe('host-private')
            ->and(Storage::disk('host-private')->get($path))->toBe(CUSTODY_PNG)
            ->and(Storage::disk('public')->exists($path))->toBeFalse()
            ->and(file_get_contents($this->disks[MediaDisks::PRIVATE]->root().'/'.$path))->toBe('stale');

        Log::shouldNotHaveReceived('warning', [Mockery::on(fn (string $message): bool => str_contains($message, 'The row still names'))]);
    });
});

/*
 * T98-T100. A copy that reads as absent when the keeper hashes it, and is there again when a step reaches it, is the one
 * copy that matches the checksum when the keeper fell back to another: settle refuses rather than remove or overwrite it
 * — rule 2's "nothing matching it is touched by a fallback", where the keeper could not see (review of slice 5b).
 */
describe('a copy that reads as absent and is back', function (): void {
    // T98: the served sweep.
    it('keeps a served copy that alone matches, though the keeper read it as absent', function (): void {
        [$id, $path] = custodyFile('public', ['public' => CUSTODY_PNG, MediaDisks::PRIVATE => 'stale'], trashed: true);
        // The presence pass; the keeper's hash — gone; the sweep's presence check — back.
        custodyFlap($this->disks['public'], $path, away: 2, back: 3);

        expect(fn () => MediaCustody::settle(DB::connection(), $id))->toThrow(MediaCustodyFailure::class, 'it matches the recorded checksum');

        expect(Storage::disk('public')->get($path))->toBe(CUSTODY_PNG)
            ->and(custodyRow($id)->disk)->toBe('public');
    });

    // T99: the move-off.
    it('keeps a named copy that alone matches, though the keeper read it as absent', function (): void {
        custodyLocal();
        [$id, $path] = custodyFile('local', ['local' => CUSTODY_PNG, MediaDisks::PRIVATE => 'stale'], trashed: true);
        custodyFlap($this->disks['local'], $path, away: 2, back: 3);

        expect(fn () => MediaCustody::settle(DB::connection(), $id))->toThrow(MediaCustodyFailure::class, 'it matches the recorded checksum');

        expect(file_get_contents($this->disks['local']->root().'/'.$path))->toBe(CUSTODY_PNG)
            ->and(custodyRow($id)->disk)->toBe('local');
    });

    // T100: the copy onto the target.
    it('never overwrites a target copy that alone matches, though the keeper read it as absent', function (): void {
        [$id, $path] = custodyFile('public', ['public' => 'changed by hand', MediaDisks::PRIVATE => CUSTODY_PNG], trashed: true);
        // The presence pass; the keeper's hash — gone; settle's own read before the copy — back.
        custodyFlap($this->disks[MediaDisks::PRIVATE], $path, away: 2, back: 3);

        expect(fn () => MediaCustody::settle(DB::connection(), $id))->toThrow(MediaCustodyFailure::class, 'it matches the recorded checksum');

        expect(file_get_contents($this->disks[MediaDisks::PRIVATE]->root().'/'.$path))->toBe(CUSTODY_PNG)
            ->and(Storage::disk('public')->get($path))->toBe('changed by hand');
    });
});

/*
 * T19. Publication and compensation write beside the final path, read the copy back and rename it into place: the only
 * byte operations on the target are those two, so the final path never holds a copy still being written.
 */
it('never writes a copy at the final path', function (string $how): void {
    [$id, $path] = $how === 'publication'
        ? custodyFile(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => CUSTODY_PNG])
        : custodyFile('public', [MediaDisks::PRIVATE => CUSTODY_PNG]);

    if ($how === 'publication') {
        MediaCustody::publish(DB::connection(), [$id]);
    } else {
        MediaCustody::queue(DB::getDefaultConnection(), [$id]);
        MediaCustody::drain(DB::connection());
    }

    $partial = MediaBytes::partial($path);

    expect(array_values(array_filter(custodyByteOperations(), fn (string $operation): bool => str_contains($operation, ' public:'))))
        ->toBe(["writeStream public:{$partial}", "move public:{$partial} -> {$path}"])
        ->and(custodyCopies($path)['public'])->toBe($this->checksum);
})->with(['publication', 'compensation']);
