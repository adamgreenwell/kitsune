<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Filament\BulkSelection;
use Kitsune\Core\Filament\MediaBulkRemoval;
use Kitsune\Core\Filament\Resources\Entries\Pages\ListEntries;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Media\MediaWithdrawalRefused;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\AuditorStandIn;
use Kitsune\Core\Tests\Fixtures\LocatedJpeg;
use Kitsune\Core\Tests\Fixtures\PanelTenancy;
use Kitsune\Core\Tests\Fixtures\RefusingDisk;
use Kitsune\Core\Tests\Fixtures\TestUser;

/*
 * A media list's *Delete selected*, *Restore selected* and *Delete selected forever*, at most fifty at a time within the
 * request's budget — Adam, ADR-042 decision 35.
 *
 * ⚠️ FROM THE ROWS, THE DISKS AND THE ONE NOTIFICATION. Each entry's place — live, trashed or gone — is read back
 * afterwards, the public disk asked, the audit log counted, and the session's notifications read as the page shows them,
 * so a notice that claims what did not happen cannot pass. Commits that fail at a real level 0 are
 * `MediaBulkRemovalLevelZeroTest`'s; the page itself is `media-bulk-removal.spec.js`'s.
 */

beforeEach(function (): void {
    config(['auth.providers.users.model' => TestUser::class]);
    $this->roots = [];

    foreach (['public', MediaDisks::PRIVATE] as $name) {
        $root = sys_get_temp_dir().'/kitsune-bulkrm-'.$name.'-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        $this->roots[] = $root;
        $this->disks[$name] = RefusingDisk::install($name, $root);
    }

    $this->org = Org::create(['slug' => 'removals', 'name' => 'Removals']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'removals-main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);
    $this->type = EntryType::create(['org_id' => $this->org->id, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);
    app()->instance(EntryType::class, $this->type);

    /** @var TestUser $user */
    $user = TestUser::create(['email' => 'remover@kitsune.test']);
    DB::table('org_user')->insert(['org_id' => $this->org->getKey(), 'user_id' => $user->getKey()]);
    $this->role = Role::create(['handle' => 'remover', 'name' => 'Remover']);
    DB::table('role_user')->insert(['role_id' => $this->role->getKey(), 'user_id' => $user->getKey()]);

    foreach (['view', 'delete'] as $action) {
        $this->role->grant(Permissions::forEntryType('image', $action));
    }

    $this->actingAs($user);
    PanelTenancy::enter($this->site);

    // The pages a card can link to, which a test's panel does not register.
    Route::get('/admin/{tenant:slug}/c/{type}/{record}', static fn (): string => '')->name('filament.admin.resources.c.view');
    Route::get('/admin/{tenant:slug}/c/{type}/{record}/edit', static fn (): string => '')->name('filament.admin.resources.c.edit');
    app('router')->getRoutes()->refreshNameLookups();

    session()->forget('filament.notifications');
});

afterEach(function (): void {
    app(Context::class)->forget();

    foreach ($this->roots as $root) {
        exec('rm -rf '.escapeshellarg($root));
    }
});

/** A stored PNG, titled, stored by the system — and trashed, where asked, as the system too. */
function bulkRmStored(string $title, string $visibility = 'public', bool $trashed = false): Entry
{
    $source = LocatedJpeg::file((string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='), 'kitsune-bulkrm-');
    $user = auth()->user();
    auth()->forgetUser();

    try {
        $entry = MediaLibrary::store($source, 'logo.png', test()->type, $visibility, title: $title);

        if ($trashed) {
            $entry->delete();
        }

        return $entry;
    } finally {
        unlink($source);

        if ($user !== null) {
            test()->actingAs($user);
        }
    }
}

/** @return list<array<string, mixed>> */
function bulkRmNotices(): array
{
    return array_values((array) session('filament.notifications', []));
}

/** Where an entry is: live, trashed or gone. */
function bulkRmWhere(Entry $entry): string
{
    $row = DB::table('entries')->where('id', $entry->id)->first(['deleted_at']);

    return $row === null ? 'gone' : ($row->deleted_at === null ? 'live' : 'trashed');
}

/** Whether the public disk serves the entry's file at its path. */
function bulkRmServed(Entry $entry): bool
{
    $path = (string) DB::table('media_files')->where('entry_id', $entry->id)->value('path');

    return $path !== '' && is_file(Storage::disk('public')->path($path));
}

/**
 * The entries as the grid loads them, trashed ones too, in this order.
 *
 * @return EloquentCollection<int, Entry>
 */
function bulkRmListed(Entry ...$entries): EloquentCollection
{
    $ids = array_map(static fn (Entry $entry): int => (int) $entry->id, $entries);
    $loaded = Entry::withTrashed()->with('mediaFile')->whereKey($ids)->get()->keyBy('id');

    return new EloquentCollection(array_map(static fn (int $id): Entry => $loaded[$id], $ids));
}

/** A booted list page, its filters set where asked. */
function bulkRmPage(?array $filters = null): ListEntries
{
    $page = app(ListEntries::class);

    if ($filters !== null) {
        $page->tableFilters = $filters;
    }

    $page->bootedInteractsWithTable();

    return $page;
}

/**
 * The list's toolbar actions, by name, for the type bound.
 *
 * @return array<string, Action>
 */
function bulkRmActions(?array $filters = null): array
{
    return collect(bulkRmPage($filters)->getTable()->getToolbarActions()[0]->getActions())
        ->keyBy(static fn (Action $action): string => (string) $action->getName())
        ->all();
}

function bulkRmAction(string $name): BulkAction
{
    $action = bulkRmActions()[$name];
    assert($action instanceof BulkAction);

    return $action;
}

/** @return Builder<Entry> the list's own query, below its filters */
function bulkRmList(): Builder
{
    return bulkRmPage()->getTable()->getQuery();
}

/** Ids, as the page holds them. */
function bulkRmKeys(Entry ...$entries): array
{
    return array_map(static fn (Entry $entry): string => (string) $entry->id, $entries);
}

/** The verbs and what each needs: Delete takes live entries, the other two trashed ones. */
dataset('verbs', [
    'delete' => [MediaBulkRemoval::DELETE, 'delete', false, 'trashed', 'deleted'],
    'restore' => [MediaBulkRemoval::RESTORE, 'restore', true, 'live', 'restored'],
    'delete forever' => [MediaBulkRemoval::ERASE, 'forceDelete', true, 'gone', 'deleted forever'],
]);

describe('the actions', function (): void {
    /* A1. Filament's own three, by name, class and place, on every list. */
    it('keeps Filament\'s three, in their place, on every list', function (): void {
        $actions = bulkRmActions();

        expect(array_keys($actions))->toBe(['makeSelectedPublic', 'makeSelectedPrivate', 'delete', 'restore', 'forceDelete'])
            ->and($actions['delete'])->toBeInstanceOf(DeleteBulkAction::class)
            ->and($actions['restore'])->toBeInstanceOf(RestoreBulkAction::class)
            ->and($actions['forceDelete'])->toBeInstanceOf(ForceDeleteBulkAction::class)
            ->and($actions['forceDelete']->getLabel())->toBe('Delete selected forever')
            ->and($actions['forceDelete']->getModalSubmitActionLabel())->toBe('Delete forever');

        app()->instance(EntryType::class, EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']));
        $articles = bulkRmActions();

        expect(array_keys($articles))->toBe(['delete', 'restore', 'forceDelete'])
            ->and($articles['delete'])->toBeInstanceOf(DeleteBulkAction::class)
            ->and($articles['forceDelete']->getLabel())->toBe('Delete selected forever');
    });

    /* A2. A media list's three outside any transaction, Filament's notices off, cleared on success alone; no other list's. */
    it('runs a media list\'s three one by one, and leaves every other list\'s as they were', function (): void {
        Action::configureUsing(static fn (Action $action) => $action->databaseTransaction()->successNotificationTitle('Host success')->failureNotificationTitle('Host failure'));

        foreach (['delete', 'restore', 'forceDelete'] as $name) {
            $action = bulkRmActions()[$name];
            assert($action instanceof BulkAction);

            expect($action->hasDatabaseTransactions())->toBeFalse($name)
                ->and((fn (): bool => $this->isSuccessNotificationDisabled)->call($action))->toBeTrue($name)
                ->and((fn (): bool => $this->isFailureNotificationDisabled)->call($action))->toBeTrue($name);

            $action->success();
            expect($action->shouldDeselectRecordsAfterCompletion())->toBeTrue($name);
            $action->failure();
            expect($action->shouldDeselectRecordsAfterCompletion())->toBeFalse($name);
        }

        app()->instance(EntryType::class, EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']));

        foreach (['delete', 'restore', 'forceDelete'] as $name) {
            $action = bulkRmActions()[$name];
            assert($action instanceof BulkAction);
            $action->failure();

            expect($action->hasDatabaseTransactions())->toBeTrue($name)
                ->and((fn (): bool => $this->isSuccessNotificationDisabled)->call($action))->toBeFalse($name)
                ->and($action->shouldDeselectRecordsAfterCompletion())->toBeTrue($name);
        }
    });

    /* A3. Filament's trash-filter rules and the policy's bulk abilities, untouched. */
    it('keeps Filament\'s trash-filter rules', function (mixed $value, bool $deleteHidden, bool $restoreHidden): void {
        $actions = bulkRmActions($value === 'unset' ? [] : ['trashed' => ['value' => $value]]);

        expect($actions['delete']->isHidden())->toBe($deleteHidden)
            ->and($actions['restore']->isHidden())->toBe($restoreHidden)
            ->and($actions['forceDelete']->isHidden())->toBe($restoreHidden);
    })->with([
        'no filter' => ['unset', false, false],
        'without the trash' => ['', false, true],
        'with the trash' => ['1', false, false],
        'only the trash' => ['0', true, false],
    ]);

    it('asks the policy\'s bulk abilities, as Filament does', function (): void {
        foreach (['delete', 'restore', 'forceDelete'] as $name) {
            expect(bulkRmActions()[$name]->isAuthorized())->toBeTrue($name);
        }

        // A reader: another user, whose role grants view alone.
        $reader = TestUser::create(['email' => 'reader@kitsune.test']);
        DB::table('org_user')->insert(['org_id' => $this->org->getKey(), 'user_id' => $reader->getKey()]);
        $role = Role::create(['handle' => 'reader', 'name' => 'Reader']);
        DB::table('role_user')->insert(['role_id' => $role->getKey(), 'user_id' => $reader->getKey()]);
        $role->grant(Permissions::forEntryType('image', 'view'));
        $this->actingAs($reader);

        foreach (['delete', 'restore', 'forceDelete'] as $name) {
            expect(bulkRmActions()[$name]->isAuthorized())->toBeFalse($name);
        }
    });

    /* A4. The modal says before it is submitted that more than fifty will not be removed — counted, never fetched. */
    it('says in the modal that more than fifty will not be removed, counting and never loading them', function (): void {
        $ids = [];

        for ($i = 1; $i <= 51; $i++) {
            $ids[] = (string) bulkRmStored("File {$i}", 'private')->id;
        }

        $page = bulkRmPage();
        $page->selectedTableRecords = $ids;
        $actions = collect($page->getTable()->getToolbarActions()[0]->getActions())->keyBy(static fn (Action $action): string => (string) $action->getName());
        $loaded = 0;
        Entry::retrieved(static function () use (&$loaded): void {
            $loaded++;
        });

        expect($actions['delete']->getModalDescription())->toBe('At most 50 entries are deleted at a time, and 51 are selected, so as it is nothing will be deleted. Select fewer first.')
            ->and($loaded)->toBe(0);

        $page->selectedTableRecords = array_slice($ids, 0, 50);

        expect($actions['delete']->getModalDescription())->toBe(__('filament-actions::modal.confirmation'))
            ->and(MediaBulkRemoval::note(MediaBulkRemoval::ERASE, 50))->toBe(__('kitsune::trash.erase_warning_media'))
            ->and(MediaBulkRemoval::note(MediaBulkRemoval::ERASE, 51))->toBe(__('kitsune::trash.erase_warning_media').' At most 50 entries are deleted forever at a time, and 51 are selected, so as it is nothing will be deleted. Select fewer first.')
            ->and(MediaBulkRemoval::note(MediaBulkRemoval::RESTORE, 51))->toStartWith('At most 50 entries are restored at a time, and 51 are selected')
            ->and(MediaBulkRemoval::note(MediaBulkRemoval::RESTORE, 50))->toBeNull()
            ->and($loaded)->toBe(0);
    });

    /* A5. A list that holds no media keeps its own three: decision 31's skips and refusal, through the page. */
    it('keeps every other list\'s three as they were, through the page', function (): void {
        foreach (['view', 'delete'] as $ability) {
            $this->role->grant(Permissions::forEntryType('article', $ability));
        }
        $article = EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);
        app()->instance(EntryType::class, $article);
        $live = Entry::create(['entry_type_id' => $article->id, 'title' => 'Live', 'status' => 'draft']);
        $trashed = Entry::create(['entry_type_id' => $article->id, 'title' => 'Trashed', 'status' => 'draft']);
        $trashed->delete();
        $updatedAt = DB::table('entries')->where('id', $live->id)->value('updated_at');
        $this->travel(1)->minutes();
        $call = static function (string $name) use ($live, $trashed): void {
            $page = bulkRmPage(['trashed' => ['value' => '1']]);
            $page->selectedTableRecords = bulkRmKeys($live, $trashed);
            collect($page->getTable()->getToolbarActions()[0]->getActions())->first(static fn (Action $action): bool => $action->getName() === $name)->call();
        };

        // Delete selected leaves the one already in the trash as it was; Restore selected, the live one.
        $call('restore');
        expect(DB::table('entries')->where('id', $live->id)->value('updated_at'))->toBe($updatedAt)
            ->and(bulkRmWhere($trashed))->toBe('live');

        $trashed->refresh()->delete();
        $deletedAt = DB::table('entries')->where('id', $trashed->id)->value('deleted_at');
        $this->travel(1)->minutes();
        $call('delete');
        expect(DB::table('entries')->where('id', $trashed->id)->value('deleted_at'))->toBe($deletedAt)
            ->and(bulkRmWhere($live))->toBe('trashed');

        // Delete selected forever names a live one and leaves it, in decision 31's words.
        Entry::withTrashed()->findOrFail($live->id)->restore();
        session()->forget('filament.notifications');
        $call('forceDelete');
        expect([bulkRmWhere($live), bulkRmWhere($trashed)])->toBe(['live', 'gone'])
            ->and(collect(bulkRmNotices())->pluck('body')->implode(' '))->toContain('&quot;Live&quot; was not deleted forever: it is not in the trash.');
    });
});

describe('the bound', function (): void {
    /* B1. More than fifty changes nothing; fifty is not too many. */
    it('changes nothing when more than fifty are selected, and fifty is not too many', function (string $verb, string $name, bool $trashed, string $after, string $word): void {
        $entries = [];

        for ($i = 1; $i <= 51; $i++) {
            $entries[] = bulkRmStored("File {$i}", 'private', $trashed);
        }

        $ids = array_map(static fn (Entry $entry): int => (int) $entry->id, $entries);
        $before = $trashed ? 'trashed' : 'live';
        $action = bulkRmAction($name);

        MediaBulkRemoval::selected($action, Entry::withTrashed()->whereKey($ids), $verb);

        expect(collect($entries)->map(fn (Entry $entry): string => bulkRmWhere($entry))->unique()->values()->all())->toBe([$before])
            ->and(bulkRmNotices())->toHaveCount(1)
            ->and(bulkRmNotices()[0]['status'])->toBe('danger')
            ->and(bulkRmNotices()[0]['duration'])->toBe('persistent')
            ->and(bulkRmNotices()[0]['title'])->toBe('Too many entries are selected')
            ->and(bulkRmNotices()[0]['body'])->toBe(e(__("kitsune::media.removal.{$verb}.too_many", ['max' => 50])))
            ->and($action->getStatus()->name)->toBe('Failure')
            ->and($action->shouldDeselectRecordsAfterCompletion())->toBeFalse();

        session()->forget('filament.notifications');
        MediaBulkRemoval::selected($action, Entry::withTrashed()->whereKey(array_slice($ids, 0, 50)), $verb);

        expect(collect(array_slice($entries, 0, 50))->map(fn (Entry $entry): string => bulkRmWhere($entry))->unique()->values()->all())->toBe([$after])
            ->and(bulkRmNotices()[0]['title'])->toBe("50 entries were {$word}");
    })->with('verbs');

    /* B2. Never loaded whole first: the bound is in the query. */
    it('loads no more than fifty-one to refuse fifty-five', function (): void {
        $ids = [];

        for ($i = 1; $i <= 55; $i++) {
            $ids[] = (int) bulkRmStored("File {$i}", 'private')->id;
        }

        $loaded = 0;
        Entry::retrieved(static function () use (&$loaded): void {
            $loaded++;
        });

        MediaBulkRemoval::selected(bulkRmAction('delete'), Entry::query()->whereKey($ids), MediaBulkRemoval::DELETE);

        expect($loaded)->toBe(51)
            ->and(bulkRmNotices()[0]['title'])->toBe('Too many entries are selected');
    });

    /* B3. A select-all written by hand, through the page's own call: refused whole, loading no more than fifty-one. */
    it('refuses a select-all of more than fifty through the page, loading no more than fifty-one', function (): void {
        for ($i = 1; $i <= 55; $i++) {
            bulkRmStored("File {$i}", 'private');
        }

        $page = bulkRmPage();
        $page->isTrackingDeselectedTableRecords = true;
        $page->deselectedTableRecords = [];
        $action = collect($page->getTable()->getToolbarActions()[0]->getActions())->first(static fn (Action $action): bool => $action->getName() === 'delete');
        $loaded = 0;
        Entry::retrieved(static function () use (&$loaded): void {
            $loaded++;
        });

        $action->call();

        expect(DB::table('entries')->whereNotNull('deleted_at')->count())->toBe(0)
            ->and($loaded)->toBeLessThanOrEqual(51)
            ->and(bulkRmNotices())->toHaveCount(1)
            ->and(bulkRmNotices()[0]['title'])->toBe('Too many entries are selected')
            ->and($action->getStatus()->name)->toBe('Failure');
    });
});

describe('deleting', function (): void {
    /* D1. Each on its own, one notice, the selection cleared. */
    it('deletes each entry on its own, in one notice, and clears the selection', function (): void {
        $public = bulkRmStored('Public');
        $files = [$public, bulkRmStored('A', 'private'), bulkRmStored('B', 'private')];
        $audits = DB::table('audit_log')->count();
        $action = bulkRmAction('delete');

        MediaBulkRemoval::each($action, bulkRmListed(...$files), MediaBulkRemoval::DELETE);

        expect(array_map('bulkRmWhere', $files))->toBe(['trashed', 'trashed', 'trashed'])
            ->and(bulkRmServed($public))->toBeFalse()
            ->and(DB::table('audit_log')->count())->toBe($audits + 3)
            ->and(bulkRmNotices())->toHaveCount(1)
            ->and(bulkRmNotices()[0]['status'])->toBe('success')
            ->and(bulkRmNotices()[0]['title'])->toBe('3 entries were deleted')
            ->and(bulkRmNotices()[0]['body'] ?? null)->toBeNull()
            ->and($action->getStatus()->name)->toBe('Success')
            ->and($action->shouldDeselectRecordsAfterCompletion())->toBeTrue();
    });

    /* D2. A file that could not leave the web: named in decision 5's words, escaped, the rest counted. */
    it('names an entry whose file could not leave the web, escaped, and counts the rest', function (): void {
        $refused = bulkRmStored('<i>Scorecard</i>');
        $private = bulkRmStored('Private', 'private');
        $this->disks['public']->failDeletes = true;
        $action = bulkRmAction('delete');

        MediaBulkRemoval::each($action, bulkRmListed($refused, $private), MediaBulkRemoval::DELETE);

        $lines = explode('<br>', (string) bulkRmNotices()[0]['body']);

        expect([bulkRmWhere($refused), bulkRmWhere($private)])->toBe(['live', 'trashed'])
            ->and(bulkRmServed($refused))->toBeTrue()
            ->and(bulkRmNotices())->toHaveCount(1)
            ->and(bulkRmNotices()[0]['status'])->toBe('danger')
            ->and(bulkRmNotices()[0]['duration'])->toBe('persistent')
            ->and(bulkRmNotices()[0]['title'])->toBe('One entry was not deleted')
            ->and($lines)->toHaveCount(2)
            ->and($lines[0])->toStartWith("&quot;&lt;i&gt;Scorecard&lt;/i&gt;&quot; was not deleted: Refusing to trash entry {$refused->id}")
            ->and($lines[1])->toBe('One entry was deleted.')
            ->and($action->getStatus()->name)->toBe('Failure')
            ->and($action->shouldDeselectRecordsAfterCompletion())->toBeFalse();
    });

    /* D3. Already in the trash, under Everything: left as it was, no failure, even past the budget, and never the first. */
    it('leaves one already in the trash as it was, as no failure, even past the budget', function (): void {
        $old = bulkRmStored('Old', 'private', trashed: true);
        $deletedAt = DB::table('entries')->where('id', $old->id)->value('deleted_at');
        $live = bulkRmStored('Live', 'private');
        $audits = DB::table('audit_log')->count();
        $action = bulkRmAction('delete');

        MediaBulkRemoval::each($action, bulkRmListed($old, $live), MediaBulkRemoval::DELETE, now()->subSecond());

        expect(DB::table('entries')->where('id', $old->id)->value('deleted_at'))->toBe($deletedAt)
            ->and(bulkRmWhere($live))->toBe('trashed')
            ->and(DB::table('audit_log')->count())->toBe($audits + 1)
            ->and(bulkRmNotices()[0]['title'])->toBe('One entry was deleted')
            ->and(bulkRmNotices()[0]['body'])->toBe('One was already in the trash, and was left as it was.')
            ->and($action->getStatus()->name)->toBe('Success');
    });

    /* D4. A failure not Kitsune's: reported once, named in words that claim nothing, its message unshown; the rest go on. */
    it('reports a failure not Kitsune\'s once, names it without its message, and goes on', function (): void {
        Exceptions::fake();
        $files = [bulkRmStored('A', 'private'), bulkRmStored('B', 'private'), bulkRmStored('C', 'private')];
        $n = 0;
        AuditorStandIn::install()->beforeRecording(function () use (&$n): void {
            if (++$n > 1) {
                throw new RuntimeException('secret <b>sql</b>');
            }
        });
        $action = bulkRmAction('delete');

        MediaBulkRemoval::each($action, bulkRmListed(...$files), MediaBulkRemoval::DELETE);

        expect(array_map('bulkRmWhere', $files))->toBe(['trashed', 'live', 'live'])
            ->and(bulkRmNotices())->toHaveCount(1)
            ->and(bulkRmNotices()[0]['title'])->toBe('2 entries were not deleted')
            ->and(bulkRmNotices()[0]['body'])->toBe('&quot;B&quot;, &quot;C&quot; may not have been deleted: something went wrong. The list shows where each is now; tell whoever runs this site if it happens again.<br>One entry was deleted.')
            ->and($action->getStatus()->name)->toBe('Failure');

        Exceptions::assertReportedCount(1);
    });

    /* D5. A model event that says no: named, and nothing reported. */
    it('names an entry a model event would not delete, and reports nothing', function (): void {
        Exceptions::fake();
        $a = bulkRmStored('A', 'private');
        $b = bulkRmStored('B', 'private');
        Entry::deleting(static fn (Entry $entry): ?bool => $entry->id === $b->id ? false : null);

        MediaBulkRemoval::each(bulkRmAction('delete'), bulkRmListed($a, $b), MediaBulkRemoval::DELETE);

        expect([bulkRmWhere($a), bulkRmWhere($b)])->toBe(['trashed', 'live'])
            ->and(bulkRmNotices()[0]['body'])->toStartWith('&quot;B&quot; may not have been deleted');

        Exceptions::assertNothingReported();
    });

    /* D6. A failure whose entry cannot be read again: named with the failures, never counted as deleted. */
    it('claims nothing of an entry it cannot read again after a failure', function (): void {
        Exceptions::fake();
        $a = bulkRmStored('A', 'private');
        $records = bulkRmListed($a);
        Entry::deleting(static fn () => throw new RuntimeException('secret'));
        DB::listen(static function ($query): void {
            if (preg_match('/^select [`"]?deleted_at[`"]? from [`"]?entries/', $query->sql) === 1) {
                throw new RuntimeException('unreadable');
            }
        });
        $action = bulkRmAction('delete');

        MediaBulkRemoval::each($action, $records, MediaBulkRemoval::DELETE);

        expect(bulkRmNotices()[0]['title'])->toBe('One entry was not deleted')
            ->and(bulkRmNotices()[0]['body'])->toStartWith('&quot;A&quot; may not have been deleted: something went wrong.')
            ->and($action->getStatus()->name)->toBe('Failure');
    });

    /* D7. An entry with no title is named by its number. */
    it('names an entry with no title by its number', function (): void {
        $a = bulkRmStored('A', 'private');
        DB::table('entries')->where('id', $a->id)->update(['title' => '']);
        Entry::deleting(static fn (): bool => false);

        MediaBulkRemoval::each(bulkRmAction('delete'), bulkRmListed($a), MediaBulkRemoval::DELETE);

        expect(bulkRmNotices()[0]['body'])->toStartWith("&quot;#{$a->id}&quot; may not have been deleted");
    });

    /* D8. The title counts those deleted, never the selection: the ones already in the trash are counted apart. */
    it('counts in its title only the entries it deleted', function (): void {
        $files = [bulkRmStored('Old', 'private', trashed: true), bulkRmStored('A', 'private'), bulkRmStored('B', 'private')];

        MediaBulkRemoval::each(bulkRmAction('delete'), bulkRmListed(...$files), MediaBulkRemoval::DELETE);

        expect(bulkRmNotices()[0]['title'])->toBe('2 entries were deleted')
            ->and(bulkRmNotices()[0]['body'])->toBe('One was already in the trash, and was left as it was.');
    });
});

describe('restoring', function (): void {
    /* R1. A trashed public file back on the web; a live one under Everything left unsaved. */
    it('restores a trashed entry and puts its public file back on the web, and leaves a live one unsaved', function (): void {
        $trashed = bulkRmStored('Trashed', 'public', trashed: true);
        $live = bulkRmStored('Live', 'private');
        $updated = DB::table('entries')->where('id', $live->id)->value('updated_at');
        $audits = DB::table('audit_log')->count();
        $action = bulkRmAction('restore');

        expect(bulkRmServed($trashed))->toBeFalse();

        MediaBulkRemoval::each($action, bulkRmListed($trashed, $live), MediaBulkRemoval::RESTORE);

        expect(bulkRmWhere($trashed))->toBe('live')
            ->and(bulkRmServed($trashed))->toBeTrue()
            ->and(DB::table('entries')->where('id', $live->id)->value('updated_at'))->toBe($updated)
            ->and(DB::table('audit_log')->count())->toBe($audits + 1)
            ->and(bulkRmNotices()[0]['status'])->toBe('success')
            ->and(bulkRmNotices()[0]['title'])->toBe('One entry was restored')
            ->and(bulkRmNotices()[0]['body'])->toBe('One was not in the trash, and was left as it was.')
            ->and($action->getStatus()->name)->toBe('Success')
            ->and($action->shouldDeselectRecordsAfterCompletion())->toBeTrue();
    });

    /* R2. Restored, and its publication failed after the commit: said, with the command — no failure. */
    it('names a restored file whose publication failed, with the command that publishes it', function (): void {
        $trashed = bulkRmStored('Trashed', 'public', trashed: true);
        $this->disks['public']->failWrites = true;
        $action = bulkRmAction('restore');

        MediaBulkRemoval::each($action, bulkRmListed($trashed), MediaBulkRemoval::RESTORE);

        expect(bulkRmWhere($trashed))->toBe('live')
            ->and(bulkRmNotices()[0]['status'])->toBe('warning')
            ->and(bulkRmNotices()[0]['duration'])->toBe('persistent')
            ->and(bulkRmNotices()[0]['title'])->toBe('One entry was restored, and its file is not yet published')
            ->and(bulkRmNotices()[0]['body'])->toContain("kitsune:media-reconcile --entry={$trashed->id} --force")
            ->and($action->getStatus()->name)->toBe('Success')
            ->and($action->shouldDeselectRecordsAfterCompletion())->toBeTrue();
    });

    /* R3. A restore that fails: reported once, named; the rest restored. */
    it('names a restore that failed, and restores the rest', function (): void {
        Exceptions::fake();
        $a = bulkRmStored('A', 'private', trashed: true);
        $b = bulkRmStored('B', 'private', trashed: true);
        AuditorStandIn::install()->throwOnce(new RuntimeException('secret'));
        $action = bulkRmAction('restore');

        MediaBulkRemoval::each($action, bulkRmListed($a, $b), MediaBulkRemoval::RESTORE);

        expect([bulkRmWhere($a), bulkRmWhere($b)])->toBe(['trashed', 'live'])
            ->and(bulkRmNotices()[0]['title'])->toBe('One entry was not restored')
            ->and(bulkRmNotices()[0]['body'])->toBe('&quot;A&quot; may not have been restored: something went wrong. The list shows where it is now; tell whoever runs this site if it happens again.<br>One entry was restored.')
            ->and($action->getStatus()->name)->toBe('Failure');

        Exceptions::assertReportedCount(1);
    });

    /* R4. A restore withdraws nothing, so custody's refusal from one is a failure like any other: reported, named, its reason unshown. */
    it('names a restore that throws custody\'s refusal as a failure, not a refusal', function (): void {
        Exceptions::fake();
        $a = bulkRmStored('A', 'private', trashed: true);
        Entry::restoring(static fn (Entry $entry) => throw new MediaWithdrawalRefused((int) $entry->id, MediaWithdrawalRefused::DELETE_FAILED, 'public', 'trash'));

        MediaBulkRemoval::each(bulkRmAction('restore'), bulkRmListed($a), MediaBulkRemoval::RESTORE);

        expect(bulkRmWhere($a))->toBe('trashed')
            ->and(bulkRmNotices()[0]['title'])->toBe('One entry was not restored')
            ->and(bulkRmNotices()[0]['body'])->toBe('&quot;A&quot; may not have been restored: something went wrong. The list shows where it is now; tell whoever runs this site if it happens again.');

        Exceptions::assertReportedCount(1);
    });

    /* R5. Restored files not yet published beside one that needed no publication: the title says what is still owed. */
    it('titles a restore by the files not yet published, and counts the rest restored', function (): void {
        $files = [bulkRmStored('P1', 'public', trashed: true), bulkRmStored('P2', 'public', trashed: true), bulkRmStored('Q', 'private', trashed: true)];
        $this->disks['public']->failWrites = true;

        MediaBulkRemoval::each(bulkRmAction('restore'), bulkRmListed(...$files), MediaBulkRemoval::RESTORE);

        $lines = explode('<br>', (string) bulkRmNotices()[0]['body']);

        expect(array_map('bulkRmWhere', $files))->toBe(['live', 'live', 'live'])
            ->and(bulkRmNotices()[0]['title'])->toBe('2 entries were restored, and their files are not yet published')
            ->and($lines)->toHaveCount(2)
            ->and($lines[0])->toContain("--entry={$files[0]->id} --entry={$files[1]->id} --force")
            ->and($lines[1])->toBe('One entry was restored.');
    });

    /* R6. A restored file whose row cannot be read afterwards is never said to be on the web. */
    it('never says a restored file is on the web when it cannot read it', function (): void {
        $a = bulkRmStored('A', 'public', trashed: true);
        $armed = false;
        Entry::restored(static function () use (&$armed): void {
            $armed = true;
        });
        DB::listen(static function ($query) use (&$armed): void {
            if ($armed && preg_match('/^select \* from [`"]?media_files[`"]? where [`"]?entry_id[`"]? = \? limit 1$/', $query->sql) === 1) {
                throw new RuntimeException('unreadable');
            }
        });

        MediaBulkRemoval::each(bulkRmAction('restore'), bulkRmListed($a), MediaBulkRemoval::RESTORE);

        expect(bulkRmWhere($a))->toBe('live')
            ->and(bulkRmNotices()[0]['title'])->toBe('One entry was restored, and its file is not yet published');
    });
});

describe('deleting forever', function (): void {
    /* E1. Only what is in the trash: a live one named and left, whatever the budget; the trashed one goes. */
    it('names a live entry and leaves it, and deletes the trashed one forever', function (): void {
        $live = bulkRmStored('Live', 'private');
        $trashed = bulkRmStored('Trashed', 'private', trashed: true);
        $action = bulkRmAction('forceDelete');

        MediaBulkRemoval::each($action, bulkRmListed($live, $trashed), MediaBulkRemoval::ERASE);

        expect([bulkRmWhere($live), bulkRmWhere($trashed)])->toBe(['live', 'gone'])
            ->and(bulkRmNotices()[0]['status'])->toBe('danger')
            ->and(bulkRmNotices()[0]['title'])->toBe('One entry was not deleted forever')
            ->and(bulkRmNotices()[0]['body'])->toBe('&quot;Live&quot; was not deleted forever: it is not in the trash. Move it to the trash first.<br>One entry was deleted forever.')
            ->and($action->getStatus()->name)->toBe('Failure')
            ->and($action->shouldDeselectRecordsAfterCompletion())->toBeFalse();
    });

    /* E2. An erasure custody refused: named, escaped, and the entry kept in the trash. */
    it('names an erasure custody refused, escaped, and keeps it in the trash', function (): void {
        $entry = bulkRmStored('<i>Scorecard</i>', 'public', trashed: true);
        config(['kitsune.media.disks.private' => 'public']);

        MediaBulkRemoval::each(bulkRmAction('forceDelete'), bulkRmListed($entry), MediaBulkRemoval::ERASE);

        expect(bulkRmWhere($entry))->toBe('trashed')
            ->and(bulkRmNotices()[0]['title'])->toBe('One entry was not deleted forever')
            ->and(bulkRmNotices()[0]['body'])->toStartWith("&quot;&lt;i&gt;Scorecard&lt;/i&gt;&quot; was not deleted forever: Refusing to erase entry {$entry->id}");
    });

    /* E3. An erasure that fails for a reason not Kitsune's: reported once and named. */
    it('names an erasure that failed for a reason not Kitsune\'s', function (): void {
        Exceptions::fake();
        $entry = bulkRmStored('A', 'private', trashed: true);
        Entry::forceDeleting(static function (): void {
            throw new RuntimeException('secret');
        });

        MediaBulkRemoval::each(bulkRmAction('forceDelete'), bulkRmListed($entry), MediaBulkRemoval::ERASE);

        expect(bulkRmWhere($entry))->toBe('trashed')
            ->and(bulkRmNotices()[0]['body'])->toStartWith('&quot;A&quot; may not have been deleted forever: something went wrong. The trash shows whether it is still there');

        Exceptions::assertReportedCount(1);
    });

    /* E4. Restored since the list loaded it: refused under the erasure's own lock, named as live, nothing reported. */
    it('names an entry restored since the list loaded it as live, and leaves it', function (): void {
        Exceptions::fake();
        $a = bulkRmStored('A', 'private', trashed: true);
        $b = bulkRmStored('B', 'private', trashed: true);
        $records = bulkRmListed($a, $b);
        Entry::withTrashed()->findOrFail($a->id)->restore();
        $action = bulkRmAction('forceDelete');

        MediaBulkRemoval::each($action, $records, MediaBulkRemoval::ERASE);

        expect([bulkRmWhere($a), bulkRmWhere($b)])->toBe(['live', 'gone'])
            ->and(bulkRmNotices()[0]['title'])->toBe('One entry was not deleted forever')
            ->and(bulkRmNotices()[0]['body'])->toBe('&quot;A&quot; was not deleted forever: it is not in the trash. Move it to the trash first.<br>One entry was deleted forever.')
            ->and($action->getStatus()->name)->toBe('Failure');

        Exceptions::assertNothingReported();
    });

    /* E5. An erasure that failed and cannot be read again: named with the failures, never said to be live. */
    it('claims nothing of an erasure that failed and cannot be read again', function (): void {
        Exceptions::fake();
        $a = bulkRmStored('A', 'private', trashed: true);
        $records = bulkRmListed($a);
        AuditorStandIn::install()->throwOnce(new RuntimeException('secret'));
        DB::listen(static function ($query): void {
            if (preg_match('/^select [`"]?deleted_at[`"]? from [`"]?entries/', $query->sql) === 1) {
                throw new RuntimeException('unreadable');
            }
        });

        MediaBulkRemoval::each(bulkRmAction('forceDelete'), $records, MediaBulkRemoval::ERASE);

        expect(bulkRmNotices()[0]['title'])->toBe('One entry was not deleted forever')
            ->and(bulkRmNotices()[0]['body'])->toStartWith('&quot;A&quot; may not have been deleted forever: something went wrong.');

        Exceptions::assertReportedCount(1);
    });
});

describe('the budget', function (): void {
    /* T1. Past the budget none is started; those not tried are counted and stay selected. */
    it('starts none once the budget has passed, says how many were not tried, and keeps the selection', function (string $verb, string $name, bool $trashed, string $after, string $word): void {
        $this->freezeTime();
        $files = [bulkRmStored('A', 'private', $trashed), bulkRmStored('B', 'private', $trashed), bulkRmStored('C', 'private', $trashed)];
        $before = $trashed ? 'trashed' : 'live';
        AuditorStandIn::install()->beforeRecording(fn () => $this->travel(11)->seconds());
        $action = bulkRmAction($name);

        MediaBulkRemoval::each($action, bulkRmListed(...$files), $verb, now()->addSeconds(10));

        expect(array_map('bulkRmWhere', $files))->toBe([$after, $before, $before])
            ->and(bulkRmNotices())->toHaveCount(1)
            ->and(bulkRmNotices()[0]['status'])->toBe('warning')
            ->and(bulkRmNotices()[0]['duration'])->toBe('persistent')
            ->and(bulkRmNotices()[0]['title'])->toBe("2 entries were not {$word}")
            ->and(bulkRmNotices()[0]['body'])->toBe("2 entries were not tried: one request may take only so long. They are still selected; run it again on the same selection to go on.<br>One entry was {$word}.")
            ->and($action->getStatus()->name)->toBe('Failure')
            ->and($action->shouldDeselectRecordsAfterCompletion())->toBeFalse();
    })->with('verbs');

    /* T2. The first that needs work is always started, however late — one that needs none is never the first. */
    it('always starts the first that needs work, however late', function (string $verb, string $name, bool $trashed, string $after, string $word): void {
        $other = bulkRmStored('Other', 'private', ! $trashed);
        $a = bulkRmStored('A', 'private', $trashed);
        $b = bulkRmStored('B', 'private', $trashed);
        $before = $trashed ? 'trashed' : 'live';

        MediaBulkRemoval::each(bulkRmAction($name), bulkRmListed($other, $a, $b), $verb, now()->subSecond());

        expect([bulkRmWhere($a), bulkRmWhere($b)])->toBe([$after, $before])
            ->and(bulkRmNotices()[0]['body'])->toContain('One entry was not tried');
    })->with('verbs');

    /* T3. A refusal beside entries not tried: the refusal decides the colour. */
    it('is a danger notice when an entry was refused and others were not tried', function (): void {
        $refused = bulkRmStored('Refused');
        $this->disks['public']->failDeletes = true;

        MediaBulkRemoval::each(bulkRmAction('delete'), bulkRmListed($refused, bulkRmStored('A', 'private'), bulkRmStored('B', 'private')), MediaBulkRemoval::DELETE, now()->subSecond());

        expect(bulkRmNotices()[0]['status'])->toBe('danger')
            ->and(bulkRmNotices()[0]['title'])->toBe('3 entries were not deleted');
    });

    /* T4. The handler passes the request's budget, in the list's order. */
    it('passes the request\'s budget from the handler, in the list\'s order', function (string $verb, string $name, bool $trashed, string $after, string $word): void {
        $this->freezeTime();
        $a = bulkRmStored('A', 'private', $trashed);
        $b = bulkRmStored('B', 'private', $trashed);
        $before = $trashed ? 'trashed' : 'live';
        AuditorStandIn::install()->beforeRecording(fn () => $this->travel(11)->seconds());
        $limit = ini_get('max_execution_time');

        try {
            ini_set('max_execution_time', '20');
            MediaBulkRemoval::selected(bulkRmAction($name), Entry::withTrashed()->whereKey([$a->id, $b->id])->orderByDesc('id'), $verb);
        } finally {
            ini_set('max_execution_time', (string) $limit);
        }

        expect([bulkRmWhere($b), bulkRmWhere($a)])->toBe([$after, $before]);
    })->with('verbs');

    /* T5. The time the selection takes to load counts against the budget. */
    it('counts the time the selection takes to load against the budget', function (): void {
        $this->freezeTime();
        $a = bulkRmStored('A', 'private');
        $b = bulkRmStored('B', 'private');
        $travelled = false;
        Entry::retrieved(function () use (&$travelled): void {
            if (! $travelled) {
                $travelled = true;
                $this->travel(16)->seconds();
            }
        });

        MediaBulkRemoval::selected(bulkRmAction('delete'), Entry::query()->whereKey([$a->id, $b->id])->orderBy('id'), MediaBulkRemoval::DELETE);

        expect([bulkRmWhere($a), bulkRmWhere($b)])->toBe(['trashed', 'live']);
    });
});

describe('what left the list', function (): void {
    /*
     * G1. Selected entries the list no longer holds, read where they are now through the list's own query: those the action
     * would have made so count as already so; another site's never reads as in the trash; the rest are said as gone.
     */
    it('says where selected entries the list no longer holds are now, and never reads another site\'s', function (): void {
        $e = bulkRmStored('E', 'private');
        $a = bulkRmStored('A', 'private', trashed: true);
        $b = bulkRmStored('B', 'private');
        $other = Site::create(['handle' => 'other', 'slug' => 'removals-other', 'name' => 'Other', 'locale' => 'en']);
        app(Context::class)->setSite($other);
        PanelTenancy::enter($other);
        $source = LocatedJpeg::file((string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='), 'kitsune-bulkrm-');
        $user = auth()->user();
        auth()->forgetUser();
        $x = MediaLibrary::store($source, 'logo.png', $this->type, 'private', title: 'X', siteOnly: true);
        $x->delete();
        unlink($source);
        $this->actingAs($user);
        app(Context::class)->setSite($this->site);
        PanelTenancy::enter($this->site);

        MediaBulkRemoval::each(bulkRmAction('delete'), bulkRmListed($e), MediaBulkRemoval::DELETE, keys: [...bulkRmKeys($e, $a, $b, $x), '999999'], list: bulkRmList());

        expect(bulkRmWhere($e))->toBe('trashed')
            ->and(bulkRmNotices()[0]['status'])->toBe('warning')
            ->and(bulkRmNotices()[0]['duration'])->toBe('persistent')
            ->and(bulkRmNotices()[0]['title'])->toBe('One entry was deleted')
            ->and(bulkRmNotices()[0]['body'])->toBe('One was already in the trash, and was left as it was.<br>3 of the selected entries are no longer on this list, and were left as they were.');
    });

    /* G2. A second run on what a stopped first one left: its work counted as already so, the rest done, cleared. */
    it('goes on where it stopped, counting the first run\'s work as already so', function (string $verb, string $name, bool $trashed, string $after, string $word): void {
        $files = [bulkRmStored('A', 'private', $trashed), bulkRmStored('B', 'private', $trashed), bulkRmStored('C', 'private', $trashed)];
        $keys = bulkRmKeys(...$files);
        $action = bulkRmAction($name);

        MediaBulkRemoval::each($action, bulkRmListed(...$files), $verb, now()->subSecond(), $keys, bulkRmList());

        // What the list now holds of the selection: the first has left it, by the first run's own work.
        session()->forget('filament.notifications');
        MediaBulkRemoval::each($action, bulkRmListed($files[1], $files[2]), $verb, null, $keys, bulkRmList());

        $already = match ($verb) {
            MediaBulkRemoval::DELETE => 'One was already in the trash, and was left as it was.',
            MediaBulkRemoval::RESTORE => 'One was not in the trash, and was left as it was.',
            default => 'One was already deleted forever.',
        };

        expect(array_map('bulkRmWhere', $files))->toBe([$after, $after, $after])
            ->and(bulkRmNotices()[0]['status'])->toBe('success')
            ->and(bulkRmNotices()[0]['title'])->toBe("2 entries were {$word}")
            ->and(bulkRmNotices()[0]['body'])->toBe($already)
            ->and($action->getStatus()->name)->toBe('Success');
    })->with('verbs');

    /* G3. Nothing of the selection left on the list. */
    it('says so when none of the selection is on the list any more', function (): void {
        $action = bulkRmAction('delete');

        MediaBulkRemoval::each($action, [], MediaBulkRemoval::DELETE, keys: ['999998'], list: bulkRmList());

        expect(bulkRmNotices()[0]['status'])->toBe('info')
            ->and(bulkRmNotices()[0]['title'])->toBe('None of the selected entries is on this list any more. Nothing was changed.')
            ->and(bulkRmNotices()[0]['body'] ?? null)->toBeNull()
            ->and($action->getStatus()->name)->toBe('Success');
    });

    /* G5. Restoring, a selected entry still in the trash that left the list — searched out of it — is gone, not already so. */
    it('says a selected entry still in the trash is gone from the list, when restoring', function (): void {
        $a = bulkRmStored('A', 'private', trashed: true);
        $b = bulkRmStored('B', 'private', trashed: true);

        MediaBulkRemoval::each(bulkRmAction('restore'), bulkRmListed($a), MediaBulkRemoval::RESTORE, keys: bulkRmKeys($a, $b), list: bulkRmList());

        expect(bulkRmWhere($b))->toBe('trashed')
            ->and(bulkRmNotices()[0]['status'])->toBe('warning')
            ->and(bulkRmNotices()[0]['title'])->toBe('One entry was restored')
            ->and(bulkRmNotices()[0]['body'])->toBe('One of the selected entries is no longer on this list, and was left as it was.');
    });

    /* G6. Every one already as asked, those that left the list included: said as already so, counted whole. */
    it('says the whole selection was already so, counting those that left the list', function (): void {
        $gone = bulkRmStored('Gone', 'private', trashed: true);

        MediaBulkRemoval::each(bulkRmAction('delete'), [], MediaBulkRemoval::DELETE, keys: bulkRmKeys($gone), list: bulkRmList());

        expect(bulkRmNotices()[0]['status'])->toBe('info')
            ->and(bulkRmNotices()[0]['title'])->toBe('The entry was already in the trash. Nothing was changed.');

        session()->forget('filament.notifications');
        $old = bulkRmStored('Old', 'private', trashed: true);
        MediaBulkRemoval::each(bulkRmAction('delete'), bulkRmListed($old), MediaBulkRemoval::DELETE, keys: bulkRmKeys($old, $gone), list: bulkRmList());

        expect(bulkRmNotices()[0]['title'])->toBe('All 2 entries were already in the trash. Nothing was changed.')
            ->and(bulkRmNotices()[0]['body'] ?? null)->toBeNull();
    });

    /* G4. Where the keys are now cannot be read: every one said as gone, reported once, and nothing escapes. */
    it('claims nothing of the keys it cannot look up', function (): void {
        Exceptions::fake();
        $e = bulkRmStored('E', 'private');

        MediaBulkRemoval::each(bulkRmAction('delete'), bulkRmListed($e), MediaBulkRemoval::DELETE, keys: [...bulkRmKeys($e), '999997'], list: Entry::query()->from('no_such_table'));

        expect(bulkRmNotices()[0]['body'])->toBe('One of the selected entries is no longer on this list, and was left as it was.');

        Exceptions::assertReportedCount(1);

        // Once in all: a failure in the loop and the read after it are reported as one.
        Exceptions::fake();
        session()->forget('filament.notifications');
        $f = bulkRmStored('F', 'private');
        AuditorStandIn::install()->throwOnce(new RuntimeException('secret'));

        MediaBulkRemoval::each(bulkRmAction('delete'), bulkRmListed($f), MediaBulkRemoval::DELETE, keys: [...bulkRmKeys($f), '999997'], list: Entry::query()->from('no_such_table'));

        expect(bulkRmNotices()[0]['title'])->toBe('One entry was not deleted');

        Exceptions::assertReportedCount(1);
    });

    /* G8. Changed by another editor since the list loaded it: counted as already so, and never written again. */
    it('counts an entry another editor changed since the list loaded it as already so, and writes nothing', function (string $verb, string $name, bool $trashed, string $after, string $word): void {
        $a = bulkRmStored('A', 'private', $trashed);
        $records = bulkRmListed($a);
        // Another editor, before this run reaches it.
        match ($verb) {
            MediaBulkRemoval::DELETE => Entry::query()->findOrFail($a->id)->delete(),
            MediaBulkRemoval::RESTORE => Entry::withTrashed()->findOrFail($a->id)->restore(),
            default => Entry::withTrashed()->findOrFail($a->id)->forceDelete(),
        };
        $row = DB::table('entries')->where('id', $a->id)->first();
        $audits = DB::table('audit_log')->count();
        $this->travel(1)->minutes();
        $action = bulkRmAction($name);

        MediaBulkRemoval::each($action, $records, $verb);

        expect(bulkRmWhere($a))->toBe($after)
            ->and(DB::table('entries')->where('id', $a->id)->first())->toEqual($row)
            ->and(DB::table('audit_log')->count())->toBe($audits)
            ->and(bulkRmNotices()[0]['status'])->toBe('info')
            ->and($action->getStatus()->name)->toBe('Success');
    })->with('verbs');

    /* G7. Where they are now is read in one statement, so a change between two counts cannot make one exceed the other. */
    it('reads where the selected keys are now in one statement', function (): void {
        $a = bulkRmStored('A', 'private', trashed: true);
        $erased = false;
        DB::listen(static function ($query) use (&$erased, $a): void {
            if (! $erased && str_contains($query->sql, 'entries') && in_array($a->id, $query->bindings, false)) {
                $erased = true;
                DB::table('entries')->where('id', $a->id)->delete();
            }
        });

        MediaBulkRemoval::each(bulkRmAction('restore'), [], MediaBulkRemoval::RESTORE, keys: bulkRmKeys($a), list: bulkRmList());

        expect($erased)->toBeTrue()
            ->and(bulkRmNotices()[0]['title'])->toBe('None of the selected entries is on this list any more. Nothing was changed.');
    });
});

describe('through the page', function (): void {
    /* P1. What the page selected, through the list's own query and Filament's own call, in one notice — each verb. */
    it('removes what the page selected, through the list\'s own query, in one notice', function (): void {
        $a = bulkRmStored('A', 'private');
        $b = bulkRmStored('B', 'private');
        $c = bulkRmStored('C', 'private', trashed: true);
        $call = static function (string $name, array $keys, ?array $filters = null): Action {
            $page = bulkRmPage($filters);
            $page->selectedTableRecords = $keys;
            $action = collect($page->getTable()->getToolbarActions()[0]->getActions())->first(static fn (Action $action): bool => $action->getName() === $name);
            $action->call();

            return $action;
        };

        $call('delete', bulkRmKeys($a, $b, $c));

        expect([bulkRmWhere($a), bulkRmWhere($b), bulkRmWhere($c)])->toBe(['trashed', 'trashed', 'trashed'])
            ->and(bulkRmNotices())->toHaveCount(1)
            ->and(bulkRmNotices()[0]['title'])->toBe('2 entries were deleted')
            ->and(bulkRmNotices()[0]['body'])->toBe('One was already in the trash, and was left as it was.');

        session()->forget('filament.notifications');
        $call('restore', bulkRmKeys($a, $b), ['trashed' => ['value' => '0']]);

        expect([bulkRmWhere($a), bulkRmWhere($b)])->toBe(['live', 'live'])
            ->and(bulkRmNotices()[0]['title'])->toBe('2 entries were restored');

        session()->forget('filament.notifications');
        $call('forceDelete', bulkRmKeys($c), ['trashed' => ['value' => '0']]);

        expect(bulkRmWhere($c))->toBe('gone')
            ->and(bulkRmNotices()[0]['title'])->toBe('One entry was deleted forever');
    });

    /* P2. A host's `authorizeIndividualRecords()` asked of each entry, as Filament asks it of the records it loads. */
    it('asks a host\'s authorizeIndividualRecords() of each entry, naming those it refuses', function (): void {
        $a = bulkRmStored('A', 'private');
        $b = bulkRmStored('B', 'private');
        $c = bulkRmStored('C', 'private');
        DeleteBulkAction::configureUsing(static fn (DeleteBulkAction $action) => $action->authorizeIndividualRecords(
            static fn (Entry $record): Response|bool => match ($record->title) {
                'B' => Response::deny('<b>not yours</b>'),
                'C' => false,
                default => true,
            },
        ));

        try {
            $page = bulkRmPage();
            $page->selectedTableRecords = bulkRmKeys($a, $b, $c);
            collect($page->getTable()->getToolbarActions()[0]->getActions())->first(static fn (Action $action): bool => $action->getName() === 'delete')->call();
        } finally {
            DeleteBulkAction::configureUsing(static fn () => null);
        }

        // In the list's order, newest first.
        expect([bulkRmWhere($a), bulkRmWhere($b), bulkRmWhere($c)])->toBe(['trashed', 'live', 'live'])
            ->and(bulkRmNotices())->toHaveCount(1)
            ->and(bulkRmNotices()[0]['title'])->toBe('2 entries were not deleted')
            ->and(bulkRmNotices()[0]['body'])->toBe('&quot;C&quot; was not deleted: you may not delete it.<br>&quot;B&quot; was not deleted: &lt;b&gt;not yours&lt;/b&gt;<br>One entry was deleted.');
    });
});

describe('the words', function (): void {
    /* W1. Every title and line escaped, whatever its translation says (decision 7). */
    it('escapes every title and line, whatever its translation says', function (): void {
        app('translator')->addLines([
            'media.removal.delete.done' => '<b>:count</b> deleted',
            'media.removal.gone_line' => '<i>:count</i> gone',
            'media.removal.too_many_title' => '<u>Too many</u>',
        ], 'en', 'kitsune');
        $e = bulkRmStored('E', 'private');

        MediaBulkRemoval::each(bulkRmAction('delete'), bulkRmListed($e), MediaBulkRemoval::DELETE, keys: [...bulkRmKeys($e), '999996'], list: bulkRmList());

        expect(bulkRmNotices()[0]['title'])->toBe('&lt;b&gt;1&lt;/b&gt; deleted')
            ->and(bulkRmNotices()[0]['body'])->toBe('&lt;i&gt;1&lt;/i&gt; gone');

        session()->forget('filament.notifications');
        BulkSelection::refuse(bulkRmAction('delete'), __('kitsune::media.removal.too_many_title'), 'x');

        expect(bulkRmNotices()[0]['title'])->toBe('&lt;u&gt;Too many&lt;/u&gt;');
    });

    /* W3. Each of the body's counted lines escaped too, whatever its translation says (decision 7). */
    it('escapes each counted line of the body', function (): void {
        // Loaded first: lines added to a group not yet loaded would stand in for the whole of it.
        __('kitsune::media.selection.quoted');
        app('translator')->addLines([
            'media.selection.awaiting_line' => '<a>:titles</a>',
            'media.removal.restore.done_count' => '<b>:count</b> restored.',
            'media.removal.restore.already_count' => '<i>:count</i> live.',
            'media.removal.not_tried' => '<s>:count</s> not tried',
        ], 'en', 'kitsune');
        $files = [bulkRmStored('P', 'public', trashed: true), bulkRmStored('Q', 'private', trashed: true), bulkRmStored('L', 'private')];
        $this->disks['public']->failWrites = true;

        MediaBulkRemoval::each(bulkRmAction('restore'), bulkRmListed(...$files), MediaBulkRemoval::RESTORE);

        expect(bulkRmNotices()[0]['body'])->toBe('&lt;a&gt;&quot;P&quot;&lt;/a&gt;<br>&lt;b&gt;1&lt;/b&gt; restored.<br>&lt;i&gt;1&lt;/i&gt; live.');

        session()->forget('filament.notifications');
        MediaBulkRemoval::each(bulkRmAction('delete'), bulkRmListed(bulkRmStored('A', 'private'), bulkRmStored('B', 'private')), MediaBulkRemoval::DELETE, now()->subSecond());

        expect(bulkRmNotices()[0]['body'])->toStartWith('&lt;s&gt;1&lt;/s&gt; not tried<br>');
    });

    /* W2. An entry already so, beside one gone, is not the colour of a selection done whole. */
    it('is a warning when the whole of what was there was already so, and some had left', function (): void {
        $old = bulkRmStored('Old', 'private', trashed: true);

        MediaBulkRemoval::each(bulkRmAction('delete'), bulkRmListed($old), MediaBulkRemoval::DELETE, keys: [...bulkRmKeys($old), '999995'], list: bulkRmList());

        expect(bulkRmNotices()[0]['status'])->toBe('warning')
            ->and(bulkRmNotices()[0]['title'])->toBe('The entry was already in the trash. Nothing was changed.')
            ->and(bulkRmNotices()[0]['body'])->toBe('One of the selected entries is no longer on this list, and was left as it was.');
    });
});
