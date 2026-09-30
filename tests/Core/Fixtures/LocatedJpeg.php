<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

/**
 * JPEGs that carry where they were made, built byte by byte — ADR-042 decision 30's fixtures.
 *
 * ⚠️ NO OTHER CLASS, SO THE BROWSER SUITE CAN `require` IT: CI's browser job installs the skeleton alone, with no
 * autoloader for the tests, and builds its upload from `photo()` with `php -r`.
 *
 * ⚠️ SENTINELS WHERE THE LOCATION IS. A GPS rational is `GGGGPPPP` — the same eight bytes in either byte order — and
 * every text carrier holds a `SENTINEL-…` of its own, so a stripped file is checked by searching its bytes for what must
 * be gone, as well as by reading it. `orientationOf()` and `gpsPointers()` read the result independently of the
 * production code, so a bug in one is not a bug in its check.
 */
final class LocatedJpeg
{
    /** A 16×8 baseline JPEG with no metadata at all — its tables, its frame, its scan, its EOI. */
    public const BASE = '/9j/2wBDAAMCAgMCAgMDAwMEAwMEBQgFBQQEBQoHBwYIDAoMDAsKCwsNDhIQDQ4RDgsLEBYQERMUFRUVDA8XGBYUGBIUFRT/2wBDAQMEBAUEBQkFBQkU'
        .'DQsNFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBT/wAARCAAIABADAREAAhEBAxEB/8QAHwAAAQUBAQEBAQEA'
        .'AAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJico'
        .'KSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT'
        .'1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEE'
        .'BSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOE'
        .'hYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwDy3wn+'
        .'yp9z/Q//AB2vr8Zxhv7x+Y8N8e/D757N4T/ZU+5/of8A47XwuM4w394/qvhvj34ffP/Z';

    /** A GPS rational's bytes: the same in `II` and `MM`. */
    public const RATIONAL = 'GGGGPPPP';

    public static function base(): string
    {
        return (string) base64_decode(self::BASE, true);
    }

    /** Everything after the base's SOI: where a built JPEG's own segments end, its picture begins. */
    public static function body(): string
    {
        return substr(self::base(), 2);
    }

    public static function segment(int $marker, string $payload): string
    {
        return "\xFF".chr($marker).pack('n', strlen($payload) + 2).$payload;
    }

    /** @param  list<string>  $segments */
    public static function jpeg(array $segments, string $trailer = ''): string
    {
        return "\xFF\xD8".implode('', $segments).self::body().$trailer;
    }

    public static function u16(bool $little, int $value): string
    {
        return pack($little ? 'v' : 'n', $value);
    }

    public static function u32(bool $little, int $value): string
    {
        return pack($little ? 'V' : 'N', $value);
    }

    /** One directory entry; a value of four bytes or fewer is written in place, left-justified. */
    public static function entry(bool $little, int $tag, int $type, int $count, string $value): string
    {
        return pack($little ? 'vvV' : 'nnN', $tag, $type, $count).str_pad($value, 4, "\0");
    }

    public static function header(bool $little): string
    {
        return ($little ? 'II' : 'MM').self::u16($little, 42).self::u32($little, 8);
    }

    public static function exif(string $tiff, string $identifier = "Exif\0\0"): string
    {
        return self::segment(0xE1, $identifier.$tiff);
    }

    /**
     * The smallest TIFF with GPS: IFD0 holding only the pointer, a GPS directory of two entries, and a latitude.
     * 80 bytes: IFD0 at 8, GPS at 26, its rational at 56.
     */
    public static function gpsOnlyTiff(bool $little): string
    {
        return self::header($little)
            .self::u16($little, 1).self::entry($little, 0x8825, 4, 1, self::u32($little, 26)).self::u32($little, 0)
            .self::u16($little, 2).self::entry($little, 0x0001, 2, 2, "S\0").self::entry($little, 0x0002, 5, 3, self::u32($little, 56)).self::u32($little, 0)
            .str_repeat(self::RATIONAL, 3);
    }

    /** An EXIF thumbnail that is itself a JPEG with GPS: 750 bytes. */
    public static function thumbnail(): string
    {
        return "\xFF\xD8".self::exif(self::gpsOnlyTiff(false)).self::body();
    }

    /**
     * The canonical block, 1112 bytes, offsets relative to it:
     *
     * - `[8,74)` IFD0, five entries: Orientation 6 inline at 10, XResolution → 254, ExifIFD → 74, **GPS → 134** at 46,
     *   XPTitle (12 bytes) → 262 at 58; next → 212 at 70.
     * - `[74,116)` ExifIFD: ExifVersion inline, a 16-byte MakerNote → 274, Interop → 116.
     * - `[116,134)` Interop: `R98`.
     * - `[134,212)` GPS, six entries: version inline, `N`, latitude → 290, `W`, longitude → 314, a 24-byte processing
     *   method → 338.
     * - `[212,254)` IFD1: compression 6, the thumbnail at 362, its length.
     * - `[254,262)` 72/1, `[262,274)` the title, `[274,290)` the maker note — `ABSO`, an absolute offset to its own
     *   payload at 282, `PAYLOAD!` — `[290,338)` the rationals, `[338,362)` the method, `[362,1112)` the thumbnail.
     */
    public static function tiff(bool $little): string
    {
        $thumbnail = self::thumbnail();

        return self::header($little)
            .self::u16($little, 5)
            .self::entry($little, 0x0112, 3, 1, self::u16($little, 6))
            .self::entry($little, 0x011A, 5, 1, self::u32($little, 254))
            .self::entry($little, 0x8769, 4, 1, self::u32($little, 74))
            .self::entry($little, 0x8825, 4, 1, self::u32($little, 134))
            .self::entry($little, 0x9C9B, 1, 12, self::u32($little, 262))
            .self::u32($little, 212)
            .self::u16($little, 3)
            .self::entry($little, 0x9000, 7, 4, '0232')
            .self::entry($little, 0x927C, 7, 16, self::u32($little, 274))
            .self::entry($little, 0xA005, 4, 1, self::u32($little, 116))
            .self::u32($little, 0)
            .self::u16($little, 1).self::entry($little, 0x0001, 2, 4, "R98\0").self::u32($little, 0)
            .self::u16($little, 6)
            .self::entry($little, 0x0000, 1, 4, "\x02\x03\x00\x00")
            .self::entry($little, 0x0001, 2, 2, "N\0")
            .self::entry($little, 0x0002, 5, 3, self::u32($little, 290))
            .self::entry($little, 0x0003, 2, 2, "W\0")
            .self::entry($little, 0x0004, 5, 3, self::u32($little, 314))
            .self::entry($little, 0x001B, 7, 24, self::u32($little, 338))
            .self::u32($little, 0)
            .self::u16($little, 3)
            .self::entry($little, 0x0103, 3, 1, self::u16($little, 6))
            .self::entry($little, 0x0201, 4, 1, self::u32($little, 362))
            .self::entry($little, 0x0202, 4, 1, self::u32($little, strlen($thumbnail)))
            .self::u32($little, 0)
            .self::u32($little, 72).self::u32($little, 1)
            ."T\0i\0t\0l\0e\0\0\0"
            .'ABSO'.self::u32($little, 282).'PAYLOAD!'
            .str_repeat(self::RATIONAL, 6)
            ."ASCII\0\0\0SENTINEL-GPS-PM!"
            .$thumbnail;
    }

    /** An XMP packet around one `rdf:Description`, padded as a writer pads it. */
    public static function packet(string $description, int $padding = 200): string
    {
        return "<?xpacket begin=\"\xEF\xBB\xBF\" id=\"W5M0MpCehiHzreSzNTczkc9d\"?>\n"
            .'<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            .$description
            ."</rdf:RDF></x:xmpmeta>\n".str_repeat(' ', $padding)."\n<?xpacket end=\"w\"?>";
    }

    public static function xmp(string $packet): string
    {
        return self::segment(0xE1, "http://ns.adobe.com/xap/1.0/\0".$packet);
    }

    /**
     * A description with GPS in every form a packet holds it — an attribute, an element, an empty element, a field of
     * a structure, a drone's namespace — beside what must stay: a gain map's version, a container directory, a city, a
     * title with GPS in its text, and `exif:SubjectLocation`, which is a point in the picture.
     */
    public static function description(): string
    {
        return '<rdf:Description rdf:about="" xmlns:exif="http://ns.adobe.com/exif/1.0/" xmlns:hdrgm="http://ns.adobe.com/hdr-gain-map/1.0/"'
            .' xmlns:dji="http://www.dji.com/drone-dji/1.0/" xmlns:dc="http://purl.org/dc/elements/1.1/"'
            .' xmlns:Iptc4xmpExt="http://iptc.org/std/Iptc4xmpExt/2008-02-29/" xmlns:Container="http://ns.google.com/photos/1.0/container/"'
            .' xmlns:Item="http://ns.google.com/photos/1.0/container/item/" xmlns:photoshop="http://ns.adobe.com/photoshop/1.0/"'
            .' hdrgm:Version="1.0" exif:GPSLatitude="SENTINEL-XMP-A" dji:AbsoluteAltitude="SENTINEL-XMP-DJI" photoshop:City="Kept City">'
            .'<exif:GPSLongitude>SENTINEL-XMP-B</exif:GPSLongitude><exif:GPSVersionID/>'
            .'<Iptc4xmpExt:LocationShown><rdf:Bag><rdf:li rdf:parseType="Resource"><Iptc4xmpExt:City>Kept Shown City</Iptc4xmpExt:City>'
            .'<exif:GPSLatitude>SENTINEL-XMP-C</exif:GPSLatitude></rdf:li></rdf:Bag></Iptc4xmpExt:LocationShown>'
            .'<Container:Directory><rdf:Seq><rdf:li rdf:parseType="Resource"><Container:Item Item:Semantic="Primary" Item:Mime="image/jpeg"/>'
            .'</rdf:li></rdf:Seq></Container:Directory>'
            .'<dc:title><rdf:Alt><rdf:li xml:lang="x-default">Café GPS &amp; é</rdf:li></rdf:Alt></dc:title>'
            .'<exif:SubjectLocation><rdf:Seq><rdf:li>1</rdf:li></rdf:Seq></exif:SubjectLocation>'
            .'</rdf:Description>';
    }

    /**
     * Extended XMP: the data split into chunks, each an APP1 with the GUID, the full length and its offset.
     *
     * @return list<string>
     */
    public static function extended(string $data, string $guid, int $chunk = 60): array
    {
        $segments = [];

        foreach (str_split($data, $chunk) as $i => $piece) {
            $segments[] = self::segment(0xE1, "http://ns.adobe.com/xmp/extension/\0".$guid.pack('N', strlen($data)).pack('N', $chunk * $i).$piece);
        }

        return $segments;
    }

    /** One Photoshop image resource, its name empty and its data padded to even. */
    public static function resource(int $id, string $data): string
    {
        return '8BIM'.pack('n', $id)."\0\0".pack('N', strlen($data)).$data.(strlen($data) % 2 === 1 ? "\0" : '');
    }

    /** @param  list<string>  $resources */
    public static function photoshop(array $resources): string
    {
        return self::segment(0xED, "Photoshop 3.0\0".implode('', $resources));
    }

    /** A gain map after the picture, as a phone appends one: GPS in its EXIF and in its XMP, beside its own parameters. */
    public static function secondary(): string
    {
        return "\xFF\xD8".self::exif(self::gpsOnlyTiff(false))
            .self::xmp(self::packet('<rdf:Description rdf:about="" xmlns:hdrgm="http://ns.adobe.com/hdr-gain-map/1.0/"'
                .' xmlns:exif="http://ns.adobe.com/exif/1.0/" hdrgm:Version="1.0" exif:GPSLatitude="SENTINEL-SEC-XMP"/>'))
            .self::body();
    }

    /**
     * The photo the browser uploads: the canonical EXIF block, the XMP description, extended XMP naming GPS, a gain
     * map after the picture, and trailing bytes that are no JPEG at all.
     */
    public static function photo(bool $little): string
    {
        $extended = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            .'<rdf:Description xmlns:exif="http://ns.adobe.com/exif/1.0/" exif:GPSLongitude="SENTINEL-EXT"/></rdf:RDF></x:xmpmeta>';

        return self::jpeg(
            [self::exif(self::tiff($little)), self::xmp(self::packet(self::description())), ...self::extended($extended, str_repeat('A', 32))],
            self::secondary().'TRAILER-KEPT',
        );
    }

    /**
     * A JPEG whose GPS data cannot be removed with certainty: its latitude lies inside the maker note's bytes, so
     * zeroing one would damage the other (`GPS_SHARED`).
     */
    public static function unremovable(bool $little = true): string
    {
        return self::jpeg([self::exif(self::header($little)
            .self::u16($little, 2).self::entry($little, 0x8825, 4, 1, self::u32($little, 38)).self::entry($little, 0x927C, 7, 24, self::u32($little, 56)).self::u32($little, 0)
            .self::u16($little, 1).self::entry($little, 0x0002, 5, 3, self::u32($little, 56)).self::u32($little, 0)
            .str_repeat(self::RATIONAL, 3))]);
    }

    /**
     * Orientation as a browser reads it — IFD0's 0x0112 in the first EXIF block before the scan — or null.
     */
    public static function orientationOf(string $jpeg): ?int
    {
        $at = 2;

        while ($at + 4 <= strlen($jpeg) && $jpeg[$at] === "\xFF") {
            $marker = ord($jpeg[$at + 1]);

            if ($marker === 0xDA || $marker === 0xD9) {
                return null;
            }

            $length = (int) unpack('n', substr($jpeg, $at + 2, 2))[1];
            $payload = substr($jpeg, $at + 4, $length - 2);

            if ($marker === 0xE1 && preg_match('/^(.{0,4})Exif\0./s', $payload, $match) === 1) {
                $tiff = substr($payload, strlen($match[1]) + 6);
                $little = str_starts_with($tiff, 'II');
                $ifd = self::read($tiff, 4, 4, $little);

                if ($ifd === null) {
                    return null;
                }

                for ($k = 0, $count = self::read($tiff, $ifd, 2, $little) ?? 0; $k < $count; $k++) {
                    $entry = $ifd + 2 + 12 * $k;

                    if (self::read($tiff, $entry, 2, $little) === 0x0112) {
                        return self::read($tiff, $entry + 8, 2, $little);
                    }
                }

                return null;
            }

            $at += 2 + $length;
        }

        return null;
    }

    /**
     * How many GPS directories any EXIF block anywhere in the bytes still points at — from IFD0, its chain, SubIFDs, the
     * EXIF and interoperability directories — found by searching for every `Exif\0` that could begin one, wherever it
     * sits, with or without an APP1 marker before it. A pointer is read as PHP's reader reads it: four bytes, in the
     * entry for a value of four bytes or fewer and at its offset for a longer one, whatever the type — and it counts
     * where a directory is there to follow.
     */
    public static function gpsPointers(string $bytes): int
    {
        $found = 0;
        $from = 0;

        while (preg_match('/exif\0./is', $bytes, $match, PREG_OFFSET_CAPTURE, $from) === 1) {
            $from = $match[0][1] + 1;
            $tiff = substr($bytes, $match[0][1] + 6);

            if (! str_starts_with($tiff, 'II') && ! str_starts_with($tiff, 'MM')) {
                continue;
            }

            $little = str_starts_with($tiff, 'II');
            $queue = [self::read($tiff, 4, 4, $little)];
            $seen = [];

            while ($queue !== [] && count($seen) < 64) {
                $ifd = array_shift($queue);

                if ($ifd === null || $ifd < 8 || isset($seen[$ifd]) || ($count = self::read($tiff, $ifd, 2, $little)) === null) {
                    continue;
                }

                $seen[$ifd] = true;

                for ($k = 0; $k < $count && $ifd + 2 + 12 * $k + 12 <= strlen($tiff); $k++) {
                    $entry = $ifd + 2 + 12 * $k;
                    $tag = self::read($tiff, $entry, 2, $little);
                    $sizes = [1 => 1, 2 => 1, 3 => 2, 4 => 4, 5 => 8, 6 => 1, 7 => 1, 8 => 2, 9 => 4, 10 => 8, 11 => 4, 12 => 8, 13 => 4];
                    $values = (int) self::read($tiff, $entry + 4, 4, $little);
                    $slot = self::read($tiff, $entry + 8, 4, $little);
                    $target = $values * ($sizes[self::read($tiff, $entry + 2, 2, $little)] ?? 1) <= 4 ? $slot : self::read($tiff, (int) $slot, 4, $little);

                    if ($tag === 0x8825 && $target !== null && $target >= 8 && self::read($tiff, $target, 2, $little) !== null) {
                        $found++;
                    }

                    if ($tag === 0x8769 || $tag === 0xA005) {
                        $queue[] = $target;
                    }

                    if ($tag === 0x014A) {
                        if ($values <= 1) {
                            $queue[] = $target;
                        } else {
                            for ($j = 0; $j < min($values, 32); $j++) {
                                $queue[] = self::read($tiff, (int) $slot + 4 * $j, 4, $little);
                            }
                        }
                    }
                }

                $queue[] = self::read($tiff, $ifd + 2 + 12 * $count, 4, $little);
            }
        }

        return $found;
    }

    /**
     * Every sentinel still in the bytes.
     *
     * @return list<string>
     */
    public static function sentinels(string $bytes): array
    {
        preg_match_all('/SENTINEL-[A-Z0-9-]+|'.self::RATIONAL.'/', $bytes, $matches);

        return array_values(array_unique($matches[0]));
    }

    /** A temporary file holding the bytes, for the caller to remove. */
    public static function file(string $bytes, string $prefix = 'kitsune-loc-'): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), $prefix);
        file_put_contents($path, $bytes);

        return $path;
    }

    private static function read(string $tiff, int $at, int $width, bool $little): ?int
    {
        $bytes = $at >= 0 ? substr($tiff, $at, $width) : '';

        if (strlen($bytes) !== $width) {
            return null;
        }

        return (int) unpack($width === 2 ? ($little ? 'v' : 'n') : ($little ? 'V' : 'N'), $bytes)[1];
    }
}
