<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Media\ExifLocation;
use Kitsune\Core\Media\LocationBudget;
use Kitsune\Core\Media\LocationUnremovable;
use Kitsune\Core\Tests\Fixtures\LocatedJpeg as J;

/*
 * The GPS data in one EXIF block — ADR-042 decision 30. Every block is built in both byte orders, and every edit is
 * applied here and the result read back, so what is asserted is the bytes a reader would see.
 */

/** @param  list<array{0: int, 1: string, 2: bool}>  $edits */
function exifApplied(string $tiff, array $edits): string
{
    foreach ($edits as [$at, $bytes]) {
        $tiff = substr_replace($tiff, $bytes, $at, strlen($bytes));
    }

    return $tiff;
}

function exifStripped(string $tiff, ?LocationBudget $budget = null): string
{
    $stripped = exifApplied($tiff, ExifLocation::edits($tiff, $budget ?? new LocationBudget));

    expect(strlen($stripped))->toBe(strlen($tiff));

    return $stripped;
}

/** A block of one IFD0 holding the given entries, and the bytes after it. */
function exifWith(bool $little, array $entries, string $after = '', int $next = 0): string
{
    return J::header($little).J::u16($little, count($entries)).implode('', $entries).J::u32($little, $next).$after;
}

/** A GPS directory at `$at` holding a latitude whose rationals follow it. */
function gpsDirectory(bool $little, int $at): string
{
    return J::u16($little, 1).J::entry($little, 0x0002, 5, 3, J::u32($little, $at + 18)).J::u32($little, 0).str_repeat(J::RATIONAL, 3);
}

dataset('byte orders', ['II' => [true], 'MM' => [false]]);

it('removes the GPS pointer and zeroes its directory and values, every other byte as it was', function (bool $little): void {
    $tiff = J::tiff($little);
    $stripped = exifStripped($tiff);

    expect(substr($stripped, 8, 2))->toBe(J::u16($little, 4))
        ->and(substr($stripped, 10, 36))->toBe(substr($tiff, 10, 36))
        // The XPTitle entry and the next pointer, moved up over the pointer's twelve bytes.
        ->and(substr($stripped, 46, 12))->toBe(substr($tiff, 58, 12))
        ->and(substr($stripped, 58, 4))->toBe(substr($tiff, 70, 4))
        ->and(substr($stripped, 62, 12))->toBe(str_repeat("\0", 12))
        ->and(substr($stripped, 74, 60))->toBe(substr($tiff, 74, 60))
        ->and(substr($stripped, 134, 78))->toBe(str_repeat("\0", 78))
        ->and(substr($stripped, 212, 78))->toBe(substr($tiff, 212, 78))
        ->and(substr($stripped, 290, 72))->toBe(str_repeat("\0", 72))
        // The thumbnail is not this block's to strip: the file's walk finds its own.
        ->and(substr($stripped, 362))->toBe(substr($tiff, 362));
})->with('byte orders');

it('keeps orientation where it was, as a reader finds it', function (bool $little): void {
    $stripped = exifStripped(J::tiff($little));

    expect(substr($stripped, 10, 12))->toBe(substr(J::tiff($little), 10, 12))
        ->and(J::orientationOf(J::jpeg([J::exif($stripped)])))->toBe(6)
        ->and(J::gpsPointers(J::jpeg([J::exif(substr($stripped, 0, 362))])))->toBe(0);
})->with('byte orders');

it('keeps the maker note, its absolute offset still pointing at its own payload', function (bool $little): void {
    $stripped = exifStripped(J::tiff($little));

    expect(substr($stripped, 274, 16))->toBe(substr(J::tiff($little), 274, 16))
        ->and(substr($stripped, 278, 4))->toBe(J::u32($little, 282))
        ->and(substr($stripped, 282, 8))->toBe('PAYLOAD!');
})->with('byte orders');

it('removes a GPS pointer from any directory it is in', function (bool $little, string $where): void {
    $gps = fn (int $at): string => J::entry($little, 0x8825, 4, 1, J::u32($little, $at));

    $tiff = match ($where) {
        // IFD0 → IFD1 (at 26), which holds the pointer to GPS at 44.
        'IFD1' => exifWith($little, [J::entry($little, 0x0112, 3, 1, J::u16($little, 6))], J::u16($little, 1).$gps(44).J::u32($little, 0).gpsDirectory($little, 44), 26),
        // IFD0 → the EXIF directory (at 26), which holds it.
        'the EXIF directory' => exifWith($little, [J::entry($little, 0x8769, 4, 1, J::u32($little, 26))], J::u16($little, 1).$gps(44).J::u32($little, 0).gpsDirectory($little, 44)),
        // IFD0's SubIFDs: two LONGs at 26 naming directories at 34 and 52; the second holds it.
        'a SubIFD' => exifWith($little, [J::entry($little, 0x014A, 4, 2, J::u32($little, 26))],
            J::u32($little, 34).J::u32($little, 52)
            .J::u16($little, 1).J::entry($little, 0x0112, 3, 1, J::u16($little, 1)).J::u32($little, 0)
            .J::u16($little, 1).$gps(70).J::u32($little, 0).gpsDirectory($little, 70)),
    };

    $stripped = exifStripped($tiff);

    expect(J::gpsPointers(J::jpeg([J::exif($stripped)])))->toBe(0)
        ->and(J::gpsPointers(J::jpeg([J::exif($tiff)])))->toBe(1)
        ->and(J::sentinels($stripped))->toBe([]);
})->with('byte orders')->with(['IFD1', 'the EXIF directory', 'a SubIFD']);

it('zeroes a directory two pointers name, once', function (bool $little): void {
    // IFD0 (8..38) names GPS at 56, and so does the EXIF directory at 38.
    $tiff = exifWith($little, [J::entry($little, 0x8769, 4, 1, J::u32($little, 38)), J::entry($little, 0x8825, 4, 1, J::u32($little, 56))],
        J::u16($little, 1).J::entry($little, 0x8825, 4, 1, J::u32($little, 56)).J::u32($little, 0).gpsDirectory($little, 56));

    $edits = ExifLocation::edits($tiff, new LocationBudget);
    $zeroes = array_values(array_filter($edits, fn (array $edit): bool => $edit[2]));

    expect($zeroes)->toHaveCount(1)
        ->and(J::sentinels(exifStripped($tiff)))->toBe([])
        ->and(J::gpsPointers(J::jpeg([J::exif(exifStripped($tiff))])))->toBe(0);
})->with('byte orders');

it('removes a pointer to nowhere and zeroes nothing else', function (bool $little): void {
    $tiff = exifWith($little, [J::entry($little, 0x0112, 3, 1, J::u16($little, 6)), J::entry($little, 0x8825, 4, 1, J::u32($little, 5000))]);
    $edits = ExifLocation::edits($tiff, new LocationBudget);

    expect($edits)->toHaveCount(1)
        ->and($edits[0][0])->toBe(8)
        ->and(substr(exifStripped($tiff), 8, 2))->toBe(J::u16($little, 1));
})->with('byte orders');

it('zeroes the part of a GPS value inside the block, and nothing past it', function (bool $little): void {
    // The latitude's 24 bytes start 8 before the block's end.
    $tiff = exifWith($little, [J::entry($little, 0x8825, 4, 1, J::u32($little, 26))],
        J::u16($little, 1).J::entry($little, 0x0002, 5, 3, J::u32($little, 44)).J::u32($little, 0).J::RATIONAL);

    expect(J::sentinels(exifStripped($tiff)))->toBe([]);
})->with('byte orders');

it('accepts a SHORT pointer', function (bool $little): void {
    $tiff = exifWith($little, [J::entry($little, 0x8825, 3, 1, J::u16($little, 26))], gpsDirectory($little, 26));

    expect(J::sentinels(exifStripped($tiff)))->toBe([])
        ->and(substr(exifStripped($tiff), 8, 2))->toBe(J::u16($little, 0));
})->with('byte orders');

it('leaves a block no reader reads as it is', function (string $tiff): void {
    expect(ExifLocation::edits($tiff, new LocationBudget))->toBe([]);
})->with([
    'a byte order that is neither' => ['XX'.substr(J::gpsOnlyTiff(true), 2)],
    'fewer than eight bytes' => ['II*'],
]);

it('strips a block whatever its magic number, as a reader may walk it anyway', function (bool $little): void {
    $tiff = substr_replace(J::gpsOnlyTiff($little), J::u16($little, 43), 2, 2);

    expect(J::sentinels(exifStripped($tiff)))->toBe([]);
})->with('byte orders');

it('leaves a damaged block with no GPS as it is, and refuses one with GPS', function (bool $little): void {
    // IFD0 claims forty entries and holds one.
    $undamagedEntry = J::entry($little, 0x0112, 3, 1, J::u16($little, 6));
    $withoutGps = J::header($little).J::u16($little, 40).$undamagedEntry;
    $withGps = J::header($little).J::u16($little, 40).J::entry($little, 0x8825, 4, 1, J::u32($little, 30));

    expect(ExifLocation::edits($withoutGps, new LocationBudget))->toBe([])
        ->and(fn () => ExifLocation::edits($withGps, new LocationBudget))
        ->toThrow(fn (LocationUnremovable $e) => expect($e->reason)->toBe(LocationUnremovable::EXIF_DAMAGED));
})->with('byte orders');

it('walks 32 directories and refuses a 33rd', function (bool $little, int $directories): void {
    $tiff = J::header($little);

    for ($i = 0; $i < $directories; $i++) {
        $at = 8 + 18 * $i;
        $last = $i === $directories - 1;
        $tiff .= J::u16($little, 1)
            .($last ? J::entry($little, 0x8825, 4, 1, J::u32($little, $at + 18)) : J::entry($little, 0x0112, 3, 1, J::u16($little, 6)))
            .J::u32($little, $last ? 0 : $at + 18);
    }

    $tiff .= gpsDirectory($little, strlen($tiff));

    if ($directories <= ExifLocation::MAX_DIRECTORIES) {
        expect(J::sentinels(exifStripped($tiff)))->toBe([]);
    } else {
        expect(fn () => ExifLocation::edits($tiff, new LocationBudget))
            ->toThrow(fn (LocationUnremovable $e) => expect($e->reason)->toBe(LocationUnremovable::EXIF_DAMAGED));
    }
})->with('byte orders')->with(['thirty-two' => [32], 'thirty-three' => [33]]);

it('walks a looping chain once', function (bool $little): void {
    // IFD0 → IFD1 at 26, whose next points back at IFD0.
    $tiff = exifWith($little, [J::entry($little, 0x0112, 3, 1, J::u16($little, 6))],
        J::u16($little, 1).J::entry($little, 0x8825, 4, 1, J::u32($little, 44)).J::u32($little, 8).gpsDirectory($little, 44), 26);

    expect(J::sentinels(exifStripped($tiff)))->toBe([]);
})->with('byte orders');

it('refuses GPS data sharing bytes with other EXIF data, and edits nothing', function (bool $little, string $how): void {
    $tiff = match ($how) {
        // A GPS value inside the maker note's bytes.
        'a GPS value inside the maker note' => exifWith($little, [J::entry($little, 0x8825, 4, 1, J::u32($little, 38)), J::entry($little, 0x927C, 7, 24, J::u32($little, 56))],
            J::u16($little, 1).J::entry($little, 0x0002, 5, 3, J::u32($little, 56)).J::u32($little, 0).str_repeat('M', 24)),
        // The title's value is the bytes the pointer's removal moves.
        'a value inside the entries that move' => exifWith($little, [J::entry($little, 0x8825, 4, 1, J::u32($little, 38)), J::entry($little, 0x9C9B, 1, 12, J::u32($little, 22))],
            gpsDirectory($little, 38)),
        // The same directory named as the EXIF directory and as GPS.
        'one directory named as EXIF and as GPS' => exifWith($little, [J::entry($little, 0x8769, 4, 1, J::u32($little, 38)), J::entry($little, 0x8825, 4, 1, J::u32($little, 38))],
            gpsDirectory($little, 38)),
    };

    expect(fn () => ExifLocation::edits($tiff, new LocationBudget))
        ->toThrow(fn (LocationUnremovable $e) => expect($e->reason)->toBe(LocationUnremovable::GPS_SHARED));
})->with('byte orders')->with(['a GPS value inside the maker note', 'a value inside the entries that move', 'one directory named as EXIF and as GPS']);

it('spends its budget on every entry it reads', function (bool $little): void {
    expect(fn () => ExifLocation::edits(J::tiff($little), new LocationBudget(entries: 3)))
        ->toThrow(fn (LocationUnremovable $e) => expect($e->reason)->toBe(LocationUnremovable::TOO_MANY));
})->with('byte orders');

it('blanks GPS in the XMP packet the block holds', function (bool $little): void {
    $packet = J::packet('<rdf:Description rdf:about="" xmlns:exif="http://ns.adobe.com/exif/1.0/" exif:GPSLatitude="SENTINEL-02BC"/>');
    $tiff = exifWith($little, [J::entry($little, 0x02BC, 7, strlen($packet), J::u32($little, 26))], $packet);
    $stripped = exifStripped($tiff);

    expect(J::sentinels($stripped))->toBe([])
        ->and($stripped)->toContain('xmlns:exif="http://ns.adobe.com/exif/1.0/"');
})->with('byte orders');
