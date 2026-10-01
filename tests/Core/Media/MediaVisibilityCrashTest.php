<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Media\JpegLocation;
use Kitsune\Core\Media\MediaCustody;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Media\MediaRefused;
use Kitsune\Core\Media\MediaVisibility;
use Kitsune\Core\Media\MediaVisibilityRefused;
use Kitsune\Core\Media\MediaWithdrawalRefused;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\LocatedJpeg;
use Kitsune\Core\Tests\Fixtures\RefusingDisk;

/*
 * A process stopped at every step of a switch — Adam, ADR-042 decision 32.
 *
 * ⚠️ DEATH, NOT FAILURE. At the chosen byte operation the process "dies": that operation and every one after it throws,
 * so no compensation in the dying process can move a byte, and the database is left as the engine leaves it — what had
 * committed, committed; the rest rolled back. Then the process "restarts" and something else runs: settle, a
 * publication, a drain, reconcile, prune, another switch, a trash and a restore, an erasure. After the death and after
 * each of those, and after a final `reconcile --force`:
 *
 *   I1. no copy or partial copy at the path on a disk the web serves carries GPS data;
 *   I2. a private or trashed file has no copy or partial copy on a disk the web serves;
 *   I3. at the end, a live public file is served whole — its served bytes match its checksum — and a private one is held
 *       on the private disk, as its checksum says or, the one residue accepted, already stripped (*What a failure leaves*).
 *
 * One more point is not a byte operation: after the stripped copy is renamed into place and before the commit. It is
 * built directly — the row recording the original, the disk holding the stripped copy — as such a death leaves it.
 */

beforeEach(function (): void {
    $this->roots = [];

    foreach (['public', MediaDisks::PRIVATE] as $name) {
        $root = sys_get_temp_dir().'/kitsune-crash-'.$name.'-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        $this->roots[] = $root;
        RefusingDisk::install($name, $root);
    }

    $org = Org::create(['slug' => 'crash', 'name' => 'Crash']);
    app(Context::class)->setOrg($org);
    app(Context::class)->setSite(Site::create(['handle' => 'main', 'slug' => 'crash-main', 'name' => 'Main', 'locale' => 'en']));
    $this->image = EntryType::create(['org_id' => $org->id, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);
});

afterEach(function (): void {
    RefusingDisk::$beforeByte = null;
    app(Context::class)->forget();

    foreach ($this->roots as $root) {
        exec('rm -rf '.escapeshellarg($root));
    }
});

/** A located JPEG stored private — or, for making one private, made public first. */
function crashStored(string $to): Entry
{
    $source = LocatedJpeg::file(LocatedJpeg::photo(true), 'kitsune-crash-');
    $entry = MediaLibrary::store($source, 'photo.jpg', test()->image, 'private', title: 'Photo');
    unlink($source);

    if ($to === 'private') {
        MediaVisibility::makePublic($entry);
    }

    RefusingDisk::forgetLog();

    return $entry;
}

/** Run the switch, dying before byte operation $at (counted across every disk); null runs it whole. */
function crashRun(Entry $entry, string $to, ?int $at): void
{
    $count = 0;
    $dead = false;

    RefusingDisk::$beforeByte = static function () use (&$count, &$dead, $at): void {
        $count++;

        if ($dead || ($at !== null && $count === $at)) {
            $dead = true;

            throw new RuntimeException('the process died');
        }
    };

    try {
        $to === 'public' ? MediaVisibility::makePublic($entry) : MediaVisibility::makePrivate($entry);
    } catch (Throwable) {
        // Dead: whatever the dying process threw is not reported anywhere.
    } finally {
        // ...and restarted: nothing it registered survives it.
        RefusingDisk::$beforeByte = null;
        app()->forgetInstance(MediaCustody::class);
    }
}

/** How many byte operations the switch makes when nothing dies. */
function crashOperations(string $to): int
{
    $entry = crashStored($to);
    $count = 0;
    RefusingDisk::$beforeByte = static function () use (&$count): void {
        $count++;
    };

    try {
        $to === 'public' ? MediaVisibility::makePublic($entry) : MediaVisibility::makePrivate($entry);
    } finally {
        RefusingDisk::$beforeByte = null;
    }

    return $count;
}

function crashPath(Entry $entry): string
{
    return (string) DB::table('media_files')->where('entry_id', $entry->id)->value('path');
}

/** I1 and I2, from the disks and the rows as they stand. */
function crashInvariants(Entry $entry, string $path, string $when): void
{
    $row = DB::table('media_files')->where('entry_id', $entry->id)->first();
    $live = DB::table('entries')->where('id', $entry->id)->value('deleted_at') === null;
    $onWeb = $row !== null && $live && $row->visibility === 'public';

    foreach ([$path, $path.'.kitsune-partial'] as $at) {
        $file = Storage::disk('public')->path($at);

        if (! is_file($file)) {
            continue;
        }

        $bytes = (string) file_get_contents($file);

        expect(LocatedJpeg::sentinels($bytes))->toBe([], "{$when}: GPS data on the web at {$at}")
            ->and(LocatedJpeg::gpsPointers($bytes))->toBe(0, "{$when}: a GPS pointer on the web at {$at}")
            ->and($onWeb)->toBeTrue("{$when}: a private or trashed file's copy on the web at {$at}");
    }
}

/** I3, once everything has settled. */
function crashSettled(Entry $entry, string $path): void
{
    $row = DB::table('media_files')->where('entry_id', $entry->id)->first();

    if ($row === null) {
        expect(is_file(Storage::disk('public')->path($path)))->toBeFalse('an erased file still on the web');

        return;
    }

    $live = DB::table('entries')->where('id', $entry->id)->value('deleted_at') === null;
    $public = Storage::disk('public')->path($path);
    $private = Storage::disk(MediaDisks::PRIVATE)->path($path);

    if ($live && $row->visibility === 'public') {
        expect(is_file($public))->toBeTrue('a live public file not published')
            ->and(hash_file('sha256', $public))->toBe($row->checksum, 'a published file its row does not describe')
            ->and((int) $row->size_bytes)->toBe((int) filesize($public));

        return;
    }

    $held = is_file($private) ? (string) file_get_contents($private) : null;

    expect($held)->not->toBeNull('a private file held nowhere');

    // As its checksum says — or already stripped, its row recording the original: the residue a death can leave.
    if (hash('sha256', (string) $held) !== $row->checksum) {
        expect(LocatedJpeg::sentinels((string) $held))->toBe([])
            ->and(strlen((string) $held))->toBe((int) $row->size_bytes);
    }
}

/** What runs once the process has restarted. */
function crashFollowUp(Entry $entry, string $followUp): void
{
    $connection = DB::connection();
    $fresh = Entry::withTrashed()->findOrFail($entry->id);

    try {
        match ($followUp) {
            'nothing' => null,
            'settle' => MediaCustody::settle($connection, $entry->id),
            'publication' => MediaCustody::publish($connection, [$entry->id]),
            'drain' => (static function () use ($connection, $entry): void {
                MediaCustody::queue($connection->getName(), [$entry->id]);
                MediaCustody::drain($connection);
            })(),
            'reconcile' => Artisan::call('kitsune:media-reconcile', ['--force' => true, '--entry' => [(string) $entry->id]]),
            'prune' => Artisan::call('kitsune:media-prune', ['--force' => true]),
            'make public' => MediaVisibility::makePublic($fresh),
            'make private' => MediaVisibility::makePrivate($fresh),
            'trash and restore' => (static function () use ($fresh): void {
                $fresh->delete();
                Entry::withTrashed()->findOrFail($fresh->id)->restore();
            })(),
            'erasure' => $fresh->forceDelete(),
        };
    } catch (MediaVisibilityRefused|MediaWithdrawalRefused|MediaRefused) {
        // A refusal is an answer: it changed nothing, which the invariants check.
    }
}

const CRASH_FOLLOW_UPS = ['nothing', 'settle', 'publication', 'drain', 'reconcile', 'prune', 'make public', 'make private', 'trash and restore', 'erasure'];

it('leaves no located JPEG and no private file on the web, wherever the process stops', function (string $to, string $followUp): void {
    $points = crashOperations($to);

    expect($points)->toBeGreaterThan(1);

    // Every byte operation, and none: the switch run whole.
    foreach ([...range(1, $points), null] as $at) {
        $entry = crashStored($to);
        $path = crashPath($entry);
        $when = $at === null ? "{$to}, run whole" : "{$to}, dead before byte operation {$at} of {$points}";

        crashRun($entry, $to, $at);
        crashInvariants($entry, $path, $when.', after the death');

        crashFollowUp($entry, $followUp);
        crashInvariants($entry, $path, $when.", after {$followUp}");

        Artisan::call('kitsune:media-reconcile', ['--force' => true, '--entry' => [(string) $entry->id]]);
        Artisan::call('kitsune:media-prune', ['--force' => true]);
        crashInvariants($entry, $path, $when.", after {$followUp} and reconcile");
        crashSettled($entry, $path);
    }
})->with(['public', 'private'])->with(CRASH_FOLLOW_UPS);

/* The point between the rename and the commit: the row records the original, the private disk holds the stripped copy. */
it('leaves nothing on the web from a switch stopped between its rename and its commit', function (string $followUp): void {
    $entry = crashStored('public');
    $path = crashPath($entry);
    $file = Storage::disk(MediaDisks::PRIVATE)->path($path);
    $stripped = JpegLocation::strippedCopy($file);
    rename((string) $stripped, $file);

    crashInvariants($entry, $path, 'renamed, not committed');

    crashFollowUp($entry, $followUp);
    crashInvariants($entry, $path, "renamed, not committed, after {$followUp}");

    Artisan::call('kitsune:media-reconcile', ['--force' => true, '--entry' => [(string) $entry->id]]);
    crashInvariants($entry, $path, "renamed, not committed, after {$followUp} and reconcile");
    crashSettled($entry, $path);
})->with(CRASH_FOLLOW_UPS);
