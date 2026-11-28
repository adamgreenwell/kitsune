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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Kitsune\Core\Fields\Types\RichTextType;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Site;

/** @internal Resolves a body's media references without changing its stored HTML or file visibility. */
final class InlineMediaPresentation
{
    /** @return array<int, string> */
    public static function staffUrls(Entry $entry, Site $site, string $html): array
    {
        $body = self::parse($html);

        return $body === null ? [] : self::urls($entry, $site, $body, true);
    }

    public static function publicHtml(Entry $entry, Site $site, string $html): HtmlString
    {
        $sanitized = (new RichTextType)->sanitize($html);
        $body = self::parse($sanitized);
        if ($body === null) {
            return new HtmlString($sanitized);
        }

        return new HtmlString(self::render($body, self::urls($entry, $site, $body, false)));
    }

    public static function withoutReferences(string $html): string
    {
        $body = self::parse($html);

        return $body === null ? $html : self::render($body, []);
    }

    /** @param array<int, string> $urls */
    private static function render(DOMElement $body, array $urls): string
    {
        foreach (iterator_to_array($body->getElementsByTagName('img')) as $image) {
            if (! $image->hasAttribute('data-kitsune-media')) {
                continue;
            }
            $id = (int) $image->getAttribute('data-kitsune-media');
            if (isset($urls[$id])) {
                $image->removeAttribute('data-kitsune-media');
                $image->setAttribute('src', $urls[$id]);
            } else {
                $caption = $image->parentNode instanceof DOMElement && $image->parentNode->tagName === 'figure'
                    ? '' : $image->getAttribute('alt');
                $image->replaceWith($body->ownerDocument->createTextNode($caption));
            }
        }

        $result = '';
        foreach ($body->childNodes as $child) {
            $result .= $body->ownerDocument->saveHTML($child);
        }

        return $result;
    }

    /** @return array<int, string> */
    private static function urls(Entry $entry, Site $site, DOMElement $body, bool $staff): array
    {
        if ($entry->site_id !== $site->id || $entry->org_id !== $site->org_id) {
            return [];
        }

        $referenced = [];
        foreach ($body->getElementsByTagName('img') as $image) {
            $raw = $image->getAttribute('data-kitsune-media');
            $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (is_int($id) && (string) $id === $raw) {
                $referenced[$id] = true;
            }
        }
        if ($referenced === []) {
            return [];
        }

        $attached = [];
        foreach ($entry->relationState() as $relation) {
            foreach ($relation as $target) {
                if (is_int($target) && isset($referenced[$target])) {
                    $attached[$target] = true;
                }
            }
        }
        if ($attached === []) {
            return [];
        }

        $mediaTypes = EntryType::visibleFor($site, $site->org_id)->where('is_media', true)->pluck('id');
        $assets = Entry::query()->where('org_id', $site->org_id)->where('site_id', $site->id)
            ->whereIn('entry_type_id', $mediaTypes)->whereKey(array_keys($attached))->with('mediaFile')->get();
        $urls = [];
        foreach ($assets as $asset) {
            $file = $asset->mediaFile;
            if ($file === null) {
                continue;
            }
            $url = $staff
                ? (Gate::allows('view', $asset) ? MediaDelivery::adminUrlForFile($asset, $file) : null)
                : (MediaDelivery::servesDirectly($file) ? MediaDelivery::urlForFile($asset, $file) : null);
            if ($url !== null) {
                $urls[(int) $asset->getKey()] = $url;
            }
        }

        return $urls;
    }

    private static function parse(string $html): ?DOMElement
    {
        if (! str_contains($html, 'data-kitsune-media')) {
            return null;
        }

        $document = new DOMDocument;
        $document->loadHTML(
            '<!doctype html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        return $document->getElementsByTagName('body')->item(0);
    }
}
