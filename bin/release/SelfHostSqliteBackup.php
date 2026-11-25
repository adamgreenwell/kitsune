<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Release;

use InvalidArgumentException;
use RuntimeException;
use SQLite3;
use Throwable;

/** Database member of a self-host backup. The caller holds the installation lock and stops writers before taking the complete backup set. */
final class SelfHostSqliteBackup
{
    /** @return array{size:int, sha256:string} */
    public static function create(string $source, string $backupDirectory): array
    {
        self::requireDatabase($source);
        $mode = @fileperms($backupDirectory);
        if (realpath($backupDirectory) !== $backupDirectory || ! is_dir($backupDirectory)
            || $mode === false || ($mode & 0077) !== 0 || ! is_writable($backupDirectory)) {
            throw new InvalidArgumentException('The SQLite backup requires a private, writable staging directory.');
        }

        $destination = $backupDirectory.'/database.sqlite';
        if (self::present($destination)) {
            throw new InvalidArgumentException('A database backup already occupies the staging directory.');
        }

        $pending = tempnam($backupDirectory, '.database-');
        if ($pending === false || dirname($pending) !== $backupDirectory) {
            if ($pending !== false) {
                unlink($pending);
            }
            throw new RuntimeException('The SQLite backup could not allocate a private file.');
        }

        try {
            try {
                self::copy($source, $pending);
            } catch (Throwable $failure) {
                throw new RuntimeException('The SQLite backup could not copy the source database.', previous: $failure);
            }
            $witness = self::verify($pending);
            self::sync($pending);
            // The staging filesystem supplies an exclusive publication step. Never replace an existing backup.
            if (! @link($pending, $destination)) {
                throw new RuntimeException('The SQLite backup could not publish its verified database.');
            }
        } finally {
            unlink($pending);
        }

        self::sync($backupDirectory);

        return $witness;
    }

    /** A self-contained backup can be rechecked without opening the original database or replaying a journal.
     *
     * @return array{size:int, sha256:string}
     */
    public static function verify(string $path): array
    {
        self::requireDatabase($path);
        foreach (['-journal', '-wal', '-shm'] as $sidecar) {
            if (self::present($path.$sidecar)) {
                throw new InvalidArgumentException('The SQLite backup has an unexpected journal or sidecar.');
            }
        }

        // SQLite's file header records the write/read format at offsets 18 and 19. The backup must use
        // rollback journaling, so even a read-only verification never opens a WAL or creates sidecar files.
        $header = @file_get_contents($path, false, null, 0, 20);
        if ($header === false || ! str_starts_with($header, "SQLite format 3\0") || substr($header, 18, 2) !== "\x01\x01") {
            throw new InvalidArgumentException('The SQLite backup is not a standalone rollback-journal database.');
        }

        try {
            $database = new SQLite3($path, SQLITE3_OPEN_READONLY);
            try {
                $database->enableExceptions(true);
                $integrity = $database->querySingle('PRAGMA integrity_check(1)');
            } finally {
                $database->close();
            }
        } catch (Throwable $failure) {
            throw new RuntimeException('The SQLite backup could not be read for integrity verification.', previous: $failure);
        }

        $size = @filesize($path);
        $digest = @hash_file('sha256', $path);
        if ($integrity !== 'ok' || $size === false || $size < 20 || $digest === false) {
            throw new RuntimeException('The SQLite backup failed integrity verification.');
        }

        return ['size' => $size, 'sha256' => $digest];
    }

    private static function copy(string $source, string $pending): void
    {
        $origin = new SQLite3($source, SQLITE3_OPEN_READONLY);
        try {
            $copy = new SQLite3($pending, SQLITE3_OPEN_READWRITE);
            try {
                $origin->enableExceptions(true);
                $copy->enableExceptions(true);
                $copy->exec('PRAGMA synchronous = FULL');
                if (! $origin->backup($copy) || $copy->querySingle('PRAGMA journal_mode = DELETE') !== 'delete') {
                    throw new RuntimeException('The SQLite backup could not copy a complete standalone database.');
                }
            } finally {
                $copy->close();
            }
        } finally {
            $origin->close();
        }
    }

    private static function requireDatabase(string $path): void
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (realpath($path) !== $path || $stat === false || ($stat['mode'] & 0170000) !== 0100000
            || $stat['nlink'] !== 1 || $stat['size'] < 1 || ! is_readable($path)) {
            throw new InvalidArgumentException('The SQLite database must be a readable, regular, single-link file at its canonical path.');
        }
    }

    private static function present(string $path): bool
    {
        clearstatcache(true, $path);

        return @lstat($path) !== false;
    }

    private static function sync(string $path): void
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The SQLite backup could not be opened for synchronization.');
        }

        try {
            if (! @fsync($handle)) {
                throw new RuntimeException('The SQLite backup could not be synchronized.');
            }
        } finally {
            fclose($handle);
        }
    }
}
