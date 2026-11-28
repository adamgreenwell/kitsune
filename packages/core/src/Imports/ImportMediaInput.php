<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Imports;

use Closure;
use DomainException;
use Kitsune\Core\Media\MediaIntake;
use Kitsune\Core\Media\MediaRefused;
use RuntimeException;

/** @internal */
final class ImportMediaInput
{
    /** @return array{extension: string, mime: string} */
    public static function inspect(string $root, ImportMediaAsset $asset): array
    {
        $path = self::resolve($root, $asset);

        return self::verify($path, $asset);
    }

    /**
     * Give the writer a private copy whose length, digest and type were verified.
     *
     * @template T
     *
     * @param  Closure(string): T  $consume
     * @return T
     */
    public static function withVerifiedCopy(string $root, ImportMediaAsset $asset, Closure $consume): mixed
    {
        $path = self::resolve($root, $asset);
        $temporary = tempnam(sys_get_temp_dir(), 'kitsune-import-');
        if ($temporary === false) {
            throw new RuntimeException('Could not allocate a private import media copy.');
        }

        try {
            $source = @fopen($path, 'rb');
            if ($source === false) {
                throw new DomainException('The approved source asset cannot be opened.');
            }

            try {
                $destination = @fopen($temporary, 'wb');
                if ($destination === false) {
                    throw new RuntimeException('Could not open the private import media copy.');
                }
                try {
                    $written = stream_copy_to_stream($source, $destination, $asset->size + 1);
                } finally {
                    fclose($destination);
                }
            } finally {
                fclose($source);
            }

            if ($written !== $asset->size) {
                throw new DomainException('The source asset differs from the approved bytes.');
            }

            self::verify($temporary, $asset);

            return $consume($temporary);
        } finally {
            @unlink($temporary);
        }
    }

    private static function resolve(string $root, ImportMediaAsset $asset): string
    {
        $directory = $root === '' ? false : realpath($root);
        if ($directory === false || ! is_dir($directory)) {
            throw new DomainException('The import media directory is unavailable.');
        }

        $prefix = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $candidate = realpath($prefix.$asset->path);
        if ($candidate === false || ! str_starts_with($candidate, $prefix) || ! is_file($candidate)) {
            throw new DomainException('The source asset is unavailable inside the import media directory.');
        }

        return $candidate;
    }

    /** @return array{extension: string, mime: string} */
    private static function verify(string $path, ImportMediaAsset $asset): array
    {
        clearstatcache(true, $path);
        if (@filesize($path) !== $asset->size || ! hash_equals($asset->sha256, @hash_file('sha256', $path) ?: '')) {
            throw new DomainException('The source asset differs from the approved bytes.');
        }

        try {
            return MediaIntake::accept($asset->originalName, $path, $asset->size);
        } catch (MediaRefused $reason) {
            throw new DomainException('The approved source asset is not accepted by the media library.', previous: $reason);
        }
    }
}
