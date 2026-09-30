<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Kitsune\Core\Filament\EntryTrash;
use Kitsune\Core\Filament\MediaDeletionNotice;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;
use Kitsune\Core\Filament\Resources\Entries\Pages\ListEntries;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\AuditorStandIn;
use Kitsune\Core\Tests\Fixtures\PanelTenancy;
use Kitsune\Core\Tests\Fixtures\RefusingDisk;
use Kitsune\Core\Tests\Fixtures\TestUser;

/*
 * The trash — ADR-042 decision 31: an entry list's trashed entries, restored or deleted forever.
 *
 * ⚠️ FROM THE ROWS AS THEY ARE AFTERWARDS. Every case reads whether the entry is live, trashed or gone, and what the
 * disks hold, so an action that reported success and did something else cannot pass. The page itself — the filter, the
 * actions a trashed card shows, a restored public file on the web again — is `media-trash.spec.js`'s.
 */

beforeEach(function (): void {
    $this->roots = [];

    foreach (['public', MediaDisks::PRIVATE] as $name) {
        $root = sys_get_temp_dir().'/kitsune-trash-'.$name.'-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        $this->roots[] = $root;
        $this->disks[$name] = RefusingDisk::install($name, $root);
    }

    $org = Org::create(['slug' => 'trash', 'name' => 'Trash']);
    app(Context::class)->setOrg($org);
    app(Context::class)->setSite(Site::create(['handle' => 'main', 'slug' => 'trash-main', 'name' => 'Main', 'locale' => 'en']));
    $this->image = EntryType::create(['org_id' => $org->id, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);
    $this->article = EntryType::create(['org_id' => $org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);

    $this->store = function (string $title, string $visibility = 'private'): Entry {
        $source = tempnam(sys_get_temp_dir(), 'kitsune-trash-');
        file_put_contents($source, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $entry = MediaLibrary::store($source, 'upload.png', $this->image, $visibility, title: $title);
        unlink($source);

        return $entry;
    };
    $this->write = fn (string $title): Entry => Entry::create(['entry_type_id' => $this->article->id, 'title' => $title, 'status' => 'draft']);

    session()->forget('filament.notifications');
});

afterEach(function (): void {
    app(Context::class)->forget();

    foreach ($this->roots as $root) {
        exec('rm -rf '.escapeshellarg($root));
    }
});

/** @return list<array<string, mixed>> */
function trashNotices(): array
{
    return array_values((array) session('filament.notifications', []));
}

/** Where an entry is: live, trashed, or gone. */
function whereIs(Entry $entry): string
{
    $row = DB::table('entries')->where('id', $entry->id)->first(['deleted_at']);

    return $row === null ? 'gone' : ($row->deleted_at === null ? 'live' : 'trashed');
}

/** The entry as the trash lists it: loaded with its trashed state, as Filament's filter loads it. */
function inTrash(Entry $entry): Entry
{
    return Entry::withTrashed()->findOrFail($entry->id);
}

describe('deleting forever', function (): void {
    it('deletes a trashed entry forever, and its file, and says nothing', function (): void {
        $entry = ($this->store)('Logo');
        $path = (string) DB::table('media_files')->where('entry_id', $entry->id)->value('path');
        $entry->delete();

        expect(EntryTrash::forceDeleteOne(inTrash($entry)))->toBeTrue()
            ->and(whereIs($entry))->toBe('gone')
            ->and(DB::table('media_files')->where('entry_id', $entry->id)->exists())->toBeFalse()
            ->and(is_file($this->disks[MediaDisks::PRIVATE]->root().'/'.$path))->toBeFalse()
            ->and(trashNotices())->toBe([]);
    });

    /* Only what is in the trash: a live entry is named and left, never erased past the trash. */
    it('leaves a live entry, and says it is not in the trash, escaped', function (): void {
        $entry = ($this->write)('<b>Draft</b>');

        expect(EntryTrash::forceDeleteOne($entry))->toBeFalse()
            ->and(whereIs($entry))->toBe('live');

        expect(trashNotices())->toHaveCount(1)
            ->and(trashNotices()[0]['status'])->toBe('danger')
            ->and(trashNotices()[0]['title'])->toBe('&quot;&lt;b&gt;Draft&lt;/b&gt;&quot; was not deleted forever')
            ->and(trashNotices()[0]['body'])->toBe('it is not in the trash. Move it to the trash first.');
    });

    /* An erasure custody refuses is said, as a refused trash is, and the entry stays in the trash. */
    it('answers an erasure custody refused with a notification, and keeps the entry in the trash', function (): void {
        $entry = ($this->store)('Scorecard', 'public');
        $entry->delete();
        config(['kitsune.media.disks.private' => 'public']);

        expect(EntryTrash::forceDeleteOne(inTrash($entry)))->toBeFalse()
            ->and(whereIs($entry))->toBe('trashed')
            ->and(trashNotices()[0]['title'] ?? null)->toBe('&quot;Scorecard&quot; was not deleted forever')
            ->and(trashNotices()[0]['body'] ?? '')->toStartWith("Refusing to erase entry {$entry->id}");
    });

    it('lets any other failure through, as Filament would have it', function (): void {
        $entry = ($this->write)('Draft');
        $entry->delete();
        AuditorStandIn::install()->beforeRecording(fn () => throw new RuntimeException('the audit row could not be written'));

        expect(fn () => EntryTrash::forceDeleteOne(inTrash($entry)))->toThrow(RuntimeException::class, 'the audit row could not be written');
        expect(whereIs($entry))->toBe('trashed');
    });

    it('deletes each trashed entry forever on its own, and names every one it left, in one notice', function (): void {
        $live = ($this->write)('Still live');
        $refused = ($this->store)('<i>Scorecard</i>', 'public');
        $erased = ($this->write)('Old draft');
        $refused->delete();
        $erased->delete();
        config(['kitsune.media.disks.private' => 'public']);

        $action = ForceDeleteBulkAction::make();
        EntryTrash::forceDeleteEach($action, [$live, inTrash($refused), inTrash($erased)]);

        $notice = trashNotices()[0] ?? [];

        expect(whereIs($live))->toBe('live')
            ->and(whereIs($refused))->toBe('trashed')
            ->and(whereIs($erased))->toBe('gone')
            ->and(trashNotices())->toHaveCount(1)
            ->and($notice['title'])->toBe('2 entries were not deleted forever')
            ->and($notice['body'])->toStartWith('&quot;Still live&quot; was not deleted forever: it is not in the trash. Move it to the trash first.<br>')
            ->and($notice['body'])->toContain('&quot;&lt;i&gt;Scorecard&lt;/i&gt;&quot; was not deleted forever: Refusing to erase entry '.$refused->id)
            ->and($notice['body'])->toEndWith('The other entry was deleted forever.')
            // Instead of Filament's own count, which says less, and would say it twice.
            ->and((fn (): bool => $this->isFailureNotificationDisabled)->call($action))->toBeTrue();
    });

    it('says nothing, and leaves Filament\'s count alone, when every trashed entry is deleted forever', function (): void {
        $entry = ($this->write)('Old draft');
        $entry->delete();

        $action = ForceDeleteBulkAction::make();
        EntryTrash::forceDeleteEach($action, [inTrash($entry)]);

        expect(whereIs($entry))->toBe('gone')
            ->and(trashNotices())->toBe([])
            ->and((fn (): bool => $this->isFailureNotificationDisabled)->call($action))->toBeFalse();
    });

    /* Any other failure keeps Filament's own count, which is the only thing that reports it. */
    it('keeps Filament\'s notification when a failure was not one it names', function (): void {
        $live = ($this->write)('Still live');
        $failing = ($this->write)('Old draft');
        $failing->delete();
        AuditorStandIn::install()->beforeRecording(fn () => throw new RuntimeException('the audit row could not be written'));

        $action = ForceDeleteBulkAction::make();
        EntryTrash::forceDeleteEach($action, [$live, inTrash($failing)]);

        expect(whereIs($failing))->toBe('trashed')
            ->and(trashNotices())->toHaveCount(1)
            ->and((fn (): bool => $this->isFailureNotificationDisabled)->call($action))->toBeFalse();
    });
});

describe('restoring', function (): void {
    it('restores each trashed entry, and leaves a live one as it was, unsaved', function (): void {
        $trashed = ($this->write)('Old draft');
        $trashed->delete();
        $live = ($this->write)('Still live');
        $stamp = DB::table('entries')->where('id', $live->id)->value('updated_at');
        $audits = DB::table('audit_log')->count();

        EntryTrash::restoreEach(RestoreBulkAction::make(), [inTrash($trashed), $live]);

        expect(whereIs($trashed))->toBe('live')
            ->and(whereIs($live))->toBe('live')
            ->and(DB::table('entries')->where('id', $live->id)->value('updated_at'))->toBe($stamp)
            // One audit row: the restore's. The live entry was not saved again.
            ->and(DB::table('audit_log')->count())->toBe($audits + 1);
    });

    it('puts a restored public file back on the web', function (): void {
        $entry = ($this->store)('Logo', 'public');
        $path = (string) DB::table('media_files')->where('entry_id', $entry->id)->value('path');
        $entry->delete();

        expect(is_file($this->disks['public']->root().'/'.$path))->toBeFalse();

        EntryTrash::restoreEach(RestoreBulkAction::make(), [inTrash($entry)]);

        expect(whereIs($entry))->toBe('live')
            ->and(is_file($this->disks['public']->root().'/'.$path))->toBeTrue();
    });

    it('counts a restore that fails, and reports the first', function (): void {
        $entry = ($this->write)('Old draft');
        $entry->delete();
        AuditorStandIn::install()->beforeRecording(fn () => throw new RuntimeException('the audit row could not be written'));

        Exceptions::fake();
        $action = RestoreBulkAction::make();
        EntryTrash::restoreEach($action, [inTrash($entry)]);

        expect(whereIs($entry))->toBe('trashed')
            ->and((fn (): int => $this->bulkProcessingFailureWithoutMessageCount)->call($action))->toBe(1);
        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'the audit row could not be written');
    });
});

/* A trashed entry selected beside live ones — the filter lists both — is not trashed again, which would move its date. */
it('deletes each live entry, and leaves one already in the trash as it was', function (): void {
    $trashed = ($this->write)('Old draft');
    $trashed->delete();
    $deletedAt = DB::table('entries')->where('id', $trashed->id)->value('deleted_at');
    $live = ($this->write)('Still live');
    $audits = DB::table('audit_log')->count();

    MediaDeletionNotice::deleteEach(DeleteBulkAction::make(), [inTrash($trashed), $live]);

    expect(whereIs($live))->toBe('trashed')
        ->and(DB::table('entries')->where('id', $trashed->id)->value('deleted_at'))->toBe($deletedAt)
        ->and(DB::table('audit_log')->count())->toBe($audits + 1);
});

describe('the list', function (): void {
    /* Inside the panel, signed in as an owner, so what an action shows is decided by the record alone. */
    beforeEach(function (): void {
        config(['auth.providers.users.model' => TestUser::class]);
        $user = TestUser::create(['email' => 'trash-owner@kitsune.test']);
        DB::table('org_user')->insert(['org_id' => app(Context::class)->orgId(), 'user_id' => $user->getKey()]);
        Role::create(['handle' => 'trash-owner', 'name' => 'Owner', 'is_owner' => true])->assignTo($user->getKey());
        $this->actingAs($user);
        PanelTenancy::enter(Site::query()->where('slug', 'trash-main')->firstOrFail());
    });

    /** The list's table, built as its page builds it, for the type bound. */
    function trashTable(EntryType $type): Table
    {
        app()->instance(EntryType::class, $type);

        return EntryResource::table(Table::make(app(ListEntries::class)));
    }

    it('offers the trash filter, and a trashed entry\'s restore and delete forever in place of view and edit', function (): void {
        $table = trashTable($this->article);
        $actions = collect($table->getRecordActions())->keyBy(fn ($action) => $action::class);
        $trashed = ($this->write)('Old draft');
        $trashed->delete();
        $trashed = inTrash($trashed);
        $live = ($this->write)('Still live');

        expect(collect($table->getFilters())->map(fn ($filter) => $filter::class)->values()->all())->toBe([TrashedFilter::class])
            ->and(collect($table->getFilters())->first()->getLabel())->toBe('Trash');

        foreach ([ViewAction::class, EditAction::class] as $class) {
            expect($actions[$class]->record($trashed)->isHidden())->toBeTrue()
                ->and($actions[$class]->record($live)->isHidden())->toBeFalse();
        }

        foreach ([RestoreAction::class, ForceDeleteAction::class] as $class) {
            expect($actions[$class]->record($trashed)->isVisible())->toBeTrue()
                ->and($actions[$class]->record($live)->isVisible())->toBeFalse();
        }
    });

    it('says what deleting forever takes with it, the file too on a media list', function (EntryType|string $type, string $words): void {
        $type = $type === 'image' ? $this->image : $this->article;
        $table = trashTable($type);
        $single = collect($table->getRecordActions())->first(fn ($action) => $action instanceof ForceDeleteAction);
        $bulk = collect($table->getToolbarActions()[0]->getActions())->first(fn ($action) => $action instanceof ForceDeleteBulkAction);

        expect($single->getModalDescription())->toBe($words)
            ->and($bulk->getModalDescription())->toBe($words)
            ->and($single->getLabel())->toBe('Delete forever')
            ->and($bulk->getLabel())->toBe('Delete selected forever')
            ->and($single->getModalSubmitActionLabel())->toBe('Delete forever')
            ->and($bulk->getModalSubmitActionLabel())->toBe('Delete forever');
    })->with([
        'an article list' => ['article', 'This cannot be undone. The entry and its history are deleted for good, and any link to it from another entry is removed.'],
        'a media list' => ['image', 'This cannot be undone. The entry, its file and its history are deleted for good, and any link to it from another entry is removed.'],
    ]);
});
