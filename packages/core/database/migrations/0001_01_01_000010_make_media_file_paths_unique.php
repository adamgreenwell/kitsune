<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use League\Flysystem\WhitespacePathNormalizer;

/**
 * One row per file, its path written as the disks read it — ADR-042 decision 5 (Adam, decision 8, 2026-09-25).
 *
 * ⚠️ THE DATABASE NOW GUARANTEES WHAT ADR-042 ASSUMED. *"Paths are generated per upload from random bytes, so the path
 * belongs to this file and nothing else."* Withdrawal, publication, disposal and prune each act on a file by its path, on
 * every disk: a second row naming that path would have its bytes moved or deleted by a step acting for the first. The
 * index makes a second row naming it impossible. And Flysystem normalises every location before a disk sees it —
 * `/media/x`, `media//x` and `media\x` are all the file at `media/x`, and `media/a/../x` is no file at all — so a unique
 * string guarantees one row per file only if every row writes its path the way the disks read it. Both are checked.
 *
 * ⚠️ IT REFUSES BEFORE IT CHANGES ANYTHING, as `0001_01_01_000008` does and for its reason: on MySQL and MariaDB a schema
 * statement commits on its own, and Laravel runs SQLite's migrations outside a transaction, so a refusal thrown after the
 * index would leave it behind and make every retry fail on the index rather than on the problem. A path not written as
 * the disks read it would not stop the build at all. And the engine's own refusal to build over duplicates names one
 * value and no entry. Trashed entries count: a restore brings them back.
 *
 * ⚠️ IT COMPARES WHAT THE ENGINE COMPARES, AND THE FORM FLYSYSTEM READS, AND CLAIMS NOTHING MORE (AGENTS.md §4: an index
 * is a safety net for the shape it compares). The shared check groups and joins on `=`, so it refuses exactly what the
 * index would: bytes on PostgreSQL and SQLite, the column's collation on MySQL and MariaDB — case-insensitive under every
 * default. Two paths differing only in case are two rows on PostgreSQL and SQLite and one file on a case-insensitive
 * filesystem; `store()` never writes such a pair, because its names are lowercase hex. A raw insert is not guarded: it is
 * the insert-path gap ADR-042 records.
 *
 * ⚠️ ON POSTGRESQL AN INDEX, NOT A CONSTRAINT. Laravel's `unique()` there is `add constraint`, which takes ACCESS
 * EXCLUSIVE and stops the serving release's reads; `create unique index` takes SHARE, so reads go on while writes to
 * `media_files` wait for the build. On MySQL and MariaDB the online build takes a metadata lock at its start and end, which
 * any open transaction that touched the table holds off, and statements queue behind it. On SQLite it holds the
 * database's write lock. *Measured — decision 5, slice 5b* records all three.
 *
 * ⚠️ THE CHECK IS A SNAPSHOT. `deploy/release.sh` migrates while the previous release still serves (#150). A row naming
 * an existing path, written between the check and the build, makes the build fail with the engine's own error: nothing
 * changes, and the migration is run again. `store()` never names an existing path.
 *
 * ⚠️ AN IMPORT CANNOT SHARE ONE FILE BETWEEN ENTRIES. Sharing is one media entry, linked wherever it is needed — ADR-042
 * decision 2. Each row needs its own copy at its own path, written as the disks read it.
 *
 * A migration of its own, for the reason `0001_01_01_000008` gives: `deploy/release.sh` runs `migrate --force`.
 */
return new class extends Migration
{
    public const INDEX = 'media_files_path_unique';

    public function up(): void
    {
        $this->refuseUnsafePaths(DB::table('media_files'));

        if (DB::connection()->getDriverName() === 'pgsql') {
            $grammar = DB::connection()->getQueryGrammar();

            DB::statement(sprintf(
                'create unique index %s on %s (%s)',
                $grammar->wrap(self::INDEX),
                $grammar->wrapTable('media_files'),
                $grammar->wrap('path'),
            ));

            return;
        }

        Schema::table('media_files', function (Blueprint $table): void {
            $table->unique('path', self::INDEX);
        });
    }

    /**
     * Refuse, naming every entry, when two rows name one path or a path is not written as the disks read it.
     *
     * Public, and taking the rows to check, so a test can ask it of a table no index guards — on every engine, where a
     * second row naming one path cannot be written to `media_files` itself.
     */
    public function refuseUnsafePaths(Builder $files): void
    {
        $table = (string) $files->from;

        // Shared, as the engine compares: GROUP BY and a join on `=`, so the collation decides, as it will for the index.
        $shared = (clone $files)->select('path')->groupBy('path')->havingRaw('count(*) > 1');
        $sharing = (clone $files)
            ->joinSub($shared, 'shared', 'shared.path', '=', "{$table}.path")
            ->orderBy('shared.path')
            ->orderBy("{$table}.entry_id")
            ->get(['shared.path as shared', "{$table}.entry_id", "{$table}.path"])
            ->groupBy('shared')
            ->map(static function ($rows, int|string $key): string {
                // A path of digits comes back from the collection as an integer key.
                $shared = (string) $key;
                $spelled = $rows->contains(static fn (object $row): bool => (string) $row->path !== $shared);

                return sprintf('%s: entries %s', $shared, $rows->map(static fn (object $row): string => $spelled
                    ? sprintf('%d [%s]', $row->entry_id, $row->path)
                    : (string) (int) $row->entry_id)->implode(', '));
            })
            ->values()
            ->all();

        // Not as the disks read it: Flysystem normalises every location before the adapter sees it.
        $normalizer = new WhitespacePathNormalizer;
        $unread = [];

        foreach ((clone $files)->select(["{$table}.entry_id", "{$table}.path"])->lazyById(1000, "{$table}.entry_id", 'entry_id') as $row) {
            $path = (string) $row->path;

            try {
                $read = $normalizer->normalizePath($path);
            } catch (Throwable) {
                $read = null;
            }

            if ($read !== $path) {
                $unread[] = sprintf(
                    '%s: entry %d, %s',
                    $path,
                    (int) $row->entry_id,
                    $read === null ? 'which no disk can read' : "which every disk reads as {$read}",
                );
            }
        }

        if ($sharing === [] && $unread === []) {
            return;
        }

        $found = array_values(array_filter([
            $sharing === [] ? null : 'named by more than one media_files row',
            $unread === [] ? null : 'not written as the disks read it',
        ]));

        throw new RuntimeException(sprintf(
            'Cannot make media file paths unique: [%s]. %s %s — so a trash, an erasure or a prune acting for one entry '
            .'would move or delete the file another shows (ADR-042 decision 5; Adam, decision 8, 2026-09-25). Nothing was '
            .'changed. Trashed entries count: a restore brings them back. A path not written as the disks read it is '
            .'corrected by a direct UPDATE of media_files.path to the path they read, where the file already is — unless '
            .'another row names that path, when it is shared. A shared path is corrected by giving every row but one its '
            .'own copy: copy the file to a new path on the disk that row names, and point that row at it with a direct '
            .'UPDATE of media_files.path — every model door refuses a path change. Then run the migration again. '
            .'Force-deleting one of the entries is not a fix: its erasure withdraws the path from every disk the web '
            .'serves, taking the other entry\'s file with it.',
            implode('; ', [...$sharing, ...$unread]),
            count($sharing) + count($unread) === 1 ? 'That path is' : 'Each of those paths is',
            implode(', or ', $found),
        ));
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }
};
