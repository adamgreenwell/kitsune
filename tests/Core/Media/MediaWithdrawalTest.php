<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\DeadlockException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\QueryException;
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
use Kitsune\Core\Media\MediaWithdrawalRefused;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\AuditorStandIn;
use Kitsune\Core\Tests\Fixtures\RefusingDisk;

/*
 * A trashed file leaves the web before the trash commits; a restored one is published after — ADR-042 decision 5
 * (T23-T37, T39).
 *
 * ⚠️ FROM THE DISKS AND THE ROW AS THEY ARE AFTERWARDS. Every case reads what each disk holds at the path, by hash,
 * and what the row names — so a refusal that happened for some other reason, or a copy that "succeeded" onto the
 * wrong disk, cannot pass for the outcome the case is about. Every disk is a `RefusingDisk`, so a failure is one
 * the test chose, at the operation it chose.
 */

const WITHDRAWN_PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\x0f\0\0\x01\x01\0\x05\x18\xd8N\0\0\0\0IEND\xaeB`\x82";

beforeEach(function (): void {
    $this->roots = [];
    $this->disks = [];
    $this->disk = function (string $name, array $config = []): RefusingDisk {
        $root = sys_get_temp_dir().'/kitsune-withdrawal-'.$name.'-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        $this->roots[] = $root;

        if ($config !== []) {
            config(["filesystems.disks.{$name}" => ['driver' => 'local', 'root' => $root, ...$config]]);
        }

        return $this->disks[$name] = RefusingDisk::install($name, $root);
    };

    ($this->disk)('public');
    ($this->disk)(MediaDisks::PRIVATE);

    $this->org = Org::create(['slug' => 'withdrawal', 'name' => 'Withdrawal']);
    app(Context::class)->setOrg($this->org);
    app(Context::class)->setSite(Site::create(['handle' => 'main', 'slug' => 'withdrawal-main', 'name' => 'Main', 'locale' => 'en']));
    $this->image = EntryType::create(['org_id' => $this->org->id, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);
    $this->article = EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);

    $this->checksum = hash('sha256', WITHDRAWN_PNG);
});

afterEach(function (): void {
    app(Context::class)->forget();

    foreach ($this->roots as $root) {
        exec('chmod -R u+rwx '.escapeshellarg($root).' 2>/dev/null; rm -rf '.escapeshellarg($root));
    }
});

/** A stored file, and its path. The log starts empty after it. */
function withdrawable(string $visibility = 'public'): array
{
    $source = tempnam(sys_get_temp_dir(), 'kitsune-withdrawal-');
    file_put_contents($source, WITHDRAWN_PNG);
    $entry = MediaLibrary::store($source, 'photo.png', test()->image, $visibility);
    unlink($source);

    RefusingDisk::forgetLog();

    return [$entry, (string) DB::table('media_files')->where('entry_id', $entry->id)->value('path')];
}

/** What each disk holds at the path: the bytes' hash, or null. @return array<string, ?string> */
function heldAt(string $path, array $disks = ['public', MediaDisks::PRIVATE]): array
{
    $held = [];

    foreach ($disks as $disk) {
        $file = Storage::disk($disk)->path($path);
        $held[$disk] = is_file($file) ? hash_file('sha256', $file) : null;
    }

    return $held;
}

function namedDisk(Entry $entry): string
{
    return (string) DB::table('media_files')->where('entry_id', $entry->id)->value('disk');
}

function isTrashed(Entry $entry): bool
{
    return DB::table('entries')->where('id', $entry->id)->value('deleted_at') !== null;
}

/** @return list<string> "event disk:path" for each operation that changed bytes */
function bytesChanged(): array
{
    return array_values(array_map(
        static fn (array $entry): string => $entry['event'].' '.$entry['disk'].':'.$entry['path'],
        array_filter(RefusingDisk::$log, static fn (array $entry): bool => $entry['bytes']),
    ));
}

/** Run a write expected to be refused, and return the refusal. */
function refusedBy(Closure $write): MediaWithdrawalRefused
{
    try {
        $write();
    } catch (MediaWithdrawalRefused $refused) {
        return $refused;
    }

    throw new RuntimeException('The write was not refused.');
}

/*
 * T23. The spellings of `deleted_at` the builder accepts all withdraw — T3's controls, so a refusal that caught every
 * qualified key would fail here.
 */
it('withdraws through every spelling of deleted_at it accepts', function (string $spelling): void {
    if ($spelling === 'in capitals' && DB::connection()->getDriverName() === 'pgsql') {
        $this->markTestSkipped('PostgreSQL has no column by another spelling');
    }

    [$entry, $path] = withdrawable();
    $parent = Entry::create(['entry_type_id' => $this->article->id, 'title' => 'Parent', 'slug' => 'parent']);
    DB::table('entries')->where('id', $entry->id)->update(['origin_id' => $parent->id]);

    match ($spelling) {
        'qualified, through a join' => Entry::query()->join('entries as p', 'p.id', '=', 'entries.origin_id')
            ->whereKey($entry->id)->update(['entries.deleted_at' => now()]),
        'in capitals' => Entry::query()->whereKey($entry->id)->update(['DELETED_AT' => now()]),
        'as an expression' => Entry::query()->whereKey($entry->id)->update(['deleted_at' => DB::raw('CURRENT_TIMESTAMP')]),
    };

    expect(isTrashed($entry))->toBeTrue()
        ->and(heldAt($path))->toBe(['public' => null, MediaDisks::PRIVATE => $this->checksum])
        ->and(namedDisk($entry))->toBe(MediaDisks::PRIVATE);
})->with(['qualified, through a join', 'in capitals', 'as an expression']);

/*
 * T24. A bulk trash is all or nothing: the third file's copy fails, and the two already moved are put back.
 */
/** Put back, then cleaned up: the private copies the moved files left are removed once the public ones verify. */
it('puts back every file a refused bulk trash had already moved', function (): void {
    $files = [withdrawable(), withdrawable(), withdrawable()];
    RefusingDisk::forgetLog();

    // Each withdrawal writes a partial and renames it into place: the third write is the fifth operation from here.
    $private = $this->disks[MediaDisks::PRIVATE];
    $private->onOperation(5, function () use ($private): void {
        $private->failWrites = true;
    });

    $refused = refusedBy(fn () => Entry::query()->whereKey(array_map(fn (array $file): int => $file[0]->id, $files))->delete());

    expect($refused->reason)->toBe(MediaWithdrawalRefused::COPY_FAILED)
        ->and($refused->entryId)->toBe($files[2][0]->id);

    foreach ($files as $i => [$entry, $path]) {
        expect(isTrashed($entry))->toBeFalse()
            ->and(namedDisk($entry))->toBe('public')
            ->and(heldAt($path))->toBe(['public' => $this->checksum, MediaDisks::PRIVATE => null]);
    }

    expect(is_file(Storage::disk(MediaDisks::PRIVATE)->path(MediaBytes::partial($files[2][1]))))->toBeFalse();
});

/*
 * T25. An exception after the moves — the audit row's, here — rolls the trash back, and the file comes back.
 */
it('puts the file back when the write fails after moving it', function (): void {
    [$entry, $path] = withdrawable();
    AuditorStandIn::install()->throwOnce(new RuntimeException('the audit row could not be written'));

    expect(fn () => $entry->delete())->toThrow(RuntimeException::class, 'the audit row could not be written');

    expect(isTrashed($entry))->toBeFalse()
        ->and(namedDisk($entry))->toBe('public')
        ->and(heldAt($path)['public'])->toBe($this->checksum);
});

/*
 * T26. A public copy that cannot be removed refuses the trash: a committed trash never leaves its file public.
 */
it('refuses a trash whose public copy cannot be removed', function (): void {
    [$entry, $path] = withdrawable();
    $this->disks['public']->failDeletes = true;

    $refused = refusedBy(fn () => $entry->delete());

    expect($refused->reason)->toBe(MediaWithdrawalRefused::DELETE_FAILED)
        ->and($refused->disk)->toBe('public')
        ->and($refused->getMessage())->not->toContain($path)
        ->and(isTrashed($entry))->toBeFalse()
        ->and(namedDisk($entry))->toBe('public')
        ->and(heldAt($path))->toBe(['public' => $this->checksum, MediaDisks::PRIVATE => null]);
});

/** A public disk that says it deleted the copy and kept it: the trash is refused, not committed with its file public. */
it('refuses a trash whose public copy is reported gone and is not', function (): void {
    [$entry, $path] = withdrawable();
    $this->disks['public']->keepOnDelete = true;

    $refused = refusedBy(fn () => $entry->delete());

    expect($refused->reason)->toBe(MediaWithdrawalRefused::DELETE_FAILED)
        ->and(isTrashed($entry))->toBeFalse()
        ->and(heldAt($path)['public'])->toBe($this->checksum);
});

/*
 * T27. A copy counts only once read back with the right hash.
 */
it('replaces a truncated private copy with a verified one', function (): void {
    [$entry, $path] = withdrawable();
    Storage::disk(MediaDisks::PRIVATE)->put($path, substr(WITHDRAWN_PNG, 0, 10));

    $entry->delete();

    expect(heldAt($path))->toBe(['public' => null, MediaDisks::PRIVATE => $this->checksum]);
});

it('refuses a trash whose private copy comes out short, and keeps the public one', function (): void {
    [$entry, $path] = withdrawable();
    $this->disks[MediaDisks::PRIVATE]->truncateWritesTo = 10;

    $refused = refusedBy(fn () => $entry->delete());

    expect($refused->reason)->toBe(MediaWithdrawalRefused::COPY_FAILED)
        ->and(isTrashed($entry))->toBeFalse()
        ->and(heldAt($path))->toBe(['public' => $this->checksum, MediaDisks::PRIVATE => null])
        ->and(is_file(Storage::disk(MediaDisks::PRIVATE)->path(MediaBytes::partial($path))))->toBeFalse();
});

/*
 * T28. A copy that cannot be read is never taken for absent: that would keep the public copy's bytes over the match.
 */
it('refuses a trash when a copy cannot be read, and changes nothing', function (string $how): void {
    if ($how === 'unreadable by its mode' && function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('root reads a file whatever its mode');
    }

    [$entry, $path] = withdrawable();
    Storage::disk(MediaDisks::PRIVATE)->put($path, WITHDRAWN_PNG);
    Storage::disk('public')->put($path, 'changed by hand');

    if ($how === 'refused by the disk') {
        $this->disks[MediaDisks::PRIVATE]->unreadable = [$path];
    } else {
        chmod(Storage::disk(MediaDisks::PRIVATE)->path($path), 0o000);
    }

    $refused = refusedBy(fn () => $entry->delete());

    if ($how !== 'refused by the disk') {
        chmod(Storage::disk(MediaDisks::PRIVATE)->path($path), 0o644);
    }

    expect($refused->reason)->toBe(MediaWithdrawalRefused::UNREADABLE)
        ->and(isTrashed($entry))->toBeFalse()
        ->and(heldAt($path))->toBe(['public' => hash('sha256', 'changed by hand'), MediaDisks::PRIVATE => $this->checksum]);
})->with(['refused by the disk', 'unreadable by its mode']);

it('puts nothing back from a copy it cannot read, and says so', function (): void {
    [$entry, $path] = withdrawable();
    Storage::disk(MediaDisks::PRIVATE)->put($path, WITHDRAWN_PNG);
    Storage::disk('public')->delete($path);
    $this->disks[MediaDisks::PRIVATE]->unreadable = [$path];
    RefusingDisk::forgetLog();
    Log::spy();

    MediaCustody::queue(DB::getDefaultConnection(), [$entry->id]);
    MediaCustody::drain(DB::connection());

    expect(bytesChanged())->toBe([])
        ->and(heldAt($path))->toBe(['public' => null, MediaDisks::PRIVATE => $this->checksum]);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'could not be put back'))->once();
});

/*
 * T29. Withdrawal goes by path, not by the disk the row names: a public copy the row does not name leaves too, and
 * an only copy is moved rather than lost.
 */
it('withdraws a public copy the row does not name', function (bool $privateHolds): void {
    [$entry, $path] = withdrawable();
    DB::table('media_files')->where('entry_id', $entry->id)->update(['disk' => MediaDisks::PRIVATE]);

    if ($privateHolds) {
        Storage::disk(MediaDisks::PRIVATE)->put($path, WITHDRAWN_PNG);
    }

    $entry->delete();

    expect(heldAt($path))->toBe(['public' => null, MediaDisks::PRIVATE => $this->checksum]);
})->with(['the private disk holds it too' => true, 'the public copy is the only one' => false]);

/*
 * T31. Decision 2: no copy matches the recorded checksum, and the trash proceeds all the same — verified against the
 * kept copy's own hash, and logged.
 */
describe('when no copy matches the checksum', function (): void {
    it('withdraws the copy there is, and logs both hashes', function (): void {
        [$entry, $path] = withdrawable();
        DB::table('media_files')->where('entry_id', $entry->id)->update(['checksum' => str_repeat('0', 64)]);
        Log::spy();

        $entry->delete();

        expect(heldAt($path))->toBe(['public' => null, MediaDisks::PRIVATE => $this->checksum]);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, str_repeat('0', 64))
            && str_contains($message, $this->checksum))->once();
    });

    it('keeps a private copy that matches over a public one that does not', function (): void {
        [$entry, $path] = withdrawable();
        Storage::disk(MediaDisks::PRIVATE)->put($path, WITHDRAWN_PNG);
        Storage::disk('public')->put($path, 'changed by hand');

        $entry->delete();

        expect(heldAt($path))->toBe(['public' => null, MediaDisks::PRIVATE => $this->checksum]);
    });

    it('withdraws a public copy named nowhere, against its own hash', function (bool $auditFails): void {
        [$entry, $path] = withdrawable();
        DB::table('media_files')->where('entry_id', $entry->id)->update(['disk' => MediaDisks::PRIVATE]);
        Storage::disk('public')->put($path, 'changed by hand');
        Log::spy();

        if ($auditFails) {
            AuditorStandIn::install()->throwOnce(new RuntimeException('the audit row could not be written'));
            expect(fn () => $entry->delete())->toThrow(RuntimeException::class, 'the audit row could not be written');
        } else {
            $entry->delete();
        }

        $changed = hash('sha256', 'changed by hand');

        expect(heldAt($path))->toBe($auditFails
            ? ['public' => $changed, MediaDisks::PRIVATE => null]
            : ['public' => null, MediaDisks::PRIVATE => $changed]);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, $changed)
            && str_contains($message, $this->checksum))->atLeast()->once();

        if ($auditFails) {
            expect(namedDisk($entry))->toBe('public')->and(isTrashed($entry))->toBeFalse();
        }
    })->with(['committed' => false, 'rolled back and put back' => true]);
});

/*
 * T32. Decision 2b: copies differ and none matches — the one the row names wins, and nothing blocks the trash.
 */
describe('when the copies differ and none matches', function (): void {
    it('keeps the named public copy, and says what it overwrote', function (): void {
        [$entry, $path] = withdrawable();
        Storage::disk('public')->put($path, 'the named copy');
        Storage::disk(MediaDisks::PRIVATE)->put($path, 'another copy');
        Log::spy();

        $entry->delete();

        expect(heldAt($path))->toBe(['public' => null, MediaDisks::PRIVATE => hash('sha256', 'the named copy')]);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_starts_with($message, 'Media custody: overwriting')
            && str_contains($message, hash('sha256', 'another copy')))->once();
    });

    it('keeps the named private copy over a short public one', function (): void {
        [$entry, $path] = withdrawable();
        DB::table('media_files')->where('entry_id', $entry->id)->update(['disk' => MediaDisks::PRIVATE]);
        Storage::disk(MediaDisks::PRIVATE)->put($path, 'the named copy');
        Storage::disk('public')->put($path, 'short');
        Log::spy();

        $entry->delete();

        expect(heldAt($path))->toBe(['public' => null, MediaDisks::PRIVATE => hash('sha256', 'the named copy')]);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_starts_with($message, 'Media custody: removing')
            && str_contains($message, hash('sha256', 'short')))->once();
    });
});

/*
 * T33. Every door that writes `deleted_at` withdraws or publishes, and the candidate filter leaves live public files
 * alone.
 */
it('withdraws through every door that trashes, and publishes through every door that restores', function (string $door): void {
    [$entry, $path] = withdrawable();
    $id = $entry->id;

    $restoring = str_starts_with($door, 'restore') || str_contains($door, 'restore');

    if ($restoring) {
        $entry->delete();
        $entry = Entry::withTrashed()->findOrFail($id);
    }

    match ($door) {
        'delete()' => $entry->delete(),
        'a save of deleted_at' => $entry->forceFill(['deleted_at' => now()])->save(),
        'a quiet save of deleted_at' => $entry->forceFill(['deleted_at' => now()])->saveQuietly(),
        'a bulk delete' => Entry::query()->whereKey($id)->delete(),
        'touch(deleted_at)' => Entry::query()->whereKey($id)->touch('deleted_at'),
        'a bulk delete inside the hatch' => Entry::withoutScopeBecause('a test of the custody doors', fn ($query) => $query->whereKey($id)->delete()),
        'restore()' => $entry->restore(),
        'restoreQuietly()' => $entry->restoreQuietly(),
        'a bulk restore' => Entry::withTrashed()->whereKey($id)->restore(),
        'restoreOrCreate()' => Entry::restoreOrCreate(['id' => $id], ['title' => 'unused']),
    };

    expect(isTrashed($entry))->toBe(! $restoring)
        ->and(heldAt($path)['public'])->toBe($restoring ? $this->checksum : null)
        ->and(heldAt($path)[MediaDisks::PRIVATE])->toBe($restoring ? null : $this->checksum)
        ->and(namedDisk($entry))->toBe($restoring ? 'public' : MediaDisks::PRIVATE);
})->with([
    'delete()', 'a save of deleted_at', 'a quiet save of deleted_at', 'a bulk delete', 'touch(deleted_at)',
    'a bulk delete inside the hatch', 'restore()', 'restoreQuietly()', 'a bulk restore', 'restoreOrCreate()',
]);

it('leaves a live public file alone in a bulk restore that includes it', function (): void {
    [$live, $livePath] = withdrawable();
    [$trashed] = withdrawable();
    $trashed->delete();
    RefusingDisk::forgetLog();

    Entry::withTrashed()->whereKey([$live->id, $trashed->id])->restore();

    expect(array_filter(RefusingDisk::$log, fn (array $entry): bool => $entry['path'] === $livePath || str_contains((string) $entry['path'], $livePath)))->toBe([])
        ->and(heldAt($livePath)['public'])->toBe($this->checksum)
        ->and(namedDisk($live))->toBe('public');
});

/*
 * T34. A private file on an unserved disk was never public: its trash asks no disk anything.
 */
it('touches no disk to trash a private file', function (): void {
    [$entry] = withdrawable('private');

    $entry->delete();

    expect(RefusingDisk::$log)->toBe([])
        ->and(namedDisk($entry))->toBe(MediaDisks::PRIVATE);
});

/*
 * T35. A restore publishes after its commit, and a publication that fails leaves the restore standing and says so
 * where the operator will look.
 */
describe('publication', function (): void {
    it('publishes a restored file, and then removes its private copy', function (bool $bulk): void {
        [$entry, $path] = withdrawable();
        $entry->delete();

        $bulk ? Entry::onlyTrashed()->whereKey($entry->id)->restore() : Entry::withTrashed()->findOrFail($entry->id)->restore();

        expect(isTrashed($entry))->toBeFalse()
            ->and(namedDisk($entry))->toBe('public')
            ->and(DB::table('media_files')->where('entry_id', $entry->id)->value('visibility'))->toBe('public')
            ->and(heldAt($path))->toBe(['public' => $this->checksum, MediaDisks::PRIVATE => null]);
    })->with(['the instance' => false, 'in bulk' => true]);

    it('publishes only once the restore commits', function (): void {
        [$entry, $path] = withdrawable();
        $entry->delete();

        DB::transaction(function () use ($entry, $path): void {
            Entry::withTrashed()->findOrFail($entry->id)->restore();

            expect(namedDisk($entry))->toBe(MediaDisks::PRIVATE)
                ->and(heldAt($path)['public'])->toBeNull();
        });

        expect(namedDisk($entry))->toBe('public')
            ->and(heldAt($path)['public'])->toBe($this->checksum);
    });

    it('keeps a restore whose publication fails, and lists it as awaiting publication', function (): void {
        [$entry, $path] = withdrawable();
        $entry->delete();
        $this->disks['public']->failWrites = true;
        Log::spy();

        expect(Entry::withTrashed()->findOrFail($entry->id)->restore())->toBeTrue();

        expect(isTrashed($entry))->toBeFalse()
            ->and(namedDisk($entry))->toBe(MediaDisks::PRIVATE);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, "entry {$entry->id}: publication failed")
            && str_contains($message, 'awaiting publication'))->once();

        Artisan::call('kitsune:media-prune');
        $output = Artisan::output();
        $heading = strpos($output, 'Awaiting publication');

        expect($heading)->not->toBeFalse()
            ->and(substr($output, (int) $heading))->toContain($path);
    });

    it('publishes nothing for an entry that is trashed', function (): void {
        [$entry, $path] = withdrawable();
        $entry->delete();

        MediaCustody::publish(DB::connection(), [$entry->id]);

        expect(heldAt($path)['public'])->toBeNull();
    });
});

/*
 * The third write: a live public file's private copy goes once the public one verifiably holds the same bytes, asked
 * again under the lock.
 */
describe('cleaning up after a publication', function (): void {
    /**
     * R21(ii), deterministically: the publication commits, a delete lands before the clean-up runs, and the clean-up
     * then deletes nothing — the file stays on the private disk, which the delete claims.
     */
    it('deletes nothing when the entry was trashed between the publication and its clean-up', function (): void {
        [$entry, $path] = withdrawable();
        $entry->delete();
        $trashedAgain = false;

        Event::listen(TransactionCommitted::class, function () use ($entry, &$trashedAgain): void {
            if ($trashedAgain || isTrashed($entry) || namedDisk($entry) !== 'public') {
                return;
            }

            $trashedAgain = true;
            Entry::query()->whereKey($entry->id)->delete();
        });

        Entry::withTrashed()->findOrFail($entry->id)->restore();

        expect($trashedAgain)->toBeTrue()
            ->and(isTrashed($entry))->toBeTrue()
            ->and(namedDisk($entry))->toBe(MediaDisks::PRIVATE)
            ->and(heldAt($path))->toBe(['public' => null, MediaDisks::PRIVATE => $this->checksum]);
    });

    it('leaves the private copy of an entry that is not live and public on the public disk', function (): void {
        [$entry, $path] = withdrawable();
        Storage::disk(MediaDisks::PRIVATE)->put($path, WITHDRAWN_PNG);
        // Trashed, still naming public with both copies: a file trashed before withdrawal existed.
        DB::table('entries')->where('id', $entry->id)->update(['deleted_at' => now()]);

        expect(MediaCustody::cleanUp(DB::connection(), $entry->id))->toBe(MediaCustody::UNCHANGED)
            ->and(heldAt($path))->toBe(['public' => $this->checksum, MediaDisks::PRIVATE => $this->checksum]);
    });

    it('keeps a private copy that differs from the published one, and says so', function (): void {
        [$entry, $path] = withdrawable();
        Storage::disk(MediaDisks::PRIVATE)->put($path, 'changed by hand');
        Log::spy();

        expect(MediaCustody::cleanUp(DB::connection(), $entry->id))->toBe(MediaCustody::UNCHANGED)
            ->and(heldAt($path)[MediaDisks::PRIVATE])->toBe(hash('sha256', 'changed by hand'));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'the private copy of')
            && str_contains($message, hash('sha256', 'changed by hand')))->once();
    });

    it('removes a private copy that matches the published one', function (): void {
        [$entry, $path] = withdrawable();
        Storage::disk(MediaDisks::PRIVATE)->put($path, WITHDRAWN_PNG);

        expect(MediaCustody::cleanUp(DB::connection(), $entry->id))->toBe(MediaCustody::SETTLED)
            ->and(heldAt($path))->toBe(['public' => $this->checksum, MediaDisks::PRIVATE => null]);
    });

    it('refuses to clean up inside an open transaction', function (): void {
        [$entry, $path] = withdrawable();
        Storage::disk(MediaDisks::PRIVATE)->put($path, WITHDRAWN_PNG);

        expect(fn () => DB::transaction(fn () => MediaCustody::cleanUp(DB::connection(), $entry->id)))
            ->toThrow(LogicException::class, 'inside an open transaction');

        expect(heldAt($path)[MediaDisks::PRIVATE])->toBe($this->checksum);
    });

    it('keeps a publication whose clean-up fails, and says the copy is kept', function (): void {
        [$entry, $path] = withdrawable();
        $entry->delete();
        $this->disks[MediaDisks::PRIVATE]->failDeletes = true;
        Log::spy();

        Entry::withTrashed()->findOrFail($entry->id)->restore();

        expect(namedDisk($entry))->toBe('public')
            ->and(heldAt($path))->toBe(['public' => $this->checksum, MediaDisks::PRIVATE => $this->checksum]);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'its private copy could not be removed'))->once();
    });
});

/*
 * T36. Disks that cannot keep the promise refuse before a byte moves, and a symlink cannot make a copy the file.
 */
describe('disks that cannot keep a trashed file private', function (): void {
    it('refuses while the private disk is the public one, and still trashes a private file', function (): void {
        [$public, $path] = withdrawable();
        [$private] = withdrawable('private');
        config(['kitsune.media.disks.private' => 'public']);

        expect(fn () => $public->delete())->toThrow(RuntimeException::class, 'share their media/ directory');

        expect(bytesChanged())->toBe([])
            ->and(isTrashed($public))->toBeFalse()
            ->and(heldAt($path)['public'])->toBe($this->checksum);

        // Under the same configuration: a private file on a disk nothing serves has nothing to withdraw.
        $private->delete();

        expect(isTrashed($private))->toBeTrue();
    });

    /*
     * T67(vi). A trash sets nothing aside: it refuses on any copy it cannot read and leaves the entry live, so nothing is
     * exposed by refusing. Only settle, taking an already-trashed file off the web, sets such a copy aside (Adam,
     * decision 6, 2026-09-25).
     */
    it('refuses to trash a file whose named copy cannot be read, and sets nothing aside', function (): void {
        [$entry, $path] = withdrawable();
        $local = ($this->disk)('local', ['visibility' => 'private']);
        Storage::disk('local')->put($path, 'stale');
        DB::table('media_files')->where('entry_id', $entry->id)->update(['disk' => 'local']);
        $local->unreadable = [$path];
        RefusingDisk::forgetLog();

        expect(fn () => $entry->delete())->toThrow(MediaWithdrawalRefused::class, 'a copy could not be read on');

        expect(bytesChanged())->toBe([])
            ->and(isTrashed($entry))->toBeFalse()
            ->and(heldAt($path, ['public'])['public'])->toBe($this->checksum);
    });

    /*
     * T62. One bucket and key prefix through two endpoints may be one store: a "copy" to the private disk could be the
     * public file itself. Refused as the configuration, before a byte moves (slice 5b).
     */
    it('refuses while the public and private disks are one bucket through two endpoints', function (): void {
        [$entry, $path] = withdrawable();
        config([
            'filesystems.disks.public' => ['driver' => 's3', 'bucket' => 'media', 'endpoint' => 'e', 'prefix' => 'site', 'url' => 'https://media.example.test'],
            'filesystems.disks.host-private' => ['driver' => 's3', 'bucket' => 'media', 'endpoint' => 'f', 'prefix' => 'site'],
            'kitsune.media.disks.private' => 'host-private',
        ]);

        expect(fn () => $entry->delete())->toThrow(MediaWithdrawalRefused::class, 'name one bucket through two endpoints');

        expect(bytesChanged())->toBe([])
            ->and(isTrashed($entry))->toBeFalse()
            ->and(heldAt($path, ['public'])['public'])->toBe($this->checksum);
    });

    it('refuses while the private disk is one the web serves', function (): void {
        [$entry, $path] = withdrawable();
        ($this->disk)('pub-serve', ['serve' => true, 'visibility' => 'public']);
        config(['kitsune.media.disks.private' => 'pub-serve']);
        RefusingDisk::forgetLog();

        expect(fn () => $entry->delete())->toThrow(RuntimeException::class, 'kitsune.media.disks.private');

        expect(bytesChanged())->toBe([])
            ->and(heldAt($path)['public'])->toBe($this->checksum);
    });

    it('refuses to take a copy onto the file itself through a symlink', function (): void {
        [$entry, $path] = withdrawable();
        $publicDir = dirname(Storage::disk('public')->path($path));
        $privateDir = dirname(Storage::disk(MediaDisks::PRIVATE)->path($path));
        @mkdir(dirname($privateDir), 0777, true);
        symlink($publicDir, $privateDir);

        $refused = refusedBy(fn () => $entry->delete());

        expect($refused->reason)->toBe(MediaWithdrawalRefused::COINCIDING)
            ->and(isTrashed($entry))->toBeFalse()
            ->and(hash_file('sha256', Storage::disk('public')->path($path)))->toBe($this->checksum);
    });
});

/*
 * T37. The move is recorded by the row's disk alone: no timestamp, no audit row for a media file.
 */
it('records the move by the disk alone', function (): void {
    [$entry] = withdrawable();

    $entry->delete();

    expect(DB::getSchemaBuilder()->hasColumn('media_files', 'updated_at'))->toBeFalse()
        ->and(DB::table('audit_log')->where('target_type', 'like', '%MediaFile%')->count())->toBe(0);
});

/*
 * T39. A trash inside a transaction that then rolls back comes back through the listener.
 */
it('puts the file back when an enclosing transaction rolls back', function (): void {
    [$entry, $path] = withdrawable();

    try {
        DB::transaction(function () use ($entry): void {
            $entry->delete();

            throw new RuntimeException('the enclosing work failed');
        });
    } catch (RuntimeException) {
    }

    expect(isTrashed($entry))->toBeFalse()
        ->and(namedDisk($entry))->toBe('public')
        ->and(heldAt($path))->toBe(['public' => $this->checksum, MediaDisks::PRIVATE => null]);
});

/*
 * T38. T7's shape around a trash: a lock-wait timeout in the nested write, which Laravel ends without `ROLLBACK TO`, and
 * a host that catches it and commits. The savepoint's rollback takes the trash with it, and the file comes back once
 * the host's transaction commits.
 */
it('puts the file back after a nested trash times out and the host commits', function (): void {
    [$entry, $path] = withdrawable();
    AuditorStandIn::install()->throwOnce(new QueryException(
        (string) DB::connection()->getName(),
        'insert into "audit_log"',
        [],
        new PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction'),
    ));

    $caught = null;

    DB::transaction(function () use ($entry, $path, &$caught): void {
        try {
            $entry->delete();
        } catch (DeadlockException $timeout) {
            $caught = $timeout;
        }

        // The host has not committed: nothing is put back yet.
        expect(heldAt($path)['public'])->toBeNull();
    });

    expect($caught)->toBeInstanceOf(DeadlockException::class)
        ->and(isTrashed($entry))->toBeFalse()
        ->and(namedDisk($entry))->toBe('public')
        ->and(heldAt($path))->toBe(['public' => $this->checksum, MediaDisks::PRIVATE => null]);
});

/*
 * T30. No copy stays on a served disk a row is moved off, whether the web serves it by a url, by `serve` with public
 * visibility, or through a scoped disk's parent — the three ways `MediaDisks::servedDisks()` knows.
 *
 * ⚠️ THE SCOPED DISK'S INSTANCE IS A `RefusingDisk` AT ITS PREFIXED ROOT, its configuration still `scoped`: building a
 * scoped disk needs Flysystem's path-prefixing package, which the root install does not carry, and what custody reads
 * of a scoped disk is its configuration.
 */
describe('a file on a disk the web serves', function (): void {
    beforeEach(function (): void {
        ($this->disk)('cdn-public', ['url' => 'https://cdn.test']);
        config(['kitsune.media.disks.public' => 'cdn-public']);
        $origin = ($this->disk)('cdn-origin', ['url' => 'https://old.test']);
        mkdir($origin->root().'/kitsune', 0777, true);
        config(['filesystems.disks.media-cdn' => ['driver' => 'scoped', 'disk' => 'cdn-origin', 'prefix' => 'kitsune']]);
        $this->disks['media-cdn'] = RefusingDisk::install('media-cdn', $origin->root().'/kitsune');
        ($this->disk)('pub-serve', ['serve' => true, 'visibility' => 'public']);

        $this->served = ['cdn-public', 'public', 'cdn-origin', 'media-cdn', 'pub-serve'];

        /** A file stored, then left on one disk by hand, the row naming it there — trashed or not. */
        $this->leftOn = function (string $disk, bool $trashed): array {
            [$entry, $path] = withdrawable();
            Storage::disk('cdn-public')->delete($path);
            Storage::disk($disk)->put($path, WITHDRAWN_PNG);
            DB::table('media_files')->where('entry_id', $entry->id)->update(['disk' => $disk]);
            DB::table('entries')->where('id', $entry->id)->update(['deleted_at' => $trashed ? now() : null]);
            RefusingDisk::forgetLog();

            return [$entry, $path];
        };
    });

    it('publishes a restored file onto the public disk, and off the disk it was left on', function (string $disk): void {
        [$entry, $path] = ($this->leftOn)($disk, true);

        Entry::withTrashed()->findOrFail($entry->id)->restore();

        expect(namedDisk($entry))->toBe('cdn-public')
            ->and(heldAt($path, ['cdn-public', $disk]))->toBe(['cdn-public' => $this->checksum, $disk => null]);
    })->with(['through a scoped disk' => 'media-cdn', 'served with public visibility' => 'pub-serve']);

    it('trashes a file left on a served disk onto the private disk, and off every served one', function (string $disk): void {
        [$entry, $path] = ($this->leftOn)($disk, true);

        Entry::withTrashed()->findOrFail($entry->id)->delete();

        expect(heldAt($path, [...$this->served, MediaDisks::PRIVATE]))->toBe([...array_fill_keys($this->served, null), MediaDisks::PRIVATE => $this->checksum])
            ->and(heldAt(MediaBytes::partial($path), $this->served))->toBe(array_fill_keys($this->served, null));
    })->with(['through a scoped disk' => 'media-cdn', 'served with public visibility' => 'pub-serve']);

    it('erases a trashed file left on a served disk from every disk', function (string $disk): void {
        [$entry, $path] = ($this->leftOn)($disk, true);

        Entry::withTrashed()->findOrFail($entry->id)->forceDelete();

        $every = [...$this->served, MediaDisks::PRIVATE];

        expect(heldAt($path, $every))->toBe(array_fill_keys($every, null))
            ->and(heldAt(MediaBytes::partial($path), $every))->toBe(array_fill_keys($every, null));
    })->with(['through a scoped disk' => 'media-cdn', 'served with public visibility' => 'pub-serve']);

    it('publishes a live and a trashed file left on a served disk, in one bulk restore', function (): void {
        [$live, $livePath] = ($this->leftOn)('media-cdn', false);
        [$trashed, $trashedPath] = ($this->leftOn)('media-cdn', true);

        Entry::withTrashed()->whereKey([$live->id, $trashed->id])->restore();

        foreach ([[$live, $livePath], [$trashed, $trashedPath]] as [$entry, $path]) {
            expect(namedDisk($entry))->toBe('cdn-public')
                ->and(heldAt($path, ['cdn-public', 'media-cdn']))->toBe(['cdn-public' => $this->checksum, 'media-cdn' => null]);
        }
    });

    it('withdraws a copy a former configuration left on a served disk no row names', function (string $former): void {
        [$entry, $path] = ($this->leftOn)(MediaDisks::PRIVATE, false);
        Storage::disk($former)->put($path, WITHDRAWN_PNG);

        $entry->delete();

        expect(heldAt($path, [$former, MediaDisks::PRIVATE]))->toBe([$former => null, MediaDisks::PRIVATE => $this->checksum]);
    })->with([
        'the old public disk' => 'public',
        'a disk served with public visibility' => 'pub-serve',
        // Served only through its parent's url: a scoped entry's own is never read.
        'a scoped disk over a disk with a url' => 'media-cdn',
    ]);

    it('leaves a host\'s file on a served disk no row names', function (): void {
        ($this->leftOn)(MediaDisks::PRIVATE, false);
        Storage::disk('public')->put('media/host.png', 'the host\'s');

        Artisan::call('kitsune:media-prune', ['--force' => true]);

        expect(Artisan::output())->not->toContain('media/host.png')
            ->and(Storage::disk('public')->get('media/host.png'))->toBe('the host\'s');
    });
});

/*
 * T41-T43. An erasure: the entries locked, then their files, then the rows deleted, then every copy the web could serve
 * withdrawn, then the commit — and what is left removed after it, asked again under the lock.
 */
describe('erasure', function (): void {
    /** The statements, the commits and the disks' byte operations, in one timeline. @return list<string> */
    function erasureTimeline(Closure $erase): array
    {
        DB::listen(fn (QueryExecuted $query) => RefusingDisk::note('sql', strtolower($query->sql)));
        Event::listen(TransactionCommitted::class, fn () => RefusingDisk::note('committed'));
        RefusingDisk::forgetLog();

        $erase();

        return array_map(
            static fn (array $entry): string => $entry['event'] === 'sql' ? 'sql '.$entry['path'] : ($entry['bytes'] ? 'bytes '.$entry['disk'] : $entry['event']),
            array_values(array_filter(RefusingDisk::$log, static fn (array $entry): bool => in_array($entry['event'], ['sql', 'committed'], true) || $entry['bytes'])),
        );
    }

    /** The index of the first line matching the pattern at or after $from. */
    function firstIn(array $timeline, string $pattern, int $from = 0): int
    {
        foreach ($timeline as $i => $line) {
            if ($i >= $from && preg_match($pattern, $line) === 1) {
                return $i;
            }
        }

        throw new RuntimeException("Nothing in the timeline matches {$pattern}.");
    }

    it('locks, deletes, withdraws and commits in that order, and disposes after', function (): void {
        [$entry, $path] = withdrawable();
        $locks = DB::connection()->getDriverName() === 'sqlite' ? '' : ' for update';

        $timeline = erasureTimeline(fn () => $entry->forceDelete());

        $entries = firstIn($timeline, '/^sql select .*from .entries.*'.$locks.'/');
        $files = firstIn($timeline, '/^sql select .*from .media_files.*'.$locks.'/', $entries + 1);
        $delete = firstIn($timeline, '/^sql delete from .entries./', $files + 1);
        $withdrawn = firstIn($timeline, '/^bytes /', $delete + 1);
        $committed = firstIn($timeline, '/^committed$/', $withdrawn + 1);
        $disposed = firstIn($timeline, '/^bytes '.MediaDisks::PRIVATE.'$/', $committed + 1);

        expect([$entries, $files, $delete, $withdrawn, $committed, $disposed])->toBe(array_values(array_unique([$entries, $files, $delete, $withdrawn, $committed, $disposed])))
            ->and(array_filter(array_slice($timeline, 0, $delete), fn (string $line): bool => str_starts_with($line, 'bytes ')))->toBe([])
            ->and(heldAt($path))->toBe(['public' => null, MediaDisks::PRIVATE => null])
            ->and(DB::table('entries')->where('id', $entry->id)->exists())->toBeFalse();
    });

    it('refuses an erasure whose withdrawal cannot finish, and keeps the rows', function (): void {
        [$entry, $path] = withdrawable();
        $this->disks[MediaDisks::PRIVATE]->failWrites = true;
        Log::spy();

        $refused = refusedBy(fn () => $entry->forceDelete());

        // Its disposal ran from the failure path, found the rows, and expected them.
        Log::shouldNotHaveReceived('warning', [Mockery::on(fn (string $message): bool => str_contains($message, 'rows are still there'))]);

        expect($refused->reason)->toBe(MediaWithdrawalRefused::COPY_FAILED)
            ->and($refused->getMessage())->toStartWith("Refusing to erase entry {$entry->id}")
            ->and(DB::table('entries')->where('id', $entry->id)->exists())->toBeTrue()
            ->and(DB::table('media_files')->where('entry_id', $entry->id)->exists())->toBeTrue()
            ->and(heldAt($path)['public'])->toBe($this->checksum);
    });

    it('completes an erasure whose private copy cannot be removed, and says where it is', function (): void {
        [$entry, $path] = withdrawable();
        $this->disks[MediaDisks::PRIVATE]->failDeletes = true;
        Log::spy();

        $entry->forceDelete();

        expect(DB::table('entries')->where('id', $entry->id)->exists())->toBeFalse()
            ->and(heldAt($path)['public'])->toBeNull();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, '['.MediaDisks::PRIVATE.':'.$path.']'))->atLeast()->once();
    });

    /** A private file on `local` with a stray public copy: withdrawal takes the stray off the web before the commit. */
    it('withdraws a stray public copy of a private file before the erasure commits', function (): void {
        ($this->disk)('local');
        [$entry, $path] = withdrawable('private');
        Storage::disk('local')->put($path, WITHDRAWN_PNG);
        Storage::disk(MediaDisks::PRIVATE)->delete($path);
        Storage::disk('public')->put($path, WITHDRAWN_PNG);
        DB::table('media_files')->where('entry_id', $entry->id)->update(['disk' => 'local']);
        Event::listen(TransactionCommitted::class, fn () => $this->disks['public']->failDeletes = true);

        $entry->forceDelete();

        expect(heldAt($path, ['public', 'local', MediaDisks::PRIVATE]))->toBe(['public' => null, 'local' => null, MediaDisks::PRIVATE => null]);
    });

    it('disposes of every disk that could hold the path, and builds none that holds nothing', function (): void {
        ($this->disk)('host-private');
        config(['kitsune.media.disks.private' => 'host-private']);
        ($this->disk)('cdn', ['url' => 'https://cdn.test']);
        ($this->disk)('named');
        $ghost = sys_get_temp_dir().'/kitsune-withdrawal-ghost-'.bin2hex(random_bytes(4));
        config(['filesystems.disks.ghost' => ['driver' => 'local', 'root' => $ghost, 'url' => 'https://ghost.test']]);

        $path = 'media/1/2026/09/gone.png';
        $disks = ['public', MediaDisks::PRIVATE, 'host-private', 'cdn', 'named'];

        foreach ($disks as $disk) {
            Storage::disk($disk)->put($path, 'a copy');
            Storage::disk($disk)->put(MediaBytes::partial($path), 'half');
        }

        expect(MediaDisposal::remove(DB::connection(), [['entry_id' => 999_999, 'disk' => 'named', 'path' => $path]]))->toBe(1)
            ->and(heldAt($path, $disks))->toBe(array_fill_keys($disks, null))
            ->and(heldAt(MediaBytes::partial($path), $disks))->toBe(array_fill_keys($disks, null))
            ->and(file_exists($ghost))->toBeFalse();
    });

    it('keeps the bytes of an erasure that did not commit, and says so only when told it had', function (): void {
        [$entry, $path] = withdrawable();
        Log::spy();

        $files = [['entry_id' => (int) $entry->id, 'disk' => 'public', 'path' => $path]];

        expect(MediaDisposal::remove(DB::connection(), $files, committed: false))->toBe(0)
            ->and(MediaDisposal::remove(DB::connection(), $files))->toBe(0)
            ->and(heldAt($path)['public'])->toBe($this->checksum);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, "entry {$entry->id}")
            && str_contains($message, 'rows are still there'))->once();
    });

    it('refuses to dispose of anything inside an open transaction', function (): void {
        $path = 'media/1/2026/09/gone.png';
        Storage::disk('public')->put($path, 'a copy');

        expect(fn () => DB::transaction(fn () => MediaDisposal::remove(DB::connection(), [['entry_id' => 999_999, 'disk' => 'public', 'path' => $path]])))
            ->toThrow(LogicException::class, 'inside an open transaction');

        expect(Storage::disk('public')->exists($path))->toBeTrue();
    });

    it('keeps a file another row still names', function (): void {
        [, $path] = withdrawable();
        Log::spy();

        expect(MediaDisposal::remove(DB::connection(), [['entry_id' => 999_999, 'disk' => 'public', 'path' => $path]]))->toBe(0)
            ->and(heldAt($path)['public'])->toBe($this->checksum);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'another media_files row names the same path'))->once();
    });

    /** Decisions 2 and 2b keep an erasure possible when no copy matches the recorded checksum. */
    it('erases a file whose copies match nothing it recorded', function (string $fixture): void {
        [$entry, $path] = withdrawable();

        if ($fixture === 'the checksum changed') {
            DB::table('media_files')->where('entry_id', $entry->id)->update(['checksum' => str_repeat('0', 64)]);
        } else {
            DB::table('media_files')->where('entry_id', $entry->id)->update(['disk' => MediaDisks::PRIVATE]);
            Storage::disk(MediaDisks::PRIVATE)->put($path, 'the named copy');
            Storage::disk('public')->put($path, 'short');
        }

        $entry->forceDelete();

        expect(heldAt($path))->toBe(['public' => null, MediaDisks::PRIVATE => null])
            ->and(heldAt(MediaBytes::partial($path)))->toBe(['public' => null, MediaDisks::PRIVATE => null]);
    })->with(['the checksum changed', 'the named copy differs from a short public one']);
});
