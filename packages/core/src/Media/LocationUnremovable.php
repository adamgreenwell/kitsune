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
 * Why a JPEG's location could not be removed with certainty — ADR-042 decision 30.
 *
 * @internal
 *
 * ⚠️ NEVER SHOWN AS IT IS, AND NEVER ESCAPES `MediaLocation`, which turns it into a `MediaRefused` naming the file and
 * the reason in an uploader's words. The code is what the tests assert, so a refusal cannot pass for another one.
 */
final class LocationUnremovable extends RuntimeException
{
    /** A directory that runs past its block where a GPS pointer is, or more directories than any camera writes. */
    public const EXIF_DAMAGED = 'EXIF_DAMAGED';

    /** GPS bytes that other EXIF data points into too, so zeroing them would damage what is kept. */
    public const GPS_SHARED = 'GPS_SHARED';

    /** XMP mentioning GPS in an encoding other than UTF-8, or holding a NUL. */
    public const XMP_UNREADABLE = 'XMP_UNREADABLE';

    /** XMP mentioning GPS that is not well-formed, or declares a DTD. */
    public const XMP_MALFORMED = 'XMP_MALFORMED';

    /** XMP whose GPS properties cannot be blanked without changing something else. */
    public const XMP_MIXED = 'XMP_MIXED';

    /** A block carrying location that runs across the segment holding it, or two edits that overlap. */
    public const BLOCKS_OVERLAP = 'BLOCKS_OVERLAP';

    /** An EXIF or XMP block carrying location inside a segment that is not metadata — a comment, a colour profile. */
    public const OUT_OF_PLACE = 'OUT_OF_PLACE';

    /** More segments, blocks, directory entries or images than `LocationBudget` reads. */
    public const TOO_MANY = 'TOO_MANY';

    /** A file that does not begin as a JPEG does, though it was sniffed as one. */
    public const NOT_JPEG = 'NOT_JPEG';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
