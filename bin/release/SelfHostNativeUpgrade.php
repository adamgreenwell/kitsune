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
    ): void {
        self::requireSwitchPaths($admission, $installation, $staging);
        self::withInstallerLock($lockPath, static function () use (
            $journal, $admission, $installation, $staging, $state, $backup, $assertWritersStopped, $migrateTarget,
        ): void {
            $assertWritersStopped();
            self::verifyRoots($admission, $installation, $staging, $state);
            if (self::present($installation.'.before-'.$admission->plan)
                || (self::present($staging.'/.installed')
                    && Files::control($staging.'/.installed', 256) !== Files::control($staging.'/.release', 256))) {
                throw new RuntimeException('The native cutover destinations are already occupied or inconsistent.');
            }
            self::clearBootCache($staging.'/app');
            SelfHostUpgradeExecution::migrate($journal, $admission, $state, $backup, $migrateTarget);
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

            if ($current['phase'] === SelfHostUpgradeJournal::MIGRATED) {
                $journal->record($admission->plan, SelfHostUpgradeJournal::STAGING_SERVICE);
            }
            self::clearBootCache($target.'/app');
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
    ): void {
        $phase = $journal->state();
        if ($admission->mode !== 'native' || $phase === null || $phase['plan'] !== $admission->plan
            || $phase['phase'] !== SelfHostUpgradeJournal::STAGING_SERVICE) {
            throw new RuntimeException('Only the recorded staged native service can be confirmed.');
        }
        $receipt = Files::control($installation.'/.release', 256);
        self::requireReceipt($receipt, $admission->target, $admission->platform);
        if ($receipt !== Files::control($installation.'/.installed', 256)
            || ! hash_equals($admission->targetRuntime, SelfHostNativeRuntime::fingerprint($installation.'/app', $state))) {
            throw new RuntimeException('The running native service does not match the staged upgrade.');
        }

        $verifyPrivateService();
        $journal->record($admission->plan, SelfHostUpgradeJournal::ACTIVATED);
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
        $pending = tempnam($installation, '.pending-upgrade-');
        if ($pending === false || dirname($pending) !== $installation) {
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
        $origin = Files::control($installation.'/.release', 256);
        if ($origin !== Files::control($installation.'/.installed', 256)) {
            throw new InvalidArgumentException('The native completion and release receipts disagree.');
        }
        self::requireReceipt($origin, $admission->origin, $admission->platform);
        self::requireReceipt(Files::control($staging.'/.release', 256), $admission->target, $admission->platform);

        if (! hash_equals($admission->originRuntime, SelfHostNativeRuntime::fingerprint($installation.'/app', $state))
            || ! hash_equals($admission->targetRuntime, SelfHostNativeRuntime::fingerprint($staging.'/app', $state))) {
            throw new InvalidArgumentException('Installed or staged native code differs from the admitted runtimes.');
        }
    }

    private static function requireReceipt(string $receipt, Manifest $manifest, string $platform): void
    {
        foreach ($manifest->artifacts as $archive) {
            if ($archive['role'] === 'native' && str_ends_with($archive['name'], '.tar.gz')
                && in_array($archive['platform'], ['any', $platform], true)
                && $receipt === $manifest->version."\n".$manifest->source."\n".$archive['sha256']."\n") {
                return;
            }
        }

        throw new InvalidArgumentException('The native receipt does not match an admitted application artifact.');
    }
}
