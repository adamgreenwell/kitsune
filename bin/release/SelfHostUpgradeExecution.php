<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Release;

use Closure;
use RuntimeException;
use Throwable;

/**
 * Run the common part of a qualified self-host cutover.
 * The caller verifies the exact transition, stages verified code, stops writers and holds the runtime lock.
 * Mode-specific callbacks must throw on failure. The deployment lock spans the sequence, including the
 * adapter's controlled handoff from the runtime lock to the staged service. Ingress stays closed until requested.
 */
final class SelfHostUpgradeExecution
{
    /**
     * @param  Closure():void  $migrate
     * @param  Closure():void  $stageService
     * @param  Closure():void  $verifyService
     * @param  Closure():void  $openTraffic  idempotent publication after the private readiness check
     * @param  Closure():void  $verifyPublished
     */
    public static function apply(
        SelfHostUpgradeJournal $journal,
        SelfHostUpgradeAdmission $admission,
        string $persistentDirectory,
        string $backupDirectory,
        Closure $migrate,
        Closure $stageService,
        Closure $verifyService,
        Closure $openTraffic,
        Closure $verifyPublished,
    ): void {
        self::migrate($journal, $admission, $persistentDirectory, $backupDirectory, $migrate);
        $journal->record($admission->plan, SelfHostUpgradeJournal::STAGING_SERVICE);
        $stageService();
        $journal->record($admission->plan, SelfHostUpgradeJournal::ACTIVATED);
        self::finish($journal, $admission->plan, $verifyService, $openTraffic, $verifyPublished);
    }

    /** @param Closure():void $migrate */
    public static function migrate(
        SelfHostUpgradeJournal $journal,
        SelfHostUpgradeAdmission $admission,
        string $persistentDirectory,
        string $backupDirectory,
        Closure $migrate,
    ): void {
        $plan = $admission->plan;
        $journal->requireSeparateFrom($persistentDirectory, $backupDirectory);
        $journal->begin($plan);

        try {
            $backup = SelfHostBackupSet::create($admission->mode, $persistentDirectory, $backupDirectory);
            SelfHostBackupSet::verify($admission->mode, $backupDirectory, $backup['inventory_sha256']);
            $admission->requireMatchingBackup($backupDirectory.'/database.sqlite');
        } catch (Throwable $failure) {
            try {
                self::abortBeforeMigration($journal, $plan);
            } catch (Throwable) {
                throw new RuntimeException('Backup failed and the journal could not record the pre-migration abort.', previous: $failure);
            }

            throw $failure;
        }
        $journal->record($plan, SelfHostUpgradeJournal::BACKUP_VERIFIED, $backup['inventory_sha256']);

        // This write precedes the first schema operation. After this point, failures need inspected recovery.
        $journal->record($plan, SelfHostUpgradeJournal::MIGRATION_STARTED);
        $migrate();
        $journal->record($plan, SelfHostUpgradeJournal::MIGRATED);
    }

    public static function abortBeforeMigration(SelfHostUpgradeJournal $journal, string $plan): void
    {
        $state = $journal->state();
        if ($state === null || $state['plan'] !== $plan
            || ! in_array($state['phase'], [SelfHostUpgradeJournal::PREPARED, SelfHostUpgradeJournal::BACKUP_VERIFIED], true)) {
            throw new RuntimeException('Only the recorded upgrade can abort before migration.');
        }

        $journal->record($plan, SelfHostUpgradeJournal::ABORTED);
    }

    /**
     * @param  Closure():void  $verifyService
     * @param  Closure():void  $openTraffic
     * @param  Closure():void  $verifyPublished
     */
    public static function finish(
        SelfHostUpgradeJournal $journal,
        string $plan,
        Closure $verifyService,
        Closure $openTraffic,
        Closure $verifyPublished,
    ): void {
        self::beginTraffic($journal, $plan, $verifyService);
        $openTraffic();
        self::confirmTraffic($journal, $plan, $verifyPublished);
    }

    /** @param Closure():void $verifyService */
    public static function beginTraffic(SelfHostUpgradeJournal $journal, string $plan, Closure $verifyService): void
    {
        $state = $journal->state();
        if ($state === null || $state['plan'] !== $plan
            || ! in_array($state['phase'], [SelfHostUpgradeJournal::ACTIVATED, SelfHostUpgradeJournal::OPENING_TRAFFIC], true)) {
            throw new RuntimeException('Only the recorded upgrade can begin or resume traffic publication.');
        }

        $verifyService();
        if ($state['phase'] === SelfHostUpgradeJournal::ACTIVATED) {
            // After this durable boundary, traffic might have reached the new release even if the command dies.
            $journal->record($plan, SelfHostUpgradeJournal::OPENING_TRAFFIC);
        }
    }

    /** @param Closure():void $verifyPublished */
    public static function confirmTraffic(SelfHostUpgradeJournal $journal, string $plan, Closure $verifyPublished): void
    {
        $state = $journal->state();
        if ($state === null || $state['plan'] !== $plan || $state['phase'] !== SelfHostUpgradeJournal::OPENING_TRAFFIC) {
            throw new RuntimeException('Only the recorded upgrade awaiting publication can confirm traffic.');
        }

        $verifyPublished();
        $journal->record($plan, SelfHostUpgradeJournal::COMPLETE);
    }

    /** @param Closure(string,string,string):void $restoreAndVerify  backup directory, inventory digest, database digest */
    public static function recover(
        SelfHostUpgradeJournal $journal,
        string $plan,
        string $mode,
        string $backupDirectory,
        Closure $restoreAndVerify,
    ): void {
        $state = $journal->state();
        if ($state === null || $state['plan'] !== $plan || $state['backup'] === null
            || ! in_array($state['phase'], [SelfHostUpgradeJournal::MIGRATION_STARTED, SelfHostUpgradeJournal::MIGRATED,
                SelfHostUpgradeJournal::STAGING_SERVICE, SelfHostUpgradeJournal::ACTIVATED], true)) {
            throw new RuntimeException('Recover only the recorded interrupted upgrade with a verified backup.');
        }

        $journal->requireSeparateFrom($backupDirectory);
        $verified = SelfHostBackupSet::verify($mode, $backupDirectory, $state['backup']);
        $restoreAndVerify($backupDirectory, $state['backup'], $verified['sha256']);
        $journal->record($plan, SelfHostUpgradeJournal::RESTORED);
    }
}
