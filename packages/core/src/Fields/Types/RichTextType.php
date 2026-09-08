<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Fields\Types;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMProcessingInstruction;
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

    /**
     * The only attributes that survive, on any tag.
     *
     * ⚠️ Absent by design: `style`, `class` and `id`. `strip_tags()` keeps
     * every attribute on an allowed tag, and removing event handlers alone
     * left `style` intact — so an allowed `<a>` carrying
     * `position:fixed;inset:0;z-index:9999` covers a public page with an
     * attacker-controlled link. Same reasoning as the tag list: an allowlist,
     * because a denylist is a promise to have thought of every case.
     */
    public const ALLOWED_ATTRIBUTES = [
        'href', 'src', 'alt', 'title', 'colspan', 'rowspan', 'width', 'height', 'lang', 'dir',
    ];

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

    protected function castToStorage(mixed $input, FieldConfig $config): mixed
    {
        return $input === null ? null : $this->sanitize((string) $input);
    }

    /**
     * Allowlist, never a denylist — and PARSED, never pattern-matched.
     *
     * ⚠️ This was a chain of regular expressions and it had a hole exactly
     * where regular expressions always have one: a `<` inside an UNQUOTED
     * attribute value. Browsers treat that as a parse error and carry on;
     * `strip_tags()` leaves it alone; and the attribute-allowlisting pattern
     * required the attribute section to contain no `<`, so the tag matched
     * nothing and NO attribute was inspected. `<img src=x alt=< onerror=alert(1)>`
     * came back byte-for-byte unchanged — stored XSS on any public page — as
     * did the full-page overlay the ALLOWED_ATTRIBUTES docblock says is closed.
     *
     * The suite was green because every attribute in it was quoted.
     *
     * A pattern that can desynchronise from the parser IS a denylist: it
     * promises to have thought of every way markup can be written. So the
     * document is parsed and REBUILT — nothing survives unless it was
     * recognised as an allowed element with an allowed attribute.
     */
    public function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return $html;
        }

        $document = new DOMDocument;

        // libxml complains about HTML5 elements and about the malformed markup
        // that is the whole point of sanitising, so its errors are collected
        // rather than emitted. The parse still yields a tree.
        $previous = libxml_use_internal_errors(true);

        // The meta charset, not a `<?xml` declaration: the latter is echoed
        // into the output as a processing instruction on some libxml builds.
        // LIBXML_HTML_NOIMPLIED with an explicit wrapper keeps libxml from
        // inventing <html><body>, which would then need stripping back off.
        $document->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8"><div id="kitsune-root">'.$html.'</div>',
            LIBXML_HTML_NODEFDTD,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('kitsune-root');

        if ($root === null) {
            // Nothing parseable. Returning the escaped input rather than the
            // input keeps the fail-closed posture: unparseable markup is not
            // markup we can vouch for.
            return htmlspecialchars($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $this->clean($root);

        $out = '';

        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $document->saveHTML($child);
        }

        return $out;
    }

    /**
     * Walk the tree, removing what is not allowed.
     *
     * Three outcomes per element, matching what the regex chain intended:
     * a FORBIDDEN element goes with its content, because stripping the tag
     * alone leaves a script body as visible text and leaves style rules
     * applying; an element that is merely not allowed is UNWRAPPED, so its
     * text survives; an allowed element keeps only allowed attributes.
     */
    private function clean(DOMNode $node): void
    {
        // A snapshot, because unwrapping and removing mutate the live list.
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $name = strtolower($child->nodeName);

                if (in_array($name, self::FORBIDDEN_TAGS, true)) {
                    $child->parentNode?->removeChild($child);

                    continue;
                }

                if (! in_array($name, self::ALLOWED_TAGS, true)) {
                    $this->clean($child);
                    $this->unwrap($child);

                    continue;
                }

                $this->cleanAttributes($child);
                $this->clean($child);

                continue;
            }

            // Comments can carry markup that a browser revives in some
            // contexts, and they are never content anyone asked to keep.
            if ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction) {
                $child->parentNode?->removeChild($child);
            }
        }
    }

    /** Replace an element with its own children, in place. */
    private function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        foreach (iterator_to_array($element->childNodes) as $child) {
            $parent->insertBefore($child, $element);
        }

        $parent->removeChild($element);
    }

    private function cleanAttributes(DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $name = strtolower($attribute->nodeName);

            if (! in_array($name, self::ALLOWED_ATTRIBUTES, true)) {
                $element->removeAttribute($attribute->nodeName);

                continue;
            }

            // ⚠️ Checked on the PARSED value. The old pattern read the raw
            // attribute text, so anything that confused it about where the
            // value ended was never checked at all.
            if (($name === 'href' || $name === 'src') && ! $this->isSafeUrl($attribute->nodeValue ?? '')) {
                $element->setAttribute($name, '#');
            }
        }
    }

    /**
     * ⚠️ Allowlist the SCHEME. Matching `javascript:` is not enough.
     *
     * Every one of these walked past a literal-string rule while still
     * executing in a browser:
     *
     * - `java&#x73;cript:` and `&#106;avascript:` — HTML entities are decoded
     *   before the URL is parsed
     * - `java\tscript:` — tabs, newlines and carriage returns are stripped
     *   from a URL during parsing
     * - `vbscript:` — not `javascript:`, and still script
     * - `data:text/html;base64,…` — a whole document, same origin
     *
     * A denylist here is the same mistake the tag handling deliberately
     * avoids. So the URL is decoded and normalised first, and then it must BE
     * one of the safe forms.
     */
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
    protected function scalarValidationRules(FieldConfig $config): array
    {
        return ['string'];
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
