<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Media;

use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The byte operations custody is built from, each one saying clearly whether it happened — ADR-042 decision 5.
 *
 * @internal
 *
 * ⚠️ THREE ANSWERS TO "WHAT IS THERE?", NEVER TWO. A file is absent, or it has a hash, or asking failed — and a hash
 * that could not be read is never taken for an absent file, which would let a step decide the only copy was not there.
 * Every comparison goes through `same()`, which is false unless both sides have a hash.
 *
 * ⚠️ A COPY IS WRITTEN BESIDE ITS PATH, READ BACK, AND ONLY THEN MOVED INTO PLACE, on a local disk: a rename replaces the
 * destination whole, so a Kitsune path holds nothing, the file it held before, or a complete copy that has been read
 * back — never part of one. An object store's PUT is all or nothing already. No `fsync`: a copy counts once it is
 * written and read back with a matching hash, the durability `MediaLibrary::store()` gives every file (decision 3,
 * Adam, 2026-09-24).
 */
final class MediaBytes
{
    /** The name a copy is written under, beside its path, before it is read back and renamed into place. */
    public const PARTIAL = '.kitsune-partial';

    public static function local(string $disk): bool
    {
        return Storage::disk($disk) instanceof LocalFilesystemAdapter;
    }

    public static function present(string $disk, string $path): bool
    {
        try {
            return Storage::disk($disk)->fileExists($path);
        } catch (Throwable $failure) {
            throw new MediaCustodyFailure('unknown', $disk, $path, $failure);
        }
    }

    /** The file's SHA-256, or null when it is not there — and a failure, never null, when it is there and unreadable. */
    public static function hash(string $disk, string $path): ?string
    {
        if (! self::present($disk, $path)) {
            return null;
        }

        try {
            $hash = Storage::disk($disk)->checksum($path, ['checksum_algo' => 'sha256']);
        } catch (Throwable $failure) {
            throw new MediaCustodyFailure('unreadable', $disk, $path, $failure);
        }

        if (! is_string($hash) || $hash === '') {
            throw new MediaCustodyFailure('unreadable', $disk, $path);
        }

        return $hash;
    }

    public static function same(?string $a, ?string $b): bool
    {
        return $a !== null && $b !== null && hash_equals($a, $b);
    }

    public static function partial(string $path): string
    {
        return $path.self::PARTIAL;
    }

    /**
     * Copy a file to another disk and prove the copy is whole: its SHA-256 must be the one expected.
     *
     * @throws MediaCustodyFailure
     */
    public static function copyVerified(string $from, string $to, string $path, string $expected): void
    {
        if (self::sameObject($from, $to, $path)) {
            throw new MediaCustodyFailure('coinciding', $to, $path);
        }

        $destination = self::local($to) ? self::partial($path) : $path;

        try {
            $stream = Storage::disk($from)->readStream($path);
        } catch (Throwable $failure) {
            throw new MediaCustodyFailure('unreadable', $from, $path, $failure);
        }

        if (! is_resource($stream)) {
            throw new MediaCustodyFailure('unreadable', $from, $path);
        }

        try {
            $written = Storage::disk($to)->writeStream($destination, $stream);
        } catch (Throwable $failure) {
            self::discard($to, $destination);

            throw new MediaCustodyFailure('copy', $to, $path, $failure);
        } finally {
            self::close($stream);
        }

        if ($written === false) {
            self::discard($to, $destination);

            throw new MediaCustodyFailure('copy', $to, $path);
        }

        try {
            $copied = self::hash($to, $destination);
        } catch (MediaCustodyFailure $failure) {
            self::discard($to, $destination);

            throw $failure;
        }

        if (! self::same($expected, $copied)) {
            self::discard($to, $destination);

            throw new MediaCustodyFailure('verify', $to, $path);
        }

        if ($destination !== $path) {
            $moved = false;

            try {
                $moved = Storage::disk($to)->move($destination, $path);
            } catch (Throwable) {
                $moved = false;
            }

            if (! $moved) {
                self::discard($to, $destination);

                throw new MediaCustodyFailure('rename', $to, $path);
            }
        }
    }

    /**
     * Delete a file and confirm it is gone.
     *
     * @throws MediaCustodyFailure
     */
    public static function delete(string $disk, string $path): void
    {
        try {
            $deleted = Storage::disk($disk)->delete($path);
        } catch (Throwable $failure) {
            throw new MediaCustodyFailure('delete', $disk, $path, $failure);
        }

        if (! $deleted || self::present($disk, $path)) {
            throw new MediaCustodyFailure('delete', $disk, $path);
        }
    }

    /**
     * Whether two local disks reach the same file at this path — one object on disk, whatever the names.
     *
     * An absent file is never the same object; a disk that is not local is never compared this way.
     */
    public static function sameObject(string $a, string $b, string $path): bool
    {
        if (! self::local($a) || ! self::local($b)) {
            return false;
        }

        $first = @stat(Storage::disk($a)->path($path));
        $second = @stat(Storage::disk($b)->path($path));

        return is_array($first) && is_array($second)
            && $first['dev'] === $second['dev'] && $first['ino'] === $second['ino'];
    }

    /**
     * Whether two local disks name one directory entry at this path: their directories for it are one directory, as
     * the filesystem resolves them, so the path on each is the same name for the same file.
     *
     * ⚠️ NOT THE SAME FILE, THE SAME ENTRY — review of slice 5b. A hard link, a symlinked file or a bind mount reaches one
     * file through two entries, and removing the other entry is what takes that name off the web; only one entry under
     * two disk names has nothing to remove. A mount the path's resolution cannot see reads as two entries, never as one.
     */
    public static function sameEntry(string $a, string $b, string $path): bool
    {
        if (! self::local($a) || ! self::local($b)) {
            return false;
        }

        $first = realpath(dirname(Storage::disk($a)->path($path)));
        $second = realpath(dirname(Storage::disk($b)->path($path)));

        return $first !== false && $first === $second;
    }

    /** An adapter may already have closed the stream it was handed. */
    private static function close(mixed $stream): void
    {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    /** Remove a copy that did not verify, as far as the disk allows: it is never anything a row names. */
    private static function discard(string $disk, string $path): void
    {
        try {
            Storage::disk($disk)->delete($path);
        } catch (Throwable) {
            // Left for kitsune:media-prune, which removes a temporary copy under its row's lock.
        }
    }
}
