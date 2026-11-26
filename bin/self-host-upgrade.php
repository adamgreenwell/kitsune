<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Release\Files;
use Kitsune\Release\Manifest;
use Kitsune\Release\SelfHostInstallation;
use Kitsune\Release\SelfHostNativeArchive;
use Kitsune\Release\SelfHostNativeHost;
use Kitsune\Release\SelfHostNativeRuntime;
use Kitsune\Release\SelfHostUpgradeAdmission;
use Kitsune\Release\SelfHostUpgradeJournal;
use Kitsune\Release\SelfHostUpgradePlan;
use Kitsune\Release\Signature;

require_once __DIR__.'/release/Manifest.php';
require_once __DIR__.'/release/Files.php';
require_once __DIR__.'/release/Signature.php';
require_once __DIR__.'/release/SelfHostInstallation.php';
require_once __DIR__.'/release/SelfHostUpgradePlan.php';

try {
    if (PHP_SAPI !== 'cli' || ! isset($argv)) {
        throw new InvalidArgumentException('Self-host upgrade tooling requires CLI arguments.');
    }

    $command = $argv[1] ?? null;
    $planOptions = [
        'mode', 'state', 'platform', 'key', 'fingerprint',
        'origin-manifest', 'origin-signature', 'origin-version', 'origin-channel',
        'target-manifest', 'target-signature', 'target-version', 'target-channel', 'target-archive',
    ];
    $nativeOptions = [...$planOptions, 'staging', 'journal'];
    $approvalOptions = [...$nativeOptions, 'approval', 'approval-signature', 'approval-key', 'approval-fingerprint', 'attempt-nonce'];
    $required = match ($command) {
        'inspect' => ['mode', 'state'],
        'plan' => $planOptions,
        'request-native-admission' => [...$nativeOptions, 'pre-migration-snapshot'],
        'verify-native-admission', 'stage-native' => $approvalOptions,
        'confirm-native-private', 'publish-native' => array_diff($approvalOptions, ['target-archive', 'staging']),
        'migrate-native' => [...$approvalOptions, 'backup'],
        'recover-native' => [...array_diff($approvalOptions, ['target-archive', 'staging']), 'backup'],
        default => throw new InvalidArgumentException('Choose inspect, plan or a native upgrade command.'),
    };

    $options = [];
    foreach (array_slice($argv, 2) as $argument) {
        if (! str_starts_with($argument, '--') || ! str_contains($argument, '=')) {
            throw new InvalidArgumentException('Explicit --name=value options are required.');
        }
        [$name, $value] = explode('=', substr($argument, 2), 2);
        if ((! in_array($name, $required, true) && $name !== 'installation') || isset($options[$name]) || $value === '') {
            throw new InvalidArgumentException('Unknown, duplicate or empty option.');
        }
        $options[$name] = $value;
    }

    if (array_diff($required, array_keys($options)) !== []) {
        throw new InvalidArgumentException('All command options are required.');
    }
    if (! in_array($options['mode'], ['native', 'docker'], true)
        || (($options['mode'] === 'native') !== isset($options['installation']))) {
        throw new InvalidArgumentException('Use native with a separate installation root, or docker with volume state only.');
    }

    $recordedNative = in_array($command, ['stage-native', 'recover-native', 'confirm-native-private', 'publish-native'], true);
    $installation = $recordedNative ? null : SelfHostInstallation::inspect($options['state'], $options['installation'] ?? null);
    if ($command === 'inspect') {
        fwrite(STDOUT, json_encode($installation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
        exit($installation['state'] === 'incomplete' ? 2 : 0);
    }

    $key = Files::control($options['key'], 16384);
    $readManifest = static fn (string $side): Manifest => Signature::verify(
        Files::control($options[$side.'-manifest'], Manifest::MAX_BYTES),
        Files::control($options[$side.'-signature'], 1024),
        $key,
        $options['fingerprint'],
        $options[$side.'-version'],
        $options[$side.'-channel'],
    );
    $origin = $readManifest('origin');
    $target = $readManifest('target');
    if ($recordedNative) {
        fwrite(STDOUT, recordedNativeOperation($command, $options, $origin, $target));
        exit(0);
    }
    $result = SelfHostUpgradePlan::build($installation, $origin, $target, $options['target-archive'], $options['platform']);
    if (in_array($command, ['request-native-admission', 'verify-native-admission', 'migrate-native'], true)) {
        fwrite(STDOUT, nativeAdmission($command, $options, $result, $origin, $target));
        exit(0);
    }

    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n");
    exit($result['decision'] === 'already_at_target' ? 0 : 2);
} catch (InvalidArgumentException|JsonException|RuntimeException $failure) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Self-host upgrade preflight refused: '.$failure->getMessage()."\n");
    }
    exit(1);
} catch (Throwable) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Self-host upgrade preflight could not read the supplied release or rehearsal files.\n");
    }
    exit(1);
}

/**
 * @param  array<string, string>  $options
 * @param  array{decision:string,target:array{archive:array{sha256:string}}}  $plan
 */
function nativeAdmission(string $command, array $options, array $plan, Manifest $origin, Manifest $target): string
{
    if ($options['mode'] !== 'native' || $plan['decision'] !== 'transition_requires_admission') {
        throw new InvalidArgumentException('A native admission request requires a completed origin installation and a distinct verified target.');
    }

    $installation = $options['installation'];
    $staging = $options['staging'];
    $snapshot = $options['pre-migration-snapshot'] ?? null;
    $state = $options['state'];
    $journalPath = $options['journal'];
    if ($command === 'migrate-native') {
        requireNativeHostPaths($options);
    }
    if (realpath($installation) !== $installation || realpath($state) !== $state) {
        throw new InvalidArgumentException('Use canonical application and persistent state directories.');
    }
    if ($staging === $installation || dirname($staging) !== dirname($installation)
        || realpath($staging) !== $staging || ! is_dir($staging)) {
        throw new InvalidArgumentException('Use a distinct canonical staging directory beside the native installation.');
    }

    foreach ([$installation, $staging, $state] as $root) {
        if (($snapshot !== null && ($snapshot === $root || str_starts_with($snapshot, $root.'/')))
            || $journalPath === $root || str_starts_with($journalPath, $root.'/') || str_starts_with($root, $journalPath.'/')) {
            throw new InvalidArgumentException('Keep the pre-migration snapshot and private journal outside the live and staged installation.');
        }
    }
    if ($snapshot !== null && (realpath($snapshot) !== $snapshot || ! is_file($snapshot))) {
        throw new InvalidArgumentException('Use the canonical pre-migration SQLite snapshot from the rehearsal.');
    }

    $archive = $plan['target']['archive']['sha256'];
    $receipt = $target->version."\n".$target->source."\n".$archive."\n";
    if (Files::control($staging.'/.release', 256) !== $receipt || scandir($staging) !== ['.', '..', '.release', 'app']) {
        throw new InvalidArgumentException('The staged application must identify the verified target archive and be uninstalled.');
    }

    foreach (['SelfHostNativeRuntime', 'SelfHostNativeArchive', 'SelfHostSqliteBackup', 'SelfHostSqliteWitness', 'SelfHostUpgradeAdmission', 'SelfHostUpgradeJournal'] as $helper) {
        require_once __DIR__.'/release/'.$helper.'.php';
    }
    if ($snapshot === null && (! is_file($journalPath.'/upgrades.jsonl') || ! is_file($journalPath.'/installation-id'))) {
        throw new InvalidArgumentException('Use the initialized private journal for this installation.');
    }
    $journal = SelfHostUpgradeJournal::open($journalPath);
    try {
        $journal->requireAvailable();
        $originRuntime = SelfHostNativeRuntime::fingerprint($installation.'/app', $state);
        $targetRuntime = SelfHostNativeRuntime::fingerprint($staging.'/app', $state);
        if (! hash_equals($targetRuntime, SelfHostNativeArchive::fingerprint($options['target-archive'], $archive, $state))) {
            throw new InvalidArgumentException('The staged native runtime differs from the signed target archive.');
        }

        if ($snapshot === null) {
            if (hash_equals($options['fingerprint'], $options['approval-fingerprint'])) {
                throw new InvalidArgumentException('Use distinct release and upgrade approval keys.');
            }
            $approval = SelfHostUpgradeAdmission::verify(
                Files::control($options['approval'], 4096),
                Files::control($options['approval-signature'], 1024),
                Files::control($options['approval-key'], 16384),
                $options['approval-fingerprint'],
                $origin, $target, 'native', $options['platform'], $originRuntime, $targetRuntime, $archive,
                $journal->deploymentIdentity($state), $options['attempt-nonce'],
            );

            $journal->requireAvailable($approval->plan);

            if ($command === 'migrate-native') {
                foreach (['SelfHostBackupSet', 'SelfHostUpgradeExecution', 'SelfHostNativeUpgrade', 'SelfHostModuleUpgrade'] as $helper) {
                    require_once __DIR__.'/release/'.$helper.'.php';
                }

                return runNativePhase($journal, $approval,
                    static fn () => SelfHostNativeHost::migrate($journal, $approval, dirname(__DIR__), $staging, $options['backup']),
                    $options['backup']);
            }

            return json_encode(['verified' => true, 'plan' => $approval->plan], JSON_THROW_ON_ERROR)."\n";
        }

        return SelfHostUpgradeAdmission::request($origin, $target, 'native', $options['platform'], $originRuntime,
            $targetRuntime, $archive, static fn () => $journal->initializeDeployment($state), bin2hex(random_bytes(32)), $snapshot);
    } finally {
        $journal->close();
    }
}

/** @param array<string,string> $options */
function recordedNativeOperation(string $command, array $options, Manifest $origin, Manifest $target): string
{
    requireNativeHostPaths($options);
    if (hash_equals($options['fingerprint'], $options['approval-fingerprint'])) {
        throw new InvalidArgumentException('Use distinct release and upgrade approval keys.');
    }
    $archive = $command === 'stage-native'
        ? SelfHostUpgradePlan::verifiedTargetArchive($target, $options['target-archive'], $options['platform'])
        : null;
    $journalPath = $options['journal'];
    if (! is_file($journalPath.'/upgrades.jsonl') || ! is_file($journalPath.'/installation-id')) {
        throw new InvalidArgumentException('Use the initialized private journal for this installation.');
    }
    foreach (['SelfHostNativeRuntime', 'SelfHostSqliteBackup', 'SelfHostBackupSet', 'SelfHostSqliteWitness',
        'SelfHostUpgradeAdmission', 'SelfHostUpgradeJournal', 'SelfHostUpgradeExecution', 'SelfHostNativeUpgrade', 'SelfHostNativeHealth'] as $helper) {
        require_once __DIR__.'/release/'.$helper.'.php';
    }

    $journal = SelfHostUpgradeJournal::open($journalPath);
    try {
        $admission = SelfHostUpgradeAdmission::reopenNative(
            Files::control($options['approval'], 4096),
            Files::control($options['approval-signature'], 1024),
            Files::control($options['approval-key'], 16384),
            $options['approval-fingerprint'],
            $origin, $target, $options['platform'], $archive['sha256'] ?? null, $journal, $options['state'], $options['attempt-nonce'],
        );

        return runNativePhase($journal, $admission, static function () use ($command, $options, $journal, $admission): void {
            match ($command) {
                'stage-native' => SelfHostNativeHost::stage($journal, $admission, $options['staging']),
                'confirm-native-private' => SelfHostNativeHost::confirmPrivate($journal, $admission),
                'publish-native' => SelfHostNativeHost::publish($journal, $admission),
                'recover-native' => SelfHostNativeHost::recover($journal, $admission, $options['backup']),
            };
        }, $options['backup'] ?? null);
    } finally {
        $journal->close();
    }
}

/** @param array<string,string> $options */
function requireNativeHostPaths(array $options): void
{
    require_once __DIR__.'/release/SelfHostNativeHost.php';
    if (($options['installation'] ?? null) !== SelfHostNativeHost::INSTALLATION || $options['state'] !== SelfHostNativeHost::STATE
        || $options['journal'] !== SelfHostNativeHost::JOURNAL) {
        throw new InvalidArgumentException('The native command requires the installed application, state and journal paths.');
    }
}

/** @param Closure():void $operation */
function runNativePhase(SelfHostUpgradeJournal $journal, SelfHostUpgradeAdmission $admission, Closure $operation, ?string $backup = null): string
{
    try {
        $operation();
    } catch (Throwable $failure) {
        $recorded = $journal->state();
        if ($recorded !== null && $recorded['plan'] === $admission->plan) {
            fwrite(STDERR, "Native upgrade journal phase is {$recorded['phase']}.\n");
            if ($recorded['phase'] === SelfHostUpgradeJournal::OPENING_TRAFFIC) {
                fwrite(STDERR, "Repeat publish-native to finish the recorded traffic handoff.\n");
            } elseif (! in_array($recorded['phase'], [SelfHostUpgradeJournal::ABORTED, SelfHostUpgradeJournal::COMPLETE], true)) {
                fwrite(STDERR, "Keep services fenced until the journal is resolved.\n");
            }
            if ($backup !== null) {
                fwrite(STDERR, "Native backup path is $backup.\n");
            }
        }

        throw $failure;
    }

    $result = ['plan' => $admission->plan, 'phase' => $journal->state()['phase']];
    if ($backup !== null) {
        $result['backup'] = $backup;
    }

    return json_encode($result, JSON_THROW_ON_ERROR)."\n";
}
