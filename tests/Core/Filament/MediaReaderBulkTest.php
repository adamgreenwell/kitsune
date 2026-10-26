<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\Enums\ActionStatus;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Filament\MediaReaderBulk;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;
use Kitsune\Core\Filament\Resources\Entries\Pages\ListEntries;
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
use Kitsune\Core\Tests\Fixtures\LocatedJpeg;
use Kitsune\Core\Tests\Fixtures\PanelTenancy;
use Kitsune\Core\Tests\Fixtures\RefusingDisk;
use Kitsune\Core\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    config(['auth.providers.users.model' => TestUser::class]);
    $this->roots = [];
    foreach (['public', MediaDisks::PRIVATE] as $name) {
        $root = sys_get_temp_dir().'/kitsune-readerbulk-'.$name.'-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        $this->roots[] = $root;
        RefusingDisk::install($name, $root);
    }
    $this->org = Org::create(['slug' => 'reader-bulk', 'name' => 'Reader bulk']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'reader-bulk-main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);
    $this->type = EntryType::create(['org_id' => $this->org->id, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);
    app()->instance(EntryType::class, $this->type);
    $user = TestUser::create(['email' => 'reader-bulk@kitsune.test']);
    DB::table('org_user')->insert(['org_id' => $this->org->id, 'user_id' => $user->getKey()]);
    $this->role = Role::create(['handle' => 'reader-bulk', 'name' => 'Reader bulk']);
    DB::table('role_user')->insert(['role_id' => $this->role->id, 'user_id' => $user->getKey()]);
    $this->grant = function (string ...$actions): void {
        foreach ($actions as $action) {
            $this->role->grant(Permissions::forEntryType('image', $action));
        }
    };
    $this->actingAs($user);
    PanelTenancy::enter($this->site);
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

function bulkReaderStored(string $title, string $visibility = 'private', bool $siteOnly = true): Entry
{
    $bytes = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $source = LocatedJpeg::file($bytes, 'kitsune-readerbulk-');
    $user = auth()->user();
    auth()->forgetUser();
    try {
        return MediaLibrary::store($source, 'report.png', test()->type, $visibility, title: $title, siteOnly: $siteOnly);
    } finally {
        unlink($source);
        if ($user !== null) {
            test()->actingAs($user);
        }
    }
}

function bulkReaderAction(): BulkAction
{
    $table = EntryResource::table(Table::make(app(ListEntries::class)));
    $actions = collect($table->getToolbarActions()[0]->getActions())->keyBy(fn ($action) => $action->getName());
    expect($actions->has('offerSelectedToReaders'))->toBeTrue('The media list must offer the selected files.');
    $action = $actions->get('offerSelectedToReaders');
    assert($action instanceof BulkAction);

    return $action;
}

function bulkReaderName(Entry $entry): mixed
{
    return DB::table('media_files')->where('entry_id', $entry->id)->value('reader_entitlement');
}

/** @return array{entitlement: string, confirmation: string} */
function bulkReaderConfirmed(string $name = 'course.selected'): array
{
    return ['entitlement' => $name, 'confirmation' => $name];
}

it('keeps the existing single-file writer as a working control', function (): void {
    ($this->grant)('view', 'publish');
    $entry = bulkReaderStored('Control');
    expect(MediaVisibility::offer($entry, 'course.selected'))->toBe(MediaVisibility::SWITCHED)
        ->and(bulkReaderName($entry))->toBe('course.selected');
});

it('offers the bounded selection through the media list', function (): void {
    ($this->grant)('view', 'publish');
    expect(bulkReaderAction()->getLabel())->toBe('Offer selected to readers');
});

it('keeps successes and reports each refusal without claiming the selection all succeeded', function (): void {
    ($this->grant)('view', 'publish');
    $action = bulkReaderAction();
    $one = bulkReaderStored('<b>Alpha</b>');
    $public = bulkReaderStored('Public', 'public');
    $two = bulkReaderStored('Bravo');
    MediaReaderBulk::offerEach($action, [$one, $public, $two], bulkReaderConfirmed());
    $notices = array_values((array) session('filament.notifications', []));
    expect(bulkReaderName($one))->toBe('course.selected')
        ->and(bulkReaderName($two))->toBe('course.selected')
        ->and(bulkReaderName($public))->toBeNull()
        ->and(DB::table('audit_log')->where('action', MediaVisibility::OFFERED)->count())->toBe(2)
        ->and($action->getStatus())->toBe(ActionStatus::Failure)
        ->and($action->shouldDeselectRecordsAfterCompletion())->toBeFalse()
        ->and($notices)->toHaveCount(1)
        ->and((string) $notices[0]['body'])->toContain('&lt;b&gt;Alpha&lt;/b&gt;', 'Bravo', 'Public')
        ->not->toContain('<b>Alpha</b>');
});

it('refuses a mismatched name confirmation before any writer runs', function (): void {
    ($this->grant)('view', 'publish');
    $action = bulkReaderAction();
    $entry = bulkReaderStored('Report');
    MediaReaderBulk::offerEach($action, [$entry], ['entitlement' => 'course.selected', 'confirmation' => 'course.other']);
    expect(bulkReaderName($entry))->toBeNull()
        ->and(DB::table('audit_log')->where('action', MediaVisibility::OFFERED)->count())->toBe(0)
        ->and($action->getStatus())->toBe(ActionStatus::Failure);
});

it('requires publish permission again when the selection is submitted', function (): void {
    ($this->grant)('view', 'update');
    $action = bulkReaderAction();
    $entry = bulkReaderStored('Private');
    MediaReaderBulk::offerEach($action, [$entry], bulkReaderConfirmed());
    expect(bulkReaderName($entry))->toBeNull()
        ->and($action->getStatus())->toBe(ActionStatus::Failure);
});

it('keeps a repeated same-name offer idempotent and clears a completed selection', function (): void {
    ($this->grant)('view', 'publish');
    $entry = bulkReaderStored('Repeat');
    MediaVisibility::offer($entry, 'course.selected');
    $before = DB::table('audit_log')->where('action', MediaVisibility::OFFERED)->count();
    $action = bulkReaderAction();
    MediaReaderBulk::offerEach($action, [$entry], bulkReaderConfirmed());
    expect(bulkReaderName($entry))->toBe('course.selected')
        ->and(DB::table('audit_log')->where('action', MediaVisibility::OFFERED)->count())->toBe($before)
        ->and($action->getStatus())->toBe(ActionStatus::Success)
        ->and($action->shouldDeselectRecordsAfterCompletion())->toBeTrue();
});

it('does not expose the form to users who cannot publish', function (array $grants, bool $authorized, bool $disabled): void {
    ($this->grant)(...$grants);
    $action = bulkReaderAction();
    expect($action->isAuthorized())->toBe($authorized)
        ->and($action->isDisabled())->toBe($disabled)
        ->and($action->getSchema(Schema::make(app(ListEntries::class))) === null)->toBe($disabled);
})->with([
    'viewer' => [['view'], false, true],
    'editor' => [['view', 'update'], true, true],
    'publisher' => [['view', 'publish'], true, false],
]);

it('requires the exact repeated name and opts out of a host enclosing transaction', function (): void {
    ($this->grant)('view', 'publish');
    Action::configureUsing(fn (Action $action) => $action->databaseTransaction()->successNotificationTitle('Host success')->failureNotificationTitle('Host failure'));
    $action = bulkReaderAction();
    $fields = $action->getSchema(Schema::make(app(ListEntries::class)))?->getFlatComponents(withHidden: true) ?? [];
    $inputs = collect($fields)->filter(fn ($field) => $field instanceof TextInput)->keyBy(fn ($field) => $field->getName());
    expect($inputs->keys()->all())->toBe(['entitlement', 'confirmation'])
        ->and($inputs['confirmation']->getValidationRules())->toContain('required', 'same:entitlement')
        ->and($action->hasDatabaseTransactions())->toBeFalse()
        ->and((fn (): bool => $this->isSuccessNotificationDisabled)->call($action))->toBeTrue()
        ->and((fn (): bool => $this->isFailureNotificationDisabled)->call($action))->toBeTrue()
        ->and((string) $action->getModalDescription())->toContain('replaces any different name', 'at most 50', 'as it is stored');
});

it('retags only after the new exact name is confirmed and audits the change', function (): void {
    ($this->grant)('view', 'publish');
    $entry = bulkReaderStored('Retag');
    MediaVisibility::offer($entry, 'course.previous');
    $action = bulkReaderAction();
    MediaReaderBulk::offerEach($action, [$entry], ['entitlement' => 'course.selected', 'confirmation' => 'course.previous']);
    expect(bulkReaderName($entry))->toBe('course.previous');
    MediaReaderBulk::offerEach($action, [$entry], bulkReaderConfirmed());
    expect(bulkReaderName($entry))->toBe('course.selected')
        ->and(DB::table('audit_log')->where('action', MediaVisibility::OFFER_CHANGED)->count())->toBe(1);
});

it('refuses public shared trashed and missing files while preserving the eligible offer', function (): void {
    ($this->grant)('view', 'publish', 'delete');
    $action = bulkReaderAction();
    $good = bulkReaderStored('Good');
    $public = bulkReaderStored('Public', 'public');
    $shared = bulkReaderStored('Shared', siteOnly: false);
    $trashed = bulkReaderStored('Trashed');
    $trashed->delete();
    $missing = bulkReaderStored('Missing');
    DB::table('media_files')->where('entry_id', $missing->id)->delete();
    MediaReaderBulk::offerEach($action, [$good, $public, $shared, $trashed, $missing], bulkReaderConfirmed());
    expect(bulkReaderName($good))->toBe('course.selected')
        ->and(bulkReaderName($public))->toBeNull()
        ->and(bulkReaderName($shared))->toBeNull()
        ->and(bulkReaderName($trashed))->toBeNull()
        ->and(DB::table('audit_log')->where('action', MediaVisibility::OFFERED)->count())->toBe(1)
        ->and($action->getStatus())->toBe(ActionStatus::Failure);
});

it('reloads a forged sibling-site selection and exposes neither its title nor the forged title', function (): void {
    ($this->grant)('view', 'publish');
    $action = bulkReaderAction();
    $good = bulkReaderStored('Good');
    $other = Site::create(['handle' => 'other', 'slug' => 'reader-bulk-other', 'name' => 'Other', 'locale' => 'en']);
    PanelTenancy::enter($other);
    $sibling = bulkReaderStored('SIBLING SECRET TITLE');
    PanelTenancy::enter($this->site);
    $forged = clone $sibling;
    $forged->forceFill(['org_id' => $this->org->id, 'site_id' => $this->site->id, 'type_handle' => 'image', 'title' => 'FORGED TITLE']);
    MediaReaderBulk::offerEach($action, [$good, $forged], bulkReaderConfirmed());
    expect(bulkReaderName($good))->toBe('course.selected')
        ->and(bulkReaderName($sibling))->toBeNull()
        ->and(json_encode(session('filament.notifications')))->not->toContain('SIBLING SECRET TITLE', 'FORGED TITLE')
        ->and($action->getStatus())->toBe(ActionStatus::Failure);
});

it('refuses a forged other-org selection with no foreign title in its feedback', function (): void {
    ($this->grant)('view', 'publish');
    $action = bulkReaderAction();
    $ownType = $this->type;
    $org = Org::create(['slug' => 'reader-bulk-rival', 'name' => 'Rival']);
    app(Context::class)->setOrg($org);
    $site = Site::create(['handle' => 'rival', 'slug' => 'reader-bulk-rival', 'name' => 'Rival', 'locale' => 'en']);
    PanelTenancy::enter($site);
    $this->type = EntryType::create(['org_id' => $org->id, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);
    app()->instance(EntryType::class, $this->type);
    $foreign = bulkReaderStored('RIVAL SECRET TITLE');
    $this->type = $ownType;
    app()->instance(EntryType::class, $ownType);
    PanelTenancy::enter($this->site);
    $forged = clone $foreign;
    $forged->forceFill(['org_id' => $this->org->id, 'site_id' => $this->site->id, 'type_handle' => 'image', 'title' => 'FORGED TITLE']);
    MediaReaderBulk::offerEach($action, [$forged], bulkReaderConfirmed());
    expect(bulkReaderName($foreign))->toBeNull()
        ->and(json_encode(session('filament.notifications')))->not->toContain('RIVAL SECRET TITLE', 'FORGED TITLE')
        ->and($action->getStatus())->toBe(ActionStatus::Failure);
});

it('fences the list type even when the user can publish the forged record type too', function (): void {
    ($this->grant)('view', 'publish');
    $this->role->grant('entry.attachment.view');
    $this->role->grant('entry.attachment.publish');
    $action = bulkReaderAction();
    $ownType = $this->type;
    $this->type = EntryType::create(['org_id' => $this->org->id, 'handle' => 'attachment', 'name' => 'Attachment', 'plural_name' => 'Attachments', 'is_media' => true]);
    $other = bulkReaderStored('OTHER TYPE SECRET');
    $this->type = $ownType;
    $forged = clone $other;
    $forged->forceFill(['type_handle' => 'image', 'title' => 'FORGED TITLE']);
    MediaReaderBulk::offerEach($action, [$forged], bulkReaderConfirmed());
    expect(bulkReaderName($other))->toBeNull()
        ->and(json_encode(session('filament.notifications')))->not->toContain('OTHER TYPE SECRET', 'FORGED TITLE')
        ->and($action->getStatus())->toBe(ActionStatus::Failure);
});

it('refuses with missing scope or identity rather than using the writer system bypass', function (string $missing): void {
    ($this->grant)('view', 'publish');
    $action = bulkReaderAction();
    $entry = bulkReaderStored('PRIVATE TITLE');
    if ($missing === 'scope') {
        app(Context::class)->forget();
    } else {
        auth()->forgetUser();
    }
    MediaReaderBulk::offerEach($action, [$entry], bulkReaderConfirmed());
    expect(bulkReaderName($entry))->toBeNull()
        ->and(json_encode(session('filament.notifications')))->not->toContain('PRIVATE TITLE')
        ->and($action->getStatus())->toBe(ActionStatus::Failure);
})->with(['scope', 'identity']);

it('rechecks a publish grant revoked between two selected files', function (): void {
    ($this->grant)('view', 'publish');
    $action = bulkReaderAction();
    $one = bulkReaderStored('First');
    $two = bulkReaderStored('Second');
    $revoked = false;
    AuditorStandIn::install()->beforeRecording(function () use (&$revoked): void {
        if (! $revoked) {
            $revoked = true;
            $this->role->revoke('entry.image.publish');
        }
    });
    MediaReaderBulk::offerEach($action, [$one, $two], bulkReaderConfirmed());
    expect(bulkReaderName($one))->toBe('course.selected')
        ->and(bulkReaderName($two))->toBeNull()
        ->and(DB::table('audit_log')->where('action', MediaVisibility::OFFERED)->count())->toBe(1)
        ->and($action->getStatus())->toBe(ActionStatus::Failure);
});

it('refuses an oversized selection as a whole rather than truncating it', function (?int $keys): void {
    ($this->grant)('view', 'publish');
    $action = bulkReaderAction();
    $files = array_map(fn (int $i) => bulkReaderStored('File '.$i), range(1, 51));
    $query = Entry::query()->whereKey(array_map(fn (Entry $entry) => $entry->id, $files))->orderBy('id');
    MediaReaderBulk::selected($action, $query, bulkReaderConfirmed(), $keys);
    expect(DB::table('media_files')->whereNotNull('reader_entitlement')->count())->toBe(0)
        ->and(DB::table('audit_log')->where('action', MediaVisibility::OFFERED)->count())->toBe(0)
        ->and($action->getStatus())->toBe(ActionStatus::Failure);
})->with(['query selection' => [null], 'explicit keys' => [51]]);

it('accepts fifty files without raising the existing selection bound', function (): void {
    ($this->grant)('view', 'publish');
    $action = bulkReaderAction();
    $files = array_map(fn (int $i) => bulkReaderStored('File '.$i), range(1, 50));
    MediaReaderBulk::offerEach($action, $files, bulkReaderConfirmed());
    expect(DB::table('media_files')->where('reader_entitlement', 'course.selected')->count())->toBe(50)
        ->and(DB::table('audit_log')->where('action', MediaVisibility::OFFERED)->count())->toBe(50)
        ->and($action->getStatus())->toBe(ActionStatus::Success);
});

it('keeps unavailable keys visible as a generic refusal and preserves the selection', function (): void {
    ($this->grant)('view', 'publish');
    $action = bulkReaderAction();
    $one = bulkReaderStored('One');
    MediaReaderBulk::selected($action, Entry::query()->whereKey($one->id), bulkReaderConfirmed(), 2);
    $notice = array_values((array) session('filament.notifications', []))[0];
    expect(bulkReaderName($one))->toBe('course.selected')
        ->and($action->getStatus())->toBe(ActionStatus::Failure)
        ->and((string) $notice['body'])->toContain('Unavailable selections: 1');
});

it('stops starting files at the deadline and can continue idempotently on another run', function (): void {
    ($this->grant)('view', 'publish');
    $this->freezeTime();
    $files = [bulkReaderStored('One'), bulkReaderStored('Two'), bulkReaderStored('Three')];
    AuditorStandIn::install()->beforeRecording(fn () => $this->travel(16)->seconds());
    $first = bulkReaderAction();
    MediaReaderBulk::offerEach($first, $files, bulkReaderConfirmed(), now()->addSeconds(15));
    expect(array_map('bulkReaderName', $files))->toBe(['course.selected', null, null])
        ->and($first->getStatus())->toBe(ActionStatus::Failure);
    $next = bulkReaderAction();
    MediaReaderBulk::offerEach($next, $files, bulkReaderConfirmed(), now()->addSeconds(15));
    expect(array_map('bulkReaderName', $files))->toBe(['course.selected', 'course.selected', null]);
});

it('starts the deadline before fetching a slow selection and still tries its first file', function (): void {
    ($this->grant)('view', 'publish');
    $this->freezeTime();
    $files = [bulkReaderStored('One'), bulkReaderStored('Two')];
    $query = Entry::query()->whereKey(array_map(fn (Entry $entry) => $entry->id, $files))->orderBy('id');
    $travelled = false;
    Entry::retrieved(function () use (&$travelled): void {
        if (! $travelled) {
            $travelled = true;
            $this->travel(16)->seconds();
        }
    });
    MediaReaderBulk::selected(bulkReaderAction(), $query, bulkReaderConfirmed());
    expect(array_map('bulkReaderName', $files))->toBe(['course.selected', null]);
});
