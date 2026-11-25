<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Release;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Durable history for a single installation. Keep it outside the data set a restore can replace.
 * Its lock serializes upgrade commands. The operator also holds the mode's runtime lock and stops writers.
 */
final class SelfHostUpgradeJournal
{
    public const PREPARED = 'prepared';

    public const BACKUP_VERIFIED = 'backup_verified';

    public const MIGRATION_STARTED = 'migration_started';

    public const MIGRATED = 'migrated';

    public const STAGING_SERVICE = 'staging_service';

    public const ACTIVATED = 'activated';

    public const OPENING_TRAFFIC = 'opening_traffic';

    public const COMPLETE = 'complete';

    public const ABORTED = 'aborted';

    public const RESTORED = 'restored';

    private const NEXT = [
        self::PREPARED => [self::BACKUP_VERIFIED, self::ABORTED],
        self::BACKUP_VERIFIED => [self::MIGRATION_STARTED, self::ABORTED, self::RESTORED],
        self::MIGRATION_STARTED => [self::MIGRATED, self::RESTORED],
        self::MIGRATED => [self::STAGING_SERVICE, self::RESTORED],
        self::STAGING_SERVICE => [self::ACTIVATED, self::RESTORED],
        self::ACTIVATED => [self::OPENING_TRAFFIC, self::RESTORED],
        self::OPENING_TRAFFIC => [self::COMPLETE],
    ];

    private const TERMINAL = [self::COMPLETE, self::ABORTED, self::RESTORED];

    /** @var resource|null */
    private mixed $file;

    /** @var array<string,true> */
    private array $used = [];

    private ?string $plan = null;

    private ?string $phase = null;

    private ?string $backup = null;

    /** @param resource $file */
    private function __construct(mixed $file, private readonly string $directory, private readonly ?string $volumeIdentity)
    {
        $this->file = $file;
    }

    public static function open(string $directory, ?string $volumeIdentity = null): self
    {
        if ($volumeIdentity !== null) {
            self::requireDigest($volumeIdentity);
        }
        $mode = @fileperms($directory);
        if (realpath($directory) !== $directory || ! is_dir($directory) || $mode === false || ($mode & 0077) !== 0) {
            throw new InvalidArgumentException('The upgrade journal requires a canonical private directory.');
        }
        $path = $directory.'/upgrades.jsonl';
        clearstatcache(true, $path);
        $known = @lstat($path);
        if ($known !== false && (($known['mode'] & 0170000) !== 0100000 || $known['nlink'] !== 1)) {
            throw new InvalidArgumentException('The upgrade journal must be a regular single-link file.');
        }
        $file = @fopen($path, 'c+be');
        if ($file === false) {
            throw new RuntimeException('The upgrade journal could not be opened.');
        }
        $journal = new self($file, $directory, $volumeIdentity);
        try {
            $opened = fstat($file);
            if ($opened === false || ($opened['mode'] & 0170000) !== 0100000 || $opened['nlink'] !== 1
                || ($known !== false && ($opened['dev'] !== $known['dev'] || $opened['ino'] !== $known['ino']))) {
                throw new RuntimeException('The upgrade journal file changed while opening.');
            }
            if (! flock($file, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Another operation holds the upgrade journal.');
            }
            if ($known === false) {
                if (! chmod($path, 0600)) {
                    throw new RuntimeException('The upgrade journal could not be made private.');
                }
                self::syncDirectory($directory);
            } elseif (($known['mode'] & 0077) !== 0) {
                throw new RuntimeException('The existing upgrade journal is not private.');
            }
            $journal->read($file);

            return $journal;
        } catch (Throwable $failure) {
            $journal->close();
            throw $failure;
        }
    }

    /** Explicitly provision an identity before requesting the first signed upgrade admission. */
    public function initializeDeployment(string $persistentDirectory): string
    {
        if ($this->file === null) {
            throw new RuntimeException('The upgrade journal is closed.');
        }
        $path = $this->directory.'/installation-id';
        clearstatcache(true, $path);
        if (@lstat($path) !== false) {
            return $this->deploymentIdentity($persistentDirectory);
        }
        if ($this->phase !== null) {
            throw new RuntimeException('Restore the installation identity that belongs to this upgrade journal.');
        }

        if (realpath($persistentDirectory) !== $persistentDirectory || ! is_dir($persistentDirectory)) {
            throw new InvalidArgumentException('A canonical persistent state directory is required.');
        }
        $this->requireSeparateFrom($persistentDirectory);
        $pending = $this->directory.'/.installation-id-'.bin2hex(random_bytes(12));
        $file = @fopen($pending, 'x+be');
        if ($file === false) {
            throw new RuntimeException('The installation identity could not be staged.');
        }
        try {
            $identity = bin2hex(random_bytes(32))."\n".$this->statePathDigest($persistentDirectory)."\n";
            if (! @chmod($pending, 0600) || fwrite($file, $identity) !== strlen($identity) || ! fflush($file) || ! fsync($file)
                || ! @rename($pending, $path)) {
                throw new RuntimeException('The installation identity could not be written.');
            }
            self::syncDirectory($this->directory);
        } finally {
            fclose($file);
            if (is_file($pending)) {
                unlink($pending);
            }
        }

        return $this->deploymentIdentity($persistentDirectory);
    }

    public function deploymentIdentity(string $persistentDirectory): string
    {
        if ($this->file === null) {
            throw new RuntimeException('The upgrade journal is closed.');
        }
        $path = $this->directory.'/installation-id';
        clearstatcache(true, $path);
        $info = @lstat($path);
        if ($info === false || ($info['mode'] & 0170777) !== 0100600 || $info['uid'] !== fileowner($this->directory)) {
            throw new RuntimeException('A private installation identity is required in the upgrade journal directory.');
        }
        $record = Files::control($path, 130);
        if (preg_match('/\A([a-f0-9]{64})\n([a-f0-9]{64})\n\z/D', $record, $match) !== 1) {
            throw new RuntimeException('The stored installation identity is invalid.');
        }
        if (! hash_equals($match[2], $this->statePathDigest($persistentDirectory))) {
            throw new InvalidArgumentException('The journal belongs to another persistent state path or Docker volume.');
        }

        return $match[1];
    }

    private function statePathDigest(string $persistentDirectory): string
    {
        if ($this->volumeIdentity !== null) {
            return hash('sha256', "kitsune-self-host-docker-volume-v1\n".$persistentDirectory."\n".$this->volumeIdentity);
        }

        return hash('sha256', "kitsune-self-host-state-path-v1\n".$persistentDirectory);
    }

    public function begin(string $plan): void
    {
        self::requireDigest($plan);
        $this->requireAvailable($plan);
        $this->append($plan, self::PREPARED, null);
    }

    public function requireAvailable(?string $plan = null): void
    {
        if ($plan !== null && isset($this->used[$plan])) {
            throw new RuntimeException('This upgrade plan was already used. Create a new plan.');
        }
        if ($this->phase !== null && ! in_array($this->phase, self::TERMINAL, true)) {
            throw new RuntimeException('An interrupted upgrade requires recovery before another one can begin.');
        }
    }

    /** @internal Transitions are sequenced by SelfHostUpgradeExecution. */
    public function record(string $plan, string $phase, ?string $inventorySha256 = null): void
    {
        if ($this->plan !== $plan || $this->phase === null || ! in_array($phase, self::NEXT[$this->phase] ?? [], true)) {
            throw new RuntimeException('The upgrade phase cannot follow the recorded state.');
        }
        if ($phase === self::BACKUP_VERIFIED) {
            self::requireDigest($inventorySha256);
        } elseif ($inventorySha256 !== null) {
            throw new InvalidArgumentException('Only a verified backup can set the inventory digest.');
        }

        $this->append($plan, $phase, $inventorySha256);
    }

    /** @internal The caller gives the canonical upgrade and backup paths. */
    public function requireSeparateFrom(string ...$directories): void
    {
        foreach ($directories as $directory) {
            if ($this->directory === $directory || str_starts_with($this->directory, $directory.'/')
                || str_starts_with($directory, $this->directory.'/')) {
                throw new InvalidArgumentException('Keep the upgrade journal separate from persistent state and backups.');
            }
        }
    }

    /** @return array{plan:string,phase:string,backup:?string}|null */
    public function state(): ?array
    {
        if ($this->plan === null || $this->phase === null) {
            return null;
        }

        return ['plan' => $this->plan, 'phase' => $this->phase, 'backup' => $this->backup];
    }

    private function append(string $plan, string $phase, ?string $backup): void
    {
        $file = $this->file;
        if ($file === null) {
            throw new RuntimeException('The upgrade journal is closed.');
        }
        $line = json_encode(['plan' => $plan, 'phase' => $phase, 'backup' => $backup], JSON_THROW_ON_ERROR)."\n";
        if (fseek($file, 0, SEEK_END) !== 0 || fwrite($file, $line) !== strlen($line) || ! fflush($file) || ! fsync($file)) {
            $this->close();
            throw new RuntimeException('The upgrade phase could not be made durable. Inspect the journal before recovery.');
        }

        $this->accept($plan, $phase, $backup);
    }

    /** @param resource $file */
    private function read(mixed $file): void
    {
        while (($line = fgets($file, 1024)) !== false) {
            try {
                $event = json_decode($line, true, 4, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new RuntimeException('The upgrade journal contains an incomplete or invalid record.');
            }
            if (! str_ends_with($line, "\n") || ! is_array($event) || array_keys($event) !== ['plan', 'phase', 'backup']
                || ! is_string($event['plan']) || ! is_string($event['phase'])
                || ($event['backup'] !== null && ! is_string($event['backup']))) {
                throw new RuntimeException('The upgrade journal contains an incomplete or invalid record.');
            }
            self::requireDigest($event['plan']);
            if ($event['phase'] === self::BACKUP_VERIFIED) {
                self::requireDigest($event['backup']);
            } elseif ($event['backup'] !== null) {
                throw new RuntimeException('Only a verified backup can set the inventory digest.');
            }
            $valid = $event['phase'] === self::PREPARED
                ? ! isset($this->used[$event['plan']]) && ($this->phase === null || in_array($this->phase, self::TERMINAL, true))
                : $event['plan'] === $this->plan && in_array($event['phase'], self::NEXT[$this->phase ?? ''] ?? [], true);
            if (! $valid) {
                throw new RuntimeException('The upgrade journal contains an invalid phase transition.');
            }
            $this->accept($event['plan'], $event['phase'], $event['backup']);
        }
        if (! feof($file)) {
            throw new RuntimeException('The upgrade journal could not be read completely.');
        }
    }

    private function accept(string $plan, string $phase, ?string $backup): void
    {
        $this->used[$plan] = true;
        $this->plan = $plan;
        $this->phase = $phase;
        if ($phase === self::PREPARED || $phase === self::BACKUP_VERIFIED) {
            $this->backup = $backup;
        }
    }

    private static function requireDigest(?string $digest): void
    {
        if ($digest === null || preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1) {
            throw new InvalidArgumentException('An exact SHA-256 digest is required in the upgrade journal.');
        }
    }

    private static function syncDirectory(string $directory): void
    {
        $handle = @fopen($directory, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The upgrade journal directory could not be opened for synchronization.');
        }
        try {
            if (! @fsync($handle)) {
                throw new RuntimeException('The upgrade journal directory could not be synchronized.');
            }
        } finally {
            fclose($handle);
        }
    }

    public function close(): void
    {
        if ($this->file !== null) {
            fclose($this->file);
            $this->file = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
