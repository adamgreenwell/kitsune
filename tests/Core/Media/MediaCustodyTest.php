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
 * Custody of a file's bytes, asked directly — ADR-042 decision 5 (T14-T19).
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

    /** ⚠️ THE DESIGN'S DEFAULT, PINNED: the configured public disk before the private one, then the served, the target last. */
    it('keeps the first copy in the configured order when the copies differ, never the target\'s first', function (): void {
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
