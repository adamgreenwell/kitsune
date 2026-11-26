<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Release;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Offline migration in the verified target container, against the stopped source volume.
 * Docker's host controller selects the exact image and volume before opening the admission.
 */
final class SelfHostDockerUpgrade
{
    /**
     * @param  Closure():void  $runTargetMigrations
     * @param  (Closure(): (Closure():void))|null  $prepareMigration
     * @param  (Closure():void)|null  $beforeBackup
     */
    public static function migrate(
        SelfHostUpgradeJournal $journal,
        SelfHostUpgradeAdmission $admission,
        string $directory,
        string $backup,
        string $originReceipt,
        string $targetReceipt,
        Closure $runTargetMigrations,
        ?Closure $prepareMigration = null,
        ?Closure $beforeBackup = null,
    ): void {
        SelfHostUpgradeExecution::requireDeployment($journal, $admission, $directory);
        if ($admission->mode !== 'docker') {
            throw new InvalidArgumentException('The admission does not authorize a Docker upgrade.');
        }
        self::requireRelease($originReceipt, $admission->origin, $admission->platform, legacy: true);
        self::requireRelease($targetReceipt, $admission->target, $admission->platform, archiveDigest: $admission->targetArchive);

        // Keep the exact entrypoint lock from the origin check all the way through the last migration.
        SelfHostDockerVolume::locked($directory, static function (SelfHostDockerVolume $volume) use (
            $directory, $backup, $admission, $journal, $originReceipt, $runTargetMigrations, $prepareMigration, $beforeBackup,
        ): void {
            $volume->requireReceipt($originReceipt);
            $beforeBackup?->__invoke();
            SelfHostUpgradeExecution::migrate($journal, $admission, $directory, $backup, $runTargetMigrations,
                prepareMigration: $prepareMigration,
                // Make the volume unavailable before recording that database writes may begin.
                fenceBeforeSchema: static fn () => $volume->fence($admission->plan, $originReceipt),
            );
        });
    }

    /** Restore into a fresh volume, leaving the fenced or migrated volume available for inspection. */
    public static function recover(
        SelfHostUpgradeJournal $journal,
        SelfHostUpgradeAdmission $admission,
        string $directory,
        string $backup,
        string $destination,
        string $originReceipt,
    ): void {
        SelfHostUpgradeExecution::requireDeployment($journal, $admission, $directory);
        if ($admission->mode !== 'docker') {
            throw new InvalidArgumentException('The admission does not authorize Docker recovery.');
        }
        self::requireRelease($originReceipt, $admission->origin, $admission->platform, legacy: true);
        $journal->requireSeparateFrom($directory, $destination, $backup);
        if ($backup === $destination || str_starts_with($backup, $destination.'/') || str_starts_with($destination, $backup.'/')
            || $directory === $destination || str_starts_with($directory, $destination.'/') || str_starts_with($destination, $directory.'/')) {
            throw new InvalidArgumentException('Use a separate, unused Docker volume for recovery.');
        }

        SelfHostDockerVolume::locked($directory, static function (SelfHostDockerVolume $volume) use ($journal, $admission, $backup, $destination, $originReceipt): void {
            $fencedBeforeMigration = ($journal->state()['phase'] ?? null) === SelfHostUpgradeJournal::BACKUP_VERIFIED;
            if ($fencedBeforeMigration) {
                $volume->requireFence($admission->plan);
            }
            SelfHostUpgradeExecution::recover($journal, $admission->plan, 'docker', $backup,
                static fn (string $path, string $inventory) => SelfHostDockerVolume::restore(
                    $destination, $admission->plan, $originReceipt, $path, $inventory,
                ),
                fencedBeforeMigration: $fencedBeforeMigration,
            );
        });
    }

    /**
     * Make the admitted target bootable while the host controller still has ingress disabled.
     * The journal records its intent first, so a crash after the receipt swap can be retried.
     */
    public static function stageReceipt(
        SelfHostUpgradeJournal $journal,
        SelfHostUpgradeAdmission $admission,
        string $directory,
        string $receipt,
    ): void {
        SelfHostUpgradeExecution::requireDeployment($journal, $admission, $directory);
        self::requireTarget($admission, $receipt);
        $state = $journal->state();
        if ($state === null || $state['plan'] !== $admission->plan
            || ! in_array($state['phase'], [SelfHostUpgradeJournal::MIGRATED, SelfHostUpgradeJournal::STAGING_SERVICE], true)) {
            throw new RuntimeException('Only the recorded migrated Docker upgrade can stage its service.');
        }

        SelfHostDockerVolume::locked($directory, static function (SelfHostDockerVolume $volume) use ($journal, $admission, $receipt, $state): void {
            if ($state['phase'] === SelfHostUpgradeJournal::MIGRATED) {
                $journal->record($admission->plan, SelfHostUpgradeJournal::STAGING_SERVICE);
            }
            $volume->stage($admission->plan, $receipt);
        });
    }

    /** @param Closure():void $verifyPrivateService */
    public static function confirmPrivateService(
        SelfHostUpgradeJournal $journal,
        SelfHostUpgradeAdmission $admission,
        string $directory,
        string $receipt,
        Closure $verifyPrivateService,
    ): void {
        SelfHostUpgradeExecution::requireDeployment($journal, $admission, $directory);
        self::requireTarget($admission, $receipt);
        $state = $journal->state();
        if ($state === null || $state['plan'] !== $admission->plan || ! in_array($state['phase'], [SelfHostUpgradeJournal::STAGING_SERVICE, SelfHostUpgradeJournal::ACTIVATED], true)
            || ! hash_equals($receipt, Files::control($directory.'/.installed', 512))) {
            throw new RuntimeException('The running Docker service does not match the staged upgrade.');
        }

        $verifyPrivateService();
        if ($state['phase'] === SelfHostUpgradeJournal::STAGING_SERVICE) {
            $journal->record($admission->plan, SelfHostUpgradeJournal::ACTIVATED);
        }
    }

    /** @param Closure():void $verifyCurrentTarget */
    public static function beginTraffic(
        SelfHostUpgradeJournal $journal,
        SelfHostUpgradeAdmission $admission,
        string $directory,
        string $receipt,
        Closure $verifyCurrentTarget,
    ): void {
        SelfHostUpgradeExecution::requireDeployment($journal, $admission, $directory);
        self::requireCurrentTarget($admission, $directory, $receipt);
        SelfHostUpgradeExecution::beginTraffic($journal, $admission->plan, $verifyCurrentTarget);
    }

    /** @param Closure():void $verifyPublishedTarget */
    public static function confirmTraffic(
        SelfHostUpgradeJournal $journal,
        SelfHostUpgradeAdmission $admission,
        string $directory,
        string $receipt,
        Closure $verifyPublishedTarget,
    ): void {
        SelfHostUpgradeExecution::requireDeployment($journal, $admission, $directory);
        self::requireCurrentTarget($admission, $directory, $receipt);
        SelfHostUpgradeExecution::confirmTraffic($journal, $admission->plan, $verifyPublishedTarget);
    }

    private static function requireCurrentTarget(SelfHostUpgradeAdmission $admission, string $directory, string $receipt): void
    {
        self::requireTarget($admission, $receipt);
        if (! hash_equals($receipt, Files::control($directory.'/.installed', 512))) {
            throw new RuntimeException('The Docker volume no longer identifies the admitted target.');
        }
    }

    private static function requireTarget(SelfHostUpgradeAdmission $admission, string $receipt): void
    {
        if ($admission->mode !== 'docker') {
            throw new InvalidArgumentException('The admission does not authorize a Docker upgrade.');
        }
        self::requireRelease($receipt, $admission->target, $admission->platform, archiveDigest: $admission->targetArchive);
    }

    public static function requireRelease(string $receipt, Manifest $manifest, string $platform, bool $legacy = false, ?string $archiveDigest = null): void
    {
        if ($legacy && $receipt === $manifest->source."\n") {
            return;
        }

        $digest = hash('sha256', $manifest->bytes());
        foreach ($manifest->artifacts as $archive) {
            if ($archive['role'] !== 'native' || ! str_ends_with($archive['name'], '.tar.gz') || ! in_array($archive['platform'], ['any', $platform], true)
                || ($archiveDigest !== null && ! hash_equals($archive['sha256'], $archiveDigest))) {
                continue;
            }
            $expected = implode("\n", [
                'kitsune-docker-installed-v2', $manifest->version, $manifest->source, $archive['sha256'], $digest, '',
            ]);
            if (hash_equals($expected, $receipt)) {
                return;
            }
        }

        throw new InvalidArgumentException('The Docker receipt does not match an admitted application artifact.');
    }
}
