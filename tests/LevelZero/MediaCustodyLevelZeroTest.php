<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Media\MediaBytes;
use Kitsune\Core\Media\MediaCustody;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaDisposal;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\AuditorStandIn;
use Kitsune\Core\Tests\Fixtures\FailingCommitPdo;
use Kitsune\Core\Tests\Fixtures\RefusingDisk;

/*
 * Custody at a real level 0 — ADR-042 decision 5 (T44-T51; slice 5b: T74, T91).
 *
 * ⚠️ EVERY CASE HERE IS A MOMENT ONLY THE OUTERMOST TRANSACTION REACHES: a COMMIT that fails before it lands or after,
 * a rollback that empties the transaction manager, callbacks that run after the outermost commit and nowhere else. Under
 * `RefreshDatabase` none of them happens, so the rest of the suite cannot see what these assert.
 */

const LEVEL_ZERO_PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\x0f\0\0\x01\x01\0\x05\x18\xd8N\0\0\0\0IEND\xaeB`\x82";

beforeEach(function (): void {
    $this->roots = [];
    $this->disks = [];

    foreach (['public', MediaDisks::PRIVATE] as $name) {
        $root = sys_get_temp_dir().'/kitsune-level0-'.$name.'-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        $this->roots[] = $root;
        $this->disks[$name] = RefusingDisk::install($name, $root);
    }

    $this->org = Org::create(['slug' => 'level-zero', 'name' => 'Level zero']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'level-zero-main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);
    $this->image = EntryType::create(['org_id' => $this->org->id, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);

    $this->checksum = hash('sha256', LEVEL_ZERO_PNG);
});

afterEach(function (): void {
    app(Context::class)->forget();

    // Absent when the harness skipped the test on a server engine before `beforeEach` ran.
    foreach ($this->roots ?? [] as $root) {
        exec('rm -rf '.escapeshellarg($root));
    }
});

/** A public file stored on the default connection, and its path. */
function levelZeroFile(): array
{
    $source = tempnam(sys_get_temp_dir(), 'kitsune-level0-');
    file_put_contents($source, LEVEL_ZERO_PNG);
    $entry = MediaLibrary::store($source, 'photo.png', test()->image, 'public');
    unlink($source);
    RefusingDisk::forgetLog();

    return [$entry, (string) DB::table('media_files')->where('entry_id', $entry->id)->value('path')];
}

/** @return array{public: ?string, kitsune-private: ?string} each disk's hash at the path */
function levelZeroHeld(string $path): array
{
    $held = [];

    foreach (['public', MediaDisks::PRIVATE] as $disk) {
        $file = Storage::disk($disk)->path($path);
        $held[$disk] = is_file($file) ? hash_file('sha256', $file) : null;
    }

    return $held;
}

/** The row as it stands: whether the entry is trashed, and the disk its file names; nulls when it is gone. */
function levelZeroRow(Entry $entry, string $connection = 'custody'): array
{
    return [
        'trashed' => ($deleted = DB::connection($connection)->table('entries')->where('id', $entry->id)->first()) === null ? null : $deleted->deleted_at !== null,
        'disk' => DB::connection($connection)->table('media_files')->where('entry_id', $entry->id)->value('disk'),
    ];
}

/*
 * T44. A trash whose outermost COMMIT fails before it lands: the stale record the manager was never told about is
 * dropped — work registered inside the write never runs — and the file comes back.
 */
it('puts the file back after a trash whose COMMIT failed, and runs nothing registered inside it', function (): void {
    [$entry, $path] = levelZeroFile();
    $pdo = FailingCommitPdo::installOn(DB::connection(), $this->custodyFile);
    $ran = false;

    AuditorStandIn::install()->beforeRecording(function () use (&$ran): void {
        DB::afterCommit(function () use (&$ran): void {
            $ran = true;
        });
    });

    $pdo->failNextCommit = 'before';

    expect(fn () => $entry->delete())->toThrow(PDOException::class, 'database is locked');

    expect(levelZeroRow($entry))->toBe(['trashed' => false, 'disk' => 'public'])
        ->and(levelZeroHeld($path))->toBe(['public' => $this->checksum, MediaDisks::PRIVATE => null])
        ->and($pdo->inTransaction())->toBeFalse();

    DB::transaction(fn () => DB::table('orgs')->where('id', $this->org->id)->update(['name' => 'Later']));

    expect($ran)->toBeFalse();
});

/*
 * T45. An erasure whose own COMMIT failed and rolled back keeps its rows and its file, and disposal, run from the
 * failure path, finds the rows and says nothing.
 */
it('keeps the rows and the file of an erasure whose COMMIT failed', function (): void {
    [$entry, $path] = levelZeroFile();
    $pdo = FailingCommitPdo::installOn(DB::connection(), $this->custodyFile);
    $pdo->failNextCommit = 'before';
    Log::spy();

    expect(fn () => $entry->forceDelete())->toThrow(PDOException::class, 'database is locked');

    expect(levelZeroRow($entry))->toBe(['trashed' => false, 'disk' => 'public'])
        ->and(levelZeroHeld($path))->toBe(['public' => $this->checksum, MediaDisks::PRIVATE => null]);
    Log::shouldNotHaveReceived('warning', [Mockery::on(fn (string $message): bool => str_contains($message, 'rows are still there'))]);
});

/*
 * T46. A COMMIT that landed and reported failure: nothing is moved back onto the web, and a landed erasure leaves no
 * bytes behind.
 */
it('moves nothing back after a trash whose COMMIT landed and reported failure', function (): void {
    [$entry, $path] = levelZeroFile();
    $pdo = FailingCommitPdo::installOn(DB::connection(), $this->custodyFile);
    $pdo->failNextCommit = 'after';

    expect(fn () => $entry->delete())->toThrow(PDOException::class, 'database is locked');

    expect(levelZeroRow($entry))->toBe(['trashed' => true, 'disk' => MediaDisks::PRIVATE])
        ->and(levelZeroHeld($path))->toBe(['public' => null, MediaDisks::PRIVATE => $this->checksum]);
});

it('leaves no bytes after an erasure whose COMMIT landed and reported failure', function (): void {
    [$entry, $path] = levelZeroFile();
    $pdo = FailingCommitPdo::installOn(DB::connection(), $this->custodyFile);
    $pdo->failNextCommit = 'after';

    expect(fn () => $entry->forceDelete())->toThrow(PDOException::class, 'database is locked');

    expect(levelZeroRow($entry))->toBe(['trashed' => null, 'disk' => null])
        ->and(levelZeroHeld($path))->toBe(['public' => null, MediaDisks::PRIVATE => null])
        ->and(levelZeroHeld(MediaBytes::partial($path)))->toBe(['public' => null, MediaDisks::PRIVATE => null]);
});

/*
 * T47. A real busy COMMIT: a reader holds SQLite's shared lock from the trash's first byte write until the file is being
 * put back. The engine's transaction is rolled back, the file comes back, and the connection keeps working.
 */
it('recovers a trash whose COMMIT was busy, puts the file back, and keeps the connection usable', function (): void {
    [$entry, $path] = levelZeroFile();
    $pdo = DB::connection()->getPdo();
    $pdo->exec('PRAGMA busy_timeout = 50');

    expect((string) $pdo->query('PRAGMA journal_mode')->fetchColumn())->toBe('delete')
        ->and((int) $pdo->query('PRAGMA busy_timeout')->fetchColumn())->toBe(50);

    $reader = new PDO('sqlite:'.$this->custodyFile);
    $this->disks[MediaDisks::PRIVATE]->onOperation(1, function () use ($reader): void {
        $reader->exec('BEGIN');
        $reader->query('select count(*) from entries')->fetchColumn();
    });
    // The withdrawal deletes the public copy first; the second write there is the file being put back.
    $this->disks['public']->onOperation(2, fn () => $reader->exec('COMMIT'));

    expect(fn () => $entry->delete())->toThrow(PDOException::class, 'database is locked');

    expect($pdo->inTransaction())->toBeFalse()
        ->and(levelZeroRow($entry))->toBe(['trashed' => false, 'disk' => 'public'])
        ->and(levelZeroHeld($path)['public'])->toBe($this->checksum);

    DB::transaction(fn () => DB::table('orgs')->where('id', $this->org->id)->update(['name' => 'After']));

    expect(DB::table('orgs')->where('id', $this->org->id)->value('name'))->toBe('After');
});

/*
 * T48. An erasure inside a host transaction whose own COMMIT fails: Laravel never tells the manager, so the erasure's
 * disposal runs at the next commit anywhere — and finds the rows still there, keeps the only copy, says so, and prune
 * keeps it too. ~~Nothing puts the file back: that is the residue ADR-042 accepts and lists.~~ kitsune:media-reconcile
 * puts it back, and prune then has nothing to say of it (slice 5b).
 */
it('keeps the only copy when a host\'s COMMIT around an erasure fails', function (): void {
    [$entry, $path] = levelZeroFile();
    $pdo = FailingCommitPdo::installOn(DB::connection(), $this->custodyFile);
    Log::spy();

    $pdo->failNextCommit = 'before';

    expect(fn () => DB::transaction(fn () => $entry->forceDelete()))->toThrow(PDOException::class, 'database is locked');

    DB::transaction(fn () => DB::table('orgs')->where('id', $this->org->id)->update(['name' => 'Later']));

    expect(levelZeroRow($entry))->toBe(['trashed' => false, 'disk' => 'public'])
        ->and(levelZeroHeld($path))->toBe(['public' => null, MediaDisks::PRIVATE => $this->checksum]);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, "entry {$entry->id}")
        && str_contains($message, 'rows are still there'))->once();

    Artisan::call('kitsune:media-prune', ['--force' => true]);
    $output = Artisan::output();
    $heading = strpos($output, 'Extra copies');

    expect($heading)->not->toBeFalse()
        ->and(substr($output, (int) $heading))->toContain($path)
        ->and(substr($output, (int) $heading))->toContain('kept: the disk its row names does not hold the file')
        ->and(levelZeroHeld($path)[MediaDisks::PRIVATE])->toBe($this->checksum);

    expect(Artisan::call('kitsune:media-reconcile', ['--force' => true]))->toBe(0)
        ->and(levelZeroHeld($path))->toBe(['public' => $this->checksum, MediaDisks::PRIVATE => null]);

    Artisan::call('kitsune:media-prune');

    expect(Artisan::output())->not->toContain($path);
});

/*
 * T49. Custody runs on the write's own connection: every read, lock and row write on the second connection, and only
 * the audit row — which the auditor writes where it always does — on the default one.
 */
it('keeps a write\'s custody on the write\'s own connection', function (): void {
    foreach (['orgs', 'sites', 'entry_types'] as $table) {
        foreach (DB::table($table)->get() as $row) {
            DB::connection('secondary')->table($table)->insert((array) $row);
        }
    }

    DB::setDefaultConnection('secondary');
    [$entry, $path] = levelZeroFile();
    DB::setDefaultConnection('custody');

    $began = [];
    $statements = [];
    Event::listen(TransactionBeginning::class, function (TransactionBeginning $event) use (&$began): void {
        $began[] = $event->connectionName;
    });
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = [$query->connectionName, strtolower($query->sql)];
    });

    Entry::on('secondary')->whereKey($entry->id)->delete();

    $custody = array_filter($statements, fn (array $statement): bool => (bool) preg_match('/\b(entries|media_files)\b/', $statement[1]) && ! str_contains($statement[1], 'audit_log'));

    expect(levelZeroHeld($path))->toBe(['public' => null, MediaDisks::PRIVATE => $this->checksum])
        ->and(levelZeroRow($entry, 'secondary'))->toBe(['trashed' => true, 'disk' => MediaDisks::PRIVATE])
        ->and(array_values(array_unique($began)))->toBe(['secondary'])
        ->and(array_values(array_unique(array_column($custody, 0))))->toBe(['secondary'])
        ->and(array_values(array_unique(array_column(array_filter($statements, fn (array $statement): bool => str_contains($statement[1], 'insert into "audit_log"')), 0))))->toBe(['custody']);
});

/*
 * T50. A restore publishes after the outermost commit, never inside it — and a COMMIT that landed and reported failure
 * still publishes, from the failure path.
 */
describe('a restore at level 0', function (): void {
    beforeEach(function (): void {
        [$this->entry, $this->path] = levelZeroFile();
        $this->entry->delete();
        $this->trashed = Entry::withTrashed()->findOrFail($this->entry->id);
        RefusingDisk::forgetLog();
    });

    it('publishes nothing when the transaction around it rolls back', function (): void {
        try {
            DB::transaction(function (): void {
                $this->trashed->restore();

                throw new RuntimeException('the enclosing work failed');
            });
        } catch (RuntimeException) {
        }

        expect(levelZeroRow($this->entry))->toBe(['trashed' => true, 'disk' => MediaDisks::PRIVATE])
            ->and(levelZeroHeld($this->path)['public'])->toBeNull();
    });

    it('publishes once the transaction around it commits', function (): void {
        DB::transaction(fn () => $this->trashed->restore());

        expect(levelZeroRow($this->entry))->toBe(['trashed' => false, 'disk' => 'public'])
            ->and(levelZeroHeld($this->path)['public'])->toBe($this->checksum);
    });

    it('publishes from the failure path when its COMMIT landed and reported failure', function (): void {
        $pdo = FailingCommitPdo::installOn(DB::connection(), $this->custodyFile);
        $pdo->failNextCommit = 'after';

        expect(fn () => $this->trashed->restore())->toThrow(PDOException::class, 'database is locked');

        expect(levelZeroRow($this->entry))->toBe(['trashed' => false, 'disk' => 'public'])
            ->and(levelZeroHeld($this->path)['public'])->toBe($this->checksum);
    });
});

/*
 * T51. SQLite: every custody path holds the database's write lock before its first byte moves (Adam, 2026-09-24). At
 * that first byte operation a second connection asks for the write lock with no wait, and must be told the database is
 * busy. T74: `removeExtra()` and a forced reconcile join the dataset, and a read-only reconcile leaves the lock free
 * (slice 5b).
 */
describe('the write lock on SQLite', function (): void {
    /**
     * Arm a probe on the first byte operation of any of these disks; it records whether a rival connection to this
     * database file could take the write lock.
     *
     * @param  array<string, RefusingDisk>  $disks
     */
    function levelZeroProbe(string $file, array $disks, string $kind = 'byte'): Closure
    {
        $outcome = ['probed' => false, 'busy' => null];
        $probe = function () use (&$outcome, $file): void {
            if ($outcome['probed']) {
                return;
            }

            $outcome['probed'] = true;
            $rival = new PDO('sqlite:'.$file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $rival->exec('PRAGMA busy_timeout = 0');

            try {
                $rival->exec('BEGIN IMMEDIATE');
                $rival->exec('ROLLBACK');
                $outcome['busy'] = false;
            } catch (PDOException $busy) {
                $outcome['busy'] = str_contains($busy->getMessage(), 'locked') || str_contains($busy->getMessage(), 'busy');
            }
        };

        foreach ($disks as $disk) {
            $disk->onOperation(1, $probe, $kind);
        }

        return function () use (&$outcome): array {
            return $outcome;
        };
    }

    it('holds it through every path that moves bytes', function (string $path): void {
        [$entry, $filePath] = levelZeroFile();

        $arrange = match ($path) {
            'publication', 'drain' => fn () => $entry->delete(),
            'removeTemp' => fn () => Storage::disk(MediaDisks::PRIVATE)->put(MediaBytes::partial($filePath), 'half'),
            // Published, with the private copy the third write removes once it has checked the public one.
            'cleanUp', 'removeExtra' => fn () => Storage::disk(MediaDisks::PRIVATE)->put($filePath, LEVEL_ZERO_PNG),
            // A file trashed before withdrawal existed: still on the public disk its row names.
            'reconcile' => fn () => DB::table('entries')->where('id', $entry->id)->update(['deleted_at' => now()]),
            'removeOrphan' => fn () => Storage::disk(MediaDisks::PRIVATE)->put('media/orphan.png', 'bytes'),
            default => fn () => null,
        };
        $arrange();

        if ($path === 'publication') {
            // A restore that committed: live and public, the row still naming the private disk.
            DB::table('entries')->where('id', $entry->id)->update(['deleted_at' => null]);
        }

        if ($path === 'drain') {
            // A trash the database rolled back: the row names public again, and the bytes are on the private disk.
            DB::table('entries')->where('id', $entry->id)->update(['deleted_at' => null]);
            DB::table('media_files')->where('entry_id', $entry->id)->update(['disk' => 'public']);
        }

        if ($path === 'disposal') {
            DB::table('entries')->where('id', $entry->id)->delete();
        }

        RefusingDisk::forgetLog();
        $outcome = levelZeroProbe($this->custodyFile, $this->disks);

        match ($path) {
            'soft delete' => $entry->delete(),
            'force-delete' => $entry->forceDelete(),
            'publication' => MediaCustody::publish(DB::connection(), [$entry->id]),
            'drain' => (function () use ($entry): void {
                MediaCustody::queue('custody', [$entry->id]);
                MediaCustody::drain(DB::connection());
            })(),
            'removeOrphan' => MediaCustody::removeOrphan(DB::connection(), MediaDisks::PRIVATE, 'media/orphan.png'),
            'removeTemp' => MediaCustody::removeTemp(DB::connection(), $entry->id, MediaDisks::PRIVATE, MediaBytes::partial($filePath)),
            'disposal' => MediaDisposal::remove(DB::connection(), [['entry_id' => $entry->id, 'disk' => 'public', 'path' => $filePath]]),
            'cleanUp' => MediaCustody::cleanUp(DB::connection(), $entry->id),
            'removeExtra' => MediaCustody::removeExtra(DB::connection(), $entry->id, MediaDisks::PRIVATE),
            'reconcile' => Artisan::call('kitsune:media-reconcile', ['--force' => true]),
        };

        expect($outcome())->toBe(['probed' => true, 'busy' => true]);
    })->with(['soft delete', 'force-delete', 'publication', 'drain', 'removeOrphan', 'removeTemp', 'disposal', 'cleanUp', 'removeExtra', 'reconcile']);

    /** A read-only reconcile only asks whether each disk holds a path, and takes no lock while it does. */
    it('leaves it free while a read-only reconcile asks the disks', function (): void {
        [$entry] = levelZeroFile();
        DB::table('entries')->where('id', $entry->id)->update(['deleted_at' => now()]);
        RefusingDisk::forgetLog();
        $outcome = levelZeroProbe($this->custodyFile, $this->disks, 'any');

        Artisan::call('kitsune:media-reconcile');

        expect($outcome())->toBe(['probed' => true, 'busy' => false]);
    });

    /** The control: a transaction that only reads leaves the write lock to a rival. */
    it('leaves it free to a transaction that only reads', function (): void {
        $free = null;

        DB::transaction(function () use (&$free): void {
            DB::table('entries')->count();

            $rival = new PDO('sqlite:'.$this->custodyFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $rival->exec('PRAGMA busy_timeout = 0');
            $rival->exec('BEGIN IMMEDIATE');
            $rival->exec('ROLLBACK');
            $free = true;
        });

        expect($free)->toBeTrue();
    });
});

/*
 * T91. A forced reconcile whose COMMIT fails before it lands: the served copies went before it, so the trashed file is
 * off the web whatever the row says, the run fails, and the next run settles the row it left.
 */
it('takes a trashed file off the web though the reconcile\'s COMMIT failed, and settles it on the next run', function (): void {
    $root = sys_get_temp_dir().'/kitsune-level0-old-cdn-'.bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    $this->roots[] = $root;
    config(['filesystems.disks.old-cdn' => ['driver' => 'local', 'root' => $root, 'url' => 'https://cdn.example.test']]);
    $cdn = RefusingDisk::install('old-cdn', $root);
    [$entry, $path] = levelZeroFile();
    Storage::disk('old-cdn')->put($path, LEVEL_ZERO_PNG);
    DB::table('entries')->where('id', $entry->id)->update(['deleted_at' => now()]);
    $pdo = FailingCommitPdo::installOn(DB::connection(), $this->custodyFile);
    $pdo->failNextCommit = 'before';

    $exit = Artisan::call('kitsune:media-reconcile', ['--force' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('→ failed: ')
        ->and(levelZeroRow($entry))->toBe(['trashed' => true, 'disk' => 'public'])
        ->and(levelZeroHeld($path))->toBe(['public' => null, MediaDisks::PRIVATE => $this->checksum])
        ->and(is_file($cdn->root().'/'.$path))->toBeFalse();

    expect(Artisan::call('kitsune:media-reconcile', ['--force' => true]))->toBe(0)
        ->and(levelZeroRow($entry))->toBe(['trashed' => true, 'disk' => MediaDisks::PRIVATE]);
});
