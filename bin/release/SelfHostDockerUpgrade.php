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
    /** @param Closure():void $runTargetMigrations */
    public static function migrate(
        SelfHostUpgradeJournal $journal,
        SelfHostUpgradeAdmission $admission,
        string $directory,
        string $backup,
        string $originReceipt,
        string $targetReceipt,
        Closure $runTargetMigrations,
    ): void {
        if ($admission->mode !== 'docker') {
            throw new InvalidArgumentException('The admission does not authorize a Docker upgrade.');
        }
        self::requireRelease($originReceipt, $admission->origin, $admission->platform, legacy: true);
        self::requireRelease($targetReceipt, $admission->target, $admission->platform);

        // Keep the exact entrypoint lock from the origin check all the way through the last migration.
        SelfHostDockerVolume::locked($directory, static function (SelfHostDockerVolume $volume) use (
            $directory, $backup, $admission, $journal, $originReceipt, $runTargetMigrations,
        ): void {
            $volume->requireReceipt($originReceipt);
            SelfHostUpgradeExecution::migrate($journal, $admission, $directory, $backup, static function () use (
                $volume, $admission, $originReceipt, $runTargetMigrations,
            ): void {
                // A new or legacy Docker runtime still refuses the volume if this helper exits mid-migration.
                $volume->fence($admission->plan, $originReceipt);
                $runTargetMigrations();
            });
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
        self::requireTarget($admission, $receipt);
        $state = $journal->state();
        if ($state === null || $state['plan'] !== $admission->plan || $state['phase'] !== SelfHostUpgradeJournal::STAGING_SERVICE
            || ! hash_equals($receipt, Files::control($directory.'/.installed', 512))) {
            throw new RuntimeException('The running Docker service does not match the staged upgrade.');
        }

        $verifyPrivateService();
        $journal->record($admission->plan, SelfHostUpgradeJournal::ACTIVATED);
    }

    /** @param Closure():void $verifyCurrentTarget */
    public static function beginTraffic(
        SelfHostUpgradeJournal $journal,
        SelfHostUpgradeAdmission $admission,
        string $directory,
        string $receipt,
        Closure $verifyCurrentTarget,
    ): void {
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
        self::requireRelease($receipt, $admission->target, $admission->platform);
    }

    private static function requireRelease(string $receipt, Manifest $manifest, string $platform, bool $legacy = false): void
    {
        if ($legacy && $receipt === $manifest->source."\n") {
            return;
        }

        $digest = hash('sha256', $manifest->bytes());
        foreach ($manifest->artifacts as $archive) {
            if ($archive['role'] !== 'native' || ! str_ends_with($archive['name'], '.tar.gz') || ! in_array($archive['platform'], ['any', $platform], true)) {
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
