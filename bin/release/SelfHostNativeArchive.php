<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Release;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/** Independently derive the expected native runtime from the verified archive. */
final class SelfHostNativeArchive
{
    public static function fingerprint(string $archive, string $digest, string $state): string
    {
        $temporaryRoot = realpath(sys_get_temp_dir());
        if ($temporaryRoot === false || ! is_dir($temporaryRoot)) {
            throw new RuntimeException('The native archive verification needs a local temporary directory.');
        }
        $temporary = $temporaryRoot.'/kitsune-native-archive-'.bin2hex(random_bytes(12));
        if (! mkdir($temporary, 0700)) {
            throw new RuntimeException('A private native archive verification directory could not be created.');
        }

        try {
            $sealed = $temporary.'/application.tar.gz';
            if (! copy($archive, $sealed) || ! hash_equals($digest, Files::digest($sealed)['sha256'])) {
                throw new InvalidArgumentException('The native archive copy differs from the verified release.');
            }

            $listing = $temporary.'/members';
            self::tar(['--list', '--verbose', '--gzip', '--file='.$sealed], $listing);
            $members = fopen($listing, 'rb');
            if ($members === false) {
                throw new RuntimeException('The native archive members could not be inspected.');
            }
            try {
                $count = 0;
                while (($member = fgets($members)) !== false) {
                    if (! in_array($member[0], ['d', '-'], true)) {
                        throw new InvalidArgumentException('The native archive must contain regular files and directories.');
                    }
                    $count++;
                }
                if ($count === 0 || ! feof($members)) {
                    throw new InvalidArgumentException('The native archive listing is empty or incomplete.');
                }
            } finally {
                fclose($members);
            }

            $application = $temporary.'/app';
            if (! mkdir($application, 0700)) {
                throw new RuntimeException('The native archive reference tree could not be created.');
            }
            $mask = umask(0022);
            try {
                self::tar(['--extract', '--gzip', '--file='.$sealed, '--directory='.$application,
                    '--no-same-owner', '--no-same-permissions']);
            } finally {
                umask($mask);
            }

            $storage = $application.'/storage';
            if (realpath($storage) !== $storage || ! is_dir($storage)
                || ! rename($storage, $temporary.'/storage-scaffold')) {
                throw new InvalidArgumentException('The native archive must include the storage scaffold.');
            }
            foreach (['.env' => '.env', 'storage' => 'storage', 'public/storage' => 'storage/app/public'] as $link => $path) {
                if (! @symlink($state.'/'.$path, $application.'/'.$link)) {
                    throw new InvalidArgumentException('The native archive already contains installation state or lacks its public directory.');
                }
            }

            return SelfHostNativeRuntime::fingerprint($application, $state);
        } finally {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                if ($file->isDir() && ! $file->isLink()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
            rmdir($temporary);
        }
    }

    /** @param list<string> $arguments */
    private static function tar(array $arguments, string $output = '/dev/null'): void
    {
        $process = proc_open(['/usr/bin/tar', ...$arguments], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $output, 'w'],
            2 => ['file', '/dev/null', 'w'],
        ], $pipes, env_vars: ['PATH' => '/usr/bin:/bin', 'LC_ALL' => 'C']);
        if ($process === false || proc_close($process) !== 0) {
            throw new InvalidArgumentException('The native release archive could not be read or extracted.');
        }
    }
}
