<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Media\MediaBytes;
use Kitsune\Core\Media\MediaCustody;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaDisposal;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\RefusingDisk;

/*
 * Custody's locks, on every engine — ADR-042 decision 5 (T52-T54).
 *
 * ⚠️ WHAT IS LOCKED, IN WHAT ORDER, BEFORE ANY BYTE MOVES, read from one timeline of the statements the connection ran
 * and the operations the disks were asked for. And against a real rival: a second connection holding a row, so a lock
 * custody failed to take would show as a byte moved while the rival held it.
 *
 * ⚠️ THE RIVAL'S ROWS ARE COMMITTED, because a row written inside `RefreshDatabase`'s transaction is invisible to any
 * other connection; they are read here with locking reads, which see the latest committed row on every engine, and
 * removed through the rival once the test's own transaction has rolled back.
 */

const LOCK_PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\x0f\0\0\x01\x01\0\x05\x18\xd8N\0\0\0\0IEND\xaeB`\x82";

beforeEach(function (): void {
    $this->roots = [];
    $this->disks = [];

    foreach (['public', MediaDisks::PRIVATE] as $name) {
        $root = sys_get_temp_dir().'/kitsune-lock-'.$name.'-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        $this->roots[] = $root;
        $this->disks[$name] = RefusingDisk::install($name, $root);
    }

    $this->checksum = hash('sha256', LOCK_PNG);
});

afterEach(function (): void {
    app(Context::class)->forget();

    if (array_key_exists('custody-rival', config('database.connections') ?? [])) {
        try {
            DB::connection('custody-rival')->rollBack();
        } catch (Throwable) {
            // Nothing open, which is the ordinary case.
        }
    }

    foreach ($this->roots as $root) {
        exec('rm -rf '.escapeshellarg($root));
    }
});

/** The statements run and the disks' byte operations, in order. @return list<string> */
function lockTimeline(Closure $work): array
{
    DB::listen(fn (QueryExecuted $query) => RefusingDisk::note('sql', strtolower($query->sql)));
    RefusingDisk::forgetLog();

    $work();

    return array_map(
        static fn (array $entry): string => $entry['event'] === 'sql' ? 'sql '.$entry['path'] : 'bytes '.$entry['disk'],
        array_values(array_filter(RefusingDisk::$log, static fn (array $entry): bool => $entry['event'] === 'sql' || $entry['bytes'])),
    );
}

/** The index of the first line matching the pattern, or -1. */
function lockFirst(array $timeline, string $pattern): int
{
    foreach ($timeline as $i => $line) {
        if (preg_match($pattern, $line) === 1) {
            return $i;
        }
    }

    return -1;
}

/*
 * T52. The lock order, on every engine, on every path that moves bytes: on PostgreSQL, MySQL and MariaDB the entry and
 * then its file read `FOR UPDATE`; on SQLite a write to `media_files` first, which takes the database's write lock; and
 * the trash's and the erasure's own row writes before their first byte.
 */
describe('the lock order', function (): void {
    beforeEach(function (): void {
        $org = Org::create(['slug' => 'locks', 'name' => 'Locks']);
        app(Context::class)->setOrg($org);
        app(Context::class)->setSite(Site::create(['handle' => 'main', 'slug' => 'locks-main', 'name' => 'Main', 'locale' => 'en']));
        $this->image = EntryType::create(['org_id' => $org->id, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);

        $this->stored = function (): array {
            $source = tempnam(sys_get_temp_dir(), 'kitsune-lock-');
            file_put_contents($source, LOCK_PNG);
            $entry = MediaLibrary::store($source, 'photo.png', $this->image, 'public');
            unlink($source);

            return [$entry, (string) DB::table('media_files')->where('entry_id', $entry->id)->value('path')];
        };
    });

    it('locks the entry and then its file before any byte moves', function (string $path): void {
        [$entry, $filePath] = ($this->stored)();
        $id = $entry->id;

        match ($path) {
            'publication', 'drain' => (function () use ($entry, $path): void {
                $entry->delete();
                DB::table('entries')->where('id', $entry->id)->update(['deleted_at' => null]);

                if ($path === 'drain') {
                    DB::table('media_files')->where('entry_id', $entry->id)->update(['disk' => 'public']);
                }
            })(),
            'removeTemp' => Storage::disk(MediaDisks::PRIVATE)->put(MediaBytes::partial($filePath), 'half'),
            'removeOrphan' => Storage::disk(MediaDisks::PRIVATE)->put('media/orphan.png', 'bytes'),
            'disposal' => DB::table('entries')->where('id', $id)->delete(),
            'cleanUp', 'removeExtra' => Storage::disk(MediaDisks::PRIVATE)->put($filePath, LOCK_PNG),
            // A file trashed before withdrawal existed: still on the public disk its row names.
            'reconcile' => DB::table('entries')->where('id', $id)->update(['deleted_at' => now()]),
        };

        $timeline = lockTimeline(fn () => match ($path) {
            'publication' => MediaCustody::publish(DB::connection(), [$id]),
            'drain' => (function () use ($id): void {
                MediaCustody::queue(DB::getDefaultConnection(), [$id]);
                MediaCustody::drain(DB::connection());
            })(),
            'removeOrphan' => MediaCustody::removeOrphan(DB::connection(), MediaDisks::PRIVATE, 'media/orphan.png'),
            'removeTemp' => MediaCustody::removeTemp(DB::connection(), $id, MediaDisks::PRIVATE, MediaBytes::partial($filePath)),
            'disposal' => MediaDisposal::remove(DB::connection(), [['entry_id' => $id, 'disk' => 'public', 'path' => $filePath]]),
            'cleanUp' => MediaCustody::cleanUp(DB::connection(), $id),
            'removeExtra' => MediaCustody::removeExtra(DB::connection(), $id, MediaDisks::PRIVATE),
            'reconcile' => Artisan::call('kitsune:media-reconcile', ['--force' => true, '--entry' => [(string) $id]]),
        });

        $bytes = lockFirst($timeline, '/^bytes /');

        expect($bytes)->toBeGreaterThan(-1);

        if (DB::connection()->getDriverName() === 'sqlite') {
            $write = lockFirst($timeline, '/^sql update "media_files" set "disk" = "disk"/');

            expect($write)->toBeGreaterThan(-1)->toBeLessThan($bytes)
                ->and(lockFirst($timeline, '/^sql select .*from "entries"/'))->toBeGreaterThan($write);
        } else {
            $entries = lockFirst($timeline, '/^sql select .*from .entries. .*for update/');
            $files = lockFirst($timeline, '/^sql select .*from .media_files. .*for update/');

            expect($entries)->toBeGreaterThan(-1)
                ->and($files)->toBeGreaterThan($entries)
                ->and($bytes)->toBeGreaterThan($files);
        }
    })->with(['publication', 'drain', 'removeOrphan', 'removeTemp', 'disposal', 'cleanUp', 'removeExtra', 'reconcile']);

    it('writes the trash\'s and the erasure\'s own rows before their first byte', function (string $write): void {
        [$entry] = ($this->stored)();

        $timeline = lockTimeline(fn () => $write === 'trash' ? $entry->delete() : $entry->forceDelete());

        $row = lockFirst($timeline, $write === 'trash' ? '/^sql update .entries. set/' : '/^sql delete from .entries./');
        $bytes = lockFirst($timeline, '/^bytes /');

        expect($row)->toBeGreaterThan(-1)
            ->and($bytes)->toBeGreaterThan($row);
    })->with(['trash', 'erasure']);
});

/*
 * T53 and T54 against a rival: a second connection to the same database, holding a row. SQLite serialises writers
 * on the database itself, so it has no row lock to hold and these are skipped there.
 */
describe('against a rival holding the row', function (): void {
    beforeEach(function (): void {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite serialises writers at the database level, so there is no row lock to hold.');
        }

        $default = (string) config('database.default');
        config(['database.connections.custody-rival' => config("database.connections.{$default}")]);
        $this->rival = DB::connection('custody-rival');

        /*
         * Committed through the rival, so the rival can lock them and this connection can see them: a live public file
         * whose row names the private disk — a publication, or a drain, still to happen.
         */
        $this->ids = lockFixtures($this->rival);
        $this->path = 'media/'.$this->ids['org'].'/2026/09/rival.png';
        Storage::disk(MediaDisks::PRIVATE)->put($this->path, LOCK_PNG);

        $this->afterRollback(function (): void {
            $sweep = DB::connection('custody-rival');
            $sweep->table('media_files')->where('entry_id', $this->ids['entry'])->delete();
            $sweep->table('entries')->where('id', $this->ids['entry'])->delete();
            $sweep->table('entry_types')->where('id', $this->ids['type'])->delete();
            $sweep->table('sites')->where('id', $this->ids['site'])->delete();
            $sweep->table('orgs')->where('id', $this->ids['org'])->delete();
            DB::purge('custody-rival');
        });

        match (DB::connection()->getDriverName()) {
            'pgsql' => DB::statement("SET lock_timeout = '750ms'"),
            default => DB::statement('SET SESSION innodb_lock_wait_timeout = 1'),
        };
    });

    /*
     * T53. A publication and a drain queue behind a rival holding the entry: each fails on the lock and says so, and no
     * byte moves while the rival holds the row.
     */
    it('moves no byte while a rival holds the entry', function (string $how): void {
        $this->rival->beginTransaction();
        $this->rival->table('entries')->where('id', $this->ids['entry'])->lockForUpdate()->first();
        Log::spy();

        if ($how === 'publication') {
            MediaCustody::publish(DB::connection(), [$this->ids['entry']]);
        } else {
            MediaCustody::queue(DB::getDefaultConnection(), [$this->ids['entry']]);
            MediaCustody::drain(DB::connection());
        }

        $this->rival->rollBack();

        expect(Storage::disk('public')->exists($this->path))->toBeFalse()
            ->and(hash('sha256', (string) Storage::disk(MediaDisks::PRIVATE)->get($this->path)))->toBe($this->checksum);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, "entry {$this->ids['entry']}"))->atLeast()->once();
    })->with(['publication', 'drain']);

    /*
     * T54. A lock wait inside a nested trash, and a host that catches it and commits. On MySQL and MariaDB the timeout
     * ends only the statement and Laravel does not roll back the savepoint — so without custody's own `ROLLBACK TO`, the
     * host's commit would commit the trash with its file still on the web. The control shows the engine doing exactly
     * that to a write custody does not guard.
     */
    it('keeps an entry whose nested trash waited on a rival past its timeout', function (): void {
        // Published: on the public disk its row names, committed before the rival takes its lock.
        $this->rival->table('media_files')->where('entry_id', $this->ids['entry'])->update(['disk' => 'public']);
        Storage::disk(MediaDisks::PRIVATE)->delete($this->path);
        Storage::disk('public')->put($this->path, LOCK_PNG);

        $this->rival->beginTransaction();
        $this->rival->table('media_files')->where('entry_id', $this->ids['entry'])->lockForUpdate()->first();
        $caught = null;

        DB::transaction(function () use (&$caught): void {
            try {
                lockVictim($this->ids)->delete();
            } catch (Throwable $failure) {
                $caught = $failure;
            }
        });

        $this->rival->rollBack();

        expect($caught)->toBeInstanceOf(DB::connection()->getDriverName() === 'pgsql' ? QueryException::class : DeadlockException::class)
            ->and(DB::table('entries')->where('id', $this->ids['entry'])->lockForUpdate()->value('deleted_at'))->toBeNull()
            ->and(hash('sha256', (string) Storage::disk('public')->get($this->path)))->toBe($this->checksum)
            ->and(Storage::disk(MediaDisks::PRIVATE)->exists($this->path))->toBeFalse();
    });

    /*
     * T84. A forced reconcile waits for no row longer than the engine's lock wait: the row a rival holds fails, and says
     * so, and the run goes on to settle the next and fails at the end.
     */
    it('fails a row a rival holds and settles the next', function (): void {
        $source = tempnam(sys_get_temp_dir(), 'kitsune-lock-');
        file_put_contents($source, LOCK_PNG);
        $next = MediaLibrary::store($source, 'next.png', EntryType::query()->findOrFail($this->ids['type']), 'public');
        unlink($source);
        $nextPath = (string) DB::table('media_files')->where('entry_id', $next->id)->value('path');
        DB::table('entries')->where('id', $next->id)->update(['deleted_at' => now()]);

        $this->rival->beginTransaction();
        $this->rival->table('entries')->where('id', $this->ids['entry'])->lockForUpdate()->first();

        $exit = Artisan::call('kitsune:media-reconcile', ['--force' => true]);
        $output = Artisan::output();

        $this->rival->rollBack();

        $held = collect(explode("\n", $output))->first(fn (string $line): bool => str_contains($line, ' entry '.$this->ids['entry'].' '));

        expect($held)->toContain('→ failed: ')
            ->and(Storage::disk('public')->exists($this->path))->toBeFalse()
            ->and(Storage::disk(MediaDisks::PRIVATE)->exists($nextPath))->toBeTrue()
            ->and(Storage::disk('public')->exists($nextPath))->toBeFalse()
            ->and($exit)->toBe(1);
    });

    it('shows the engine keeping a savepoint\'s write that a lock wait interrupted', function (): void {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->markTestSkipped('PostgreSQL\'s lock_timeout is not a concurrency error, so Laravel rolls the savepoint back itself.');
        }

        $this->rival->beginTransaction();
        $this->rival->table('media_files')->where('entry_id', $this->ids['entry'])->lockForUpdate()->first();

        DB::transaction(function (): void {
            try {
                DB::transaction(function (): void {
                    DB::table('entries')->where('id', $this->ids['entry'])->update(['deleted_at' => now()]);
                    DB::table('media_files')->where('entry_id', $this->ids['entry'])->lockForUpdate()->first();
                });
            } catch (DeadlockException) {
            }
        });

        $this->rival->rollBack();

        expect(DB::table('entries')->where('id', $this->ids['entry'])->lockForUpdate()->value('deleted_at'))->not->toBeNull();
    });
});

/**
 * An org, a site, a media type, an entry and its file row, committed through the rival.
 *
 * @return array{org: int, site: int, type: int, entry: int}
 */
function lockFixtures(Connection $rival): array
{
    $slug = 'lock-'.bin2hex(random_bytes(3));
    $now = now();

    $org = (int) $rival->table('orgs')->insertGetId(['slug' => $slug, 'name' => 'Lock', 'created_at' => $now, 'updated_at' => $now]);
    $site = (int) $rival->table('sites')->insertGetId(['org_id' => $org, 'handle' => 'main', 'slug' => $slug, 'name' => 'Main', 'locale' => 'en', 'created_at' => $now, 'updated_at' => $now]);
    $type = (int) $rival->table('entry_types')->insertGetId(['org_id' => $org, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true, 'created_at' => $now, 'updated_at' => $now]);
    $entry = (int) $rival->table('entries')->insertGetId(['org_id' => $org, 'site_id' => null, 'entry_type_id' => $type, 'type_handle' => 'image', 'title' => 'Rival', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now]);
    $rival->table('media_files')->insert([
        'entry_id' => $entry, 'disk' => MediaDisks::PRIVATE, 'path' => 'media/'.$org.'/2026/09/rival.png', 'mime' => 'image/png',
        'size_bytes' => strlen(LOCK_PNG), 'checksum' => hash('sha256', LOCK_PNG), 'visibility' => 'public', 'created_at' => $now,
    ]);

    app(Context::class)->setOrg(Org::query()->lockForUpdate()->findOrFail($org));

    return ['org' => $org, 'site' => $site, 'type' => $type, 'entry' => $entry];
}

/** The rival's entry, read with a locking read, which sees the latest committed row on every engine. */
function lockVictim(array $ids): Entry
{
    return Entry::withoutScopeBecause('a test reading a row a rival connection committed', fn ($query) => $query
        ->whereKey($ids['entry'])->lockForUpdate()->firstOrFail());
}
