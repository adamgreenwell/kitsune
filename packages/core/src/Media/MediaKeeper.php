<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Media;

/**
 * The copy of a file custody keeps, and how it was chosen — ADR-042 decision 5.
 *
 * @internal
 *
 * ⚠️ THE RECORDED CHECKSUM FIRST, THEN THE DISK THE ROW NAMES. A copy matching the checksum `MediaLibrary` recorded
 * is the file. When none does, the copy on the disk the row names wins over any other (Adam, 2026-09-24): it is the
 * one delivery has been serving. Only when that disk holds nothing does a fixed order choose, and every choice but a
 * match is logged with each disk's hash, because it means a copy was changed outside Kitsune.
 */
final readonly class MediaKeeper
{
    /** A copy matches the recorded checksum. */
    public const MATCH = 'match';

    /** None matches, and the disk the row names holds a copy: it wins. */
    public const NAMED = 'named';

    /** None matches and the named disk holds nothing: the first copy in the configured public, private, served order. */
    public const FIRST = 'first';

    /** No disk custody asked holds the file. */
    public const MISSING = 'missing';

    /**
     * @param  ?string  $disk  the disk whose copy is kept; null when the file is missing
     * @param  ?string  $expected  the hash every copy is verified against: the recorded checksum on a match, the kept
     *                             copy's own hash otherwise
     * @param  bool  $targetHolds  whether the target disk already holds a copy with that hash
     * @param  array<string, ?string>  $hashes  every disk hashed, with what it read
     */
    public function __construct(
        public ?string $disk,
        public ?string $expected,
        public string $mode,
        public bool $targetHolds,
        public array $hashes,
    ) {}
}
