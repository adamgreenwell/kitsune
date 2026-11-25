<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Kitsune\Release\SelfHostSqliteBackup;

beforeEach(function (): void {
    require_once dirname(__DIR__, 3).'/bin/release/SelfHostSqliteBackup.php';
    $this->workspace = realpath(sys_get_temp_dir()).'/kitsune-backup-'.bin2hex(random_bytes(12));
    mkdir($this->workspace, 0700);
    mkdir($this->workspace.'/backup', 0700);
});

afterEach(function (): void {
    File::deleteDirectory($this->workspace);
});

it('publishes a durable SQLite backup that can be opened under a separate restore path', function (): void {
    $source = $this->workspace.'/live.sqlite';
    $database = new SQLite3($source);
    $database->exec('CREATE TABLE retained_content (title TEXT NOT NULL)');
    $database->exec("INSERT INTO retained_content VALUES ('before backup')");
    $database->close();

    $initial = hash_file('sha256', $source);
    $witness = SelfHostSqliteBackup::create($source, $this->workspace.'/backup');
    $backup = $this->workspace.'/backup/database.sqlite';
    $restored = $this->workspace.'/restored.sqlite';
    copy($backup, $restored);

    $database = new SQLite3($source);
    $database->exec("UPDATE retained_content SET title = 'after backup'");
    $database->close();

    $readback = new SQLite3($restored, SQLITE3_OPEN_READONLY);
    try {
        expect($readback->querySingle('SELECT title FROM retained_content'))->toBe('before backup')
            ->and($witness)->toBe(SelfHostSqliteBackup::verify($restored))
            ->and($witness['size'])->toBe(filesize($backup))
            ->and($witness['sha256'])->toBe(hash_file('sha256', $backup))
            ->and(fileperms($backup) & 0077)->toBe(0)
            ->and(hash_file('sha256', $source))->not->toBe($initial);
    } finally {
        $readback->close();
    }

    expect(fn () => SelfHostSqliteBackup::create($source, $this->workspace.'/backup'))
        ->toThrow(InvalidArgumentException::class, 'already occupies');
    expect(SelfHostSqliteBackup::verify($backup))->toBe($witness);
});

it('includes committed WAL data in a standalone database without copying its journal files', function (): void {
    $source = $this->workspace.'/live.sqlite';
    $writer = new SQLite3($source);
    try {
        expect($writer->querySingle('PRAGMA journal_mode = WAL'))->toBe('wal');
        $writer->exec('PRAGMA wal_autocheckpoint = 0');
        $writer->exec('CREATE TABLE retained_content (title TEXT NOT NULL)');
        $writer->exec("INSERT INTO retained_content VALUES ('committed in WAL')");
        $before = hash_file('sha256', $source);
        $walBefore = hash_file('sha256', $source.'-wal');

        $witness = SelfHostSqliteBackup::create($source, $this->workspace.'/backup');

        expect(hash_file('sha256', $source))->toBe($before)
            ->and(hash_file('sha256', $source.'-wal'))->toBe($walBefore)
            ->and(scandir($this->workspace.'/backup'))->toBe(['.', '..', 'database.sqlite']);
        $backup = $this->workspace.'/backup/database.sqlite';
        $readback = new SQLite3($backup, SQLITE3_OPEN_READONLY);
        try {
            expect($readback->querySingle('SELECT title FROM retained_content'))->toBe('committed in WAL')
                ->and(SelfHostSqliteBackup::verify($backup))->toBe($witness);
        } finally {
            $readback->close();
        }
    } finally {
        $writer->close();
    }
});

it('refuses a linked source, a backup sidecar and an invalid SQLite file without publishing data', function (): void {
    $invalid = $this->workspace.'/not-sqlite';
    file_put_contents($invalid, 'not a database');
    $linked = $this->workspace.'/link.sqlite';
    symlink($invalid, $linked);

    expect(fn () => SelfHostSqliteBackup::create($linked, $this->workspace.'/backup'))
        ->toThrow(InvalidArgumentException::class, 'regular, single-link')
        ->and(fn () => SelfHostSqliteBackup::create($invalid, $this->workspace.'/backup'))
        ->toThrow(RuntimeException::class, 'could not copy the source database');
    expect(scandir($this->workspace.'/backup'))->toBe(['.', '..']);

    $standalone = $this->workspace.'/backup/database.sqlite';
    $database = new SQLite3($standalone);
    $database->exec('CREATE TABLE marker (id INTEGER PRIMARY KEY)');
    $database->close();
    file_put_contents($standalone.'-journal', 'unexpected sidecar');

    expect(fn () => SelfHostSqliteBackup::verify($standalone))->toThrow(InvalidArgumentException::class, 'sidecar');
});
