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
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Filament\MediaVisibilityActions;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;
use Kitsune\Core\Filament\Resources\Entries\Pages\ListEntries;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Media\MediaRefused;
use Kitsune\Core\Media\MediaVisibility;
use Kitsune\Core\Media\MediaVisibilityRefused;
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
 * *Make selected public* and *Make selected private* on a media list — Adam, ADR-042 decision 34.
 *
 * ⚠️ FROM THE ROWS AND THE ONE NOTIFICATION. The switch itself is `MediaVisibilityTest`'s; here, who is offered the two,
 * the acknowledgement they ask for, and what a selection does — each file's row read back afterwards, the audit log
 * counted, and the session's notifications read as the page would show them, so a notice that claims what did not happen
 * cannot pass. Commits that fail at a real level 0 are `MediaVisibilityBulkLevelZeroTest`'s; the page itself is
 * `media-visibility.spec.js`'s.
 */

beforeEach(function (): void {
    config(['auth.providers.users.model' => TestUser::class]);
    $this->roots = [];

    foreach (['public', MediaDisks::PRIVATE] as $name) {
        $root = sys_get_temp_dir().'/kitsune-visbulk-'.$name.'-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        $this->roots[] = $root;
        $this->disks[$name] = RefusingDisk::install($name, $root);
    }

    $this->org = Org::create(['slug' => 'selections', 'name' => 'Selections']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'selections-main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);
    $this->type = EntryType::create(['org_id' => $this->org->id, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);
    app()->instance(EntryType::class, $this->type);

    /** @var TestUser $user */
    $user = TestUser::create(['email' => 'selector@kitsune.test']);
    DB::table('org_user')->insert(['org_id' => $this->org->getKey(), 'user_id' => $user->getKey()]);
    $this->role = Role::create(['handle' => 'selector', 'name' => 'Selector']);
    DB::table('role_user')->insert(['role_id' => $this->role->getKey(), 'user_id' => $user->getKey()]);
    $this->grant = function (string ...$actions): void {
        foreach ($actions as $action) {
            $this->role->grant(Permissions::forEntryType('image', $action));
        }
    };
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

/** A stored file, titled, stored by the system whatever the acting user may do. */
function bulkVisStored(string $title, string $bytes = '', string $visibility = 'private', bool $siteOnly = true, string $name = 'logo.png'): Entry
{
    $bytes = $bytes === '' ? (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==') : $bytes;
    $source = LocatedJpeg::file($bytes, 'kitsune-visbulk-');
    $user = auth()->user();
    auth()->forgetUser();

    try {
        return MediaLibrary::store($source, $name, test()->type, $visibility, title: $title, siteOnly: $siteOnly);
    } finally {
        unlink($source);

        if ($user !== null) {
            test()->actingAs($user);
        }
    }
}

/** @return list<array<string, mixed>> */
function bulkVisNotices(): array
{
    return array_values((array) session('filament.notifications', []));
}

function bulkVisRow(Entry $entry): string
{
    return (string) DB::table('media_files')->where('entry_id', $entry->id)->value('visibility');
}

function bulkVisAudits(string $action): int
{
    return DB::table('audit_log')->where('action', $action)->count();
}

/**
 * The entries as the grid loads them — trashed ones too, as *Everything* lists them, with their files — in this order.
 *
 * @return EloquentCollection<int, Entry>
 */
function bulkVisListed(Entry ...$entries): EloquentCollection
{
    $ids = array_map(static fn (Entry $entry): int => (int) $entry->id, $entries);
    $loaded = Entry::withTrashed()->with('mediaFile')->whereKey($ids)->get()->keyBy('id');

    return new EloquentCollection(array_map(static fn (int $id): Entry => $loaded[$id], $ids));
}

/**
 * The list's toolbar actions, by name, as the list builds them for the type bound.
 *
 * @param  ?array<string, mixed>  $filters
 * @return array<string, Action>
 */
function bulkVisActions(?array $filters = null): array
{
    $page = app(ListEntries::class);

    if ($filters !== null) {
        $page->tableFilters = $filters;
    }

    $table = EntryResource::table(Table::make($page));

    return collect($table->getToolbarActions()[0]->getActions())->keyBy(static fn (Action $action): string => (string) $action->getName())->all();
}

function bulkVisAction(string $name): BulkAction
{
    $action = bulkVisActions()[$name];
    assert($action instanceof BulkAction);

    return $action;
}

function bulkVisDeselects(BulkAction $action): bool
{
    return $action->shouldDeselectRecordsAfterCompletion();
}

const BULK_VIS_TICKED = ['public_confirmed' => true];

describe('the actions, as the list builds them', function (): void {
    /* S1. On a media list, first; on any other, none. */
    it('puts both first in a media list\'s bulk actions, and on no other list', function (): void {
        ($this->grant)('view', 'publish', 'delete');
        $actions = bulkVisActions();

        expect(array_keys($actions))->toBe(['makeSelectedPublic', 'makeSelectedPrivate', 'delete', 'restore', 'forceDelete'])
            ->and($actions['makeSelectedPublic'])->toBeInstanceOf(BulkAction::class)->not->toBeInstanceOf(DeleteBulkAction::class)
            ->and($actions['makeSelectedPublic']->getLabel())->toBe('Make selected public')
            ->and($actions['makeSelectedPrivate']->getLabel())->toBe('Make selected private')
            ->and($actions['makeSelectedPublic']->getModalSubmitActionLabel())->toBe('Make public')
            ->and($actions['makeSelectedPrivate']->getModalSubmitActionLabel())->toBe('Make private');

        app()->instance(EntryType::class, EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']));

        expect(array_keys(bulkVisActions()))->toBe(['delete', 'restore', 'forceDelete'])
            ->and(MediaVisibilityActions::bulk())->toBe([]);
    });

    /* S2. Hidden from a reader; disabled, naming the permission, with no field, for whoever may only update. */
    it('is hidden from a reader, and disabled with no field for whoever may only update', function (array $grants, bool $authorized, bool $disabled): void {
        ($this->grant)(...$grants);

        foreach (['makeSelectedPublic', 'makeSelectedPrivate'] as $name) {
            $action = bulkVisAction($name);

            expect($action->isAuthorized())->toBe($authorized, $name)
                ->and($action->isDisabled())->toBe($disabled, $name);

            expect($action->getTooltip())->toBe($disabled ? __('kitsune::media.visibility.needs_publish', ['type' => 'Images', 'permission' => 'entry.image.publish']) : null);
        }

        $schema = bulkVisAction('makeSelectedPublic')->getSchema(Schema::make(app(ListEntries::class)));

        expect($schema === null)->toBe($disabled);
    })->with([
        'view alone' => [['view'], false, true],
        'update' => [['view', 'update'], true, true],
        'publish' => [['view', 'publish'], true, false],
        'delete' => [['view', 'delete'], false, true],
    ]);

    /* S3. Decision 15's acknowledgement once, required; no transaction; Filament's own notices off, under any host's. */
    it('asks the acknowledgement as a required tick, outside any transaction, with Filament\'s notices off', function (): void {
        ($this->grant)('view', 'publish');
        Action::configureUsing(static fn (Action $action) => $action->databaseTransaction()->successNotificationTitle('Host success')->failureNotificationTitle('Host failure'));

        $public = bulkVisAction('makeSelectedPublic');
        $private = bulkVisAction('makeSelectedPrivate');
        $components = $public->getSchema(Schema::make(app(ListEntries::class)))?->getFlatComponents(withHidden: true) ?? [];
        $checkbox = collect($components)->first(static fn ($component): bool => $component instanceof Checkbox);
        $helper = null;

        foreach ($checkbox?->getChildSchema(Field::BELOW_CONTENT_SCHEMA_KEY)?->getComponents() ?? [] as $component) {
            $helper = $component instanceof Text ? (string) $component->getContent() : $helper;
        }

        expect($checkbox)->toBeInstanceOf(Checkbox::class)
            ->and($checkbox->getName())->toBe('public_confirmed')
            ->and($checkbox->getLabel())->toBe('Make these files public')
            ->and($helper)->toBe(__('kitsune::media.visibility.bulk.public_warning'))
            ->and($checkbox->getValidationRules())->toContain('accepted')
            ->and($checkbox->getValidationMessages()['accepted'] ?? null)->toBe(__('kitsune::media.visibility.bulk.not_confirmed'))
            ->and($public->isConfirmationRequired())->toBeFalse()
            ->and($private->isConfirmationRequired())->toBeTrue()
            ->and($private->getSchema(Schema::make(app(ListEntries::class))))->toBeNull();

        foreach ([$public, $private] as $action) {
            expect($action->hasDatabaseTransactions())->toBeFalse()
                ->and((fn (): bool => $this->isSuccessNotificationDisabled)->call($action))->toBeTrue()
                ->and((fn (): bool => $this->isFailureNotificationDisabled)->call($action))->toBeTrue();
        }
    });

    /* S4. Not offered while the list shows only the trash, as Delete selected is not. */
    it('is not offered while the list shows only the trash', function (mixed $value, bool $hidden, bool $restoreHidden): void {
        ($this->grant)('view', 'publish', 'delete');
        $actions = bulkVisActions($value === 'unset' ? [] : ['trashed' => ['value' => $value]]);

        // As Delete selected is hidden; Restore selected, the other way about, is the trash's.
        expect($actions['makeSelectedPublic']->isHidden())->toBe($hidden)
            ->and($actions['makeSelectedPrivate']->isHidden())->toBe($hidden)
            ->and($actions['delete']->isHidden())->toBe($hidden)
            ->and($actions['restore']->isHidden())->toBe($restoreHidden);
    })->with([
        'no filter' => ['unset', false, false],
        'without the trash' => ['', false, true],
        'null' => [null, false, true],
        'with the trash' => ['1', false, false],
        'with the trash, as true' => [true, false, false],
        'only the trash' => ['0', true, false],
        'only the trash, as false' => [false, true, false],
    ]);

    /* S5. The selection is cleared once every file is as asked, and kept otherwise. */
    it('clears the selection only when every file is as asked', function (string $name): void {
        $action = bulkVisAction($name);

        $action->success();
        expect(bulkVisDeselects($action))->toBeTrue();

        $action->failure();
        expect(bulkVisDeselects($action))->toBeFalse();
    })->with(['makeSelectedPublic', 'makeSelectedPrivate']);

    /* S6. The modal's heading counts the selection; its note counts the shared files and warns of the bound. */
    it('counts the selection, its shared files and the bound in the modal\'s words', function (): void {
        expect(MediaVisibilityActions::selectionHeading('public', 1))->toBe('Make the selected file public')
            ->and(MediaVisibilityActions::selectionHeading('public', 3))->toBe('Make the 3 selected files public')
            ->and(MediaVisibilityActions::selectionHeading('private', 1))->toBe('Make the selected file private')
            ->and(MediaVisibilityActions::selectionHeading('private', 2))->toBe('Make the 2 selected files private')
            ->and(MediaVisibilityActions::selectionNote('public', 3, 0))->toBeNull()
            ->and(MediaVisibilityActions::selectionNote('public', 3, 1))->toStartWith('One of them is shared with every site')
            ->and(MediaVisibilityActions::selectionNote('public', 3, 2))->toStartWith('2 of them are shared with every site')
            ->and(MediaVisibilityActions::selectionNote('public', 3, 3))->toStartWith('Each of them is shared with every site')
            ->and(MediaVisibilityActions::selectionNote('public', 1, 1))->toBe(__('kitsune::media.visibility.shared_public'))
            ->and(MediaVisibilityActions::selectionNote('private', 2, 0))->toBe(__('kitsune::media.visibility.bulk.private_warning'))
            ->and(MediaVisibilityActions::selectionNote('private', 2, 1))->toBe(__('kitsune::media.visibility.bulk.private_warning').' One of them is shared with every site in the organisation, so it becomes private for all of those sites.')
            ->and(MediaVisibilityActions::selectionNote('private', 1, 1))->toBe(__('kitsune::media.visibility.bulk.private_warning').' '.__('kitsune::media.visibility.shared_private'))
            ->and(MediaVisibilityActions::selectionNote('public', 50, 0))->toBeNull()
            ->and(MediaVisibilityActions::selectionNote('public', 51, 0))->toBe('At most 50 files are switched at a time, and 51 are selected, so as it is nothing will be changed. Select fewer first.')
            ->and(MediaVisibilityActions::selectionNote('public', 51, 2))->toBe('2 of them are shared with every site in the organisation, so they become public for all of those sites. At most 50 files are switched at a time, and 51 are selected, so as it is nothing will be changed. Select fewer first.');
    });

    /* S7. Counted without the list's sort, which PostgreSQL refuses beside count(*): Laravel drops it, pinned here. */
    it('counts the selection and its shared files without the list\'s sort', function (): void {
        $a = bulkVisStored('A');
        $b = bulkVisStored('B', siteOnly: false);
        $c = bulkVisStored('C', siteOnly: false);
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        expect(MediaVisibilityActions::countsOf(Entry::query()->whereKey([$a->id, $b->id, $c->id])->orderByDesc('updated_at')))->toBe([3, 2]);

        $counts = array_values(array_filter($queries, static fn (string $sql): bool => str_contains(strtolower($sql), 'count(')));

        expect($counts)->toHaveCount(2);

        foreach ($counts as $sql) {
            expect(strtolower($sql))->not->toContain('order by');
        }
    });

    /* S8. At most fifty, fetched in one bounded query, or none. */
    it('fetches at most fifty in one bounded query, and refuses more whole', function (): void {
        ($this->grant)('view', 'publish');
        $files = [bulkVisStored('A'), bulkVisStored('B'), bulkVisStored('C')];
        $query = Entry::query()->whereKey(array_map(static fn (Entry $e): int => (int) $e->id, $files));

        expect(MediaVisibilityActions::upTo($query, most: 2))->toBeNull()
            ->and(MediaVisibilityActions::upTo($query, most: 3))->toHaveCount(3)
            ->and(MediaVisibilityActions::MOST_AT_ONCE)->toBe(50);

        // In the list's order, which is the order a budget cuts off in (review of decision 34).
        $ids = array_map(static fn (Entry $e): int => (int) $e->id, $files);

        expect(MediaVisibilityActions::upTo(Entry::query()->whereKey($ids)->orderByDesc('id'))?->modelKeys())->toBe(array_reverse($ids));

        // No page the list offers holds more than the bound, and none offers every record at once.
        $table = EntryResource::table(Table::make(app(ListEntries::class)));
        $sizes = array_filter($table->getPaginationPageOptions(), 'is_int');

        expect(max($sizes))->toBeLessThanOrEqual(MediaVisibilityActions::MOST_AT_ONCE)
            ->and($table->getPaginationPageOptions())->not->toContain('all');

        $action = bulkVisAction('makeSelectedPublic');
        MediaVisibilityActions::refuseTooMany($action);

        expect(bulkVisNotices())->toHaveCount(1)
            ->and(bulkVisNotices()[0]['status'])->toBe('danger')
            ->and(bulkVisNotices()[0]['duration'])->toBe('persistent')
            ->and(bulkVisNotices()[0]['title'])->toBe('Too many files are selected')
            ->and(bulkVisNotices()[0]['body'])->toBe('More than 50 files are selected, and at most 50 are switched at a time, so nothing was changed. Select 50 or fewer, and run it again.')
            ->and($action->getStatus()->name)->toBe('Failure');
    });

    /* S8, through the handlers: fifty-one selected, nothing switched either way. */
    it('switches nothing when more than fifty are selected', function (string $to): void {
        ($this->grant)('view', 'publish');
        $ids = [];

        for ($i = 1; $i <= 51; $i++) {
            $ids[] = (int) bulkVisStored("File {$i}", visibility: $to === 'public' ? 'private' : 'public')->id;
        }

        $action = bulkVisAction($to === 'public' ? 'makeSelectedPublic' : 'makeSelectedPrivate');
        $to === 'public'
            ? MediaVisibilityActions::publicSelected($action, Entry::query()->whereKey($ids), BULK_VIS_TICKED)
            : MediaVisibilityActions::privateSelected($action, Entry::query()->whereKey($ids));

        expect(DB::table('media_files')->whereIn('entry_id', $ids)->where('visibility', $to)->count())->toBe(0)
            ->and(bulkVisAudits($to === 'public' ? MediaVisibility::MADE_PUBLIC : MediaVisibility::MADE_PRIVATE))->toBe(0)
            ->and(bulkVisNotices())->toHaveCount(1)
            ->and(bulkVisNotices()[0]['title'])->toBe('Too many files are selected');

        // Fifty is not too many (review of decision 34: two would pass for any bound above one).
        session()->forget('filament.notifications');
        $to === 'public'
            ? MediaVisibilityActions::publicSelected($action, Entry::query()->whereKey(array_slice($ids, 0, 50)), BULK_VIS_TICKED)
            : MediaVisibilityActions::privateSelected($action, Entry::query()->whereKey(array_slice($ids, 0, 50)));

        expect(DB::table('media_files')->whereIn('entry_id', $ids)->where('visibility', $to)->count())->toBe(50)
            ->and(bulkVisNotices()[0]['title'])->toBe("50 files were made {$to}");
    })->with(['public', 'private']);

    /* ⚠️ AND NEVER LOADED WHOLE FIRST: a select-all is the whole list, and the bound is in the query (review of decision 34). */
    it('loads no more than one past the bound to refuse a larger selection', function (): void {
        ($this->grant)('view', 'publish');
        $ids = [];

        for ($i = 1; $i <= MediaVisibilityActions::MOST_AT_ONCE + 5; $i++) {
            $ids[] = (int) bulkVisStored("File {$i}")->id;
        }

        $loaded = 0;
        Entry::retrieved(static function () use (&$loaded): void {
            $loaded++;
        });

        MediaVisibilityActions::publicSelected(bulkVisAction('makeSelectedPublic'), Entry::query()->whereKey($ids), BULK_VIS_TICKED);

        expect($loaded)->toBe(MediaVisibilityActions::MOST_AT_ONCE + 1)
            ->and(bulkVisNotices()[0]['title'])->toBe('Too many files are selected');
    });
});

describe('making a selection public', function (): void {
    beforeEach(fn () => ($this->grant)('view', 'publish'));

    /* H1. Each file on its own; one notice naming every one not made public, escaped, and counting the rest. */
    it('makes each file public on its own, and names in one notice each it could not', function (): void {
        $logo = bulkVisStored('Logo');
        $scorecard = bulkVisStored('<i>Scorecard</i>', LocatedJpeg::unremovable(), name: 'shared.jpg');
        $banner = bulkVisStored('Banner', visibility: 'public');
        $old = bulkVisStored('Old');
        $old->delete();
        $shared = bulkVisStored('Shared', siteOnly: false);
        $action = bulkVisAction('makeSelectedPublic');

        MediaVisibilityActions::publicEach($action, bulkVisListed($logo, $scorecard, $banner, $old, $shared), BULK_VIS_TICKED);

        expect([bulkVisRow($logo), bulkVisRow($scorecard), bulkVisRow($banner), bulkVisRow($old), bulkVisRow($shared)])
            ->toBe(['public', 'private', 'public', 'private', 'public']);

        $notice = bulkVisNotices();

        expect($notice)->toHaveCount(1)
            ->and($notice[0]['status'])->toBe('danger')
            ->and($notice[0]['duration'])->toBe('persistent')
            ->and($notice[0]['title'])->toBe('2 files were not made public');

        $lines = explode('<br>', (string) $notice[0]['body']);

        expect($lines)->toHaveCount(4)
            ->and($lines[0])->toStartWith('&quot;&lt;i&gt;Scorecard&lt;/i&gt;&quot; was not made public: Refusing to make [&lt;i&gt;Scorecard&lt;/i&gt;] public')
            ->and($lines[0])->toContain('cannot be removed with certainty')
            ->and($lines[1])->toBe("&quot;Old&quot; was not made public: Refusing to make entry {$old->id} public: it is in the trash, where its file is private whatever it is set to, so nothing was changed. Restore it first.")
            ->and($lines[2])->toBe('2 files were made public.')
            ->and($lines[3])->toBe('One was already public, and was left as it was.')
            ->and((string) $notice[0]['body'])->not->toContain('<i>')
            ->and(bulkVisAudits(MediaVisibility::MADE_PUBLIC))->toBe(2)
            ->and($action->getStatus()->name)->toBe('Failure')
            ->and(bulkVisDeselects($action))->toBeFalse();
    });

    /* H2. All made public — read again, for the list loaded each file before its switch. */
    it('says every file was made public, and clears the selection', function (): void {
        $files = [bulkVisStored('A'), bulkVisStored('B', siteOnly: false), bulkVisStored('C')];
        $action = bulkVisAction('makeSelectedPublic');

        MediaVisibilityActions::publicEach($action, bulkVisListed(...$files), BULK_VIS_TICKED);

        expect(array_map('bulkVisRow', $files))->toBe(['public', 'public', 'public'])
            ->and(bulkVisNotices())->toHaveCount(1)
            ->and(bulkVisNotices()[0]['status'])->toBe('success')
            ->and(bulkVisNotices()[0]['title'])->toBe('3 files were made public')
            ->and(bulkVisNotices()[0]['body'] ?? null)->toBeNull()
            ->and(bulkVisNotices()[0]['duration'])->not->toBe('persistent')
            ->and(bulkVisAudits(MediaVisibility::MADE_PUBLIC))->toBe(3)
            ->and($action->getStatus()->name)->toBe('Success')
            ->and(bulkVisDeselects($action))->toBeTrue();
    });

    /* H3. Already public is no failure: nothing written, nothing audited, and said. */
    it('says files already public were left, as no failure', function (): void {
        $action = bulkVisAction('makeSelectedPublic');

        MediaVisibilityActions::publicEach($action, bulkVisListed(bulkVisStored('A', visibility: 'public'), bulkVisStored('B', visibility: 'public')), BULK_VIS_TICKED);

        expect(bulkVisNotices())->toHaveCount(1)
            ->and(bulkVisNotices()[0]['status'])->toBe('info')
            ->and(bulkVisNotices()[0]['title'])->toBe('All 2 files were already public. Nothing was changed.')
            ->and(bulkVisAudits(MediaVisibility::MADE_PUBLIC))->toBe(0)
            ->and($action->getStatus()->name)->toBe('Success')
            ->and(bulkVisDeselects($action))->toBeTrue();
    });

    /* H4. Unticked, or a tick forged as anything but true: nothing switched, and said. */
    it('switches nothing unticked', function (array $data): void {
        $files = [bulkVisStored('A'), bulkVisStored('B')];
        $action = bulkVisAction('makeSelectedPublic');

        MediaVisibilityActions::publicEach($action, bulkVisListed(...$files), $data);

        expect(array_map('bulkVisRow', $files))->toBe(['private', 'private'])
            ->and(bulkVisNotices())->toHaveCount(1)
            ->and(bulkVisNotices()[0]['status'])->toBe('danger')
            ->and(bulkVisNotices()[0]['title'])->toBe('No file was made public')
            ->and(bulkVisNotices()[0]['body'])->toBe(e(__('kitsune::media.visibility.bulk.not_confirmed')))
            ->and(bulkVisAudits(MediaVisibility::MADE_PUBLIC))->toBe(0)
            ->and($action->getStatus()->name)->toBe('Failure')
            ->and(bulkVisDeselects($action))->toBeFalse();
    })->with([
        'no answer' => [[]],
        'unticked' => [['public_confirmed' => false]],
        'a forged string' => [['public_confirmed' => '1']],
    ]);

    /* H5. Committed, and publication failed after: named together with one reconcile command, never claimed done. */
    it('names the files made public and not yet published, with one command to publish them', function (): void {
        $a = bulkVisStored('A');
        $b = bulkVisStored('<b>B</b>');
        $this->disks['public']->failWrites = true;
        $action = bulkVisAction('makeSelectedPublic');

        MediaVisibilityActions::publicEach($action, bulkVisListed($a, $b), BULK_VIS_TICKED);

        expect([bulkVisRow($a), bulkVisRow($b)])->toBe(['public', 'public'])
            ->and(bulkVisNotices())->toHaveCount(1)
            ->and(bulkVisNotices()[0]['status'])->toBe('warning')
            ->and(bulkVisNotices()[0]['duration'])->toBe('persistent')
            ->and(bulkVisNotices()[0]['title'])->toBe('2 files are public, and not yet published')
            ->and(bulkVisNotices()[0]['body'])->toBe('Not yet published: &quot;A&quot;, &quot;&lt;b&gt;B&lt;/b&gt;&quot;. Until they are, their links do not open them, and they open only through this admin. The log says why; '
                ."kitsune:media-reconcile --entry={$a->id} --entry={$b->id} --force publishes them.")
            ->and($action->getStatus()->name)->toBe('Success');
    });

    /* H7. A failure not Kitsune's: reported once, named in words that claim nothing, its message unshown; the rest go on. */
    it('reports a failure not Kitsune\'s once, names its file without its message, and goes on', function (): void {
        Exceptions::fake();
        $files = [bulkVisStored('A'), bulkVisStored('B'), bulkVisStored('C')];
        AuditorStandIn::install()->throwOnce(new RuntimeException('secret <b>sql</b>'));
        $action = bulkVisAction('makeSelectedPublic');

        MediaVisibilityActions::publicEach($action, bulkVisListed(...$files), BULK_VIS_TICKED);

        expect(array_map('bulkVisRow', $files))->toBe(['private', 'public', 'public'])
            ->and(bulkVisNotices())->toHaveCount(1)
            ->and(bulkVisNotices()[0]['status'])->toBe('danger')
            ->and(bulkVisNotices()[0]['title'])->toBe('One file was not made public')
            ->and(bulkVisNotices()[0]['body'])->toBe('&quot;A&quot; may not have been made public: something went wrong. Its page shows what it is now; tell whoever runs this site if it happens again.<br>2 files were made public.')
            ->and((string) bulkVisNotices()[0]['body'])->not->toContain('secret')
            ->and($action->getStatus()->name)->toBe('Failure');

        Exceptions::assertReportedCount(1);
    });

    /* H8. Every file failing: one report, one line, and a file already public counted so — what it was, not what it is. */
    it('names every failed file in one line, reports once, and counts one already public as already', function (): void {
        Exceptions::fake();
        $records = bulkVisListed(bulkVisStored('A'), bulkVisStored('B'), bulkVisStored('C'), bulkVisStored('D', visibility: 'public'));
        app(Context::class)->forget();
        $action = bulkVisAction('makeSelectedPublic');

        MediaVisibilityActions::publicEach($action, $records, BULK_VIS_TICKED);

        expect(DB::table('media_files')->whereIn('entry_id', $records->modelKeys())->pluck('visibility')->sort()->values()->all())->toBe(['private', 'private', 'private', 'public'])
            ->and(bulkVisNotices())->toHaveCount(1)
            ->and(bulkVisNotices()[0]['title'])->toBe('3 files were not made public')
            ->and(bulkVisNotices()[0]['body'])->toBe('&quot;A&quot;, &quot;B&quot;, &quot;C&quot; may not have been made public: something went wrong. Their pages show what each is now; tell whoever runs this site if it happens again.<br>One was already public, and was left as it was.');

        Exceptions::assertReportedCount(1);
    });

    /* H10. The budget: past it no file is started, the one in hand finishes, and the rest are counted and stay selected. */
    it('starts no file once the budget has passed, and says how many were not tried', function (string $to): void {
        $this->freezeTime();
        $files = [bulkVisStored('A', visibility: $to === 'public' ? 'private' : 'public'), bulkVisStored('B', visibility: $to === 'public' ? 'private' : 'public'), bulkVisStored('C', visibility: $to === 'public' ? 'private' : 'public')];
        AuditorStandIn::install()->beforeRecording(fn () => $this->travel(11)->seconds());
        $action = bulkVisAction($to === 'public' ? 'makeSelectedPublic' : 'makeSelectedPrivate');

        $to === 'public'
            ? MediaVisibilityActions::publicEach($action, bulkVisListed(...$files), BULK_VIS_TICKED, now()->addSeconds(10))
            : MediaVisibilityActions::privateEach($action, bulkVisListed(...$files), now()->addSeconds(10));

        $other = $to === 'public' ? 'private' : 'public';
        $made = $to === 'public'
            ? 'One file was made public.'
            : 'One file was made private.<br>Its public link no longer opens it. A copy a browser, a proxy or a CDN has already kept can be served until it expires.';

        expect(array_map('bulkVisRow', $files))->toBe([$to, $other, $other])
            ->and(bulkVisNotices())->toHaveCount(1)
            ->and(bulkVisNotices()[0]['status'])->toBe('warning')
            ->and(bulkVisNotices()[0]['duration'])->toBe('persistent')
            ->and(bulkVisNotices()[0]['title'])->toBe("2 files were not made {$to}")
            ->and(bulkVisNotices()[0]['body'])->toBe('2 files were not tried: one request may take only so long. They are still selected; run it again on the same selection to go on.<br>'.$made)
            ->and($action->getStatus()->name)->toBe('Failure')
            ->and(bulkVisDeselects($action))->toBeFalse();
    })->with(['public', 'private']);

    /* H11. The first file is always tried, so every run goes on. */
    it('always tries the first file, however late', function (): void {
        $files = [bulkVisStored('A'), bulkVisStored('B')];
        $action = bulkVisAction('makeSelectedPublic');

        MediaVisibilityActions::publicEach($action, bulkVisListed(...$files), BULK_VIS_TICKED, now()->subSecond());

        expect(array_map('bulkVisRow', $files))->toBe(['public', 'private'])
            ->and(bulkVisNotices()[0]['body'])->toStartWith('One file was not tried: one request may take only so long. It is still selected;');
    });

    /* A refusal beside files not tried: the refusal decides the colour (review of decision 34). */
    it('is a danger notice when a file was refused and others were not tried', function (): void {
        $old = bulkVisStored('Old');
        $old->delete();
        $action = bulkVisAction('makeSelectedPublic');

        MediaVisibilityActions::publicEach($action, bulkVisListed($old, bulkVisStored('A'), bulkVisStored('B')), BULK_VIS_TICKED, now()->subSecond());

        expect(bulkVisNotices())->toHaveCount(1)
            ->and(bulkVisNotices()[0]['status'])->toBe('danger')
            ->and(bulkVisNotices()[0]['title'])->toBe('3 files were not made public');
    });

    /* Files published beside one whose publication failed: the title says not yet published, the body counts the rest. */
    it('counts the files published beside one not yet published', function (): void {
        $a = bulkVisStored('A');
        $b = bulkVisStored('B');
        $n = 0;
        AuditorStandIn::install()->beforeRecording(function () use (&$n): void {
            $this->disks['public']->failWrites = ++$n === 2;
        });
        $action = bulkVisAction('makeSelectedPublic');

        MediaVisibilityActions::publicEach($action, bulkVisListed($a, $b), BULK_VIS_TICKED);

        expect(bulkVisNotices()[0]['status'])->toBe('warning')
            ->and(bulkVisNotices()[0]['title'])->toBe('One file is public, and not yet published')
            ->and(explode('<br>', (string) bulkVisNotices()[0]['body']))->toBe([
                'Not yet published: &quot;B&quot;. Until it is, its link does not open it, and it opens only through this admin. The log says why; '
                    ."kitsune:media-reconcile --entry={$b->id} --force publishes it.",
                'One file was made public.',
            ]);
    });

    /*
     * ⚠️ SELECTED, AND GONE FROM THE LIST SINCE — review of decision 34. Filament fetches a selection through the list's
     * filters, so a file trashed or renamed out of its search since it was ticked is not there to switch; it is said,
     * never dropped from a notice that reads as the whole selection done.
     */
    it('says how many of the selection have left the list since it was made', function (): void {
        $a = bulkVisStored('A');
        $b = bulkVisStored('B');
        $action = bulkVisAction('makeSelectedPublic');

        MediaVisibilityActions::publicEach($action, bulkVisListed($a, $b), BULK_VIS_TICKED, keys: 3);

        expect(bulkVisNotices())->toHaveCount(1)
            ->and(bulkVisNotices()[0]['status'])->toBe('warning')
            ->and(bulkVisNotices()[0]['duration'])->toBe('persistent')
            ->and(bulkVisNotices()[0]['title'])->toBe('2 files were made public')
            ->and(bulkVisNotices()[0]['body'])->toBe('One of the selected files is no longer on this list, and was left as it was.')
            ->and($action->getStatus()->name)->toBe('Success');

        session()->forget('filament.notifications');
        MediaVisibilityActions::publicEach($action, bulkVisListed($a, $b), BULK_VIS_TICKED, keys: 4);

        expect(bulkVisNotices()[0]['status'])->toBe('warning')
            ->and(bulkVisNotices()[0]['title'])->toBe('All 2 files were already public. Nothing was changed.')
            ->and(bulkVisNotices()[0]['body'])->toBe('2 of the selected files are no longer on this list, and were left as they were.');
    });

    /* ⚠️ THROUGH FILAMENT'S OWN SELECTION: the keys the page holds, the list's filters, and the tick, as the action is called. */
    it('switches what the page selected, through the list\'s own query, and says what left it', function (): void {
        $a = bulkVisStored('A');
        $b = bulkVisStored('B');
        $c = bulkVisStored('C');
        $page = app(ListEntries::class);
        $page->bootedInteractsWithTable();
        $page->selectedTableRecords = [(string) $a->id, (string) $b->id, (string) $c->id];
        $c->delete();
        $action = collect($page->getTable()->getToolbarActions()[0]->getActions())->first(static fn (Action $action): bool => $action->getName() === 'makeSelectedPublic');
        $action->data(BULK_VIS_TICKED);

        $action->call();

        expect([bulkVisRow($a), bulkVisRow($b), bulkVisRow($c)])->toBe(['public', 'public', 'private'])
            ->and(bulkVisNotices())->toHaveCount(1)
            ->and(bulkVisNotices()[0]['title'])->toBe('2 files were made public')
            ->and(bulkVisNotices()[0]['body'])->toBe('One of the selected files is no longer on this list, and was left as it was.');

        // Every record selected but those deselected: the selection is the list's own query, which nothing has left.
        session()->forget('filament.notifications');
        $page->isTrackingDeselectedTableRecords = true;
        $page->deselectedTableRecords = [];
        $action->call();

        expect(bulkVisNotices()[0]['title'])->toBe('All 2 files were already public. Nothing was changed.')
            ->and(bulkVisNotices()[0]['body'] ?? null)->toBeNull();

        // And made private the same way, the page's keys counted too.
        session()->forget('filament.notifications');
        $page->isTrackingDeselectedTableRecords = false;
        $private = collect($page->getTable()->getToolbarActions()[0]->getActions())->first(static fn (Action $action): bool => $action->getName() === 'makeSelectedPrivate');
        $private->call();

        expect([bulkVisRow($a), bulkVisRow($b), bulkVisRow($c)])->toBe(['private', 'private', 'private'])
            ->and(bulkVisNotices()[0]['title'])->toBe('2 files were made private')
            ->and(bulkVisNotices()[0]['body'])->toEndWith('One of the selected files is no longer on this list, and was left as it was.');
    });

    /* ⚠️ EVERY TITLE ESCAPED, its words included: a translation is not trusted with markup either (decision 7). */
    it('escapes the notice\'s title, whatever its translation says', function (): void {
        app('translator')->addLines(['media.visibility.bulk.made_public' => '<b>:count</b> made public'], 'en', 'kitsune');

        MediaVisibilityActions::publicEach(bulkVisAction('makeSelectedPublic'), bulkVisListed(bulkVisStored('A')), BULK_VIS_TICKED);

        expect(bulkVisNotices()[0]['title'])->toBe('&lt;b&gt;1&lt;/b&gt; made public');
    });

    /* A file whose row cannot be read again after a failure is named with the failures, never counted (review of decision 34). */
    it('claims nothing of a file it cannot read again after a failure', function (): void {
        Exceptions::fake();
        $records = bulkVisListed(bulkVisStored('D', visibility: 'public'));
        app(Context::class)->forget();
        DB::listen(static function ($query): void {
            if (preg_match('/^select [`"]?deleted_at[`"]? from [`"]?entries/', $query->sql) === 1) {
                throw new RuntimeException('unreadable');
            }
        });

        MediaVisibilityActions::publicEach(bulkVisAction('makeSelectedPublic'), $records, BULK_VIS_TICKED);

        expect(bulkVisNotices()[0]['title'])->toBe('One file was not made public')
            ->and(bulkVisNotices()[0]['body'])->toStartWith('&quot;D&quot; may not have been made public');
    });

    /*
     * ⚠️ A FILE IN THE TRASH IS NEVER PUBLIC ALREADY — review of decision 34. A failure before the switch's own guard
     * would otherwise count a trashed file set public as public, where the guard refuses it.
     */
    it('never counts a trashed file as already public when its switch fails', function (): void {
        Exceptions::fake();
        $old = bulkVisStored('Old', visibility: 'public');
        $old->delete();
        $records = bulkVisListed($old);
        app(Context::class)->forget();
        $action = bulkVisAction('makeSelectedPublic');

        MediaVisibilityActions::publicEach($action, $records, BULK_VIS_TICKED);

        expect(bulkVisNotices()[0]['status'])->toBe('danger')
            ->and(bulkVisNotices()[0]['title'])->toBe('One file was not made public')
            ->and(bulkVisNotices()[0]['body'])->toStartWith('&quot;Old&quot; may not have been made public')
            ->and($action->getStatus()->name)->toBe('Failure');
    });

    /* H20. Nothing resolved: the selection's files have left the list since it was made. */
    it('says so when none of the selection is on the list any more', function (): void {
        $action = bulkVisAction('makeSelectedPublic');

        MediaVisibilityActions::publicEach($action, [], BULK_VIS_TICKED);

        expect(bulkVisNotices())->toHaveCount(1)
            ->and(bulkVisNotices()[0]['status'])->toBe('info')
            ->and(bulkVisNotices()[0]['title'])->toBe('None of the selected files is on this list any more. Nothing was changed.')
            ->and($action->getStatus()->name)->toBe('Success');
    });

    /* H14. The handlers fetch the selection and pass the request's budget. */
    it('passes the request\'s budget from the handler', function (string $to): void {
        $this->freezeTime();
        $files = [bulkVisStored('A', visibility: $to === 'public' ? 'private' : 'public'), bulkVisStored('B', visibility: $to === 'public' ? 'private' : 'public')];
        // Eleven seconds a file, under a limit of twenty: only half of it, ten, stops the second (review of decision 34).
        AuditorStandIn::install()->beforeRecording(fn () => $this->travel(11)->seconds());
        $query = Entry::query()->with('mediaFile')->whereKey(array_map(static fn (Entry $e): int => (int) $e->id, $files))->orderBy('id');
        $action = bulkVisAction($to === 'public' ? 'makeSelectedPublic' : 'makeSelectedPrivate');
        $limit = ini_get('max_execution_time');

        try {
            ini_set('max_execution_time', '20');
            $to === 'public' ? MediaVisibilityActions::publicSelected($action, $query, BULK_VIS_TICKED) : MediaVisibilityActions::privateSelected($action, $query);
        } finally {
            ini_set('max_execution_time', (string) $limit);
        }

        expect(array_map('bulkVisRow', $files))->toBe([$to, $to === 'public' ? 'private' : 'public'])
            ->and(bulkVisNotices()[0]['body'])->toStartWith('One file was not tried');
    })->with(['public', 'private']);
});

/* H6. The switch asks again under its lock: a direct call by whoever may not publish is refused, file by file. */
it('is refused file by file for whoever may not publish', function (): void {
    ($this->grant)('view', 'update');
    $files = [bulkVisStored('A'), bulkVisStored('B')];
    $action = bulkVisAction('makeSelectedPublic');

    MediaVisibilityActions::publicEach($action, bulkVisListed(...$files), BULK_VIS_TICKED);

    $lines = explode('<br>', (string) bulkVisNotices()[0]['body']);

    expect(array_map('bulkVisRow', $files))->toBe(['private', 'private'])
        ->and(bulkVisNotices()[0]['title'])->toBe('2 files were not made public')
        ->and($lines)->toHaveCount(2)
        ->and($lines[0])->toContain('that needs [entry.image.publish]')
        ->and($lines[1])->toContain('that needs [entry.image.publish]')
        ->and(bulkVisAudits(MediaVisibility::MADE_PUBLIC))->toBe(0);
});

/* ⚠️ THE BUDGET STARTS BEFORE THE SELECTION IS FETCHED: a slow fetch spends it, never extends it (Codex, #166). */
it('counts the time the selection takes to load against the budget', function (string $to): void {
    ($this->grant)('view', 'publish');
    $this->freezeTime();
    $files = [bulkVisStored('A', visibility: $to === 'public' ? 'private' : 'public'), bulkVisStored('B', visibility: $to === 'public' ? 'private' : 'public')];
    $query = Entry::query()->with('mediaFile')->whereKey(array_map(static fn (Entry $e): int => (int) $e->id, $files))->orderBy('id');
    $travelled = false;
    // The fetch takes sixteen seconds: past the budget of fifteen before any file is switched.
    Entry::retrieved(function () use (&$travelled): void {
        if (! $travelled) {
            $travelled = true;
            $this->travel(16)->seconds();
        }
    });
    $action = bulkVisAction($to === 'public' ? 'makeSelectedPublic' : 'makeSelectedPrivate');

    $to === 'public' ? MediaVisibilityActions::publicSelected($action, $query, BULK_VIS_TICKED) : MediaVisibilityActions::privateSelected($action, $query);

    // The first is always tried; the second is not started.
    expect(array_map('bulkVisRow', $files))->toBe([$to, $to === 'public' ? 'private' : 'public'])
        ->and(bulkVisNotices()[0]['body'])->toStartWith('One file was not tried');
})->with(['public', 'private']);

describe('making a selection private', function (): void {
    beforeEach(fn () => ($this->grant)('view', 'publish'));

    /* H15. In the trash: private already is as asked; set public is named, for a restore would publish it. */
    it('makes each file private, and names one in the trash that is set public', function (): void {
        $logo = bulkVisStored('Logo', visibility: 'public');
        $draft = bulkVisStored('Draft');
        $old = bulkVisStored('Old', visibility: 'public');
        $old->delete();
        $gone = bulkVisStored('Gone');
        $gone->delete();
        $path = (string) DB::table('media_files')->where('entry_id', $logo->id)->value('path');
        $action = bulkVisAction('makeSelectedPrivate');

        MediaVisibilityActions::privateEach($action, bulkVisListed($logo, $draft, $old, $gone));

        expect([bulkVisRow($logo), bulkVisRow($draft), bulkVisRow($old), bulkVisRow($gone)])->toBe(['private', 'private', 'public', 'private'])
            ->and(is_file($this->disks['public']->root().'/'.$path))->toBeFalse()
            ->and(bulkVisNotices())->toHaveCount(1)
            ->and(bulkVisNotices()[0]['status'])->toBe('danger')
            ->and(bulkVisNotices()[0]['title'])->toBe('One file was not made private')
            ->and(explode('<br>', (string) bulkVisNotices()[0]['body']))->toBe([
                '&quot;Old&quot; is in the trash, so it was not made private. Its file is off the web while it is there, but it is set public, and a restore publishes it again.',
                'One file was made private.',
                'Its public link no longer opens it. A copy a browser, a proxy or a CDN has already kept can be served until it expires.',
                '2 were already private, and were left as they were.',
            ])
            ->and(bulkVisAudits(MediaVisibility::MADE_PRIVATE))->toBe(1)
            ->and($action->getStatus()->name)->toBe('Failure');
    });

    /* H16. A withdrawal refused is named in the trash's words, escaped, and the file stays public. */
    it('names a file whose withdrawal was refused, escaped, and leaves it public', function (): void {
        $scorecard = bulkVisStored('<i>Scorecard</i>', visibility: 'public');
        $b = bulkVisStored('B', visibility: 'public');
        $this->disks['public']->failDeletes = true;
        $action = bulkVisAction('makeSelectedPrivate');

        MediaVisibilityActions::privateEach($action, bulkVisListed($scorecard, $b));

        $lines = explode('<br>', (string) bulkVisNotices()[0]['body']);

        expect([bulkVisRow($scorecard), bulkVisRow($b)])->toBe(['public', 'public'])
            ->and(bulkVisNotices()[0]['title'])->toBe('2 files were not made private')
            ->and($lines[0])->toStartWith("&quot;&lt;i&gt;Scorecard&lt;/i&gt;&quot; was not made private: Refusing to make entry {$scorecard->id} private: its file could not be withdrawn")
            ->and($lines[1])->toStartWith("&quot;B&quot; was not made private: Refusing to make entry {$b->id} private");
    });

    /* H17. All made private: said, with what that does, and the selection cleared. */
    it('says every file was made private, and what that does', function (): void {
        $files = [bulkVisStored('A', visibility: 'public'), bulkVisStored('B', visibility: 'public')];
        $action = bulkVisAction('makeSelectedPrivate');

        MediaVisibilityActions::privateEach($action, bulkVisListed(...$files));

        expect(array_map('bulkVisRow', $files))->toBe(['private', 'private'])
            ->and(bulkVisNotices())->toHaveCount(1)
            ->and(bulkVisNotices()[0]['status'])->toBe('success')
            ->and(bulkVisNotices()[0]['title'])->toBe('2 files were made private')
            ->and(bulkVisNotices()[0]['body'])->toBe(e(trans_choice('kitsune::media.visibility.bulk.made_private_body', 2)))
            ->and(bulkVisNotices()[0]['duration'])->not->toBe('persistent')
            ->and(bulkVisAudits(MediaVisibility::MADE_PRIVATE))->toBe(2)
            ->and($action->getStatus()->name)->toBe('Success')
            ->and(bulkVisDeselects($action))->toBeTrue();
    });

    it('says files already private were left, as no failure', function (): void {
        $action = bulkVisAction('makeSelectedPrivate');

        MediaVisibilityActions::privateEach($action, bulkVisListed(bulkVisStored('A')));

        expect(bulkVisNotices()[0]['status'])->toBe('info')
            ->and(bulkVisNotices()[0]['title'])->toBe('The file was already private. Nothing was changed.')
            ->and($action->getStatus()->name)->toBe('Success');
    });
});

/* The private twin: a file already private is not counted so where the switch refused it (review of decision 34). */
it('is refused file by file for whoever may not publish, made private too', function (): void {
    ($this->grant)('view', 'update');
    $public = bulkVisStored('Pub', visibility: 'public');
    $private = bulkVisStored('Priv');
    $action = bulkVisAction('makeSelectedPrivate');

    MediaVisibilityActions::privateEach($action, bulkVisListed($public, $private));

    $lines = explode('<br>', (string) bulkVisNotices()[0]['body']);

    expect([bulkVisRow($public), bulkVisRow($private)])->toBe(['public', 'private'])
        ->and(bulkVisNotices()[0]['title'])->toBe('2 files were not made private')
        ->and($lines)->toHaveCount(2)
        ->and($lines[0])->toContain('that needs [entry.image.publish]')
        ->and($lines[1])->toContain('that needs [entry.image.publish]')
        ->and((string) bulkVisNotices()[0]['body'])->not->toContain('in the trash')
        ->and($action->getStatus()->name)->toBe('Failure');
});

/* ⚠️ IN THE TRASH WITH NO FILE: said so, not that a restore publishes it (review of decision 34). */
it('says a trashed entry with no file has none, made private', function (): void {
    ($this->grant)('view', 'publish');
    $orphan = bulkVisStored('Orphan');
    DB::table('media_files')->where('entry_id', $orphan->id)->delete();
    $orphan->delete();
    $action = bulkVisAction('makeSelectedPrivate');

    MediaVisibilityActions::privateEach($action, bulkVisListed($orphan));

    expect(bulkVisNotices()[0]['title'])->toBe('One file was not made private')
        ->and(bulkVisNotices()[0]['body'])->toBe("&quot;Orphan&quot; was not made private: Refusing to make entry {$orphan->id} private: no file is recorded for it, so nothing was changed.");
});

/* In the trash, made private, and its row not readable again: named with the failures, reported once (review of decision 34). */
it('claims nothing of a trashed file it cannot read again, made private', function (): void {
    ($this->grant)('view', 'publish');
    Exceptions::fake();
    $old = bulkVisStored('Old', visibility: 'public');
    $old->delete();
    $records = bulkVisListed($old);
    $armed = false;
    DB::listen(static function ($query) use (&$armed): void {
        // Armed by the switch's own guard reading the entry, so its file is read once before, as custody locks it.
        if (preg_match('/^select [`"]?org_id[`"]?, [`"]?site_id/', $query->sql) === 1) {
            $armed = true;
        } elseif ($armed && preg_match('/from [`"]?media_files[`"]?/', $query->sql) === 1) {
            throw new RuntimeException('unreadable');
        }
    });

    MediaVisibilityActions::privateEach(bulkVisAction('makeSelectedPrivate'), $records);

    expect(bulkVisNotices()[0]['title'])->toBe('One file was not made private')
        ->and(bulkVisNotices()[0]['body'])->toStartWith('&quot;Old&quot; may not have been made private');

    Exceptions::assertReportedCount(1);
});

describe('the parts', function (): void {
    /* H12. Half PHP's limit, at most fifteen seconds, and fifteen where PHP sets none. */
    it('budgets half the limit, at most fifteen seconds, and fifteen with none', function (int $limit, float $budget): void {
        expect(MediaVisibilityActions::budgetSeconds($limit))->toBe($budget);
    })->with([
        'no limit' => [0, 15.0],
        'a negative one' => [-1, 15.0],
        'one second' => [1, 0.5],
        'twenty' => [20, 10.0],
        'thirty' => [30, 15.0],
        'an hour' => [3600, 15.0],
    ]);

    /* H13. The deadline is the budget from the handler's start. */
    it('sets the deadline the budget from now', function (): void {
        $this->freezeTime();
        $limit = ini_get('max_execution_time');

        try {
            ini_set('max_execution_time', '20');
            expect(MediaVisibilityActions::deadline()->equalTo(now()->addSeconds(10)))->toBeTrue();

            ini_set('max_execution_time', '0');
            expect(MediaVisibilityActions::deadline()->equalTo(now()->addSeconds(15)))->toBeTrue();
        } finally {
            ini_set('max_execution_time', (string) $limit);
        }
    });

    /* H18. The refusals an editor reads, as each page's handler catches them — and nothing else. */
    it('reads as refusals exactly what each page\'s handler catches', function (Throwable $failure, bool $public, bool $private): void {
        expect(MediaVisibilityActions::refusedIn($failure, 'public'))->toBe($public)
            ->and(MediaVisibilityActions::refusedIn($failure, 'private'))->toBe($private);
    })->with([
        'a strip refused' => [new MediaRefused('x'), true, false],
        'a switch refused' => [new MediaVisibilityRefused(1, MediaVisibilityRefused::TRASHED, 'public'), true, true],
        'a withdrawal refused' => [new MediaWithdrawalRefused(1, MediaWithdrawalRefused::NOWHERE, 'public', 'make private'), false, true],
        'anything else' => [new RuntimeException('x'), false, false],
        'a transaction refused' => [new LogicException('x'), false, false],
        'the database' => [new PDOException('x'), false, false],
    ]);
});
