<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Release;

use Dotenv\Dotenv;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Persistent data for native and Docker. The caller must stop all writers and hold the installation lock.
 * The separate activation journal records the corresponding code tree or container image.
 */
final class SelfHostBackupSet
{
    /** @return array{inventory_sha256:string,database_sha256:string} */
    public static function create(string $mode, string $state, string $destination): array
    {
        $database = match ($mode) {
            'native' => 'database/database.sqlite',
            'docker' => 'database.sqlite',
            default => throw new InvalidArgumentException('Choose native or Docker data layout.'),
        };
        $parent = dirname($destination);
        if (realpath($state) !== $state || ! is_dir($state) || realpath($parent) !== $parent
            || in_array(basename($destination), ['', '.', '..'], true)
            || $destination !== rtrim($parent, '/').'/'.basename($destination)
            || $destination === $state || str_starts_with($destination, $state.'/')) {
            throw new InvalidArgumentException('Use a canonical backup path outside the persistent state.');
        }
        if (realpath($state.'/'.$database) !== $state.'/'.$database
            || realpath($state.'/.env') !== $state.'/.env' || @filesize($state.'/.env') === 0
            || realpath($state.'/storage') !== $state.'/storage' || ! is_dir($state.'/storage')) {
            throw new InvalidArgumentException('The database, environment file and storage directory must belong to the persistent state.');
        }
        $environment = self::configuration($state.'/.env', $state.'/'.$database);
        if (! @mkdir($destination, 0700)) {
            throw new RuntimeException('The backup destination must be a new private directory.');
        }

        try {
            $databaseWitness = SelfHostSqliteBackup::create($state.'/'.$database, $destination);
            $savedEnvironment = $destination.'/.env';
            if (@file_put_contents($savedEnvironment, $environment) !== strlen($environment) || ! chmod($savedEnvironment, 0600)) {
                throw new RuntimeException('The persisted configuration could not be backed up.');
            }
            self::syncPath($savedEnvironment);
            unset($environment);
            self::copyStorage($state.'/storage', $destination.'/storage');

            $inventory = self::inventory($mode, $destination);
            $seal = $destination.'/persisted-data.sha256';
            if (@file_put_contents($seal, $inventory."\n") !== 65 || ! chmod($seal, 0600)) {
                throw new RuntimeException('The backup inventory could not be sealed.');
            }
            self::syncPath($seal);
            self::syncPath($destination);
            self::syncPath($parent);
        } catch (Throwable $failure) {
            self::discard($destination);
            if (is_dir($destination)) {
                throw new RuntimeException('Backup failed and its private staging directory could not be removed.', previous: $failure);
            }

            throw $failure;
        }

        return ['inventory_sha256' => $inventory, 'database_sha256' => $databaseWitness['sha256']];
    }

    private static function configuration(string $path, string $database): string
    {
        try {
            $environment = Files::control($path, 262144);
            $config = Dotenv::parse($environment);
        } catch (Throwable) {
            // A dotenv parse exception can quote the original line and its values.
            throw new InvalidArgumentException('The persisted configuration could not be read or parsed.');
        }

        if (($config['DB_CONNECTION'] ?? null) !== 'sqlite' || ($config['DB_DATABASE'] ?? null) !== $database
            || ($config['DB_URL'] ?? '') !== '') {
            throw new InvalidArgumentException('The persisted configuration must select this installation’s SQLite database.');
        }

        $key = $config['APP_KEY'] ?? '';
        if (! str_starts_with($key, 'base64:') || ! self::isApplicationKey($key)) {
            throw new InvalidArgumentException('The persisted configuration is missing its valid application key.');
        }
        foreach (explode(',', $config['APP_PREVIOUS_KEYS'] ?? '') as $previous) {
            if ($previous !== '' && ! self::isApplicationKey($previous)) {
                throw new InvalidArgumentException('The persisted configuration has an invalid previous application key.');
            }
        }

        return $environment;
    }

    private static function isApplicationKey(string $configured): bool
    {
        $raw = str_starts_with($configured, 'base64:') ? base64_decode(substr($configured, 7), true) : $configured;

        return is_string($raw) && strlen($raw) === 32;
    }

    private static function discard(string $directory): void
    {
        $items = @scandir($directory, SCANDIR_SORT_NONE);
        foreach ($items !== false ? $items : [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory.'/'.$name;
            if (! is_link($path) && is_dir($path)) {
                self::discard($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }

    /** @return array{size:int, sha256:string} */
    public static function verify(string $mode, string $directory, string $expectedInventory): array
    {
        if (! in_array($mode, ['native', 'docker'], true) || preg_match('/\A[a-f0-9]{64}\z/D', $expectedInventory) !== 1
            || ! is_dir($directory) || realpath($directory) !== $directory) {
            throw new InvalidArgumentException('The backup set requires a canonical path and its recorded inventory digest.');
        }

        if (self::children($directory) !== ['.env', 'database.sqlite', 'persisted-data.sha256', 'storage']) {
            throw new RuntimeException('The persisted backup set is incomplete or contains unexpected files.');
        }

        $seal = $directory.'/persisted-data.sha256';
        self::regular($seal);
        if (@file_get_contents($seal, false, null, 0, 66) !== $expectedInventory."\n"
            || ! hash_equals($expectedInventory, self::inventory($mode, $directory))) {
            throw new RuntimeException('The persisted backup contents no longer match the recorded inventory.');
        }

        return SelfHostSqliteBackup::verify($directory.'/database.sqlite');
    }

    /**
     * Recreate persistent data in a new private root. The adapter verifies the release and takes ownership
     * of the staged root before switching anything into service.
     *
     * @return array{size:int, sha256:string}
     */
    public static function stageRestore(string $mode, string $backup, string $inventory, string $destination): array
    {
        $verified = self::verify($mode, $backup, $inventory);
        $parent = dirname($destination);
        if (realpath($parent) !== $parent || $destination !== rtrim($parent, '/').'/'.basename($destination)
            || in_array(basename($destination), ['', '.', '..'], true)
            || $backup === $destination || str_starts_with($destination, $backup.'/')) {
            throw new InvalidArgumentException('Use a fresh canonical restore path outside the backup.');
        }
        if (! @mkdir($destination, 0700)) {
            throw new RuntimeException('The restore destination must be a new private directory.');
        }

        try {
            $database = $mode === 'native' ? 'database/database.sqlite' : 'database.sqlite';
            if ($mode === 'native' && ! @mkdir($destination.'/database', 0700)) {
                throw new RuntimeException('The native restore database directory could not be created.');
            }

            self::copyFile($backup.'/.env', $destination.'/.env');
            self::copyFile($backup.'/database.sqlite', $destination.'/'.$database);
            self::copyStorage($backup.'/storage', $destination.'/storage');
            if (! hash_equals($inventory, self::inventory($mode, $destination, $database))) {
                throw new RuntimeException('The copied state differs from the recorded backup inventory.');
            }
            $actual = SelfHostSqliteBackup::verify($destination.'/'.$database);
            if ($actual !== $verified) {
                throw new RuntimeException('The restored database differs from the recorded backup.');
            }

            if ($mode === 'native') {
                self::syncPath($destination.'/database');
            }
            self::syncPath($destination);
            self::syncPath($parent);
        } catch (Throwable $failure) {
            self::discard($destination);
            if (is_dir($destination)) {
                throw new RuntimeException('Restore staging failed and the incomplete root could not be removed.', previous: $failure);
            }

            throw $failure;
        }

        return $actual;
    }

    private static function copyStorage(string $source, string $destination): void
    {
        if (! @mkdir($destination, 0700)) {
            throw new RuntimeException('A backup storage directory could not be created.');
        }
        foreach (self::children($source) as $name) {
            $path = $source.'/'.$name;
            if (is_link($path)) {
                throw new InvalidArgumentException('The backup refuses linked persistent storage.');
            }
            if (is_dir($path)) {
                self::copyStorage($path, $destination.'/'.$name);
            } else {
                self::copyFile($path, $destination.'/'.$name);
            }
        }
        self::syncPath($destination);
    }

    private static function copyFile(string $source, string $destination): void
    {
        self::regular($source);
        if (! @copy($source, $destination) || ! chmod($destination, 0600)) {
            throw new RuntimeException('A persistent backup file could not be copied.');
        }
        self::syncPath($destination);
    }

    private static function inventory(string $mode, string $directory, string $database = 'database.sqlite'): string
    {
        $inventory = hash_init('sha256');
        hash_update($inventory, "kitsune-persisted-backup-v1\n$mode\n");
        $record = static function (string $relative, string $path) use ($inventory): void {
            if (is_link($path)) {
                throw new InvalidArgumentException('The backup inventory refuses links.');
            }
            if (is_dir($path)) {
                hash_update($inventory, json_encode(['directory', bin2hex($relative)], JSON_THROW_ON_ERROR)."\n");

                return;
            }

            self::regular($path);
            $digest = @hash_file('sha256', $path);
            if ($digest === false) {
                throw new RuntimeException('A persistent backup file could not be verified.');
            }
            hash_update($inventory, json_encode(['file', bin2hex($relative), $digest], JSON_THROW_ON_ERROR)."\n");
        };

        foreach (['.env' => '.env', 'database.sqlite' => $database] as $label => $file) {
            self::regular($directory.'/'.$file);
            $record($label, $directory.'/'.$file);
        }
        self::recordStorage($directory, 'storage', $record);

        return hash_final($inventory);
    }

    /** @param callable(string,string):void $record */
    private static function recordStorage(string $root, string $relative, callable $record): void
    {
        $path = $root.'/'.$relative;
        if (is_link($path) || ! is_dir($path)) {
            throw new InvalidArgumentException('The backup storage directory is missing or linked.');
        }
        $record($relative, $path);
        foreach (self::children($path) as $name) {
            $child = $relative.'/'.$name;
            if (is_dir($root.'/'.$child)) {
                self::recordStorage($root, $child, $record);
            } else {
                $record($child, $root.'/'.$child);
            }
        }
    }

    /** @return list<string> */
    private static function children(string $directory): array
    {
        $items = @scandir($directory, SCANDIR_SORT_NONE);
        if ($items === false) {
            throw new RuntimeException('A persistent storage directory could not be read.');
        }

        $items = array_values(array_diff($items, ['.', '..']));
        sort($items, SORT_STRING);

        return $items;
    }

    private static function regular(string $path): void
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1) {
            throw new InvalidArgumentException('A persistent backup file is missing, linked or has an unsupported file type.');
        }
    }

    private static function syncPath(string $path): void
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('A persistent backup path could not be opened for synchronization.');
        }

        try {
            if (! @fsync($handle)) {
                throw new RuntimeException('A persistent backup path could not be synchronized.');
            }
        } finally {
            fclose($handle);
        }
    }
}
