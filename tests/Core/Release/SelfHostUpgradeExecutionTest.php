<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Kitsune\Release\Manifest;
use Kitsune\Release\SelfHostBackupSet;
use Kitsune\Release\SelfHostDockerUpgrade;
use Kitsune\Release\SelfHostDockerVolume;
use Kitsune\Release\SelfHostNativeRuntime;
use Kitsune\Release\SelfHostNativeUpgrade;
use Kitsune\Release\SelfHostSqliteWitness;
use Kitsune\Release\SelfHostUpgradeAdmission;
use Kitsune\Release\SelfHostUpgradeExecution;
use Kitsune\Release\SelfHostUpgradeJournal as Journal;
use Kitsune\Release\Signature;

beforeEach(function (): void {
    foreach (['Files', 'Manifest', 'Signature', 'SelfHostSqliteBackup', 'SelfHostSqliteWitness', 'SelfHostBackupSet',
        'SelfHostUpgradeJournal', 'SelfHostUpgradeAdmission', 'SelfHostUpgradeExecution', 'SelfHostDockerVolume', 'SelfHostDockerUpgrade',
        'SelfHostNativeRuntime', 'SelfHostNativeUpgrade'] as $helper) {
        require_once dirname(__DIR__, 3).'/bin/release/'.$helper.'.php';
    }
    $this->workspace = realpath(sys_get_temp_dir()).'/kitsune-upgrade-execution-'.bin2hex(random_bytes(10));
    mkdir($this->workspace, 0700);
    $this->state = $this->workspace.'/state';
    $this->databasePath = $this->state.'/database.sqlite';
    mkdir($this->state.'/storage', 0700, true);
    file_put_contents($this->state.'/.env', 'APP_KEY=base64:'.base64_encode(str_repeat('a', 32))."\n"
        ."DB_CONNECTION=sqlite\nDB_DATABASE={$this->databasePath}\n");
    file_put_contents($this->state.'/storage/media.bin', 'retained media');
    $database = new SQLite3($this->databasePath);
    $database->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT, batch INT)');
    $database->exec('CREATE TABLE modules (handle TEXT, version TEXT, is_enabled INT, installed_at TEXT)');
    $database->exec("INSERT INTO migrations VALUES (1, 'fixture', 1)");
    $database->exec('CREATE TABLE articles (title TEXT NOT NULL)');
    $database->exec("INSERT INTO articles VALUES ('retained content')");
    $database->close();
    $this->journalPath = $this->workspace.'/journal';
    mkdir($this->journalPath, 0700);
    $this->backup = $this->workspace.'/backup';
    $this->admission = selfHostExecutionAdmission($this->databasePath);
    $this->plan = $this->admission->plan;
});

afterEach(function (): void {
    File::deleteDirectory($this->workspace);
});

it('durably journals a verified backup before migration and marks complete only after service verification', function (): void {
    $journal = Journal::open($this->journalPath);
    try {
        expect(fn () => Journal::open($this->journalPath))->toThrow(RuntimeException::class, 'Another operation');
        SelfHostUpgradeExecution::apply($journal, $this->admission, $this->state, $this->backup,
            function () use ($journal): void {
                expect($journal->state()['phase'])->toBe(Journal::MIGRATION_STARTED)
                    ->and($journal->state()['backup'])->toBe(trim(file_get_contents($this->backup.'/persisted-data.sha256')));
                $database = new SQLite3($this->databasePath);
                $database->exec('ALTER TABLE articles ADD COLUMN slug TEXT');
                $database->close();
            },
            fn () => expect($journal->state()['phase'])->toBe(Journal::STAGING_SERVICE),
            fn () => expect($journal->state()['phase'])->toBe(Journal::ACTIVATED),
            fn () => expect($journal->state()['phase'])->toBe(Journal::OPENING_TRAFFIC),
            fn () => expect($journal->state()['phase'])->toBe(Journal::OPENING_TRAFFIC),
        );
    } finally {
        $journal->close();
    }

    $journal = Journal::open($this->journalPath);
    expect($journal->state()['phase'])->toBe(Journal::COMPLETE);
    expect(fn () => $journal->begin($this->plan))->toThrow(RuntimeException::class, 'already used');
    $journal->close();
});

it('retains the migration barrier through interruption and recovers only the recorded verified backup', function (): void {
    $journal = Journal::open($this->journalPath);
    $unreachable = fn () => $this->fail('This stage was never reached.');
    expect(fn () => SelfHostUpgradeExecution::apply($journal, $this->admission, $this->state, $this->backup,
        function (): void {
            $database = new SQLite3($this->databasePath);
            $database->exec("UPDATE articles SET title = 'changed during migration'");
            $database->close();
            throw new RuntimeException('Interrupted during migration.');
        },
        $unreachable,
        $unreachable,
        $unreachable,
        $unreachable,
    ))->toThrow(RuntimeException::class, 'Interrupted during migration.');
    $journal->close();

    $journal = Journal::open($this->journalPath);
    expect($journal->state()['phase'])->toBe(Journal::MIGRATION_STARTED);
    expect(fn () => $journal->begin(str_repeat('b', 64)))->toThrow(RuntimeException::class, 'requires recovery');
    expect(fn () => SelfHostUpgradeExecution::recover($journal, str_repeat('b', 64), 'docker', $this->backup, $unreachable))
        ->toThrow(RuntimeException::class, 'only the recorded interrupted upgrade');
    expect(fn () => SelfHostUpgradeExecution::recover($journal, $this->plan, 'native', $this->backup, $unreachable))
        ->toThrow(RuntimeException::class, 'no longer match');

    SelfHostUpgradeExecution::recover($journal, $this->plan, 'docker', $this->backup, function (string $backup, string $inventory, string $digest): void {
        expect($backup)->toBe($this->backup)
            ->and($inventory)->toBe(trim(file_get_contents($this->backup.'/persisted-data.sha256')));
        $restored = $this->workspace.'/restored';
        expect(SelfHostBackupSet::stageRestore('docker', $backup, $inventory, $restored)['sha256'])->toBe($digest);
        $database = new SQLite3($restored.'/database.sqlite', SQLITE3_OPEN_READONLY);
        expect($database->querySingle('SELECT title FROM articles'))->toBe('retained content');
        $database->close();
    });
    expect($journal->state()['phase'])->toBe(Journal::RESTORED);
    expect(fn () => $journal->begin($this->plan))->toThrow(RuntimeException::class, 'already used');
    $journal->close();
});

it('refuses to enter migrations when the backup fails, and quarantines incomplete journal records', function (): void {
    $journal = Journal::open($this->journalPath);
    $unreachable = fn () => $this->fail('This stage was never reached.');
    mkdir($this->backup, 0700);
    expect(fn () => SelfHostUpgradeExecution::apply($journal, $this->admission, $this->state, $this->backup,
        $unreachable, $unreachable, $unreachable, $unreachable, $unreachable))
        ->toThrow(RuntimeException::class, 'new private directory');
    expect($journal->state()['phase'])->toBe(Journal::ABORTED);
    $journal->close();

    file_put_contents($this->journalPath.'/upgrades.jsonl', '{"plan":', FILE_APPEND);
    expect(fn () => Journal::open($this->journalPath))->toThrow(RuntimeException::class, 'incomplete or invalid record');
});

it('finishes forward after interrupted traffic publication and preserves writes after cutover', function (): void {
    $journal = Journal::open($this->journalPath);
    $nothing = static function (): void {};
    expect(fn () => SelfHostUpgradeExecution::apply($journal, $this->admission, $this->state, $this->backup,
        $nothing,
        $nothing,
        $nothing,
        function (): void {
            $db = new SQLite3($this->databasePath);
            $db->exec("INSERT INTO articles VALUES ('new traffic')");
            $db->close();
            throw new RuntimeException('Cutover acknowledgement lost.');
        },
        $nothing,
    ))->toThrow(RuntimeException::class, 'Cutover acknowledgement lost.');
    $journal->close();

    $journal = Journal::open($this->journalPath);
    expect($journal->state()['phase'])->toBe(Journal::OPENING_TRAFFIC);
    expect(fn () => SelfHostUpgradeExecution::recover($journal, $this->plan, 'docker', $this->backup,
        fn () => $this->fail('A live service must not be overwritten from the old backup.')))
        ->toThrow(RuntimeException::class, 'only the recorded interrupted upgrade');
    SelfHostUpgradeExecution::finish($journal, $this->plan, function (): void {
        $db = new SQLite3($this->databasePath, SQLITE3_OPEN_READONLY);
        expect($db->querySingle('SELECT COUNT(*) FROM articles'))->toBe(2);
        $db->close();
    }, $nothing, $nothing);
    expect($journal->state()['phase'])->toBe(Journal::COMPLETE);
    $journal->close();
});

it('records an interrupted pre-migration abort and prevents reuse of its plan', function (): void {
    $journal = Journal::open($this->journalPath);
    $journal->begin($this->plan);
    $journal->close();

    $journal = Journal::open($this->journalPath);
    expect(fn () => SelfHostUpgradeExecution::abortBeforeMigration($journal, str_repeat('b', 64)))
        ->toThrow(RuntimeException::class, 'Only the recorded upgrade');
    SelfHostUpgradeExecution::abortBeforeMigration($journal, $this->plan);
    expect($journal->state()['phase'])->toBe(Journal::ABORTED)
        ->and(fn () => $journal->begin($this->plan))->toThrow(RuntimeException::class, 'already used');
    $journal->close();
});

function selfHostExecutionAdmission(
    string $backup,
    ?string $runtimeOverride = null,
    string $mode = 'docker',
    ?string $originDigest = null,
    ?string $targetDigest = null,
): SelfHostUpgradeAdmission {
    static $private;
    $private ??= openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 3072]);
    $public = openssl_pkey_get_details($private)['key'];
    $pin = Signature::fingerprint($public);
    $manifest = static function (string $version, string $source) use ($private, $public, $pin): Manifest {
        $raw = "kitsune-release-manifest-v1\nversion\t$version\nchannel\ttest\nsource\t$source\n"
            .'tree'."\t".str_repeat('b', 40)."\nsignature\trsa-pkcs1-sha256\n"
            ."artifact\tfixture.tar.gz\tnative\tlinux-arm64\t1\t".str_repeat('c', 64)."\n"
            ."package\tkitsune/core\tdev-$source\t$source\t".str_repeat('d', 64)."\tsplit\n";
        openssl_sign($raw, $signature, $private, OPENSSL_ALGO_SHA256);

        return Signature::verify($raw, $signature, $public, $pin, $version, 'test');
    };
    $origin = $manifest('v1.0.0-test.1', str_repeat('a', 40));
    $target = $manifest('v1.0.0-test.2', str_repeat('b', 40));
    $originRuntime = $originDigest ?? str_repeat('e', 64);
    $targetRuntime = $targetDigest ?? str_repeat('f', 64);
    $record = [
        'format' => 'kitsune-self-host-admission-v1', 'mode' => $mode, 'platform' => 'linux-arm64',
        'origin_manifest_sha256' => hash('sha256', $origin->bytes()),
        'target_manifest_sha256' => hash('sha256', $target->bytes()),
        'origin_runtime_sha256' => $originRuntime, 'target_runtime_sha256' => $targetRuntime,
    ] + SelfHostSqliteWitness::inspect($backup);
    $raw = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
    openssl_sign($raw, $signature, $private, OPENSSL_ALGO_SHA256);

    return SelfHostUpgradeAdmission::verify($raw, $signature, $public, $pin, $origin, $target, $mode, 'linux-arm64',
        $originRuntime, $runtimeOverride ?? $targetRuntime, str_repeat('a', 64), str_repeat('b', 64));
}

it('refuses a signed transition for another runtime or a changed SQLite origin before migrations', function (): void {
    expect(fn () => selfHostExecutionAdmission($this->databasePath, str_repeat('a', 64)))
        ->toThrow(InvalidArgumentException::class, 'does not match the selected release and runtimes');
    $database = new SQLite3($this->databasePath);
    $database->exec('UPDATE migrations SET batch = 2');
    $database->close();
    $journal = Journal::open($this->journalPath);
    $unreachable = fn () => $this->fail('The changed origin must not advance to migration.');
    try {
        expect(fn () => SelfHostUpgradeExecution::apply($journal, $this->admission, $this->state, $this->backup,
            $unreachable, $unreachable, $unreachable, $unreachable, $unreachable))
            ->toThrow(InvalidArgumentException::class, 'differs from the admitted schema');
        expect($journal->state()['phase'])->toBe(Journal::ABORTED);
    } finally {
        $journal->close();
    }
});

it('runs the admitted Docker migration under the runtime lock and leaves the fenced volume unavailable', function (): void {
    $origin = $this->admission->origin->source."\n";
    $target = $this->admission->target;
    $receipt = implode("\n", ['kitsune-docker-installed-v2', $target->version, $target->source,
        $target->artifacts[0]['sha256'], hash('sha256', $target->bytes()), '']);
    file_put_contents($this->state.'/.installed', $origin);
    touch($this->state.'/.lock');
    $unreachable = fn () => $this->fail('An invalid Docker receipt must stop before migration.');
    $journal = Journal::open($this->journalPath);
    try {
        expect(fn () => SelfHostDockerUpgrade::migrate($journal, $this->admission, $this->state, $this->backup,
            $origin, str_replace($target->source, str_repeat('a', 40), $receipt), $unreachable))
            ->toThrow(InvalidArgumentException::class, 'does not match an admitted application artifact');
        expect($journal->state())->toBeNull()
            ->and(file_exists($this->backup))->toBeFalse();

        SelfHostDockerUpgrade::migrate($journal, $this->admission, $this->state, $this->backup, $origin, $receipt,
            function () use ($journal): void {
                expect($journal->state()['phase'])->toBe(Journal::MIGRATION_STARTED);
                expect(fn () => SelfHostDockerVolume::locked($this->state, fn () => $this->fail('Docker volume was unlocked.')))
                    ->toThrow(RuntimeException::class, 'holds the Docker volume');
                $database = new SQLite3($this->databasePath);
                $database->exec('ALTER TABLE articles ADD COLUMN slug TEXT');
                $database->close();
            },
        );
        expect($journal->state()['phase'])->toBe(Journal::MIGRATED)
            ->and(file_get_contents($this->state.'/.installed'))->toBe("kitsune-docker-upgrade-v1\n{$this->plan}\n");

        SelfHostDockerUpgrade::stageReceipt($journal, $this->admission, $this->state, $receipt);
        $journal->close();

        $journal = Journal::open($this->journalPath);
        SelfHostDockerUpgrade::stageReceipt($journal, $this->admission, $this->state, $receipt);
        expect($journal->state()['phase'])->toBe(Journal::STAGING_SERVICE)
            ->and(file_get_contents($this->state.'/.installed'))->toBe($receipt);
        $readiness = static function (): void {
            throw new RuntimeException('Private target is still starting.');
        };
        expect(fn () => SelfHostDockerUpgrade::confirmPrivateService($journal, $this->admission, $this->state, $receipt, $readiness))
            ->toThrow(RuntimeException::class, 'still starting')
            ->and($journal->state()['phase'])->toBe(Journal::STAGING_SERVICE);
        SelfHostDockerUpgrade::confirmPrivateService($journal, $this->admission, $this->state, $receipt, static function (): void {});
        expect($journal->state()['phase'])->toBe(Journal::ACTIVATED);
        $readback = static function (): void {};
        expect(fn () => SelfHostDockerUpgrade::confirmTraffic($journal, $this->admission, $this->state, $receipt, $readback))
            ->toThrow(RuntimeException::class, 'awaiting publication');

        SelfHostDockerUpgrade::beginTraffic($journal, $this->admission, $this->state, $receipt, $readback);
        $journal->close();
        $journal = Journal::open($this->journalPath);
        SelfHostDockerUpgrade::beginTraffic($journal, $this->admission, $this->state, $receipt, $readback);
        expect($journal->state()['phase'])->toBe(Journal::OPENING_TRAFFIC);
        expect(fn () => SelfHostDockerUpgrade::confirmTraffic($journal, $this->admission, $this->state, $receipt, $readiness))
            ->toThrow(RuntimeException::class, 'still starting')
            ->and($journal->state()['phase'])->toBe(Journal::OPENING_TRAFFIC);
        SelfHostDockerUpgrade::confirmTraffic($journal, $this->admission, $this->state, $receipt, $readback);
        expect($journal->state()['phase'])->toBe(Journal::COMPLETE);
    } finally {
        $journal->close();
    }
});

it('admits native migration under the installer lock and retains the old tree during staging', function (bool $interrupted, bool $recover): void {
    mkdir($this->state.'/database', 0700);
    mkdir($this->state.'/storage/app/public', 0700, true);
    $nativeDatabase = $this->state.'/database/database.sqlite';
    rename($this->databasePath, $nativeDatabase);
    file_put_contents($this->state.'/.env', 'APP_KEY=base64:'.base64_encode(str_repeat('a', 32))."\n"
        ."DB_CONNECTION=sqlite\nDB_DATABASE=$nativeDatabase\n");
    $installation = $this->workspace.'/installed';
    $staged = $this->workspace.'/staged';
    foreach ([$installation, $staged] as $root) {
        foreach (['public', 'vendor', 'bootstrap/cache'] as $directory) {
            mkdir($root.'/app/'.$directory, 0700, true);
        }
        foreach (['public/index.php', 'artisan', 'vendor/autoload.php', 'composer.lock'] as $file) {
            file_put_contents($root.'/app/'.$file, basename($root));
        }
        foreach (['.env' => '.env', 'storage' => 'storage', 'public/storage' => 'storage/app/public'] as $link => $target) {
            symlink($this->state.'/'.$target, $root.'/app/'.$link);
        }
    }
    $admission = selfHostExecutionAdmission($nativeDatabase, mode: 'native',
        originDigest: SelfHostNativeRuntime::fingerprint($installation.'/app', $this->state),
        targetDigest: SelfHostNativeRuntime::fingerprint($staged.'/app', $this->state));
    foreach ([$installation => $admission->origin, $staged => $admission->target] as $root => $manifest) {
        $receipt = $manifest->version."\n".$manifest->source."\n".$manifest->artifacts[0]['sha256']."\n";
        file_put_contents($root.'/.release', $receipt);
        if ($root === $installation) {
            file_put_contents($root.'/.installed', $receipt);
        }
    }
    $lock = $this->workspace.'/native-install.lock';
    touch($lock);
    $journal = Journal::open($this->journalPath);
    try {
        $offline = static function (): void {};
        $unexpected = fn () => $this->fail('The native migration was not admitted.');
        $held = fopen($lock, 'r+b');
        flock($held, LOCK_EX);
        try {
            expect(fn () => SelfHostNativeUpgrade::migrate($journal, $admission, $lock, $installation, $staged,
                $this->state, $this->backup, $unexpected, $unexpected))
                ->toThrow(RuntimeException::class, 'holds the installation lock');
        } finally {
            fclose($held);
        }

        file_put_contents($installation.'/app/artisan', 'unreviewed host change');
        expect(fn () => SelfHostNativeUpgrade::migrate($journal, $admission, $lock, $installation, $staged,
            $this->state, $this->backup, $offline, $unexpected))
            ->toThrow(InvalidArgumentException::class, 'differs from the admitted runtimes');
        expect($journal->state())->toBeNull()
            ->and(file_exists($this->backup))->toBeFalse();

        file_put_contents($installation.'/app/artisan', basename($installation));
        file_put_contents($staged.'/.installed', 'wrong receipt');
        expect(fn () => SelfHostNativeUpgrade::migrate($journal, $admission, $lock, $installation, $staged,
            $this->state, $this->backup, $offline, $unexpected))
            ->toThrow(RuntimeException::class, 'destinations are already occupied or inconsistent')
            ->and($journal->state())->toBeNull();
        unlink($staged.'/.installed');
        $cache = $staged.'/app/bootstrap/cache/config.php';
        file_put_contents($cache, 'fixture generated config');
        SelfHostNativeUpgrade::migrate($journal, $admission, $lock, $installation, $staged, $this->state, $this->backup,
            $offline, function () use ($nativeDatabase, $journal, $lock, $cache): void {
                expect(file_exists($cache))->toBeFalse();
                $check = fopen($lock, 'r+b');
                try {
                    expect(flock($check, LOCK_EX | LOCK_NB))->toBeFalse()
                        ->and($journal->state()['phase'])->toBe(Journal::MIGRATION_STARTED);
                } finally {
                    fclose($check);
                }
                $database = new SQLite3($nativeDatabase);
                $database->exec('ALTER TABLE articles ADD COLUMN slug TEXT');
                $database->close();
            });
        expect($journal->state()['phase'])->toBe(Journal::MIGRATED);
        $retained = $installation.'.before-'.$admission->plan;
        file_put_contents($cache, 'fixture with staging paths');
        if ($interrupted) {
            $journal->record($admission->plan, Journal::STAGING_SERVICE);
            rename($installation, $retained);
        }
        expect(SelfHostNativeUpgrade::stage($journal, $admission, $lock, $installation, $staged, $this->state, $offline))->toBe($retained)
            ->and(file_get_contents($retained.'/app/artisan'))->toBe('installed')
            ->and(file_get_contents($installation.'/app/artisan'))->toBe('staged')
            ->and(file_exists($staged))->toBeFalse()
            ->and(file_exists($installation.'/app/bootstrap/cache/config.php'))->toBeFalse();
        expect(SelfHostNativeUpgrade::stage($journal, $admission, $lock, $installation, $staged, $this->state, $offline))->toBe($retained)
            ->and($journal->state()['phase'])->toBe(Journal::STAGING_SERVICE);

        $readinessFailure = static function (): void {
            throw new RuntimeException('Private native check failed.');
        };
        expect(fn () => SelfHostNativeUpgrade::confirmPrivateService($journal, $admission, $installation, $this->state, $readinessFailure))
            ->toThrow(RuntimeException::class, 'check failed')
            ->and($journal->state()['phase'])->toBe(Journal::STAGING_SERVICE);
        SelfHostNativeUpgrade::confirmPrivateService($journal, $admission, $installation, $this->state, $offline);
        expect($journal->state()['phase'])->toBe(Journal::ACTIVATED);

        if ($recover) {
            $stateUser = fileowner($this->state);
            $stateGroup = filegroup($this->state);
            if ($stateUser === 0 || $stateGroup === 0) {
                $this->markTestSkipped('Run this fixture with an unprivileged owner.');
            }
            expect(fn () => SelfHostNativeUpgrade::recover($journal, $admission, $lock, $installation, $this->state,
                $this->backup, $stateUser, $stateGroup, $readinessFailure))
                ->toThrow(RuntimeException::class, 'check failed')
                ->and($journal->state()['phase'])->toBe(Journal::ACTIVATED);

            expect(fn () => SelfHostNativeUpgrade::recover($journal, $admission, $lock, $installation, $this->state,
                $installation.'/nested-backup', $stateUser, $stateGroup, $unexpected))
                ->toThrow(InvalidArgumentException::class, 'outside native cutover paths')
                ->and($journal->state()['phase'])->toBe(Journal::ACTIVATED);

            expect(fn () => SelfHostNativeUpgrade::recover($journal, $admission, $lock, $installation, $this->state,
                $this->backup, $stateUser + 1, $stateGroup, $offline))
                ->toThrow(RuntimeException::class, 'does not match the installer and runtime ownership')
                ->and($journal->state()['phase'])->toBe(Journal::ACTIVATED);

            SelfHostNativeUpgrade::recover($journal, $admission, $lock, $installation, $this->state,
                $this->backup, $stateUser, $stateGroup, $offline);
            $failedState = $this->state.'.failed-'.$admission->plan;
            $failedCode = $installation.'.failed-'.$admission->plan;
            $before = new SQLite3($this->state.'/database/database.sqlite', SQLITE3_OPEN_READONLY);
            $after = new SQLite3($failedState.'/database/database.sqlite', SQLITE3_OPEN_READONLY);
            try {
                expect($journal->state()['phase'])->toBe(Journal::RESTORED)
                    ->and(file_get_contents($installation.'/app/artisan'))->toBe('installed')
                    ->and(file_get_contents($failedCode.'/app/artisan'))->toBe('staged')
                    ->and(fileperms($this->state) & 0777)->toBe(0750)
                    ->and(fileperms($this->state.'/.env') & 0777)->toBe(0640)
                    ->and(fileperms($this->state.'/database/database.sqlite') & 0777)->toBe(0600)
                    ->and($before->querySingle("SELECT count(*) FROM pragma_table_info('articles') WHERE name = 'slug'"))->toBe(0)
                    ->and($after->querySingle("SELECT count(*) FROM pragma_table_info('articles') WHERE name = 'slug'"))->toBe(1);
            } finally {
                $before->close();
                $after->close();
            }

            return;
        }

        $receipt = file_get_contents($installation.'/.installed');
        file_put_contents($installation.'/.installed', 'wrong receipt');
        expect(fn () => SelfHostNativeUpgrade::beginTraffic($journal, $admission, $lock, $installation, $this->state, $unexpected))
            ->toThrow(RuntimeException::class, 'does not match the staged upgrade')
            ->and($journal->state()['phase'])->toBe(Journal::ACTIVATED);
        file_put_contents($installation.'/.installed', $receipt);

        $requireLock = function () use ($lock): void {
            $other = fopen($lock, 'r+b');
            try {
                expect(flock($other, LOCK_EX | LOCK_NB))->toBeFalse();
            } finally {
                fclose($other);
            }
        };
        SelfHostNativeUpgrade::beginTraffic($journal, $admission, $lock, $installation, $this->state, $requireLock);
        expect($journal->state()['phase'])->toBe(Journal::OPENING_TRAFFIC);
        expect(fn () => SelfHostNativeUpgrade::confirmTraffic($journal, $admission, $lock, $installation, $this->state, $readinessFailure))
            ->toThrow(RuntimeException::class, 'check failed')
            ->and($journal->state()['phase'])->toBe(Journal::OPENING_TRAFFIC);
        expect(fn () => SelfHostUpgradeExecution::recover($journal, $admission->plan, 'native', $this->backup, $unexpected))
            ->toThrow(RuntimeException::class, 'Recover only the recorded interrupted upgrade');
        SelfHostNativeUpgrade::beginTraffic($journal, $admission, $lock, $installation, $this->state, $requireLock);
        SelfHostNativeUpgrade::confirmTraffic($journal, $admission, $lock, $installation, $this->state, $requireLock);
        expect($journal->state()['phase'])->toBe(Journal::COMPLETE);
    } finally {
        $journal->close();
    }
})->with([
    'normal switch' => [false, false],
    'interrupted after retaining the old tree' => [true, false],
    'pre-traffic recovery' => [false, true],
]);
