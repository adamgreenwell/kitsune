<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Benchmarks;

use InvalidArgumentException;
use Kitsune\Core\Media\MediaDisks;
use RuntimeException;

/**
 * Separate tiny PNG files for the synthetic media entries, never absent-file placeholders or deduplicated links.
 *
 * Bulk fixture construction is not MediaLibrary write throughput. A control test stores the same bytes through
 * MediaLibrary and compares the facts. Locations stay inside the new experiment's storage, and an existing file
 * or linked ancestor is refused rather than replaced. This class never removes an existing directory.
 *
 * @internal
 */
final class SchemaScaleMedia
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private readonly string $output;

    public function __construct(string $output)
    {
        if (! str_starts_with($output, '/') || realpath($output) !== $output || ! is_dir($output)) {
            throw new InvalidArgumentException('The media fixture needs the canonical owned output directory.');
        }
        $this->output = $output;
    }

    public function root(): string
    {
        return $this->output.'/storage/app/kitsune/private';
    }

    public function absolutePath(string $path): string
    {
        if (! preg_match('~^media/scale/([0-9]+)/([1-9][0-9]*)\.png$~D', $path, $match)
            || (int) $match[2] > 1_000_000 || (int) $match[1] !== intdiv((int) $match[2], 1000)) {
            throw new InvalidArgumentException('The media path must belong to one synthetic corpus entry.');
        }
        $absolute = $this->root().'/'.$path;
        $this->checkParents($absolute, create: false);

        return $absolute;
    }

    /** @return array<string, mixed> */
    public function write(int $entryId): array
    {
        if ($entryId < 1 || $entryId > 1_000_000) {
            throw new InvalidArgumentException('The media entry id must be in the synthetic scale corpus.');
        }
        $path = 'media/scale/'.intdiv($entryId, 1000).'/'.$entryId.'.png';
        $absolute = $this->absolutePath($path);
        $this->checkParents($absolute, create: true);
        if (file_exists($absolute) || is_link($absolute)) {
            throw new RuntimeException('Refusing to replace an existing media fixture.');
        }
        $stream = @fopen($absolute, 'xb');
        if ($stream === false) {
            throw new RuntimeException('Cannot exclusively create a new owned media fixture.');
        }
        $bytes = base64_decode(self::PNG, true);
        try {
            if ($bytes === false || fwrite($stream, $bytes) !== strlen($bytes)) {
                throw new RuntimeException('The media fixture was not completely written.');
            }
        } catch (\Throwable $error) {
            fclose($stream);
            unlink($absolute);
            throw $error;
        }
        fclose($stream);
        chmod($absolute, 0600);

        return [
            'entry_id' => $entryId, 'disk' => MediaDisks::PRIVATE, 'path' => $path,
            'mime' => 'image/png', 'size_bytes' => strlen($bytes), 'checksum' => hash('sha256', $bytes),
            'visibility' => 'private', 'width' => 1, 'height' => 1, 'duration_ms' => null,
        ];
    }

    private function checkParents(string $absolute, bool $create): void
    {
        $relative = substr(dirname($absolute), strlen($this->output) + 1);
        $current = $this->output;
        foreach (explode('/', $relative) as $part) {
            $current .= '/'.$part;
            if (is_link($current) || (file_exists($current) && ! is_dir($current))) {
                throw new RuntimeException('Refusing a media fixture through a link or a non-directory ancestor.');
            }
            if ($create && ! is_dir($current) && ! mkdir($current, 0700)) {
                throw new RuntimeException('Cannot create an owned media fixture directory.');
            }
        }
    }
}
