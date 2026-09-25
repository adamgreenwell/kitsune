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
 * one delivery has been serving. Only when that disk holds nothing does a fixed order choose — the configured public
 * disk, the configured private disk, core's private disk, the served disks, any other disk asked, the target last (Adam,
 * decision 5, 2026-09-25) — and every choice but a match is logged with each disk's hash, because it means a copy was
 * changed outside Kitsune.
 */
final readonly class MediaKeeper
{
    /** A copy matches the recorded checksum. */
    public const MATCH = 'match';

    /** None matches, and the disk the row names holds a copy: it wins. */
    public const NAMED = 'named';

    /** None matches and the named disk holds nothing: the first copy in Adam's order, the target last. */
    public const FIRST = 'first';

    /** No disk custody asked holds the file. */
    public const MISSING = 'missing';

    /**
     * @param  ?string  $disk  the disk whose copy is kept; null when the file is missing
     * @param  ?string  $expected  the hash every copy is verified against: the recorded checksum on a match, the kept
     *                             copy's own hash otherwise
     * @param  bool  $targetHolds  whether the target disk already holds a copy with that hash
     * @param  array<string, ?string>  $hashes  every disk hashed, with what it read
     * @param  list<string>  $spare  the disks whose unreadable copy this choice may set aside: empty unless settle is
     *                               taking a file off the web (Adam, decision 6, 2026-09-25)
     * @param  array<string, MediaCustodyFailure>  $setAside  each disk found holding a copy it could not read, set aside
     *                                                        and never chosen, with its failure
     * @param  ?string  $checksum  the row's recorded checksum, so a step removing a copy the keeper never read can ask
     *                             whether it is the one that matches
     */
    public function __construct(
        public ?string $disk,
        public ?string $expected,
        public string $mode,
        public bool $targetHolds,
        public array $hashes,
        public array $spare = [],
        public array $setAside = [],
        public ?string $checksum = null,
    ) {}

    /**
     * Refuse to remove or overwrite a copy that matches the recorded checksum while the copy kept does not — one the
     * keeper read as absent, or never read, and that is there now.
     *
     * ⚠️ RULE 2, WHERE THE KEEPER COULD NOT SEE (review of slice 5b). A copy that flaps — an object store's 404 a moment
     * before, a sync tool rewriting it — reads as absent when the keeper hashes it, so the choice falls back to a copy
     * that differs; when a step then finds it there again, it is the one copy that is right, and nothing matching the
     * checksum is ever touched by a fallback.
     *
     * @throws MediaCustodyFailure
     */
    public function refuseToLose(string $disk, string $path, ?string $hash): void
    {
        if ($this->mode !== self::MATCH && MediaBytes::same($hash, $this->checksum)) {
            throw new MediaCustodyFailure('matches', $disk, $path);
        }
    }
}
