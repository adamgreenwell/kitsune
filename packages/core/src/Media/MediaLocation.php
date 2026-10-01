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
 * Where a file was made, removed as it is made public — Adam, ADR-042 decision 30.
 *
 * ⚠️ THE ONE STEP A FILE GOING PUBLIC PASSES THROUGH, and the seam decision 32 calls when a stored file is made public:
 * given a file, it answers a copy without the GPS data its format can carry, or null where there is nothing to remove
 * and the file is used as it is. It never writes the file it is given, and a copy's copy is null — so a file stripped
 * once and made public again is stored with the same checksum.
 *
 * ⚠️ JPEG ALONE, as the decision has it. Which other formats `MediaIntake` accepts can carry location, and whether each
 * could lose it the same way, is recorded in ADR-042 (decision 30, as built); each is a slice of its own.
 *
 * ⚠️ REFUSED AS PUBLIC, NEVER STORED WITH IT, where it cannot be removed with certainty — in words that say why and
 * offer private, where a file is served only to those who may view it and keeps what it was uploaded with.
 */
final class MediaLocation
{
    /**
     * The formats whose location is removed as they are made public, as `MediaFormats` keys.
     *
     * ⚠️ THE CONFIRMATIONS' WORDS CLAIM THIS LIST AND NO MORE — the one statement `kitsune::media.upload.public_warning`,
     * `kitsune::media.visibility.public_warning` and `kitsune::media.visibility.bulk.public_warning` are all built from:
     * widening it changes them in the same change, and a test holds the formats they name to it.
     */
    public const STRIPPED = ['jpeg'];

    /** What each refusal says went wrong, finishing "Refusing [file] as public: …". */
    private const REASONS = [
        LocationUnremovable::EXIF_DAMAGED => 'its EXIF data is damaged',
        LocationUnremovable::GPS_SHARED => 'its GPS data shares bytes with other EXIF data',
        LocationUnremovable::XMP_UNREADABLE => 'its XMP data is not text this can read',
        LocationUnremovable::XMP_MALFORMED => 'its XMP data mentions GPS and is not well-formed',
        LocationUnremovable::XMP_MIXED => 'its XMP data would change beyond its GPS properties',
        LocationUnremovable::BLOCKS_OVERLAP => 'its metadata blocks overlap one another or the picture\'s own segments',
        LocationUnremovable::OUT_OF_PLACE => 'its GPS data sits inside a part of the file that is not its metadata',
        LocationUnremovable::TOO_MANY => 'it holds more metadata than this reads',
        LocationUnremovable::NOT_JPEG => 'it does not begin as a JPEG does',
    ];

    public static function strips(?string $format): bool
    {
        return in_array($format, self::STRIPPED, true);
    }

    /** A JPEG's other names: the types and extensions a row written past the model may give one (decision 32). */
    private const JPEG_TYPES = ['image/jpeg', 'image/pjpeg', 'image/jpg'];

    private const JPEG_EXTENSIONS = ['jpg', 'jpeg', 'jpe', 'jfif', 'pjpeg', 'pjp'];

    /**
     * The format a stored row says its file is, for making it public — ADR-042 decision 32.
     *
     * ⚠️ READ GENEROUSLY, AND NEVER ALONE. `MediaLibrary::store()` writes a lowercase extension its bytes were checked
     * against, but a row written past the model — an import's `PHOTO.JPG`, a browser's `.jfif`, a `pjpeg` type, a type
     * with parameters — is made public through here too. So a JPEG's every name is read as one, in any case; and because
     * a row can still call a JPEG something else entirely, `MediaVisibility` asks the file's own bytes as well.
     */
    public static function formatOf(string $path, ?string $mime): ?string
    {
        $type = mb_strtolower(trim(explode(';', (string) $mime, 2)[0]));
        $extension = mb_strtolower(trim(pathinfo(trim($path), PATHINFO_EXTENSION)));

        if (in_array($type, self::JPEG_TYPES, true) || in_array($extension, self::JPEG_EXTENSIONS, true)) {
            return 'jpeg';
        }

        return MediaFormats::of($extension);
    }

    /**
     * A copy of the file without its location, in a temporary the caller owns and removes — or null.
     *
     * @param  string  $absolutePath  a readable file on local disk, never written
     * @param  string|null  $format  its `MediaFormats` key
     * @param  bool  $stored  whether the file is already stored, and being made public (decision 32), rather than uploaded
     *
     * @throws MediaRefused where the location cannot be removed with certainty, in words an uploader is shown (decision 7)
     * @throws RuntimeException for a file that could not be read or copied, or a copy that failed its own check
     */
    public static function strippedCopy(string $absolutePath, ?string $format, string $originalName, bool $stored = false): ?string
    {
        if (! self::strips($format)) {
            return null;
        }

        try {
            return JpegLocation::strippedCopy($absolutePath);
        } catch (LocationUnremovable $unremovable) {
            throw self::refusal($originalName, $unremovable->reason, $stored);
        }
    }

    /** @internal the words for a refusal, for `strippedCopy()` and its tests */
    public static function refusal(string $originalName, string $reason, bool $stored = false): MediaRefused
    {
        if ($stored) {
            return new MediaRefused(sprintf(
                'Refusing to make [%s] public: %s, so the GPS data it may carry cannot be removed with certainty. It '
                .'stays private, where it is served only to those who may view it; saved again without its location and '
                .'uploaded, it can be public.',
                $originalName,
                self::REASONS[$reason] ?? $reason,
            ));
        }

        return new MediaRefused(sprintf(
            'Refusing [%s] as public: %s, so the GPS data it may carry cannot be removed with certainty. It can be '
            .'stored private, where it is served only to those who may view it, or saved again without its location '
            .'and uploaded.',
            $originalName,
            self::REASONS[$reason] ?? $reason,
        ));
    }
}
