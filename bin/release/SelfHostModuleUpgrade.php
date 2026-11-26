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
use SQLite3;
use Throwable;

final class SelfHostModuleUpgrade
{
    /**
     * @param  string|Closure(string):string  $originApp
     * @return array{upgrades:list<string>, expected:list<array{handle:string,version:string,is_enabled:int,installed_at:string}>}
     */
    public static function plan(string $database, Manifest $origin, Manifest $target, string|Closure $originApp, string $targetApp): array
    {
        $originalPackages = array_column($origin->packages, null, 'name');
        $targetPackages = array_column($target->packages, null, 'name');
        $upgrades = [];
        $expected = self::receipts($database);
        if ($expected === []) {
            return ['upgrades' => [], 'expected' => []];
        }

        $composerVersions = self::composerVersions($targetApp.'/vendor/composer/installed.json');
        foreach ($expected as &$receipt) {
            $handle = $receipt['handle'];
            $original = $originalPackages[$handle] ?? null;
            $replacement = $targetPackages[$handle] ?? null;
            if ($original === null || $original['version'] !== $receipt['version'] || $replacement === null) {
                throw new RuntimeException("The installed module $handle is not covered by both signed release manifests.");
            }
            if ($original['publication'] === 'excluded' || $replacement['publication'] === 'excluded'
                || ($composerVersions[$handle] ?? null) !== $replacement['version']) {
                throw new RuntimeException("The installed module $handle does not match the staged Composer package inventory.");
            }
            $from = $original['version'];
            $to = $replacement['version'];
            if ($to === $from) {
                $path = '/vendor/'.$handle;
                $originalFiles = $originApp instanceof Closure
                    ? $originApp($handle)
                    : SelfHostNativeRuntime::packageFingerprint($originApp.$path);
                if (! hash_equals($originalFiles, SelfHostNativeRuntime::packageFingerprint($targetApp.$path))) {
                    throw new RuntimeException("The installed module $handle changed package files without changing its version.");
                }

                continue;
            }

            $fromDev = str_starts_with($from, 'dev-');
            $toDev = str_starts_with($to, 'dev-');
            if ($fromDev !== $toDev || (! $fromDev && ! version_compare(explode('+', $to, 2)[0], explode('+', $from, 2)[0], '>'))) {
                throw new RuntimeException("The installed module $handle has no supported forward version transition.");
            }

            $upgrades[] = $handle;
            $receipt['version'] = $replacement['version'];
        }
        unset($receipt);

        return ['upgrades' => $upgrades, 'expected' => $expected];
    }

    /** @param list<array{handle:string,version:string,is_enabled:int,installed_at:string}> $expected */
    public static function verify(string $database, array $expected): void
    {
        if (self::receipts($database) !== $expected) {
            throw new RuntimeException('The module receipt readback did not match the admitted versions and enabled settings.');
        }
    }

    /** @return array<string, string> */
    private static function composerVersions(string $inventory): array
    {
        try {
            $packages = json_decode(Files::control($inventory, 8388608), true, 64, JSON_THROW_ON_ERROR)['packages'] ?? null;
            if (! is_array($packages) || ! array_is_list($packages)) {
                throw new RuntimeException('Invalid Composer inventory.');
            }
            $versions = [];
            foreach ($packages as $package) {
                $name = $package['name'] ?? null;
                $version = $package['version'] ?? null;
                if (! is_string($name) || ! is_string($version) || isset($versions[$name])) {
                    throw new RuntimeException('Invalid Composer package.');
                }
                $versions[$name] = $version;
            }
        } catch (Throwable) {
            throw new RuntimeException('The staged Composer package inventory could not be read.');
        }

        return $versions;
    }

    /** @return list<array{handle:string,version:string,is_enabled:int,installed_at:string}> */
    private static function receipts(string $database): array
    {
        if (realpath($database) !== $database || ! is_file($database)) {
            throw new RuntimeException('The SQLite module receipts could not be read from the native state.');
        }

        $rows = [];
        try {
            $db = new SQLite3($database, SQLITE3_OPEN_READONLY);
            $db->enableExceptions(true);
            try {
                $results = $db->query('SELECT handle, version, is_enabled, installed_at FROM modules ORDER BY handle COLLATE BINARY');
                while (($row = $results->fetchArray(SQLITE3_ASSOC)) !== false) {
                    if (! is_string($row['handle']) || ! is_string($row['version']) || ! in_array($row['is_enabled'], [0, 1], true)
                        || ! is_string($row['installed_at'])) {
                        throw new RuntimeException('An installed module receipt is invalid.');
                    }
                    $rows[] = $row;
                }
            } finally {
                $db->close();
            }
        } catch (Throwable) {
            throw new RuntimeException('The installed module receipts could not be read.');
        }

        return $rows;
    }
}
