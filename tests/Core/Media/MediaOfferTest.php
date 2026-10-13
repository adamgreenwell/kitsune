<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Js;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Filament\MediaReaderActions;
use Kitsune\Core\Filament\MediaVisibilityActions;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;
use Kitsune\Core\Filament\Resources\Entries\Pages\EditEntry;
use Kitsune\Core\Filament\Resources\Entries\Pages\ViewEntry;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Media\MediaVisibility;
use Kitsune\Core\Media\MediaVisibilityRefused;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\MediaFile;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Settings\SettingsResolver;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\AuditorStandIn;
use Kitsune\Core\Tests\Fixtures\LocatedJpeg;
use Kitsune\Core\Tests\Fixtures\PanelTenancy;
use Kitsune\Core\Tests\Fixtures\RefusingDisk;
use Kitsune\Core\Tests\Fixtures\TestUser;

/*
 * A file offered to readers — ADR-041 decisions 1–4 (Adam, 2026-10-11): the writer (`MediaVisibility::offer()` and
 * `stopOffering()`), the actions that call it, and the File section's lines about it.
 *
 * ⚠️ FROM THE ROW, THE DISKS AND THE AUDIT LOG AS THEY ARE AFTERWARDS. Every refusal is asserted by what it left — the
 * file row, every byte on every disk, the number of audit rows — so a writer that reported a refusal and wrote anyway
 * cannot pass; and every notice is read back from the session as the page would show it.
 *
 * ⚠️ THE NAME IS IN NO AUDIT ROW (ADR-040, decision 1): asserted of every column of every row, not of the action alone.
 */

const OFFER_PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\x0f\0\0\x01\x01\0\x05\x18\xd8N\0\0\0\0IEND\xaeB`\x82";

const OFFER_NAME = 'course.advanced-php';

const OFFER_OTHER = 'bundle.everything-2026';

beforeEach(function (): void {
    config(['auth.providers.users.model' => TestUser::class]);
    $this->roots = [];
    $this->disks = [];

    foreach (['public', MediaDisks::PRIVATE] as $name) {
        $root = sys_get_temp_dir().'/kitsune-offer-'.$name.'-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        $this->roots[] = $root;
        $this->disks[$name] = RefusingDisk::install($name, $root);
    }

    $this->org = Org::create(['slug' => 'offers', 'name' => 'Offers']);
    app(Context::class)->setOrg($this->org);
    // An address of its own, so its reader link is absolute (`ReaderLinks::absoluteDownload()`).
    $this->site = Site::create(['handle' => 'main', 'slug' => 'offers-main', 'name' => 'Main', 'locale' => 'en', 'base_url' => 'https://offers.test']);
    app(Context::class)->setSite($this->site);
    $this->type = EntryType::create(['org_id' => $this->org->id, 'handle' => 'download', 'name' => 'Download', 'plural_name' => 'Downloads', 'is_media' => true]);
    app()->instance(EntryType::class, $this->type);
});

afterEach(function (): void {
    app(Context::class)->forget();

    foreach ($this->roots as $root) {
        exec('rm -rf '.escapeshellarg($root));
    }
});

/** A stored file, by the system — a media entry is created published — and the disk log emptied after it. */
function offerStored(string $title = 'Report', string $visibility = 'private', bool $siteOnly = true, string $bytes = OFFER_PNG, string $name = 'report.png'): Entry
{
    $source = LocatedJpeg::file($bytes, 'kitsune-offer-');
    $user = auth()->user();
    auth()->forgetUser();

    try {
        return MediaLibrary::store($source, $name, test()->type, $visibility, title: $title, siteOnly: $siteOnly);
    } finally {
        unlink($source);
        RefusingDisk::forgetLog();

        if ($user !== null) {
            test()->actingAs($user);
        }
    }
}

/** Offered by the system, for a test that starts from an offer. */
function offerAsSystem(Entry $entry, string $name = OFFER_NAME): void
{
    $user = auth()->user();
    auth()->forgetUser();

    try {
        MediaVisibility::offer($entry, $name);
    } finally {
        if ($user !== null) {
            test()->actingAs($user);
        }
    }
}

/** The file row, as the database holds it. @return array<string, mixed> */
function offerRow(Entry $entry): array
{
    return (array) DB::table('media_files')->where('entry_id', $entry->id)->first();
}

/** Every file under each disk's root, with its hash. @return array<string, string> */
function offerDisks(): array
{
    $all = [];

    foreach (test()->disks as $name => $disk) {
        $root = $disk->root();

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $all[$name.':'.substr($file->getPathname(), strlen($root) + 1)] = hash_file('sha256', $file->getPathname());
            }
        }
    }

    ksort($all);

    return $all;
}

/** The actions audited against the entry, oldest first. @return list<string> */
function offerAudits(Entry $entry): array
{
    return DB::table('audit_log')->where('target_type', Entry::class)->where('target_id', (string) $entry->id)
        ->orderBy('id')->pluck('action')->all();
}

function offerRefusedBy(Closure $write): MediaVisibilityRefused
{
    try {
        $write();
    } catch (MediaVisibilityRefused $refused) {
        return $refused;
    }

    throw new RuntimeException('The write was not refused.');
}

/** A user holding these actions on `download`, acting. */
function offerActingWith(string ...$actions): TestUser
{
    /** @var TestUser $user */
    $user = TestUser::create(['email' => 'offerer-'.bin2hex(random_bytes(3)).'@kitsune.test']);
    DB::table('org_user')->insert(['org_id' => test()->org->getKey(), 'user_id' => $user->getKey()]);
    $role = Role::create(['handle' => 'offerer-'.bin2hex(random_bytes(3)), 'name' => 'Offerer']);
    DB::table('role_user')->insert(['role_id' => $role->getKey(), 'user_id' => $user->getKey()]);

    foreach ($actions as $action) {
        $role->grant(str_contains($action, '.') ? $action : Permissions::forEntryType('download', $action));
    }

    test()->actingAs($user);
    Permissions::forget();

    return $user;
}

/** @return list<array<string, mixed>> */
function offerNotices(): array
{
    return array_values((array) session('filament.notifications', []));
}

/** A media entry's form, built as its edit page builds it, keyed by name — a note, which has none, by its key. @return array<string, Component> */
function offerSection(Entry $entry): array
{
    $found = [];

    foreach (EntryResource::form(Schema::make(app(EditEntry::class))->record($entry))->getFlatComponents(withHidden: true) as $component) {
        $key = method_exists($component, 'getName') ? $component->getName() : $component->getKey(isAbsolute: false);
        $found[$key ?? spl_object_id($component)] = $component;
    }

    return $found;
}

/** The File section's reader lines that show, by name, with what each says. @return array<string, mixed> */
function offerLines(Entry $entry): array
{
    $lines = [];

    foreach (offerSection($entry) as $name => $component) {
        // The link and the notes; the Readers row shows for every file, and is asserted on its own.
        if (is_string($name) && str_starts_with($name, 'media_file_reader_') && ! $component->isHidden()) {
            $lines[$name] = $component instanceof Text ? $component->getContent() : $component->getState();
        }
    }

    return $lines;
}

/** The action, for this record, as its page builds it. */
function offerAction(Action $action, Entry $record): Action
{
    return $action->record($record);
}

describe('offering a file', function (): void {
    /* W1. Nothing moves: the file stays private where it is, as uploaded — a located photo with its location (decision 4). */
    it('offers a private file kept to one site, moving no byte and writing no entry', function (): void {
        $entry = offerStored('Photo', bytes: LocatedJpeg::photo(true), name: 'photo.jpg');
        $before = offerRow($entry);
        $held = offerDisks();
        $updatedAt = DB::table('entries')->where('id', $entry->id)->value('updated_at');
        $revisions = DB::table('entry_revisions')->where('entry_id', $entry->id)->count();

        expect(MediaVisibility::offer($entry, OFFER_NAME))->toBe(MediaVisibility::SWITCHED);

        $row = offerRow($entry);
        $bytes = (string) file_get_contents($this->disks[MediaDisks::PRIVATE]->root().'/'.$row['path']);

        expect($row)->toBe([...$before, 'reader_entitlement' => OFFER_NAME])
            ->and(offerDisks())->toBe($held)
            ->and(RefusingDisk::$log)->toBe([])
            ->and(LocatedJpeg::sentinels($bytes))->not->toBe([])
            ->and(offerAudits($entry))->toBe(['entry.created', MediaVisibility::OFFERED])
            ->and(DB::table('entries')->where('id', $entry->id)->value('updated_at'))->toBe($updatedAt)
            ->and(DB::table('entry_revisions')->where('entry_id', $entry->id)->count())->toBe($revisions)
            ->and(MediaFile::query()->where('entry_id', $entry->id)->firstOrFail()->readerEntitlement())->toBe(OFFER_NAME);
    });

    /* W2. Already offered for that name: nothing written, nothing audited. */
    it('writes nothing and audits nothing when the file is already offered for that name', function (): void {
        $entry = offerStored();
        MediaVisibility::offer($entry, OFFER_NAME);
        $before = offerRow($entry);
        $audits = DB::table('audit_log')->count();
        DB::enableQueryLog();
        DB::flushQueryLog();

        expect(MediaVisibility::offer($entry, OFFER_NAME))->toBe(MediaVisibility::UNCHANGED);

        $writes = array_filter(DB::getQueryLog(), static fn (array $query): bool => preg_match('/^\s*(insert|delete)\b|^\s*update\s+\S+\s+set\s+(?!["`]?disk["`]?\s*=\s*["`]?disk)/i', $query['query']) === 1);

        expect(offerRow($entry))->toBe($before)
            ->and(DB::table('audit_log')->count())->toBe($audits)
            ->and($writes)->toBe([]);
    });

    /* W3. Another name: the row says it, and the audit says it changed. */
    it('changes the name a file is offered for, audited as a change', function (): void {
        $entry = offerStored();
        MediaVisibility::offer($entry, OFFER_NAME);

        expect(MediaVisibility::offer($entry, OFFER_OTHER))->toBe(MediaVisibility::SWITCHED)
            ->and(offerRow($entry)['reader_entitlement'])->toBe(OFFER_OTHER)
            ->and(offerAudits($entry))->toBe(['entry.created', MediaVisibility::OFFERED, MediaVisibility::OFFER_CHANGED]);
    });

    /* W4. Stopped: offered to no one, audited; stopped again: nothing written, nothing audited. */
    it('stops offering a file, audited, and writes nothing when stopped again', function (): void {
        $entry = offerStored();
        MediaVisibility::offer($entry, OFFER_NAME);

        expect(MediaVisibility::stopOffering($entry))->toBe(MediaVisibility::SWITCHED)
            ->and(offerRow($entry)['reader_entitlement'])->toBeNull()
            ->and(offerRow($entry)['visibility'])->toBe('private')
            ->and(offerAudits($entry))->toBe(['entry.created', MediaVisibility::OFFERED, MediaVisibility::OFFER_STOPPED]);

        $before = offerRow($entry);
        $audits = DB::table('audit_log')->count();

        expect(MediaVisibility::stopOffering($entry))->toBe(MediaVisibility::UNCHANGED)
            ->and(offerRow($entry))->toBe($before)
            ->and(DB::table('audit_log')->count())->toBe($audits);
    });

    /* W5. A name as long as the column is stored; a byte longer is refused. */
    it('stores a name of a hundred bytes, and refuses one of a hundred and one', function (): void {
        $entry = offerStored();
        $longest = 'a.'.str_repeat('b', 98);
        $longer = 'a.'.str_repeat('b', 99);

        expect(strlen($longest))->toBe(100)->and(strlen($longer))->toBe(101);

        MediaVisibility::offer($entry, $longest);

        expect(offerRow($entry)['reader_entitlement'])->toBe($longest)
            ->and(offerRefusedBy(fn () => MediaVisibility::offer($entry, $longer))->reason)->toBe(MediaVisibilityRefused::NOT_A_NAME)
            ->and(offerRow($entry)['reader_entitlement'])->toBe($longest);
    });

    /* W6. The audit fails: the offer is not written. */
    it('writes no offer when its audit cannot be written', function (): void {
        $entry = offerStored();
        AuditorStandIn::install()->throwOnce(new RuntimeException('The audit log is unavailable.'));

        expect(fn () => MediaVisibility::offer($entry, OFFER_NAME))->toThrow(RuntimeException::class, 'The audit log is unavailable.');
        expect(offerRow($entry)['reader_entitlement'])->toBeNull();
    });

    /* W7. Whoever may publish the type offers and stops, attributed; the audit row names the entry, the org and the site. */
    it('records each change against the entry, its actor, organisation and site', function (string $grant): void {
        $entry = offerStored();
        $user = offerActingWith($grant);

        MediaVisibility::offer($entry, OFFER_NAME);
        MediaVisibility::stopOffering($entry);

        foreach ([MediaVisibility::OFFERED, MediaVisibility::OFFER_STOPPED] as $action) {
            $row = (array) DB::table('audit_log')->where('action', $action)->sole();

            expect([$row['target_type'], $row['target_id'], $row['actor_type'], $row['actor_id'], (int) $row['org_id'], (int) $row['site_id']])
                ->toBe([Entry::class, (string) $entry->id, $user->getMorphClass(), (string) $user->getKey(), $this->org->id, $this->site->id]);
        }
    })->with(['the type\'s publish' => 'publish', 'every type\'s publish' => 'entry.*.publish']);
});

describe('the audit', function (): void {
    /* A1. ADR-040: no audit row holds what a reader holds — the name is in no column of any row, whichever way it got there. */
    it('holds the name in no column of any audit row', function (): void {
        $entry = offerStored();
        offerActingWith('publish');

        MediaVisibility::offer($entry, OFFER_NAME);
        MediaVisibility::offer($entry, OFFER_OTHER);
        MediaVisibility::stopOffering($entry);
        MediaVisibility::offer($entry, OFFER_NAME);

        $columns = DatabaseSchema::getColumnListing('audit_log');
        $rows = DB::table('audit_log')->get()->map(static fn (stdClass $row): array => (array) $row)->all();
        $found = [];

        foreach ($rows as $row) {
            // Every column the table has, each read whatever its type.
            expect(array_keys($row))->toEqualCanonicalizing($columns);

            foreach ($row as $column => $value) {
                foreach ([OFFER_NAME, OFFER_OTHER, 'advanced-php', 'everything-2026'] as $needle) {
                    if (str_contains((string) $value, $needle)) {
                        $found[] = "{$column} holds {$needle}";
                    }
                }
            }
        }

        expect(array_column($rows, 'action'))->toContain(MediaVisibility::OFFERED, MediaVisibility::OFFER_CHANGED, MediaVisibility::OFFER_STOPPED)
            ->and($found)->toBe([]);
    });
});

describe('what is refused', function (): void {
    /* R1. Each refusal leaves the row, the disks and the audit log as they were, in the writer's own words. */
    it('refuses to offer, changing nothing', function (Closure $arrange, string $reason, string $words): void {
        [$entry, $name] = $arrange->call(test());
        $before = offerRow($entry);
        $held = offerDisks();
        $audits = DB::table('audit_log')->count();

        $refused = offerRefusedBy(fn () => MediaVisibility::offer($entry, $name));

        expect($refused->reason)->toBe($reason)
            ->and($refused->getMessage())->toStartWith("Refusing to offer entry {$entry->id} to readers: ")->toContain($words)
            ->and(offerRow($entry))->toBe($before)
            ->and(offerDisks())->toBe($held)
            ->and(DB::table('audit_log')->count())->toBe($audits);
    })->with([
        'capitals' => [fn (): array => [offerStored(), 'Course.Advanced-PHP'], MediaVisibilityRefused::NOT_A_NAME, 'not an entitlement name'],
        'one word' => [fn (): array => [offerStored(), 'course'], MediaVisibilityRefused::NOT_A_NAME, 'not an entitlement name'],
        'nothing' => [fn (): array => [offerStored(), ''], MediaVisibilityRefused::NOT_A_NAME, 'not an entitlement name'],
        'a trailing space' => [fn (): array => [offerStored(), OFFER_NAME.' '], MediaVisibilityRefused::NOT_A_NAME, 'not an entitlement name'],
        'a trailing newline' => [fn (): array => [offerStored(), OFFER_NAME."\n"], MediaVisibilityRefused::NOT_A_NAME, 'not an entitlement name'],
        'an underscore' => [fn (): array => [offerStored(), 'course.advanced_php'], MediaVisibilityRefused::NOT_A_NAME, 'not an entitlement name'],
        'a hundred and one bytes' => [fn (): array => [offerStored(), 'a.'.str_repeat('b', 99)], MediaVisibilityRefused::NOT_A_NAME, 'not an entitlement name'],
        'a public file' => [fn (): array => [offerStored(visibility: 'public'), OFFER_NAME], MediaVisibilityRefused::PUBLIC_FILE, 'Make it private first'],
        'a file shared with every site' => [fn (): array => [offerStored(siteOnly: false), OFFER_NAME], MediaVisibilityRefused::SHARED, 'Upload it to this site alone'],
        'in the trash' => [function (): array {
            $entry = offerStored();
            $entry->delete();

            return [Entry::withTrashed()->findOrFail($entry->id), OFFER_NAME];
        }, MediaVisibilityRefused::TRASHED, 'Restore it first'],
        'no file' => [function (): array {
            $entry = offerStored();
            DB::table('media_files')->where('entry_id', $entry->id)->delete();

            return [$entry, OFFER_NAME];
        }, MediaVisibilityRefused::NO_FILE, 'no file is recorded'],
        'gone' => [function (): array {
            $entry = offerStored();
            DB::table('media_files')->where('entry_id', $entry->id)->delete();
            DB::table('entries')->where('id', $entry->id)->delete();

            return [$entry, OFFER_NAME];
        }, MediaVisibilityRefused::GONE, 'it no longer exists'],
        'view alone' => [function (): array {
            $entry = offerStored();
            offerActingWith('view');

            return [$entry, OFFER_NAME];
        }, MediaVisibilityRefused::NOT_PERMITTED, '[entry.download.publish]'],
        'update, and not publish' => [function (): array {
            $entry = offerStored();
            offerActingWith('view', 'update');

            return [$entry, OFFER_NAME];
        }, MediaVisibilityRefused::NOT_PERMITTED, '[entry.download.publish]'],
        'another site\'s own, in the same organisation' => [function (): array {
            $entry = offerStored();
            app(Context::class)->setSite(Site::create(['handle' => 'other', 'slug' => 'offers-other', 'name' => 'Other', 'locale' => 'en']));

            return [$entry, OFFER_NAME];
        }, MediaVisibilityRefused::OUT_OF_SCOPE, 'neither this site\'s'],
        'another organisation\'s' => [function (): array {
            $entry = offerStored();
            $other = Org::create(['slug' => 'elsewhere', 'name' => 'Elsewhere']);
            app(Context::class)->setOrg($other);
            app(Context::class)->setSite(Site::create(['org_id' => $other->id, 'handle' => 'main', 'slug' => 'elsewhere-main', 'name' => 'Main', 'locale' => 'en']));

            return [$entry, OFFER_NAME];
        }, MediaVisibilityRefused::OUT_OF_SCOPE, 'neither this site\'s'],
    ]);

    /* R2. A name the writer would not store is refused before any row is read or locked. */
    it('refuses what is not a name before reading any row', function (): void {
        $entry = offerStored();
        DB::enableQueryLog();
        DB::flushQueryLog();

        expect(offerRefusedBy(fn () => MediaVisibility::offer($entry, 'Not a name'))->reason)->toBe(MediaVisibilityRefused::NOT_A_NAME)
            ->and(DB::getQueryLog())->toBe([]);
    });

    /* R3. Stopping is refused where the file is not this user's to change, or not there. */
    it('refuses to stop offering, changing nothing', function (Closure $arrange, string $reason): void {
        $entry = offerStored();
        offerAsSystem($entry);
        $entry = $arrange->call(test(), $entry);
        $before = offerRow($entry);
        $audits = DB::table('audit_log')->count();

        $refused = offerRefusedBy(fn () => MediaVisibility::stopOffering($entry));

        expect($refused->reason)->toBe($reason)
            ->and($refused->getMessage())->toStartWith("Refusing to stop offering entry {$entry->id} to readers: ")
            ->and(offerRow($entry))->toBe($before)
            ->and(DB::table('audit_log')->count())->toBe($audits);
    })->with([
        'in the trash' => [function (Entry $entry): Entry {
            $entry->delete();

            return Entry::withTrashed()->findOrFail($entry->id);
        }, MediaVisibilityRefused::TRASHED],
        'view alone' => [function (Entry $entry): Entry {
            offerActingWith('view');

            return $entry;
        }, MediaVisibilityRefused::NOT_PERMITTED],
        'update, and not publish' => [function (Entry $entry): Entry {
            offerActingWith('view', 'update');

            return $entry;
        }, MediaVisibilityRefused::NOT_PERMITTED],
        'another site\'s own, in the same organisation' => [function (Entry $entry): Entry {
            app(Context::class)->setSite(Site::create(['handle' => 'other', 'slug' => 'offers-other', 'name' => 'Other', 'locale' => 'en']));

            return $entry;
        }, MediaVisibilityRefused::OUT_OF_SCOPE],
        'another organisation\'s' => [function (Entry $entry): Entry {
            $other = Org::create(['slug' => 'elsewhere', 'name' => 'Elsewhere']);
            app(Context::class)->setOrg($other);
            app(Context::class)->setSite(Site::create(['org_id' => $other->id, 'handle' => 'main', 'slug' => 'elsewhere-main', 'name' => 'Main', 'locale' => 'en']));

            return $entry;
        }, MediaVisibilityRefused::OUT_OF_SCOPE],
    ]);

    /* R4. An offer written below the writer onto a public or a shared file is cleared, never kept. */
    it('stops an offer written below the writer on a public or a shared file', function (string $visibility, bool $siteOnly): void {
        $entry = offerStored(visibility: $visibility, siteOnly: $siteOnly);
        DB::table('media_files')->where('entry_id', $entry->id)->update(['reader_entitlement' => OFFER_NAME]);

        expect(MediaVisibility::stopOffering($entry))->toBe(MediaVisibility::SWITCHED)
            ->and(offerRow($entry)['reader_entitlement'])->toBeNull()
            ->and(offerRow($entry)['visibility'])->toBe($visibility);
    })->with(['public' => ['public', true], 'shared' => ['private', false]]);

    /* R5. No organisation: refused before anything is read. */
    it('refuses with no organisation in context', function (): void {
        $entry = offerStored();
        app(Context::class)->forget();

        expect(fn () => MediaVisibility::offer($entry, OFFER_NAME))->toThrow(RuntimeException::class, 'no organisation in context')
            ->and(fn () => MediaVisibility::stopOffering($entry))->toThrow(RuntimeException::class, 'no organisation in context');

        expect(offerRow($entry)['reader_entitlement'])->toBeNull();
    });

    /* R6. The one write below the model checks what nothing below it checks: an offer only on a private row, only a name. */
    it('writes an offer only onto a private row, and only a name', function (array $arguments): void {
        $entry = offerStored(visibility: $arguments[0]);
        $before = offerRow($entry);
        $writeRow = new ReflectionMethod(MediaVisibility::class, 'writeRow');

        expect(fn () => $writeRow->invoke(null, DB::connection(), $entry->id, ...$arguments))->toThrow(LogicException::class, 'the values are not a file\'s');
        expect(offerRow($entry))->toBe($before);
    })->with([
        'a name on a public row' => [['public', null, null, null, OFFER_NAME]],
        'what is not a name' => [['private', null, null, null, 'Not a name']],
        'a hundred and one bytes' => [['private', null, null, null, 'a.'.str_repeat('b', 99)]],
    ]);
});

describe('making an offered file public', function (): void {
    /* M1. Decision 2: a file offered to readers stays private until the offer stops. */
    it('is refused while an offer stands, changing nothing, and made public once it stops', function (): void {
        $entry = offerStored();
        MediaVisibility::offer($entry, OFFER_NAME);
        $before = offerRow($entry);
        $held = offerDisks();
        $audits = DB::table('audit_log')->count();

        $refused = offerRefusedBy(fn () => MediaVisibility::makePublic($entry));

        expect($refused->reason)->toBe(MediaVisibilityRefused::OFFERED)
            ->and($refused->getMessage())->toStartWith("Refusing to make entry {$entry->id} public: ")->toContain('Stop offering it first')
            ->and(offerRow($entry))->toBe($before)
            ->and(offerDisks())->toBe($held)
            ->and(DB::table('audit_log')->count())->toBe($audits);

        MediaVisibility::stopOffering($entry);

        expect(MediaVisibility::makePublic($entry))->toBe(MediaVisibility::SWITCHED)
            ->and(offerRow($entry)['visibility'])->toBe('public');
    });

    /* M2. Any stored value, a name or not: the writer refuses for what the row holds, not for what reads as an offer. */
    it('is refused for a stored value that is not a name', function (): void {
        $entry = offerStored();
        DB::table('media_files')->where('entry_id', $entry->id)->update(['reader_entitlement' => 'Not a name']);

        expect(offerRefusedBy(fn () => MediaVisibility::makePublic($entry))->reason)->toBe(MediaVisibilityRefused::OFFERED)
            ->and(offerRow($entry)['visibility'])->toBe('private');
    });
});

describe('what else writes an entry', function (): void {
    /* E1. A revision restore writes the entry's content, never its file's row: the offer stays as it is, either way. */
    it('leaves the offer as it is when a revision is restored', function (): void {
        $entry = offerStored();
        $first = $entry->revisions()->firstOrFail();
        MediaVisibility::offer($entry, OFFER_NAME);
        $entry->update(['title' => 'Renamed']);

        $entry->restoreRevision($first);

        expect(DB::table('entries')->where('id', $entry->id)->value('title'))->toBe('Report')
            ->and(offerRow($entry)['reader_entitlement'])->toBe(OFFER_NAME);

        MediaVisibility::stopOffering($entry);
        $entry->update(['title' => 'Renamed again']);
        $entry->restoreRevision($first);

        expect(offerRow($entry)['reader_entitlement'])->toBeNull();
    });

    /* E2. A stored value written below the writer that is not a name reads as no offer. */
    it('reads a stored value that is not a name as no offer', function (string $stored, ?string $read): void {
        $entry = offerStored();
        DB::table('media_files')->where('entry_id', $entry->id)->update(['reader_entitlement' => $stored]);

        expect(MediaFile::query()->where('entry_id', $entry->id)->firstOrFail()->readerEntitlement())->toBe($read);
    })->with([
        'a name' => [OFFER_NAME, OFFER_NAME],
        'capitals' => ['Course.PHP', null],
        'a trailing space' => [OFFER_NAME.' ', null],
        'nothing' => ['', null],
    ]);
});

describe('the actions', function (): void {
    beforeEach(function (): void {
        PanelTenancy::enter($this->site);
        session()->forget('filament.notifications');
    });

    /* P1. A media type's pages carry the three, after Make public and Make private; any other type's carry none. */
    it('puts the three on a media entry\'s View and Edit pages, and none on another type\'s', function (): void {
        $names = static function (string $page): array {
            $method = new ReflectionMethod($page, 'getHeaderActions');

            return array_map(static fn ($action): string => $action->getName(), $method->invoke(app($page)));
        };

        expect(array_map(static fn (Action $action): string => $action->getName(), MediaReaderActions::all()))
            ->toBe(['offerToReaders', 'changeReaderOffer', 'stopOfferingToReaders'])
            ->and($names(ViewEntry::class))->toBe(['makePublic', 'makePrivate', 'offerToReaders', 'changeReaderOffer', 'stopOfferingToReaders', 'edit'])
            ->and($names(EditEntry::class))->toBe(['makePublic', 'makePrivate', 'offerToReaders', 'changeReaderOffer', 'stopOfferingToReaders', 'delete']);

        app()->instance(EntryType::class, EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']));

        expect(MediaReaderActions::all())->toBe([]);
    });

    /* P2. Offer on a file offered to no one; Change and Stop on an offered one; none in the trash or with no file. */
    it('shows Offer, or Change and Stop, as the file is — and none in the trash or with no file', function (): void {
        offerActingWith('view', 'publish');
        $plain = offerStored('Plain');
        $public = offerStored('Public', visibility: 'public');
        $offered = offerStored('Offered');
        offerAsSystem($offered);
        $trashed = offerStored('Trashed');
        offerAsSystem($trashed);
        $trashed->delete();
        $trashed = Entry::withTrashed()->findOrFail($trashed->id);
        $bare = offerStored('Bare');
        DB::table('media_files')->where('entry_id', $bare->id)->delete();

        $shown = static fn (Entry $record): array => array_keys(array_filter(
            ['offer' => MediaReaderActions::offer(), 'change' => MediaReaderActions::change(), 'stop' => MediaReaderActions::stop()],
            static fn (Action $action): bool => offerAction($action, $record)->isVisible(),
        ));

        expect($shown($plain))->toBe(['offer'])
            ->and($shown($public))->toBe(['offer'])
            ->and($shown($offered))->toBe(['change', 'stop'])
            ->and($shown($trashed))->toBe([])
            ->and($shown($bare))->toBe([]);
    });

    /* P3. Hidden from whoever may not publish the type — update included — with no field; shown to whoever may. */
    it('is hidden, with no field, from whoever may not publish the type', function (array $grants, bool $may): void {
        offerActingWith(...$grants);
        $plain = offerStored('Plain');
        $offered = offerStored('Offered');
        offerAsSystem($offered);

        foreach ([[MediaReaderActions::offer(), $plain], [MediaReaderActions::change(), $offered], [MediaReaderActions::stop(), $offered]] as [$action, $record]) {
            $action = offerAction($action, $record);

            expect($action->isAuthorized())->toBe($may)
                ->and($action->isHidden())->toBe(! $may);
        }

        expect(offerAction(MediaReaderActions::offer(), $plain)->getSchema(Schema::make(app(ViewEntry::class))) === null)->toBe(! $may);
    })->with([
        'view alone' => [['view'], false],
        'update' => [['view', 'update'], false],
        'publish' => [['view', 'publish'], true],
    ]);

    /* P4. Decision 2 before anyone types: a shared or a public file is shown the action disabled, saying why, with no field. */
    it('is disabled for a file it cannot be offered, saying why — and Stop never is', function (): void {
        offerActingWith('view', 'publish');
        $shared = offerStored('Shared', siteOnly: false);
        $public = offerStored('Public', visibility: 'public');
        $plain = offerStored('Plain');
        $sharedOffered = offerStored('Shared, offered below the writer', siteOnly: false);
        DB::table('media_files')->where('entry_id', $sharedOffered->id)->update(['reader_entitlement' => OFFER_NAME]);

        $offerShared = offerAction(MediaReaderActions::offer(), $shared);
        $offerPublic = offerAction(MediaReaderActions::offer(), $public);
        $offerPlain = offerAction(MediaReaderActions::offer(), $plain);
        $change = offerAction(MediaReaderActions::change(), $sharedOffered);
        $stop = offerAction(MediaReaderActions::stop(), $sharedOffered);

        expect($offerShared->isDisabled())->toBeTrue()
            ->and($offerShared->getTooltip())->toBe(__('kitsune::media.readers.shared'))
            ->and($offerShared->getSchema(Schema::make(app(ViewEntry::class))))->toBeNull()
            ->and($offerPublic->isDisabled())->toBeTrue()
            ->and($offerPublic->getTooltip())->toBe(__('kitsune::media.readers.public'))
            ->and($offerPlain->isDisabled())->toBeFalse()
            ->and($offerPlain->getTooltip())->toBeNull()
            ->and($change->isDisabled())->toBeTrue()
            ->and($change->getTooltip())->toBe(__('kitsune::media.readers.shared'))
            ->and($stop->isVisible())->toBeTrue()
            ->and($stop->isDisabled())->toBeFalse();
    });

    /* P5. The name, typed as a name is; the dialog says readers get the file as uploaded, location included (decision 4). */
    it('asks for the name left to right, unspelled, at most a hundred, refusing anything not a name — and warns of the location', function (): void {
        offerActingWith('view', 'publish');
        $entry = offerStored();
        $offered = offerStored('Offered');
        offerAsSystem($offered);

        $offer = offerAction(MediaReaderActions::offer(), $entry);
        $components = $offer->getSchema(Schema::make(app(ViewEntry::class)))?->getFlatComponents(withHidden: true) ?? [];
        $input = collect($components)->first(static fn ($component): bool => $component instanceof TextInput);

        expect($input)->toBeInstanceOf(TextInput::class)
            ->and($input->getName())->toBe(MediaReaderActions::FIELD)
            ->and($input->getExtraInputAttributes())->toMatchArray(['dir' => 'ltr', 'spellcheck' => 'false', 'autocapitalize' => 'off', 'autocomplete' => 'off'])
            ->and($input->getMaxLength())->toBe(100)
            ->and($input->isRequired())->toBeTrue();

        $rules = $input->getValidationRules();
        $passes = static fn (mixed $value): bool => Validator::make([MediaReaderActions::FIELD => $value], [MediaReaderActions::FIELD => $rules])->passes();

        expect($passes(OFFER_NAME))->toBeTrue()
            ->and($passes('a.'.str_repeat('b', 98)))->toBeTrue()
            ->and($passes('a.'.str_repeat('b', 99)))->toBeFalse()
            ->and($passes('Course.PHP'))->toBeFalse()
            ->and($passes(OFFER_NAME.' '))->toBeFalse()
            ->and($passes('course'))->toBeFalse()
            ->and($passes(''))->toBeFalse()
            ->and($passes(['course.php']))->toBeFalse()
            // The first word starts with a letter, and the words say so.
            ->and($passes('1course.php'))->toBeFalse()
            ->and($passes('course.1php'))->toBeTrue()
            ->and(__('kitsune::media.readers.not_a_name'))->toContain('the first word starting with a letter');

        $refusal = Validator::make([MediaReaderActions::FIELD => 'Course.PHP'], [MediaReaderActions::FIELD => $rules])->errors()->first(MediaReaderActions::FIELD);

        expect($refusal)->toBe(__('kitsune::media.readers.not_a_name'))
            ->and($offer->getModalDescription())->toBe(__('kitsune::media.readers.as_stored'))
            // As stored, not as uploaded: a JPEG made public at some point lost its GPS coordinates then.
            ->and(__('kitsune::media.readers.as_stored'))->toStartWith('Readers get this file as it is stored here: a photo keeps any location it still holds (a JPEG made public at some point lost its GPS coordinates then)')
            ->and(__('kitsune::media.readers.as_stored'))->not->toContain('as it was uploaded')
            ->and((string) offerAction(MediaReaderActions::change(), $offered)->getModalDescription())->toContain(__('kitsune::media.readers.as_stored'));
    });

    /* P5b. Outside any transaction, whatever a host panel asks of every action: the writer commits under its own lock. */
    it('runs outside any transaction, whatever the panel asks', function (): void {
        $actions = Action::configureUsing(
            static fn (Action $action) => $action->databaseTransaction(),
            static fn (): array => [MediaReaderActions::offer(), MediaReaderActions::change(), MediaReaderActions::stop(), Action::make('control')],
        );

        expect(array_map(static fn (Action $action): bool => $action->hasDatabaseTransactions(), $actions))->toBe([false, false, false, true]);
    });

    /* P6. Change opens with the name the file is offered for. */
    it('fills Change with the name the file is offered for', function (): void {
        offerActingWith('view', 'publish');
        $entry = offerStored();
        offerAsSystem($entry);
        $filled = null;
        $schema = Mockery::mock(Schema::class);
        $schema->shouldReceive('fill')->once()->andReturnUsing(static function (array $state) use (&$filled, $schema) {
            $filled = $state;

            return $schema;
        });

        offerAction(MediaReaderActions::change(), $entry)->mount(['schema' => $schema]);

        expect($filled)->toBe([MediaReaderActions::FIELD => OFFER_NAME]);
    });

    /* P7. Make public waits for the offer to stop: disabled, saying why, with no field. */
    it('disables Make public while the file is offered, saying why, and enables it once the offer stops', function (): void {
        offerActingWith('view', 'publish');
        $entry = offerStored();
        offerAsSystem($entry);

        $public = offerAction(MediaVisibilityActions::makePublic(), $entry);

        expect($public->isVisible())->toBeTrue()
            ->and($public->isDisabled())->toBeTrue()
            ->and($public->getTooltip())->toBe(__('kitsune::media.readers.offered_not_public'))
            ->and($public->getSchema(Schema::make(app(ViewEntry::class))))->toBeNull();

        MediaVisibility::stopOffering($entry);
        $public = offerAction(MediaVisibilityActions::makePublic(), $entry);

        expect($public->isDisabled())->toBeFalse()
            ->and($public->getTooltip())->toBeNull()
            ->and($public->getSchema(Schema::make(app(ViewEntry::class))))->not->toBeNull();
    });

    /* P8. Make public's handler shows the writer's refusal while offered, escaped, and changes nothing. */
    it('says why Make public was refused while the file is offered', function (): void {
        offerActingWith('view', 'publish');
        $entry = offerStored('<b>Report</b>');
        offerAsSystem($entry);

        MediaVisibilityActions::publicOne($entry, ['public_confirmed' => true]);

        expect(offerRow($entry)['visibility'])->toBe('private')
            ->and(offerNotices()[0]['status'])->toBe('danger')
            ->and(offerNotices()[0]['title'])->toBe('&quot;&lt;b&gt;Report&lt;/b&gt;&quot; was not made public')
            ->and(offerNotices()[0]['body'])->toBe(e((new MediaVisibilityRefused($entry->id, MediaVisibilityRefused::OFFERED, 'public'))->getMessage()));
    });
});

describe('what each outcome says', function (): void {
    beforeEach(function (): void {
        PanelTenancy::enter($this->site);
        session()->forget('filament.notifications');
        offerActingWith('view', 'publish');
    });

    /* N1. Offered: said, with the name; again: left as it was. */
    it('says a file is offered, and that offering it again changed nothing', function (): void {
        $entry = offerStored('<i>Report</i>');

        MediaReaderActions::offerOne($entry, [MediaReaderActions::FIELD => OFFER_NAME]);

        expect(offerRow($entry)['reader_entitlement'])->toBe(OFFER_NAME)
            ->and(offerNotices())->toHaveCount(1)
            ->and(offerNotices()[0]['status'])->toBe('success')
            ->and(offerNotices()[0]['title'])->toBe('&quot;&lt;i&gt;Report&lt;/i&gt;&quot; is offered to readers')
            ->and(offerNotices()[0]['body'])->toBe(e(__('kitsune::media.readers.offered_body', ['name' => OFFER_NAME])));

        session()->forget('filament.notifications');
        MediaReaderActions::offerOne($entry, [MediaReaderActions::FIELD => OFFER_NAME]);

        expect(offerNotices()[0]['status'])->toBe('info')
            ->and(offerNotices()[0]['title'])->toBe('&quot;&lt;i&gt;Report&lt;/i&gt;&quot; was already offered for that entitlement. Nothing was changed.');
    });

    /* N2. Changed, and stopped — each said; stopped again: left as it was. */
    it('says an offer was changed, and stopped, and that stopping it again changed nothing', function (): void {
        $entry = offerStored();
        offerAsSystem($entry);

        MediaReaderActions::changeOne($entry, [MediaReaderActions::FIELD => OFFER_OTHER]);

        expect(offerRow($entry)['reader_entitlement'])->toBe(OFFER_OTHER)
            ->and(offerNotices()[0]['status'])->toBe('success')
            ->and(offerNotices()[0]['title'])->toBe('The offer of &quot;Report&quot; was changed');

        session()->forget('filament.notifications');
        MediaReaderActions::stopOne($entry);

        expect(offerRow($entry)['reader_entitlement'])->toBeNull()
            ->and(offerNotices()[0]['status'])->toBe('success')
            ->and(offerNotices()[0]['title'])->toBe('&quot;Report&quot; is no longer offered to readers')
            ->and(offerNotices()[0]['body'])->toBe(e(__('kitsune::media.readers.stopped_body')));

        session()->forget('filament.notifications');
        MediaReaderActions::stopOne($entry);

        expect(offerNotices()[0]['status'])->toBe('info')
            ->and(offerNotices()[0]['title'])->toBe('&quot;Report&quot; was not offered to readers. Nothing was changed.');
    });

    /* N3. Every refusal in the writer's own words, escaped; the row as it was. */
    it('shows each refusal in the writer\'s own words, escaped', function (Closure $arrange, Closure $handler, string $title): void {
        [$entry, $reason] = $arrange->call(test());
        $before = offerRow($entry);

        $handler($entry);

        expect(offerRow($entry))->toBe($before)
            ->and(offerNotices())->toHaveCount(1)
            ->and(offerNotices()[0]['status'])->toBe('danger')
            ->and(offerNotices()[0]['title'])->toBe($title)
            ->and(offerNotices()[0]['body'])->toBe(e((new MediaVisibilityRefused($entry->id, $reason, str_contains($title, 'still') ? MediaVisibilityRefused::TO_STOP : MediaVisibilityRefused::TO_OFFER, detail: 'download'))->getMessage()));
    })->with([
        'not a name' => [
            fn (): array => [offerStored('<b>A & B</b>'), MediaVisibilityRefused::NOT_A_NAME],
            fn (Entry $entry) => MediaReaderActions::offerOne($entry, [MediaReaderActions::FIELD => 'Course.PHP']),
            '&quot;&lt;b&gt;A &amp; B&lt;/b&gt;&quot; was not offered to readers',
        ],
        'no string at all' => [
            fn (): array => [offerStored('<b>A & B</b>'), MediaVisibilityRefused::NOT_A_NAME],
            fn (Entry $entry) => MediaReaderActions::offerOne($entry, [MediaReaderActions::FIELD => ['course.php']]),
            '&quot;&lt;b&gt;A &amp; B&lt;/b&gt;&quot; was not offered to readers',
        ],
        'shared' => [
            fn (): array => [offerStored('<b>A & B</b>', siteOnly: false), MediaVisibilityRefused::SHARED],
            fn (Entry $entry) => MediaReaderActions::offerOne($entry, [MediaReaderActions::FIELD => OFFER_NAME]),
            '&quot;&lt;b&gt;A &amp; B&lt;/b&gt;&quot; was not offered to readers',
        ],
        'public, changed' => [
            function (): array {
                $entry = offerStored('<b>A & B</b>', visibility: 'public');
                DB::table('media_files')->where('entry_id', $entry->id)->update(['reader_entitlement' => OFFER_NAME]);

                return [$entry, MediaVisibilityRefused::PUBLIC_FILE];
            },
            fn (Entry $entry) => MediaReaderActions::changeOne($entry, [MediaReaderActions::FIELD => OFFER_OTHER]),
            'The offer of &quot;&lt;b&gt;A &amp; B&lt;/b&gt;&quot; was not changed',
        ],
        'another site\'s own, its words holding an apostrophe' => [
            function (): array {
                $entry = offerStored('<b>A & B</b>');
                PanelTenancy::moveTo(Site::create(['handle' => 'other', 'slug' => 'offers-other', 'name' => 'Other', 'locale' => 'en']));

                return [$entry, MediaVisibilityRefused::OUT_OF_SCOPE];
            },
            fn (Entry $entry) => MediaReaderActions::offerOne($entry, [MediaReaderActions::FIELD => OFFER_NAME]),
            '&quot;&lt;b&gt;A &amp; B&lt;/b&gt;&quot; was not offered to readers',
        ],
        'stopped from another site, its words holding an apostrophe' => [
            function (): array {
                $entry = offerStored('<b>A & B</b>');
                offerAsSystem($entry);
                PanelTenancy::moveTo(Site::create(['handle' => 'other', 'slug' => 'offers-other', 'name' => 'Other', 'locale' => 'en']));

                return [$entry, MediaVisibilityRefused::OUT_OF_SCOPE];
            },
            fn (Entry $entry) => MediaReaderActions::stopOne($entry),
            '&quot;&lt;b&gt;A &amp; B&lt;/b&gt;&quot; is still offered to readers',
        ],
        'stopped by whoever may only update' => [
            function (): array {
                $entry = offerStored('<b>A & B</b>');
                offerAsSystem($entry);
                offerActingWith('view', 'update');

                return [$entry, MediaVisibilityRefused::NOT_PERMITTED];
            },
            fn (Entry $entry) => MediaReaderActions::stopOne($entry),
            '&quot;&lt;b&gt;A &amp; B&lt;/b&gt;&quot; is still offered to readers',
        ],
    ]);

    /* N4. Any other failure is not the editor's to read: it goes through, for Filament to report as its own. */
    it('lets any other failure through, sending no notice', function (): void {
        $entry = offerStored();
        app(Context::class)->forget();

        expect(fn () => MediaReaderActions::offerOne($entry, [MediaReaderActions::FIELD => OFFER_NAME]))->toThrow(RuntimeException::class, 'no organisation in context')
            ->and(fn () => MediaReaderActions::stopOne($entry))->toThrow(RuntimeException::class, 'no organisation in context');

        expect(offerNotices())->toBe([]);
    });
});

describe('the File section', function (): void {
    beforeEach(function (): void {
        PanelTenancy::enter($this->site);
    });

    /* S1. Offered to no one: said, in its own direction, and no link or note. */
    it('says a file is offered to no one, with no link and no note', function (): void {
        $entry = offerStored();
        $section = offerSection($entry);

        expect($section['media_file_readers']->getState())->toBe('Not offered')
            ->and($section['media_file_readers']->isHidden())->toBeFalse()
            ->and($section['media_file_readers']->getExtraAttributes())->toMatchArray(['dir' => 'auto'])
            ->and(offerLines($entry))->toBe([]);
    });

    /* S2. Offered: the name, left to right; the absolute reader link as text, a button to copy it; and each note an owner needs (decision 3). */
    it('names the entitlement and gives the reader link, with the notes an owner needs', function (): void {
        $entry = offerStored();
        offerAsSystem($entry);
        $section = offerSection($entry);
        $link = $section['media_file_reader_link'];
        $url = "https://offers.test/account/downloads/{$entry->id}";

        expect($section['media_file_readers']->getState())->toBe(OFFER_NAME)
            ->and($section['media_file_readers']->getExtraAttributes())->toMatchArray(['dir' => 'ltr'])
            ->and($link->getState())->toBe($url)
            ->and($link->getExtraAttributes())->toMatchArray(['dir' => 'ltr'])
            // No reader guard is declared in this suite, so reader accounts cannot work here: said.
            ->and(offerLines($entry))->toBe([
                'media_file_reader_link' => $url,
                'media_file_reader_accounts_off' => __('kitsune::media.readers.file.accounts_off'),
                'media_file_reader_not_a_reader' => __('kitsune::media.readers.file.not_a_reader'),
                'media_file_reader_as_stored' => __('kitsune::media.readers.file.as_stored'),
            ])
            ->and(__('kitsune::media.readers.file.as_stored'))->toBe('Readers get it as it is stored here, with any location it holds.');
    });

    /*
     * S2b. The link is text anyone can select, and never Filament's click-only copyable span: beside it, a real button
     * with a name, which copies in the browser and confirms in a notification — Filament's, in its `role="status"`
     * container — or says it could not.
     */
    it('gives the reader link a copy button a keyboard reaches, with a name, confirming in a notification', function (): void {
        $entry = offerStored();
        offerAsSystem($entry);
        $link = offerSection($entry)['media_file_reader_link'];
        $url = "https://offers.test/account/downloads/{$entry->id}";
        $html = $link->toHtml();
        $actions = $link->getSuffixActions();

        expect($link)->toBeInstanceOf(TextEntry::class)
            ->and($link->isCopyable($url))->toBeFalse()
            ->and($html)->not->toContain('fi-copyable')
            ->and(preg_match('#<li[^>]*>\s*'.preg_quote($url, '#').'\s*</li>#', $html))->toBe(1)
            ->and(array_keys($actions))->toBe(['copyReaderLink'])
            ->and($actions['copyReaderLink']->getLabel())->toBe('Copy reader link')
            // The button's own attributes, up to its icon: its click handler holds `=>`, so `[^>]*` would stop short.
            ->and(preg_match_all('#<button\b(.*?)>\s*<svg\b#s', $html, $buttons))->toBe(1)
            ->and(substr_count($html, '<button'))->toBe(1);

        $button = $buttons[1][0];
        $copied = 'new FilamentNotification().title('.Js::from(__('kitsune::media.readers.file.copied')).').success().send()';
        $refused = 'new FilamentNotification().title('.Js::from(__('kitsune::media.readers.file.not_copied')).').danger().send()';

        // A button in the tab order, named, that asks nothing of the server: no Livewire round trip, no disabled state.
        expect($button)->toContain('type="button"')
            ->and($button)->toContain('aria-label="Copy reader link"')
            ->and($button)->not->toContain('tabindex')
            ->and($button)->not->toContain('wire:click')
            ->and($button)->not->toMatch('/\sdisabled(?:[\s=>]|$)/')
            ->and($button)->toContain('x-on:click="window.navigator.clipboard?.writeText('.Js::from($url).').then(() => '.$copied.', () => '.$refused.') ?? '.$refused.'"')
            ->and(__('kitsune::media.readers.file.copied'))->toBe('Reader link copied');
    });

    /* S2c. Every note is text: no screen-reader term made from its name, and each in its own direction. */
    it('renders each note as text in its own direction, with no label a screen reader reads from its name', function (): void {
        $entry = offerStored();
        offerAsSystem($entry);
        $section = offerSection($entry);
        $notes = array_filter($section, static fn (Component $component, int|string $key): bool => is_string($key)
            && str_starts_with($key, 'media_file_reader_') && $key !== 'media_file_reader_link', ARRAY_FILTER_USE_BOTH);

        expect(array_keys($notes))->toBe([
            'media_file_reader_path_only', 'media_file_reader_not_served', 'media_file_reader_accounts_off',
            'media_file_reader_not_a_reader', 'media_file_reader_as_stored',
        ]);

        foreach ($notes as $key => $note) {
            expect($note)->toBeInstanceOf(Text::class, $key)
                ->and($note->getExtraAttributes())->toBe(['dir' => 'auto'], $key)
                ->and($note->getColumnSpan('default'))->toBe('full', $key);

            if (! $note->isHidden()) {
                $html = $note->toHtml();

                // `toContain()` takes needles, not a message: the key is said by the last assertion's.
                expect($html)->toContain('dir="auto"')
                    ->and($html)->not->toContain('role="term"')
                    ->and($html)->not->toContain('sr-only')
                    ->and(strtolower($html))->not->toContain('media file')
                    ->and(trim(strip_tags($html)))->toBe(e((string) $note->getContent()), $key);
            }
        }
    });

    /* S3. A site with no address of its own: the link is the page's path on the site, never built from the request — and said so. */
    it('gives the link as a path where the site has no address of its own, and says so', function (): void {
        $bare = Site::create(['handle' => 'bare', 'slug' => 'offers-bare', 'name' => 'Bare', 'locale' => 'en']);
        PanelTenancy::moveTo($bare);
        $entry = offerStored();
        offerAsSystem($entry);

        expect(offerSection($entry)['media_file_reader_link']->getState())->toBe("/account/downloads/{$entry->id}")
            ->and(offerLines($entry))->toHaveKey('media_file_reader_path_only')
            ->and(offerLines($entry)['media_file_reader_path_only'])->toBe('This site has no address of its own, so this is the link\'s path. Add it to the address readers use, or give the site one with kitsune:site address.');
    });

    /* S3b. A host-less site borrows `APP_URL`'s origin in local and testing alone; anywhere else, its path and the note. */
    it('gives a host-less site\'s link APP_URL\'s origin in testing, and its path with the note in production', function (): void {
        $hostless = Site::create(['handle' => 'hostless', 'slug' => 'offers-hostless', 'name' => 'Hostless', 'locale' => 'en', 'base_url' => '/offers']);
        PanelTenancy::moveTo($hostless);
        $entry = offerStored();
        offerAsSystem($entry);
        $origin = rtrim((string) config('app.url'), '/');

        expect(offerSection($entry)['media_file_reader_link']->getState())->toBe("{$origin}/offers/account/downloads/{$entry->id}")
            ->and(offerLines($entry))->not->toHaveKey('media_file_reader_path_only');

        app()->detectEnvironment(static fn (): string => 'production');

        expect(offerSection($entry)['media_file_reader_link']->getState())->toBe("/offers/account/downloads/{$entry->id}")
            ->and(offerLines($entry))->toHaveKey('media_file_reader_path_only');
    });

    /* S4. Where readers can sign in, the note that they cannot is not shown — the rest are. */
    it('says nothing of reader accounts being off where readers can sign in', function (): void {
        config(AppServiceProvider::readerConfig());
        app()->forgetInstance(ReaderGuard::class);
        Auth::forgetGuards();
        $this->site->update(['settings' => ['reader_accounts' => 'sign-in']]);
        SettingsResolver::forgetEverywhere();
        $entry = offerStored();
        offerAsSystem($entry);

        expect(array_keys(offerLines($entry)))->toBe(['media_file_reader_link', 'media_file_reader_not_a_reader', 'media_file_reader_as_stored']);

        $this->site->update(['settings' => ['reader_accounts' => 'off']]);
        SettingsResolver::forgetEverywhere();

        expect(offerLines($entry))->toHaveKey('media_file_reader_accounts_off');
    });

    /* S5. A value planted below the writer is shown for what it is, and no link is offered for a file no reader is served. */
    it('shows a value written below the writer for what it is, with no link', function (string $visibility, bool $siteOnly, string $stored, string $shown, array $lines): void {
        offerActingWith('view', 'publish');
        $entry = offerStored(visibility: $visibility, siteOnly: $siteOnly);
        DB::table('media_files')->where('entry_id', $entry->id)->update(['reader_entitlement' => $stored]);

        expect(offerSection($entry)['media_file_readers']->getState())->toBe($shown)
            ->and(offerLines($entry))->toBe($lines);
    })->with([
        'not a name' => ['private', true, 'Not a name', 'Not offered: what is stored is not an entitlement name. Stop offering clears it.', []],
        'a name, on a public file' => ['public', true, OFFER_NAME, OFFER_NAME, ['media_file_reader_not_served' => 'No reader can download it: only a private file kept to this site is served to readers. Stop offering it.']],
        'a name, on a shared file' => ['private', false, OFFER_NAME, OFFER_NAME, ['media_file_reader_not_served' => 'No reader can download it: only a private file kept to this site is served to readers. Stop offering it.']],
    ]);

    /* S5b. "Stop offering" is said only to whoever has it: anyone else is told who can. */
    it('names who may stop offering to whoever may not publish the type, and tells no one else to', function (array $grants): void {
        offerActingWith(...$grants);
        $malformed = offerStored('Malformed');
        $public = offerStored('Public', visibility: 'public');
        DB::table('media_files')->where('entry_id', $malformed->id)->update(['reader_entitlement' => 'Not a name']);
        DB::table('media_files')->where('entry_id', $public->id)->update(['reader_entitlement' => OFFER_NAME]);

        $state = offerSection($malformed)['media_file_readers']->getState();
        $note = offerLines($public)['media_file_reader_not_served'];

        expect($state)->toBe('Not offered: what is stored is not an entitlement name. Whoever may publish this type can clear it with Stop offering.')
            ->and($note)->toBe('No reader can download it: only a private file kept to this site is served to readers. Whoever may publish this type can stop offering it.')
            ->and(offerAction(MediaReaderActions::stop(), $malformed)->isAuthorized())->toBeFalse();
    })->with([
        'view alone' => [['view']],
        'update' => [['view', 'update']],
        'no grant at all' => [[]],
    ]);

    /* S6. The same lines for whoever may view the entry; the actions for whoever may publish its type alone. */
    it('shows the same lines to whoever may view the entry, and the actions only to whoever may publish', function (): void {
        $entry = offerStored();
        offerAsSystem($entry);

        offerActingWith('view', 'publish');
        $publisher = offerLines($entry);
        $mayChange = offerAction(MediaReaderActions::change(), $entry)->isAuthorized();

        offerActingWith('view');
        $viewer = offerLines($entry);
        $viewerMayChange = offerAction(MediaReaderActions::change(), $entry)->isAuthorized();

        expect($viewer)->toBe($publisher)
            ->and($viewer)->toHaveKey('media_file_reader_link')
            ->and($mayChange)->toBeTrue()
            ->and($viewerMayChange)->toBeFalse();
    });
});
