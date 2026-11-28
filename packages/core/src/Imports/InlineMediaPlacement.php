<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Imports;

use DomainException;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use stdClass;

/**
 * @internal
 *
 * @phpstan-type Placement array{field: string, relation: string, positions: list<int>}
 *
 * @phpstan-import-type LinkTargets from ImportPlan
 */
final class InlineMediaPlacement
{
    /**
     * The ordered list maps each numbered image slot to a target in the
     * approved relation. A repeated image can point to the same target.
     *
     * @param  array<string, mixed>  $values
     * @param  LinkTargets  $links
     * @return list<Placement>
     */
    public static function fromPlan(mixed $input, array $values, array $links): array
    {
        if (! is_array($input) || ! array_is_list($input) || $input === []) {
            throw new InvalidArgumentException('Inline media placements must be a nonempty list.');
        }

        $placements = [];
        foreach ($input as $item) {
            if (! $item instanceof stdClass || array_keys(get_object_vars($item)) !== ['field', 'relation', 'positions']
                || ! is_string($item->field) || ! is_string($item->relation) || isset($placements[$item->field])
                || ! is_string($values[$item->field] ?? null) || ! isset($links[$item->relation])
                || ! is_array($item->positions) || ! array_is_list($item->positions) || $item->positions === []) {
                throw new InvalidArgumentException('An inline media placement needs a body and its approved relation.');
            }
            foreach ($item->positions as $position) {
                if (! is_int($position) || ! isset($links[$item->relation][$position])) {
                    throw new InvalidArgumentException('An inline media occurrence is missing its approved relation target.');
                }
            }
            self::slots($values[$item->field], count($item->positions));
            $placements[$item->field] = [
                'field' => $item->field, 'relation' => $item->relation, 'positions' => $item->positions,
            ];
        }

        return array_values($placements);
    }

    public static function containsReference(mixed $value): bool
    {
        if (is_array($value) || $value instanceof stdClass) {
            foreach ($value as $child) {
                if (self::containsReference($child)) {
                    return true;
                }
            }

            return false;
        }
        if (! is_string($value) || stripos($value, 'data-kitsune-media') === false) {
            return false;
        }

        foreach (self::parseBody($value)->getElementsByTagName('img') as $image) {
            if ($image->hasAttribute('data-kitsune-media')) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $urls The reviewed source images in document order. */
    public static function preview(string $html, array $urls): string
    {
        $body = self::parseBody($html);
        $images = $body->getElementsByTagName('img');
        if ($images->length !== count($urls)) {
            throw new DomainException('The reviewed inventory must cover each inline image occurrence.');
        }
        foreach ($images as $index => $image) {
            if ($image->getAttribute('src') !== $urls[$index] || $image->hasAttribute('data-kitsune-media')
                || $image->hasAttribute('srcset')) {
                throw new DomainException('An inline image differs from its reviewed source location.');
            }
            $image->removeAttribute('src');
            foreach (['width', 'height', 'title', 'lang', 'dir'] as $attribute) {
                $image->removeAttribute($attribute);
            }
            $image->setAttribute('data-kitsune-media', (string) ($index + 1));
        }

        // The editor round-trips paragraphs. Keep the caption beside the image in that format.
        foreach (iterator_to_array($body->getElementsByTagName('figcaption')) as $caption) {
            $paragraph = $body->ownerDocument->createElement('p');
            while ($caption->firstChild !== null) {
                $paragraph->appendChild($caption->firstChild);
            }
            $caption->replaceWith($paragraph);
        }
        foreach (iterator_to_array($body->getElementsByTagName('figure')) as $figure) {
            while ($figure->firstChild !== null) {
                $figure->before($figure->firstChild);
            }
            $figure->remove();
        }

        return self::markup($body);
    }

    /** @param list<int> $ids The scoped destination entries in body order. */
    public static function apply(string $html, array $ids): string
    {
        $body = self::slots($html, count($ids));
        foreach ($body->getElementsByTagName('img') as $index => $image) {
            $image->setAttribute('data-kitsune-media', (string) $ids[$index]);
        }

        return self::markup($body);
    }

    private static function slots(string $html, int $count): DOMElement
    {
        $body = self::parseBody($html);
        $images = $body->getElementsByTagName('img');
        if ($images->length !== $count) {
            throw new InvalidArgumentException('The approved inline media slots changed.');
        }
        foreach ($images as $index => $image) {
            if ($image->getAttribute('data-kitsune-media') !== (string) ($index + 1) || $image->hasAttribute('src')) {
                throw new InvalidArgumentException('The approved inline media slot order changed.');
            }
        }

        return $body;
    }

    private static function parseBody(string $html): DOMElement
    {
        $document = new DOMDocument;
        $document->loadHTML(
            '<!doctype html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        return $document->getElementsByTagName('body')->item(0)
            ?? throw new DomainException('Unable to read the inline media body.');
    }

    private static function markup(DOMElement $body): string
    {
        $html = '';
        foreach ($body->childNodes as $child) {
            $html .= $body->ownerDocument?->saveHTML($child);
        }

        return $html;
    }
}
