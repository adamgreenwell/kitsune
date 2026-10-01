<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Filament\Actions\BulkAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Filament\MediaVisibilityActions;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\AuditorStandIn;
use Kitsune\Core\Tests\Fixtures\FailingCommitPdo;
use Kitsune\Core\Tests\Fixtures\LocatedJpeg;
use Kitsune\Core\Tests\Fixtures\RefusingDisk;

/*
 * A selection switched file by file, each COMMIT its own — ADR-042 decision 34, at a real level 0.
 *
 * ⚠️ NO TRANSACTION AROUND A SELECTION. Each file commits — and, made public, publishes — before the next begins, so a
 * file refused or a COMMIT that does not land leaves every other as its own switch left it, and one that lands and
 * reports failure is counted as what it is. Under `RefreshDatabase` no COMMIT is the outermost and nothing publishes, so
 * none of this can be seen elsewhere. The system acts, so who may is not asked.
 */

beforeEach(function (): void {
    $this->roots = [];
    $this->disks = [];

    foreach (['public', MediaDisks::PRIVATE] as $name) {
        $root = sys_get_temp_dir().'/kitsune-visbulk0-'.$name.'-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        $this->roots[] = $root;
        $this->disks[$name] = RefusingDisk::install($name, $root);
    }

    $this->org = Org::create(['slug' => 'selection-zero', 'name' => 'Selection zero']);
    app(Context::class)->setOrg($this->org);
    app(Context::class)->setSite(Site::create(['handle' => 'main', 'slug' => 'selection-zero-main', 'name' => 'Main', 'locale' => 'en']));
    $this->image = EntryType::create(['org_id' => $this->org->id, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);

    session()->forget('filament.notifications');
});

afterEach(function (): void {
    app(Context::class)->forget();

    foreach ($this->roots ?? [] as $root) {
        exec('rm -rf '.escapeshellarg($root));
    }
});

/** A stored file, titled, loaded as the grid loads it. */
function bulkZeroStored(string $title, string $visibility = 'private', string $bytes = '', string $name = 'logo.png'): Entry
{
    $bytes = $bytes === '' ? (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==') : $bytes;
    $source = LocatedJpeg::file($bytes, 'kitsune-visbulk0-');
    $entry = MediaLibrary::store($source, $name, test()->image, $visibility, title: $title);
    unlink($source);

    return Entry::query()->with('mediaFile')->findOrFail($entry->id);
}

/** @return array{string, bool} the row's visibility, and whether the public disk serves the file */
function bulkZeroState(Entry $entry): array
{
    $row = DB::table('media_files')->where('entry_id', $entry->id)->first(['visibility', 'path']);

    return [(string) $row->visibility, is_file(Storage::disk('public')->path((string) $row->path))];
}

/** @return list<array<string, mixed>> */
function bulkZeroNotices(): array
{
    return array_values((array) session('filament.notifications', []));
}

/* Z1. Each file commits on its own: one refused between two others leaves the one before published and the one after. */
it('commits and publishes each file on its own, a refusal between them leaving the rest switched', function (): void {
    $a = bulkZeroStored('A');
    $b = bulkZeroStored('B', bytes: LocatedJpeg::unremovable(), name: 'shared.jpg');
    $c = bulkZeroStored('C');

    MediaVisibilityActions::publicEach(BulkAction::make('makeSelectedPublic'), [$a, $b, $c], ['public_confirmed' => true]);

    expect([bulkZeroState($a), bulkZeroState($b), bulkZeroState($c)])->toBe([['public', true], ['private', false], ['public', true]])
        ->and(DB::transactionLevel())->toBe(0)
        ->and(bulkZeroNotices())->toHaveCount(1)
        ->and(bulkZeroNotices()[0]['title'])->toBe('One file was not made public');
});

/* Z2. A COMMIT that does not land leaves its own file as it was, and the files either side of it switched. */
it('leaves only its own file as it was when a COMMIT does not land', function (): void {
    $files = [bulkZeroStored('One'), bulkZeroStored('Two'), bulkZeroStored('Three')];
    $pdo = FailingCommitPdo::installOn(DB::connection(), $this->custodyFile);
    $n = 0;
    AuditorStandIn::install()->beforeRecording(function () use (&$n, $pdo): void {
        if (++$n === 2) {
            $pdo->failNextCommit = 'before';
        }
    });

    MediaVisibilityActions::publicEach(BulkAction::make('makeSelectedPublic'), $files, ['public_confirmed' => true]);

    expect(array_map('bulkZeroState', $files))->toBe([['public', true], ['private', false], ['public', true]])
        ->and(bulkZeroNotices()[0]['title'])->toBe('One file was not made public')
        ->and(bulkZeroNotices()[0]['body'])->toStartWith('&quot;Two&quot; may not have been made public: something went wrong.')
        ->and($pdo->inTransaction())->toBeFalse();
});

/* Z3. A COMMIT that lands and reports failure: read again, the file is counted as what it is — public, and published. */
it('counts a file whose COMMIT landed and reported failure as what it is', function (): void {
    Exceptions::fake();
    $files = [bulkZeroStored('One'), bulkZeroStored('Two'), bulkZeroStored('Three')];
    $pdo = FailingCommitPdo::installOn(DB::connection(), $this->custodyFile);
    $n = 0;
    AuditorStandIn::install()->beforeRecording(function () use (&$n, $pdo): void {
        if (++$n === 2) {
            $pdo->failNextCommit = 'after';
        }
    });
    $action = BulkAction::make('makeSelectedPublic');

    MediaVisibilityActions::publicEach($action, $files, ['public_confirmed' => true]);

    expect(array_map('bulkZeroState', $files))->toBe([['public', true], ['public', true], ['public', true]])
        ->and(bulkZeroNotices())->toHaveCount(1)
        ->and(bulkZeroNotices()[0]['status'])->toBe('success')
        ->and(bulkZeroNotices()[0]['title'])->toBe('3 files were made public')
        ->and($action->getStatus()->name)->toBe('Success');

    Exceptions::assertReportedCount(1);
});

/* Z4. Made private: a COMMIT that does not land leaves its file on the web and named; one that lands, off it and counted. */
it('names a file made private whose COMMIT did not land, and counts one whose COMMIT did', function (string $commit): void {
    Exceptions::fake();
    $files = [bulkZeroStored('One', 'public'), bulkZeroStored('Two', 'public'), bulkZeroStored('Three', 'public')];
    $pdo = FailingCommitPdo::installOn(DB::connection(), $this->custodyFile);
    $n = 0;
    AuditorStandIn::install()->beforeRecording(function () use (&$n, $pdo, $commit): void {
        if (++$n === 2) {
            $pdo->failNextCommit = $commit;
        }
    });

    MediaVisibilityActions::privateEach(BulkAction::make('makeSelectedPrivate'), $files);

    $two = $commit === 'before' ? ['public', true] : ['private', false];

    expect(array_map('bulkZeroState', $files))->toBe([['private', false], $two, ['private', false]])
        ->and(bulkZeroNotices()[0]['title'])->toBe($commit === 'before' ? 'One file was not made private' : '3 files were made private');

    if ($commit === 'before') {
        expect(bulkZeroNotices()[0]['body'])->toStartWith('&quot;Two&quot; may not have been made private: something went wrong.');
    }
})->with(['before', 'after']);
