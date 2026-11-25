<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Kitsune\Release\SelfHostBackupSet;

beforeEach(function (): void {
    require_once dirname(__DIR__, 3).'/bin/release/Files.php';
    require_once dirname(__DIR__, 3).'/bin/release/SelfHostSqliteBackup.php';
    require_once dirname(__DIR__, 3).'/bin/release/SelfHostBackupSet.php';
    $this->testPath = realpath(sys_get_temp_dir()).'/kitsune-state-backup-'.bin2hex(random_bytes(10));
    mkdir($this->testPath, 0700);
});

afterEach(function (): void {
    File::deleteDirectory($this->testPath);
});

it('keeps persisted configuration, database and media together in a restorable private set', function (string $mode, string $sqlitePath): void {
    $state = $this->testPath.'/state';
    mkdir($state.'/storage/app/private/media', 0700, true);
    mkdir($state.'/storage/app/public', 0700);
    if ($mode === 'native') {
        mkdir($state.'/database', 0700);
    }
    $environment = selfHostBackupEnvironment($state.'/'.$sqlitePath);
    file_put_contents($state.'/.env', $environment);
    file_put_contents($state.'/storage/app/private/media/asset.bin', "\x00\x9f\x10sample file\x00");
    $sqlite = new SQLite3($state.'/'.$sqlitePath);
    $sqlite->exec('CREATE TABLE fixture (text TEXT NOT NULL)');
    $sqlite->exec("INSERT INTO fixture VALUES ('saved content')");
    $sqlite->close();

    $backup = $this->testPath.'/backup';
    $result = SelfHostBackupSet::create($mode, $state, $backup);
    SelfHostBackupSet::verify($mode, $backup, $result['inventory_sha256']);
    $other = $mode === 'native' ? 'docker' : 'native';
    expect(fn () => SelfHostBackupSet::verify($other, $backup, $result['inventory_sha256']))
        ->toThrow(RuntimeException::class, 'no longer match');
    expect($result['database_sha256'])->toBe(hash_file('sha256', $backup.'/database.sqlite'))
        ->and(file_get_contents($backup.'/.env'))->toBe($environment)
        ->and(fileperms($backup.'/.env') & 0077)->toBe(0)
        ->and(fileperms($backup) & 0077)->toBe(0)
        ->and(is_dir($backup.'/storage/app/public'))->toBeTrue();

    $stage = $this->testPath.'/restore-stage';
    expect(SelfHostBackupSet::stageRestore($mode, $backup, $result['inventory_sha256'], $stage)['sha256'])
        ->toBe($result['database_sha256'])
        ->and(file_get_contents($stage.'/.env'))->toBe($environment)
        ->and(file_get_contents($stage.'/storage/app/private/media/asset.bin'))->toBe("\x00\x9f\x10sample file\x00")
        ->and(hash_file('sha256', $stage.'/'.$sqlitePath))->toBe($result['database_sha256']);
    expect(fn () => SelfHostBackupSet::stageRestore($mode, $backup, $result['inventory_sha256'], $stage))
        ->toThrow(RuntimeException::class, 'new private directory');

    expect(SelfHostBackupSet::verifyRestored($mode, $stage, $result['inventory_sha256'])['sha256'])
        ->toBe($result['database_sha256']);
    $extra = dirname($stage.'/'.$sqlitePath).'/sidecar';
    file_put_contents($extra, 'unexpected');
    expect(fn () => SelfHostBackupSet::verifyRestored($mode, $stage, $result['inventory_sha256']))
        ->toThrow(RuntimeException::class, 'differs from the recorded backup inventory');
    unlink($extra);
    file_put_contents($stage.'/storage/app/private/media/asset.bin', 'modified');
    expect(fn () => SelfHostBackupSet::verifyRestored($mode, $stage, $result['inventory_sha256']))
        ->toThrow(RuntimeException::class, 'differs from the recorded backup inventory');

    $restored = $this->testPath.'/restored';
    File::copyDirectory($backup, $restored);
    SelfHostBackupSet::verify($mode, $restored, $result['inventory_sha256']);
    $database = new SQLite3($restored.'/database.sqlite', SQLITE3_OPEN_READONLY);
    try {
        expect($database->querySingle('SELECT text FROM fixture'))->toBe('saved content')
            ->and(file_get_contents($restored.'/storage/app/private/media/asset.bin'))->toBe("\x00\x9f\x10sample file\x00");
    } finally {
        $database->close();
    }

    expect(fn () => SelfHostBackupSet::create($mode, $state, $backup))
        ->toThrow(RuntimeException::class, 'must be a new private directory');
    file_put_contents($restored.'/storage/app/private/media/asset.bin', 'modified');
    expect(fn () => SelfHostBackupSet::verify($mode, $restored, $result['inventory_sha256']))
        ->toThrow(RuntimeException::class, 'no longer match');
    $stale = $this->testPath.'/stale-stage';
    expect(fn () => SelfHostBackupSet::stageRestore($mode, $restored, $result['inventory_sha256'], $stale))
        ->toThrow(RuntimeException::class, 'no longer match')
        ->and(file_exists($stale))->toBeFalse();
})->with([
    'native' => ['native', 'database/database.sqlite'],
    'Docker' => ['docker', 'database.sqlite'],
]);

it('refuses linked storage and cleans up only its incomplete backup', function (): void {
    $state = $this->testPath.'/state';
    mkdir($state.'/storage', 0700, true);
    $environment = selfHostBackupEnvironment($state.'/database.sqlite');
    file_put_contents($state.'/.env', $environment);
    $sqlite = new SQLite3($state.'/database.sqlite');
    $sqlite->exec('CREATE TABLE fixture (id INTEGER PRIMARY KEY)');
    $sqlite->close();
    symlink($state.'/.env', $state.'/storage/linked');
    $backup = $this->testPath.'/backup';

    expect(fn () => SelfHostBackupSet::create('docker', $state, $state.'/nested'))
        ->toThrow(InvalidArgumentException::class, 'outside the persistent state')
        ->and(fn () => SelfHostBackupSet::create('docker', $state, $backup))
        ->toThrow(InvalidArgumentException::class, 'linked persistent storage');

    expect(file_exists($backup))->toBeFalse()
        ->and(file_get_contents($state.'/.env'))->toBe($environment)
        ->and(is_link($state.'/storage/linked'))->toBeTrue();
});

function selfHostBackupEnvironment(string $database): string
{
    return "DB_CONNECTION=sqlite\nDB_DATABASE=$database\n"
        .'APP_KEY=base64:'.base64_encode(str_repeat('a', 32))."\n"
        .'APP_PREVIOUS_KEYS=base64:'.base64_encode(str_repeat('b', 32))."\n";
}

it('refuses to seal data without the configured database and encryption keys', function (string $case, string $message): void {
    $state = $this->testPath.'/state';
    mkdir($state.'/storage', 0700, true);
    mkdir($state.'/database', 0700);
    $database = $state.'/database/database.sqlite';
    $sqlite = new SQLite3($database);
    $sqlite->exec('CREATE TABLE fixture (id INTEGER PRIMARY KEY)');
    $sqlite->close();
    $environment = selfHostBackupEnvironment($database);
    $environment = match ($case) {
        'other_path' => str_replace($database, $this->testPath.'/other.sqlite', $environment),
        'db_url' => $environment."DB_URL=sqlite:///other-location\n",
        'current_key' => preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=', $environment),
        'previous_keys' => $environment."APP_PREVIOUS_KEYS=invalid\n",
    };
    file_put_contents($state.'/.env', $environment);
    $backup = $this->testPath.'/backup';

    expect(fn () => SelfHostBackupSet::create('native', $state, $backup))
        ->toThrow(InvalidArgumentException::class, $message);
    expect(file_exists($backup))->toBeFalse();
})->with([
    'other database' => ['other_path', 'select this installation'],
    'database URL override' => ['db_url', 'select this installation'],
    'missing key' => ['current_key', 'missing its valid application key'],
    'invalid previous key' => ['previous_keys', 'invalid previous application key'],
]);
