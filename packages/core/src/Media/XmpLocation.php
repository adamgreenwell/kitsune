<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Media;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * The GPS properties in one XMP packet, as the edits that blank them (ADR-042 decision 30).
 *
 * @internal
 *
 * @phpstan-import-type Edit from ExifLocation
 *
 * ⚠️ XMP'S GPS GOES WITH EXIF'S, because an export mirrors one into the other — Lightroom, Photoshop and Capture One
 * write `exif:GPSLatitude` into the packet beside the EXIF block — and a drone writes its own. Without this, a public
 * JPEG's confirmation would promise a removal the packet undoes. What goes: every property whose name holds `gps`,
 * `latitude` or `longitude` (and the misspelling `longtitude` one vendor ships), in any namespace; everything in a
 * drone's (`drone-dji`) and a phone's cell-tower (`sonyericsson`) namespaces; and an image the packet embeds as base64,
 * which is a JPEG of its own that could carry them. Place names — `photoshop:City`, IPTC's location structures — stay,
 * as the confirmation says.
 *
 * ⚠️ BLANKED WITH SPACES OF THE SAME LENGTH, NEVER RE-SERIALISED. A packet sits in a segment of fixed length, and
 * `DOMDocument::saveXML()` escapes and reorders what it writes; spaces where an attribute or an element stood are
 * whitespace XML allows there. So a small tokenizer finds each property's exact bytes — resolving prefixes, since a
 * packet may call the EXIF namespace anything — and libxml then reads the packet twice, as it was with the properties
 * removed and as blanked: the two must be the same document, or the packet is refused rather than trusted.
 *
 * ⚠️ A PACKET NAMING NONE OF IT IS NEVER PARSED, so an odd but harmless packet is never a reason to refuse a file.
 */
final class XmpLocation
{
    /** Namespaces whose every property is location. */
    public const DROPPED = [
        'http://www.dji.com/drone-dji/1.0/',
        'http://developer.sonyericsson.com/cell/1.0/',
    ];

    /**
     * A property name that is location, in any namespace. `SubjectLocation` is a point in the picture, and
     * `ISOSpeedLatitudeyyy` an exposure's latitude: both stay.
     */
    public const NAME = '/gps|(?<!isospeed)latitude|longitude|longtitude/i';

    /** What a packet must mention before it is read at all: the names, the dropped namespaces, a base64 JPEG. */
    public const MENTIONS = '~gps|latitude|longitude|longtitude|www\.dji\.com/drone-dji|developer\.sonyericsson\.com/cell|/9j/~i';

    private const XML = 'http://www.w3.org/XML/1998/namespace';

    /**
     * @return list<Edit>
     *
     * @throws LocationUnremovable
     */
    public static function edits(string $payload, ?LocationBudget $budget = null): array
    {
        if (self::matches(self::MENTIONS, str_replace("\0", '', $payload)) === 0) {
            return [];
        }

        $budget ??= new LocationBudget;
        $budget->spend('packets');

        $first = strpos($payload, '<');
        $last = strrpos($payload, '>');

        if ($first === false || $last === false || $last < $first) {
            throw new LocationUnremovable(LocationUnremovable::XMP_MALFORMED);
        }

        $xml = substr($payload, $first, $last - $first + 1);

        /*
         * UTF-16 or UTF-32, or bytes that are not UTF-8: names this cannot read are names it cannot remove. Asked of
         * mbstring, which Laravel requires, rather than of a `/u` pattern — whose `false` means both "not UTF-8" and
         * "could not run", and only the first is the file's.
         */
        if (! mb_check_encoding($xml, 'UTF-8') || str_contains($xml, "\0")) {
            throw new LocationUnremovable(LocationUnremovable::XMP_UNREADABLE);
        }

        $ranges = self::locations($xml, $budget->attributes);
        // In one pass: a packet can hold thousands of properties, and a copy of it for each is quadratic.
        $blanked = '';
        $cursor = 0;

        foreach ($ranges as [$from, $to]) {
            $blanked .= substr($xml, $cursor, $from - $cursor).str_repeat(' ', $to - $from);
            $cursor = $to;
        }

        $blanked .= substr($xml, $cursor);

        // Always, once the packet mentions any of it: a tokenizer that missed a property is caught here, not trusted.
        self::audit($xml, $blanked);

        return array_map(
            static fn (array $range): array => [$first + $range[0], str_repeat(' ', $range[1] - $range[0]), false],
            $ranges,
        );
    }

    /** Whether a property in `$namespace` called `$local` is location. */
    public static function names(string $namespace, string $local): bool
    {
        return in_array($namespace, self::DROPPED, true) || preg_match(self::NAME, $local) === 1;
    }

    /**
     * `preg_match()`, throwing where the pattern could not run rather than reading that as no match.
     *
     *
     * @param-out array<array-key, string> $matches
     *
     * @throws RuntimeException
     */
    public static function matches(string $pattern, string $subject, mixed &$matches = null, int $offset = 0): int
    {
        $result = preg_match($pattern, $subject, $matches, 0, $offset);

        if ($result === false) {
            throw new RuntimeException('XMP could not be searched: '.preg_last_error_msg());
        }

        return $result;
    }

    /**
     * Compare the packet with its location removed by libxml to the packet as blanked: they must be one document.
     *
     * @throws LocationUnremovable
     */
    public static function audit(string $xml, string $blanked): void
    {
        $previous = libxml_use_internal_errors(true);

        try {
            libxml_clear_errors();
            $original = new DOMDocument;
            $result = new DOMDocument;

            if (! $original->loadXML($xml, LIBXML_NONET) || ! $result->loadXML($blanked, LIBXML_NONET) || libxml_get_errors() !== []) {
                throw new LocationUnremovable(LocationUnremovable::XMP_MALFORMED);
            }

            if ($original->documentElement !== null) {
                self::prune($original->documentElement);
            }

            foreach ([$original, $result] as $document) {
                $blank = (new DOMXPath($document))->query('//text()[normalize-space(.)=""]');

                foreach ($blank === false ? [] : iterator_to_array($blank) as $text) {
                    $text->parentNode?->removeChild($text);
                }
            }

            if ($original->C14N() !== $result->C14N()) {
                throw new LocationUnremovable(LocationUnremovable::XMP_MIXED);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** An embedded JPEG, as base64: `/9j/` is how `FF D8 FF` encodes. */
    private static function embedsImage(string $text): bool
    {
        return str_starts_with(ltrim($text), '/9j/');
    }

    /**
     * The byte ranges of every location property in a packet, found without libxml so they are exact.
     *
     * @return list<array{0: int, 1: int}>
     *
     * @throws LocationUnremovable for a packet this cannot read, or an element with more attributes than `$maxAttributes`
     */
    private static function locations(string $xml, int $maxAttributes): array
    {
        $at = 0;
        $stack = [];
        $ranges = [];
        // The depth at which a removed element was opened, while its content is skipped.
        $skipping = null;
        $skipFrom = 0;
        $root = ['xml' => self::XML];

        while (($open = strpos($xml, '<', $at)) !== false) {
            foreach (['<?' => '?>', '<!--' => '-->', '<![CDATA[' => ']]>'] as $opener => $closer) {
                if (substr_compare($xml, $opener, $open, strlen($opener)) === 0) {
                    $close = strpos($xml, $closer, $open + strlen($opener));

                    if ($close === false) {
                        throw new LocationUnremovable(LocationUnremovable::XMP_MALFORMED);
                    }

                    $at = $close + strlen($closer);

                    continue 2;
                }
            }

            // A DTD, and with it entities, is never read: XMP has none, and libxml would expand them.
            if (($xml[$open + 1] ?? '') === '!') {
                throw new LocationUnremovable(LocationUnremovable::XMP_MALFORMED);
            }

            if (($xml[$open + 1] ?? '') === '/') {
                if (self::matches('/\G<\/([^\s>]+)\s*>/', $xml, $closing, $open) === 0) {
                    throw new LocationUnremovable(LocationUnremovable::XMP_MALFORMED);
                }

                $top = array_pop($stack);

                if ($top === null || $top['name'] !== $closing[1]) {
                    throw new LocationUnremovable(LocationUnremovable::XMP_MALFORMED);
                }

                $close = $open + strlen($closing[0]);
                $text = html_entity_decode(substr($xml, $top['content'], $open - $top['content']), ENT_XML1 | ENT_QUOTES, 'UTF-8');

                if ($skipping === null && ! $top['children'] && self::embedsImage($text)) {
                    $ranges[] = [$top['from'], $close];
                }

                if ($skipping !== null && count($stack) === $skipping) {
                    $ranges[] = [$skipFrom, $close];
                    $skipping = null;
                }

                $at = $close;

                continue;
            }

            if (self::matches('/\G<([^\s\/>]+)/', $xml, $tag, $open) === 0) {
                throw new LocationUnremovable(LocationUnremovable::XMP_MALFORMED);
            }

            $name = $tag[1];
            $cursor = $open + strlen($tag[0]);
            $namespaces = $stack === [] ? $root : $stack[count($stack) - 1]['namespaces'];
            $attributes = [];
            $selfClosing = false;

            while (true) {
                self::matches('/\G\s*/', $xml, $space, $cursor);
                $cursor += strlen($space[0]);

                if (($xml[$cursor] ?? '') === '>') {
                    $cursor++;

                    break;
                }

                if (substr_compare($xml, '/>', $cursor, 2) === 0) {
                    $selfClosing = true;
                    $cursor += 2;

                    break;
                }

                if ($space[0] === '' || self::matches('/\G([^\s=\/>]+)\s*=\s*("[^"<]*"|\'[^\'<]*\')/', $xml, $attribute, $cursor) === 0) {
                    throw new LocationUnremovable(LocationUnremovable::XMP_MALFORMED);
                }

                if (count($attributes) >= $maxAttributes) {
                    throw new LocationUnremovable(LocationUnremovable::TOO_MANY);
                }

                $value = html_entity_decode(substr($attribute[2], 1, -1), ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $attributes[] = [$attribute[1], $value, $cursor, $cursor + strlen($attribute[0])];

                if ($attribute[1] === 'xmlns') {
                    $namespaces[''] = $value;
                } elseif (str_starts_with($attribute[1], 'xmlns:')) {
                    $namespaces[substr($attribute[1], 6)] = $value;
                }

                $cursor += strlen($attribute[0]);
            }

            [$uri, $local] = self::resolve($name, $namespaces, true);

            if ($skipping === null) {
                foreach ($attributes as [$attributeName, $value, $from, $to]) {
                    if ($attributeName === 'xmlns' || str_starts_with($attributeName, 'xmlns:')) {
                        continue;
                    }

                    [$attributeUri, $attributeLocal] = self::resolve($attributeName, $namespaces, false);

                    if (self::names($attributeUri, $attributeLocal) || self::embedsImage($value)) {
                        $ranges[] = [$from, $to];
                    }
                }
            }

            if ($stack !== []) {
                $stack[count($stack) - 1]['children'] = true;
            }

            if ($selfClosing) {
                if ($skipping === null && self::names($uri, $local)) {
                    $ranges[] = [$open, $cursor];
                }

                $at = $cursor;

                continue;
            }

            if ($skipping === null && self::names($uri, $local)) {
                $skipping = count($stack);
                $skipFrom = $open;
            }

            $stack[] = ['name' => $name, 'namespaces' => $namespaces, 'from' => $open, 'content' => $cursor, 'children' => false];
            $at = $cursor;
        }

        if ($stack !== []) {
            throw new LocationUnremovable(LocationUnremovable::XMP_MALFORMED);
        }

        return $ranges;
    }

    /**
     * A qualified name's namespace and local name. An unprefixed attribute is in no namespace, as XML has it.
     *
     * @param  array<string, string>  $namespaces
     * @return array{0: string, 1: string}
     *
     * @throws LocationUnremovable for a prefix nothing declared
     */
    private static function resolve(string $name, array $namespaces, bool $element): array
    {
        if (! str_contains($name, ':')) {
            return [$element ? ($namespaces[''] ?? '') : '', $name];
        }

        [$prefix, $local] = explode(':', $name, 2);

        if (! isset($namespaces[$prefix])) {
            throw new LocationUnremovable(LocationUnremovable::XMP_MALFORMED);
        }

        return [$namespaces[$prefix], $local];
    }

    /** Remove, as libxml sees them, the properties `locations()` blanks — the other half of the audit. */
    private static function prune(DOMElement $element): void
    {
        // As a list: keyed, PHP keys attributes by local name, and `drone-dji:Version` would hide behind `crs:Version`.
        foreach (iterator_to_array($element->attributes, false) as $attribute) {
            if (self::names((string) $attribute->namespaceURI, (string) $attribute->localName) || self::embedsImage($attribute->value)) {
                $element->removeAttributeNode($attribute);
            }
        }

        foreach (iterator_to_array($element->childNodes, false) as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $leaf = true;

            foreach ($child->childNodes as $grandchild) {
                if ($grandchild instanceof DOMElement) {
                    $leaf = false;

                    break;
                }
            }

            if (self::names((string) $child->namespaceURI, (string) $child->localName) || ($leaf && self::embedsImage($child->textContent))) {
                $element->removeChild($child);

                continue;
            }

            self::prune($child);
        }
    }
}
