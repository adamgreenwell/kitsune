<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

/**
 * A stream that hands each read one byte, as a network stream may hand fewer than were asked for — so a reader that takes
 * one `fread()` for all it asked is seen to be wrong (Codex, #165). Opened as `kitsune-trickle://` and the file's path,
 * URL-encoded; it reads that file.
 */
final class TrickleStream
{
    public const SCHEME = 'kitsune-trickle';

    /** @var resource|null */
    public $context;

    /** @var resource|null */
    private $file;

    public static function register(): void
    {
        if (! in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
    }

    /** @return resource|false */
    public static function open(string $path)
    {
        self::register();

        return fopen(self::SCHEME.'://'.rawurlencode($path), 'rb');
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        $file = @fopen(rawurldecode(substr($path, strlen(self::SCHEME) + 3)), 'rb');
        $this->file = $file === false ? null : $file;

        return $this->file !== null;
    }

    public function stream_read(int $count): string|false
    {
        return $this->file === null ? false : fread($this->file, 1);
    }

    public function stream_eof(): bool
    {
        return $this->file === null || feof($this->file);
    }

    /** @return array<int|string, int> */
    public function stream_stat(): array
    {
        return $this->file === null ? [] : (fstat($this->file) ?: []);
    }

    public function stream_close(): void
    {
        if ($this->file !== null) {
            fclose($this->file);
        }
    }
}
