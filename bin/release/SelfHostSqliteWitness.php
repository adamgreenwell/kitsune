<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Release;

use RuntimeException;
use SQLite3;

/** Fingerprint an isolated, standalone backup before considering an exact retained-origin transition. */
final class SelfHostSqliteWitness
{
    /**
     * @return array{schema_sha256:string,migrations_sha256:string,modules_sha256:string}
     */
    public static function inspect(string $backup): array
    {
        SelfHostSqliteBackup::verify($backup);
        $database = new SQLite3($backup, SQLITE3_OPEN_READONLY);
        try {
            $database->enableExceptions(true);
            $database->exec('PRAGMA query_only = ON');
            $database->exec('BEGIN');
            $schema = self::fingerprint($database, 'schema', "
                SELECT type, name, tbl_name, sql FROM main.sqlite_schema
                WHERE substr(name, 1, 7) <> 'sqlite_'
                ORDER BY type COLLATE BINARY, name COLLATE BINARY
            ");
            $migrations = self::fingerprint($database, 'migrations', '
                SELECT migration, batch FROM main.migrations ORDER BY id
            ');
            $modules = self::fingerprint($database, 'modules', '
                SELECT handle, version, is_enabled, installed_at FROM main.modules ORDER BY handle COLLATE BINARY
            ');
            $database->exec('COMMIT');
        } finally {
            $database->close();
        }

        return ['schema_sha256' => $schema, 'migrations_sha256' => $migrations, 'modules_sha256' => $modules];
    }

    private static function fingerprint(SQLite3 $database, string $kind, string $query): string
    {
        $result = $database->query($query);
        if ($result === false) {
            throw new RuntimeException('A required SQLite origin witness could not be read.');
        }

        $hash = hash_init('sha256');
        hash_update($hash, "kitsune-sqlite-origin-v1\n$kind\n");
        try {
            while (($row = $result->fetchArray(SQLITE3_NUM)) !== false) {
                // Retain exact text bytes even in a host-defined schema containing non-UTF-8 identifiers.
                $fields = array_map(static fn (mixed $value): mixed => is_string($value) ? ['hex', bin2hex($value)] : $value, $row);
                hash_update($hash, json_encode($fields, JSON_THROW_ON_ERROR)."\n");
            }
        } finally {
            $result->finalize();
        }

        return hash_final($hash);
    }
}
