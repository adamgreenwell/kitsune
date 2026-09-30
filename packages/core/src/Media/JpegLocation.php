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
 * A JPEG without the GPS data in its EXIF and XMP — ADR-042 decision 30, for `MediaLocation`.
 *
 * @internal
 *
 * @phpstan-import-type Edit from ExifLocation
 *
 * @phpstan-type Range array{0: int, 1: int}
 * @phpstan-type Segment array{marker: int, at: int, payload: int, end: int}
 * @phpstan-type Image array{end: int, complete: bool, mapped: int, segments: list<Segment>, editable: list<Range>}
 * @phpstan-type Plan array{edits: list<Edit>, editable: list<Range>}
 *
 * A segment's `at` is its marker's own `FF`, past any fill before it.
 *
 * ⚠️ OVERWRITTEN IN PLACE, BYTE FOR BYTE, AND NEVER RE-ENCODED (Adam, decision 30). Every edit replaces as many bytes as
 * it removes, so the file keeps its length: every offset in it — EXIF's, a maker note's, the thumbnail's, the
 * Multi-Picture index that finds a gain map or a stereo pair's other frame — still points where it did, without any of
 * them being read. The picture's tables and scans are never written, so its pixels are the ones uploaded.
 *
 * ⚠️ FOUND WHERE A READER FINDS IT. The file is walked as a decoder walks it — stray bytes and fill skipped, stuffing and
 * restart markers read as scan data — and so is each JPEG after its end: a gain map, a stereo frame, an image appended by
 * a phone, each with blocks of its own. EXIF is recognised by ExifTool's own rule, including a block split over several
 * segments and a block in capitals; XMP by the standard header and by ExifTool's fallback; Photoshop's copies of both
 * inside its APP13; and a block nested inside another segment — the EXIF thumbnail's own, a preview's — by looking inside
 * each application segment, and inside the bytes after the last image, for another.
 *
 * ⚠️ EDITED ONLY WHERE METADATA IS. The segments that change how a picture is drawn — the colour profile, the
 * Multi-Picture index, ISO gain-map metadata, Adobe's and JFIF's — are never written, however their bytes read, and a
 * check before any byte is written holds every edit inside the application segment, or the trailing bytes, that it was
 * found in. A block that runs across the segment holding it is refused rather than written across the next header.
 *
 * ⚠️ AND CHECKED AFTERWARDS. The copy is planned again before it is handed over; a copy that would still change is a
 * fault, reported, and never stored.
 */
final class JpegLocation
{
    public const TEMPORARY_PREFIX = 'kitsune-jpeg-';

    /** ExifTool's rule: up to four stray bytes, then `Exif\0` in any case, and something after it. */
    private const EXIF = '/^(.{0,4})Exif\0./is';

    private const XMP = "http://ns.adobe.com/xap/1.0/\0";

    private const EXTENDED = "http://ns.adobe.com/xmp/extension/\0";

    /** The header, GUID, full length and offset before each extended chunk's data. */
    private const EXTENDED_HEADER = 75;

    private const PHOTOSHOP = "Photoshop 3.0\0";

    /** Continuation segments joined to one EXIF or Photoshop block before it is refused as too many. */
    private const MAX_CONTINUATIONS = 16;

    /** Places after the picture where another JPEG is looked for before the rest is taken as trailing bytes. */
    private const MAX_TRAILER_TRIES = 1024;

    /** How much of one extended chunk's end is carried into the next, so a name split across two is still read. */
    private const CARRY = 255;

    /**
     * What a file stored public would have edited — read-only.
     *
     * @return Plan
     *
     * @throws LocationUnremovable
     * @throws RuntimeException
     */
    public static function plan(string $path, ?LocationBudget $budget = null): array
    {
        $bytes = JpegBytes::ofFile($path);

        try {
            return self::planOf($bytes, $budget ?? new LocationBudget);
        } finally {
            $bytes->close();
        }
    }

    /**
     * The same, for bytes in memory — for the tests.
     *
     * @return Plan
     *
     * @throws LocationUnremovable
     */
    public static function planBytes(string $bytes, ?LocationBudget $budget = null): array
    {
        return self::planOf(JpegBytes::ofString($bytes), $budget ?? new LocationBudget);
    }

    /**
     * A copy of the file without its location, or null where there is none to remove and the file is used as it is.
     *
     * @throws LocationUnremovable before any temporary exists
     * @throws RuntimeException
     */
    public static function strippedCopy(string $path): ?string
    {
        $plan = self::plan($path);

        return $plan['edits'] === [] ? null : self::audited($path, $plan);
    }

    /**
     * The plan applied to a copy, which is then planned again and must have nothing left to change.
     *
     * @param  Plan  $plan
     *
     * @throws RuntimeException
     */
    public static function audited(string $path, array $plan): string
    {
        $copy = self::copyWith($path, $plan);

        try {
            $again = self::plan($copy);
        } catch (LocationUnremovable) {
            $again = null;
        } catch (Throwable $e) {
            @unlink($copy);

            throw $e;
        }

        if ($again === null || $again['edits'] !== []) {
            @unlink($copy);

            throw new RuntimeException('The copy of a JPEG stripped of its location failed its own check.');
        }

        return $copy;
    }

    /**
     * The plan applied to a temporary copy of the file, which the caller owns. The file itself is never written.
     *
     * ⚠️ VALIDATED BEFORE THE TEMPORARY EXISTS: edits in order, apart, inside the file, as long as what they replace,
     * and each inside one editable range. Anything else is a fault in the plan, never a file to refuse.
     *
     * ⚠️ `tempnam()` IN THE SYSTEM TEMP DIRECTORY, NEVER ON A DISK, for `MediaLibrary::sanitisedCopy()`'s reason: a copy
     * written under `media/` before its row is a file prune would report as an orphan.
     *
     * @param  Plan  $plan
     *
     * @throws RuntimeException
     */
    public static function copyWith(string $path, array $plan): string
    {
        clearstatcache(true, $path);
        $size = @filesize($path);

        if ($size === false) {
            throw new RuntimeException("Cannot strip media: [{$path}] could not be read.");
        }

        $previous = -1;

        foreach ($plan['edits'] as [$at, $replacement]) {
            $length = strlen($replacement);

            // In order and apart — and, since `$previous` starts at -1, never before the file's first byte.
            if ($at <= $previous || $length < 1 || $at + $length > $size || ! self::inside([$at, $at + $length], $plan['editable'])) {
                throw new RuntimeException('A plan to strip a JPEG of its location was inconsistent at '.$at.'.');
            }

            $previous = $at + $length - 1;
        }

        $copy = tempnam(sys_get_temp_dir(), self::TEMPORARY_PREFIX);

        if ($copy === false) {
            throw new RuntimeException('Cannot strip media: no temporary file could be made.');
        }

        try {
            if (! @copy($path, $copy)) {
                throw new RuntimeException("Cannot strip media: [{$path}] could not be copied.");
            }

            $handle = @fopen($copy, 'r+b');

            if ($handle === false) {
                throw new RuntimeException('Cannot strip media: its copy could not be opened.');
            }

            try {
                foreach ($plan['edits'] as [$at, $replacement]) {
                    if (fseek($handle, $at) !== 0 || fwrite($handle, $replacement) !== strlen($replacement)) {
                        throw new RuntimeException('Cannot strip media: its copy could not be written.');
                    }
                }

                fflush($handle);
            } finally {
                fclose($handle);
            }

            clearstatcache(true, $copy);
        } catch (Throwable $e) {
            @unlink($copy);

            throw $e;
        }

        return $copy;
    }

    /**
     * @return Plan
     *
     * @throws LocationUnremovable
     */
    private static function planOf(JpegBytes $bytes, LocationBudget $budget): array
    {
        $size = $bytes->size();
        $primary = self::walk($bytes, 0, $size, false, $budget);

        if ($primary === null) {
            throw new LocationUnremovable(LocationUnremovable::NOT_JPEG);
        }

        $images = [$primary];
        $unmapped = [];
        $cursor = $primary['mapped'];

        // The JPEGs after the picture's end, each walked strictly: bytes that only look like one are trailing bytes.
        if ($primary['complete']) {
            $from = $primary['end'];
            $tries = 0;

            while ($from < $size && $tries++ < self::MAX_TRAILER_TRIES && ($at = $bytes->find("\xFF\xD8\xFF", $from, $size)) !== null) {
                $image = self::walk($bytes, $at, $size, true, $budget);

                if ($image === null) {
                    $from = $at + 1;

                    continue;
                }

                $budget->spend('images');

                if ($at > $cursor) {
                    $unmapped[] = [$cursor, $at];
                }

                $images[] = $image;
                $cursor = $from = $image['end'];
            }
        }

        if ($cursor < $size) {
            $unmapped[] = [$cursor, $size];
        }

        $editable = $unmapped;
        $edits = [];

        foreach ($images as $image) {
            array_push($editable, ...$image['editable']);
            array_push($edits, ...self::blocks($bytes, $image, $budget));
        }

        array_push($edits, ...self::nested($bytes, $editable, $budget));
        self::refuseOutOfPlace($bytes, $images, $editable, $budget);

        return ['edits' => self::settle($bytes, $edits, $editable), 'editable' => $editable];
    }

    /**
     * Every block that reads as EXIF or XMP anywhere in the file, which neither the segments nor the editable ranges
     * account for, refused where it carries location.
     *
     * ⚠️ FAIL CLOSED WHERE NO EDIT MAY GO. A block inside a comment, a colour profile, a segment no reader follows or
     * one a damaged marker hides is not metadata any decoder reads — and it is still bytes served to anyone with the
     * link, which a search finds. It cannot be edited without writing what the picture is drawn from, so a file
     * holding one with location is refused as public rather than stored with it.
     *
     * @param  list<Image>  $images
     * @param  list<Range>  $editable
     *
     * @throws LocationUnremovable
     */
    private static function refuseOutOfPlace(JpegBytes $bytes, array $images, array $editable, LocationBudget $budget): void
    {
        $segments = [];

        foreach ($images as $image) {
            foreach ($image['segments'] as $segment) {
                $segments[$segment['at']] = true;
            }
        }

        $size = $bytes->size();
        $cursor = 0;

        while (($at = $bytes->find("\xFF\xE1", $cursor, $size)) !== null) {
            $cursor = $at + 1;

            if (isset($segments[$at]) || self::inside([$at, $at + 2], $editable) || $at + 4 > $size) {
                continue;
            }

            $length = (int) unpack('n', $bytes->read($at + 2, 2))[1];
            $payload = $bytes->read($at + 4, $length < 2 ? 0xFFFF : $length - 2);
            $exif = XmpLocation::matches(self::EXIF, $payload, $match) === 1;

            if (! $exif && ! str_starts_with($payload, 'http') && ! str_starts_with($payload, "XMP\0")) {
                continue;
            }

            $budget->spend('blocks');
            $offset = str_starts_with($payload, self::XMP) ? strlen(self::XMP) : 0;
            $found = $exif
                ? ExifLocation::edits(substr($payload, strlen($match[1]) + 6), $budget)
                : XmpLocation::edits(substr($payload, $offset));

            if ($found !== []) {
                throw new LocationUnremovable(LocationUnremovable::OUT_OF_PLACE);
            }
        }
    }

    /**
     * One JPEG's segments, from its SOI to its EOI.
     *
     * Lenient for the picture, as libjpeg and ExifTool are: stray bytes and fill are skipped, a segment running past the
     * end is read to the end, and a length that cannot be one stops the map there, leaving the rest as trailing bytes.
     * Strict for a JPEG after it — any of that, and it is not one.
     *
     * @return Image|null
     *
     * @throws LocationUnremovable
     */
    private static function walk(JpegBytes $bytes, int $start, int $limit, bool $strict, LocationBudget $budget): ?array
    {
        if ($bytes->read($start, 2) !== "\xFF\xD8" || ($strict && $bytes->byte($start + 2) !== 0xFF)) {
            return null;
        }

        $at = $start + 2;
        $scanning = false;
        $segments = [];
        $editable = [];
        $end = $limit;
        $complete = true;
        $mapped = null;

        while (true) {
            if ($at >= $limit) {
                if ($strict && ! $scanning) {
                    return null;
                }

                break;
            }

            if ($bytes->byte($at) !== 0xFF) {
                // Scan data, or stray bytes between segments, which a decoder skips.
                if ($strict && ! $scanning) {
                    return null;
                }

                $next = $bytes->find("\xFF", $at, $limit);

                if ($next === null) {
                    break;
                }

                $at = $next;

                continue;
            }

            $marker = $at + 1;

            // Fill bytes (T.81 B.1.1.2).
            while ($marker < $limit && $bytes->byte($marker) === 0xFF) {
                $marker++;
            }

            if ($marker >= $limit) {
                if ($strict && ! $scanning) {
                    return null;
                }

                break;
            }

            $code = $bytes->byte($marker);

            // Stuffing and restart markers are scan data.
            if ($code === 0x00 || ($code >= 0xD0 && $code <= 0xD7)) {
                if ($strict && ! $scanning) {
                    return null;
                }

                $at = $marker + 1;

                continue;
            }

            if ($code === 0xD9) {
                $end = $marker + 1;

                break;
            }

            if ($code === 0x01 || $code === 0xD8) {
                if ($strict && $code === 0xD8) {
                    return null;
                }

                $at = $marker + 1;

                continue;
            }

            $scanning = false;
            $budget->spend('segments');
            $length = $marker + 3 <= $limit ? (int) unpack('n', $bytes->read($marker + 1, 2))[1] : 0;

            if ($length < 2) {
                if ($strict) {
                    return null;
                }

                $complete = false;
                $mapped = $at;

                break;
            }

            $segmentEnd = $marker + 1 + $length;

            if ($segmentEnd > $limit) {
                if ($strict) {
                    return null;
                }

                $segmentEnd = $limit;
            }

            $payload = $marker + 3;
            $segments[] = ['marker' => $code, 'at' => $marker - 1, 'payload' => $payload, 'end' => $segmentEnd];

            if ($code >= 0xE0 && $code <= 0xEF && ! self::drawsThePicture($code, $bytes->read($payload, 12))) {
                $editable[] = [$payload, $segmentEnd];
            }

            $at = $segmentEnd;
            $scanning = $code === 0xDA;
        }

        return ['end' => $end, 'complete' => $complete, 'mapped' => $mapped ?? $end, 'segments' => $segments, 'editable' => $editable];
    }

    /** The application segments that change how the picture is drawn, which are never written. */
    private static function drawsThePicture(int $code, string $prefix): bool
    {
        return match ($code) {
            0xE0 => str_starts_with($prefix, "JFIF\0"),
            0xE2 => str_starts_with($prefix, "ICC_PROFILE\0") || str_starts_with($prefix, "MPF\0") || str_starts_with($prefix, 'urn:'),
            0xEE => str_starts_with($prefix, 'Adobe'),
            default => false,
        };
    }

    /**
     * The EXIF, XMP, extended-XMP and Photoshop blocks among one JPEG's own segments, as edits.
     *
     * @param  Image  $image
     * @return list<Edit>
     *
     * @throws LocationUnremovable
     */
    private static function blocks(JpegBytes $bytes, array $image, LocationBudget $budget): array
    {
        $segments = $image['segments'];
        $edits = [];
        /** @var array<string, list<array{0: int, 1: int, 2: int}>> $extended declared offset, payload, end — by GUID */
        $extended = [];
        $count = count($segments);

        for ($i = 0; $i < $count; $i++) {
            $segment = $segments[$i];

            if (($segment['marker'] !== 0xE1 && $segment['marker'] !== 0xED) || $segment['end'] <= $segment['payload']) {
                continue;
            }

            $payload = $bytes->read($segment['payload'], $segment['end'] - $segment['payload']);

            if ($segment['marker'] === 0xED) {
                if (! str_starts_with($payload, self::PHOTOSHOP)) {
                    continue;
                }

                $budget->spend('blocks');
                $slices = [[$segment['payload'], $segment['end']]];

                while (isset($segments[$i + 1]) && $segments[$i + 1]['marker'] === 0xED
                    && $bytes->read($segments[$i + 1]['payload'], strlen(self::PHOTOSHOP)) === self::PHOTOSHOP) {
                    self::continuing($slices);
                    $slices[] = [$segments[$i + 1]['payload'] + strlen(self::PHOTOSHOP), $segments[$i + 1]['end']];
                    $i++;
                }

                array_push($edits, ...self::logical($bytes, $slices, static fn (string $block): array => self::photoshop($block, $budget)));

                continue;
            }

            if (XmpLocation::matches(self::EXIF, $payload, $match) === 1) {
                $budget->spend('blocks');
                $slices = [[$segment['payload'] + strlen($match[1]) + 6, $segment['end']]];

                // A block too long for one segment continues in the next, which says `Exif\0\0` and no TIFF header.
                while (isset($segments[$i + 1]) && $segments[$i + 1]['marker'] === 0xE1) {
                    $head = $bytes->read($segments[$i + 1]['payload'], 10);

                    if (! str_starts_with($head, "Exif\0\0") || in_array(substr($head, 6, 4), ["MM\0*", "II*\0"], true)) {
                        break;
                    }

                    self::continuing($slices);
                    $slices[] = [$segments[$i + 1]['payload'] + 6, $segments[$i + 1]['end']];
                    $i++;
                }

                array_push($edits, ...self::logical($bytes, $slices, static fn (string $tiff): array => ExifLocation::edits($tiff, $budget)));

                continue;
            }

            if (str_starts_with($payload, self::EXTENDED)) {
                if (strlen($payload) >= self::EXTENDED_HEADER) {
                    $extended[substr($payload, 35, 32)][] = [(int) unpack('N', substr($payload, 71, 4))[1], $segment['payload'], $segment['end']];
                }

                continue;
            }

            // Not XMP though they may hold text that reads like it: a camera's own records.
            if (str_starts_with($payload, 'QVCI') || str_starts_with($payload, "FLIR\0") || str_starts_with($payload, "PARROT\0")) {
                continue;
            }

            if (str_starts_with($payload, 'http') || str_starts_with($payload, "XMP\0") || XmpLocation::matches('/<(?:exif:|\?xpacket)/', $payload) === 1) {
                $budget->spend('blocks');
                $offset = str_starts_with($payload, self::XMP) ? strlen(self::XMP) : 0;

                foreach (XmpLocation::edits(substr($payload, $offset)) as [$at, $replacement, $zero]) {
                    $edits[] = [$segment['payload'] + $offset + $at, $replacement, $zero];
                }
            }
        }

        foreach ($extended as $chunks) {
            $budget->spend('blocks');
            array_push($edits, ...self::extended($bytes, $chunks));
        }

        return $edits;
    }

    /**
     * An extended XMP group, zeroed whole — its identifier with it — where it names location or embeds an image.
     *
     * ⚠️ ZEROED, NOT BLANKED, AND NOT REFUSED. Its chunks are one packet identified by the MD5 of that packet, so no
     * edit inside it leaves it valid; it is where a phone keeps a depth map or the picture before an edit, and never
     * what the picture is drawn from. An image in it is taken as carrying location rather than decoded and asked.
     *
     * @param  list<array{0: int, 1: int, 2: int}>  $chunks
     * @return list<Edit>
     */
    private static function extended(JpegBytes $bytes, array $chunks): array
    {
        usort($chunks, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $carry = '';
        $names = false;

        foreach ($chunks as [, $payload, $end]) {
            $text = $carry.str_replace("\0", '', $bytes->read($payload + self::EXTENDED_HEADER, $end - $payload - self::EXTENDED_HEADER));

            if (XmpLocation::matches('/[<:][A-Za-z0-9_.\-]*(?:gps|latitude|longitude|longtitude)|[>"\'\s]\/9j\//i', $text) === 1
                || str_contains($text, 'www.dji.com/drone-dji') || str_contains($text, 'developer.sonyericsson.com/cell')) {
                $names = true;

                break;
            }

            $carry = substr($text, -self::CARRY);
        }

        if (! $names) {
            return [];
        }

        return array_map(static fn (array $chunk): array => [$chunk[1], str_repeat("\0", $chunk[2] - $chunk[1]), true], $chunks);
    }

    /**
     * Photoshop's resources in its APP13, of which two are copies: EXIF (0x0422) and XMP (0x0424).
     *
     * ⚠️ IPTC (0x0404) STAYS. It holds place names — a city, a country — and never coordinates.
     *
     * @return list<Edit>
     *
     * @throws LocationUnremovable
     */
    private static function photoshop(string $block, LocationBudget $budget): array
    {
        $edits = [];
        $at = strlen(self::PHOTOSHOP);
        $length = strlen($block);

        while ($at + 12 <= $length && in_array(substr($block, $at, 4), ['8BIM', 'PHUT', 'AgHg', 'DCSR', 'MeSa'], true)) {
            $id = (int) unpack('n', substr($block, $at + 4, 2))[1];
            $name = 1 + ord($block[$at + 6]);
            $sizeAt = $at + 6 + $name + ($name % 2);

            if ($sizeAt + 4 > $length) {
                break;
            }

            $size = (int) unpack('N', substr($block, $sizeAt, 4))[1];
            $data = $sizeAt + 4;

            if ($data + $size > $length) {
                break;
            }

            $found = match ($id) {
                0x0422 => ExifLocation::edits(substr($block, $data, $size), $budget),
                0x0424 => XmpLocation::edits(substr($block, $data, $size)),
                default => [],
            };

            foreach ($found as [$offset, $replacement, $zero]) {
                $edits[] = [$data + $offset, $replacement, $zero];
            }

            $at = $data + $size + ($size % 2);
        }

        return $edits;
    }

    /**
     * EXIF and XMP blocks nested in an editable range — inside another segment, or in the bytes after the last image.
     *
     * @param  list<Range>  $editable
     * @return list<Edit>
     *
     * @throws LocationUnremovable
     */
    private static function nested(JpegBytes $bytes, array $editable, LocationBudget $budget): array
    {
        $edits = [];
        $size = $bytes->size();

        foreach ($editable as [$from, $to]) {
            $cursor = $from;

            while (($at = $bytes->find("\xFF\xE1", $cursor, $to)) !== null) {
                $cursor = $at + 1;

                if ($at + 4 > $size) {
                    break;
                }

                $length = (int) unpack('n', $bytes->read($at + 2, 2))[1];
                // A length that cannot be one is not a reason to look away: a search reads what follows `Exif\0` anyway.
                $end = $length < 2 ? $to : min($size, $at + 2 + $length);
                $payload = $bytes->read($at + 4, $end - $at - 4);
                $exif = XmpLocation::matches(self::EXIF, $payload, $match) === 1;

                if (! $exif && ! str_starts_with($payload, 'http') && ! str_starts_with($payload, "XMP\0")) {
                    continue;
                }

                $budget->spend('blocks');

                if ($end > $to) {
                    // Across the segment holding it: its end cannot be edited without writing the next header.
                    if ($exif || XmpLocation::matches(XmpLocation::MENTIONS, str_replace("\0", '', $payload)) === 1) {
                        throw new LocationUnremovable(LocationUnremovable::BLOCKS_OVERLAP);
                    }

                    continue;
                }

                if ($exif) {
                    $tiff = $at + 4 + strlen($match[1]) + 6;

                    foreach (ExifLocation::edits(substr($payload, $tiff - $at - 4), $budget) as [$offset, $replacement, $zero]) {
                        $edits[] = [$tiff + $offset, $replacement, $zero];
                    }

                    continue;
                }

                $offset = str_starts_with($payload, self::XMP) ? strlen(self::XMP) : 0;

                foreach (XmpLocation::edits(substr($payload, $offset)) as [$within, $replacement, $zero]) {
                    $edits[] = [$at + 4 + $offset + $within, $replacement, $zero];
                }
            }
        }

        return $edits;
    }

    /**
     * Edits found in a block joined from several segments, cut back into each segment's own bytes — so a header or an
     * identifier between them is never written.
     *
     * @param  list<Range>  $slices
     * @param  callable(string): list<Edit>  $find
     * @return list<Edit>
     */
    private static function logical(JpegBytes $bytes, array $slices, callable $find): array
    {
        $block = '';
        $map = [];

        foreach ($slices as [$from, $to]) {
            $map[] = [strlen($block), $from, $to - $from];
            $block .= $bytes->read($from, $to - $from);
        }

        $edits = [];

        foreach ($find($block) as [$at, $replacement, $zero]) {
            foreach ($map as [$logical, $physical, $length]) {
                $from = max($at, $logical);
                $to = min($at + strlen($replacement), $logical + $length);

                if ($from < $to) {
                    $edits[] = [$physical + $from - $logical, substr($replacement, $from - $at, $to - $from), $zero];
                }
            }
        }

        return $edits;
    }

    /**
     * @param  list<Range>  $slices
     *
     * @throws LocationUnremovable past `MAX_CONTINUATIONS`
     */
    private static function continuing(array $slices): void
    {
        if (count($slices) > self::MAX_CONTINUATIONS) {
            throw new LocationUnremovable(LocationUnremovable::TOO_MANY);
        }
    }

    /**
     * The edits that change something, in order: one inside a range already zeroed goes with it, and any other overlap
     * is refused. Each must lie inside one editable range, or the plan is a fault.
     *
     * @param  list<Edit>  $edits
     * @param  list<Range>  $editable
     * @return list<Edit>
     *
     * @throws LocationUnremovable
     */
    private static function settle(JpegBytes $bytes, array $edits, array $editable): array
    {
        $changing = array_values(array_filter(
            $edits,
            static fn (array $edit): bool => $bytes->read($edit[0], strlen($edit[1])) !== $edit[1],
        ));

        usort($changing, static fn (array $a, array $b): int => [$a[0], -strlen($a[1])] <=> [$b[0], -strlen($b[1])]);
        $kept = [];

        foreach ($changing as $edit) {
            foreach ($kept as $earlier) {
                if ($earlier[2] && $edit[0] >= $earlier[0] && $edit[0] + strlen($edit[1]) <= $earlier[0] + strlen($earlier[1])) {
                    continue 2;
                }
            }

            $last = $kept === [] ? null : $kept[count($kept) - 1];

            if ($last !== null && $edit[0] < $last[0] + strlen($last[1])) {
                throw new LocationUnremovable(LocationUnremovable::BLOCKS_OVERLAP);
            }

            if (! self::inside([$edit[0], $edit[0] + strlen($edit[1])], $editable)) {
                throw new RuntimeException('An edit to strip a JPEG of its location fell outside the metadata it was for, at '.$edit[0].'.');
            }

            $kept[] = $edit;
        }

        return $kept;
    }

    /**
     * @param  Range  $range
     * @param  list<Range>  $editable
     */
    private static function inside(array $range, array $editable): bool
    {
        foreach ($editable as [$from, $to]) {
            if ($range[0] >= $from && $range[1] <= $to) {
                return true;
            }
        }

        return false;
    }
}
