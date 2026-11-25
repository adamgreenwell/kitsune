<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Release;

use InvalidArgumentException;

/** Read-only verification. This tool never extracts, executes or changes an installation. */
final class Files
{
    public static function control(string $path, int $maximum): string
    {
        $handle = self::open($path);
        try {
            $before = fstat($handle);
            if ($before === false || $before['size'] < 1 || $before['size'] > $maximum) {
                throw new InvalidArgumentException('Control file size refused.');
            }
            $bytes = stream_get_contents($handle, $maximum + 1);
            $after = fstat($handle);
            if ($bytes === false || strlen($bytes) !== $before['size'] || ! self::unchanged($before, $after)) {
                throw new InvalidArgumentException('Control file changed during verification.');
            }

            return $bytes;
        } finally {
            fclose($handle);
        }
    }

    /** @return array{size:int, sha256:string} */
    public static function digest(string $path, ?int $expectedSize = null): array
    {
        $handle = self::open($path);
        try {
            $before = fstat($handle);
            if ($before === false || $before['size'] < 1 || $before['size'] > Manifest::MAX_ARTIFACT_BYTES
                || ($expectedSize !== null && $before['size'] !== $expectedSize)) {
                throw new InvalidArgumentException('Artifact size refused.');
            }
            $hash = hash_init('sha256');
            $read = hash_update_stream($hash, $handle, $before['size'] + 1);
            $after = fstat($handle);
            if ($read !== $before['size'] || ! self::unchanged($before, $after)) {
                throw new InvalidArgumentException('Artifact changed during verification.');
            }

            return ['size' => $read, 'sha256' => hash_final($hash)];
        } finally {
            fclose($handle);
        }
    }

    public static function verify(Manifest $manifest, string $directory): void
    {
        $root = realpath($directory);
        if ($root === false || ! is_dir($root)) {
            throw new InvalidArgumentException('Artifact directory refused.');
        }
        foreach ($manifest->artifacts as $artifact) {
            // The parser admits basenames only, never archive member names or operator paths.
            $digest = self::digest($root.DIRECTORY_SEPARATOR.$artifact['name'], $artifact['size']);
            if (! hash_equals($artifact['sha256'], $digest['sha256'])) {
                throw new InvalidArgumentException('Artifact checksum refused.');
            }
        }
    }

    /**
     * Reading may update atime. Compare identity, content-relevant metadata and ownership links only.
     *
     * @param  array<int|string, int>  $before
     * @param  array<int|string, int>|false  $after
     */
    private static function unchanged(array $before, array|false $after): bool
    {
        if ($after === false) {
            return false;
        }
        foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
            if ($before[$field] !== $after[$field]) {
                return false;
            }
        }

        return true;
    }

    /** @return resource */
    private static function open(string $path)
    {
        $before = @lstat($path);
        if ($before === false || ($before['mode'] & 0170000) !== 0100000 || $before['nlink'] !== 1) {
            throw new InvalidArgumentException('A regular, single-link input file is required.');
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new InvalidArgumentException('Input file could not be read.');
        }
        $opened = fstat($handle);
        if ($opened === false || $opened['dev'] !== $before['dev'] || $opened['ino'] !== $before['ino']
            || ($opened['mode'] & 0170000) !== 0100000 || $opened['nlink'] !== 1) {
            fclose($handle);
            throw new InvalidArgumentException('Input file identity changed.');
        }

        return $handle;
    }
}
