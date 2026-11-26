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

/** Validate the installed and privately staged native runtimes under the installer lock before offline migration. */
final class SelfHostNativeUpgrade
{
    /**
     * @param  Closure():void  $assertWritersStopped  proves the host has stopped and fenced ingress and all writers
     * @param  Closure():void  $migrateTarget
     * @param  (Closure():void)|null  $prepareTarget
     * @param  (Closure(): (Closure():void))|null  $prepareMigration
     */
    public static function migrate(
        SelfHostUpgradeJournal $journal,
        SelfHostUpgradeAdmission $admission,
        string $lockPath,
        string $installation,
        string $staging,
        string $state,
        string $backup,
        Closure $assertWritersStopped,
        Closure $migrateTarget,
        ?Closure $prepareTarget = null,
        ?Closure $prepareMigration = null,
    ): void {
        SelfHostUpgradeExecution::requireDeployment($journal, $admission, $state);
        self::requireSwitchPaths($admission, $installation, $staging);
        self::withInstallerLock($lockPath, static function () use (
            $journal, $admission, $installation, $staging, $state, $backup, $assertWritersStopped, $migrateTarget, $prepareTarget, $prepareMigration,
        ): void {
            $assertWritersStopped();
            self::verifyRoots($admission, $installation, $staging, $state);
            if (self::present($installation.'.before-'.$admission->plan)
                || (self::present($staging.'/.installed')
                    && Files::control($staging.'/.installed', 256) !== Files::control($staging.'/.release', 256))) {
                throw new RuntimeException('The native cutover destinations are already occupied or inconsistent.');
            }
            self::clearBootCache($staging.'/app');
            $prepareTarget?->__invoke();
            SelfHostUpgradeExecution::migrate($journal, $admission, $state, $backup, $migrateTarget,
                $assertWritersStopped, $prepareMigration);
        });
    }

    /**
     * Preserve the origin and move the verified target into place while native ingress and writers are stopped.
     *
     * @param  Closure():void  $assertWritersStopped
     */
    public static function stage(
        SelfHostUpgradeJournal $journal,
        SelfHostUpgradeAdmission $admission,
        string $lockPath,
        string $installation,
        string $staging,
        string $state,
        Closure $assertWritersStopped,
    ): string {
        SelfHostUpgradeExecution::requireDeployment($journal, $admission, $state);
        $parent = self::requireSwitchPaths($admission, $installation, $staging);
        $retained = $installation.'.before-'.$admission->plan;
        $current = $journal->state();
        if ($current === null || $current['plan'] !== $admission->plan
            || ! in_array($current['phase'], [SelfHostUpgradeJournal::MIGRATED, SelfHostUpgradeJournal::STAGING_SERVICE], true)) {
            throw new RuntimeException('Only the recorded migrated native upgrade can stage its service.');
        }

        self::withInstallerLock($lockPath, static function () use (
            $journal, $admission, $installation, $staging, $state, $assertWritersStopped, $retained, $parent, $current,
        ): void {
            $assertWritersStopped();
            $originalAlreadyRetained = self::present($retained);
            $origin = $originalAlreadyRetained ? $retained : $installation;
            $target = self::present($staging) ? $staging : $installation;
            if ($origin === $target || ($originalAlreadyRetained && $target === $staging && self::present($installation))
                || ($originalAlreadyRetained && $current['phase'] === SelfHostUpgradeJournal::MIGRATED)) {
                throw new RuntimeException('The native cutover paths do not identify one original and one target.');
            }
            self::verifyRoots($admission, $origin, $target, $state);

            self::clearBootCache($target.'/app');
            if ($current['phase'] === SelfHostUpgradeJournal::MIGRATED) {
                $journal->record($admission->plan, SelfHostUpgradeJournal::STAGING_SERVICE);
            }
            self::completeReceipt($target);
            if (! $originalAlreadyRetained) {
                if (! rename($installation, $retained)) {
                    throw new RuntimeException('The original native runtime could not be retained.');
                }
                self::sync($parent);
            }
            if ($target === $staging) {
                if (self::present($installation) || ! rename($staging, $installation)) {
                    throw new RuntimeException('The verified native runtime could not be put in place.');
                }
                self::sync($parent);
            }
        });

        return $retained;
    }

    /** @param Closure():void $verifyPrivateService */
    public static function confirmPrivateService(
        SelfHostUpgradeJournal $journal,
        SelfHostUpgradeAdmission $admission,
        string $installation,
        string $state,
        Closure $verifyPrivateService,
        ?string $lockPath = null,
    ): void {
        SelfHostUpgradeExecution::requireDeployment($journal, $admission, $state);
        $check = static function () use ($journal, $admission, $installation, $state, $verifyPrivateService): void {
            $phase = $journal->state();
            if ($admission->mode !== 'native' || $phase === null || $phase['plan'] !== $admission->plan
                || ! in_array($phase['phase'], [SelfHostUpgradeJournal::STAGING_SERVICE, SelfHostUpgradeJournal::ACTIVATED], true)) {
                throw new RuntimeException('Only the recorded staged native service can be confirmed.');
            }
            self::requireCurrentTarget($admission, $installation, $state);
            $verifyPrivateService();
            if ($phase['phase'] === SelfHostUpgradeJournal::STAGING_SERVICE) {
                $journal->record($admission->plan, SelfHostUpgradeJournal::ACTIVATED);
            }
        };
        if ($lockPath === null) {
            $check();
        } else {
            self::withInstallerLock($lockPath, $check);
        }
    }

    /** @param Closure():void $verifyCurrentTarget */
    public static function beginTraffic(
        SelfHostUpgradeJournal $journal,
        SelfHostUpgradeAdmission $admission,
        string $lockPath,
        string $installation,
        string $state,
        Closure $verifyCurrentTarget,
    ): void {
        SelfHostUpgradeExecution::requireDeployment($journal, $admission, $state);
        self::withInstallerLock($lockPath, static function () use ($journal, $admission, $installation, $state, $verifyCurrentTarget): void {
            self::requireCurrentTarget($admission, $installation, $state);
            SelfHostUpgradeExecution::beginTraffic($journal, $admission->plan, $verifyCurrentTarget);
        });
    }

    /** @param Closure():void $verifyPublishedTarget */
    public static function confirmTraffic(
        SelfHostUpgradeJournal $journal,
        SelfHostUpgradeAdmission $admission,
        string $lockPath,
        string $installation,
        string $state,
        Closure $verifyPublishedTarget,
    ): void {
        SelfHostUpgradeExecution::requireDeployment($journal, $admission, $state);
        self::withInstallerLock($lockPath, static function () use ($journal, $admission, $installation, $state, $verifyPublishedTarget): void {
            self::requireCurrentTarget($admission, $installation, $state);
            SelfHostUpgradeExecution::confirmTraffic($journal, $admission->plan, $verifyPublishedTarget);
        });
    }

    /**
     * @param  Closure():void  $verifyFence
     * @param  Closure():void  $publish
     * @param  Closure():void  $verifyPublished
     */
    public static function publishTraffic(
        SelfHostUpgradeJournal $journal,
        SelfHostUpgradeAdmission $admission,
        string $lockPath,
        string $installation,
        string $state,
        Closure $verifyFence,
        Closure $publish,
        Closure $verifyPublished,
    ): void {
        SelfHostUpgradeExecution::requireDeployment($journal, $admission, $state);
        self::withInstallerLock($lockPath, static function () use (
            $journal, $admission, $installation, $state, $verifyFence, $publish, $verifyPublished,
        ): void {
            self::requireCurrentTarget($admission, $installation, $state);
            $recorded = $journal->state();
            if ($recorded !== null && $recorded['plan'] === $admission->plan && $recorded['phase'] === SelfHostUpgradeJournal::COMPLETE) {
                $verifyPublished();

                return;
            }

            SelfHostUpgradeExecution::finish($journal, $admission->plan, $verifyFence, $publish, $verifyPublished);
        });
    }

    /** @param Closure():void $assertWritersStopped */
    public static function recover(
        SelfHostUpgradeJournal $journal,
        SelfHostUpgradeAdmission $admission,
        string $lockPath,
        string $installation,
        string $state,
        string $backup,
        int $runtimeUser,
        int $runtimeGroup,
        Closure $assertWritersStopped,
    ): void {
        SelfHostUpgradeExecution::requireDeployment($journal, $admission, $state);
        $pendingState = $state.'.restore-'.$admission->plan;
        $failedState = $state.'.failed-'.$admission->plan;
        $previous = $installation.'.before-'.$admission->plan;
        $failedCode = $installation.'.failed-'.$admission->plan;
        $stateParent = self::requireSwitchPaths($admission, $state, $pendingState);
        $codeParent = self::requireSwitchPaths($admission, $installation, $previous);
        $paths = [$state, $installation, $pendingState, $failedState, $previous, $failedCode];
        $journal->requireSeparateFrom(...$paths);
        foreach ($paths as $path) {
            if ($backup === $path || str_starts_with($backup, $path.'/') || str_starts_with($path, $backup.'/')) {
                throw new InvalidArgumentException('Keep the verified backup outside native cutover paths.');
            }
        }

        self::withInstallerLock($lockPath, static function () use (
            $journal, $admission, $installation, $state, $backup, $assertWritersStopped, $pendingState,
            $failedState, $previous, $failedCode, $stateParent, $codeParent, $runtimeUser, $runtimeGroup,
        ): void {
            $current = $journal->state();
            if ($current !== null && $current['plan'] === $admission->plan
                && in_array($current['phase'], [SelfHostUpgradeJournal::OPENING_TRAFFIC, SelfHostUpgradeJournal::COMPLETE], true)) {
                throw new RuntimeException('Native traffic handoff has begun. Finish forward with publish-native.');
            }
            $assertWritersStopped();
            if (in_array($journal->state()['phase'] ?? null, [SelfHostUpgradeJournal::PREPARED, SelfHostUpgradeJournal::BACKUP_VERIFIED], true)) {
                SelfHostUpgradeExecution::abortBeforeMigration($journal, $admission->plan);

                return;
            }
            if ($runtimeUser <= 0 || $runtimeGroup <= 0) {
                throw new InvalidArgumentException('Native recovery requires the dedicated unprivileged runtime user and group.');
            }
            SelfHostUpgradeExecution::recover($journal, $admission->plan, 'native', $backup,
                static function (string $backup, string $inventory, string $database) use (
                    $admission, $installation, $state, $pendingState, $failedState, $previous, $failedCode,
                    $stateParent, $codeParent, $runtimeUser, $runtimeGroup,
                ): void {
                    if (self::present($state)) {
                        self::verifyRecoveryCode($admission, $installation, $previous, $failedCode, $state);
                    }
                    self::restoreState($state, $pendingState, $failedState, $stateParent,
                        $backup, $inventory, $database, $runtimeUser, $runtimeGroup);
                    self::verifyRecoveryCode($admission, $installation, $previous, $failedCode, $state);

                    if (self::present($previous)) {
                        self::clearBootCache($previous.'/app');
                        if (self::present($installation)) {
                            self::renameAndSync($installation, $failedCode, $codeParent);
                        }
                        self::renameAndSync($previous, $installation, $codeParent);
                    } else {
                        self::clearBootCache($installation.'/app');
                    }

                    self::requireRestoredOrigin($admission, $installation, $state);
                });
        });
    }

    private static function restoreState(
        string $state,
        string $pending,
        string $failed,
        string $parent,
        string $backup,
        string $inventory,
        string $database,
        int $runtimeUser,
        int $runtimeGroup,
    ): void {
        $hasState = self::present($state);
        $hasFailed = self::present($failed);
        $hasPending = self::present($pending);
        $originState = $hasFailed ? $failed : $state;
        $controlOwner = self::verifyNativeOwner($originState, $runtimeUser, $runtimeGroup);
        if ($hasFailed && $hasState) {
            if ($hasPending) {
                throw new RuntimeException('Native recovery found both active and pending restored state.');
            }
        } else {
            if (! $hasFailed && (! $hasState || realpath($state) !== $state || ! is_dir($state))) {
                throw new RuntimeException('Native recovery could not locate the original state to retain.');
            }
            if (! $hasPending) {
                $scratch = $pending.'.'.bin2hex(random_bytes(8));
                SelfHostBackupSet::stageRestore('native', $backup, $inventory, $scratch,
                    static fn (string $path) => self::prepareNativeOwnership($path, $runtimeUser, $runtimeGroup));
                self::renameAndSync($scratch, $pending, $parent);
            }
            if (SelfHostBackupSet::verifyRestored('native', $pending, $inventory)['sha256'] !== $database) {
                throw new RuntimeException('The pending native restore differs from the verified database.');
            }
            self::verifyNativeOwner($pending, $runtimeUser, $runtimeGroup, $controlOwner);
            if ($hasState) {
                self::renameAndSync($state, $failed, $parent);
            }
            self::renameAndSync($pending, $state, $parent);
        }
        if (SelfHostBackupSet::verifyRestored('native', $state, $inventory)['sha256'] !== $database) {
            throw new RuntimeException('The native restore differs from the verified database.');
        }
        self::verifyNativeOwner($state, $runtimeUser, $runtimeGroup, $controlOwner);
    }

    private static function verifyNativeOwner(string $root, int $user, int $group, ?int $expectedControlOwner = null): int
    {
        if (realpath($root) !== $root || ! is_dir($root)) {
            throw new RuntimeException('Native recovery could not locate the original or verified state.');
        }
        clearstatcache(true, $root);
        $owner = @fileowner($root);
        if ($owner === false || ($expectedControlOwner !== null && $expectedControlOwner !== $owner)) {
            throw new RuntimeException('The restored native control files have a different owner from the installation.');
        }
        $layout = [
            '' => [$owner, 0040000, 0750],
            '/.env' => [$owner, 0100000, 0640],
            '/database' => [$user, 0040000, 0700],
            '/database/database.sqlite' => [$user, 0100000, 0600],
            '/storage' => [$user, 0040000, 0700],
        ];
        foreach ($layout as $relative => [$expectedUser, $type, $mode]) {
            $path = $root.$relative;
            clearstatcache(true, $path);
            $info = @lstat($path);
            if ($info === false || ($info['mode'] & 0170000) !== $type
                || $info['uid'] !== $expectedUser || $info['gid'] !== $group
                || ($expectedControlOwner !== null && ($info['mode'] & 0777) !== $mode)) {
                throw new RuntimeException('The native state does not match the installer and runtime ownership.');
            }
        }

        return $owner;
    }

    private static function prepareNativeOwnership(string $root, int $user, int $group): void
    {
        foreach (['database', 'storage'] as $area) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root.'/'.$area, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                self::setNativeOwnership($file->getPathname(), $user, $group, $file->isDir() ? 0700 : 0600);
            }
            self::setNativeOwnership($root.'/'.$area, $user, $group, 0700);
        }
        self::setNativeOwnership($root.'/.env', null, $group, 0640);
        self::setNativeOwnership($root, null, $group, 0750);
    }

    private static function setNativeOwnership(string $path, ?int $user, int $group, int $mode): void
    {
        clearstatcache(true, $path);
        $info = @lstat($path);
        if ($info === false || ! in_array($info['mode'] & 0170000, [0040000, 0100000], true)
            || ($user !== null && $info['uid'] !== $user && ! @chown($path, $user))
            || ($info['gid'] !== $group && ! @chgrp($path, $group)) || ! @chmod($path, $mode)) {
            throw new RuntimeException('The restored native state could not be assigned to the runtime.');
        }
        self::sync($path);
    }

    private static function verifyRecoveryCode(
        SelfHostUpgradeAdmission $admission,
        string $installation,
        string $previous,
        string $failed,
        string $state,
    ): void {
        if (! self::present($previous)) {
            self::requireRestoredOrigin($admission, $installation, $state);
        } else {
            self::requireRestoredOrigin($admission, $previous, $state);
            if (self::present($installation)) {
                if (self::present($failed)) {
                    throw new RuntimeException('The retained failed native code path is occupied.');
                }
                self::requireCurrentTarget($admission, $installation, $state);
            }
        }
        if (self::present($failed)) {
            self::requireCurrentTarget($admission, $failed, $state);
        }
    }

    private static function requireRestoredOrigin(SelfHostUpgradeAdmission $admission, string $installation, string $state): void
    {
        if (realpath($installation) !== $installation) {
            throw new RuntimeException('The original native installation is missing or linked.');
        }
        $receipt = Files::control($installation.'/.release', 256);
        self::requireReceipt($receipt, $admission->origin, $admission->platform);
        if ($receipt !== Files::control($installation.'/.installed', 256)
            || ! hash_equals($admission->originRuntime, SelfHostNativeRuntime::fingerprint($installation.'/app', $state))) {
            throw new RuntimeException('The original native runtime differs from the admitted release.');
        }
    }

    private static function renameAndSync(string $source, string $destination, string $parent): void
    {
        if (self::present($destination) || ! rename($source, $destination)) {
            throw new RuntimeException('Native recovery could not move a verified path into place.');
        }
        self::sync($parent);
    }

    private static function requireCurrentTarget(SelfHostUpgradeAdmission $admission, string $installation, string $state): void
    {
        if ($admission->mode !== 'native') {
            throw new InvalidArgumentException('The admission does not authorize a native upgrade.');
        }
        self::requireTargetLayout($installation);
        $receipt = Files::control($installation.'/.release', 256);
        self::requireReceipt($receipt, $admission->target, $admission->platform, $admission->targetArchive);
        if ($receipt !== Files::control($installation.'/.installed', 256)
            || ! hash_equals($admission->targetRuntime, SelfHostNativeRuntime::fingerprint($installation.'/app', $state))) {
            throw new RuntimeException('The running native service does not match the staged upgrade.');
        }
    }

    private static function requireSwitchPaths(SelfHostUpgradeAdmission $admission, string $installation, string $staging): string
    {
        $parent = dirname($installation);
        if ($admission->mode !== 'native' || realpath($parent) !== $parent || dirname($staging) !== $parent
            || $installation !== rtrim($parent, '/').'/'.basename($installation)
            || $staging !== rtrim($parent, '/').'/'.basename($staging)
            || $installation === $staging || in_array(basename($installation), ['.', '..'], true)
            || in_array(basename($staging), ['.', '..'], true)) {
            throw new InvalidArgumentException('Native switching requires distinct installation directories under one canonical parent.');
        }
        $parentInfo = @stat($parent);
        foreach ([$installation, $staging] as $path) {
            if (self::present($path)) {
                $info = @lstat($path);
                if ($parentInfo === false || $info === false || $info['dev'] !== $parentInfo['dev']) {
                    throw new InvalidArgumentException('Native cutover paths must be on the same filesystem as their parent.');
                }
            }
        }

        return $parent;
    }

    private static function clearBootCache(string $app): void
    {
        $directory = $app.'/bootstrap/cache';
        if (! self::present($directory)) {
            return;
        }
        $files = @scandir($directory, SCANDIR_SORT_NONE);
        if ($files === false || realpath($directory) !== $directory) {
            throw new RuntimeException('The staged Laravel boot cache could not be inspected.');
        }

        foreach ($files as $file) {
            if (in_array($file, ['.', '..', '.gitignore'], true)) {
                continue;
            }
            $path = $directory.'/'.$file;
            $info = @lstat($path);
            if ($info === false || ($info['mode'] & 0170000) !== 0100000 || $info['nlink'] !== 1
                || preg_match('/\A(?:packages|services|config|events|routes-v[0-9]+)\.php\z/D', $file) !== 1) {
                throw new InvalidArgumentException('The staged Laravel boot cache contains an unexpected file.');
            }
        }
        foreach ($files as $file) {
            if (preg_match('/\A(?:packages|services|config|events|routes-v[0-9]+)\.php\z/D', $file) === 1
                && ! unlink($directory.'/'.$file)) {
                throw new RuntimeException('The staged Laravel boot cache could not be cleared.');
            }
        }
        self::sync($directory);
    }

    /** @param Closure():void $work */
    private static function withInstallerLock(string $lockPath, Closure $work): void
    {
        $lockInfo = @lstat($lockPath);
        if (realpath($lockPath) !== $lockPath || $lockInfo === false || ($lockInfo['mode'] & 0170000) !== 0100000
            || $lockInfo['nlink'] !== 1) {
            throw new InvalidArgumentException('Use the existing native installer lock.');
        }
        $lock = @fopen($lockPath, 'r+be');
        if ($lock === false) {
            throw new RuntimeException('The native installer lock could not be opened.');
        }

        try {
            $opened = fstat($lock);
            if ($opened === false || $opened['dev'] !== $lockInfo['dev'] || $opened['ino'] !== $lockInfo['ino']
                || ! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('The native installer or another operator holds the installation lock.');
            }
            $work();
        } finally {
            fclose($lock);
        }
    }

    private static function completeReceipt(string $installation): void
    {
        $receipt = Files::control($installation.'/.release', 256);
        $path = $installation.'/.installed';
        if (self::present($path)) {
            if ($receipt !== Files::control($path, 256)) {
                throw new InvalidArgumentException('The staged native completion receipt disagrees with its release.');
            }

            return;
        }
        // A hard stop can leave the temporary receipt. Keep it outside the release tree so retry can verify that tree.
        $parent = dirname($installation);
        $pending = tempnam($parent, '.kitsune-native-receipt-');
        if ($pending === false || dirname($pending) !== $parent) {
            if ($pending !== false) {
                unlink($pending);
            }
            throw new RuntimeException('The native completion receipt could not be staged.');
        }
        try {
            if (file_put_contents($pending, $receipt) !== strlen($receipt)) {
                throw new RuntimeException('The native completion receipt could not be written.');
            }
            self::sync($pending);
            if (! rename($pending, $path)) {
                throw new RuntimeException('The native completion receipt could not be published.');
            }
            self::sync($installation);
            self::sync($parent);
        } finally {
            if (is_file($pending)) {
                unlink($pending);
            }
        }
    }

    private static function present(string $path): bool
    {
        clearstatcache(true, $path);

        return @lstat($path) !== false;
    }

    private static function sync(string $path): void
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The native cutover path could not be opened for synchronization.');
        }
        try {
            if (! @fsync($handle)) {
                throw new RuntimeException('The native cutover path could not be synchronized.');
            }
        } finally {
            fclose($handle);
        }
    }

    private static function verifyRoots(SelfHostUpgradeAdmission $admission, string $installation, string $staging, string $state): void
    {
        if (realpath($installation) !== $installation || realpath($staging) !== $staging) {
            throw new InvalidArgumentException('The native cutover requires canonical application roots.');
        }
        self::requireTargetLayout($staging);
        $origin = Files::control($installation.'/.release', 256);
        if ($origin !== Files::control($installation.'/.installed', 256)) {
            throw new InvalidArgumentException('The native completion and release receipts disagree.');
        }
        self::requireReceipt($origin, $admission->origin, $admission->platform);
        self::requireReceipt(Files::control($staging.'/.release', 256), $admission->target, $admission->platform, $admission->targetArchive);

        if (! hash_equals($admission->originRuntime, SelfHostNativeRuntime::fingerprint($installation.'/app', $state))
            || ! hash_equals($admission->targetRuntime, SelfHostNativeRuntime::fingerprint($staging.'/app', $state))) {
            throw new InvalidArgumentException('Installed or staged native code differs from the admitted runtimes.');
        }
    }

    private static function requireTargetLayout(string $root): void
    {
        $contents = @scandir($root);
        if (! in_array($contents, [['.', '..', '.release', 'app'], ['.', '..', '.installed', '.release', 'app']], true)) {
            throw new InvalidArgumentException('The native target directory contains unexpected files.');
        }
    }

    private static function requireReceipt(string $receipt, Manifest $manifest, string $platform, ?string $digest = null): void
    {
        foreach ($manifest->artifacts as $archive) {
            if ($archive['role'] === 'native' && str_ends_with($archive['name'], '.tar.gz')
                && in_array($archive['platform'], ['any', $platform], true)
                && ($digest === null || hash_equals($archive['sha256'], $digest))
                && $receipt === $manifest->version."\n".$manifest->source."\n".$archive['sha256']."\n") {
                return;
            }
        }

        throw new InvalidArgumentException('The native receipt does not match an admitted application artifact.');
    }
}
