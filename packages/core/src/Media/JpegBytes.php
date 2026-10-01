<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Media;

use RuntimeException;

/**
 * A file's bytes, read a window at a time — for `JpegLocation`.
 *
 * @internal
 *
 * ⚠️ NEVER THE WHOLE FILE IN MEMORY. The upload ceiling is 64 MiB and the floor is 1 GB of RAM (ADR-027), which is why
 * `MediaLibrary::write()` streams. Metadata is small — a segment holds at most 64 KiB — but finding it means walking the
 * picture's scan data to its end, and the images after it, so reads go through one window of `WINDOW` bytes, loaded
 * again only when a read leaves it. A string can stand in for a file, for the tests and for nothing else.
 */
final class JpegBytes
{
    public const WINDOW = 1 << 20;

    private string $window = '';

    private int $windowAt = 0;

    /** @param  resource|null  $handle */
    private function __construct(
        private $handle,
        private readonly int $size,
        private readonly string $path,
    ) {}

    /** @throws RuntimeException */
    public static function ofFile(string $path): self
    {
        $handle = @fopen($path, 'rb');
        $stat = $handle === false ? false : fstat($handle);

        if ($handle === false || $stat === false) {
            throw new RuntimeException("Cannot read media: [{$path}] could not be opened.");
        }

        return new self($handle, (int) $stat['size'], $path);
    }

    public static function ofString(string $bytes): self
    {
        $source = new self(null, strlen($bytes), 'a string');
        $source->window = $bytes;

        return $source;
    }

    public function size(): int
    {
        return $this->size;
    }

    /**
     * Up to `$length` bytes from `$at`, fewer where the file ends first.
     *
     * @throws RuntimeException where the file is shorter than it was when it was opened
     */
    public function read(int $at, int $length): string
    {
        $at = max(0, $at);
        $length = min($length, $this->size - $at);

        if ($length <= 0) {
            return '';
        }

        if ($at < $this->windowAt || $at + $length > $this->windowAt + strlen($this->window)) {
            if ($length > self::WINDOW) {
                return $this->direct($at, $length);
            }

            $this->load($at);
        }

        return substr($this->window, $at - $this->windowAt, $length);
    }

    /** The byte at `$at`, or -1 past the end. */
    public function byte(int $at): int
    {
        if ($at < 0 || $at >= $this->size) {
            return -1;
        }

        if ($at < $this->windowAt || $at >= $this->windowAt + strlen($this->window)) {
            $this->load($at);
        }

        return ord($this->window[$at - $this->windowAt]);
    }

    /**
     * Where `$needle` next begins at or after `$from`, wholly before `$limit`, or null.
     *
     * ⚠️ WINDOWS OVERLAP BY THE NEEDLE'S LENGTH LESS ONE, so a needle straddling two windows is still found.
     */
    public function find(string $needle, int $from, int $limit): ?int
    {
        $limit = min($limit, $this->size);
        $width = strlen($needle);
        $from = max(0, $from);

        while ($from + $width <= $limit) {
            if ($from < $this->windowAt || $from + $width > $this->windowAt + strlen($this->window)) {
                $this->load($from);
            }

            $found = strpos($this->window, $needle, $from - $this->windowAt);

            if ($found !== false) {
                $at = $this->windowAt + $found;

                return $at + $width <= $limit ? $at : null;
            }

            $windowEnd = $this->windowAt + strlen($this->window);

            if ($windowEnd >= $limit) {
                return null;
            }

            $from = max($from + 1, $windowEnd - $width + 1);
        }

        return null;
    }

    /**
     * Where the next marker that ends scan data begins — an `FF` followed by anything but stuffing (`00`), a restart
     * (`D0`–`D7`) or more fill — at or after `$from` and before `$limit`, or null.
     *
     * ⚠️ ASKED OF PCRE, NOT A LOOP OVER EVERY `FF`. Scan data holds an `FF 00` every few hundred bytes, and a file built
     * to hold one every other byte would otherwise walk 64 MiB a byte at a time, twice, inside one upload's request.
     *
     * @throws RuntimeException where the pattern could not run
     */
    public function nextMarker(int $from, int $limit): ?int
    {
        return $this->search('/\xFF[^\x00\xD0-\xD7\xFF]/', $from, $limit, 2)[0] ?? null;
    }

    /**
     * The next match of `$pattern` wholly at or after `$from` and before `$limit` — where it begins, and what it
     * matched — or null. `$longest` is the longest match the pattern can make, which windows overlap by.
     *
     * @return array{0: int, 1: string}|null
     *
     * @throws RuntimeException where the pattern could not run
     */
    public function search(string $pattern, int $from, int $limit, int $longest): ?array
    {
        $limit = min($limit, $this->size);
        $from = max(0, $from);

        while ($from < $limit) {
            if ($from < $this->windowAt || $from >= $this->windowAt + strlen($this->window)) {
                $this->load($from);
            }

            $found = preg_match($pattern, $this->window, $match, PREG_OFFSET_CAPTURE, $from - $this->windowAt);

            if ($found === false) {
                throw new RuntimeException('A file could not be searched: '.preg_last_error_msg());
            }

            $windowEnd = $this->windowAt + strlen($this->window);

            if ($found === 1) {
                $at = $this->windowAt + $match[0][1];
                $text = $match[0][0];

                return $at + strlen($text) <= $limit ? [$at, $text] : null;
            }

            if ($windowEnd >= $limit) {
                return null;
            }

            // A match may begin in the last bytes of this window and end in the next: the next window starts there.
            $from = max($from + 1, $windowEnd - $longest + 1);
            $this->load($from);
        }

        return null;
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }

        $this->handle = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function load(int $at): void
    {
        $this->windowAt = $at;
        $this->window = $this->direct($at, min(self::WINDOW, $this->size - $at));
    }

    private function direct(int $at, int $length): string
    {
        if ($this->handle === null) {
            // A string's window is the whole of it, so a read outside it is outside the string.
            throw new RuntimeException('A read fell outside '.$this->path.'.');
        }

        if (fseek($this->handle, $at) !== 0) {
            throw new RuntimeException("Cannot read media: [{$this->path}] could not be read at {$at}.");
        }

        $bytes = '';

        while (strlen($bytes) < $length) {
            $piece = fread($this->handle, $length - strlen($bytes));

            if ($piece === false || $piece === '') {
                throw new RuntimeException("Cannot read media: [{$this->path}] changed while it was read.");
            }

            $bytes .= $piece;
        }

        return $bytes;
    }
}
