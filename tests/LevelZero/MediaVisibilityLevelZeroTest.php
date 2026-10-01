<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Media\MediaVisibility;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\FailingCommitPdo;
use Kitsune\Core\Tests\Fixtures\LocatedJpeg;
use Kitsune\Core\Tests\Fixtures\RefusingDisk;

/*
 * A file made public or private whose own COMMIT fails — ADR-042 decision 32, at a real level 0.
 *
 * ⚠️ MAKING A JPEG PUBLIC IS THE ONE STEP THAT WRITES BYTES BEFORE ITS COMMIT — the stripped copy over the private one —
 * so a COMMIT that does not land must put the original back, and one that lands and reports failure must not: the row
 * then records the stripped copy, and its publication, registered on the transaction Laravel was never told ended, is
 * registered again. Under `RefreshDatabase` no COMMIT is the outermost, so none of this can be seen elsewhere.
 */

beforeEach(function (): void {
    $this->roots = [];
    $this->disks = [];

    foreach (['public', MediaDisks::PRIVATE] as $name) {
        $root = sys_get_temp_dir().'/kitsune-vis0-'.$name.'-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        $this->roots[] = $root;
        $this->disks[$name] = RefusingDisk::install($name, $root);
    }

    $this->org = Org::create(['slug' => 'visibility-zero', 'name' => 'Visibility zero']);
    app(Context::class)->setOrg($this->org);
    app(Context::class)->setSite(Site::create(['handle' => 'main', 'slug' => 'visibility-zero-main', 'name' => 'Main', 'locale' => 'en']));
    $this->image = EntryType::create(['org_id' => $this->org->id, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);
});

afterEach(function (): void {
    app(Context::class)->forget();

    foreach ($this->roots ?? [] as $root) {
        exec('rm -rf '.escapeshellarg($root));
    }
});

/** A stored file, the disk log emptied after it. */
function visZeroStored(string $bytes, string $name, string $visibility): Entry
{
    $source = LocatedJpeg::file($bytes, 'kitsune-vis0-');
    $entry = MediaLibrary::store($source, $name, test()->image, $visibility);
    unlink($source);
    RefusingDisk::forgetLog();

    return $entry;
}

/** @return array<string, mixed> */
function visZeroRow(Entry $entry): array
{
    return (array) DB::table('media_files')->where('entry_id', $entry->id)->first(['visibility', 'disk', 'checksum', 'size_bytes']);
}

/** @return array{public: ?string, kitsune-private: ?string} each disk's bytes at the path */
function visZeroBytes(string $path): array
{
    $held = [];

    foreach (['public', MediaDisks::PRIVATE] as $disk) {
        $file = Storage::disk($disk)->path($path);
        $held[$disk] = is_file($file) ? (string) file_get_contents($file) : null;
    }

    return $held;
}

/* L5. A COMMIT that does not land: the original is written back, so the private file keeps what it was uploaded with. */
it('writes the original back when making a JPEG public does not commit', function (): void {
    $upload = LocatedJpeg::photo(true);
    $entry = visZeroStored($upload, 'photo.jpg', 'private');
    $before = visZeroRow($entry);
    $path = (string) DB::table('media_files')->where('entry_id', $entry->id)->value('path');
    $pdo = FailingCommitPdo::installOn(DB::connection(), $this->custodyFile);
    $pdo->failNextCommit = 'before';

    expect(fn () => MediaVisibility::makePublic($entry))->toThrow(PDOException::class, 'database is locked');

    expect(visZeroRow($entry))->toBe($before)
        ->and(visZeroBytes($path))->toBe(['public' => null, MediaDisks::PRIVATE => $upload])
        ->and(DB::table('audit_log')->where('action', MediaVisibility::MADE_PUBLIC)->exists())->toBeFalse()
        ->and($pdo->inTransaction())->toBeFalse();
});

/*
 * L6. A COMMIT that lands and reports failure: the stripped copy is the file now, so nothing is written back, and the
 * publication the failure discarded is registered again — the stripped copy is published.
 */
it('publishes the stripped copy when making a JPEG public commits and reports failure', function (): void {
    $entry = visZeroStored(LocatedJpeg::photo(true), 'photo.jpg', 'private');
    $path = (string) DB::table('media_files')->where('entry_id', $entry->id)->value('path');
    $pdo = FailingCommitPdo::installOn(DB::connection(), $this->custodyFile);
    $pdo->failNextCommit = 'after';

    expect(fn () => MediaVisibility::makePublic($entry))->toThrow(PDOException::class, 'database is locked');

    $row = visZeroRow($entry);
    $held = visZeroBytes($path);
    $privateWrites = array_filter(RefusingDisk::$log, static fn (array $op): bool => $op['disk'] === MediaDisks::PRIVATE && $op['event'] === 'writeStream');

    expect([$row['visibility'], $row['disk']])->toBe(['public', 'public'])
        ->and($held[MediaDisks::PRIVATE])->toBeNull()
        ->and(hash('sha256', (string) $held['public']))->toBe($row['checksum'])
        ->and(LocatedJpeg::sentinels((string) $held['public']))->toBe([])
        // The stripped copy, once: nothing was written back over it.
        ->and($privateWrites)->toHaveCount(1);
});

/* L7. Making a file private: a COMMIT that does not land puts it back on the web; one that lands leaves it off. */
it('puts a file back on the web when making it private does not commit, and leaves it off when it does', function (string $commit): void {
    $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $entry = visZeroStored($png, 'logo.png', 'public');
    $path = (string) DB::table('media_files')->where('entry_id', $entry->id)->value('path');
    $pdo = FailingCommitPdo::installOn(DB::connection(), $this->custodyFile);
    $pdo->failNextCommit = $commit;

    expect(fn () => MediaVisibility::makePrivate($entry))->toThrow(PDOException::class, 'database is locked');

    expect(visZeroRow($entry)['visibility'])->toBe($commit === 'before' ? 'public' : 'private')
        ->and(visZeroBytes($path))->toBe($commit === 'before'
            ? ['public' => $png, MediaDisks::PRIVATE => null]
            : ['public' => null, MediaDisks::PRIVATE => $png]);
})->with(['before', 'after']);
