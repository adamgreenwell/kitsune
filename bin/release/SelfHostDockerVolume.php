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

    /** Install the fence before any schema write, after journaling the verified backup and intent. */
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
