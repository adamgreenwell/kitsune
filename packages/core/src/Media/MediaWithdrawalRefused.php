<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Media;

use RuntimeException;
use Throwable;

/**
 * A trash or an erasure refused because its file could not be withdrawn from the web — ADR-042 decision 5.
 *
 * ⚠️ THE MESSAGE NAMES THE ENTRY AND THE DISK, NEVER THE PATH. It reaches whoever asked for the delete, in the panel
 * as much as in a log, and a stored file's path is not theirs to read; the path goes to the log alone.
 */
final class MediaWithdrawalRefused extends RuntimeException
{
    /** A verified copy could not be written to the private disk. */
    public const COPY_FAILED = 'copy_failed';

    /** A copy on a disk the web serves could not be deleted, or was still there afterwards. */
    public const DELETE_FAILED = 'delete_failed';

    /** A copy could not be read, so which copy to keep could not be known. */
    public const UNREADABLE = 'unreadable';

    /** The private disk and a disk holding the file are one place, so a copy would be the file itself. */
    public const COINCIDING = 'coinciding';

    /** The configured media disks cannot keep a withdrawn file private: they are one place, or the web serves the private one. */
    public const UNSAFE_DISKS = 'unsafe_disks';

    /**
     * A copy changed while it was read: it read as absent a moment after it was seen, or it matched the recorded checksum
     * after the copy to keep was chosen without it (review of slice 5b).
     */
    public const CHANGED = 'changed';

    /**
     * A copy is on a read-through disk, which custody neither reads nor removes a copy through (ADR-042 decision 5, open
     * for Adam): a retry fails the same way until that copy is dealt with by hand (review of slice 5c).
     */
    public const READ_THROUGH = 'read_through';

    /**
     * The row's path is not written as the disks read it — written past `MediaFile`, by a direct insert or an import — so a
     * copy or removal would act on another path: the row is corrected, and a retry fails the same way until then.
     */
    public const MISNAMED = 'misnamed';

    /**
     * Whether a disk the web serves holds a copy cannot be told: its root cannot be looked at, for a reason other than its
     * not being there (Adam, decision 25) — so it is not taken to hold nothing, and a retry fails the same way until then.
     */
    public const UNKNOWN_ROOT = 'unknown_root';

    public function __construct(
        public readonly int $entryId,
        public readonly string $reason,
        public readonly string $disk,
        string $operation,
        ?Throwable $previous = null,
    ) {
        // The configuration's own refusal names disks and keys, never a file: it is shown as it is.
        if ($reason === self::UNSAFE_DISKS) {
            parent::__construct(sprintf(
                'Refusing to %s entry %d: the configured media disks cannot keep a withdrawn file private, so the entry '
                .'and its file stay as they were. %s',
                $operation,
                $entryId,
                $previous?->getMessage() ?? '',
            ), 0, $previous);

            return;
        }

        parent::__construct(sprintf(
            'Refusing to %s entry %d: its file could not be withdrawn from the web — %s [%s] — so the entry and its file '
            .'stay as they were (ADR-042 decision 5). %s',
            $operation,
            $entryId,
            match ($reason) {
                self::COPY_FAILED => 'a verified copy could not be written to the private disk',
                self::DELETE_FAILED => 'a copy could not be removed from',
                self::UNREADABLE => 'a copy could not be read on',
                self::CHANGED => 'a copy changed while it was read, on',
                self::READ_THROUGH => 'a copy is on a read-through disk, which custody neither reads nor removes a copy through:',
                self::MISNAMED => 'its row\'s path is not written as the disks read it, on',
                self::UNKNOWN_ROOT => 'whether a disk the web serves holds a copy cannot be told, because its root cannot be looked at:',
                default => 'the private disk and the disk holding the file are one place:',
            },
            $disk,
            match ($reason) {
                self::READ_THROUGH => 'The log names the file: compare that copy with the others by hand (a partial copy, one custody did not finish writing, needs none), and take it off the read-through disk through the disk each half is — a retry fails the same way until then.',
                self::MISNAMED => 'The log names the row: correct media_files.path to the path its file is under, as the disks read it, unless another row names that path — a file under the row\'s literal name moved by hand to such a path first — and a retry fails the same way until then.',
                self::UNKNOWN_ROOT => 'See that the user running this may search every directory above that disk\'s root; a retry fails the same way until then.',
                default => 'The log names the file; retry once the disk answers.',
            },
        ), 0, $previous);
    }
}
