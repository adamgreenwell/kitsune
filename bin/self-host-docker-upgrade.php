<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Dotenv\Dotenv;
use Kitsune\Release\Files;
use Kitsune\Release\Manifest;
use Kitsune\Release\SelfHostDockerUpgrade;
use Kitsune\Release\SelfHostDockerVolume;
use Kitsune\Release\SelfHostModuleUpgrade;
use Kitsune\Release\SelfHostNativeRuntime;
use Kitsune\Release\SelfHostUpgradeAdmission;
use Kitsune\Release\SelfHostUpgradeExecution;
use Kitsune\Release\SelfHostUpgradeJournal;
use Kitsune\Release\SelfHostUpgradePlan;
use Kitsune\Release\Signature;

foreach (['Files', 'Manifest', 'Signature', 'SelfHostUpgradePlan', 'SelfHostUpgradeJournal', 'SelfHostSqliteBackup',
    'SelfHostSqliteWitness', 'SelfHostBackupSet', 'SelfHostUpgradeAdmission', 'SelfHostUpgradeExecution', 'SelfHostDockerVolume',
    'SelfHostDockerUpgrade', 'SelfHostNativeRuntime', 'SelfHostModuleUpgrade'] as $component) {
    require_once __DIR__.'/release/'.$component.'.php';
}

const APP = '/srv/kitsune';
const STATE = '/var/lib/kitsune';
const CONTROLS = '/controls';
const CUSTODY = '/custody';
const RESTORED = '/restored';
const IMAGE_RECEIPT = '/usr/local/share/kitsune-release/installed';

umask(0077);

try {
    if (PHP_SAPI !== 'cli' || ! isset($argv) || posix_geteuid() !== 33 || posix_getegid() !== 33) {
        throw new RuntimeException('Run the Docker helper as the Kitsune runtime account inside the selected image.');
    }
    $phase = $argv[1] ?? '';
    if ($phase === 'origin') {
        if (count($argv) !== 2) {
            throw new InvalidArgumentException('The origin image inventory takes no arguments.');
        }

        $receipt = is_file(IMAGE_RECEIPT) ? Files::control(IMAGE_RECEIPT, 512)
            : Files::control('/usr/local/share/kitsune-source-commit', 64);
        $packages = [];
        foreach (glob(APP.'/vendor/kitsune/*', GLOB_ONLYDIR | GLOB_ERR) ?: [] as $directory) {
            $name = 'kitsune/'.basename($directory);
            if (preg_match('~\Akitsune/[a-z0-9]+(?:-[a-z0-9]+)*\z~D', $name) === 1) {
                $packages[$name] = SelfHostNativeRuntime::packageFingerprint($directory);
            }
        }
        echo json_encode(['receipt' => $receipt, 'packages' => $packages], JSON_THROW_ON_ERROR)."\n";
        exit(0);
    }

    if (count($argv) !== 8 || ! in_array($phase, ['request', 'migrate', 'stage', 'recover', 'abort', 'confirm-private', 'begin-traffic', 'confirm-traffic'], true)) {
        throw new InvalidArgumentException('Use the Docker host upgrade script to select a phase.');
    }
    [, , $platform, $originImage, $targetImage, $originArchive, $dataBinding, $metadata] = $argv;
    foreach ([$originImage, $targetImage, $originArchive, $dataBinding] as $digest) {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1) {
            throw new InvalidArgumentException('Select exact Docker image and archive digests.');
        }
    }
    $originMetadata = json_decode($metadata, true, 8, JSON_THROW_ON_ERROR);
    if (! is_array($originMetadata) || ! is_string($originMetadata['receipt'] ?? null) || ! is_array($originMetadata['packages'] ?? null)) {
        throw new InvalidArgumentException('The selected origin image has no usable package inventory.');
    }
    $originReceipt = $originMetadata['receipt'];
    $originPackage = static function (string $handle) use ($originMetadata): string {
        $digest = $originMetadata['packages'][$handle] ?? null;
        if (! is_string($digest) || preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1) {
            throw new InvalidArgumentException("The selected origin image has no package custody for $handle.");
        }

        return $digest;
    };

    $release = controlJson('selection.json');
    $targetLeaf = $release['target_archive'] ?? null;
    foreach (['origin_version', 'origin_channel', 'target_version', 'target_channel', 'release_fingerprint', 'approval_fingerprint'] as $field) {
        if (! is_string($release[$field] ?? null)) {
            throw new InvalidArgumentException('The Docker release selection is incomplete.');
        }
    }
    if (! is_string($targetLeaf) || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}\.tar\.gz\z/D', $targetLeaf) !== 1
        || hash_equals($release['release_fingerprint'], $release['approval_fingerprint'])) {
        throw new InvalidArgumentException('Select a target archive basename and distinct release and approval keys.');
    }
    $publicKey = Files::control(CONTROLS.'/release.pem', 16384);
    $manifest = static fn (string $side): Manifest => Signature::verify(
        Files::control(CONTROLS.'/'.$side.'.manifest', Manifest::MAX_BYTES),
        Files::control(CONTROLS.'/'.$side.'.sig', 1024),
        $publicKey,
        $release['release_fingerprint'],
        $release[$side.'_version'],
        $release[$side.'_channel'],
    );
    $origin = $manifest('origin');
    $target = $manifest('target');
    $expectedOrigin = implode("\n", ['kitsune-docker-installed-v2', $origin->version, $origin->source,
        $originArchive, hash('sha256', $origin->bytes()), '']);
    SelfHostDockerUpgrade::requireRelease($expectedOrigin, $origin, $platform, archiveDigest: $originArchive);
    if (! hash_equals($expectedOrigin, $originReceipt) && ! hash_equals($origin->source."\n", $originReceipt)) {
        throw new InvalidArgumentException('The stopped origin image differs from the verified origin release.');
    }
    // A managed origin image may still serve a completed source-only receipt from an older install.
    // On recovery use the selected image's own receipt, since the original volume holds the fence or target.
    $legacyOrigin = $origin->source."\n";
    if (in_array($phase, ['request', 'migrate', 'abort'], true)
        && hash_equals($legacyOrigin, Files::control(STATE.'/.installed', 512))) {
        $originReceipt = $legacyOrigin;
    }
    $targetReceipt = Files::control(IMAGE_RECEIPT, 512);
    if (in_array($phase, ['request', 'migrate'], true)) {
        $archive = SelfHostUpgradePlan::verifiedTargetArchive($target, CONTROLS.'/'.$targetLeaf, $platform)['sha256'];
    } else {
        $archive = controlJson('approval.json')['target_archive_sha256'] ?? null;
        if (! is_string($archive)) {
            throw new InvalidArgumentException('The signed admission must identify its target archive.');
        }
    }
    SelfHostDockerUpgrade::requireRelease($targetReceipt, $target, $platform, archiveDigest: $archive);

    $bootstrap = realpath(APP.'/vendor/autoload.php');
    if ($bootstrap === false || ! is_file($bootstrap)) {
        throw new RuntimeException('The selected target image has no application autoloader.');
    }
    require_once $bootstrap;
    $identity = SelfHostDockerVolume::installationIdentity(STATE, originReceipt: $phase === 'request' ? $originReceipt : null);
    $journalPath = CUSTODY.'/journal';
    if ($phase === 'request' && ! is_dir($journalPath) && ! @mkdir($journalPath, 0700) && ! is_dir($journalPath)) {
        throw new RuntimeException('The private Docker upgrade journal could not be created.');
    }
    $journal = SelfHostUpgradeJournal::open($journalPath, hash('sha256', "kitsune-docker-volume-v1\n".$identity."\n".$dataBinding));
    try {
        if ($phase === 'request') {
            $journal->requireAvailable();
            SelfHostDockerVolume::locked(STATE, static fn (SelfHostDockerVolume $volume) => $volume->requireReceipt($originReceipt));
            echo SelfHostUpgradeAdmission::request($origin, $target, 'docker', $platform, $originImage, $targetImage, $archive,
                static fn (): string => $journal->initializeDeployment(STATE), bin2hex(random_bytes(32)), CONTROLS.'/pre-migration.sqlite');
            exit(0);
        }

        $raw = Files::control(CONTROLS.'/approval.json', 4096);
        $nonce = controlJson('approval.json')['attempt_nonce'] ?? null;
        if (! is_string($nonce)) {
            throw new InvalidArgumentException('The signed approval must identify the reviewed attempt.');
        }
        $admission = SelfHostUpgradeAdmission::verify($raw, Files::control(CONTROLS.'/approval.sig', 1024),
            Files::control(CONTROLS.'/approval.pem', 16384), $release['approval_fingerprint'], $origin, $target,
            'docker', $platform, $originImage, $targetImage, $archive, $journal->deploymentIdentity(STATE), $nonce);
        $backup = CUSTODY.'/backup-'.$admission->plan;
        match ($phase) {
            'migrate' => migrateDocker($journal, $admission, $backup, $originReceipt, $targetReceipt, $originPackage),
            'stage' => SelfHostDockerUpgrade::stageReceipt($journal, $admission, STATE, $targetReceipt),
            'confirm-private' => SelfHostDockerUpgrade::confirmPrivateService($journal, $admission, STATE, $targetReceipt, static function (): void {}),
            'begin-traffic' => SelfHostDockerUpgrade::beginTraffic($journal, $admission, STATE, $targetReceipt, static function (): void {}),
            'confirm-traffic' => SelfHostDockerUpgrade::confirmTraffic($journal, $admission, STATE, $targetReceipt, static function (): void {}),
            'recover' => SelfHostDockerUpgrade::recover($journal, $admission, STATE, $backup, RESTORED, $originReceipt),
            'abort' => SelfHostDockerVolume::locked(STATE, static function (SelfHostDockerVolume $volume) use ($journal, $admission, $originReceipt): void {
                $volume->requireReceipt($originReceipt);
                SelfHostUpgradeExecution::abortBeforeMigration($journal, $admission->plan);
            }),
        };
        echo json_encode(['state' => $journal->state(), 'backup' => $backup], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
    } catch (Throwable $failure) {
        $recorded = $journal->state();
        if ($recorded !== null) {
            fwrite(STDERR, "Docker upgrade journal phase is {$recorded['phase']}.\n");
        }
        throw $failure;
    } finally {
        $journal->close();
    }
} catch (InvalidArgumentException|RuntimeException $failure) {
    fwrite(STDERR, 'Docker upgrade refused: '.$failure->getMessage()."\n");
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, "Docker upgrade could not read the selected release or approval inputs.\n");
    exit(1);
}

/** @return array<string, mixed> */
function controlJson(string $file): array
{
    $data = json_decode(Files::control(CONTROLS.'/'.$file, 4096), true, 8, JSON_THROW_ON_ERROR);
    if (! is_array($data)) {
        throw new InvalidArgumentException('The Docker upgrade requires object-shaped control files.');
    }

    return $data;
}

/** @param Closure(string):string $originPackage */
function migrateDocker(
    SelfHostUpgradeJournal $journal,
    SelfHostUpgradeAdmission $admission,
    string $backup,
    string $originReceipt,
    string $targetReceipt,
    Closure $originPackage,
): void {
    $plan = static fn (string $database): array => SelfHostModuleUpgrade::plan(
        $database, $admission->origin, $admission->target, $originPackage, APP,
    );
    SelfHostDockerUpgrade::migrate($journal, $admission, STATE, $backup, $originReceipt, $targetReceipt,
        static fn () => throw new RuntimeException('The Docker migration was not prepared from its verified backup.'),
        static function () use ($backup, $plan): Closure {
            $modules = $plan($backup.'/database.sqlite');

            return static function () use ($modules): void {
                dockerArtisan(['migrate', '--force', '--no-interaction']);
                foreach ($modules['upgrades'] as $handle) {
                    dockerArtisan(['kitsune:module', 'upgrade', $handle, '--no-interaction']);
                }
                SelfHostModuleUpgrade::verify(STATE.'/database.sqlite', $modules['expected']);
            };
        },
        beforeBackup: static function () use ($plan): void {
            try {
                $settings = Dotenv::parse(Files::control(STATE.'/.env', 262144));
            } catch (Throwable) {
                throw new RuntimeException('The installed Docker configuration could not be validated.');
            }
            if (($settings['QUEUE_CONNECTION'] ?? null) !== 'sync') {
                throw new RuntimeException('Stop and qualify externally managed writers before Docker migration.');
            }

            $plan(STATE.'/database.sqlite');
        });
}

/** @param list<string> $arguments */
function dockerArtisan(array $arguments): void
{
    $process = proc_open([PHP_BINARY, APP.'/artisan', ...$arguments], [0 => ['file', '/dev/null', 'r'], 1 => STDERR, 2 => STDERR],
        $pipes, APP, ['PATH' => '/usr/local/bin:/usr/bin:/bin']);
    if ($process === false || proc_close($process) !== 0) {
        throw new RuntimeException('The admitted Docker migration failed. Keep ingress closed and inspect the journal.');
    }
}
