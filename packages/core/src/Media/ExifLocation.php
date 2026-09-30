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
 * The GPS data in one EXIF block — a TIFF structure — as the edits that remove it (ADR-042 decision 30).
 *
 * @internal
 *
 * @phpstan-type Edit array{0: int, 1: string, 2: bool}
 *
 * An edit is where it starts, the bytes that replace as many, and whether they are zeros.
 *
 * ⚠️ OVERWRITTEN WHERE IT STANDS, NEVER CUT OUT. A maker note counts its offsets from the TIFF header, and so do the
 * thumbnail and every value too long for its entry: removing a single byte would move them all. So the GPS pointer's
 * entry (0x8825) is taken out of each directory holding it — the entries after it move up by twelve bytes inside the
 * same table, the count drops, and the twelve bytes left at its end are zeros — and the GPS directory and every value it
 * names are overwritten with zeros. The block keeps its length, and every other offset points where it did.
 *
 * ⚠️ ORIENTATION KEEPS ITS PLACE. It is tag 0x0112, and entries are sorted by tag, so it comes before the pointer and
 * never moves — and a browser reads it from the first EXIF block, which is never retyped or emptied.
 *
 * ⚠️ REFUSED RATHER THAN GUESSED where zeroing could damage what is kept: a directory that runs past the block, with a
 * GPS pointer in the block; GPS bytes another entry also points into; or an entry that would move over bytes something
 * else points at. A block no reader can read — a byte order that is neither `II` nor `MM` — is left as it is, since
 * nothing reads the GPS in it either. The magic number after it is not asked: ExifTool reads TIFF under several, and a
 * block whose 42 is damaged is still one a search can walk.
 *
 * Works on a string, relative to it, so a PNG's `eXIf` or a WebP's `EXIF` can use it as it stands.
 */
final class ExifLocation
{
    /** The GPS directory's pointer. */
    public const GPS_POINTER = 0x8825;

    /** Readers stop following a chain long before this; no camera writes more than a handful. */
    public const MAX_DIRECTORIES = 32;

    /** Each TIFF type's size in bytes; a type not here is one readers skip. */
    private const SIZES = [1 => 1, 2 => 1, 3 => 2, 4 => 4, 5 => 8, 6 => 1, 7 => 1, 8 => 2, 9 => 4, 10 => 8, 11 => 4, 12 => 8, 13 => 4, 129 => 1];

    /**
     * @return list<Edit>
     *
     * @throws LocationUnremovable
     */
    public static function edits(string $tiff, LocationBudget $budget): array
    {
        $length = strlen($tiff);
        $order = substr($tiff, 0, 2);

        if ($length < 8 || ($order !== 'II' && $order !== 'MM')) {
            return [];
        }

        $little = $order === 'II';
        $u16 = static fn (int $at): int => self::unsigned($tiff, $at, 2, $little);
        $u32 = static fn (int $at): int => self::unsigned($tiff, $at, 4, $little);

        /*
         * ⚠️ EACH DIRECTORY QUEUED ONCE, AND PAID FOR AS IT IS QUEUED. A SubIFD entry names up to 32 directories, and a
         * block can hold thousands of such entries naming the same few: queued and drained as they came, that is
         * quadratic, and nothing refuses it.
         */
        $queue = [];
        $queued = [];
        $enqueue = static function (int $directory) use (&$queue, &$queued, $length, $budget): void {
            if ($directory < 8 || $directory + 2 > $length || isset($queued[$directory])) {
                return;
            }

            $queued[$directory] = true;
            $budget->spend('entries');
            $queue[] = $directory;
        };
        $enqueue($u32(4));
        $next = 0;
        $seen = [];
        /** @var array<int, array{count: int, remove: list<int>}> $tables */
        $tables = [];
        /** @var list<array{0: int, 1: int}> $claims every byte range something other than GPS points at */
        $claims = [[0, 8]];
        $gps = [];
        $xmp = [];
        $damaged = false;

        while ($next < count($queue)) {
            $at = $queue[$next++];

            if (count($seen) >= self::MAX_DIRECTORIES) {
                throw new LocationUnremovable(LocationUnremovable::EXIF_DAMAGED);
            }

            $seen[$at] = true;
            $count = $u16($at);
            $budget->spend('entries', $count);

            $end = $at + 2 + 12 * $count + 4;
            $fits = $end <= $length;
            $damaged = $damaged || ! $fits;
            $read = $fits ? $count : intdiv($length - $at - 2, 12);
            $tables[$at] = ['count' => $count, 'remove' => []];
            $claims[] = [$at, min($end, $length)];
            $thumbnailAt = null;
            $thumbnailLength = null;

            for ($k = 0; $k < $read; $k++) {
                $entry = $at + 2 + 12 * $k;
                $tag = $u16($entry);
                $type = $u16($entry + 2);
                $values = $u32($entry + 4);
                $size = self::SIZES[$type] ?? 0;
                $bytes = $values * $size;
                $pointer = match (true) {
                    $values === 1 && ($type === 4 || $type === 13) => $u32($entry + 8),
                    $values === 1 && $type === 3 => $u16($entry + 8),
                    default => null,
                };

                if ($tag === self::GPS_POINTER) {
                    // Never a claim: the pointer goes, and what it names — as any reader reads it — is what is zeroed.
                    $tables[$at]['remove'][] = $k;

                    foreach (self::pointers($tiff, $entry, $type, $values, $size, $little) as $directory) {
                        $gps[$directory] = true;
                    }

                    continue;
                }

                $value = $size > 0 && $bytes > 4 ? $u32($entry + 8) : null;

                if ($value !== null && ($range = self::within($value, $bytes, $length)) !== null) {
                    $claims[] = $range;
                }

                // The EXIF and interoperability directories, and SubIFDs: a GPS pointer can sit in any of them.
                if ($tag === 0x8769 || $tag === 0xA005 || ($tag === 0x014A && $values <= 1)) {
                    foreach (self::pointers($tiff, $entry, $type, $values, $size, $little) as $directory) {
                        $enqueue($directory);
                    }
                }

                if ($tag === 0x014A && $values > 1 && $value !== null) {
                    for ($j = 0; $j < min($values, self::MAX_DIRECTORIES) && $value + 4 * $j + 4 <= $length; $j++) {
                        $enqueue($u32($value + 4 * $j));
                    }
                }

                // XMP kept inside EXIF (0x02BC) is a copy of the packet, and loses its GPS as the packet does.
                if ($tag === 0x02BC && $size === 1 && $value !== null && ($range = self::within($value, $bytes, $length)) !== null) {
                    $xmp[] = $range;
                }

                if ($tag === 0x0201) {
                    $thumbnailAt = $pointer;
                }

                if ($tag === 0x0202) {
                    $thumbnailLength = $pointer;
                }
            }

            if ($thumbnailAt !== null && $thumbnailLength !== null && ($range = self::within($thumbnailAt, $thumbnailLength, $length)) !== null) {
                $claims[] = $range;
            }

            if ($fits) {
                $enqueue($u32($at + 2 + 12 * $count));
            }
        }

        $edits = [];

        foreach ($xmp as [$from, $to]) {
            foreach (XmpLocation::edits(substr($tiff, $from, $to - $from)) as [$offset, $replacement, $zero]) {
                $edits[] = [$from + $offset, $replacement, $zero];
            }
        }

        $removing = array_filter($tables, static fn (array $table): bool => $table['remove'] !== []);

        if ($removing === []) {
            return $edits;
        }

        if ($damaged) {
            throw new LocationUnremovable(LocationUnremovable::EXIF_DAMAGED);
        }

        $regions = [];

        foreach (array_keys($gps) as $directory) {
            // A pointer to nowhere is simply removed: there is nothing there to zero.
            if ($directory < 8 || $directory + 2 > $length) {
                continue;
            }

            $count = $u16($directory);
            $budget->spend('entries', $count);
            $regions[] = [$directory, min($length, $directory + 2 + 12 * $count + 4)];

            for ($k = 0; $k < $count && $directory + 2 + 12 * $k + 12 <= $length; $k++) {
                $entry = $directory + 2 + 12 * $k;
                $size = self::SIZES[$u16($entry + 2)] ?? 0;
                $bytes = $u32($entry + 4) * $size;

                if ($size > 0 && $bytes > 4 && ($range = self::within($u32($entry + 8), $bytes, $length)) !== null) {
                    $regions[] = $range;
                }
            }
        }

        foreach ($regions as $region) {
            foreach ($claims as $claim) {
                if (self::overlaps($region, $claim)) {
                    throw new LocationUnremovable(LocationUnremovable::GPS_SHARED);
                }
            }
        }

        foreach ($removing as $at => $table) {
            $count = $table['count'];
            $whole = [$at, $at + 2 + 12 * $count + 4];
            // What moves: from the first entry removed to the end of the next-directory pointer.
            $moving = [$at + 2 + 12 * min($table['remove']), $whole[1]];

            foreach ($claims as $claim) {
                if ($claim !== $whole && self::overlaps($moving, $claim)) {
                    throw new LocationUnremovable(LocationUnremovable::GPS_SHARED);
                }
            }

            $kept = '';

            for ($k = 0; $k < $count; $k++) {
                if (! in_array($k, $table['remove'], true)) {
                    $kept .= substr($tiff, $at + 2 + 12 * $k, 12);
                }
            }

            $removed = count($table['remove']);
            $edits[] = [
                $at,
                pack($little ? 'v' : 'n', $count - $removed).$kept.substr($tiff, $at + 2 + 12 * $count, 4).str_repeat("\0", 12 * $removed),
                false,
            ];
        }

        foreach (self::merge($regions) as [$from, $to]) {
            $edits[] = [$from, str_repeat("\0", $to - $from), true];
        }

        return $edits;
    }

    /**
     * Every directory a reader could take a pointer entry to name.
     *
     * ⚠️ AS THE MOST FORGIVING READER READS IT, NOT AS TIFF SAYS IT SHOULD BE WRITTEN. PHP's reads four bytes whatever the
     * entry's type — in the entry for a value of four bytes or fewer, at its offset for a longer one — and ExifTool reads
     * a single integer of the entry's own width. An EXIF directory typed SLONG, a GPS pointer typed UNDEFINED: each
     * leads a reader to the directory, and each is followed here.
     *
     * @return list<int>
     */
    private static function pointers(string $tiff, int $entry, int $type, int $values, int $size, bool $little): array
    {
        $length = strlen($tiff);
        $found = [];

        if ($values * max($size, 1) <= 4) {
            $found[] = self::unsigned($tiff, $entry + 8, 4, $little);

            if ($values === 1 && ($type === 3 || $type === 8)) {
                $found[] = self::unsigned($tiff, $entry + 8, 2, $little);
            }

            if ($values === 1 && ($type === 1 || $type === 6)) {
                $found[] = ord($tiff[$entry + 8]);
            }
        } elseif (($at = self::unsigned($tiff, $entry + 8, 4, $little)) + 4 <= $length) {
            $found[] = self::unsigned($tiff, $at, 4, $little);
        }

        return array_values(array_unique(array_filter($found, static fn (int $at): bool => $at >= 8 && $at + 2 <= $length)));
    }

    /**
     * Ranges sorted and joined where they touch or overlap.
     *
     * @param  list<array{0: int, 1: int}>  $ranges
     * @return list<array{0: int, 1: int}>
     */
    public static function merge(array $ranges): array
    {
        usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];

        foreach ($ranges as $range) {
            $last = count($merged) - 1;

            if ($last >= 0 && $range[0] <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $range[1]);
            } else {
                $merged[] = $range;
            }
        }

        return $merged;
    }

    /**
     * `[$at, $at + $bytes)` clamped to the block past its header, or null where nothing of it is inside.
     *
     * @return array{0: int, 1: int}|null
     */
    private static function within(int $at, int $bytes, int $length): ?array
    {
        $from = max(8, $at);
        $to = min($length, $at + $bytes);

        return $from < $to ? [$from, $to] : null;
    }

    /**
     * @param  array{0: int, 1: int}  $a
     * @param  array{0: int, 1: int}  $b
     */
    private static function overlaps(array $a, array $b): bool
    {
        return $a[0] < $b[1] && $b[0] < $a[1];
    }

    /** @throws RuntimeException for a read the walk's own bounds should have prevented */
    private static function unsigned(string $tiff, int $at, int $width, bool $little): int
    {
        $bytes = $at >= 0 ? substr($tiff, $at, $width) : '';

        if (strlen($bytes) !== $width) {
            throw new RuntimeException("An EXIF read at {$at} fell outside its block.");
        }

        return (int) unpack($width === 2 ? ($little ? 'v' : 'n') : ($little ? 'V' : 'N'), $bytes)[1];
    }
}
