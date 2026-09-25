<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\MediaFile;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/*
 * One row per file, its path written as the disks read it — ADR-042 decision 5 (Adam, decision 8, 2026-09-25; T56-T58).
 *
 * ⚠️ THE MIGRATION'S CHECK IS ASKED OF A TABLE NO INDEX GUARDS. `media_files` refuses a second row naming one path, so
 * the state an installation migrated before the index can be in is written to `media_path_fixtures`, which declares its
 * path as `media_files` does — and so is compared by the same collation. Every refusal is asserted by its own words, and
 * the engine's refusals by their class, so a test cannot pass because a different guard refused.
 */

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake(MediaDisks::PRIVATE);

    $this->org = Org::create(['slug' => 'paths', 'name' => 'Paths']);
    app(Context::class)->setOrg($this->org);
    app(Context::class)->setSite(Site::create(['handle' => 'main', 'slug' => 'paths-main', 'name' => 'Main', 'locale' => 'en']));
    $this->image = EntryType::create(['org_id' => $this->org->id, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);
});

afterEach(fn () => app(Context::class)->forget());

/** The migration, as a fresh instance: `require` rather than `require_once`, because the file returns one. */
function pathIndexMigration(): object
{
    return require __DIR__.'/../../../packages/core/database/migrations/0001_01_01_000010_make_media_file_paths_unique.php';
}

function pathIndexStored(EntryType $type): MediaFile
{
    $source = tempnam(sys_get_temp_dir(), 'kitsune-paths-');
    file_put_contents($source, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    $entry = MediaLibrary::store($source, 'paths.png', $type, 'public');
    unlink($source);

    return MediaFile::query()->where('entry_id', $entry->getKey())->firstOrFail();
}

function pathIndexFixtures(array $paths): void
{
    foreach ($paths as $entry => $path) {
        DB::table('media_path_fixtures')->insert(['entry_id' => $entry, 'path' => $path]);
    }
}

function pathIndexCheck(): void
{
    pathIndexMigration()->refuseUnsafePaths(DB::table('media_path_fixtures'));
}

/** Whether the engine folds case when it compares `path`, as MySQL's and MariaDB's default collations do. */
function pathIndexFoldsCase(): bool
{
    return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
}

describe('the migration\'s check (T56)', function (): void {
    it('refuses two rows naming one path, naming the path and both entries', function (): void {
        pathIndexFixtures([11 => 'media/1/2026/09/a.png', 12 => 'media/1/2026/09/a.png', 13 => 'media/1/2026/09/b.png']);

        expect(fn () => pathIndexCheck())->toThrow(
            RuntimeException::class,
            'Cannot make media file paths unique: [media/1/2026/09/a.png: entries 11, 12]. That path is named by more '
            .'than one media_files row — so a trash, an erasure or a prune acting for one entry would move or delete the '
            .'file another shows',
        );
    });

    it('names a path three rows share once, with every entry', function (): void {
        pathIndexFixtures([11 => 'media/1/2026/09/a.png', 12 => 'media/1/2026/09/a.png', 13 => 'media/1/2026/09/a.png']);

        expect(fn () => pathIndexCheck())->toThrow(RuntimeException::class, '[media/1/2026/09/a.png: entries 11, 12, 13]. That path is');
    });

    it('passes rows that each name their own path, written as the disks read it', function (): void {
        pathIndexFixtures([11 => 'media/1/2026/09/a.png', 12 => 'media/1/2026/09/b.png', 13 => 'media/2/2026/09/a.png']);

        pathIndexCheck();

        expect(DB::table('media_path_fixtures')->count())->toBe(3);
    });

    /** The check and the index compare alike: by bytes on PostgreSQL and SQLite, by the collation on MySQL and MariaDB. */
    it('compares two spellings differing only in case as the engine does', function (): void {
        pathIndexFixtures([11 => 'media/1/2026/09/a.png', 12 => 'media/1/2026/09/A.png']);

        if (pathIndexFoldsCase()) {
            expect(fn () => pathIndexCheck())->toThrow(RuntimeException::class, 'entries 11 [media/1/2026/09/a.png], 12 [media/1/2026/09/A.png]');

            return;
        }

        pathIndexCheck();

        expect(DB::table('media_path_fixtures')->count())->toBe(2);
    });

    it('refuses a path every disk reads as another, naming the entry and what the disks read', function (string $path): void {
        pathIndexFixtures([21 => $path]);

        expect(fn () => pathIndexCheck())->toThrow(
            RuntimeException::class,
            "[{$path}: entry 21, which every disk reads as media/1/x.png]. That path is not written as the disks read it —",
        );
    })->with([
        'a leading slash' => '/media/1/x.png',
        'a trailing slash' => 'media/1/x.png/',
        'an empty segment' => 'media//1/x.png',
        'a dot segment' => 'media/./1/x.png',
        'backslashes' => 'media\\1\\x.png',
        'a parent segment' => 'media/2/../1/x.png',
    ]);

    it('refuses a path no disk can read', function (string $path): void {
        pathIndexFixtures([21 => $path]);

        expect(fn () => pathIndexCheck())->toThrow(RuntimeException::class, "[{$path}: entry 21, which no disk can read]");
    })->with([
        'a parent segment above the root' => '../media/1/x.png',
        'a control character' => "media/1/x\x01.png",
    ]);

    it('names both kinds together', function (): void {
        pathIndexFixtures([11 => 'media/1/2026/09/a.png', 12 => 'media/1/2026/09/a.png', 21 => '/media/1/x.png']);

        expect(fn () => pathIndexCheck())->toThrow(
            RuntimeException::class,
            '[media/1/2026/09/a.png: entries 11, 12; /media/1/x.png: entry 21, which every disk reads as media/1/x.png]. '
            .'Each of those paths is named by more than one media_files row, or not written as the disks read it —',
        );
    });
});

describe('the index (T57)', function (): void {
    it('refuses a second row naming a stored file\'s path, and accepts a fresh one', function (): void {
        $a = pathIndexStored($this->image);
        $b = pathIndexStored($this->image);

        // Nested, so PostgreSQL rolls back to the savepoint rather than aborting the test's transaction.
        expect(fn () => DB::transaction(fn () => DB::table('media_files')->where('entry_id', $b->entry_id)->update(['path' => $a->path])))
            ->toThrow(UniqueConstraintViolationException::class);

        expect(DB::table('media_files')->where('path', $a->path)->pluck('entry_id')->map(fn (mixed $id): int => (int) $id)->all())
            ->toBe([$a->entry_id]);

        $fresh = 'media/'.$this->org->id.'/2026/09/'.str_repeat('f', 32).'.png';
        DB::table('media_files')->where('entry_id', $b->entry_id)->update(['path' => $fresh]);

        expect(DB::table('media_files')->where('entry_id', $b->entry_id)->value('path'))->toBe($fresh);
    });

    it('refuses a spelling differing only in case exactly where the check does', function (): void {
        $a = pathIndexStored($this->image);
        $b = pathIndexStored($this->image);
        $variant = strtoupper($a->path);
        $write = fn () => DB::transaction(fn () => DB::table('media_files')->where('entry_id', $b->entry_id)->update(['path' => $variant]));

        if (pathIndexFoldsCase()) {
            expect($write)->toThrow(UniqueConstraintViolationException::class);

            return;
        }

        $write();

        expect(DB::table('media_files')->where('entry_id', $b->entry_id)->value('path'))->toBe($variant);
    });
});

describe('the model (T58)', function (): void {
    /** An entry whose file row is gone, so a row can be created for it. */
    beforeEach(function (): void {
        $this->stored = (array) DB::table('media_files')->where('id', pathIndexStored($this->image)->getKey())->first();
        DB::table('media_files')->where('id', $this->stored['id'])->delete();
        unset($this->stored['id']);
    });

    it('refuses to create a row whose path the disks read as another, before anything reaches the engine', function (string $path, string $reads): void {
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = strtolower(ltrim($query->sql));
        });

        expect(fn () => MediaFile::create([...$this->stored, 'path' => $path]))->toThrow(
            RuntimeException::class,
            "Refusing to store a media file at [{$path}]: {$reads}, so the path would not be this file's alone",
        );

        expect(array_filter($statements, static fn (string $sql): bool => ! str_starts_with($sql, 'select')))->toBe([])
            ->and(DB::table('media_files')->where('entry_id', $this->stored['entry_id'])->exists())->toBeFalse();
    })->with([
        'a leading slash' => ['/media/1/x.png', 'every disk reads it as [media/1/x.png]'],
        'an empty segment' => ['media//1/x.png', 'every disk reads it as [media/1/x.png]'],
        'backslashes' => ['media\\1\\x.png', 'every disk reads it as [media/1/x.png]'],
        'a parent segment' => ['media/2/../1/x.png', 'every disk reads it as [media/1/x.png]'],
        'a parent segment above the root' => ['../media/1/x.png', 'no disk can read it'],
    ]);

    it('creates a row whose path is written as the disks read it', function (): void {
        MediaFile::create($this->stored);

        expect(DB::table('media_files')->where('entry_id', $this->stored['entry_id'])->value('path'))->toBe($this->stored['path']);
    });
});
