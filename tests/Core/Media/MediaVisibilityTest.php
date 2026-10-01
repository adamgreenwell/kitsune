<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Auth\Permissions;
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
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\AuditorStandIn;
use Kitsune\Core\Tests\Fixtures\LocatedJpeg;
use Kitsune\Core\Tests\Fixtures\RefusingDisk;
use Kitsune\Core\Tests\Fixtures\TestUser;
use League\Flysystem\Filesystem;

/*
 * A stored file made public or private — Adam, ADR-042 decision 32 — and a JPEG made public without its location
 * (decision 30's made-public half).
 *
 * ⚠️ FROM THE DISKS, THE ROWS AND THE LOG AS THEY ARE AFTERWARDS. Every case reads what each disk holds at the path, by
 * its bytes, what the row records, and what the audit log says — so a switch that reported success and did something
 * else cannot pass. Every disk is a `RefusingDisk`, so a failure is one the test chose, at the operation it chose.
 */

const VISIBILITY_PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\x0f\0\0\x01\x01\0\x05\x18\xd8N\0\0\0\0IEND\xaeB`\x82";

beforeEach(function (): void {
    $this->roots = [];
    $this->disks = [];
    $this->disk = function (string $name, array $config = []): RefusingDisk {
        $root = sys_get_temp_dir().'/kitsune-vistest-'.$name.'-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        $this->roots[] = $root;

        if ($config !== []) {
            config(["filesystems.disks.{$name}" => ['driver' => 'local', 'root' => $root, ...$config]]);
        }

        return $this->disks[$name] = RefusingDisk::install($name, $root);
    };

    ($this->disk)('public');
    ($this->disk)(MediaDisks::PRIVATE);

    $this->org = Org::create(['slug' => 'visibility', 'name' => 'Visibility']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'visibility-main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);
    $this->image = EntryType::create(['org_id' => $this->org->id, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);
});

afterEach(function (): void {
    app(Context::class)->forget();

    foreach ($this->roots as $root) {
        exec('chmod -R u+rwx '.escapeshellarg($root).' 2>/dev/null; rm -rf '.escapeshellarg($root));
    }
});

/** A stored file, and the disk log emptied after it. */
function visStored(string $bytes, string $name = 'photo.jpg', string $visibility = 'private', bool $siteOnly = false): Entry
{
    $source = LocatedJpeg::file($bytes, 'kitsune-vis-source-');

    try {
        $entry = MediaLibrary::store($source, $name, test()->image, $visibility, title: pathinfo($name, PATHINFO_FILENAME), siteOnly: $siteOnly);
    } finally {
        unlink($source);
    }

    RefusingDisk::forgetLog();

    return $entry;
}

/** The file row, as the database holds it. @return array<string, mixed> */
function visRow(Entry $entry): array
{
    return (array) DB::table('media_files')->where('entry_id', $entry->id)->first();
}

/** What each disk holds at the path — its bytes, or null. @return array<string, ?string> */
function visBytes(string $path, array $disks = ['public', MediaDisks::PRIVATE]): array
{
    $held = [];

    foreach ($disks as $disk) {
        $file = Storage::disk($disk)->path($path);
        $held[$disk] = is_file($file) ? (string) file_get_contents($file) : null;
    }

    return $held;
}

/** Every file under each disk's root, with its hash — a refusal must leave each exactly as it was. @return array<string, string> */
function visDisks(): array
{
    $all = [];

    foreach (test()->disks as $name => $disk) {
        $root = $disk->root();
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->isFile()) {
                $all[$name.':'.substr($file->getPathname(), strlen($root) + 1)] = hash_file('sha256', $file->getPathname());
            }
        }
    }

    ksort($all);

    return $all;
}

/** The actions audited against the entry, oldest first. @return list<string> */
function visAudits(Entry $entry): array
{
    return DB::table('audit_log')->where('target_type', Entry::class)->where('target_id', (string) $entry->id)
        ->orderBy('id')->pluck('action')->all();
}

/** @return list<string> "event disk:path" for each operation that changed bytes */
function visWrites(): array
{
    return array_values(array_map(
        static fn (array $entry): string => $entry['event'].' '.$entry['disk'].':'.$entry['path'],
        array_filter(RefusingDisk::$log, static fn (array $entry): bool => $entry['bytes']),
    ));
}

/** The temporaries the switch writes the original into: none may outlive it. @return list<string> */
function visTemporaries(): array
{
    return glob(sys_get_temp_dir().'/kitsune-visibility-*') ?: [];
}

/**
 * Note the switch's own commit on the disks' timeline: an after-commit callback registered inside its transaction, before
 * the publication it registers — Laravel runs them in order, and fires `TransactionCommitted` only after all of them.
 */
function visMarkCommit(): void
{
    $marked = false;

    AuditorStandIn::install()->beforeRecording(static function () use (&$marked): void {
        if (! $marked) {
            $marked = true;
            MediaCustody::whenOutermost(DB::connection(), static fn () => RefusingDisk::note('committed'));
        }
    });
}

/** The index of the commit and of the first byte operation on the timeline. @return array{0: int|false, 1: int|false} */
function visCommitThenBytes(): array
{
    return [
        array_search('committed', array_column(RefusingDisk::$log, 'event'), true),
        array_search(true, array_column(RefusingDisk::$log, 'bytes'), true),
    ];
}

/** Run a switch expected to be refused, and return the refusal. */
function visRefusedBy(Closure $switch): Throwable
{
    try {
        $switch();
    } catch (MediaVisibilityRefused|MediaWithdrawalRefused|MediaRefused $refused) {
        return $refused;
    }

    throw new RuntimeException('The switch was not refused.');
}

/** A user holding these actions on `image`, acting. */
function visActingWith(string ...$actions): TestUser
{
    config(['auth.providers.users.model' => TestUser::class]);
    /** @var TestUser $user */
    $user = TestUser::create(['email' => 'switcher-'.bin2hex(random_bytes(3)).'@kitsune.test']);
    DB::table('org_user')->insert(['org_id' => test()->org->getKey(), 'user_id' => $user->getKey()]);
    $role = Role::create(['handle' => 'switcher-'.bin2hex(random_bytes(3)), 'name' => 'Switcher']);
    DB::table('role_user')->insert(['role_id' => $role->getKey(), 'user_id' => $user->getKey()]);

    foreach ($actions as $action) {
        $role->grant(str_contains($action, '.') ? $action : Permissions::forEntryType('image', $action));
    }

    test()->actingAs($user);

    return $user;
}

describe('making a JPEG public', function (): void {
    /* V1. The located photo, stored private, is published without its location — and the row describes what is served. */
    it('publishes a located JPEG without its GPS data, its row describing the bytes it serves', function (): void {
        $upload = LocatedJpeg::photo(true);
        $entry = visStored($upload);
        $before = visRow($entry);
        $updatedAt = DB::table('entries')->where('id', $entry->id)->value('updated_at');
        $revisions = DB::table('entry_revisions')->where('entry_id', $entry->id)->count();

        expect(MediaVisibility::makePublic($entry))->toBe(MediaVisibility::SWITCHED);

        $row = visRow($entry);
        ['public' => $served, MediaDisks::PRIVATE => $private] = visBytes($row['path']);

        expect($served)->not->toBeNull()
            ->and(LocatedJpeg::sentinels($served))->toBe([])
            ->and(LocatedJpeg::gpsPointers($served))->toBe(0)
            ->and(LocatedJpeg::orientationOf($served))->toBe(6)
            ->and($served)->toContain('TRAILER-KEPT')->toContain('Kept City')->toContain('hdrgm:Version="1.0"')
            ->and(strlen($served))->toBe(strlen($upload))
            ->and($private)->toBeNull()
            ->and($row['visibility'])->toBe('public')
            ->and($row['disk'])->toBe('public')
            ->and($row['checksum'])->toBe(hash('sha256', $served))
            ->and((int) $row['size_bytes'])->toBe(strlen($served))
            ->and($row['checksum'])->not->toBe($before['checksum'])
            ->and([$row['mime'], $row['width'], $row['height'], $row['path']])->toBe([$before['mime'], $before['width'], $before['height'], $before['path']])
            ->and(visAudits($entry))->toBe(['entry.created', MediaVisibility::MADE_PUBLIC])
            ->and(DB::table('entries')->where('id', $entry->id)->value('updated_at'))->toBe($updatedAt)
            ->and(DB::table('entry_revisions')->where('entry_id', $entry->id)->count())->toBe($revisions)
            ->and(visTemporaries())->toBe([]);

        // The picture itself is the one uploaded, byte for byte, wherever it is in the file.
        for ($at = strpos($upload, LocatedJpeg::body()); $at !== false; $at = strpos($upload, LocatedJpeg::body(), $at + 1)) {
            expect(substr($served, $at, strlen(LocatedJpeg::body())))->toBe(LocatedJpeg::body());
        }
    });

    /* No byte reaches a disk the web serves before the commit that makes the file public; the private disk is the only one written before it. */
    it('writes the private disk alone before the commit, and the web\'s disk only after', function (): void {
        $entry = visStored(LocatedJpeg::photo(true));
        visMarkCommit();

        MediaVisibility::makePublic($entry);

        [$committed] = visCommitThenBytes();
        $disks = static fn (array $ops): array => array_values(array_unique(array_column(array_filter($ops, static fn (array $op): bool => $op['bytes']), 'disk')));

        expect($committed)->toBeInt()
            ->and($disks(array_slice(RefusingDisk::$log, 0, (int) $committed)))->toBe([MediaDisks::PRIVATE])
            ->and(in_array('public', $disks(array_slice(RefusingDisk::$log, (int) $committed)), true))->toBeTrue();
    });

    /* V2. A PNG has nothing stripped: its row keeps its checksum, and no byte moves before the commit. */
    it('publishes a PNG as a restore publishes it: its checksum as it was, and nothing written before the commit', function (): void {
        $entry = visStored(VISIBILITY_PNG, 'logo.png');
        $before = visRow($entry);
        visMarkCommit();

        MediaVisibility::makePublic($entry);

        $row = visRow($entry);
        [$committed, $firstWrite] = visCommitThenBytes();

        expect([$row['checksum'], (int) $row['size_bytes']])->toBe([$before['checksum'], (int) $before['size_bytes']])
            ->and(visBytes($row['path']))->toBe(['public' => VISIBILITY_PNG, MediaDisks::PRIVATE => null])
            ->and($row['disk'])->toBe('public')
            ->and($committed)->toBeInt()
            ->and($firstWrite)->toBeGreaterThan($committed);
    });

    /* V3. A JPEG with nothing to remove is published as it is: no copy is rewritten, and its checksum stays. */
    it('rewrites nothing for a JPEG with no location, and keeps its checksum', function (): void {
        $entry = visStored(LocatedJpeg::base(), 'plain.jpg');
        $before = visRow($entry);
        visMarkCommit();

        MediaVisibility::makePublic($entry);

        [$committed, $firstWrite] = visCommitThenBytes();

        expect($committed)->toBeInt()
            ->and($firstWrite)->toBeGreaterThan($committed)
            ->and(visRow($entry)['checksum'])->toBe($before['checksum'])
            ->and(visBytes($before['path'])['public'])->toBe(LocatedJpeg::base());
    });

    /* V4. Already public: nothing written, nothing audited. */
    it('leaves a file already public as it is, unaudited', function (): void {
        $entry = visStored(LocatedJpeg::photo(true), visibility: 'public');
        $before = visRow($entry);
        $held = visDisks();

        expect(MediaVisibility::makePublic($entry))->toBe(MediaVisibility::UNCHANGED)
            ->and(visRow($entry))->toBe($before)
            ->and(visDisks())->toBe($held)
            ->and(visWrites())->toBe([])
            ->and(visAudits($entry))->toBe(['entry.created']);
    });

    /* V5, V7. Public, private, public again: stripped once, and the same file each time after. */
    it('keeps a JPEG stripped when it is made private, and makes it public again with the same checksum', function (): void {
        $entry = visStored(LocatedJpeg::photo(false));
        MediaVisibility::makePublic($entry);
        $published = visRow($entry);

        MediaVisibility::makePrivate($entry);
        $private = visRow($entry);
        $held = visBytes($private['path']);

        RefusingDisk::forgetLog();
        MediaVisibility::makePublic($entry);

        $privateWrites = array_values(array_filter(visWrites(), static fn (string $write): bool => str_contains($write, MediaDisks::PRIVATE.':') && ! str_starts_with($write, 'delete')));

        expect($private['checksum'])->toBe($published['checksum'])
            ->and($held[MediaDisks::PRIVATE])->not->toBeNull()
            ->and(hash('sha256', (string) $held[MediaDisks::PRIVATE]))->toBe($published['checksum'])
            ->and(LocatedJpeg::sentinels((string) $held[MediaDisks::PRIVATE]))->toBe([])
            ->and(visRow($entry)['checksum'])->toBe($published['checksum'])
            ->and($privateWrites)->toBe([]);
    });

    /* V11. A private file already stripped while its row recorded the original — a switch stopped after its rename — is
       recorded as it is and published, the copy its row names adopted. */
    it('records and publishes a stripped copy its row does not describe, the copy its row names', function (): void {
        $entry = visStored(LocatedJpeg::photo(true));
        $row = visRow($entry);
        $stripped = LocatedJpeg::file(LocatedJpeg::photo(true));
        $copy = JpegLocation::strippedCopy($stripped);
        unlink($stripped);
        copy((string) $copy, Storage::disk(MediaDisks::PRIVATE)->path($row['path']));
        $x = hash_file('sha256', (string) $copy);
        unlink((string) $copy);
        Log::spy();

        MediaVisibility::makePublic($entry);

        expect(visRow($entry)['checksum'])->toBe($x)
            ->and(hash('sha256', (string) visBytes($row['path'])['public']))->toBe($x);

        Log::shouldHaveReceived('warning')->withArgs(static fn (string $message): bool => str_contains($message, 'the disk the row names'))->once();
        // Nothing was stripped this time, so no line says it was.
        Log::shouldNotHaveReceived('info');
    });

    /* V16. A strip is logged with both checksums; one with nothing to strip is not. */
    it('logs the checksum a strip replaced', function (): void {
        $entry = visStored(LocatedJpeg::photo(true));
        $before = visRow($entry)['checksum'];
        Log::spy();

        MediaVisibility::makePublic($entry);

        $after = visRow($entry)['checksum'];

        Log::shouldHaveReceived('info')->withArgs(static fn (string $message): bool => str_contains($message, "is now [{$after}] where it was [{$before}]"))->once();
    });

    /* V17. A row written past the model — a capitalised extension, a JPEG under another name — is still stripped. */
    it('strips a JPEG whatever its row calls it', function (string $path, string $mime): void {
        $entry = visStored(LocatedJpeg::photo(true));
        $row = visRow($entry);
        @mkdir(dirname(Storage::disk(MediaDisks::PRIVATE)->path($path)), 0777, true);
        rename(Storage::disk(MediaDisks::PRIVATE)->path($row['path']), Storage::disk(MediaDisks::PRIVATE)->path($path));
        DB::table('media_files')->where('entry_id', $entry->id)->update(['path' => $path, 'mime' => $mime]);

        MediaVisibility::makePublic($entry);

        expect(LocatedJpeg::sentinels((string) visBytes($path)['public']))->toBe([]);
    })->with([
        'a capitalised extension' => ['media/x/PHOTO.JPG', 'image/jpeg'],
        'a JPEG under a PNG\'s name' => ['media/x/photo.png', 'image/jpeg'],
        'a JPEG\'s extension, its type left out' => ['media/x/photo.Jpeg', ''],
    ]);
});

describe('making a file private', function (): void {
    /* V6. Withdrawn from every disk the web serves, before the commit, its row and checksum as they were. */
    it('withdraws a public file from every served disk, its checksum and size kept, and audits it', function (): void {
        $cdn = ($this->disk)('old-cdn', ['url' => 'https://cdn.example.test']);
        $entry = visStored(VISIBILITY_PNG, 'logo.png', 'public');
        $before = visRow($entry);
        mkdir(dirname($cdn->root().'/'.$before['path']), 0777, true);
        file_put_contents($cdn->root().'/'.$before['path'], VISIBILITY_PNG);
        file_put_contents(Storage::disk('public')->path($before['path']).'.kitsune-partial', 'part');

        expect(MediaVisibility::makePrivate($entry))->toBe(MediaVisibility::SWITCHED);

        $row = visRow($entry);

        expect(visBytes($row['path'], ['public', MediaDisks::PRIVATE, 'old-cdn']))->toBe(['public' => null, MediaDisks::PRIVATE => VISIBILITY_PNG, 'old-cdn' => null])
            ->and(is_file(Storage::disk('public')->path($before['path']).'.kitsune-partial'))->toBeFalse()
            ->and([$row['visibility'], $row['disk']])->toBe(['private', MediaDisks::PRIVATE])
            ->and([$row['checksum'], $row['size_bytes']])->toBe([$before['checksum'], $before['size_bytes']])
            ->and(visAudits($entry))->toBe(['entry.created', MediaVisibility::MADE_PRIVATE]);
    });

    it('leaves a file already private as it is, unaudited', function (): void {
        $entry = visStored(VISIBILITY_PNG, 'logo.png');
        $before = visRow($entry);

        expect(MediaVisibility::makePrivate($entry))->toBe(MediaVisibility::UNCHANGED)
            ->and(visRow($entry))->toBe($before)
            ->and(visWrites())->toBe([])
            ->and(visAudits($entry))->toBe(['entry.created']);
    });

    /* A copy that cannot be removed refuses the switch in the trash's words — "make entry N private" — and puts back what
       moved once nothing is left to commit. */
    it('refuses in the trash\'s words when a served copy cannot be removed, and leaves the file public', function (): void {
        $entry = visStored(VISIBILITY_PNG, 'logo.png', 'public');
        $before = visRow($entry);
        $this->disks['public']->failDeletes = true;

        $refused = visRefusedBy(fn () => MediaVisibility::makePrivate($entry));

        expect($refused)->toBeInstanceOf(MediaWithdrawalRefused::class)
            ->and($refused->getMessage())->toStartWith("Refusing to make entry {$entry->id} private: its file could not be withdrawn from the web")
            ->and(visRow($entry))->toBe($before)
            ->and(visBytes($before['path'])['public'])->toBe(VISIBILITY_PNG)
            ->and(visAudits($entry))->toBe(['entry.created']);

        $this->disks['public']->failDeletes = false;
        MediaCustody::drain(DB::connection());

        expect(visBytes($before['path']))->toBe(['public' => VISIBILITY_PNG, MediaDisks::PRIVATE => null]);
    });
});

describe('what is refused', function (): void {
    /* V8. Each refusal leaves the row, the disks and the log as they were, and no temporary behind. */
    it('refuses, changing nothing', function (Closure $arrange, string $to, string $reason, string $words): void {
        $entry = $arrange->call(test());
        $before = visRow($entry);
        $held = visDisks();
        $audits = DB::table('audit_log')->count();

        $refused = visRefusedBy(fn () => $to === 'public' ? MediaVisibility::makePublic($entry) : MediaVisibility::makePrivate($entry));

        // The strip's own refusal names the file, as the editor knows it; every other names the entry.
        expect($refused->getMessage())->toStartWith($reason === '' ? 'Refusing to make [shared] public: ' : "Refusing to make entry {$entry->id} {$to}: ")
            ->toContain($words)
            ->and($refused instanceof MediaVisibilityRefused ? $refused->reason : '')->toBe($reason)
            ->and(visRow($entry))->toBe($before)
            ->and(visDisks())->toBe($held)
            ->and(DB::table('audit_log')->count())->toBe($audits)
            ->and(visTemporaries())->toBe([]);
    })->with([
        'trashed, made public' => [function (): Entry {
            $entry = visStored(LocatedJpeg::photo(true));
            $entry->delete();
            RefusingDisk::forgetLog();

            return Entry::withTrashed()->findOrFail($entry->id);
        }, 'public', MediaVisibilityRefused::TRASHED, 'Restore it first'],
        'trashed, made private' => [function (): Entry {
            $entry = visStored(VISIBILITY_PNG, 'logo.png', 'public');
            $entry->delete();

            return Entry::withTrashed()->findOrFail($entry->id);
        }, 'private', MediaVisibilityRefused::TRASHED, 'Restore it first'],
        'no file' => [function (): Entry {
            $entry = visStored(VISIBILITY_PNG, 'logo.png');
            DB::table('media_files')->where('entry_id', $entry->id)->delete();

            return $entry;
        }, 'public', MediaVisibilityRefused::NO_FILE, 'no file is recorded'],
        'gone' => [function (): Entry {
            $entry = visStored(VISIBILITY_PNG, 'logo.png');
            DB::table('media_files')->where('entry_id', $entry->id)->delete();
            DB::table('entries')->where('id', $entry->id)->delete();

            return $entry;
        }, 'public', MediaVisibilityRefused::GONE, 'it no longer exists'],
        'unsafe disks' => [function (): Entry {
            $entry = visStored(LocatedJpeg::photo(true));
            config(['kitsune.media.disks.public' => MediaDisks::PRIVATE]);

            return $entry;
        }, 'public', MediaVisibilityRefused::UNSAFE_DISKS, 'cannot keep a private file off the web'],
        'misnamed' => [function (): Entry {
            $entry = visStored(LocatedJpeg::photo(true));
            DB::table('media_files')->where('entry_id', $entry->id)->update(['path' => 'media//x.jpg']);

            return $entry;
        }, 'public', MediaVisibilityRefused::MISNAMED, 'not written as the disks read it'],
        'unsettled: the row names another disk' => [function (): Entry {
            $entry = visStored(LocatedJpeg::photo(true));
            $moved = ($this->disk)('moved-private', ['visibility' => 'private']);
            config(['kitsune.media.disks.private' => 'moved-private']);
            RefusingDisk::forgetLog();

            return $entry;
        }, 'public', MediaVisibilityRefused::UNSETTLED, 'kitsune:media-reconcile --entry='],
        'exposed: a copy on the public disk' => [function (): Entry {
            $entry = visStored(LocatedJpeg::photo(true));
            $path = visRow($entry)['path'];
            Storage::disk('public')->put($path, LocatedJpeg::photo(true));

            return $entry;
        }, 'public', MediaVisibilityRefused::EXPOSED, 'a disk the web serves'],
        'exposed: a partial copy on the public disk' => [function (): Entry {
            $entry = visStored(LocatedJpeg::photo(true));
            Storage::disk('public')->put(visRow($entry)['path'].'.kitsune-partial', 'part');

            return $entry;
        }, 'public', MediaVisibilityRefused::EXPOSED, 'a disk the web serves'],
        'stray: a copy on core\'s private disk after the private disk moved' => [function (): Entry {
            $entry = visStored(LocatedJpeg::photo(true));
            $row = visRow($entry);
            $moved = ($this->disk)('moved-private', ['visibility' => 'private']);
            config(['kitsune.media.disks.private' => 'moved-private']);
            Storage::disk('moved-private')->put($row['path'], LocatedJpeg::photo(true));
            DB::table('media_files')->where('entry_id', $entry->id)->update(['disk' => 'moved-private']);

            return $entry;
        }, 'public', MediaVisibilityRefused::STRAY, 'kitsune:media-prune'],
        'missing' => [function (): Entry {
            $entry = visStored(LocatedJpeg::photo(true));
            unlink(Storage::disk(MediaDisks::PRIVATE)->path(visRow($entry)['path']));

            return $entry;
        }, 'public', MediaVisibilityRefused::MISSING, 'holds no copy of its file'],
        'unreadable' => [function (): Entry {
            $entry = visStored(LocatedJpeg::photo(true));
            $private = $this->disks[MediaDisks::PRIVATE];
            $private->unreadable = [visRow($entry)['path']];

            return $entry;
        }, 'public', MediaVisibilityRefused::UNREADABLE, 'could not be read'],
        'the strip refused' => [function (): Entry {
            return visStored(LocatedJpeg::unremovable(), 'shared.jpg');
        }, 'public', '', 'cannot be removed with certainty'],
    ]);

    /* The private bytes swapped between the keeper's hash and the read: the copy read is proved, or nothing is made of it. */
    it('refuses a copy that changes between its hash and its read', function (): void {
        $entry = visStored(LocatedJpeg::photo(true));
        $before = visRow($entry);
        $path = $before['path'];
        $swapped = false;

        // Whichever operation the read is, the file is something else by the time it is opened.
        for ($n = 1; $n <= 12; $n++) {
            $this->disks[MediaDisks::PRIVATE]->onOperation($n, static function (string $event) use (&$swapped, $path): void {
                if ($event === 'readStream' && ! $swapped) {
                    $swapped = true;
                    file_put_contents(Storage::disk(MediaDisks::PRIVATE)->path($path), LocatedJpeg::photo(false));
                }
            }, 'any');
        }

        $refused = visRefusedBy(fn () => MediaVisibility::makePublic($entry));

        expect($swapped)->toBeTrue()
            ->and($refused)->toBeInstanceOf(MediaVisibilityRefused::class)
            ->and($refused->reason)->toBe(MediaVisibilityRefused::CHANGED)
            ->and(visRow($entry))->toBe($before)
            ->and(visBytes($path)['public'])->toBeNull()
            ->and(visWrites())->toBe([])
            ->and(visTemporaries())->toBe([]);
    });

    /* A served disk whose root cannot be looked at: whether it holds an original cannot be told. */
    it('refuses while a served disk\'s root cannot be looked at', function (): void {
        $base = sys_get_temp_dir().'/kitsune-vistest-locked-'.bin2hex(random_bytes(4));
        mkdir($base.'/locked/cdn', 0777, true);
        $this->roots[] = $base;
        config(['filesystems.disks.locked-cdn' => ['driver' => 'local', 'root' => $base.'/locked/cdn', 'url' => 'https://locked.example.test']]);
        $entry = visStored(LocatedJpeg::photo(true));
        chmod($base.'/locked', 0);

        if (@stat($base.'/locked/cdn') !== false) {
            chmod($base.'/locked', 0755);
            test()->markTestSkipped('this user reads past a directory it may not search (root)');
        }

        try {
            $refused = visRefusedBy(fn () => MediaVisibility::makePublic($entry));
        } finally {
            chmod($base.'/locked', 0755);
        }

        expect($refused->reason)->toBe(MediaVisibilityRefused::UNKNOWN_ROOT)
            ->and(visRow($entry)['visibility'])->toBe('private');
    });

    /* V9. The audit fails: nothing written to any disk, and the row as it was. */
    it('writes no byte when the audit fails, the bytes being the last step', function (): void {
        $entry = visStored(LocatedJpeg::photo(true));
        $before = visRow($entry);
        $original = visBytes($before['path'])[MediaDisks::PRIVATE];
        AuditorStandIn::install()->throwOnce(new RuntimeException('the audit failed'));

        expect(fn () => MediaVisibility::makePublic($entry))->toThrow(RuntimeException::class, 'the audit failed');

        expect(visWrites())->toBe([])
            ->and(visRow($entry))->toBe($before)
            ->and(visBytes($before['path']))->toBe(['public' => null, MediaDisks::PRIVATE => $original])
            ->and(visTemporaries())->toBe([]);
    });

    /* COPY_FAILED on a local disk: the partial is discarded, the original never touched. */
    it('leaves the original in place when the stripped copy cannot be written', function (string $failure): void {
        $entry = visStored(LocatedJpeg::photo(true));
        $before = visRow($entry);
        $original = visBytes($before['path'])[MediaDisks::PRIVATE];
        match ($failure) {
            'write' => $this->disks[MediaDisks::PRIVATE]->failWrites = true,
            'truncate' => $this->disks[MediaDisks::PRIVATE]->truncateWritesTo = 10,
            'rename' => $this->disks[MediaDisks::PRIVATE]->failMoves = true,
        };

        $refused = visRefusedBy(fn () => MediaVisibility::makePublic($entry));

        expect($refused->reason)->toBe(MediaVisibilityRefused::COPY_FAILED)
            ->and($refused->getMessage())->toContain('so it stays private')
            ->and(visRow($entry))->toBe($before)
            ->and(visBytes($before['path']))->toBe(['public' => null, MediaDisks::PRIVATE => $original])
            ->and(is_file(Storage::disk(MediaDisks::PRIVATE)->path($before['path']).'.kitsune-partial'))->toBeFalse()
            ->and(visAudits($entry))->toBe(['entry.created']);
    })->with(['write', 'truncate', 'rename']);

    /* V10. On an object store the PUT is the file: one that does not read back is never discarded — that would delete the
       only copy — and the original is written back over it. */
    it('writes the original back over a stripped copy an object store did not keep whole', function (bool $putBack): void {
        $entry = visStored(LocatedJpeg::photo(true));
        $before = visRow($entry);
        $original = visBytes($before['path'])[MediaDisks::PRIVATE];
        $private = $this->disks[MediaDisks::PRIVATE];
        Storage::set(MediaDisks::PRIVATE, new FilesystemAdapter(new Filesystem($private), $private, ['driver' => 's3']));
        Log::spy();
        // The stripped copy's PUT keeps ten bytes; the put-back's is whole — or fails too.
        $private->truncateWritesTo = 10;
        $private->onOperation(2, static function () use ($private, $putBack): void {
            $private->truncateWritesTo = null;
            $private->failWrites = ! $putBack;
        });

        $refused = visRefusedBy(fn () => MediaVisibility::makePublic($entry));

        $deletes = array_filter(RefusingDisk::$log, static fn (array $op): bool => $op['event'] === 'delete' && $op['disk'] === MediaDisks::PRIVATE);

        expect($refused->reason)->toBe(MediaVisibilityRefused::COPY_FAILED)
            ->and(visRow($entry))->toBe($before)
            ->and($deletes)->toBe([]);

        if ($putBack) {
            expect(file_get_contents($private->root().'/'.$before['path']))->toBe($original);
        } else {
            // Left as the failed PUT left it, and said so with the checksum the row still records.
            expect(strlen((string) file_get_contents($private->root().'/'.$before['path'])))->toBe(10);
            Log::shouldHaveReceived('warning')->withArgs(static fn (string $message): bool => str_contains($message, 'could not be written back')
                && str_contains($message, (string) $before['checksum']))->once();
        }
    })->with(['written back' => true, 'the write-back failing too' => false]);
});

describe('a transaction', function (): void {
    /* V12. Making a file public refuses inside one, with nothing done. */
    it('refuses to make a file public inside an open transaction', function (): void {
        $entry = visStored(LocatedJpeg::photo(true));
        $before = visRow($entry);

        expect(fn () => DB::transaction(fn () => MediaVisibility::makePublic($entry)))
            ->toThrow(LogicException::class, 'inside an open transaction');

        expect(visWrites())->toBe([])
            ->and(visRow($entry))->toBe($before);
    });

    /* ...and making one private nests, as a trash does: committed with it, put back when it rolls back. */
    it('makes a file private inside a host\'s transaction, and puts it back when that rolls back', function (bool $commits): void {
        $entry = visStored(VISIBILITY_PNG, 'logo.png', 'public');
        $path = visRow($entry)['path'];

        try {
            DB::transaction(function () use ($entry, $commits): void {
                MediaVisibility::makePrivate($entry);

                if (! $commits) {
                    throw new RuntimeException('the host rolled back');
                }
            });
        } catch (RuntimeException) {
        }

        expect(visRow($entry)['visibility'])->toBe($commits ? 'private' : 'public')
            ->and(visBytes($path))->toBe($commits
                ? ['public' => null, MediaDisks::PRIVATE => VISIBILITY_PNG]
                : ['public' => VISIBILITY_PNG, MediaDisks::PRIVATE => null]);
    })->with(['committed' => true, 'rolled back' => false]);
});

describe('after the commit', function (): void {
    /* V13. Publication fails after the commit: the stripped copy waits on the private disk, and reconcile publishes it. */
    it('leaves a JPEG awaiting publication when the public disk refuses it, and reconcile publishes the stripped copy', function (): void {
        $entry = visStored(LocatedJpeg::photo(true));
        $path = visRow($entry)['path'];
        $this->disks['public']->failWrites = true;

        expect(MediaVisibility::makePublic($entry))->toBe(MediaVisibility::SWITCHED);

        $row = visRow($entry);

        expect([$row['visibility'], $row['disk']])->toBe(['public', MediaDisks::PRIVATE])
            ->and(visBytes($path)['public'])->toBeNull()
            ->and(hash('sha256', (string) visBytes($path)[MediaDisks::PRIVATE]))->toBe($row['checksum']);

        $this->disks['public']->failWrites = false;
        Artisan::call('kitsune:media-reconcile', ['--force' => true, '--entry' => [$entry->id]]);

        $served = (string) visBytes($path)['public'];

        expect(hash('sha256', $served))->toBe($row['checksum'])
            ->and(LocatedJpeg::sentinels($served))->toBe([])
            ->and(visRow($entry)['disk'])->toBe('public');
    });

    /* V14. The private copy cannot be removed after publication: published all the same, and prune removes it later. */
    it('publishes all the same when the private copy cannot be removed, for prune to remove', function (): void {
        $entry = visStored(LocatedJpeg::photo(true));
        $path = visRow($entry)['path'];
        $private = $this->disks[MediaDisks::PRIVATE];
        // The private disk's deletes fail from after the stripped copy is renamed into place.
        $private->onOperation(2, static function () use ($private): void {
            $private->failDeletes = true;
        });

        MediaVisibility::makePublic($entry);

        expect(visRow($entry)['disk'])->toBe('public')
            ->and(visBytes($path)[MediaDisks::PRIVATE])->not->toBeNull();

        $private->failDeletes = false;
        Artisan::call('kitsune:media-prune', ['--force' => true]);

        expect(visBytes($path)[MediaDisks::PRIVATE])->toBeNull()
            ->and(LocatedJpeg::sentinels((string) visBytes($path)['public']))->toBe([]);
    });

    /* V15. Made private before its publication: the publication finds a private row and moves nothing. */
    it('publishes nothing when the file was made private before its publication ran', function (): void {
        $entry = visStored(LocatedJpeg::photo(true));
        $path = visRow($entry)['path'];
        $this->disks['public']->failWrites = true;
        MediaVisibility::makePublic($entry);
        $this->disks['public']->failWrites = false;

        MediaVisibility::makePrivate($entry);
        MediaCustody::publish(DB::connection(), [$entry->id]);

        expect(visRow($entry)['visibility'])->toBe('private')
            ->and(visBytes($path)['public'])->toBeNull();
    });

    it('publishes nothing when the entry was trashed before its publication ran', function (): void {
        $entry = visStored(LocatedJpeg::photo(true));
        $path = visRow($entry)['path'];
        $this->disks['public']->failWrites = true;
        MediaVisibility::makePublic($entry);
        $this->disks['public']->failWrites = false;

        $entry->delete();
        MediaCustody::publish(DB::connection(), [$entry->id]);

        expect(visBytes($path)['public'])->toBeNull();
    });
});

describe('who may', function (): void {
    /* P1. Whoever may publish the type, both ways; update or view alone may not. */
    it('lets whoever may publish switch a file, and nobody else', function (array $grants, bool $may): void {
        $entry = visStored(VISIBILITY_PNG, 'logo.png');
        visActingWith(...$grants);

        if (! $may) {
            $refused = visRefusedBy(fn () => MediaVisibility::makePublic($entry));

            expect($refused->reason)->toBe(MediaVisibilityRefused::NOT_PERMITTED)
                ->and($refused->getMessage())->toContain('[entry.image.publish]')
                ->and(visRow($entry)['visibility'])->toBe('private');

            return;
        }

        MediaVisibility::makePublic($entry);
        MediaVisibility::makePrivate($entry);

        expect(visRow($entry)['visibility'])->toBe('private')
            ->and(visAudits($entry))->toBe(['entry.created', MediaVisibility::MADE_PUBLIC, MediaVisibility::MADE_PRIVATE]);
    })->with([
        'publish' => [['view', 'publish'], true],
        'every type\'s publish' => [['entry.*.publish'], true],
        'update alone' => [['view', 'update'], false],
        'view alone' => [['view'], false],
    ]);

    it('lets an owner switch a file, and the system acting on its own, unattributed', function (): void {
        $entry = visStored(VISIBILITY_PNG, 'logo.png');

        MediaVisibility::makePublic($entry);

        expect(DB::table('audit_log')->where('action', MediaVisibility::MADE_PUBLIC)->value('actor_id'))->toBeNull();

        config(['auth.providers.users.model' => TestUser::class]);
        $owner = TestUser::create(['email' => 'owner@kitsune.test']);
        DB::table('org_user')->insert(['org_id' => $this->org->getKey(), 'user_id' => $owner->getKey()]);
        Role::create(['handle' => 'owner', 'name' => 'Owner', 'is_owner' => true])->assignTo($owner->getKey());
        $this->actingAs($owner);

        MediaVisibility::makePrivate($entry);

        expect(DB::table('audit_log')->where('action', MediaVisibility::MADE_PRIVATE)->value('actor_id'))->toBe((string) $owner->getKey());
    });

    /* P2. The stored type decides, not the instance's. */
    it('asks the stored type, not the instance\'s', function (): void {
        EntryType::create(['org_id' => $this->org->id, 'handle' => 'banner', 'name' => 'Banner', 'plural_name' => 'Banners', 'is_media' => true]);
        $entry = visStored(VISIBILITY_PNG, 'logo.png');
        visActingWith('entry.banner.publish');
        $entry->type_handle = 'banner';

        expect(visRefusedBy(fn () => MediaVisibility::makePublic($entry))->reason)->toBe(MediaVisibilityRefused::NOT_PERMITTED);
    });

    /* P3. An instance whose row was retyped underneath it is refused. */
    it('refuses an instance whose row was moved underneath it', function (): void {
        $entry = visStored(VISIBILITY_PNG, 'logo.png');
        $other = EntryType::create(['org_id' => $this->org->id, 'handle' => 'banner', 'name' => 'Banner', 'plural_name' => 'Banners', 'is_media' => true]);
        DB::table('entries')->where('id', $entry->id)->update(['entry_type_id' => $other->id]);

        expect(fn () => MediaVisibility::makePublic($entry))->toThrow(RuntimeException::class, 'Refusing to change the visibility of entry');
        expect(visRow($entry)['visibility'])->toBe('private');
    });

    /* P4, P5. Another site's own file, and another organisation's, are out of scope — decided from the locked row. */
    it('refuses a file outside this site and organisation', function (Closure $arrange): void {
        $entry = $arrange->call(test());

        expect(visRefusedBy(fn () => MediaVisibility::makePublic($entry))->reason)->toBe(MediaVisibilityRefused::OUT_OF_SCOPE)
            ->and(DB::table('media_files')->where('entry_id', $entry->id)->value('visibility'))->toBe('private');
    })->with([
        'another site\'s own' => [function (): Entry {
            $entry = visStored(VISIBILITY_PNG, 'logo.png', siteOnly: true);
            app(Context::class)->setSite(Site::create(['handle' => 'other', 'slug' => 'visibility-other', 'name' => 'Other', 'locale' => 'en']));

            return $entry;
        }],
        'another organisation\'s' => [function (): Entry {
            $entry = visStored(VISIBILITY_PNG, 'logo.png');
            $other = Org::create(['slug' => 'elsewhere', 'name' => 'Elsewhere']);
            app(Context::class)->setOrg($other);
            app(Context::class)->setSite(Site::create(['org_id' => $other->id, 'handle' => 'main', 'slug' => 'elsewhere-main', 'name' => 'Main', 'locale' => 'en']));

            return $entry;
        }],
    ]);

    /* P6. A shared file is switched from any site of its organisation, for every site; the audit names the acting site. */
    it('switches a shared file from another site of its organisation', function (): void {
        $entry = visStored(VISIBILITY_PNG, 'logo.png');
        $other = Site::create(['handle' => 'other', 'slug' => 'visibility-other', 'name' => 'Other', 'locale' => 'en']);
        app(Context::class)->setSite($other);

        MediaVisibility::makePublic($entry);

        expect(visRow($entry)['visibility'])->toBe('public')
            ->and((int) DB::table('audit_log')->where('action', MediaVisibility::MADE_PUBLIC)->value('site_id'))->toBe($other->id);
    });

    /* P7. No organisation: refused before anything is read. */
    it('refuses with no organisation in context, before anything is read', function (): void {
        $entry = visStored(VISIBILITY_PNG, 'logo.png');
        app(Context::class)->forget();
        RefusingDisk::forgetLog();

        expect(fn () => MediaVisibility::makePublic($entry))->toThrow(RuntimeException::class, 'no organisation in context')
            ->and(fn () => MediaVisibility::makePrivate($entry))->toThrow(RuntimeException::class, 'no organisation in context');

        expect(RefusingDisk::$log)->toBe([]);
    });
});

describe('the audit', function (): void {
    /* A1. One row per switch, naming the entry, the actor, the organisation and the site. */
    it('records each switch against the entry, in this organisation and site', function (): void {
        $entry = visStored(VISIBILITY_PNG, 'logo.png');
        $user = visActingWith('view', 'publish');

        MediaVisibility::makePublic($entry);
        MediaVisibility::makePublic($entry);

        $row = (array) DB::table('audit_log')->where('action', MediaVisibility::MADE_PUBLIC)->sole();

        expect([$row['target_type'], $row['target_id'], $row['actor_id'], (int) $row['org_id'], (int) $row['site_id']])
            ->toBe([Entry::class, (string) $entry->id, (string) $user->getKey(), $this->org->id, $this->site->id]);
    });
});
