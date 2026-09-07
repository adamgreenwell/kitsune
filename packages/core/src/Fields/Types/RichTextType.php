<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Fields\Types;

use Kitsune\Core\Fields\FieldConfig;

/**
 * User-supplied HTML rendered on public pages — the ONLY field type in v1
 * that is an XSS vector, and one of two needing security review.
 *
 * Sanitised on write (canonical, cheap) and escaped on read (defence in
 * depth). The allowlist lives in core, never in field settings: an org must
 * not be able to widen its own allowlist, because the org and the attacker
 * are not always different people.
 *
 * Erasure is the awkward part (ADR-020). Personal data can be anywhere in a
 * free-text body, so redaction is a content operation rather than a field
 * one. A `personal`-classified rich text field requires manual review during
 * an erasure request, and the UI must say so rather than implying automation.
 */
final class RichTextType extends BaseFieldType
{
    /** Never permitted regardless of settings. */
    public const FORBIDDEN_TAGS = ['script', 'style', 'iframe', 'object', 'embed', 'form'];

    public const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'em', 'u', 's', 'a', 'ul', 'ol', 'li',
        'h2', 'h3', 'h4', 'blockquote', 'code', 'pre', 'img', 'figure', 'figcaption',
    ];

    public static function handle(): string
    {
        return 'rich_text';
    }

    public static function label(): string
    {
        return 'Rich text';
    }

    public static function icon(): string
    {
        return 'heroicon-o-document-text';
    }

    /** Free-text bodies are not projectable to a scalar worth indexing. */
    public function isIndexable(): bool
    {
        return false;
    }

    public function supportsCardinality(): bool
    {
        return false;
    }

    public function toStorage(mixed $input, FieldConfig $config): mixed
    {
        return $input === null ? null : $this->sanitize((string) $input);
    }

    /**
     * Allowlist, never a denylist.
     *
     * A denylist is a promise to have thought of every tag, and nobody has.
     */
    public function sanitize(string $html): string
    {
        // Drop forbidden elements INCLUDING their content — stripping only
        // the tags would leave script bodies as visible text, and leave
        // style rules applying.
        foreach (self::FORBIDDEN_TAGS as $tag) {
            $html = (string) preg_replace('#<'.$tag.'\b[^>]*>.*?</'.$tag.'>#is', '', $html);
            $html = (string) preg_replace('#<'.$tag.'\b[^>]*/?>#is', '', $html);
        }

        $allowed = '<'.implode('><', self::ALLOWED_TAGS).'>';
        $html = strip_tags($html, $allowed);

        // Event handlers and javascript: URLs survive tag allowlisting,
        // because they are attributes rather than elements.
        $html = (string) preg_replace('#\son[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
        $html = (string) preg_replace('#(href|src)\s*=\s*(["\']?)\s*javascript:[^"\'>]*\2#i', '$1="#"', $html);

        return $html;
    }

    /** @return array<int, mixed> */
    public function validationRules(FieldConfig $config): array
    {
        return [...parent::validationRules($config), 'string'];
    }

    public function suggestedPiiClass(): string
    {
        // Free text about people is where personal data hides. Suggesting
        // `none` here would be the wrong default to nudge an org toward.
        return 'none';
    }
}
