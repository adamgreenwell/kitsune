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

/** The Docker entrypoint and the upgrader serialize on the same volume lock. */
final class SelfHostDockerVolume
{
    private const FENCE = 'kitsune-docker-upgrade-v1';

    private bool $locked = true;

    private function __construct(private readonly string $directory) {}

    /**
     * @template T
     *
     * @param  Closure(self):T  $work
     * @return T
     */
    public static function locked(string $directory, Closure $work): mixed
    {
        if (realpath($directory) !== $directory || ! is_dir($directory)) {
            throw new InvalidArgumentException('Use the canonical Docker state directory.');
        }

        $path = $directory.'/.lock';
        clearstatcache(true, $path);
        $found = @lstat($path);
        if ($found === false || ($found['mode'] & 0170000) !== 0100000 || $found['nlink'] !== 1) {
            throw new RuntimeException('The Docker volume has no recognized installation lock.');
        }
        $lock = @fopen($path, 'r+be');
        if ($lock === false) {
            throw new RuntimeException('The Docker installation lock could not be opened.');
        }

        $volume = new self($directory);
        try {
            $opened = fstat($lock);
            if ($opened === false || $opened['ino'] !== $found['ino'] || $opened['dev'] !== $found['dev']
                || ($opened['mode'] & 0170000) !== 0100000 || $opened['nlink'] !== 1) {
                throw new RuntimeException('The Docker installation lock changed while opening.');
            }
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('A running container or another operation holds the Docker volume.');
            }

            return $work($volume);
        } finally {
            $volume->locked = false;
            fclose($lock);
        }
    }

    public static function installationIdentity(string $directory, ?string $originReceipt = null): string
    {
        $path = $directory.'/.upgrade-id';
        if ($originReceipt !== null) {
            self::locked($directory, static function (self $volume) use ($path, $directory, $originReceipt): void {
                $volume->requireReceipt($originReceipt);
                if (@lstat($path) !== false) {
                    return;
                }
                $pending = tempnam($directory, '.pending-identity-');
                if ($pending === false || dirname($pending) !== $directory) {
                    throw new RuntimeException('The Docker volume identity could not be staged.');
                }
                try {
                    $identity = bin2hex(random_bytes(32))."\n";
                    if (! chmod($pending, 0600) || file_put_contents($pending, $identity) !== 65) {
                        throw new RuntimeException('The Docker volume identity could not be persisted.');
                    }
                    self::sync($pending);
                    if (! rename($pending, $path)) {
                        throw new RuntimeException('The Docker volume identity could not be published.');
                    }
                    self::sync($directory);
                } finally {
                    if (is_file($pending)) {
                        unlink($pending);
                    }
                }
            });
        }

        $record = Files::control($path, 65);
        if (preg_match('/\A([a-f0-9]{64})\n\z/D', $record, $parts) !== 1) {
            throw new InvalidArgumentException('The Docker volume identity is invalid.');
        }

        return $parts[1];
    }

    /** A partial restore cannot boot. Publish the origin receipt only after the promoted data verifies. */
    public static function restore(string $directory, string $plan, string $receipt, string $backup, string $inventory): void
    {
        self::completedReceipt($receipt);
        $fence = self::fenceFor($plan);
        if (realpath($directory) !== $directory || ! is_dir($directory) || scandir($directory) !== ['.', '..']) {
            throw new InvalidArgumentException('Recovery requires a new empty Docker volume.');
        }
        $lock = @fopen($directory.'/.lock', 'xb');
        if ($lock === false) {
            throw new RuntimeException('The new Docker volume could not be initialized for recovery.');
        }
        chmod($directory.'/.lock', 0600);
        fclose($lock);

        self::locked($directory, static function (self $volume) use ($directory, $plan, $receipt, $backup, $inventory, $fence): void {
            $mount = $directory.'/.installed';
            $written = @file_put_contents($mount, $fence);
            if ($written !== strlen($fence) || ! chmod($mount, 0600)) {
                throw new RuntimeException('The new Docker volume could not be fenced.');
            }
            self::sync($mount);
            self::sync($directory);

            $staged = $directory.'/.restore';
            $expected = SelfHostBackupSet::stageRestore('docker', $backup, $inventory, $staged);
            foreach (['.env', 'database.sqlite', 'storage'] as $entry) {
                if (! rename($staged.'/'.$entry, $directory.'/'.$entry)) {
                    throw new RuntimeException('The Docker restore could not promote the verified files.');
                }
            }
            if (! rmdir($staged)) {
                throw new RuntimeException('The Docker restore could not finish staging.');
            }
            self::sync($directory);
            if (SelfHostBackupSet::verifyRestoredDockerVolume($directory, $inventory) !== $expected) {
                throw new RuntimeException('The promoted Docker volume differs from the verified backup.');
            }
            $volume->publish($plan, $receipt);
        });
    }

    public function requireFence(string $plan): void
    {
        $this->requireReceipt(self::fenceFor($plan));
    }

    /** Install the fence after the verified backup, before journaling schema writes. */
    public function fence(string $plan, string $expectedOrigin): void
    {
        self::completedReceipt($expectedOrigin);
        $this->replace($expectedOrigin, self::fenceFor($plan));
    }

    /** Publish a verified release receipt only after migration, or after an explicitly verified restore. */
    public function publish(string $plan, string $verifiedRelease): void
    {
        self::completedReceipt($verifiedRelease);
        $this->replace(self::fenceFor($plan), $verifiedRelease);
    }

    /** Accept a retry after the exact target receipt was durably published. */
    public function stage(string $plan, string $verifiedRelease): void
    {
        self::completedReceipt($verifiedRelease);
        $fence = self::fenceFor($plan);
        if (! $this->locked) {
            throw new RuntimeException('The Docker volume lock is no longer held.');
        }
        if (! hash_equals($verifiedRelease, Files::control($this->directory.'/.installed', 512))) {
            $this->replace($fence, $verifiedRelease);
        }
    }

    private static function completedReceipt(string $receipt): void
    {
        if (preg_match('/\A[a-f0-9]{40}\n\z/D', $receipt) === 1) {
            return;
        }
        if (preg_match('/\Akitsune-docker-installed-v2\n([^\n]+)\n[a-f0-9]{40}\n[a-f0-9]{64}\n[a-f0-9]{64}\n\z/D', $receipt, $parts) !== 1
            || ! Manifest::validVersion($parts[1])) {
            throw new InvalidArgumentException('The Docker volume requires an exact completed release receipt.');
        }
    }

    private static function fenceFor(string $plan): string
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $plan) !== 1) {
            throw new InvalidArgumentException('The Docker volume fence requires the exact upgrade plan digest.');
        }

        return self::FENCE."\n".$plan."\n";
    }

    public function requireReceipt(string $expected): void
    {
        if (! $this->locked) {
            throw new RuntimeException('The Docker volume lock is no longer held.');
        }
        if (! hash_equals($expected, Files::control($this->directory.'/.installed', 512))) {
            throw new RuntimeException('The Docker volume receipt no longer matches this upgrade.');
        }
    }

    private function replace(string $previous, string $next): void
    {
        $this->requireReceipt($previous);
        $path = $this->directory.'/.installed';

        $temporary = tempnam($this->directory, '.pending-upgrade-');
        if ($temporary === false || dirname($temporary) !== $this->directory) {
            if ($temporary !== false) {
                unlink($temporary);
            }
            throw new RuntimeException('The Docker volume could not allocate a private receipt.');
        }

        try {
            if (file_put_contents($temporary, $next) !== strlen($next)) {
                throw new RuntimeException('The Docker volume could not write its receipt.');
            }
            self::sync($temporary);
            if (! rename($temporary, $path)) {
                throw new RuntimeException('The Docker volume could not publish its receipt.');
            }
            self::sync($this->directory);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private static function sync(string $path): void
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The Docker volume could not open its receipt or directory for synchronization.');
        }
        try {
            if (! @fsync($handle)) {
                throw new RuntimeException('The Docker volume could not synchronize its receipt.');
            }
        } finally {
            fclose($handle);
        }
    }
}
