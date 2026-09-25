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
            .'stay as they were (ADR-042 decision 5). The log names the file; retry once the disk answers.',
            $operation,
            $entryId,
            match ($reason) {
                self::COPY_FAILED => 'a verified copy could not be written to the private disk',
                self::DELETE_FAILED => 'a copy could not be removed from',
                self::UNREADABLE => 'a copy could not be read on',
                default => 'the private disk and the disk holding the file are one place:',
            },
            $disk,
        ), 0, $previous);
    }
}
