<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Media\JpegBytes;
use Kitsune\Core\Media\JpegLocation;
use Kitsune\Core\Media\LocationBudget;
use Kitsune\Core\Media\LocationUnremovable;
use Kitsune\Core\Tests\Fixtures\LocatedJpeg as J;

/*
 * A JPEG without the GPS data in its EXIF and XMP — ADR-042 decision 30. What is asserted is always the bytes: the
 * length kept, every sentinel gone, no GPS pointer an independent reader can find, orientation as it was, and the
 * picture's own bytes — `J::body()` wherever it appears — exactly as uploaded.
 */

afterEach(function (): void {
    foreach ([...glob(sys_get_temp_dir().'/kitsune-loc-*') ?: [], ...glob(sys_get_temp_dir().'/'.JpegLocation::TEMPORARY_PREFIX.'*') ?: []] as $leftover) {
        @unlink($leftover);
    }
});

/** @return array{0: string, 1: list<array{0: int, 1: string, 2: bool}>} */
function jpegStripped(string $jpeg, ?LocationBudget $budget = null): array
{
    $edits = JpegLocation::planBytes($jpeg, $budget)['edits'];
    $out = $jpeg;

    foreach ($edits as [$at, $bytes]) {
        $out = substr_replace($out, $bytes, $at, strlen($bytes));
    }

    return [$out, $edits];
}

/** Everything a stripped file must be, against what it was. */
function expectStripped(string $in, string $out): void
{
    expect(strlen($out))->toBe(strlen($in))
        ->and(J::sentinels($out))->toBe([])
        ->and(J::gpsPointers($out))->toBe(0)
        ->and(J::orientationOf($out))->toBe(J::orientationOf($in))
        ->and(JpegLocation::planBytes($out)['edits'])->toBe([]);

    for ($at = strpos($in, J::body()); $at !== false; $at = strpos($in, J::body(), $at + 1)) {
        expect(substr($out, $at, strlen(J::body())))->toBe(J::body());
    }
}

function expectRefused(string $jpeg, string $reason, ?LocationBudget $budget = null): void
{
    expect(fn () => JpegLocation::planBytes($jpeg, $budget))
        ->toThrow(fn (LocationUnremovable $e) => expect($e->reason)->toBe($reason));
}

function temporaries(): int
{
    return count(glob(sys_get_temp_dir().'/'.JpegLocation::TEMPORARY_PREFIX.'*') ?: []);
}

/** The base picture up to its scan data: its tables, its frame and its SOS header. */
function pictureHeader(): string
{
    return substr(J::base(), 0, 605);
}

dataset('byte orders', ['II' => [true], 'MM' => [false]]);

/*
 * ────────────────────────────────  What is stripped  ────────────────────────────────
 */

it('strips the canonical block and its thumbnail, and edits nothing else', function (bool $little): void {
    $in = J::jpeg([J::exif(J::tiff($little))]);
    [$out, $edits] = jpegStripped($in);

    expectStripped($in, $out);
    // The block's IFD0 and GPS data, and the thumbnail's own, at the file's offsets: the block begins at 12.
    expect(array_map(fn (array $edit): array => [$edit[0], $edit[0] + strlen($edit[1]), $edit[2]], $edits))->toBe([
        [20, 86, false], [146, 224, true], [302, 374, true], [394, 412, false], [412, 466, true],
    ]);
})->with('byte orders');

it('strips the images after the picture, and keeps what else follows as it was', function (bool $little): void {
    $in = J::photo($little);
    [$out] = jpegStripped($in);

    expectStripped($in, $out);
    expect($out)->toEndWith('TRAILER-KEPT')
        ->toContain('hdrgm:Version="1.0"')
        ->toContain('photoshop:City="Kept City"')
        ->toContain('PAYLOAD!');
})->with('byte orders');

it('strips a block in trailing bytes that are no JPEG', function (): void {
    $in = J::jpeg([J::exif(J::tiff(true))], "\xFF\xD8\xFFJUNK".J::segment(0xFE, J::exif(J::gpsOnlyTiff(true))));
    [$out] = jpegStripped($in);

    expectStripped($in, $out);
});

it('strips past stray bytes, leaving them and a comment as they were', function (): void {
    $comment = J::segment(0xFE, 'Shot on a kept camera');
    $in = "\xFF\xD8\x12\x34".$comment.J::exif(J::gpsOnlyTiff(true)).J::body();
    [$out] = jpegStripped($in);

    expect(substr($out, 2, 2 + strlen($comment)))->toBe("\x12\x34".$comment)
        ->and(J::sentinels(substr($out, 4 + strlen($comment))))->toBe([]);
});

it('reads fill bytes, stuffing and restart markers as a decoder does', function (): void {
    $scan = "\x12\xFF\x00\x34\x56\xFF\xD0\x78\xFF\xFF\xD1\x9A";
    $in = pictureHeader().$scan.J::exif(J::gpsOnlyTiff(true))."\xFF\xFF\xD9";
    [$out] = jpegStripped($in);

    expect(substr($out, 605, strlen($scan)))->toBe($scan)
        ->and(J::sentinels($out))->toBe([])
        ->and(strlen($out))->toBe(strlen($in));
});

it('strips an EXIF block after the first scan, where a progressive encoder may put one', function (): void {
    $body = J::body();
    $in = "\xFF\xD8".substr($body, 0, 656).J::exif(J::gpsOnlyTiff(true)).substr($body, 656);
    [$out] = jpegStripped($in);

    expect(J::sentinels($out))->toBe([])->and(strlen($out))->toBe(strlen($in));
});

it('refuses location inside a segment it may not write, and leaves one without location as it is', function (Closure $segment): void {
    expectRefused(J::jpeg([$segment(J::exif(J::gpsOnlyTiff(true)))]), LocationUnremovable::OUT_OF_PLACE);

    $withoutLocation = J::header(true).J::u16(true, 1).J::entry(true, 0x0112, 3, 1, J::u16(true, 6)).J::u32(true, 0);
    expect(JpegLocation::planBytes(J::jpeg([$segment(J::exif($withoutLocation))]))['edits'])->toBe([]);
})->with([
    'a comment' => [fn (string $block): string => J::segment(0xFE, $block)],
    'a colour profile' => [fn (string $block): string => J::segment(0xE2, "ICC_PROFILE\0\x01\x01".$block)],
    'the Multi-Picture index' => [fn (string $block): string => J::segment(0xE2, "MPF\0".$block)],
    'Adobe\'s segment' => [fn (string $block): string => J::segment(0xEE, 'Adobe'.$block)],
    'a quantisation table' => [fn (string $block): string => J::segment(0xDB, $block)],
]);

it('refuses XMP carrying location inside a segment it may not write', function (): void {
    $xmp = J::xmp(J::packet('<rdf:Description rdf:about="" xmlns:exif="http://ns.adobe.com/exif/1.0/" exif:GPSLatitude="1"/>'));

    expectRefused(J::jpeg([J::segment(0xFE, $xmp)]), LocationUnremovable::OUT_OF_PLACE);
});

it('refuses location a damaged marker hides from the walk', function (): void {
    // The APP1's marker read as fill: the whole block, its thumbnail among it, is inside a segment of no known kind.
    $hidden = substr_replace(J::jpeg([J::exif(J::tiff(true))]), "\xFF", 3, 1);

    expectRefused($hidden, LocationUnremovable::OUT_OF_PLACE);
});

it('strips a preview\'s EXIF inside one segment, and refuses one crossing two', function (): void {
    $preview = "\xFF\xD8".J::exif(J::gpsOnlyTiff(true)).J::body();
    $inOne = J::jpeg([J::segment(0xE2, $preview)]);
    [$out] = jpegStripped($inOne);

    expect(J::sentinels($out))->toBe([]);
    expectRefused(J::jpeg([J::segment(0xE2, substr($preview, 0, 40)), J::segment(0xE2, substr($preview, 40))]), LocationUnremovable::BLOCKS_OVERLAP);
});

it('leaves a block crossing two segments alone where it carries no location', function (): void {
    $block = J::xmp(J::packet('<rdf:Description rdf:about=""/>'));
    $in = J::jpeg([J::segment(0xE2, substr($block, 0, 30)), J::segment(0xE2, substr($block, 30))]);

    expect(JpegLocation::planBytes($in)['edits'])->toBe([]);
});

it('joins a block split over several segments, and writes no segment\'s header', function (bool $little): void {
    $tiff = J::tiff($little);
    $second = J::segment(0xE1, "Exif\0\0".substr($tiff, 100));
    $in = J::jpeg([J::exif(substr($tiff, 0, 100)), $second]);
    [$out] = jpegStripped($in);
    $secondAt = 2 + 4 + 6 + 100;

    expect(J::sentinels($out))->toBe([])
        ->and(substr($out, $secondAt, 10))->toBe(substr($second, 0, 10))
        ->and(strlen($out))->toBe(strlen($in));
})->with('byte orders');

it('strips Photoshop\'s copies of EXIF and XMP, in one segment or two, and keeps its place names', function (bool $split): void {
    $resources = [
        J::resource(0x0404, "\x1C\x02\x5A\x00\x09Kept Town"),
        J::resource(0x0422, J::gpsOnlyTiff(true)),
        J::resource(0x0424, J::packet('<rdf:Description rdf:about="" xmlns:exif="http://ns.adobe.com/exif/1.0/" exif:GPSLatitude="SENTINEL-IRB-XMP"/>')),
    ];
    $whole = implode('', $resources);
    $segments = $split
        ? [J::photoshop([substr($whole, 0, 40)]), J::photoshop([substr($whole, 40)])]
        : [J::photoshop($resources)];
    $in = J::jpeg($segments);
    [$out] = jpegStripped($in);

    expect(J::sentinels($out))->toBe([])
        ->and($out)->toContain('Kept Town')
        ->and(strlen($out))->toBe(strlen($in));
})->with(['one segment' => [false], 'two' => [true]]);

it('zeroes extended XMP naming GPS, across its chunks, identifier and all, and leaves another alone', function (): void {
    // The one name is split between the first chunk and the second, and is whole in neither: `<v:Longi` and `tude/>`.
    $named = J::extended(str_repeat(' ', 52).'<v:Longitude/>SENTINEL-EXT', str_repeat('A', 32));
    $other = J::extended('<dc:title>kept</dc:title>', str_repeat('B', 32));
    $in = J::jpeg([J::xmp(J::packet('<rdf:Description rdf:about=""/>')), ...$named, ...$other]);
    [$out] = jpegStripped($in);
    $at = 2 + strlen(J::xmp(J::packet('<rdf:Description rdf:about=""/>')));

    foreach ($named as $segment) {
        expect(substr($out, $at, 4))->toBe(substr($segment, 0, 4))
            ->and(substr($out, $at + 4, strlen($segment) - 4))->toBe(str_repeat("\0", strlen($segment) - 4));
        $at += strlen($segment);
    }

    expect(substr($out, $at, strlen(implode('', $other))))->toBe(implode('', $other));
});

it('zeroes extended XMP embedding an image, rather than decoding it to ask', function (): void {
    $in = J::jpeg(J::extended('<GImage:Data>'.base64_encode(J::base()).'</GImage:Data>', str_repeat('C', 32)));
    [$out] = jpegStripped($in);

    expect($out)->not->toContain('/9j/')
        ->and(strlen($out))->toBe(strlen($in));
});

it('strips XMP in an APP1 with no standard header, and not in a thermal camera\'s', function (): void {
    $packet = J::packet('<rdf:Description rdf:about="" xmlns:exif="http://ns.adobe.com/exif/1.0/" exif:GPSLatitude="SENTINEL-NONSTD"/>');
    [$out] = jpegStripped(J::jpeg([J::segment(0xE1, 'JUNK'.$packet)]));

    expect(J::sentinels($out))->toBe([])
        ->and(JpegLocation::planBytes(J::jpeg([J::segment(0xE1, "FLIR\0".$packet)]))['edits'])->toBe([]);
});

it('strips an EXIF block named in capitals, after stray bytes', function (): void {
    $in = J::jpeg([J::segment(0xE1, "\0\0EXIF\0\0".J::gpsOnlyTiff(true))]);
    [$out] = jpegStripped($in);

    expect(J::sentinels($out))->toBe([]);
});

it('zeroes a block inside a GPS value with it, and refuses one that runs past it', function (): void {
    $little = true;
    $nested = J::exif(J::gpsOnlyTiff(true));
    $gps = fn (string $value): string => J::header($little)
        .J::u16($little, 1).J::entry($little, 0x8825, 4, 1, J::u32($little, 26)).J::u32($little, 0)
        .J::u16($little, 1).J::entry($little, 0x001B, 7, strlen($value), J::u32($little, 44)).J::u32($little, 0)
        .$value;

    [$out] = jpegStripped(J::jpeg([J::exif($gps($nested))]));
    expect(J::sentinels($out))->toBe([]);

    // The GPS value holds the nested block's first part alone; its GPS directory runs on past the value's end.
    $cut = strlen($nested) - 30;
    expectRefused(J::jpeg([J::exif($gps(substr($nested, 0, $cut)).substr($nested, $cut))]), LocationUnremovable::BLOCKS_OVERLAP);
});

it('refuses more blocks, or segments, than its budget', function (): void {
    expectRefused(J::jpeg(array_fill(0, 257, J::exif(J::gpsOnlyTiff(true)))), LocationUnremovable::TOO_MANY);
    expectRefused(J::jpeg([J::segment(0xFE, 'a')]), LocationUnremovable::TOO_MANY, new LocationBudget(segments: 4));
});

it('refuses more images after the picture than its budget', function (): void {
    expectRefused(J::jpeg([], str_repeat(J::base(), 17)), LocationUnremovable::TOO_MANY);
    expect(JpegLocation::planBytes(J::jpeg([], str_repeat(J::base(), 16)))['edits'])->toBe([]);
});

it('refuses a file that does not begin as a JPEG does', function (): void {
    expectRefused('GIF89a', LocationUnremovable::NOT_JPEG);
});

/*
 * ────────────────────────────────  The copy  ────────────────────────────────
 */

it('plans nothing for a file with no location, and makes no temporary', function (): void {
    $clean = J::file(J::jpeg([
        J::segment(0xE0, "JFIF\0\x01\x01\0\0\x01\0\x01\0\0"),
        J::segment(0xE2, "ICC_PROFILE\0\x01\x01".str_repeat('i', 40)),
        J::segment(0xEE, "Adobe\0\x64\0\0\0\0\x01"),
        J::segment(0xFE, 'CREATOR: gd-jpeg'),
    ]));
    $before = temporaries();

    expect(JpegLocation::plan($clean)['edits'])->toBe([])
        ->and(JpegLocation::strippedCopy($clean))->toBeNull()
        ->and(temporaries())->toBe($before);
});

it('copies, strips, and leaves the file it was given as it was', function (bool $little): void {
    $path = J::file(J::photo($little));
    $before = hash_file('sha256', $path);
    $copy = JpegLocation::strippedCopy($path);

    expect($copy)->not->toBeNull()
        ->and(hash_file('sha256', $path))->toBe($before);
    expectStripped(J::photo($little), (string) file_get_contents((string) $copy));
    expect(JpegLocation::strippedCopy((string) $copy))->toBeNull();
})->with('byte orders');

it('refuses an inconsistent plan, before any temporary exists', function (array $edits): void {
    $path = J::file(J::jpeg([J::exif(J::tiff(true))]));
    $plan = JpegLocation::plan($path);
    $before = temporaries();

    expect(fn () => JpegLocation::copyWith($path, ['edits' => $edits, 'editable' => $plan['editable']]))->toThrow(RuntimeException::class)
        ->and(temporaries())->toBe($before);
})->with([
    'out of order' => [[[146, str_repeat("\0", 4), true], [20, str_repeat("\0", 4), true]]],
    'overlapping' => [[[146, str_repeat("\0", 8), true], [150, str_repeat("\0", 8), true]]],
    'past the end' => [[[1780, str_repeat("\0", 8), true]]],
    'empty' => [[[146, '', true]]],
    'outside the metadata' => [[[1130, "\0", true]]],
    'before the start' => [[[-1, "\0", true]]],
]);

it('refuses a copy that fails its own check, and leaves no temporary', function (): void {
    $path = J::file(J::jpeg([J::exif(J::tiff(true))]));
    $plan = JpegLocation::plan($path);
    // The pointer's removal without the zeroing of the directory it named: planned again, the copy still changes.
    $partial = ['edits' => [$plan['edits'][0]], 'editable' => $plan['editable']];
    $before = temporaries();

    expect(fn () => JpegLocation::audited($path, $partial))->toThrow(RuntimeException::class, 'failed its own check')
        ->and(temporaries())->toBe($before);
});

/*
 * ────────────────────────────────  What it survives  ────────────────────────────────
 */

it('survives every truncation, refusing or leaving no GPS, with no PHP error', function (bool $little): void {
    $photo = J::photo($little);
    set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
        if ((error_reporting() & $level) === 0) {
            return false;
        }

        throw new ErrorException($message, 0, $level, $file, $line);
    });

    try {
        for ($length = 1; $length < strlen($photo); $length++) {
            $prefix = substr($photo, 0, $length);

            try {
                [$out] = jpegStripped($prefix);
            } catch (LocationUnremovable) {
                continue;
            }

            if (J::gpsPointers($out) !== 0 || J::sentinels($out) !== []) {
                throw new RuntimeException("Location left in the first {$length} bytes.");
            }
        }
    } finally {
        restore_error_handler();
    }

    expect(true)->toBeTrue();
})->with('byte orders');

it('survives a flipped byte anywhere in its EXIF block, refusing or leaving no GPS pointer and the orientation read', function (bool $little): void {
    $photo = J::jpeg([J::exif(J::tiff($little)), J::xmp(J::packet(J::description()))]);
    set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
        if ((error_reporting() & $level) === 0) {
            return false;
        }

        throw new ErrorException($message, 0, $level, $file, $line);
    });

    try {
        for ($at = 2; $at < 12 + strlen(J::tiff($little)); $at++) {
            foreach ([0x00, 0xFF, 0x80, (ord($photo[$at]) + 1) & 0xFF, ord($photo[$at]) ^ 1] as $value) {
                $flipped = substr_replace($photo, chr($value), $at, 1);

                try {
                    [$out] = jpegStripped($flipped);
                } catch (LocationUnremovable) {
                    continue;
                }

                if (J::gpsPointers($out) !== 0 || J::orientationOf($out) !== J::orientationOf($flipped)) {
                    throw new RuntimeException("Byte {$at} set to {$value} left a GPS pointer, or moved orientation.");
                }
            }
        }
    } finally {
        restore_error_handler();
    }

    expect(true)->toBeTrue();
})->with('byte orders');

it('finds a block past the first window of a large file', function (): void {
    // The picture's scan data runs past two windows, and a gain map with GPS follows it.
    $scan = str_repeat("\x12", 2 * JpegBytes::WINDOW + 7);
    $path = J::file(pictureHeader().$scan."\xFF\xD9".J::secondary());
    $copy = JpegLocation::strippedCopy($path);

    expect($copy)->not->toBeNull()
        ->and(J::sentinels(substr((string) file_get_contents((string) $copy), -2000)))->toBe([])
        ->and(filesize((string) $copy))->toBe(filesize($path));
});

/*
 * ────────────────────────────────  What other readers see  ────────────────────────────────
 */

it('leaves a file ext-exif reads the orientation, thumbnail and maker note of, and no GPS', function (bool $little): void {
    $copy = (string) JpegLocation::strippedCopy(J::file(J::photo($little)));
    $before = exif_read_data(J::file(J::photo($little)), null, true, true);
    $after = exif_read_data($copy, null, true, true);

    expect($before)->toHaveKey('GPS')
        ->and($after)->not->toHaveKey('GPS')
        ->and($after['IFD0']['Orientation'])->toBe(6)
        ->and($after)->toHaveKey('THUMBNAIL')
        ->and($after['EXIF'])->toHaveKey('MakerNote');
})->with('byte orders')->skip(! function_exists('exif_read_data'), 'ext-exif is not installed');

it('leaves a file that decodes to the same pixels', function (bool $little): void {
    $pixels = function (string $bytes): string {
        $image = imagecreatefromstring($bytes);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    };
    [$out] = jpegStripped(J::photo($little));

    expect($pixels($out))->toBe($pixels(J::photo($little)));
})->with('byte orders')->skip(! function_exists('imagecreatefromstring'), 'ext-gd is not installed');
