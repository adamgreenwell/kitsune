<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Release\Manifest;
use Kitsune\Release\SelfHostInstallation;
use Kitsune\Release\SelfHostUpgradeAdmission;
use Kitsune\Release\SelfHostUpgradeJournal;
use Kitsune\Release\SelfHostUpgradePlan;
use Kitsune\Release\Signature;
use League\Flysystem\Config;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    require_once dirname(__DIR__, 3).'/bin/release/Manifest.php';
    require_once dirname(__DIR__, 3).'/bin/release/Files.php';
    require_once dirname(__DIR__, 3).'/bin/release/SelfHostInstallation.php';

    $this->fixtureRoot = realpath(sys_get_temp_dir()).'/kitsune-installed-'.bin2hex(random_bytes(10));
    $this->fixtureStorage = new LocalFilesystemAdapter($this->fixtureRoot);
});

afterEach(function (): void {
    $this->fixtureStorage->deleteDirectory('');
});

it('identifies Docker volumes by the persisted source receipt without opening database or configuration contents', function (): void {
    $source = str_repeat('a', 40);
    $state = $this->fixtureRoot.'/state';

    expect(SelfHostInstallation::inspect($state))->toBe(['mode' => 'docker', 'state' => 'empty']);
    $this->fixtureStorage->write('state/.lock', '', new Config);
    expect(SelfHostInstallation::inspect($state))->toBe(['mode' => 'docker', 'state' => 'empty']);

    $configuration = "APP_KEY=example-test-placeholder\n";
    $this->fixtureStorage->write('state/.env', $configuration, new Config);
    $this->fixtureStorage->write('state/database.sqlite', 'database placeholder', new Config);
    mkdir($state.'/storage');
    expect(SelfHostInstallation::inspect($state)['state'])->toBe('incomplete');

    $this->fixtureStorage->write('state/.installed', $source."\n", new Config);
    expect(SelfHostInstallation::inspect($state))->toBe(['mode' => 'docker', 'state' => 'installed', 'source' => $source])
        ->and(file_get_contents($state.'/.env'))->toBe($configuration)
        ->and(file_get_contents($state.'/database.sqlite'))->toBe('database placeholder');
});

it('identifies unfinished Docker writes and requires a real single-link receipt', function (): void {
    $state = $this->fixtureRoot.'/state';
    $this->fixtureStorage->write('state/.pending-env.scratch', 'initial setup', new Config);
    expect(SelfHostInstallation::inspect($state)['state'])->toBe('incomplete');

    $this->fixtureStorage->write('actual-receipt', str_repeat('a', 40)."\n", new Config);
    symlink($this->fixtureRoot.'/actual-receipt', $state.'/.installed');
    expect(SelfHostInstallation::inspect($state)['state'])->toBe('incomplete');
});

it('requires matching native completion receipts and the actual persistent paths', function (): void {
    $source = str_repeat('a', 40);
    $archive = str_repeat('b', 64);
    $record = "v1.0.2\n$source\n$archive\n";
    $state = $this->fixtureRoot.'/state';
    $install = $this->fixtureRoot.'/install';

    expect(SelfHostInstallation::inspect($state, $install))->toBe(['mode' => 'native', 'state' => 'empty']);
    foreach ([
        'install/.release' => $record,
        'install/app/public/index.php' => 'host front controller',
        'install/app/composer.lock' => 'host Composer lock',
        'state/.env' => 'do not read me',
        'state/database/database.sqlite' => 'database placeholder',
        'state/storage/app/public/keep.txt' => 'host media',
    ] as $path => $value) {
        $this->fixtureStorage->write($path, $value, new Config);
    }

    foreach (['.env' => '.env', 'storage' => 'storage', 'public/storage' => 'storage/app/public'] as $link => $destination) {
        symlink($state.'/'.$destination, $install.'/app/'.$link);
    }

    expect(SelfHostInstallation::inspect($state, $install)['state'])->toBe('incomplete');
    $this->fixtureStorage->write('install/.installed', str_replace('v1.0.2', 'v1.0.1', $record), new Config);
    expect(SelfHostInstallation::inspect($state, $install)['state'])->toBe('incomplete');

    $this->fixtureStorage->write('install/.installed', $record, new Config);
    expect(SelfHostInstallation::inspect($state, $install))->toBe([
        'mode' => 'native',
        'state' => 'installed',
        'version' => 'v1.0.2',
        'source' => $source,
        'archive_sha256' => $archive,
    ]);

    unlink($install.'/app/.env');
    symlink($state.'/other.env', $install.'/app/.env');
    expect(SelfHostInstallation::inspect($state, $install)['state'])->toBe('incomplete')
        ->and(file_get_contents($state.'/storage/app/public/keep.txt'))->toBe('host media');
});

it('refuses ambiguous and overlapping roots', function (): void {
    expect(fn () => SelfHostInstallation::inspect('state'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => SelfHostInstallation::inspect($this->fixtureRoot.'/../state'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => SelfHostInstallation::inspect($this->fixtureRoot, $this->fixtureRoot.'/app'))->toThrow(InvalidArgumentException::class);
});

function selfHostManifest(string $version, string $source, string $archive, string $contents): string
{
    return "kitsune-release-manifest-v1\nversion\t$version\nchannel\ttest\nsource\t$source\n"
        ."tree\t".str_repeat('b', 40)."\nsignature\trsa-pkcs1-sha256\n"
        ."artifact\t$archive\tnative\tlinux-arm64\t".strlen($contents)."\t".hash('sha256', $contents)."\n"
        ."package\tkitsune/core\tdev-$source\t$source\t".str_repeat('c', 64)."\tsplit\n";
}

it('authenticates the exact requested release files, reports their identities and keeps unknown transitions closed', function (): void {
    require_once dirname(__DIR__, 3).'/bin/release/Signature.php';
    $script = dirname(__DIR__, 3).'/bin/self-host-upgrade.php';
    $state = $this->fixtureRoot.'/state';
    $storage = $this->fixtureStorage;
    $config = new Config;
    $source = str_repeat('a', 40);
    $targetSource = str_repeat('b', 40);

    $storage->write('state/.installed', $source."\n", $config);
    $storage->write('state/.env', 'configuration kept private', $config);
    $storage->write('state/database.sqlite', 'database placeholder', $config);
    mkdir($state.'/storage');
    $origin = selfHostManifest('v1.0.0-test', $source, 'old.tar.gz', 'old archive');
    $target = selfHostManifest('v1.0.1-test', $targetSource, 'new.tar.gz', 'new archive');
    $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 3072]);
    $public = openssl_pkey_get_details($private)['key'];
    $pin = Signature::fingerprint($public);
    foreach (['origin' => $origin, 'target' => $target] as $side => $manifest) {
        openssl_sign($manifest, $signature, $private, OPENSSL_ALGO_SHA256);
        $storage->write("$side.manifest", $manifest, $config);
        $storage->write("$side.sig", $signature, $config);
    }
    $storage->write('key.pem', $public, $config);
    $storage->write('new.tar.gz', 'new archive', $config);
    $root = $this->fixtureRoot;
    $options = [
        '--mode=docker', "--state=$state", '--platform=linux-arm64', "--key=$root/key.pem", "--fingerprint=$pin",
        "--origin-manifest=$root/origin.manifest", "--origin-signature=$root/origin.sig", '--origin-version=v1.0.0-test',
        '--origin-channel=test', "--target-manifest=$root/target.manifest", "--target-signature=$root/target.sig",
        '--target-version=v1.0.1-test', '--target-channel=test', "--target-archive=$root/new.tar.gz",
    ];
    $plan = fn (array $arguments = []) => (new Process(
        [PHP_BINARY, $script, 'plan', ...array_replace($options, $arguments)],
    ))->run();

    $process = new Process([PHP_BINARY, $script, 'plan', ...$options]);
    expect($process->run())->toBe(2);
    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($result['decision'])->toBe('docker_image_identity_required')
        ->and($result['upgrade_admitted'])->toBeFalse()
        ->and($result['origin']['manifest_sha256'])->toBe(hash('sha256', $origin))
        ->and($result['target']['source'])->toBe($targetSource)
        ->and($result['target']['archive']['sha256'])->toBe(hash('sha256', 'new archive'))
        ->and($process->getOutput())->not->toContain('configuration kept private');

    expect($plan([2 => '--platform=linux-amd64']))->toBe(1)
        ->and($plan([10 => "--target-signature=$root/origin.sig"]))->toBe(1)
        ->and($plan([4 => '--fingerprint='.str_repeat('0', 64)]))->toBe(1);

    $storage->write('new.tar.gz', 'bad archive', $config);
    expect($plan())->toBe(1)
        ->and(file_get_contents($state.'/.installed'))->toBe($source."\n")
        ->and(file_get_contents($state.'/database.sqlite'))->toBe('database placeholder')
        ->and(file_get_contents($state.'/.env'))->toBe('configuration kept private');
});

it('requests a native upgrade approval from signed releases and a separate pre-migration snapshot', function (): void {
    require_once dirname(__DIR__, 3).'/bin/release/Signature.php';
    require_once dirname(__DIR__, 3).'/bin/release/SelfHostUpgradeJournal.php';
    require_once dirname(__DIR__, 3).'/bin/release/SelfHostUpgradeAdmission.php';
    $root = $this->fixtureRoot;
    $paths = "$root/state";
    $installation = "$root/install";
    $staging = "$root/staged";
    $journalPath = "$root/journal";
    $snapshot = "$root/rehearsed-origin.sqlite";
    $storage = $this->fixtureStorage;
    $config = new Config;
    $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 3072]);
    $public = openssl_pkey_get_details($private)['key'];
    $pin = Signature::fingerprint($public);
    $storage->write('key.pem', $public, $config);
    foreach (['origin' => [$installation, 'v1.0.0-test', str_repeat('a', 40)],
        'target' => [$staging, 'v1.0.1-test', str_repeat('b', 40)]] as $side => [$runtime, $version, $source]) {
        foreach (['public/index.php', 'artisan', 'vendor/autoload.php', 'composer.lock'] as $file) {
            $storage->write(basename($runtime).'/app/'.$file, $side, $config);
        }
        if ($side === 'target') {
            mkdir($runtime.'/app/storage', 0700);
            $tar = new Process(['/usr/bin/tar', '-czf', "$root/$side.tar.gz", '-C', $runtime.'/app', '.']);
            $tar->mustRun();
            rmdir($runtime.'/app/storage');
            $contents = file_get_contents("$root/$side.tar.gz");
        } else {
            $contents = "$side archive";
        }
        $release = selfHostManifest($version, $source, "$side.tar.gz", $contents);
        openssl_sign($release, $signature, $private, OPENSSL_ALGO_SHA256);
        $storage->write("$side.manifest", $release, $config);
        $storage->write("$side.sig", $signature, $config);
        $receipt = "$version\n$source\n".hash('sha256', $contents)."\n";
        file_put_contents($runtime.'/.release', $receipt);
        if ($side === 'origin') {
            file_put_contents($runtime.'/.installed', $receipt);
        }
        foreach (['.env' => '.env', 'storage' => 'storage', 'public/storage' => 'storage/app/public'] as $link => $destination) {
            symlink($paths.'/'.$destination, $runtime.'/app/'.$link);
        }
    }

    mkdir($journalPath, 0700);
    mkdir($paths.'/database', 0700, true);
    mkdir($paths.'/storage/app/public', 0700, true);
    file_put_contents($paths.'/.env', 'kept out of the approval');
    $databasePath = $paths.'/database/database.sqlite';
    $database = new SQLite3($databasePath);
    $database->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT, batch INT)');
    $database->exec('CREATE TABLE modules (handle TEXT, version TEXT, is_enabled INT, installed_at TEXT)');
    $database->close();
    copy($databasePath, $snapshot);
    $checksum = hash_file('sha256', $databasePath);

    $script = dirname(__DIR__, 3).'/bin/self-host-upgrade.php';
    $options = [
        '--mode=native', "--installation=$installation", "--state=$paths", '--platform=linux-arm64', "--key=$root/key.pem",
        "--fingerprint=$pin", "--origin-manifest=$root/origin.manifest", "--origin-signature=$root/origin.sig",
        '--origin-version=v1.0.0-test', '--origin-channel=test', "--target-manifest=$root/target.manifest",
        "--target-signature=$root/target.sig", '--target-version=v1.0.1-test', '--target-channel=test',
        "--target-archive=$root/target.tar.gz", "--staging=$staging", "--journal=$journalPath", "--pre-migration-snapshot=$snapshot",
    ];
    $request = static fn (array $overrides = []): Process => new Process(
        [PHP_BINARY, $script, 'request-native-admission', ...array_replace($options, $overrides)],
    );
    $live = $request([17 => "--pre-migration-snapshot=$databasePath"]);
    expect($live->run())->toBe(1)
        ->and($live->getErrorOutput())->toContain('outside the live and staged installation')
        ->and(file_exists($journalPath.'/installation-id'))->toBeFalse();

    $incompletePath = "$root/incomplete.sqlite";
    $incomplete = new SQLite3($incompletePath);
    $incomplete->exec('CREATE TABLE unrelated (id INTEGER PRIMARY KEY)');
    $incomplete->close();
    $invalid = $request([17 => "--pre-migration-snapshot=$incompletePath"]);
    expect($invalid->run())->toBe(1)
        ->and($invalid->getOutput())->toBe('')
        ->and($invalid->getErrorOutput())->toContain('lacks the required schema')
        ->and(file_exists($journalPath.'/installation-id'))->toBeFalse();

    file_put_contents($staging.'/app/artisan', 'unexpected staged bytes');
    $changed = $request();
    expect($changed->run())->toBe(1)
        ->and($changed->getErrorOutput())->toContain('differs from the signed target archive')
        ->and(file_exists($journalPath.'/installation-id'))->toBeFalse();
    file_put_contents($staging.'/app/artisan', 'target');

    $first = $request();
    expect($first->run())->toBe(0);
    $firstPayload = json_decode($first->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    $second = $request();
    expect($second->run())->toBe(0);
    $secondPayload = json_decode($second->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($firstPayload['format'])->toBe('kitsune-self-host-admission-v1')
        ->and($firstPayload['installation_id'])->toBe($secondPayload['installation_id'])
        ->and($firstPayload['attempt_nonce'])->not->toBe($secondPayload['attempt_nonce'])
        ->and($firstPayload['origin_runtime_sha256'])->not->toBe($firstPayload['target_runtime_sha256'])
        ->and(hash_file('sha256', $databasePath))->toBe($checksum)
        ->and(hash_file('sha256', $snapshot))->toBe($checksum)
        ->and($first->getOutput())->not->toContain('kept out of the approval');

    $approval = $first->getOutput();
    $approvalKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 3072]);
    $approvalPublic = openssl_pkey_get_details($approvalKey)['key'];
    $approvalPin = Signature::fingerprint($approvalPublic);
    openssl_sign($approval, $approvalSignature, $approvalKey, OPENSSL_ALGO_SHA256);
    $storage->write('approval.json', $approval, $config);
    $storage->write('approval.sig', $approvalSignature, $config);
    $storage->write('approval.pem', $approvalPublic, $config);
    $verify = static fn (string $nonce, string $fingerprint = ''): Process => new Process([
        PHP_BINARY, $script, 'verify-native-admission', ...array_slice($options, 0, -1),
        "--approval=$root/approval.json", "--approval-signature=$root/approval.sig", "--approval-key=$root/approval.pem",
        '--approval-fingerprint='.($fingerprint === '' ? $approvalPin : $fingerprint), "--attempt-nonce=$nonce",
    ]);
    $verified = $verify($firstPayload['attempt_nonce']);
    expect($verified->run())->toBe(0)
        ->and(json_decode($verified->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toMatchArray([
            'verified' => true,
            'plan' => hash('sha256', "kitsune-self-host-plan-v1\n".$approval),
        ]);
    $wrongAttempt = $verify($secondPayload['attempt_nonce']);
    expect($wrongAttempt->run())->toBe(1)
        ->and($wrongAttempt->getErrorOutput())->toContain('does not authorize this installation and attempt');

    $migrate = new Process([
        PHP_BINARY, $script, 'migrate-native', ...array_slice($options, 0, -1),
        "--approval=$root/approval.json", "--approval-signature=$root/approval.sig", "--approval-key=$root/approval.pem",
        "--approval-fingerprint=$approvalPin", "--attempt-nonce={$firstPayload['attempt_nonce']}", "--backup=$root/backup",
    ]);
    expect($migrate->run())->toBe(1)
        ->and($migrate->getErrorOutput())->toContain('requires the installed application, state and journal paths')
        ->and(file_exists($root.'/backup'))->toBeFalse()
        ->and(hash_file('sha256', $databasePath))->toBe($checksum);

    $sameKey = $verify($firstPayload['attempt_nonce'], $pin);
    expect($sameKey->run())->toBe(1)
        ->and($sameKey->getErrorOutput())->toContain('distinct release and upgrade approval keys');
    $journal = SelfHostUpgradeJournal::open($journalPath);
    try {
        $plan = hash('sha256', "kitsune-self-host-plan-v1\n".$approval);
        $reopen = static fn (): SelfHostUpgradeAdmission => SelfHostUpgradeAdmission::reopenNative(
            $approval, $approvalSignature, $approvalPublic, $approvalPin,
            Manifest::parse(file_get_contents("$root/origin.manifest")), Manifest::parse(file_get_contents("$root/target.manifest")),
            'linux-arm64', null, $journal, $paths, $firstPayload['attempt_nonce'],
        );
        expect($reopen)->toThrow(RuntimeException::class, 'does not match the recorded native upgrade');
        $journal->begin($plan);
        rename($installation, $installation.'.interrupted');
        rename($paths, $paths.'.interrupted');
        try {
            expect($reopen()->plan)->toBe($plan);
        } finally {
            rename($paths.'.interrupted', $paths);
            rename($installation.'.interrupted', $installation);
        }
        $journal->record($plan, SelfHostUpgradeJournal::ABORTED);
    } finally {
        $journal->close();
    }
    $used = $verify($firstPayload['attempt_nonce']);
    expect($used->run())->toBe(1)
        ->and($used->getErrorOutput())->toContain('already used')
        ->and($firstPayload['target_archive_sha256'])->toBe(hash_file('sha256', "$root/target.tar.gz"));

    file_put_contents($staging.'/.release', "unexpected\n");
    $refused = $request();
    expect($refused->run())->toBe(1)
        ->and($refused->getErrorOutput())->toContain('verified target archive');
});

it('matches native receipts by version, source, platform and archive digest', function (): void {
    require_once dirname(__DIR__, 3).'/bin/release/SelfHostUpgradePlan.php';
    $source = str_repeat('a', 40);
    $archiveName = 'origin.tar.gz';
    $contents = 'original archive';
    $this->fixtureStorage->write($archiveName, $contents, new Config);
    $archive = $this->fixtureRoot.'/'.$archiveName;
    $origin = Manifest::parse(selfHostManifest('v1.0.0-test', $source, $archiveName, $contents));
    $target = Manifest::parse(selfHostManifest('v1.0.1-test', str_repeat('b', 40), $archiveName, $contents));
    $record = [
        'mode' => 'native', 'state' => 'installed', 'version' => $origin->version, 'source' => $source,
        'archive_sha256' => hash('sha256', $contents),
    ];

    expect(SelfHostUpgradePlan::build($record, $origin, $origin, $archive, 'linux-arm64')['decision'])
        ->toBe('already_at_target')
        ->and(SelfHostUpgradePlan::build($record, $origin, $target, $archive, 'linux-arm64')['decision'])
        ->toBe('transition_requires_admission')
        ->and(SelfHostUpgradePlan::build($record, $origin, $target, $archive, 'linux-arm64')['upgrade_admitted'])
        ->toBeFalse();
    foreach (['version' => 'v1.1.0', 'source' => str_repeat('b', 40), 'archive_sha256' => str_repeat('a', 64)] as $field => $value) {
        expect(SelfHostUpgradePlan::build(array_replace($record, [$field => $value]), $origin, $target, $archive, 'linux-arm64')['decision'])
            ->toBe('origin_receipt_mismatch');
    }
});

it('reports an interrupted or completed versioned Docker receipt with its exact artifact identity', function (): void {
    $state = $this->fixtureRoot.'/state';
    $version = 'v1.0.1+'.str_repeat('a', 121);
    $source = str_repeat('a', 40);
    $archive = str_repeat('b', 64);
    $manifest = str_repeat('c', 64);
    $identity = "$version\n$source\n$archive\n$manifest\n";
    $config = new Config;
    $expected = [
        'version' => $version, 'source' => $source,
        'archive_sha256' => $archive, 'manifest_sha256' => $manifest,
    ];

    $this->fixtureStorage->write('state/.installed', "kitsune-docker-installing-v2\n".$identity, $config);
    expect(SelfHostInstallation::inspect($state))->toBe([
        'mode' => 'docker', 'state' => 'incomplete', 'reason' => 'The first Docker installation has not completed.',
    ] + $expected);

    $this->fixtureStorage->write('state/.installed', "kitsune-docker-installed-v2\n".$identity, $config);
    expect(SelfHostInstallation::inspect($state)['state'])->toBe('incomplete');
    $this->fixtureStorage->write('state/.env', 'private configuration', $config);
    $this->fixtureStorage->write('state/database.sqlite', 'database placeholder', $config);
    mkdir($state.'/storage');

    expect(SelfHostInstallation::inspect($state))->toBe(['mode' => 'docker', 'state' => 'installed'] + $expected);
});
