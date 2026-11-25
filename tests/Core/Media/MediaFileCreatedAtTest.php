<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Media\MediaCustody;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Media\MediaVisibility;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\LocatedJpeg;
use Kitsune\Core\Tests\Fixtures\RefusingDisk;

/*
 * `media_files.created_at` records when the file was stored, and every write after that keeps it.
 *
 * ⚠️ MARIADB BEFORE 10.10 MOVES IT. With `explicit_defaults_for_timestamp=0`, its default until 10.10 and CI's 10.6, the
 * server gives a table's first TIMESTAMP `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`, and this is that column:
 * an UPDATE that leaves it out stores the server's clock in it. Measured on 10.6.28, as the credentials were (ADR-040's
 * *Credential timestamp portability*). The clock is frozen far from the server's, and moved on before each write, so the
 * substitution fails every time rather than when a second ticks over between the insert and the update — which is how
 * #389's run of `MediaOfferTest` first showed it.
 *
 * ⚠️ AND THE SQL, FOR THE ENGINES THAT HAVE NO SUCH CLAUSE. On SQLite, PostgreSQL and MySQL 8.4 a write that left the
 * column out would keep it all the same, so each write is also read from the query log: it names `created_at` as itself.
 */

const STAMP_PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\x0f\0\0\x01\x01\0\x05\x18\xd8N\0\0\0\0IEND\xaeB`\x82";

const STAMP_STORED = '2001-02-03 04:05:06';

beforeEach(function (): void {
    $this->roots = [];

    foreach (['public', MediaDisks::PRIVATE, 'old-cdn'] as $name) {
        $root = sys_get_temp_dir().'/kitsune-stamp-'.$name.'-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        $this->roots[] = $root;
        RefusingDisk::install($name, $root);
    }

    // `old-cdn` was once a public disk: the web serves it, so custody moves a row off it.
    config(['filesystems.disks.old-cdn' => ['driver' => 'local', 'root' => $this->roots[2], 'url' => 'https://cdn.example.test']]);

    $org = Org::create(['slug' => 'stamps', 'name' => 'Stamps']);
    app(Context::class)->setOrg($org);
    app(Context::class)->setSite(Site::create(['handle' => 'main', 'slug' => 'stamps-main', 'name' => 'Main', 'locale' => 'en']));
    $this->type = EntryType::create(['org_id' => $org->id, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);
    app()->instance(EntryType::class, $this->type);

    stampClock(STAMP_STORED);
});

afterEach(function (): void {
    DB::connection()->disableQueryLog();
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
    app(Context::class)->forget();

    foreach ($this->roots as $root) {
        exec('rm -rf '.escapeshellarg($root));
    }
});

function stampClock(string $at): void
{
    Carbon::setTestNow(Carbon::parse($at, 'UTC'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse($at, 'UTC'));
}

/** Stored by the system at the frozen time; the clock then moves on a year, and the query log starts. */
function stampStored(string $visibility = 'private', string $bytes = STAMP_PNG, string $name = 'photo.png'): Entry
{
    $source = LocatedJpeg::file($bytes, 'kitsune-stamp-');

    try {
        $entry = MediaLibrary::store($source, $name, test()->type, $visibility, title: 'Photo', siteOnly: true);
    } finally {
        unlink($source);
        RefusingDisk::forgetLog();
    }

    expect(stampCreatedAt($entry))->toBe(STAMP_STORED);
    stampClock('2002-03-04 05:06:07');
    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    return $entry;
}

function stampCreatedAt(Entry $entry): ?string
{
    $value = DB::table('media_files')->where('entry_id', $entry->id)->value('created_at');

    return $value === null ? null : (string) $value;
}

/**
 * The stored time is still the file's, and every UPDATE of `media_files` the write sent named it as itself — but the one
 * `MediaCustody::locked()` sends on SQLite to take the write lock, which changes nothing and so moves nothing anywhere.
 */
function stampKept(Entry $entry, int $writes): void
{
    expect(stampCreatedAt($entry))->toBe(STAMP_STORED);

    $updates = array_values(array_filter(
        DB::connection()->getQueryLog(),
        static fn (array $query): bool => preg_match('/\Aupdate\s+[`"]?media_files[`"]?\s+set\b/i', $query['query']) === 1
            && preg_match('/\Aupdate\s+[`"]?media_files[`"]?\s+set\s+[`"]?disk[`"]?\s*=\s*[`"]?disk[`"]?\s+where\b/i', $query['query']) !== 1,
    ));

    expect($updates)->toHaveCount($writes);

    foreach ($updates as $update) {
        $set = preg_split('/\s+where\s+/i', $update['query'])[0];
        expect(preg_match('/[`"]?created_at[`"]?\s*=\s*[`"]?created_at[`"]?(\s*,|\s*$)/i', $set))->toBe(1);
    }
}

it('keeps when a file was stored through Make public, rewriting a JPEG, and Make private', function (): void {
    $entry = stampStored(bytes: LocatedJpeg::photo(true), name: 'photo.jpg');

    // The row, with the stripped JPEG's checksum; then custody, naming the public disk the copy moved to.
    expect(MediaVisibility::makePublic($entry))->toBe(MediaVisibility::SWITCHED);
    stampKept($entry, 2);
    expect(DB::table('media_files')->where('entry_id', $entry->id)->value('checksum'))->not->toBe(hash('sha256', LocatedJpeg::photo(true)));

    DB::connection()->flushQueryLog();
    expect(MediaVisibility::makePrivate($entry))->toBe(MediaVisibility::SWITCHED);
    stampKept($entry, 1);
});

it('keeps when a file was stored through Offer to readers and Stop offering', function (): void {
    $entry = stampStored();

    expect(MediaVisibility::offer($entry, 'course.stamps'))->toBe(MediaVisibility::SWITCHED);
    stampKept($entry, 1);

    DB::connection()->flushQueryLog();
    expect(MediaVisibility::stopOffering($entry))->toBe(MediaVisibility::SWITCHED);
    stampKept($entry, 1);
});

it('keeps when a file was stored as custody moves its row off a disk the web serves', function (): void {
    $entry = stampStored('public');
    $path = (string) DB::table('media_files')->where('entry_id', $entry->id)->value('path');
    Storage::disk('old-cdn')->put($path, STAMP_PNG);
    Storage::disk('public')->delete($path);
    DB::table('media_files')->where('entry_id', $entry->id)->update(['disk' => 'old-cdn', 'created_at' => STAMP_STORED]);
    DB::connection()->flushQueryLog();

    expect(MediaCustody::settle(DB::connection(), (int) $entry->id))->toBe(MediaCustody::SETTLED)
        ->and(DB::table('media_files')->where('entry_id', $entry->id)->value('disk'))->toBe('public');
    stampKept($entry, 1);
});

it('keeps when a file was stored as trashing it withdraws it to the private disk', function (): void {
    $entry = stampStored('public');

    Entry::query()->whereKey($entry->id)->update(['deleted_at' => now()]);

    expect(DB::table('media_files')->where('entry_id', $entry->id)->value('disk'))->toBe(MediaDisks::PRIVATE);
    stampKept($entry, 1);
});

/*
 * ...and a write added later carries it too: every UPDATE of `media_files` in core's source names `keepingCreatedAt()`,
 * but the SQLite lock, which changes nothing. Model doors need no such thing: `created_at` is fixed at creation, so
 * `ScopedBuilder` refuses any model write that names it, and the switch's columns are written below the model alone.
 */
it('carries the stored time in every write of the file row core makes', function (): void {
    $writes = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 3).'/packages', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! str_ends_with((string) $file, '.php') || ! str_contains((string) $file, '/src/')) {
            continue;
        }

        $lines = file((string) $file) ?: [];

        foreach ($lines as $i => $line) {
            if (preg_match('/->(update|updateOrInsert|upsert|increment|decrement)(Each)?\(/', $line) !== 1) {
                continue;
            }

            // The statement, from the table it names: a chain may put `->update()` a line or two below it.
            $statement = implode(' ', array_slice($lines, max(0, $i - 2), 3));

            if (preg_match('/table\((\'media_files\'|MediaFile::TABLE)\)/', $statement) === 1) {
                $writes[basename((string) $file).':'.($i + 1)] = str_contains($line, 'MediaFile::keepingCreatedAt(')
                    || str_contains($line, "['disk' => new Expression(\$inside->getQueryGrammar()->wrap('disk'))]");
            }
        }
    }

    expect($writes)->toHaveCount(4)->and(array_keys(array_filter($writes, static fn (bool $kept): bool => ! $kept)))->toBe([]);
});
