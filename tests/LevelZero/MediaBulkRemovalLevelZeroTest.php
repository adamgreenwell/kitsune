<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Filament\MediaBulkRemoval;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Media\MediaWithdrawalRefused;
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
 * A media list's selection removed entry by entry, each COMMIT its own — ADR-042 decision 35, at a real level 0.
 *
 * ⚠️ NO TRANSACTION AROUND A SELECTION. Each entry commits — and, restored, publishes — before the next begins, so an
 * entry refused or a COMMIT that does not land leaves every other as its own write left it, and one that lands and
 * reports failure is counted as what it is. Under `RefreshDatabase` no COMMIT is the outermost and nothing publishes,
 * so none of this can be seen elsewhere. The system acts.
 */

beforeEach(function (): void {
    $this->roots = [];
    $this->disks = [];

    foreach (['public', MediaDisks::PRIVATE] as $name) {
        $root = sys_get_temp_dir().'/kitsune-bulkrm0-'.$name.'-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        $this->roots[] = $root;
        $this->disks[$name] = RefusingDisk::install($name, $root);
    }

    $this->org = Org::create(['slug' => 'removal-zero', 'name' => 'Removal zero']);
    app(Context::class)->setOrg($this->org);
    app(Context::class)->setSite(Site::create(['handle' => 'main', 'slug' => 'removal-zero-main', 'name' => 'Main', 'locale' => 'en']));
    $this->image = EntryType::create(['org_id' => $this->org->id, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);

    session()->forget('filament.notifications');
});

afterEach(function (): void {
    app(Context::class)->forget();

    foreach ($this->roots ?? [] as $root) {
        exec('rm -rf '.escapeshellarg($root));
    }
});

/** A stored public PNG, titled — trashed where asked — loaded as the grid loads it. */
function bulkRmZeroStored(string $title, bool $trashed = false): Entry
{
    $source = LocatedJpeg::file((string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='), 'kitsune-bulkrm0-');
    $entry = MediaLibrary::store($source, 'logo.png', test()->image, 'public', title: $title);
    unlink($source);

    if ($trashed) {
        $entry->delete();
    }

    return Entry::withTrashed()->with('mediaFile')->findOrFail($entry->id);
}

/** @return array{string, bool} where the entry is, and whether the public disk serves its file */
function bulkRmZeroState(Entry $entry): array
{
    $row = DB::table('entries')->where('id', $entry->id)->first(['deleted_at']);
    $path = (string) DB::table('media_files')->where('entry_id', $entry->id)->value('path');

    return [$row === null ? 'gone' : ($row->deleted_at === null ? 'live' : 'trashed'), $path !== '' && is_file(Storage::disk('public')->path($path))];
}

/** @return list<array<string, mixed>> */
function bulkRmZeroNotices(): array
{
    return array_values((array) session('filament.notifications', []));
}

/* Z1. Each entry commits on its own: one refused between two others leaves both trashed and itself on the web. */
it('trashes each entry on its own in one run, a refusal between them leaving the rest trashed', function (): void {
    $a = bulkRmZeroStored('A');
    $b = bulkRmZeroStored('B');
    $c = bulkRmZeroStored('C');
    // B's withdrawal refused, in custody's own words, as a disk that will not let its copy go refuses it.
    Entry::deleting(static function (Entry $entry) use ($b): void {
        if ($entry->id === $b->id) {
            throw new MediaWithdrawalRefused((int) $b->id, MediaWithdrawalRefused::DELETE_FAILED, 'public', 'trash');
        }
    });

    MediaBulkRemoval::each(DeleteBulkAction::make(), [$a, $b, $c], MediaBulkRemoval::DELETE);

    expect([bulkRmZeroState($a), bulkRmZeroState($b), bulkRmZeroState($c)])->toBe([['trashed', false], ['live', true], ['trashed', false]])
        ->and(DB::transactionLevel())->toBe(0)
        ->and(bulkRmZeroNotices())->toHaveCount(1)
        ->and(bulkRmZeroNotices()[0]['title'])->toBe('One entry was not deleted');
});

/* Z2. A COMMIT that does not land leaves only its own entry live, named; the ones either side trashed. */
it('leaves only its own entry live when a COMMIT does not land', function (): void {
    $files = [bulkRmZeroStored('One'), bulkRmZeroStored('Two'), bulkRmZeroStored('Three')];
    $pdo = FailingCommitPdo::installOn(DB::connection(), $this->custodyFile);
    $n = 0;
    AuditorStandIn::install()->beforeRecording(function () use (&$n, $pdo): void {
        if (++$n === 2) {
            $pdo->failNextCommit = 'before';
        }
    });

    MediaBulkRemoval::each(DeleteBulkAction::make(), $files, MediaBulkRemoval::DELETE);

    expect(array_map('bulkRmZeroState', $files))->toBe([['trashed', false], ['live', true], ['trashed', false]])
        ->and(bulkRmZeroNotices()[0]['title'])->toBe('One entry was not deleted')
        ->and(bulkRmZeroNotices()[0]['body'])->toStartWith('&quot;Two&quot; may not have been deleted: something went wrong.')
        ->and($pdo->inTransaction())->toBeFalse();
});

/* Z3. A COMMIT that lands and reports failure: read again, counted as what it is. */
it('counts an entry whose COMMIT landed and reported failure as deleted', function (): void {
    Exceptions::fake();
    $files = [bulkRmZeroStored('One'), bulkRmZeroStored('Two'), bulkRmZeroStored('Three')];
    $pdo = FailingCommitPdo::installOn(DB::connection(), $this->custodyFile);
    $n = 0;
    AuditorStandIn::install()->beforeRecording(function () use (&$n, $pdo): void {
        if (++$n === 2) {
            $pdo->failNextCommit = 'after';
        }
    });
    $action = DeleteBulkAction::make();

    MediaBulkRemoval::each($action, $files, MediaBulkRemoval::DELETE);

    expect(array_map(static fn (Entry $entry): string => bulkRmZeroState($entry)[0], $files))->toBe(['trashed', 'trashed', 'trashed'])
        ->and(bulkRmZeroNotices()[0]['title'])->toBe('3 entries were deleted')
        ->and($action->getStatus()->name)->toBe('Success');

    Exceptions::assertReportedCount(1);
});

/* Z3a. An erasure whose COMMIT landed and reported failure: read again, counted as deleted forever. */
it('counts an entry whose erasure COMMIT landed and reported failure as deleted forever', function (): void {
    Exceptions::fake();
    $files = [bulkRmZeroStored('One', trashed: true), bulkRmZeroStored('Two', trashed: true)];
    $pdo = FailingCommitPdo::installOn(DB::connection(), $this->custodyFile);
    $n = 0;
    AuditorStandIn::install()->beforeRecording(function () use (&$n, $pdo): void {
        if (++$n === 1) {
            $pdo->failNextCommit = 'after';
        }
    });
    $action = ForceDeleteBulkAction::make();

    MediaBulkRemoval::each($action, $files, MediaBulkRemoval::ERASE);

    expect(array_map(static fn (Entry $entry): string => bulkRmZeroState($entry)[0], $files))->toBe(['gone', 'gone'])
        ->and(bulkRmZeroNotices()[0]['title'])->toBe('2 entries were deleted forever')
        ->and($action->getStatus()->name)->toBe('Success');

    Exceptions::assertReportedCount(1);
});

/* Z4. Each restored public file is published before the next restore begins. */
it('publishes each restored file before the next restore begins', function (): void {
    $first = bulkRmZeroStored('First', trashed: true);
    $second = bulkRmZeroStored('Second', trashed: true);
    $seen = null;
    $n = 0;
    AuditorStandIn::install()->beforeRecording(function () use (&$n, &$seen, $first): void {
        if (++$n === 2) {
            $seen = bulkRmZeroState($first);
        }
    });

    MediaBulkRemoval::each(RestoreBulkAction::make(), [$first, $second], MediaBulkRemoval::RESTORE);

    expect($seen)->toBe(['live', true])
        ->and(bulkRmZeroState($second))->toBe(['live', true])
        ->and(bulkRmZeroNotices()[0]['title'])->toBe('2 entries were restored');
});

/* Z4a. And through the handler the page calls: no transaction around the selection, each entry at its own level 1. */
it('runs a selection through the handler with no transaction around it', function (): void {
    $first = bulkRmZeroStored('First', trashed: true);
    $second = bulkRmZeroStored('Second', trashed: true);
    $seen = null;
    $n = 0;
    AuditorStandIn::install()->beforeRecording(function () use (&$n, &$seen, $first): void {
        if (++$n === 2) {
            $seen = [bulkRmZeroState($first), DB::transactionLevel()];
        }
    });

    MediaBulkRemoval::selected(RestoreBulkAction::make(), Entry::withTrashed()->with('mediaFile')->whereKey([$first->id, $second->id])->orderBy('id'), MediaBulkRemoval::RESTORE);

    expect($seen)->toBe([['live', true], 1])
        ->and(bulkRmZeroNotices()[0]['title'])->toBe('2 entries were restored');
});

/* Z5. A restore whose COMMIT landed and reported failure: counted as what it is, published. */
it('says what a restore whose COMMIT landed and reported failure left on the web', function (): void {
    Exceptions::fake();
    $entry = bulkRmZeroStored('One', trashed: true);
    $pdo = FailingCommitPdo::installOn(DB::connection(), $this->custodyFile);
    AuditorStandIn::install()->beforeRecording(static function () use ($pdo): void {
        $pdo->failNextCommit = 'after';
    });

    MediaBulkRemoval::each(RestoreBulkAction::make(), [$entry], MediaBulkRemoval::RESTORE);

    // The restore registers its publication again for a COMMIT that may have landed (decision 5), so it is published.
    expect(bulkRmZeroState($entry))->toBe(['live', true])
        ->and(bulkRmZeroNotices()[0]['title'])->toBe('One entry was restored');

    Exceptions::assertReportedCount(1);
});

/* Z6. And where that publication fails too: restored, and said as not yet published, with its command. */
it('names a restore whose COMMIT landed and whose publication failed as not yet published', function (): void {
    Exceptions::fake();
    $entry = bulkRmZeroStored('One', trashed: true);
    $this->disks['public']->failWrites = true;
    $pdo = FailingCommitPdo::installOn(DB::connection(), $this->custodyFile);
    AuditorStandIn::install()->beforeRecording(static function () use ($pdo): void {
        $pdo->failNextCommit = 'after';
    });

    MediaBulkRemoval::each(RestoreBulkAction::make(), [$entry], MediaBulkRemoval::RESTORE);

    expect(bulkRmZeroState($entry))->toBe(['live', false])
        ->and(bulkRmZeroNotices()[0]['title'])->toBe('One entry was restored, and its file is not yet published')
        ->and(bulkRmZeroNotices()[0]['body'])->toContain("--entry={$entry->id}");
});
