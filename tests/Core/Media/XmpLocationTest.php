<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Media\LocationBudget;
use Kitsune\Core\Media\LocationUnremovable;
use Kitsune\Core\Media\XmpLocation;
use Kitsune\Core\Tests\Fixtures\LocatedJpeg as J;

/*
 * The GPS properties in one XMP packet — ADR-042 decision 30. Blanked with spaces of the same length, and read back
 * with libxml, which is not the tokenizer that found them.
 */

function xmpBlanked(string $packet): string
{
    $blanked = $packet;

    foreach (XmpLocation::edits($packet) as [$at, $bytes]) {
        expect(trim($bytes, ' '))->toBe('');
        $blanked = substr_replace($blanked, $bytes, $at, strlen($bytes));
    }

    expect(strlen($blanked))->toBe(strlen($packet));

    return $blanked;
}

function xmpDocument(string $packet): DOMXPath
{
    $document = new DOMDocument;
    expect($document->loadXML(substr($packet, strpos($packet, '<'), strrpos($packet, '>') - strpos($packet, '<') + 1)))->toBeTrue();

    $xpath = new DOMXPath($document);

    foreach ([
        'exif' => 'http://ns.adobe.com/exif/1.0/', 'hdrgm' => 'http://ns.adobe.com/hdr-gain-map/1.0/', 'dc' => 'http://purl.org/dc/elements/1.1/',
        'photoshop' => 'http://ns.adobe.com/photoshop/1.0/', 'Iptc4xmpExt' => 'http://iptc.org/std/Iptc4xmpExt/2008-02-29/',
        'Container' => 'http://ns.google.com/photos/1.0/container/', 'rdf' => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
    ] as $prefix => $uri) {
        $xpath->registerNamespace($prefix, $uri);
    }

    return $xpath;
}

const XMP_EXIF = 'xmlns:exif="http://ns.adobe.com/exif/1.0/"';

it('blanks each form of a GPS property, the packet keeping its length', function (string $description, string $gone = 'SENTINEL-'): void {
    $packet = J::packet($description);

    expect(xmpBlanked($packet))->not->toContain($gone)
        ->and($packet)->toContain($gone);
})->with([
    'an attribute' => ['<rdf:Description rdf:about="" '.XMP_EXIF.' exif:GPSLatitude="SENTINEL-A"/>'],
    'an element' => ['<rdf:Description rdf:about="" '.XMP_EXIF.'><exif:GPSLongitude>SENTINEL-B</exif:GPSLongitude></rdf:Description>'],
    'an empty element' => ['<rdf:Description rdf:about="" '.XMP_EXIF.'><exif:GPSVersionID/><dc:x xmlns:dc="urn:x">kept</dc:x></rdf:Description>', 'GPSVersionID'],
    'a field of a structure' => ['<rdf:Description rdf:about="" '.XMP_EXIF.' xmlns:I="http://iptc.org/std/Iptc4xmpExt/2008-02-29/"><I:LocationShown><rdf:Bag><rdf:li rdf:parseType="Resource"><exif:GPSLatitude>SENTINEL-C</exif:GPSLatitude></rdf:li></rdf:Bag></I:LocationShown></rdf:Description>'],
    'a drone\'s namespace, whatever the name' => ['<rdf:Description rdf:about="" xmlns:dji="http://www.dji.com/drone-dji/1.0/" dji:AbsoluteAltitude="SENTINEL-DJI"/>'],
    'a phone\'s cell tower' => ['<rdf:Description rdf:about="" xmlns:cell="http://developer.sonyericsson.com/cell/1.0/"><cell:cellid>SENTINEL-CELL</cell:cellid></rdf:Description>'],
    'the EXIF namespace under another prefix' => ['<rdf:Description rdf:about="" xmlns:e="http://ns.adobe.com/exif/1.0/" e:GPSLatitude="SENTINEL-E"/>'],
    'an element in a default namespace' => ['<rdf:Description rdf:about=""><GPSLatitude xmlns="http://ns.adobe.com/exif/1.0/">SENTINEL-D</GPSLatitude></rdf:Description>'],
    'a vendor\'s name in lower case' => ['<rdf:Description rdf:about="" xmlns:v="urn:vendor" v:gpsTrack="SENTINEL-V"/>'],
    'an embedded image' => ['<rdf:Description rdf:about="" xmlns:g="http://ns.google.com/photos/1.0/image/"><g:Data>/9j/SENTINEL-IMAGE</g:Data></rdf:Description>'],
    'an embedded image as an attribute' => ['<rdf:Description rdf:about="" xmlns:g="http://ns.google.com/photos/1.0/image/" g:Data="/9j/SENTINEL-ATTR-IMAGE"/>'],
    // As an XML reader reads them, references decoded (Codex, #164).
    'an embedded image written with a character reference' => ['<rdf:Description rdf:about="" xmlns:g="http://ns.google.com/photos/1.0/image/"><g:Data>&#47;9j/SENTINEL-REF-IMAGE</g:Data></rdf:Description>'],
    'an embedded image as an attribute, with a hexadecimal reference' => ['<rdf:Description rdf:about="" xmlns:g="http://ns.google.com/photos/1.0/image/" g:Data="&#x2F;9j/SENTINEL-HEX-IMAGE"/>'],
    'a drone\'s namespace written with a reference' => ['<rdf:Description rdf:about="" xmlns:d="http://www.dji.com&#47;drone-dji/1.0/" d:AbsoluteAltitude="SENTINEL-REF-DJI"/>'],
    'two location attributes sharing a local name' => ['<rdf:Description rdf:about="" '.XMP_EXIF.' xmlns:v="urn:vendor" exif:GPSLatitude="SENTINEL-ONE" v:GPSLatitude="SENTINEL-TWO"/>'],
]);

/*
 * ⚠️ AS A DRONE WRITES IT (review of decision 30). DJI's packet puts `drone-dji:Version` before `crs:Version` on one
 * description: libxml keys attributes by local name, so a pruning that read them keyed saw one `Version` and refused.
 */
it('strips a drone\'s packet and keeps the attribute that shares a name with one it drops', function (): void {
    $packet = J::packet('<rdf:Description rdf:about="" xmlns:drone-dji="http://www.dji.com/drone-dji/1.0/" xmlns:crs="http://ns.adobe.com/camera-raw-settings/1.0/"'
        .' drone-dji:Version="SENTINEL-DJI-VERSION" drone-dji:GpsLatitude="SENTINEL-DJI-LAT" crs:Version="7.0" crs:HasSettings="False"/>');
    $blanked = xmpBlanked($packet);

    expect(J::sentinels($blanked))->toBe([])
        ->and($blanked)->toContain('crs:Version="7.0"')->toContain('crs:HasSettings="False"');
});

it('refuses a blanked packet that leaves a location attribute a same-named one would hide', function (): void {
    $xml = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><rdf:Description xmlns:drone-dji="http://www.dji.com/drone-dji/1.0/"'
        .' xmlns:crs="http://ns.adobe.com/camera-raw-settings/1.0/" drone-dji:Version="1" crs:Version="7.0"/></rdf:RDF></x:xmpmeta>';

    expect(fn () => XmpLocation::audit($xml, $xml))
        ->toThrow(fn (LocationUnremovable $e) => expect($e->reason)->toBe(LocationUnremovable::XMP_MIXED));
});

it('keeps an exposure\'s latitude, which is no place', function (): void {
    $blanked = xmpBlanked(J::packet('<rdf:Description rdf:about="" '.XMP_EXIF.' xmlns:exifEX="http://cipa.jp/exif/1.0/" exif:GPSLatitude="SENTINEL-G"'
        .' exifEX:ISOSpeedLatitudeyyy="200" exifEX:ISOSpeedLatitudezzz="400"/>'));

    expect(J::sentinels($blanked))->toBe([])
        ->and($blanked)->toContain('exifEX:ISOSpeedLatitudeyyy="200"')->toContain('exifEX:ISOSpeedLatitudezzz="400"');
});

it('keeps what is not GPS', function (): void {
    $xpath = xmpDocument(xmpBlanked(J::packet(J::description())));

    expect($xpath->evaluate('string(//rdf:Description/@hdrgm:Version)'))->toBe('1.0')
        ->and($xpath->evaluate('string(//rdf:Description/@photoshop:City)'))->toBe('Kept City')
        ->and($xpath->evaluate('string(//Iptc4xmpExt:City)'))->toBe('Kept Shown City')
        ->and($xpath->evaluate('count(//Container:Directory//Container:Item)'))->toBe(1.0)
        ->and($xpath->evaluate('string(//dc:title//rdf:li)'))->toBe('Café GPS & é')
        ->and($xpath->evaluate('count(//exif:SubjectLocation)'))->toBe(1.0)
        ->and($xpath->evaluate('count(//@exif:GPSLatitude | //exif:GPSLatitude | //exif:GPSLongitude | //exif:GPSVersionID)'))->toBe(0.0);
});

it('never parses a packet naming none of it', function (): void {
    expect(XmpLocation::edits('<x:xmpmeta><unclosed><dc:title>A title</dc:title>'))->toBe([]);
});

it('refuses XMP it cannot read as text', function (string $payload): void {
    expect(fn () => XmpLocation::edits($payload))
        ->toThrow(fn (LocationUnremovable $e) => expect($e->reason)->toBe(LocationUnremovable::XMP_UNREADABLE));
})->with([
    'UTF-16' => [mb_convert_encoding(J::packet('<rdf:Description '.XMP_EXIF.' exif:GPSLatitude="1"/>'), 'UTF-16LE', 'UTF-8')],
    'a NUL inside it' => [J::packet('<rdf:Description '.XMP_EXIF.' exif:GPSLatitude="1'."\0".'"/>')],
    'bytes that are not UTF-8' => [J::packet('<rdf:Description '.XMP_EXIF.' exif:GPSLatitude="'."\xC3\x28".'"/>')],
]);

it('refuses XMP mentioning GPS that it cannot parse', function (string $payload): void {
    expect(fn () => XmpLocation::edits($payload))
        ->toThrow(fn (LocationUnremovable $e) => expect($e->reason)->toBe(LocationUnremovable::XMP_MALFORMED));
})->with([
    'a DTD with an entity' => ['<!DOCTYPE x [<!ENTITY a "gps">]>'.J::packet('<rdf:Description/>')],
    'an element left open' => [J::packet('<rdf:Description '.XMP_EXIF.'><exif:GPSLatitude>1</rdf:Description>')],
    'an end tag that does not match' => [J::packet('<rdf:Description '.XMP_EXIF.'><exif:GPSLatitude>1</exif:GPSLongitude></rdf:Description>')],
    'a prefix nobody declared' => [J::packet('<rdf:Description e:GPSLatitude="1"/>')],
    'an unterminated comment' => ['<x:xmpmeta xmlns:x="adobe:ns:meta/"><!-- gps </x:xmpmeta>'],
]);

it('refuses XMP mentioning GPS that libxml cannot read, though no property is GPS', function (): void {
    // `&nbsp;` is no XML entity: the tokenizer finds nothing to blank, and the audit still reads the packet.
    expect(fn () => XmpLocation::edits(J::packet('<rdf:Description rdf:about="" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>GPS&nbsp;</dc:title></rdf:Description>')))
        ->toThrow(fn (LocationUnremovable $e) => expect($e->reason)->toBe(LocationUnremovable::XMP_MALFORMED));
});

it('refuses a change beyond the GPS properties', function (): void {
    $mixed = J::packet('<rdf:Description rdf:about="" '.XMP_EXIF.' xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:x>foo<exif:GPSLatitude>1</exif:GPSLatitude>bar</dc:x></rdf:Description>');

    expect(fn () => XmpLocation::edits($mixed))
        ->toThrow(fn (LocationUnremovable $e) => expect($e->reason)->toBe(LocationUnremovable::XMP_MIXED));
});

it('audits what it was handed, so a tokenizer that missed a property is caught', function (): void {
    $xml = J::packet(J::description());
    $xml = substr($xml, strpos($xml, '<'), strrpos($xml, '>') - strpos($xml, '<') + 1);

    expect(fn () => XmpLocation::audit($xml, $xml))
        ->toThrow(fn (LocationUnremovable $e) => expect($e->reason)->toBe(LocationUnremovable::XMP_MIXED));
});

it('throws, rather than reading no match, when a pattern cannot run', function (): void {
    $limit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '1');

    try {
        expect(fn () => XmpLocation::edits(J::packet(J::description())))
            ->toThrow(fn (RuntimeException $e) => expect($e)->not->toBeInstanceOf(LocationUnremovable::class));
    } finally {
        ini_set('pcre.backtrack_limit', (string) $limit);
    }
});

it('names none of the gain-map, container, panorama or camera vocabulary', function (string $local): void {
    expect(XmpLocation::names('http://ns.google.com/photos/1.0/container/', $local))->toBeFalse();
})->with(['Version', 'GainMapMin', 'HDRCapacityMax', 'Directory', 'Item', 'Semantic', 'Mime', 'Length', 'ProjectionType', 'PoseHeadingDegrees', 'MotionPhoto', 'SubjectLocation', 'City']);

/*
 * ⚠️ IN BOUNDED TIME (review of decision 30). libxml checks an element's attributes against one another, so a packet
 * built with thousands on one element costs it seconds to read, twice: an element may carry `LocationBudget`'s
 * `attributes`, and a file may have `packets` read.
 */
it('refuses an element with more attributes than its budget, and reads one with as many', function (): void {
    $packet = fn (int $attributes): string => J::packet('<rdf:Description rdf:about="" '.XMP_EXIF.' xmlns:a="urn:a" exif:GPSLatitude="SENTINEL-G"'
        .implode('', array_map(fn (int $i): string => " a:x{$i}=\"1\"", range(1, $attributes - 4))).'/>');

    expect(J::sentinels(xmpBlanked($packet(1_024))))->toBe([])
        ->and(fn () => XmpLocation::edits($packet(1_025)))
        ->toThrow(fn (LocationUnremovable $e) => expect($e->reason)->toBe(LocationUnremovable::TOO_MANY));
});

it('reads as many packets as its budget, and refuses one more', function (): void {
    $budget = new LocationBudget(packets: 2);
    $packet = J::packet('<rdf:Description rdf:about="" '.XMP_EXIF.' exif:GPSLatitude="1"/>');

    XmpLocation::edits($packet, $budget);
    XmpLocation::edits($packet, $budget);
    // A packet naming none of it is never read, and costs nothing.
    XmpLocation::edits(J::packet('<rdf:Description rdf:about=""/>'), $budget);

    expect(fn () => XmpLocation::edits($packet, $budget))
        ->toThrow(fn (LocationUnremovable $e) => expect($e->reason)->toBe(LocationUnremovable::TOO_MANY));
});
