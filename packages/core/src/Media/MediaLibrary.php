<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Media;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\MediaFile;
use Kitsune\Core\Tenancy\Context;
use RuntimeException;
use Throwable;

/**
 * Put a file into the library: an entry, and the bytes behind it — ADR-016 and ADR-041.
 *
 * @internal
 *
 * ⚠️ BYTES FIRST, ROWS SECOND, AND THE ORDER IS THE DECISION. Neither order is free of failure, so the
 * question is which residue is recoverable. Rows first leaves a media entry pointing at a file that does not
 * exist — a broken asset an operator sees and cannot explain. Bytes first leaves an unreferenced file, which
 * costs disk and nothing else, and which a sweep can find by asking the table what it knows about. So the
 * bytes are written, the rows are committed, and a failure in between removes the bytes it just wrote.
 *
 * ⚠️ THE CLEANUP IS BEST EFFORT AND SAYS SO. A crash between writing bytes and rolling back leaves the file,
 * because a process that has stopped cannot tidy up after itself. That is the residual cost of the order
 * above, it is bounded to wasted disk, and the repair is a sweep rather than anything this method can promise.
 *
 * ⚠️ NO DEDUPE, THOUGH THE CHECKSUM IS STORED. ADR-016 published `checksum` as "dedupe + integrity" and
 * integrity is what it does here. Dedupe means two entries sharing one file, which makes disposal a
 * reference-counting problem — the shape ADR-028 already had to solve for generated columns — and that is a
 * decision with consequences rather than an optimisation to slip in. The column is populated so the decision
 * stays available.
 */
final class MediaLibrary
{
    /**
     * Store a file as a new media entry, and return the entry.
     *
     * @param  string  $absolutePath  a readable file on local disk — an upload's temporary path, or a file a
     *                                console command is importing
     * @param  string  $originalName  what the caller called it. Used for the title, never for the location.
     *
     * @throws RuntimeException
     */
    public static function store(
        string $absolutePath,
        string $originalName,
        EntryType $type,
        string $visibility = 'private',
        ?string $title = null,
    ): Entry {
        if (! in_array($visibility, MediaFile::VISIBILITIES, true)) {
            throw new RuntimeException(sprintf(
                'Refusing to store media with visibility [%s]. It is one of: %s — and ADR-041 makes private '
                .'the default, so an unrecognised value is refused rather than resolved to something.',
                $visibility,
                implode(', ', MediaFile::VISIBILITIES),
            ));
        }

        /*
         * ⚠️ ASKED OF THE DATABASE, NOT OF THE INSTANCE HANDED IN — ADR-042 decision 1. `$type->is_media = true`
         * on an `article` loaded a moment ago is an attribute anybody can set, and the flag is only a promise once
         * it is the stored one, which the type's own guard then locks. One primary-key read, before any byte.
         */
        $isMedia = $type->exists
            && (bool) EntryType::query()->whereKey($type->getKey())->value('is_media');

        if (! $isMedia) {
            throw new RuntimeException(sprintf(
                'Refusing [%s]: [%s] is not a media type, so it has no way to show a file. Files are uploaded '
                .'into a type that was created to hold them, and an existing type cannot be switched to one '
                .'(ADR-042). Nothing was stored.',
                $originalName,
                $type->handle,
            ));
        }

        $orgId = app(Context::class)->orgId();

        if ($orgId === null) {
            throw new RuntimeException(
                'Cannot store media with no organisation in context: a media file is an entry, and an entry '
                .'write with no org is refused rather than merely unaudited (ADR-020). Set the context first.'
            );
        }

        if (! is_readable($absolutePath)) {
            throw new RuntimeException("Cannot store media: [{$absolutePath}] is not readable.");
        }

        $size = (int) filesize($absolutePath);

        /* The refusals, before a single byte is written anywhere. */
        ['extension' => $extension, 'mime' => $mime] = MediaIntake::accept($originalName, $absolutePath, $size);

        /*
         * ⚠️ SANITISED BEFORE ANY BYTE IS WRITTEN, AND THE ORIGINAL IS NEVER STORED — ADR-041's departure 2.
         * `rich_text` keeps its pre-sanitisation original in `entry_revisions.unsanitized_values`, a column
         * `snapshot()` does not expose; a file has no equivalent hiding place, because a stored original is a
         * file waiting for a delivery path that forgets which one is which. So this rebinds `$source` and
         * everything downstream reads the sanitised file: the stream, the checksum, the size and the
         * dimensions. Leaving any one of them on `$absolutePath` writes a row that describes bytes nobody
         * stored — and `size_bytes` in particular is sent verbatim as `Content-Length`, so a stale one is a
         * truncated or hanging response for every SVG.
         */
        $source = $absolutePath;
        $sanitised = null;

        /*
         * ⚠️ THE `try` OPENS BEFORE THE TEMPORARY EXISTS, AND REVIEW FOUND WHY THAT MATTERS. It used to open
         * after the block below, so the ceiling re-check inside it could throw with the sanitised copy
         * already written and nothing arranged to remove it — a leaked file per refused upload, on the one
         * refusal path an attacker chooses the size of.
         */
        try {
            if (MediaIntake::isGuarded($extension)) {
                $sanitised = self::sanitisedCopy($absolutePath, $originalName);
                $source = $sanitised;
                $size = (int) filesize($source);

                /*
                 * ⚠️ THE CEILING IS CHECKED AGAIN, because sanitising can GROW a file: the document is
                 * re-serialised through the library's own writer. `GUARDED_MAX_BYTES` promises what gets
                 * stored, and the first check only knew what arrived.
                 */
                MediaIntake::refuseIfTooLarge($size, $originalName, MediaIntake::GUARDED_MAX_BYTES);
            }

            return self::write($source, $originalName, $extension, $mime, $size, $orgId, $type, $visibility, $title);
        } finally {
            /*
             * ⚠️ `finally`, SO THE TEMPORARY GOES ON BOTH PATHS. A sanitised copy left in the system temp
             * directory on every upload is an accumulating artifact of a security boundary, which is the last
             * place to leak files.
             */
            if ($sanitised !== null) {
                @unlink($sanitised);
            }
        }
    }

    /**
     * Write the bytes, then the rows — everything after the refusals.
     *
     * @throws RuntimeException
     */
    private static function write(
        string $source,
        string $originalName,
        string $extension,
        string $mime,
        int $size,
        int $orgId,
        EntryType $type,
        string $visibility,
        ?string $title,
    ): Entry {
        $disk = self::diskFor($visibility);
        $path = self::pathFor($orgId, MediaIntake::storedName($extension));

        $stream = fopen($source, 'rb');

        if ($stream === false) {
            throw new RuntimeException("Cannot store media: [{$source}] could not be opened.");
        }

        /* Streamed rather than read into memory: the ceiling is 64 MiB and the floor is 1 GB of RAM. */
        $written = Storage::disk($disk)->writeStream($path, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        if ($written === false) {
            throw new RuntimeException("Cannot store media: writing to [{$disk}:{$path}] failed.");
        }

        try {
            return Entry::query()->getConnection()->transaction(
                static function () use ($type, $title, $originalName, $extension, $disk, $path, $mime, $size, $source, $visibility): Entry {
                    $entry = Entry::create([
                        'entry_type_id' => $type->getKey(),
                        'title' => $title ?? pathinfo(basename($originalName), PATHINFO_FILENAME),
                        /*
                         * The slug carries the stored name's randomness, so two uploads of `logo.png` into one
                         * site cannot collide on `UNIQUE (site_id, entry_type_id, slug)` — and a retry after a
                         * failure is a new file rather than a conflict with the one that failed.
                         */
                        'slug' => Str::slug(pathinfo(basename($originalName), PATHINFO_FILENAME) ?: 'file')
                            .'-'.substr(basename($path, '.'.$extension), 0, 8),
                        /*
                         * ⚠️ PUBLISHED, and the consequence is real rather than hidden: an uploader who lacks
                         * `entry.{type}.publish` is refused by `refuseUnpermittedCreationAsPublished()`. A media
                         * file's gate is `visibility`, not `status` — an asset that is not available is not an
                         * asset — so this is the honest default, and an operator grants the permission to whoever
                         * uploads.
                         */
                        'status' => 'published',
                    ]);

                    MediaFile::create([
                        'entry_id' => $entry->getKey(),
                        'disk' => $disk,
                        'path' => $path,
                        'mime' => $mime,
                        'size_bytes' => $size,
                        'checksum' => hash_file('sha256', $source),
                        'visibility' => $visibility,
                        ...self::dimensionsOf($source),
                        'created_at' => now(),
                    ]);

                    return $entry;
                },
            );
        } catch (Throwable $e) {
            /* Best effort, as the docblock says: the bytes this call wrote go, and a crash leaves them. */
            Storage::disk($disk)->delete($path);

            throw $e;
        }
    }

    /**
     * Sanitise a guarded file into a temporary copy this class owns, and return its path.
     *
     * ⚠️ THE SANITISER IS RESOLVED HERE RATHER THAN CHECKED, and the refusal is not a repeat of `accept()`'s.
     * `MediaIntake::accept()` already declined this extension if nothing was bound, so reaching this with an
     * unbound container means the binding disappeared between the two calls — a module disabled mid-request,
     * or a caller reaching past `accept()`. Either way it fails closed and says which.
     *
     * ⚠️ `tempnam()` IN THE SYSTEM TEMP DIRECTORY, NEVER ON A DISK. Sanitised bytes are not the stored
     * artifact yet — the row write can still fail — and writing them to `local` or `public` first would put a
     * file under `media/` that no row claims and that `kitsune:media-prune` would report as an orphan on every
     * upload.
     *
     * @throws RuntimeException
     */
    private static function sanitisedCopy(string $absolutePath, string $originalName): string
    {
        if (! app()->bound(SanitisesSvg::class)) {
            throw new RuntimeException(sprintf(
                'Refusing [%s]: nothing implements [%s] any more, so these bytes cannot be made safe. Nothing '
                .'was written.',
                $originalName,
                SanitisesSvg::class,
            ));
        }

        $original = file_get_contents($absolutePath);

        if ($original === false) {
            throw new RuntimeException("Cannot store media: [{$absolutePath}] could not be read.");
        }

        $clean = app(SanitisesSvg::class)->sanitise($original);

        $temporary = tempnam(sys_get_temp_dir(), 'kitsune-svg-');

        if ($temporary === false || file_put_contents($temporary, $clean) === false) {
            if ($temporary !== false) {
                @unlink($temporary);
            }

            throw new RuntimeException("Cannot store media: the sanitised copy of [{$originalName}] could not be written.");
        }

        return $temporary;
    }

    /** ADR-041: visibility decides the disk, and therefore which delivery path can reach the bytes. */
    public static function diskFor(string $visibility): string
    {
        $key = $visibility === 'public' ? 'public' : 'private';
        $disk = config("kitsune.media.disks.{$key}");

        return is_string($disk) && $disk !== '' ? $disk : ($key === 'public' ? 'public' : MediaDisks::PRIVATE);
    }

    /**
     * ⚠️ THE ORG AND THE DATE ARE THE ONLY THINGS IN THE PATH, and none of it comes from the caller.
     *
     * A single directory holding every file an installation has ever taken is a directory nobody can list, so
     * the date fans it out. The org id keeps one customer's uploads under one prefix, which is what makes a
     * per-org sweep or export a prefix operation rather than a table scan.
     */
    private static function pathFor(int $orgId, string $storedName): string
    {
        return sprintf('media/%d/%s/%s', $orgId, now()->format('Y/m'), $storedName);
    }

    /**
     * Width and height when the file is an image, and nothing when it is not.
     *
     * `getimagesize()` is `ext/standard` — always compiled in, and it does not need GD. It returns false for
     * anything it cannot read, which is the answer for a PDF or an MP4 rather than an error.
     *
     * @return array{width: int|null, height: int|null, duration_ms: null}
     */
    private static function dimensionsOf(string $absolutePath): array
    {
        $size = @getimagesize($absolutePath);

        return [
            'width' => is_array($size) ? (int) $size[0] : null,
            'height' => is_array($size) ? (int) $size[1] : null,
            /* ADR-041: null in v1.0. Probing a video needs a binary the floor cannot assume. */
            'duration_ms' => null,
        ];
    }
}
