<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Release;

use HashContext;
use InvalidArgumentException;
use RuntimeException;

/** Fingerprint the native code tree while writers and installer operations are excluded. */
final class SelfHostNativeRuntime
{
    private const STATE_LINKS = [
        '.env' => '.env',
        'storage' => 'storage',
        'public/storage' => 'storage/app/public',
    ];

    public static function fingerprint(string $application, string $state): string
    {
        if (realpath($application) !== $application || ! is_dir($application)
            || realpath($state) !== $state || ! is_dir($state)
            || $application === $state || str_starts_with($application, $state.'/') || str_starts_with($state, $application.'/')) {
            throw new InvalidArgumentException('Use separate canonical native application and state roots.');
        }

        foreach (['public/index.php', 'artisan', 'vendor/autoload.php', 'composer.lock'] as $required) {
            if (realpath($application.'/'.$required) !== $application.'/'.$required || ! is_file($application.'/'.$required)) {
                throw new InvalidArgumentException('The native runtime is missing required application files.');
            }
        }

        foreach (self::STATE_LINKS as $name => $target) {
            if (@readlink($application.'/'.$name) !== $state.'/'.$target) {
                throw new InvalidArgumentException('The native runtime state links do not match the installation.');
            }
        }

        $hash = hash_init('sha256');
        hash_update($hash, "kitsune-native-runtime-v1\n");
        self::addDirectory($hash, $application, '');

        return hash_final($hash);
    }

    public static function packageFingerprint(string $directory): string
    {
        if (realpath($directory) !== $directory || ! is_dir($directory)) {
            throw new InvalidArgumentException('The native application is missing a local module package.');
        }

        $hash = hash_init('sha256');
        self::addDirectory($hash, $directory, '', false);

        return hash_final($hash);
    }

    private static function addDirectory(HashContext $hash, string $root, string $relative, bool $application = true): void
    {
        $directory = $relative === '' ? $root : $root.'/'.$relative;
        $children = @scandir($directory, SCANDIR_SORT_NONE);
        if ($children === false) {
            throw new RuntimeException('A native runtime directory could not be inspected.');
        }
        sort($children, SORT_STRING);
        foreach ($children as $name) {
            if (in_array($name, ['.', '..'], true)) {
                continue;
            }
            $path = $relative === '' ? $name : $relative.'/'.$name;
            if ($application && isset(self::STATE_LINKS[$path])) {
                self::row($hash, 'state-link', $path, self::STATE_LINKS[$path]);

                continue;
            }

            $absolute = $root.'/'.$path;
            clearstatcache(true, $absolute);
            $stat = @lstat($absolute);
            if ($stat === false) {
                throw new RuntimeException('A native runtime member disappeared during inspection.');
            }

            $type = $stat['mode'] & 0170000;
            if ($type === 0040000) {
                // Filament/Laravel publish runtime caches here. Their layout is checked and rebuilt by the adapter.
                if (! $application || $path !== 'bootstrap/cache') {
                    self::row($hash, 'directory', $path, (string) ($stat['mode'] & 0777));
                    self::addDirectory($hash, $root, $path, $application);
                }

                continue;
            }

            if (($application && $path === 'bootstrap/cache') || $type !== 0100000 || $stat['nlink'] !== 1) {
                throw new InvalidArgumentException('The native runtime contains an unexpected link or file type.');
            }
            $digest = @hash_file('sha256', $absolute);
            if ($digest === false) {
                throw new RuntimeException('A native runtime file could not be read.');
            }
            self::row($hash, 'file', $path, ($stat['mode'] & 0777).':'.$digest);
        }
    }

    private static function row(HashContext $hash, string $type, string $relative, string $identity): void
    {
        hash_update($hash, $type."\t".bin2hex($relative)."\t".$identity."\n");
    }
}
