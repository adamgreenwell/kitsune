<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Benchmarks;

use Dotenv\Dotenv;
use PDO;
use Pdo\Sqlite;
use RuntimeException;

require_once __DIR__.'/SchemaScaleDatabase.php';

/** A separate query-only connection to a synthetic benchmark fixture. @internal */
final class SchemaScaleOwnedReadConnection
{
    private function __construct(
        public readonly PDO $pdo,
        public readonly string $driver,
        public readonly int $rows,
        public readonly string $seedDigest,
    ) {}

    public static function open(string $root, string $application, string $fixture): self
    {
        require $application.'/vendor/autoload.php';
        $marker = json_decode(file_get_contents(self::privateFile($fixture, 'owned-experiment.json')), true, flags: JSON_THROW_ON_ERROR);
        $seed = json_decode(file_get_contents(self::privateFile($fixture, 'result.json')), true, flags: JSON_THROW_ON_ERROR);
        $rows = $marker['rows'] ?? null;
        $driver = $seed['engine'] ?? null;
        if (($marker['purpose'] ?? null) !== 'kitsune-schema-scale' || ($marker['root'] ?? null) !== $root
            || ! in_array($rows, [10000, 100000, 1000000], true) || ($seed['rows'] ?? null) !== $rows
            || ! in_array($driver, ['sqlite', 'pgsql', 'mysql', 'mariadb'], true)
            || ($seed['stage'] ?? null) !== 'consistent-app-'.$driver.'-seed-and-query-baseline'
            || ! is_int($seed['checks_passed'] ?? null) || $seed['checks_passed'] <= 0) {
            throw new RuntimeException('The benchmark marker and completed seed must match.');
        }

        if ($driver === 'sqlite') {
            $pdo = new PDO('sqlite:'.self::privateFile($fixture, 'scale.sqlite'), options: [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                Sqlite::ATTR_OPEN_FLAGS => Sqlite::OPEN_READONLY,
            ]);
            $pdo->exec('PRAGMA query_only = ON');
        } else {
            $env = Dotenv::parse(file_get_contents(self::privateFile($fixture, '.env')));
            $settings = [
                'driver' => $env['DB_CONNECTION'] ?? null,
                'host' => $env['DB_HOST'] ?? null,
                'port' => $env['DB_PORT'] ?? null,
                'database' => $env['DB_DATABASE'] ?? null,
                'username' => $env['DB_USERNAME'] ?? null,
                'password' => $env['DB_PASSWORD'] ?? null,
                'url' => $env['DB_URL'] ?? null,
                'unix_socket' => $env['DB_SOCKET'] ?? null,
            ];
            $owner = SchemaScaleDatabase::nativeConfiguration($marker, $settings, $root, $rows);
            if ($owner['driver'] !== $driver) {
                throw new RuntimeException('The engine differs from the completed seed.');
            }
            $pdoDriver = $driver === 'pgsql' ? 'pgsql' : 'mysql';
            $dsn = $pdoDriver.':host=127.0.0.1;port='.$owner['port'].';dbname='.$owner['database'];
            $pdo = new PDO($dsn, $settings['username'], $settings['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $pdo->exec($driver === 'pgsql' ? 'SET default_transaction_read_only = on' : 'SET SESSION TRANSACTION READ ONLY');
            $readOnly = $pdo->query(match ($driver) {
                'pgsql' => 'SHOW transaction_read_only',
                'mysql' => 'SELECT @@session.transaction_read_only',
                'mariadb' => 'SELECT @@session.tx_read_only',
            })->fetchColumn();
            if ($driver === 'pgsql' ? $readOnly !== 'on' : (int) $readOnly !== 1) {
                throw new RuntimeException('The plan connection must be read-only.');
            }

            $witness = $pdo->query('SELECT * FROM kitsune_scale_ownership')->fetchAll(PDO::FETCH_ASSOC);
            if (count($witness) !== 1 || $witness[0]['nonce'] !== $owner['nonce']
                || $witness[0]['root_sha256'] !== hash('sha256', $root)
                || (int) $witness[0]['rows'] !== $rows || $witness[0]['driver'] !== $driver) {
                throw new RuntimeException('The database is not the owned benchmark.');
            }
        }

        return new self($pdo, $driver, $rows, hash_file('sha256', self::privateFile($fixture, 'result.json')));
    }

    public static function privateFile(string $directory, string $name): string
    {
        $path = $directory.'/'.$name;
        if (! is_file($path) || is_link($path) || (fileperms($path) & 0077) !== 0) {
            throw new RuntimeException('The benchmark input must be a private file.');
        }

        return $path;
    }
}
