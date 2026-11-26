<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Release;

use InvalidArgumentException;

/**
 * Read the receipts and layout written by the self-host installers.
 * Neither a receipt nor a matching layout admits a transition or proves database health.
 */
final class SelfHostInstallation
{
    /**
     * @return array{mode: string, state: string, version?: string, source?: string, archive_sha256?: string, manifest_sha256?: string, reason?: string}
     */
    public static function inspect(string $state, ?string $installation = null): array
    {
        $state = self::root($state);
        $installation = $installation === null ? null : self::root($installation);
        if ($installation !== null && ($installation === $state || str_starts_with($installation, $state.'/')
            || str_starts_with($state, $installation.'/'))) {
            throw new InvalidArgumentException('Native application and persistent state need separate roots.');
        }

        $mode = $installation === null ? 'docker' : 'native';
        $receipt = ($installation ?? $state).'/.installed';
        $release = $installation === null ? null : $installation.'/.release';
        $database = $state.($installation === null ? '/database.sqlite' : '/database/database.sqlite');
        $configuration = $state.'/.env';
        $storage = $state.'/storage';
        $result = ['mode' => $mode];

        foreach (array_filter([$state, $installation], static fn (?string $root): bool => $root !== null) as $root) {
            if (self::metadata($root) !== false && ! self::directory($root)) {
                return $result + ['state' => 'incomplete', 'reason' => 'An installation root is linked or has the wrong file type.'];
            }
        }

        if (self::metadata($receipt) === false) {
            $otherPaths = [$configuration, $database, $storage];
            if ($installation !== null) {
                $otherPaths[] = $installation;
                $otherPaths[] = $state;
            }
            foreach ($otherPaths as $path) {
                if (self::metadata($path) !== false) {
                    return $result + ['state' => 'incomplete', 'reason' => 'Application or persistent state exists without a completion receipt.'];
                }
            }

            // The Docker runtime creates the lock before writing state. A pending file is evidence of interrupted setup.
            if ($installation === null && self::directory($state)) {
                $contents = @scandir($state);
                if ($contents === false || array_diff($contents, ['.', '..', '.lock']) !== []
                    || (in_array('.lock', $contents, true) && ! self::regularFile($state.'/.lock'))) {
                    return $result + ['state' => 'incomplete', 'reason' => 'The volume contains unclassified state without a completion receipt.'];
                }
            }

            return $result + ['state' => 'empty'];
        }

        try {
            $record = Files::control($receipt, 512);
            if ($release !== null && Files::control($release, 256) !== $record) {
                throw new InvalidArgumentException('Native receipts differ.');
            }
            $identity = $release === null ? self::dockerIdentity($record) : self::nativeIdentity($record);
        } catch (InvalidArgumentException) {
            return $result + ['state' => 'incomplete', 'reason' => 'The installation receipts are missing, invalid or inconsistent.'];
        }

        if ($release === null && str_starts_with($record, "kitsune-docker-installing-v2\n")) {
            return $result + ['state' => 'incomplete', 'reason' => 'The first Docker installation has not completed.'] + $identity;
        }

        if (($installation !== null && ! self::directory($state.'/database'))
            || ! self::directory($storage) || ! self::nonEmptyFile($configuration) || ! self::nonEmptyFile($database)
            || ($installation !== null && ! self::nativeApplicationMatches($installation.'/app', $state))) {
            return $result + ['state' => 'incomplete', 'reason' => 'The persisted configuration, database, storage or application layout needs inspection.'] + $identity;
        }

        return $result + ['state' => 'installed'] + $identity;
    }

    /** @return array{source: string, version?: string, archive_sha256?: string, manifest_sha256?: string} */
    private static function dockerIdentity(string $record): array
    {
        if (preg_match('/\A([a-f0-9]{40})\n\z/D', $record, $matches) === 1) {
            return ['source' => $matches[1]];
        }
        if (preg_match('/\Akitsune-docker-(?:installing|installed)-v2\n([^\n]+)\n([a-f0-9]{40})\n([a-f0-9]{64})\n([a-f0-9]{64})\n\z/D',
            $record, $matches) !== 1 || ! Manifest::validVersion($matches[1])) {
            throw new InvalidArgumentException('Invalid Docker receipt.');
        }

        return ['version' => $matches[1], 'source' => $matches[2], 'archive_sha256' => $matches[3], 'manifest_sha256' => $matches[4]];
    }

    /** @return array{version: string, source: string, archive_sha256: string} */
    private static function nativeIdentity(string $record): array
    {
        if (preg_match('/\A([^\n]+)\n([a-f0-9]{40})\n([a-f0-9]{64})\n\z/D', $record, $matches) !== 1
            || ! Manifest::validVersion($matches[1])) {
            throw new InvalidArgumentException('Invalid native receipt.');
        }

        return ['version' => $matches[1], 'source' => $matches[2], 'archive_sha256' => $matches[3]];
    }

    private static function nativeApplicationMatches(string $app, string $state): bool
    {
        if (! self::directory($app) || ! self::directory($app.'/public')
            || ! self::nonEmptyFile($app.'/public/index.php') || ! self::nonEmptyFile($app.'/composer.lock')) {
            return false;
        }

        return @readlink($app.'/.env') === $state.'/.env'
            && @readlink($app.'/storage') === $state.'/storage'
            && @readlink($app.'/public/storage') === $state.'/storage/app/public';
    }

    private static function root(string $root): string
    {
        $root = rtrim($root, '/');
        if (! str_starts_with($root, '/') || preg_match('~(?:^|/)\.{1,2}(?:/|$)|//|[\x00-\x1f\x7f]~D', $root) === 1) {
            throw new InvalidArgumentException('Use a canonical absolute installation root.');
        }

        $parent = dirname($root);
        if (realpath($parent) !== $parent || ! is_readable($parent) || ! is_executable($parent)) {
            throw new InvalidArgumentException('The installation root needs a readable, canonical parent directory.');
        }

        return $root;
    }

    private static function directory(string $path): bool
    {
        $stat = self::metadata($path);

        return $stat !== false && ($stat['mode'] & 0170000) === 0040000
            && is_readable($path) && is_executable($path);
    }

    private static function nonEmptyFile(string $path): bool
    {
        return self::regularFile($path, nonEmpty: true);
    }

    private static function regularFile(string $path, bool $nonEmpty = false): bool
    {
        $stat = self::metadata($path);

        return $stat !== false && ($stat['mode'] & 0170000) === 0100000 && $stat['nlink'] === 1
            && (! $nonEmpty || $stat['size'] > 0);
    }

    /** @return array<int|string, int>|false */
    private static function metadata(string $path): array|false
    {
        clearstatcache(true, $path);

        return @lstat($path);
    }
}
