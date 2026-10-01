<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Filament\MediaVisibilityActions;
use Kitsune\Core\Filament\Resources\Entries\Pages\EditEntry;
use Kitsune\Core\Filament\Resources\Entries\Pages\ViewEntry;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\LocatedJpeg;
use Kitsune\Core\Tests\Fixtures\PanelTenancy;
use Kitsune\Core\Tests\Fixtures\RefusingDisk;
use Kitsune\Core\Tests\Fixtures\TestUser;

/*
 * *Make public* and *Make private* on a media entry's pages — Adam, ADR-042 decision 32.
 *
 * ⚠️ WHO SEES WHAT, AND WHAT EACH OUTCOME SAYS. The switch itself is `MediaVisibilityTest`'s; here, the actions a user is
 * shown for a record, the acknowledgement they ask for, and the notification each outcome sends — read back from the
 * session as the page would show it, and from the row, so a notice that claims what did not happen cannot pass.
 */

beforeEach(function (): void {
    config(['auth.providers.users.model' => TestUser::class]);
    $this->roots = [];

    foreach (['public', MediaDisks::PRIVATE] as $name) {
        $root = sys_get_temp_dir().'/kitsune-visactions-'.$name.'-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        $this->roots[] = $root;
        $this->disks[$name] = RefusingDisk::install($name, $root);
    }

    $this->org = Org::create(['slug' => 'switches', 'name' => 'Switches']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'switches-main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);
    $this->type = EntryType::create(['org_id' => $this->org->id, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);
    app()->instance(EntryType::class, $this->type);

    /** @var TestUser $user */
    $user = TestUser::create(['email' => 'switcher@kitsune.test']);
    DB::table('org_user')->insert(['org_id' => $this->org->getKey(), 'user_id' => $user->getKey()]);
    $this->role = Role::create(['handle' => 'switcher', 'name' => 'Switcher']);
    DB::table('role_user')->insert(['role_id' => $this->role->getKey(), 'user_id' => $user->getKey()]);
    $this->grant = function (string ...$actions): void {
        foreach ($actions as $action) {
            $this->role->grant(Permissions::forEntryType('image', $action));
        }
    };
    $this->actingAs($user);
    PanelTenancy::enter($this->site);

    session()->forget('filament.notifications');
});

afterEach(function (): void {
    app(Context::class)->forget();

    foreach ($this->roots as $root) {
        exec('rm -rf '.escapeshellarg($root));
    }
});

/** A stored file, titled. */
function switchable(string $title, string $bytes = '', string $visibility = 'private', bool $siteOnly = true, string $name = 'logo.png'): Entry
{
    $bytes = $bytes === '' ? (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==') : $bytes;
    $source = LocatedJpeg::file($bytes, 'kitsune-visactions-');
    // Stored by the system, whatever the acting user may do: a media entry is created published.
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
function switchNotices(): array
{
    return array_values((array) session('filament.notifications', []));
}

function switchVisibility(Entry $entry): string
{
    return (string) DB::table('media_files')->where('entry_id', $entry->id)->value('visibility');
}

/** The action, for this record, as its page builds it. */
function switchAction(Action $action, Entry $record): Action
{
    return $action->record($record);
}

/* F1. A media type's pages have them; any other type's have none. */
it('offers both actions on a media type\'s pages, and none on any other', function (): void {
    expect(array_map(static fn (Action $action): string => $action->getName(), MediaVisibilityActions::all()))->toBe(['makePublic', 'makePrivate']);

    app()->instance(EntryType::class, EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']));

    expect(MediaVisibilityActions::all())->toBe([]);
});

/* F2. Make public on a private file, Make private on a public one, neither on a trashed one. */
it('offers the switch the file does not have, and neither in the trash', function (): void {
    ($this->grant)('view', 'update', 'publish');
    $private = switchable('Private');
    $public = switchable('Public', visibility: 'public');
    $trashed = switchable('Trashed', visibility: 'public');
    $trashed->delete();
    $trashed = Entry::withTrashed()->findOrFail($trashed->id);
    // A private one too: hidden for being in the trash, not for being private (review of decision 32).
    $trashedPrivate = switchable('Trashed private');
    $trashedPrivate->delete();
    $trashedPrivate = Entry::withTrashed()->findOrFail($trashedPrivate->id);

    expect(switchAction(MediaVisibilityActions::makePublic(), $private)->isVisible())->toBeTrue()
        ->and(switchAction(MediaVisibilityActions::makePrivate(), $private)->isVisible())->toBeFalse()
        ->and(switchAction(MediaVisibilityActions::makePublic(), $public)->isVisible())->toBeFalse()
        ->and(switchAction(MediaVisibilityActions::makePrivate(), $public)->isVisible())->toBeTrue()
        ->and(switchAction(MediaVisibilityActions::makePublic(), $trashed)->isVisible())->toBeFalse()
        ->and(switchAction(MediaVisibilityActions::makePrivate(), $trashed)->isVisible())->toBeFalse()
        ->and(switchAction(MediaVisibilityActions::makePublic(), $trashedPrivate)->isVisible())->toBeFalse()
        ->and(switchAction(MediaVisibilityActions::makePrivate(), $trashedPrivate)->isVisible())->toBeFalse();
});

/* F3. Hidden from a reader; disabled, naming the permission, with no field, for an editor who may not publish. */
it('is hidden from a reader, and disabled with no field for whoever may only update', function (array $grants, bool $hidden, bool $disabled): void {
    ($this->grant)(...$grants);
    $entry = switchable('Logo');
    $public = switchable('Public', visibility: 'public');

    // Each on a file it applies to: Filament reads a hidden action as disabled too.
    foreach ([[MediaVisibilityActions::makePublic(), $entry], [MediaVisibilityActions::makePrivate(), $public]] as [$action, $record]) {
        $action = switchAction($action, $record);

        expect($action->isAuthorized())->toBe(! $hidden)
            ->and($action->isDisabled())->toBe($disabled);

        if ($disabled) {
            expect($action->getTooltip())->toContain('entry.image.publish');
        }
    }

    $schema = switchAction(MediaVisibilityActions::makePublic(), $entry)->getSchema(Schema::make(app(ViewEntry::class)));

    expect($schema === null)->toBe($hidden || $disabled);
})->with([
    'view alone' => [['view'], true, true],
    'update' => [['view', 'update'], false, true],
    'publish' => [['view', 'publish'], false, false],
]);

/* F4, F5. Decision 15's acknowledgement, in Upload's words, required — and no transaction around either. */
it('asks Upload\'s acknowledgement as a required tick, and runs outside any transaction', function (): void {
    ($this->grant)('view', 'publish');
    $entry = switchable('Logo');
    Action::configureUsing(static fn (Action $action) => $action->databaseTransaction());

    $public = switchAction(MediaVisibilityActions::makePublic(), $entry);
    $components = $public->getSchema(Schema::make(app(ViewEntry::class)))?->getFlatComponents(withHidden: true) ?? [];
    $checkbox = collect($components)->first(static fn ($component): bool => $component instanceof Checkbox);

    $helper = null;

    foreach ($checkbox?->getChildSchema(Field::BELOW_CONTENT_SCHEMA_KEY)?->getComponents() ?? [] as $component) {
        $helper = $component instanceof Text ? (string) $component->getContent() : $helper;
    }

    expect($checkbox)->toBeInstanceOf(Checkbox::class)
        ->and($checkbox->getName())->toBe('public_confirmed')
        ->and($helper)->toBe(__('kitsune::media.visibility.public_warning'))
        ->and($checkbox->getValidationRules())->toContain('accepted')
        ->and($public->hasDatabaseTransactions())->toBeFalse()
        ->and(switchAction(MediaVisibilityActions::makePrivate(), $entry)->hasDatabaseTransactions())->toBeFalse();
});

/* F9. A shared file's modal says the change is for every site; a site's own file's does not. */
it('says a shared file changes for every site, and a site\'s own file does not', function (): void {
    ($this->grant)('view', 'publish');
    $shared = switchable('Shared', siteOnly: false);
    $own = switchable('Own');
    $sharedPublic = switchable('Shared public', visibility: 'public', siteOnly: false);

    expect(switchAction(MediaVisibilityActions::makePublic(), $shared)->getModalDescription())->toBe(__('kitsune::media.visibility.shared_public'))
        ->and(switchAction(MediaVisibilityActions::makePublic(), $own)->getModalDescription())->toBeNull()
        ->and(switchAction(MediaVisibilityActions::makePrivate(), $sharedPublic)->getModalDescription())
        ->toBe(__('kitsune::media.visibility.private_warning').' '.__('kitsune::media.visibility.shared_private'));
});

describe('making a file public', function (): void {
    beforeEach(fn () => ($this->grant)('view', 'publish'));

    /* F6. Unticked changes nothing; ticked makes it public and says so. */
    it('changes nothing unticked, and makes the file public ticked', function (): void {
        $entry = switchable('Logo');

        MediaVisibilityActions::publicOne($entry, ['public_confirmed' => false]);

        expect(switchVisibility($entry))->toBe('private')
            ->and(switchNotices()[0]['status'])->toBe('danger')
            ->and(switchNotices()[0]['body'])->toBe(e(__('kitsune::media.visibility.not_confirmed')));

        session()->forget('filament.notifications');
        MediaVisibilityActions::publicOne($entry, ['public_confirmed' => true]);

        expect(switchVisibility($entry))->toBe('public')
            ->and(switchNotices())->toHaveCount(1)
            ->and(switchNotices()[0]['status'])->toBe('success')
            ->and(switchNotices()[0]['title'])->toBe('&quot;Logo&quot; is public');
    });

    it('says a file already public was left as it was', function (): void {
        $entry = switchable('Logo', visibility: 'public');

        MediaVisibilityActions::publicOne($entry, ['public_confirmed' => true]);

        expect(switchNotices()[0]['status'])->toBe('info')
            ->and(switchNotices()[0]['title'])->toBe('&quot;Logo&quot; was already public. Nothing was changed.');
    });

    /* Committed, and its publication failed after the commit: said so, never claimed done. */
    it('says a file made public is not yet published when its publication fails', function (): void {
        $entry = switchable('Logo');
        $this->disks['public']->failWrites = true;

        MediaVisibilityActions::publicOne($entry, ['public_confirmed' => true]);

        expect(switchVisibility($entry))->toBe('public')
            ->and(switchNotices()[0]['status'])->toBe('warning')
            ->and(switchNotices()[0]['title'])->toBe('&quot;Logo&quot; is public, and not yet published')
            ->and(switchNotices()[0]['body'])->toContain("kitsune:media-reconcile --entry={$entry->id} --force");
    });

    /* F7. A refusal is a notification naming the entry, escaped — the strip's own among them. */
    it('shows a refusal as a notification, escaped', function (): void {
        $entry = switchable('<i>Scorecard</i>', LocatedJpeg::unremovable(), name: 'shared.jpg');

        MediaVisibilityActions::publicOne($entry, ['public_confirmed' => true]);

        expect(switchVisibility($entry))->toBe('private')
            ->and(switchNotices()[0]['status'])->toBe('danger')
            ->and(switchNotices()[0]['title'])->toBe('&quot;&lt;i&gt;Scorecard&lt;/i&gt;&quot; was not made public')
            ->and(switchNotices()[0]['body'])->toContain('cannot be removed with certainty')->toContain('[&lt;i&gt;Scorecard&lt;/i&gt;]');
    });
});

describe('making a file private', function (): void {
    beforeEach(fn () => ($this->grant)('view', 'publish'));

    /* F8. Success says the link stops opening it; a withdrawal refused is a notification in the trash's words. */
    it('makes a public file private, and says what that does', function (): void {
        $entry = switchable('Logo', visibility: 'public');

        MediaVisibilityActions::privateOne($entry);

        expect(switchVisibility($entry))->toBe('private')
            ->and(switchNotices()[0]['status'])->toBe('success')
            ->and(switchNotices()[0]['title'])->toBe('&quot;Logo&quot; is private')
            ->and(switchNotices()[0]['body'])->toBe(e(__('kitsune::media.visibility.made_private_body')));
    });

    it('shows a withdrawal refused as a notification, escaped, and leaves the file public', function (): void {
        $entry = switchable('<i>Scorecard</i>', visibility: 'public');
        $this->disks['public']->failDeletes = true;

        MediaVisibilityActions::privateOne($entry);

        expect(switchVisibility($entry))->toBe('public')
            ->and(switchNotices()[0]['status'])->toBe('danger')
            ->and(switchNotices()[0]['title'])->toBe('&quot;&lt;i&gt;Scorecard&lt;/i&gt;&quot; was not made private')
            ->and(switchNotices()[0]['body'])->toStartWith("Refusing to make entry {$entry->id} private: its file could not be withdrawn");
    });

    it('lets any other failure through, for Filament to report as its own', function (): void {
        $entry = switchable('Logo', visibility: 'public');
        app(Context::class)->forget();

        expect(fn () => MediaVisibilityActions::privateOne($entry))->toThrow(RuntimeException::class, 'no organisation in context');
        expect(switchNotices())->toBe([]);
    });

    it('says a file already private was left as it was', function (): void {
        $entry = switchable('Logo');

        MediaVisibilityActions::privateOne($entry);

        expect(switchNotices()[0]['status'])->toBe('info')
            ->and(switchNotices()[0]['title'])->toBe('&quot;Logo&quot; was already private. Nothing was changed.');
    });
});

/* The pages carry them: View beside Edit, Edit beside Delete. */
it('puts both actions on a media entry\'s View and Edit pages', function (): void {
    $names = static function (string $page): array {
        $method = new ReflectionMethod($page, 'getHeaderActions');

        return array_map(static fn ($action): string => $action->getName(), $method->invoke(app($page)));
    };

    expect($names(ViewEntry::class))->toBe(['makePublic', 'makePrivate', 'edit'])
        ->and($names(EditEntry::class))->toBe(['makePublic', 'makePrivate', 'delete']);
});
