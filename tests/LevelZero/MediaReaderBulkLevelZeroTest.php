<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Filament\Actions\BulkAction;
use Filament\Actions\Enums\ActionStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Filament\MediaReaderBulk;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Media\MediaVisibility;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\AuditorStandIn;
use Kitsune\Core\Tests\Fixtures\FailingCommitPdo;
use Kitsune\Core\Tests\Fixtures\LocatedJpeg;
use Kitsune\Core\Tests\Fixtures\PanelTenancy;
use Kitsune\Core\Tests\Fixtures\RefusingDisk;
use Kitsune\Core\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    config(['auth.providers.users.model' => TestUser::class]);
    $this->roots = [];
    foreach (['public', MediaDisks::PRIVATE] as $name) {
        $root = sys_get_temp_dir().'/kitsune-readerbulk0-'.$name.'-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        $this->roots[] = $root;
        RefusingDisk::install($name, $root);
    }
    $this->org = Org::create(['slug' => 'reader-bulk-zero', 'name' => 'Reader bulk zero']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'reader-bulk-zero-main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);
    $this->image = EntryType::create(['org_id' => $this->org->id, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);
    app()->instance(EntryType::class, $this->image);
    $user = TestUser::create(['email' => 'bulk-zero@kitsune.test']);
    DB::table('org_user')->insert(['org_id' => $this->org->id, 'user_id' => $user->getKey()]);
    $role = Role::create(['handle' => 'bulk-zero', 'name' => 'Bulk zero']);
    DB::table('role_user')->insert(['role_id' => $role->id, 'user_id' => $user->getKey()]);
    $role->grant('entry.image.view');
    $role->grant('entry.image.publish');
    $this->actingAs($user);
    PanelTenancy::enter($this->site);
    session()->forget('filament.notifications');
});

afterEach(function (): void {
    app(Context::class)->forget();
    foreach ($this->roots ?? [] as $root) {
        exec('rm -rf '.escapeshellarg($root));
    }
});

function readerBulkZeroStored(string $title, bool $siteOnly = true): Entry
{
    $source = LocatedJpeg::file((string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='), 'kitsune-readerbulk0-');
    $user = auth()->user();
    auth()->forgetUser();
    try {
        return MediaLibrary::store($source, 'report.png', test()->image, 'private', title: $title, siteOnly: $siteOnly);
    } finally {
        unlink($source);
        if ($user !== null) {
            test()->actingAs($user);
        }
    }
}

function readerBulkZeroName(Entry $entry): mixed
{
    return DB::table('media_files')->where('entry_id', $entry->id)->value('reader_entitlement');
}

/** @return list<array<string, mixed>> */
function readerBulkZeroNotices(): array
{
    return array_values((array) session('filament.notifications', []));
}

const READER_BULK_ZERO_CONFIRMED = ['entitlement' => 'course.selected', 'confirmation' => 'course.selected'];

it('commits each successful offer at level zero and never rewrites its private bytes', function (): void {
    $one = readerBulkZeroStored('One');
    $shared = readerBulkZeroStored('Shared', false);
    $three = readerBulkZeroStored('Three');
    $rows = DB::table('media_files')->whereIn('entry_id', [$one->id, $shared->id, $three->id])->orderBy('entry_id')->get();
    $bytes = $rows->map(fn ($row) => Storage::disk($row->disk)->get($row->path))->all();
    MediaReaderBulk::offerEach(BulkAction::make('offerSelectedToReaders'), [$one, $shared, $three], READER_BULK_ZERO_CONFIRMED);
    expect(array_map('readerBulkZeroName', [$one, $shared, $three]))->toBe(['course.selected', null, 'course.selected'])
        ->and($rows->map(fn ($row) => Storage::disk($row->disk)->get($row->path))->all())->toBe($bytes)
        ->and(DB::table('audit_log')->where('action', MediaVisibility::OFFERED)->count())->toBe(2)
        ->and(DB::transactionLevel())->toBe(0)
        ->and(readerBulkZeroNotices())->toHaveCount(1);
});

it('distinguishes a commit that did not land from one that landed and reported failure', function (string $commit): void {
    Exceptions::fake();
    $files = [readerBulkZeroStored('One'), readerBulkZeroStored('Two'), readerBulkZeroStored('Three')];
    $pdo = FailingCommitPdo::installOn(DB::connection(), $this->custodyFile);
    $count = 0;
    AuditorStandIn::install()->beforeRecording(function () use (&$count, $pdo, $commit): void {
        if (++$count === 2) {
            $pdo->failNextCommit = $commit;
        }
    });
    $action = BulkAction::make('offerSelectedToReaders');
    MediaReaderBulk::offerEach($action, $files, READER_BULK_ZERO_CONFIRMED);
    expect(array_map('readerBulkZeroName', $files))->toBe(['course.selected', $commit === 'before' ? null : 'course.selected', 'course.selected'])
        ->and(DB::table('audit_log')->where('action', MediaVisibility::OFFERED)->count())->toBe($commit === 'before' ? 2 : 3)
        ->and($action->getStatus())->toBe($commit === 'before' ? ActionStatus::Failure : ActionStatus::Success)
        ->and($pdo->inTransaction())->toBeFalse()
        ->and(DB::transactionLevel())->toBe(0);
    Exceptions::assertReportedCount(1);
})->with(['before', 'after']);

it('rolls back an unauditable offer without undoing a later successful file or exposing the exception', function (): void {
    Exceptions::fake();
    $one = readerBulkZeroStored('One');
    $two = readerBulkZeroStored('Two');
    AuditorStandIn::install()->throwOnce(new RuntimeException('PRIVATE DRIVER CANARY'));
    $action = BulkAction::make('offerSelectedToReaders');
    MediaReaderBulk::offerEach($action, [$one, $two], READER_BULK_ZERO_CONFIRMED);
    expect([readerBulkZeroName($one), readerBulkZeroName($two)])->toBe([null, 'course.selected'])
        ->and(DB::table('audit_log')->where('action', MediaVisibility::OFFERED)->count())->toBe(1)
        ->and($action->getStatus())->toBe(ActionStatus::Failure)
        ->and(json_encode(readerBulkZeroNotices()))->not->toContain('PRIVATE DRIVER CANARY')
        ->and(DB::transactionLevel())->toBe(0);
    Exceptions::assertReportedCount(1);
});
