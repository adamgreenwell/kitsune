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
 * A file's visibility left as it was — ADR-042 decision 32 — in words whoever asked is shown (decision 7).
 *
 * ⚠️ THE MESSAGE NAMES THE ENTRY AND AT MOST A DISK, NEVER A PATH. It reaches the editor who asked, in the panel as much
 * as in a log, and a stored file's path is not theirs to read; the path goes to the log alone, as a withdrawal's does
 * (`MediaWithdrawalRefused`). A refusal while making a file private that comes from its bytes is that class, in the
 * trash's own words; this one is the switch's.
 */
final class MediaVisibilityRefused extends RuntimeException
{
    /** The entry, or its row, is gone. */
    public const GONE = 'gone';

    /** The entry is neither this site's nor shared with this site's organisation. */
    public const OUT_OF_SCOPE = 'out_of_scope';

    /** The acting user may not publish the entry's type. */
    public const NOT_PERMITTED = 'not_permitted';

    /** The entry is in the trash, where its file is private whatever it is set to. */
    public const TRASHED = 'trashed';

    /** No file is recorded for the entry. */
    public const NO_FILE = 'no_file';

    /** The configured media disks cannot keep a private file off the web. */
    public const UNSAFE_DISKS = 'unsafe_disks';

    /** The row's path is not written as the disks read it. */
    public const MISNAMED = 'misnamed';

    /** A JPEG's row names a disk other than the configured private one. */
    public const UNSETTLED = 'unsettled';

    /** A copy of a JPEG still private is on a disk the web serves. */
    public const EXPOSED = 'exposed';

    /** A copy of a JPEG is on a disk custody asks that is not the configured private one. */
    public const STRAY = 'stray';

    /** The configured private disk holds no copy of a JPEG. */
    public const MISSING = 'missing';

    /** A copy could not be read, or whether one is there could not be told. */
    public const UNREADABLE = 'unreadable';

    /** Whether a disk holds a copy cannot be told: its root cannot be looked at (Adam, decision 25). */
    public const UNKNOWN_ROOT = 'unknown_root';

    /** A copy is on a read-through disk, which custody neither reads nor removes a copy through. */
    public const READ_THROUGH = 'read_through';

    /** The file changed while it was read. */
    public const CHANGED = 'changed';

    /** The copy without the location could not be written and read back. */
    public const COPY_FAILED = 'copy_failed';

    /** The copy without the location failed its own check. */
    public const STRIP_FAILED = 'strip_failed';

    /** `EXPOSED`'s detail when what a served disk holds is a partial copy — a write that did not finish. */
    public const PARTIAL = 'partial';

    /**
     * @param  string  $to  "public" or "private"
     * @param  ?string  $disk  the disk the refusal is about, where there is one
     * @param  ?string  $detail  the type's handle for `NOT_PERMITTED`, the private disk for `UNSETTLED`, the
     *                           configuration's own refusal for `UNSAFE_DISKS`, and `PARTIAL` for a partial copy `EXPOSED`
     */
    public function __construct(
        public readonly int $entryId,
        public readonly string $reason,
        string $to,
        public readonly ?string $disk = null,
        ?string $detail = null,
        ?Throwable $previous = null,
    ) {
        $disk = (string) $disk;

        parent::__construct(sprintf('Refusing to make entry %d %s: ', $entryId, $to).match ($reason) {
            self::GONE => 'it no longer exists.',
            self::OUT_OF_SCOPE => 'it is neither this site\'s nor shared with this site\'s organisation, so nothing was changed.',
            self::NOT_PERMITTED => sprintf(
                'that needs [entry.%s.publish], which the acting user does not hold, so nothing was changed (ADR-042 decision 32).',
                (string) $detail,
            ),
            self::TRASHED => 'it is in the trash, where its file is private whatever it is set to, so nothing was changed. '
                .'Restore it first.',
            self::NO_FILE => 'no file is recorded for it, so nothing was changed.',
            self::UNSAFE_DISKS => 'the configured media disks cannot keep a private file off the web, so nothing was '
                .'changed. '.(string) $detail,
            self::MISNAMED => sprintf(
                'its row\'s path is not written as the disks read it, on [%s], so nothing was changed. The log names the '
                .'row: correct media_files.path to the path its file is under, as the disks read it, and a retry fails the '
                .'same way until then.',
                $disk,
            ),
            self::UNSETTLED => sprintf(
                'its row names [%s], and a JPEG is made public only from the private disk, [%s], where its location is '
                .'removed before anything reaches the web — so nothing was changed. kitsune:media-reconcile --entry=%d '
                .'--force moves it there, and kitsune:media-prune --force then removes any copy it leaves; then make it '
                .'public again.',
                $disk,
                (string) $detail,
                $entryId,
            ),
            self::EXPOSED => $detail === self::PARTIAL ? sprintf(
                'a partial copy of its file, left by a write that did not finish, is on [%s], a disk the web serves, where '
                .'a private file\'s never is; making it public would leave it as it is, its location with it, so nothing '
                .'was changed. kitsune:media-prune --force removes it; then make it public again.',
                $disk,
            ) : sprintf(
                'a copy of its file is on [%s], a disk the web serves, where a private file never is; making it public '
                .'would leave that copy as it is, its location with it, so nothing was changed. kitsune:media-reconcile '
                .'--entry=%d --force takes it off; then make it public again.',
                $disk,
                $entryId,
            ),
            self::STRAY => sprintf(
                'a copy of its file is also on [%s], which making it public would leave as it is, its location with it, '
                .'so nothing was changed. kitsune:media-prune lists it as an extra copy, and --force removes it; then make '
                .'it public again.',
                $disk,
            ),
            self::MISSING => sprintf(
                'the private disk, [%s], holds no copy of its file, so nothing was changed. kitsune:media-reconcile '
                .'--entry=%d lists where it is; restore it from a backup, or erase the entry.',
                $disk,
                $entryId,
            ),
            self::UNKNOWN_ROOT => sprintf(
                'whether [%s] holds a copy cannot be told, because its root cannot be looked at, so nothing was changed. '
                .'See that the user running this may search every directory above that disk\'s root; a retry fails the '
                .'same way until then.',
                $disk,
            ),
            self::READ_THROUGH => sprintf(
                'a copy is on [%s], a read-through disk, which custody neither reads nor removes a copy through, so '
                .'nothing was changed. The log names the file: compare that copy with the others by hand and take it off '
                .'through the disk each half is — a retry fails the same way until then.',
                $disk,
            ),
            self::CHANGED => sprintf('its file on [%s] changed while it was read, so nothing was changed. Try again.', $disk),
            self::COPY_FAILED => sprintf(
                'its copy without the location could not be written and read back on [%s], so it stays private. The log '
                .'names the file; retry once the disk answers.',
                $disk,
            ),
            self::STRIP_FAILED => 'its copy without the location failed its own check, so it stays private and as it '
                .'was. The log says why.',
            default => sprintf(
                'a copy of its file could not be read on [%s], so nothing was changed. The log names the file; retry once '
                .'the disk answers.',
                $disk,
            ),
        }, 0, $previous);
    }
}
