<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Closure;
use FilesystemIterator;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/** Claims the database namespace before a subprocess can migrate or remove it. */
final class CommerceRaceNamespace
{
    private const MARKER = 'race_owner';

    private readonly string $receipt;

    private readonly string $driver;

    public function __construct(private readonly Connection $connection, private readonly string $name, string $directory)
    {
        $this->driver = $connection->getDriverName();
        if (preg_match('/\A[fp][0-9a-f]{5}_\z/D', $name) !== 1 || ! is_dir($directory)
            || ($this->driver === 'pgsql' ? $connection->getConfig('search_path') !== $name : $connection->getTablePrefix() !== $name)) {
            throw new RuntimeException('Invalid disposable commerce race namespace.');
        }
        $this->receipt = $directory.'/database-owner';
    }

    public function prepare(string $mode): void
    {
        if ($this->driver === 'sqlite') {
            // Each SQLite database lives in the parent process's private fixture directory.
            return;
        }

        // An advisory lock belongs to this connection. Losing it must stop the fixture, never retry the SQL unlocked.
        $this->connection->setReconnector(static function (): never {
            throw new RuntimeException('The disposable commerce race database connection was lost.');
        });

        match ($mode) {
            'init' => $this->withLock(fn () => $this->claim()),
            'cleanup' => $this->withLock(fn () => $this->cleanup()),
            default => $this->assertOwner(),
        };
    }

    public static function prepareChild(Connection $connection, string $name, string $directory, string $mode): void
    {
        try {
            (new self($connection, $name, $directory))->prepare($mode);
        } catch (Throwable $failure) {
            // Laravel's installed handler can render an uncaught exception and leave a standalone PHP child at exit 0.
            $message = $failure::class === RuntimeException::class ? $failure->getMessage()
                : 'Commerce race namespace verification failed ('.$failure::class.').';
            fwrite(STDERR, $message.PHP_EOL);
            exit(1);
        }
    }

    public static function copyApplication(string $source, string $target): void
    {
        File::ensureDirectoryExists($target);
        foreach (new FilesystemIterator($source, FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item->getFilename() === 'vendor' || $item->isLink()) {
                continue;
            }
            $destination = $target.'/'.$item->getFilename();
            if ($item->isDir()) {
                if (str_ends_with($destination, '/bootstrap/cache')) {
                    File::ensureDirectoryExists($destination);
                } else {
                    self::copyApplication($item->getPathname(), $destination);
                }
            } else {
                File::copy($item->getPathname(), $destination);
            }
        }
    }

    private function claim(): void
    {
        if ($this->hasNamespace() || is_file($this->receipt)) {
            throw new RuntimeException('The disposable commerce race namespace is already in use.');
        }

        $token = bin2hex(random_bytes(32));
        $receipt = @fopen($this->receipt, 'xb');
        if ($receipt === false) {
            throw new RuntimeException('Could not reserve the commerce race receipt.');
        }
        try {
            if (! chmod($this->receipt, 0600) || fwrite($receipt, $token) !== strlen($token)) {
                throw new RuntimeException('Could not write the commerce race receipt.');
            }
        } finally {
            fclose($receipt);
        }

        if ($this->driver === 'pgsql') {
            $this->connection->statement('CREATE SCHEMA "'.$this->name.'"');
        }
        // Store the witness in the same DDL statement as the marker. No table can be adopted on retry.
        $table = $this->connection->getQueryGrammar()->wrapTable(self::MARKER);
        $this->connection->statement("CREATE TABLE {$table} AS SELECT CAST('{$token}' AS CHAR(64)) AS owner_token");
        $this->assertOwner();
    }

    private function assertOwner(): void
    {
        $token = @file_get_contents($this->receipt);
        if (! is_string($token) || preg_match('/\A[0-9a-f]{64}\z/D', $token) !== 1
            || ! $this->connection->getSchemaBuilder()->hasTable(self::MARKER)) {
            throw new RuntimeException('The disposable commerce race namespace has no verified owner.');
        }
        $witnesses = $this->connection->table(self::MARKER)->useWritePdo()->limit(2)->pluck('owner_token')->all();
        if (count($witnesses) !== 1 || ! is_string($witnesses[0]) || ! hash_equals($token, $witnesses[0])) {
            throw new RuntimeException('The disposable commerce race namespace belongs to another run.');
        }
    }

    private function cleanup(): void
    {
        if (! is_file($this->receipt) || ! $this->hasNamespace()) {
            return;
        }
        $this->assertOwner();
        if ($this->driver === 'pgsql') {
            $this->connection->statement('DROP SCHEMA "'.$this->name.'" CASCADE');

            return;
        }

        $schema = $this->connection->getSchemaBuilder();
        $schema->disableForeignKeyConstraints();
        try {
            foreach (array_reverse($this->prefixedTables()) as $table) {
                if ($table !== self::MARKER) {
                    $schema->drop($table);
                }
            }
            // Keep the witness until every other owned table has been removed, so cleanup can be retried.
            $schema->drop(self::MARKER);
        } finally {
            $schema->enableForeignKeyConstraints();
        }
    }

    private function hasNamespace(): bool
    {
        if ($this->driver === 'pgsql') {
            return $this->connection->selectOne('select 1 from pg_catalog.pg_namespace where nspname = ?', [$this->name], false) !== null;
        }

        return $this->prefixedTables() !== [];
    }

    /** @return list<string> */
    private function prefixedTables(): array
    {
        $tables = $this->connection->getSchemaBuilder()->getTableListing($this->connection->getDatabaseName(), false);
        $owned = [];
        foreach ($tables as $table) {
            if (str_starts_with($table, $this->name)) {
                $owned[] = substr($table, strlen($this->name));
            }
        }

        return $owned;
    }

    private function withLock(Closure $work): void
    {
        $key = hash('sha256', $this->connection->getDatabaseName().':'.$this->name);
        if ($this->driver === 'pgsql') {
            $this->connection->transaction(function () use ($key, $work): void {
                $this->connection->selectOne('select pg_advisory_xact_lock(hashtext(?))', [$key], false);
                $work();
            });

            return;
        }

        $lock = $this->connection->selectOne('select get_lock(?, 10) as acquired', [$key], false);
        if ((int) ($lock->acquired ?? 0) !== 1) {
            throw new RuntimeException('Could not lock the disposable commerce race namespace.');
        }
        try {
            $work();
        } finally {
            $this->connection->selectOne('select release_lock(?)', [$key], false);
        }
    }
}
