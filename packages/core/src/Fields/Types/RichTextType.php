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

    /**
     * The only schemes a link or an embedded resource may use.
     *
     * An allowlist, matching how tags are handled. `javascript:`, `vbscript:`
     * and `data:` are absent because they execute; they are not listed as
     * forbidden anywhere, because a denylist is a promise to have thought of
     * every case and nobody has.
     */
    public const ALLOWED_URL_SCHEMES = ['http', 'https', 'mailto', 'tel', 'ftp'];

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

        // Event handlers survive tag allowlisting, because they are
        // attributes rather than elements.
        $html = (string) preg_replace('#\son[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);

        return $this->allowlistUrlSchemes($html);
    }

    /**
     * ⚠️ Allowlist the SCHEME. Matching `javascript:` is not enough.
     *
     * The previous rule looked for the literal string, and every one of these
     * walked past it while still executing in a browser:
     *
     * - `java&#x73;cript:` and `&#106;avascript:` — HTML entities are decoded
     *   before the URL is parsed
     * - `java\tscript:` — tabs, newlines and carriage returns are stripped
     *   from a URL during parsing
     * - `vbscript:` — not `javascript:`, and still script
     * - `data:text/html;base64,…` — a whole document, same origin
     *
     * A denylist here is the same mistake the tag handling deliberately
     * avoids: a promise to have thought of every case. So the URL is decoded
     * and normalised first, and then it must BE one of the safe forms.
     */
    private function allowlistUrlSchemes(string $html): string
    {
        return (string) preg_replace_callback(
            '#\b(href|src)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))#i',
            function (array $m): string {
                $attribute = strtolower($m[1]);
                $url = $m[2] !== '' ? $m[2] : ($m[3] ?? '');
                $url = $url !== '' ? $url : ($m[4] ?? '');

                return $this->isSafeUrl($url) ? $m[0] : $attribute.'="#"';
            },
            $html,
        );
    }

    private function isSafeUrl(string $url): bool
    {
        // Decode first — the browser will. Twice, because `&amp;#x73;`
        // survives one pass and a browser tolerates it.
        $decoded = html_entity_decode(html_entity_decode($url, ENT_QUOTES | ENT_HTML5), ENT_QUOTES | ENT_HTML5);

        // Then strip what a URL parser ignores: tabs, newlines, carriage
        // returns anywhere, and leading control characters and whitespace.
        $normalised = (string) preg_replace('#[\x00-\x20]+#', '', $decoded);

        if ($normalised === '') {
            return true;
        }

        // Relative, root-relative, anchor and protocol-relative URLs carry no
        // scheme, so there is nothing to allow or forbid.
        if (! preg_match('#^([a-z][a-z0-9+.-]*):#i', $normalised, $scheme)) {
            return true;
        }

        return in_array(strtolower($scheme[1]), self::ALLOWED_URL_SCHEMES, true);
    }

    /** @return array<int, mixed> */
    public function validationRules(FieldConfig $config): array
    {
        return [...parent::validationRules($config), 'string'];
    }

    public function suggestedPiiClass(): string
    {
        // Free text about people is where personal data hides, and the org
        // still confirms it (ADR-020). This said exactly that and then
        // returned `none`, which would have nudged every rich text field out
        // of subject-access and erasure handling.
        return 'personal';
    }
}
