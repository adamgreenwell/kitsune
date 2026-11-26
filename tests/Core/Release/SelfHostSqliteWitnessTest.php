<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Kitsune\Release\SelfHostSqliteBackup;
use Kitsune\Release\SelfHostSqliteWitness;

beforeEach(function (): void {
    require_once dirname(__DIR__, 3).'/bin/release/SelfHostSqliteBackup.php';
    require_once dirname(__DIR__, 3).'/bin/release/SelfHostSqliteWitness.php';
    $this->root = realpath(sys_get_temp_dir()).'/kitsune-witness-'.bin2hex(random_bytes(10));
    mkdir($this->root, 0700);
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

it('binds the schema, ledger and module receipts of an isolated SQLite backup without hashing content', function (): void {
    $source = $this->root.'/source.sqlite';
    $database = new SQLite3($source);
    $database->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT NOT NULL, batch INT NOT NULL)');
    $database->exec('CREATE TABLE modules (handle TEXT UNIQUE, version TEXT, is_enabled INT, installed_at TEXT)');
    $database->exec('CREATE TABLE entries (title TEXT NOT NULL)');
    $database->exec("INSERT INTO migrations VALUES (1, '2026_01_01_add_entries', 1)");
    $database->exec("INSERT INTO modules VALUES ('kitsune/person', '1.0.0', 0, '2026-01-01 00:00:00')");
    $database->exec("INSERT INTO entries VALUES ('fixture content')");
    $database->close();

    mkdir($this->root.'/origin', 0700);
    SelfHostSqliteBackup::create($source, $this->root.'/origin');
    $copy = $this->root.'/origin/database.sqlite';
    $before = hash_file('sha256', $copy);
    $origin = SelfHostSqliteWitness::inspect($copy);
    expect(hash_file('sha256', $copy))->toBe($before);

    $changes = [
        "UPDATE entries SET title = 'different fixture'" => [],
        'UPDATE migrations SET batch = 2' => ['migrations_sha256'],
        'UPDATE modules SET is_enabled = 1' => ['modules_sha256'],
        'CREATE INDEX example_title ON entries (title)' => ['schema_sha256'],
    ];
    foreach ($changes as $sql => $changed) {
        $trial = $this->root.'/trial.sqlite';
        copy($copy, $trial);
        $db = new SQLite3($trial);
        $db->exec($sql);
        $db->close();
        $actual = SelfHostSqliteWitness::inspect($trial);
        foreach ($origin as $key => $hash) {
            expect($actual[$key] !== $hash)->toBe(in_array($key, $changed, true));
        }
    }
});

it('requires an origin to resolve any recorded module uninstall before upgrade admission', function (bool $pending): void {
    $source = $this->root.'/module-origin.sqlite';
    $database = new SQLite3($source);
    $database->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT NOT NULL, batch INT NOT NULL)');
    $database->exec('CREATE TABLE modules (handle TEXT UNIQUE, version TEXT, is_enabled INT, installed_at TEXT)');
    $database->exec('CREATE TABLE module_uninstalls (handle TEXT PRIMARY KEY, started_at TEXT NOT NULL)');
    if ($pending) {
        // The ordinary module receipt has already been removed.
        $database->exec("INSERT INTO module_uninstalls VALUES ('kitsune/person', '2026-01-01 00:00:00')");
    }
    $database->close();

    mkdir($this->root.'/origin', 0700);
    SelfHostSqliteBackup::create($source, $this->root.'/origin');
    $copy = $this->root.'/origin/database.sqlite';
    $before = hash_file('sha256', $copy);

    if ($pending) {
        expect(fn () => SelfHostSqliteWitness::inspect($copy))->toThrow(RuntimeException::class, 'Recover the interrupted module uninstall');
    } else {
        expect(SelfHostSqliteWitness::inspect($copy))->toHaveKeys(['schema_sha256', 'migrations_sha256', 'modules_sha256']);
    }
    expect(hash_file('sha256', $copy))->toBe($before);
})->with([false, true]);
