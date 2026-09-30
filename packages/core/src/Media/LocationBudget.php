<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Media;

/**
 * How much of a file's metadata `JpegLocation` reads before it stops — ADR-042 decision 30.
 *
 * @internal
 *
 * ⚠️ A CEILING ON WORK, NOT ON FILES A CAMERA WRITES. Every directory entry, segment, block and embedded image is paid
 * for as it is read, so a file built to make the walk loop or fan out is refused as public (`TOO_MANY`) in bounded time
 * rather than read for as long as it asks. The defaults sit far above any real photo: a camera JPEG holds a few dozen
 * segments, a handful of blocks and a few hundred entries, and an XMP packet or two whose longest element — Lightroom's
 * develop settings — carries a few hundred attributes. `attributes` is per element, not spent: libxml checks an
 * element's attributes against one another, so thousands on one element cost it seconds to read.
 */
final class LocationBudget
{
    public function __construct(
        public int $entries = 100_000,
        public int $blocks = 256,
        public int $segments = 8_192,
        public int $images = 16,
        public int $packets = 32,
        public int $attributes = 1_024,
    ) {}

    /**
     * @param  'entries'|'blocks'|'segments'|'images'|'packets'  $what
     *
     * @throws LocationUnremovable once it is spent
     */
    public function spend(string $what, int $count = 1): void
    {
        $this->{$what} -= $count;

        if ($this->{$what} < 0) {
            throw new LocationUnremovable(LocationUnremovable::TOO_MANY);
        }
    }
}
