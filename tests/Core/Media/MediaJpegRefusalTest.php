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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Media\MediaBytes;
use Kitsune\Core\Media\MediaCustody;
use Kitsune\Core\Media\MediaCustodyFailure;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Media\MediaLocation;
use Kitsune\Core\Media\MediaRefused;
use Kitsune\Core\Media\MediaVisibility;
use Kitsune\Core\Media\MediaVisibilityRefused;
use Kitsune\Core\Media\MediaWithdrawalRefused;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\AuditorStandIn;
use Kitsune\Core\Tests\Fixtures\LocatedJpeg;
use Kitsune\Core\Tests\Fixtures\RefusingDisk;

/*
 * A JPEG no copy of which matches its recorded checksum is not published — Adam, ADR-042 decision 37 (T184-T203).
 *
 * ⚠️ THE CHECKSUM A JPEG MADE PUBLIC RECORDS IS ITS STRIPPED BYTES'. Every fixture stores a located photo public, so
 * `MediaLibrary` strips it and records the stripped hash, then sets the row and the disks by hand: a copy that is the
 * located photo is one a backup put back over the private copy. Every disk is a `RefusingDisk`, so the assertions read
 * what each disk holds and every operation it saw.
 */

const REFUSAL_PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\x0f\0\0\x01\x01\0\x05\x18\xd8N\0\0\0\0IEND\xaeB`\x82";

beforeEach(function (): void {
    $this->roots = [];
    $this->disks = [];

    // `old-cdn` was once a public disk: a local one with a url, so the web serves it.
    foreach (['public', MediaDisks::PRIVATE, 'old-cdn'] as $name) {
        $root = sys_get_temp_dir().'/kitsune-refusal-'.$name.'-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        $this->roots[] = $root;
        $this->disks[$name] = RefusingDisk::install($name, $root);
    }

    config(['filesystems.disks.old-cdn' => ['driver' => 'local', 'root' => $this->disks['old-cdn']->root(), 'url' => 'https://cdn.example.test']]);

    $this->org = Org::create(['slug' => 'refusal', 'name' => 'Refusal']);
    app(Context::class)->setOrg($this->org);
    app(Context::class)->setSite(Site::create(['handle' => 'main', 'slug' => 'refusal-main', 'name' => 'Main', 'locale' => 'en']));
    $this->image = EntryType::create(['org_id' => $this->org->id, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);
    $this->located = LocatedJpeg::photo(true);
});

afterEach(function (): void {
    app(Context::class)->forget();

    foreach ($this->roots as $root) {
        exec('chmod -R u+rwx '.escapeshellarg($root).' 2>/dev/null; rm -rf '.escapeshellarg($root));
    }
});

/**
 * A located JPEG stored public — its stripped hash the row's checksum — then set by hand: the disk its row names, its
 * entry trashed or not, what its row calls it, and which disks hold which bytes. A copy given as `stripped` is the
 * stripped bytes the checksum records.
 *
 * @param  array<string, string>  $copies  disk => bytes
 * @return array{0: int, 1: string, 2: string} the entry's id, the file's path and its stripped bytes
 */
function refusalJpeg(string $named, array $copies, bool $trashed = false, string $name = 'photo.jpg', string $mime = 'image/jpeg', ?string $checksum = null): array
{
    $source = LocatedJpeg::file(test()->located, 'kitsune-refusal-source-');

    try {
        $entry = MediaLibrary::store($source, 'photo.jpg', test()->image, 'public');
    } finally {
        unlink($source);
    }

    $stored = (string) DB::table('media_files')->where('entry_id', $entry->id)->value('path');
    $stripped = (string) Storage::disk('public')->get($stored);
    Storage::disk('public')->delete($stored);

    // Another name for the file is the row's alone: written past the model, as an import's would be.
    $path = substr($stored, 0, -strlen('.jpg')).'.'.pathinfo($name, PATHINFO_EXTENSION);

    DB::table('media_files')->where('entry_id', $entry->id)->update([
        'disk' => $named,
        'path' => $path,
        'mime' => $mime,
        ...($checksum === null ? [] : ['checksum' => $checksum]),
    ]);
    DB::table('entries')->where('id', $entry->id)->update(['deleted_at' => $trashed ? now() : null]);

    foreach ($copies as $disk => $bytes) {
        Storage::disk($disk)->put($path, $bytes === 'stripped' ? $stripped : $bytes);
    }

    RefusingDisk::forgetLog();

    return [(int) $entry->id, $path, $stripped];
}

/** What each disk holds at the path: its bytes' hash, or null. @return array<string, ?string> */
function refusalCopies(string $path): array
{
    $copies = [];

    foreach (['public', MediaDisks::PRIVATE, 'old-cdn'] as $disk) {
        $copies[$disk] = Storage::disk($disk)->exists($path) ? hash('sha256', (string) Storage::disk($disk)->get($path)) : null;
    }

    return $copies;
}

/** @return list<string> "event disk:path" for every operation that changed bytes at the path itself, partials aside */
function refusalWrites(string $path): array
{
    return array_values(array_map(
        static fn (array $entry): string => $entry['event'].' '.$entry['disk'].':'.$entry['path'],
        array_filter(RefusingDisk::$log, static fn (array $entry): bool => $entry['bytes']
            && ! str_contains((string) $entry['path'], MediaBytes::PARTIAL)),
    ));
}

/** How many times one disk's copy at the path was opened. */
function refusalOpened(string $disk, string $path): int
{
    return count(array_filter(RefusingDisk::$log, static fn (array $entry): bool => $entry['event'] === 'readStream'
        && $entry['disk'] === $disk && $entry['path'] === $path));
}

/** @return array{disk: string, visibility: string, checksum: string} */
function refusalRow(int $entryId): array
{
    $row = DB::table('media_files')->where('entry_id', $entryId)->first();

    return ['disk' => (string) $row->disk, 'visibility' => (string) $row->visibility, 'checksum' => (string) $row->checksum];
}

/** The refusal's own warning, by what every one of them says. */
function refusalLine(string $message, int $entryId): bool
{
    return str_starts_with($message, "Media custody, entry {$entryId}: refusing to publish [")
        && str_contains($message, 'makes it private, then public, in the admin, which removes its location, records its copy and publishes it, or says what stands in the way')
        && str_contains($message, 'kitsune:media-reconcile --entry='.$entryId.' --force publishes it (ADR-042 decision 37).');
}

/*
 * T184-T197. Custody's own step: a JPEG settle would publish from a copy that does not match its checksum is settled as
 * a private file is instead, and every other file as decision 5 has it.
 */
describe('settle', function (): void {
    it('refuses to publish it, moving nothing, when its row names the private disk', function (): void {
        [$id, $path, $stripped] = refusalJpeg(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => $this->located]);
        Log::spy();

        expect(MediaCustody::settle(DB::connection(), $id, publication: true))->toBe(MediaCustody::REFUSED)
            ->and(refusalCopies($path))->toBe(['public' => null, MediaDisks::PRIVATE => hash('sha256', $this->located), 'old-cdn' => null])
            ->and(refusalWrites($path))->toBe([])
            ->and(refusalRow($id))->toBe(['disk' => MediaDisks::PRIVATE, 'visibility' => 'public', 'checksum' => hash('sha256', $stripped)]);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => refusalLine($message, $id)
            && str_contains($message, '['.$path.']')
            && str_contains($message, 'checksum ['.hash('sha256', $stripped).'], so the copy kept, from ['.MediaDisks::PRIVATE.']')
            && str_contains($message, 'kept on ['.MediaDisks::PRIVATE.'], its row naming it'))->once();
        // The keeper's own line, every disk's hash in it, is still the first word on the mismatch.
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'matches its recorded checksum')
            && str_contains($message, '(the disk the row names)')
            && str_contains($message, hash('sha256', $this->located)))->once();
    });

    it('settles it on the private disk when its row names the public disk, which no longer holds it', function (): void {
        // A trash rolled back after its withdrawal: the row names the public disk, the copy is on the private one.
        [$id, $path] = refusalJpeg('public', [MediaDisks::PRIVATE => $this->located]);

        expect(MediaCustody::settle(DB::connection(), $id))->toBe(MediaCustody::REFUSED)
            ->and(refusalCopies($path))->toBe(['public' => null, MediaDisks::PRIVATE => hash('sha256', $this->located), 'old-cdn' => null])
            ->and(refusalWrites($path))->toBe([])
            ->and(refusalRow($id)['disk'])->toBe(MediaDisks::PRIVATE);
    });

    it('takes every served copy off as it refuses', function (array $copies, ?string $differs, string $from): void {
        [$id, $path] = refusalJpeg(MediaDisks::PRIVATE, array_map(fn (string $bytes): string => $bytes === 'located' ? $this->located : $bytes, $copies));
        Log::spy();

        expect(MediaCustody::settle(DB::connection(), $id, publication: true))->toBe(MediaCustody::REFUSED)
            ->and(refusalCopies($path))->toBe(['public' => null, MediaDisks::PRIVATE => hash('sha256', $this->located), 'old-cdn' => null])
            ->and(refusalRow($id)['disk'])->toBe(MediaDisks::PRIVATE);

        if ($differs !== null) {
            Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_starts_with($message, 'Media custody: removing')
                && str_contains($message, hash('sha256', $differs))
                && str_contains($message, hash('sha256', $this->located)))->once();
        }

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => refusalLine($message, $id)
            && str_contains($message, 'so the copy kept, from ['.$from.'], was changed outside Kitsune'))->once();
    })->with([
        'the same bytes on the public disk' => [[MediaDisks::PRIVATE => 'located', 'public' => 'located'], null, MediaDisks::PRIVATE],
        'others on the public disk' => [[MediaDisks::PRIVATE => 'located', 'public' => 'another copy'], 'another copy', MediaDisks::PRIVATE],
        'only the public disk holds it' => [['public' => 'located'], null, 'public'],
        'only a former public disk holds it' => [['old-cdn' => 'located'], null, 'old-cdn'],
    ]);

    it('leaves a JPEG whose row names the public disk, which holds the copy kept, as it is', function (string $name, string $mime): void {
        [$id, $path] = refusalJpeg('public', ['public' => $this->located], name: $name, mime: $mime);
        Log::spy();

        expect(MediaCustody::settle(DB::connection(), $id, publication: true))->toBe(MediaCustody::UNCHANGED)
            ->and(refusalCopies($path)['public'])->toBe(hash('sha256', $this->located))
            ->and(refusalWrites($path))->toBe([])
            ->and(refusalRow($id)['disk'])->toBe('public')
            // Left before it is asked what it is: called a PNG, its first bytes are never read for it either.
            ->and(refusalOpened('public', $path))->toBe(0);

        Log::shouldNotHaveReceived('warning', [Mockery::on(fn (string $message): bool => str_contains($message, 'refusing to publish'))]);
    })->with([
        'called a JPEG' => ['photo.jpg', 'image/jpeg'],
        'called a PNG' => ['photo.png', 'image/png'],
    ]);

    it('reads a JPEG by its row in any of its names, without opening its bytes for it', function (string $name, string $mime): void {
        [$id, $path] = refusalJpeg(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => REFUSAL_PNG], name: $name, mime: $mime);

        expect(MediaCustody::settle(DB::connection(), $id, publication: true))->toBe(MediaCustody::REFUSED)
            ->and(refusalCopies($path)['public'])->toBeNull()
            // Hashed, as a local disk hashes, without being opened: the row said JPEG, so its first bytes were never read.
            ->and(refusalOpened(MediaDisks::PRIVATE, $path))->toBe(0);
    })->with([
        'a .jfif' => ['photo.jfif', 'image/png'],
        'a type in capitals, with a parameter' => ['photo.bin', 'IMAGE/JPEG; q=1'],
        'image/pjpeg' => ['photo.bin', 'image/pjpeg'],
        'a .JPG' => ['photo.JPG', 'application/octet-stream'],
    ]);

    it('reads a JPEG by the first bytes of the copy kept, wherever its row calls it a PNG', function (string $named, array $copies): void {
        [$id, $path] = refusalJpeg($named, array_map(fn (string $bytes): string => $bytes === 'located' ? $this->located : $bytes, $copies), name: 'photo.png', mime: 'image/png');

        expect(MediaCustody::settle(DB::connection(), $id, publication: true))->toBe(MediaCustody::REFUSED)
            ->and(refusalCopies($path)['public'])->toBeNull()
            ->and(refusalCopies($path)['old-cdn'])->toBeNull()
            ->and(refusalCopies($path)[MediaDisks::PRIVATE])->toBe(hash('sha256', $this->located))
            ->and(refusalRow($id)['disk'])->toBe(MediaDisks::PRIVATE);
    })->with([
        'kept from the disk its row names' => [MediaDisks::PRIVATE, [MediaDisks::PRIVATE => 'located']],
        // The disk the row names holds nothing: the copy kept is the first in Adam's order, and its bytes are what is read.
        'kept as the first in Adam\'s order' => ['old-cdn', [MediaDisks::PRIVATE => 'located']],
        // Kept from a former public disk, the private one holding a PNG: the copy kept is read, never the private disk's.
        'kept from another disk than the private one' => ['old-cdn', ['old-cdn' => 'located', MediaDisks::PRIVATE => REFUSAL_PNG]],
    ]);

    it('publishes any other format whose copies differ, as decision 5 has it', function (string $named): void {
        $changed = REFUSAL_PNG.'changed by hand';
        [$id, $path] = refusalJpeg($named, [MediaDisks::PRIVATE => $changed], name: 'photo.png', mime: 'image/png');
        Log::spy();

        expect(MediaCustody::settle(DB::connection(), $id, publication: true))->toBe(MediaCustody::SETTLED)
            ->and(refusalCopies($path)['public'])->toBe(hash('sha256', $changed))
            ->and(refusalRow($id)['disk'])->toBe('public');

        Log::shouldNotHaveReceived('warning', [Mockery::on(fn (string $message): bool => str_contains($message, 'refusing to publish'))]);
    })->with([
        'kept from the disk its row names' => MediaDisks::PRIVATE,
        'kept as the first in Adam\'s order' => 'old-cdn',
    ]);

    it('refuses a JPEG whose checksum matches nothing', function (string $how): void {
        $stripped = refusalJpeg(MediaDisks::PRIVATE, [])[2];
        $checksum = $how === 'empty' ? '' : strtoupper(hash('sha256', $stripped));
        [$id, $path] = refusalJpeg(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => 'stripped'], checksum: $checksum);
        Log::spy();

        expect(MediaCustody::settle(DB::connection(), $id, publication: true))->toBe(MediaCustody::REFUSED)
            ->and(refusalCopies($path)['public'])->toBeNull();

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => refusalLine($message, $id)
            && str_contains($message, 'recorded checksum ['.($how === 'empty' ? 'none' : $checksum).']'))->once();
    })->with(['empty', 'in capitals']);

    it('publishes a JPEG a copy of which matches', function (): void {
        [$id, $path, $stripped] = refusalJpeg(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => 'stripped']);

        expect(MediaCustody::settle(DB::connection(), $id, publication: true))->toBe(MediaCustody::SETTLED)
            ->and(refusalCopies($path)['public'])->toBe(hash('sha256', $stripped))
            ->and(refusalRow($id)['disk'])->toBe('public');
    });

    it('publishes a file a copy of which matches without reading its first bytes', function (): void {
        // Called a PNG, so only the order of the questions keeps its first bytes unread: a match is asked first.
        [$id, $path] = refusalJpeg(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => REFUSAL_PNG], name: 'photo.png', mime: 'image/png', checksum: hash('sha256', REFUSAL_PNG));

        expect(MediaCustody::settle(DB::connection(), $id, publication: true))->toBe(MediaCustody::SETTLED)
            ->and(refusalCopies($path)['public'])->toBe(hash('sha256', REFUSAL_PNG))
            // Opened once, to be copied onto the public disk: never to read its first bytes.
            ->and(refusalOpened(MediaDisks::PRIVATE, $path))->toBe(1);
    });

    it('fails, calling it neither, when the copy kept is gone before its first bytes are read', function (): void {
        [$id, $path] = refusalJpeg(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => $this->located], name: 'photo.png', mime: 'image/png');
        $file = $this->disks[MediaDisks::PRIVATE]->root().'/'.$path;
        // The private disk is asked whether it holds the copy, then hashes it; the fourth look is the first-bytes read's.
        $this->disks[MediaDisks::PRIVATE]->onOperation(4, static function (string $event) use ($file): void {
            expect($event)->toBe('fileExists');
            rename($file, $file.'.away');
        }, 'any');

        expect(fn () => MediaCustody::settle(DB::connection(), $id, publication: true))
            ->toThrow(MediaCustodyFailure::class, 'whether it exists cannot be told');

        rename($file.'.away', $file);

        expect(refusalWrites($path))->toBe([])
            ->and(refusalCopies($path))->toBe(['public' => null, MediaDisks::PRIVATE => hash('sha256', $this->located), 'old-cdn' => null])
            ->and(refusalRow($id)['disk'])->toBe(MediaDisks::PRIVATE);
    });

    it('takes a trashed JPEG off the web whatever its copies hold', function (): void {
        [$id, $path] = refusalJpeg('public', ['public' => $this->located], trashed: true);
        Log::spy();

        expect(MediaCustody::settle(DB::connection(), $id))->toBe(MediaCustody::SETTLED)
            ->and(refusalCopies($path))->toBe(['public' => null, MediaDisks::PRIVATE => hash('sha256', $this->located), 'old-cdn' => null])
            ->and(refusalRow($id)['disk'])->toBe(MediaDisks::PRIVATE);

        Log::shouldNotHaveReceived('warning', [Mockery::on(fn (string $message): bool => str_contains($message, 'refusing to publish'))]);
    });

    it('fails where a copy that matches turns up as it refuses, and removes nothing', function (): void {
        // The public disk alone seems to hold it, changed: the private copy, which matches, reads as absent when the
        // keeper looks, and is there again when the refusal hashes it before writing over it.
        [$id, $path] = refusalJpeg(MediaDisks::PRIVATE, ['public' => $this->located, MediaDisks::PRIVATE => 'stripped']);
        $file = $this->disks[MediaDisks::PRIVATE]->root().'/'.$path;
        rename($file, $file.'.away');
        $this->disks[MediaDisks::PRIVATE]->onOperation(2, static function () use ($file): void {
            rename($file.'.away', $file);
        }, 'any');

        expect(fn () => MediaCustody::settle(DB::connection(), $id, publication: true))
            ->toThrow(MediaCustodyFailure::class, 'it matches the recorded checksum');

        expect(refusalCopies($path)['public'])->toBe(hash('sha256', $this->located))
            ->and(Storage::disk(MediaDisks::PRIVATE)->exists($path))->toBeTrue()
            ->and(refusalWrites($path))->toBe([])
            ->and(refusalRow($id)['disk'])->toBe(MediaDisks::PRIVATE);
    });

    it('puts nothing back on the web after a rollback it refuses', function (): void {
        [$id, $path] = refusalJpeg('public', [MediaDisks::PRIVATE => $this->located]);
        Log::spy();
        // Under the suite's own transaction, each custody step's is a savepoint: counted, the cleanup's would be a second.
        $begun = 0;
        Event::listen(TransactionBeginning::class, static function () use (&$begun): void {
            $begun++;
        });

        MediaCustody::queue(DB::getDefaultConnection(), [$id]);
        MediaCustody::drain(DB::connection());

        // The settle's alone: the copy on the private disk is the file, and no cleanup is asked after it.
        expect($begun)->toBe(1);

        expect(refusalCopies($path))->toBe(['public' => null, MediaDisks::PRIVATE => hash('sha256', $this->located), 'old-cdn' => null])
            ->and(refusalRow($id)['disk'])->toBe(MediaDisks::PRIVATE);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => refusalLine($message, $id))->once();
        Log::shouldNotHaveReceived('warning', [Mockery::on(fn (string $message): bool => str_contains($message, 'could not be put back')
            || str_contains($message, 'rewrites [public]'))]);
    });

    it('logs no failure and cleans nothing up after a refused publication', function (): void {
        [$id, $path] = refusalJpeg(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => $this->located]);
        Log::spy();

        MediaCustody::publish(DB::connection(), [$id]);

        expect(refusalCopies($path))->toBe(['public' => null, MediaDisks::PRIVATE => hash('sha256', $this->located), 'old-cdn' => null]);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => refusalLine($message, $id))->once();
        Log::shouldNotHaveReceived('warning', [Mockery::on(fn (string $message): bool => str_contains($message, 'publication failed'))]);
    });

    it('says a publication or a put-back that failed may yet be refused', function (): void {
        [$id] = refusalJpeg(MediaDisks::PRIVATE, ['public' => $this->located]);
        $this->disks[MediaDisks::PRIVATE]->failWrites = true;
        Log::spy();

        MediaCustody::publish(DB::connection(), [$id]);
        MediaCustody::queue(DB::getDefaultConnection(), [$id]);
        MediaCustody::drain(DB::connection());

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'publication failed')
            && str_contains($message, "kitsune:media-reconcile --entry={$id} --force publishes it, or says what will (ADR-042 decision 5)."))->once();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'could not be put back')
            && str_contains($message, "--entry={$id} --force puts it back, or says what will (ADR-042 decision 5)."))->once();
        Log::shouldNotHaveReceived('warning', [Mockery::on(fn (string $message): bool => refusalLine($message, $id))]);
    });
});

/*
 * T198-T200. Through the doors that publish: a restore, and the compensation of a trash rolled back.
 */
describe('the doors', function (): void {
    it('keeps a restored JPEG off the web when a backup was restored over its private copy', function (bool $bulk): void {
        [$id, $path, $stripped] = refusalJpeg('public', ['public' => 'stripped']);
        Entry::query()->findOrFail($id)->delete();
        Storage::disk(MediaDisks::PRIVATE)->put($path, $this->located);
        Log::spy();

        $bulk ? Entry::onlyTrashed()->whereKey($id)->restore() : Entry::withTrashed()->findOrFail($id)->restore();

        expect(Entry::query()->whereKey($id)->exists())->toBeTrue()
            ->and(refusalCopies($path))->toBe(['public' => null, MediaDisks::PRIVATE => hash('sha256', $this->located), 'old-cdn' => null])
            ->and(refusalRow($id))->toBe(['disk' => MediaDisks::PRIVATE, 'visibility' => 'public', 'checksum' => hash('sha256', $stripped)]);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => refusalLine($message, $id))->once();
        Log::shouldNotHaveReceived('warning', [Mockery::on(fn (string $message): bool => str_contains($message, 'publication failed'))]);
    })->with(['restore()' => false, 'a bulk restore' => true]);

    it('does not republish a JPEG an image optimiser changed', function (): void {
        // No location in it, and still not the bytes Kitsune recorded: the cost Adam accepted with decision 37.
        [$id, $path] = refusalJpeg('public', ['public' => 'stripped']);
        Entry::query()->findOrFail($id)->delete();
        $optimised = LocatedJpeg::base();
        Storage::disk(MediaDisks::PRIVATE)->put($path, $optimised);

        Entry::withTrashed()->findOrFail($id)->restore();

        expect(LocatedJpeg::sentinels($optimised))->toBe([])
            ->and(refusalCopies($path))->toBe(['public' => null, MediaDisks::PRIVATE => hash('sha256', $optimised), 'old-cdn' => null]);
    });

    it('puts no changed JPEG back on the web when a trash rolls back', function (): void {
        [$id, $path] = refusalJpeg('public', ['public' => $this->located]);
        AuditorStandIn::install()->throwOnce(new RuntimeException('the audit row could not be written'));

        expect(fn () => Entry::query()->findOrFail($id)->delete())->toThrow(RuntimeException::class, 'the audit row could not be written');

        expect(Entry::query()->whereKey($id)->exists())->toBeTrue()
            ->and(refusalCopies($path))->toBe(['public' => null, MediaDisks::PRIVATE => hash('sha256', $this->located), 'old-cdn' => null])
            ->and(refusalRow($id)['disk'])->toBe(MediaDisks::PRIVATE);
    });
});

/*
 * T201-T203. What an operator's commands say of it.
 */
describe('reconcile and prune', function (): void {
    it('keeps such a JPEG off the web under --force, and fails', function (string $named, array $copies, string $label): void {
        [$id, $path] = refusalJpeg($named, array_map(fn (string $bytes): string => $bytes === 'located' ? $this->located : $bytes, $copies));

        $before = Artisan::call('kitsune:media-reconcile');
        expect(Artisan::output())->toContain($label);

        $exit = Artisan::call('kitsune:media-reconcile', ['--force' => true]);
        $output = Artisan::output();

        expect($before)->toBe(1)
            ->and($exit)->toBe(1)
            ->and($output)->toContain('→ kept off the web: a JPEG no copy of which matches its recorded checksum is not published, as the log below says (ADR-042 decision 37)')
            ->and($output)->toContain('refusing to publish ['.$path.']')
            ->and(refusalCopies($path)['public'])->toBeNull()
            ->and(refusalRow($id)['disk'])->toBe(MediaDisks::PRIVATE);

        // Counted under Kept, in the summary's row for its label: Label, Rows, Settled, Nothing to do, Gone, Kept.
        expect(preg_match('/\|\s*'.preg_quote($label, '/').'\s*\|\s*1\s*\|\s*0\s*\|\s*0\s*\|\s*0\s*\|\s*1\s*\|\s*0\s*\|\s*0\s*\|/', $output))->toBe(1);
    })->with([
        'awaiting publication' => [MediaDisks::PRIVATE, [MediaDisks::PRIVATE => 'located'], 'awaiting publication'],
        'absent' => ['public', [MediaDisks::PRIVATE => 'located'], 'absent'],
        'served while awaiting' => [MediaDisks::PRIVATE, [MediaDisks::PRIVATE => 'located', 'public' => 'located'], 'awaiting publication'],
    ]);

    it('says, read-only, that --force keeps such a JPEG off the web', function (): void {
        refusalJpeg(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => $this->located]);

        expect(Artisan::call('kitsune:media-reconcile'))->toBe(1)
            ->and(Artisan::output())->toContain('and a JPEG it would publish, no copy of which matches its recorded checksum, kept off the web rather than published, and the run failed on it, as the log then says — one whose row names the public disk, which holds the copy kept, left where it is (ADR-042 decision 37)');
    });

    it('passes once the JPEG is made private and then public', function (): void {
        [$id, $path] = refusalJpeg(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => $this->located]);
        Artisan::call('kitsune:media-reconcile', ['--force' => true]);
        $entry = Entry::query()->findOrFail($id);

        expect(MediaVisibility::makePrivate($entry))->toBe(MediaVisibility::SWITCHED)
            ->and(MediaVisibility::makePublic(Entry::query()->findOrFail($id)))->toBe(MediaVisibility::SWITCHED)
            ->and(Artisan::call('kitsune:media-reconcile'))->toBe(0)
            ->and(LocatedJpeg::sentinels((string) Storage::disk('public')->get($path)))->toBe([]);
    });

    it('says under Awaiting publication what publishes a JPEG custody refused', function (): void {
        refusalJpeg(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => $this->located]);

        Artisan::call('kitsune:media-prune');

        expect(Artisan::output())->toContain('so not published at its public URL, though a copy left on a served disk, listed above, may be. kitsune:media-reconcile --force publishes it, unless it is a JPEG no copy of which matches its recorded checksum: that one it keeps off the web, and making it private, then public, in the admin publishes it, or says what stands in the way (ADR-042 decision 37):');
    });

    it('says of a served copy prune keeps that reconcile takes a refused JPEG off the web rather than moving its row', function (): void {
        [$id, $path] = refusalJpeg(MediaDisks::PRIVATE, ['public' => $this->located]);

        Artisan::call('kitsune:media-prune');
        $output = Artisan::output();

        expect($output)->toContain('kept: kitsune:media-reconcile moves its row first, or, for a JPEG no copy of which matches its recorded checksum, takes the file off the web and points its row at the private disk (ADR-042 decision 37)')
            ->and(Artisan::call('kitsune:media-reconcile', ['--force' => true]))->toBe(1)
            ->and(refusalCopies($path)['public'])->toBeNull()
            ->and(refusalRow($id)['disk'])->toBe(MediaDisks::PRIVATE);
    });
});

/*
 * V18. The remedy: Make private, then Make public, strips the copy kept, records it and publishes it — from every state
 * a refusal leaves, and from the one it leaves alone.
 */
it('publishes a JPEG custody refused once it is made private and then public', function (string $named, array $copies, ?string $checksum): void {
    [$id, $path] = refusalJpeg($named, array_map(fn (string $bytes): string => $bytes === 'located' ? $this->located : $bytes, $copies), checksum: $checksum);
    MediaCustody::settle(DB::connection(), $id, publication: true);

    expect(MediaVisibility::makePublic(Entry::query()->findOrFail($id)))->toBe(MediaVisibility::UNCHANGED)
        ->and(MediaVisibility::makePrivate(Entry::query()->findOrFail($id)))->toBe(MediaVisibility::SWITCHED)
        ->and(refusalCopies($path)['public'])->toBeNull()
        ->and(MediaVisibility::makePublic(Entry::query()->findOrFail($id)))->toBe(MediaVisibility::SWITCHED);

    $published = (string) Storage::disk('public')->get($path);

    expect(LocatedJpeg::sentinels($published))->toBe([])
        ->and(LocatedJpeg::gpsPointers($published))->toBe(0)
        ->and(refusalRow($id))->toBe(['disk' => 'public', 'visibility' => 'public', 'checksum' => hash('sha256', $published)])
        ->and(Storage::disk(MediaDisks::PRIVATE)->exists($path))->toBeFalse()
        ->and(MediaLocation::beginsAsJpeg($published))->toBeTrue();
})->with([
    'a backup over the private copy' => [MediaDisks::PRIVATE, [MediaDisks::PRIVATE => 'located'], null],
    'a trash rolled back' => ['public', [MediaDisks::PRIVATE => 'located'], null],
    'only the public disk holding it' => [MediaDisks::PRIVATE, ['public' => 'located'], null],
    'the public disk holding it, its row naming it' => ['public', ['public' => 'located'], null],
    'an empty checksum' => [MediaDisks::PRIVATE, [MediaDisks::PRIVATE => 'located'], ''],
]);

/*
 * V18a. Where Make public cannot strip the copy kept — its location cannot be removed with certainty, or its bytes are
 * not a JPEG's at all — the refusal said so: Make public refuses it in its own words, and the file stays private.
 */
it('says what stands in the way of a copy Make public cannot strip', function (string $bytes): void {
    $kept = $bytes === 'unremovable' ? LocatedJpeg::unremovable() : REFUSAL_PNG;
    [$id] = refusalJpeg(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => $kept]);
    Log::spy();

    expect(MediaCustody::settle(DB::connection(), $id, publication: true))->toBe(MediaCustody::REFUSED)
        ->and(MediaVisibility::makePrivate(Entry::query()->findOrFail($id)))->toBe(MediaVisibility::SWITCHED)
        ->and(fn () => MediaVisibility::makePublic(Entry::query()->findOrFail($id)))->toThrow(MediaRefused::class, 'It stays private');

    expect(refusalRow($id)['visibility'])->toBe('private')
        ->and(refusalCopies($id === 0 ? '' : (string) DB::table('media_files')->where('entry_id', $id)->value('path'))['public'])->toBeNull();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => refusalLine($message, $id)
        && str_contains($message, 'or says what stands in the way — a copy whose location cannot be removed with certainty, or one that is not a JPEG\'s bytes'))->once();
})->with(['unremovable', 'not a JPEG']);

/*
 * The host's own private disk: a refusal settles the file there, and core's private disk keeps the copy it was kept from,
 * as a private disk's copy always stays — so the remedy is three steps, and the refusal says so.
 */
it('settles a refused JPEG on the host\'s own private disk, and says what its copy on core\'s asks', function (): void {
    $root = sys_get_temp_dir().'/kitsune-refusal-host-private-'.bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    test()->roots = [...test()->roots, $root];
    config([
        'filesystems.disks.host-private' => ['driver' => 'local', 'root' => $root],
        'kitsune.media.disks.private' => 'host-private',
    ]);
    RefusingDisk::install('host-private', $root);
    [$id, $path] = refusalJpeg(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => $this->located]);
    Log::spy();

    expect(MediaCustody::settle(DB::connection(), $id, publication: true))->toBe(MediaCustody::REFUSED)
        ->and(refusalRow($id)['disk'])->toBe('host-private')
        ->and(hash('sha256', (string) Storage::disk('host-private')->get($path)))->toBe(hash('sha256', $this->located))
        ->and(refusalCopies($path))->toBe(['public' => null, MediaDisks::PRIVATE => hash('sha256', $this->located), 'old-cdn' => null]);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => refusalLine($message, $id)
        && str_contains($message, 'kept on [host-private], its row naming it')
        && str_contains($message, 'Its copy on ['.MediaDisks::PRIVATE.'], core\'s own private disk, stays there, as a private disk\'s copy does: once it is made private, kitsune:media-prune --force removes it, and only then does making it public publish it.'))->once();

    // The three steps it names.
    expect(MediaVisibility::makePrivate(Entry::query()->findOrFail($id)))->toBe(MediaVisibility::SWITCHED)
        ->and(fn () => MediaVisibility::makePublic(Entry::query()->findOrFail($id)))->toThrow(MediaVisibilityRefused::class);

    Artisan::call('kitsune:media-prune', ['--force' => true]);

    expect(MediaVisibility::makePublic(Entry::query()->findOrFail($id)))->toBe(MediaVisibility::SWITCHED)
        ->and(LocatedJpeg::sentinels((string) Storage::disk('public')->get($path)))->toBe([]);
});

/*
 * A trash refused part-way — a served copy that could not be removed — says the file is where it was, unless it is a
 * JPEG custody then keeps off the web: what its rollback's put-back does instead.
 */
it('says a refused trash keeps a JPEG whose copy fails its checksum off the web', function (): void {
    [$id, $path] = refusalJpeg('public', ['public' => $this->located, 'old-cdn' => $this->located]);
    // The trash's delete there fails; the put-back's, after its rollback, does not.
    $cdn = $this->disks['old-cdn'];
    $cdn->failDeletes = true;
    $cdn->onOperation(2, static function () use ($cdn): void {
        $cdn->failDeletes = false;
    });

    expect(fn () => Entry::query()->findOrFail($id)->delete())->toThrow(
        MediaWithdrawalRefused::class,
        'so the entry stays as it was, and its file where it was, unless it is a JPEG no copy of which matches its recorded checksum, which is kept off the web instead (ADR-042 decisions 5 and 37).',
    );

    expect(Entry::query()->whereKey($id)->exists())->toBeTrue()
        ->and(refusalCopies($path))->toBe(['public' => null, MediaDisks::PRIVATE => hash('sha256', $this->located), 'old-cdn' => null])
        ->and(refusalRow($id)['disk'])->toBe(MediaDisks::PRIVATE);
});

/*
 * V19. Make public strips and records before its commit, and publishes after it: a private copy changed in between is
 * refused as any other.
 */
it('keeps off the web a JPEG whose private copy changes between Make public\'s commit and its publication', function (): void {
    [$id, $path] = refusalJpeg(MediaDisks::PRIVATE, [MediaDisks::PRIVATE => 'stripped']);
    DB::table('media_files')->where('entry_id', $id)->update(['visibility' => 'private']);
    $located = $this->located;
    AuditorStandIn::install()->beforeRecording(static function () use ($path, $located): void {
        MediaCustody::whenOutermost(DB::connection(), static fn () => Storage::disk(MediaDisks::PRIVATE)->put($path, $located));
    });
    Log::spy();

    expect(MediaVisibility::makePublic(Entry::query()->findOrFail($id)))->toBe(MediaVisibility::SWITCHED)
        ->and(refusalCopies($path))->toBe(['public' => null, MediaDisks::PRIVATE => hash('sha256', $located), 'old-cdn' => null])
        ->and(refusalRow($id)['disk'])->toBe(MediaDisks::PRIVATE)
        ->and(refusalRow($id)['visibility'])->toBe('public');

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => refusalLine($message, $id))->once();
});

it('reads a file as a JPEG by its first three bytes alone', function (string $head, bool $jpeg): void {
    expect(MediaLocation::beginsAsJpeg($head))->toBe($jpeg);
})->with([
    'a JFIF marker' => ["\xFF\xD8\xFF\xE0", true],
    'the marker alone' => ["\xFF\xD8\xFF", true],
    'two bytes of it' => ["\xFF\xD8", false],
    'nothing' => ['', false],
    'a PNG' => ["\x89PNG", false],
]);
