<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Imports;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use InvalidArgumentException;
use XMLReader;

/**
 * Read the supported WXR fields from a private local snapshot.
 *
 * @internal
 *
 * @phpstan-type SourceRow array{id: string, type: string, status: string, parent: string, slug: string, title: string, utc: string, content_sha256: string, meta: array<string, list<?string>>}
 */
final class WordPressWxrExport
{
    private const WP = ['https://wordpress.org/export/1.2/', 'http://wordpress.org/export/1.2/'];

    private const FIELDS = [
        'id' => 'wp:post_id', 'type' => 'wp:post_type', 'status' => 'wp:status',
        'parent' => 'wp:post_parent', 'slug' => 'wp:post_name', 'title' => 'title',
        'utc' => 'wp:post_date_gmt', 'content' => 'content:encoded',
    ];

    /** @return array{sha256: string, rows: list<SourceRow>} */
    public function read(string $path, string $blog): array
    {
        if (! class_exists(XMLReader::class)) {
            throw new InvalidArgumentException('WXR preview requires PHP XMLReader.');
        }

        $source = realpath($path);
        $file = $source !== false && is_file($source) ? @fopen($source, 'rb') : false;
        if ($file === false) {
            throw new InvalidArgumentException('Provide a readable local WXR export.');
        }

        $snapshot = tmpfile();
        if ($snapshot === false) {
            fclose($file);

            throw new InvalidArgumentException('Unable to prepare a local WXR snapshot.');
        }

        try {
            if (stream_copy_to_stream($file, $snapshot) === false || ! rewind($snapshot)) {
                throw new InvalidArgumentException('Unable to copy the local WXR export.');
            }
            $hash = hash_init('sha256');
            hash_update_stream($hash, $snapshot);
            if (! rewind($snapshot)) {
                throw new InvalidArgumentException('Unable to fingerprint the local WXR export.');
            }

            return ['sha256' => hash_final($hash), 'rows' => $this->parse($snapshot, $blog)];
        } finally {
            fclose($file);
            fclose($snapshot);
        }
    }

    /**
     * @param  resource  $snapshot
     * @return list<SourceRow>
     */
    private function parse($snapshot, string $blog): array
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $flags = LIBXML_NONET;
            if (defined('LIBXML_NO_XXE')) {
                $flags |= LIBXML_NO_XXE;
            }
            $reader = XMLReader::fromStream($snapshot, flags: $flags);
            $reader->setParserProperty(XMLReader::LOADDTD, false);
            $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);
            try {
                return $this->rows($reader, $blog);
            } finally {
                $reader->close();
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @return list<SourceRow> */
    private function rows(XMLReader $reader, string $blog): array
    {
        $version = null;
        $address = null;
        $namespace = null;
        $rss = false;
        $channel = false;
        $channelSeen = false;
        $rows = [];
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::DOC_TYPE) {
                throw new InvalidArgumentException('A WXR export cannot declare a document type.');
            }
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === 1 && $reader->name === 'channel') {
                $channel = false;
            }
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }
            if ($reader->depth === 0) {
                $rss = $reader->name === 'rss' && $reader->namespaceURI === '' && $reader->getAttribute('version') === '2.0';
            } elseif ($rss && $reader->depth === 1 && $reader->name === 'channel' && $reader->namespaceURI === '') {
                if ($channelSeen) {
                    throw new InvalidArgumentException('A WXR export must contain one channel.');
                }
                $channel = true;
                $channelSeen = true;
            }
            if (! $channel || $reader->depth !== 2) {
                continue;
            }

            if (in_array($reader->namespaceURI, self::WP, true) && in_array($reader->localName, ['wxr_version', 'base_blog_url'], true)) {
                $namespace ??= $reader->namespaceURI;
                if ($reader->namespaceURI !== $namespace) {
                    throw new InvalidArgumentException('The WXR export mixes WordPress namespaces.');
                }
                if ($reader->localName === 'wxr_version' && $version === null) {
                    $version = self::text($reader->expand());
                } elseif ($reader->localName === 'base_blog_url' && $address === null) {
                    $address = self::text($reader->expand());
                } else {
                    throw new InvalidArgumentException('The WXR export repeats source metadata.');
                }
            } elseif ($reader->name === 'item' && $reader->namespaceURI === '') {
                if ($namespace === null) {
                    throw new InvalidArgumentException('WXR source metadata must precede its items.');
                }
                $rows[] = $this->item($reader, $namespace);
            }
        }
        if (libxml_get_last_error() !== false || $version !== '1.2' || $address !== $blog || $rows === []) {
            throw new InvalidArgumentException('Expected a complete WXR 1.2 export for the confirmed blog address.');
        }

        if (count(array_unique(array_column($rows, 'id'))) !== count($rows)) {
            throw new InvalidArgumentException('The WXR export repeats a post ID.');
        }
        usort($rows, static fn (array $first, array $second): int => strnatcmp($first['id'], $second['id']));

        return $rows;
    }

    /** @return SourceRow */
    private function item(XMLReader $reader, string $namespace): array
    {
        $document = new DOMDocument;
        $item = $reader->expand($document);
        if (! $item instanceof DOMElement) {
            throw new InvalidArgumentException('Unable to read a WXR item.');
        }

        $document->appendChild($item);
        $xpath = new DOMXPath($document, false);
        $xpath->registerNamespace('wp', $namespace);
        $xpath->registerNamespace('content', 'http://purl.org/rss/1.0/modules/content/');
        $parts = [];
        foreach (self::FIELDS as $key => $selector) {
            $parts[$key] = self::one($xpath, $selector, $item);
        }
        if (preg_match('/^[1-9][0-9]{0,63}$/D', $parts['id']) !== 1) {
            throw new InvalidArgumentException('A WXR item has an invalid post ID.');
        }

        $meta = [];
        $nodes = $xpath->query('wp:postmeta', $item);
        if ($nodes === false) {
            throw new InvalidArgumentException('Unable to read WXR post metadata.');
        }
        foreach ($nodes as $node) {
            $key = self::one($xpath, 'wp:meta_key', $node);
            if ($key === '') {
                throw new InvalidArgumentException('A WXR item has an empty metadata key.');
            }
            $meta[$key][] = self::one($xpath, 'wp:meta_value', $node);
        }
        $content = $parts['content'];
        unset($parts['content']);

        return $parts + ['content_sha256' => hash('sha256', $content), 'meta' => $meta];
    }

    private static function one(DOMXPath $xpath, string $selector, DOMNode $node): string
    {
        $result = $xpath->query($selector, $node);
        if ($result === false || $result->length !== 1) {
            throw new InvalidArgumentException('A WXR item is missing a required value or repeats it.');
        }

        return self::text($result->item(0));
    }

    private static function text(mixed $node): string
    {
        if (! $node instanceof DOMElement) {
            throw new InvalidArgumentException('Unable to read a WXR text value.');
        }
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                throw new InvalidArgumentException('A WXR text value contains an unexpected element.');
            }
        }

        return $node->textContent;
    }
}
