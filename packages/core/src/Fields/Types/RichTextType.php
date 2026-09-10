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
use Kitsune\Core\Fields\Control;
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

    /**
     * The tags that hold a run of text, and therefore have a direction of their own.
     *
     * ⚠️ `ul`, `ol` and `figure` are absent on purpose: they contain blocks rather than text, so a
     * direction on them would be inherited by children that should each resolve their own. `li` and
     * `figcaption` are the text-bearing halves of those pairs and are here.
     *
     * ⚠️ `br` and the inline tags are absent for the opposite reason — `dir` on a fragment of a
     * sentence resolves from a fragment, which is how you get one clause of a paragraph pointing the
     * wrong way.
     */
    /**
     * The only values that establish a direction.
     *
     * ⚠️ `dir` IS ALLOWED AND ITS VALUE WAS NEVER CHECKED, which review found: `<p dir="">` and
     * `<p dir="banana">` survive sanitising, and an attribute that establishes nothing was still
     * enough to block the stamp. A malformed value is not a choice to respect.
     */
    public const DIRECTIONS = ['ltr', 'rtl', 'auto'];

    public const BLOCK_TAGS = [
        'p', 'li', 'h2', 'h3', 'h4', 'blockquote', 'pre', 'figcaption',
    ];

    /**
     * Tags that are blocks at the top level, so a run beside one is a separate run.
     *
     * ⚠️ `ul`, `ol` and `figure` are here although they are NOT in `BLOCK_TAGS`: they carry no
     * direction of their own — their children each resolve one — but they are still blocks, so text
     * before and after a list is two runs rather than one.
     */
    public const CONTAINER_TAGS = [
        'p', 'li', 'h2', 'h3', 'h4', 'blockquote', 'pre', 'figure', 'figcaption', 'ul', 'ol',
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

    /**
     * The only `PerBlock` control. A single `dir` on the editor would impose one
     * direction on a document that may legitimately hold an Arabic paragraph and an
     * English one — worse than none, because it looks handled.
     *
     * ⚠️ THIS DOCBLOCK USED TO SAY "`dir` is already in ALLOWED_ATTRIBUTES, so per-block direction
     * survives sanitising", and that was true and not enough. Surviving is not the same as
     * existing: nothing PRODUCED a `dir`, so an author writing an Arabic paragraph next to an
     * English one got neither — every block inherited the chrome, which is issue #39's gap G3 in
     * the one place the inventory called awkward. `toStorage()` now stamps `dir="auto"` on each
     * block, so the browser resolves each one from its own first strong character.
     */
    public function control(): Control
    {
        return Control::RichText;
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

    /**
     * ⚠️ The one type whose conversion is LOSSY, so the original is kept.
     *
     * Sanitizing removes markup, and an author who pasted something that lost half
     * its formatting has no way to see what went. field-types.md §6 requires the
     * pre-sanitization original in the revision record — and, explicitly, NOT in
     * `entries.values`, so a restore can never put unsanitized HTML back.
     */
    public function retainsOriginal(): bool
    {
        return true;
    }

    /**
     * Whether SANITISING removed something — not whether the stored bytes differ.
     *
     * ⚠️ THOSE STOPPED BEING THE SAME QUESTION when `castToStorage()` began stamping `dir="auto"`
     * on each block for issue #39. The conversion now ADDS as well as removes, so the recorder's old
     * test — stored `!==` submitted — was true for every rich text save, and every revision retained
     * a pre-sanitisation original that was identical to the input apart from an attribute this class
     * had just added. Caught by a test asserting that clean input keeps no original.
     *
     * The question §6 actually asks is "can the author see what the sanitiser took", so this
     * compares the SANITISED value with what was submitted and ignores the direction step entirely.
     */
    public function conversionLostSomething(mixed $submitted, mixed $stored): bool
    {
        if (! is_string($submitted)) {
            return $stored !== $submitted;
        }

        return $this->sanitize($submitted) !== $submitted;
    }

    protected function castToStorage(mixed $input, FieldConfig $config): mixed
    {
        /*
         * ⚠️ TWO STEPS, AND THEY ARE TWO STEPS ON PURPOSE. The first version of this stamped the
         * direction inside `sanitize()`'s attribute walk, which broke nineteen tests — five of them
         * in `RichTextSecurityTest`, asserting the sanitiser's exact output.
         *
         * That breakage was the design telling me something. `sanitize()` is a security boundary
         * (field-types.md §6) and its output is asserted byte-for-byte precisely because it is one;
         * threading a presentation concern through it means every future direction change edits
         * security expectations, and a reviewer reading that diff cannot tell which half is which.
         * Direction is not safety. It is a second step over a value already known to be safe.
         *
         * The cost is a second parse on write. Rich text writes are rare and bounded by
         * `MAX_LENGTH`, and the separation is worth more than the microseconds.
         */
        if ($input === null) {
            return null;
        }

        return $this->withBlockDirection($this->sanitize((string) $input));
    }

    /**
     * Give every text-bearing block its own `dir="auto"`, so each resolves from its own content.
     *
     * ⚠️ A SINGLE `dir` ON THE FIELD IS WORSE THAN NONE, which is why this is per block. `dir="auto"`
     * on the editor resolves from the FIRST strong directional character in the whole document, so a
     * body that opens in English and continues in Arabic renders every Arabic paragraph
     * left-to-right — and looks handled, which is the failure mode issue #39 exists to stop.
     *
     * ⚠️ ON THE WAY TO STORAGE, not at render time. A value arriving from the API, a seeder or an
     * import gets the same treatment as one typed into the panel, and the stored bytes and the
     * displayed bytes cannot drift apart — which is the kind of difference that survives for years
     * because both halves look right on their own.
     *
     * ⚠️ ONLY WHEN A VALID DIRECTION IS ABSENT. `dir` is in ALLOWED_ATTRIBUTES, so an author who
     * wrote `dir="rtl"` has said something more specific than `auto` — a paragraph of Arabic opening
     * with a Latin brand name, where `auto` would resolve from the brand name and be wrong. This
     * fills a gap rather than overruling a decision.
     *
     * ⚠️ BUT `hasAttribute()` ALONE WAS THE WRONG TEST, which review found. `<p dir="">` and
     * `<p dir="banana">` survive sanitising — `dir` is allowed and its VALUE was never checked — and
     * an attribute that establishes no direction blocked the stamp while doing nothing itself, so an
     * Arabic block still inherited the chrome. Only `ltr`, `rtl` and `auto` are directions; anything
     * else is replaced rather than respected, because it expresses no choice to respect.
     *
     * ⚠️ AND A VALUE WITH NO BLOCK IN IT GOT NOTHING AT ALL, which review also found. `مرحبا` and
     * `<strong>مرحبا</strong>` are shapes the sanitiser deliberately preserves — it refuses to wrap
     * loose text, because reshaping content that arrived as a bare string is data loss of its own —
     * so this loop found no block and added no direction. A top-level run is a paragraph in
     * everything but markup, and it is wrapped in one so that it has somewhere to carry a direction.
     * That is a reshape, and it is confined to the case where the alternative is no direction at all.
     */
    private function withBlockDirection(string $html): string
    {
        if (trim($html) === '') {
            return $html;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        // The same wrapper-and-meta parsing aid `sanitize()` documents at length: the meta declares
        // UTF-8, and the wrapper stops libxml wrapping loose top-level text in an implied `<p>`.
        $document->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8"><div>'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach (self::BLOCK_TAGS as $tag) {
            foreach (iterator_to_array($document->getElementsByTagName($tag)) as $element) {
                // No `instanceof` guard: `getElementsByTagName()` yields elements by definition,
                // and static analysis correctly calls the check dead.
                if (! in_array(strtolower($element->getAttribute('dir')), self::DIRECTIONS, true)) {
                    $element->setAttribute('dir', 'auto');
                }
            }
        }

        /*
         * ⚠️ THE WRAPPER'S CHILDREN, NOT THE DOCUMENT'S, and the first version of this returned an
         * empty string by getting that wrong. Under `LIBXML_HTML_NOIMPLIED` the charset `<meta>`
         * becomes the document ELEMENT and the wrapper `<div>` is not among `$document->childNodes`
         * at all — measured: `childNodes` holds one node, the meta, while
         * `getElementsByTagName('p')` finds both paragraphs.
         *
         * `sanitize()` never notices because `clean()` UNWRAPS the div — `div` is not an allowed tag
         * — which lifts its children to document level on the way past. This method does not clean,
         * so it has to address the wrapper itself.
         *
         * ⚠️ THE FIRST `div` IS RELIABLY THE WRAPPER, and that is an argument rather than a guess:
         * this runs on output that has already been through `sanitize()`, and `div` is not in
         * ALLOWED_TAGS, so no `div` can have survived from the input.
         */
        $wrapper = $document->getElementsByTagName('div')->item(0);

        if ($wrapper === null) {
            return $html;
        }

        $this->wrapLooseRuns($document, $wrapper);

        return $this->serialize($wrapper);
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

        // ⚠️ The wrapper is a PARSING aid, not a boundary, and the first
        // version of this treated it as a boundary.
        //
        // It serialised the wrapper element's children, so a stray `</div>` in
        // the input — ordinary in pasted markup — closed it and every node
        // after landed outside and was silently discarded:
        // `sanitize('hello</div>world')` returned `hello`, and
        // `<p>one</p></div><p>two</p>` lost the second paragraph. Data loss
        // introduced by a sanitiser is worse than the hole it replaced.
        //
        // The whole DOCUMENT is walked now, so nothing can fall outside what
        // is collected. The wrapper needs no special handling on the way out
        // either: `div` is not in ALLOWED_TAGS, so `clean()` unwraps it like
        // any other disallowed element.
        //
        // It is still there because without it libxml wraps loose top-level
        // TEXT in an implied `<p>`, which would silently reshape content that
        // arrived as a bare string. The meta tells the parse the bytes are
        // UTF-8 and is skipped on the way out.
        $document->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8"><div>'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->clean($document);

        return $this->serialize($document);
    }

    /**
     * Wrap top-level text and inline runs in a paragraph, so they can carry a direction.
     *
     * ⚠️ A VALUE NEED NOT CONTAIN A BLOCK, which review found and I had assumed away. `مرحبا` and
     * `<strong>مرحبا</strong>` are shapes `sanitize()` deliberately preserves, and neither has any
     * element that a direction could sit on — so the per-block guarantee did not reach them and they
     * inherited the chrome, which for API and import input is the ordinary case rather than an edge
     * one.
     *
     * ⚠️ THIS RESHAPES, AND THAT IS THE TRADE. `sanitize()` refuses to wrap loose text because
     * reshaping content that arrived as a bare string is data loss of its own — the reason its
     * parsing wrapper exists at all. The alternative here is no direction at all for those values,
     * and a top-level run is a paragraph in everything except markup. So the reshape is confined to
     * exactly the nodes that have nowhere else to carry one, and adjacent runs are collected into ONE
     * paragraph rather than one each, because `a <strong>b</strong> c` is one sentence.
     */
    private function wrapLooseRuns(DOMDocument $document, DOMNode $wrapper): void
    {
        $runs = [];
        $current = [];

        foreach (iterator_to_array($wrapper->childNodes) as $child) {
            $isBlock = $child instanceof DOMElement
                && in_array(strtolower($child->nodeName), self::CONTAINER_TAGS, true);

            if ($isBlock) {
                if ($current !== []) {
                    $runs[] = $current;
                    $current = [];
                }

                continue;
            }

            // Whitespace between blocks is formatting, not a run worth wrapping.
            if ($child->nodeType === XML_TEXT_NODE && trim($child->textContent) === '') {
                continue;
            }

            $current[] = $child;
        }

        if ($current !== []) {
            $runs[] = $current;
        }

        foreach ($runs as $run) {
            $paragraph = $document->createElement('p');
            $paragraph->setAttribute('dir', 'auto');

            $wrapper->insertBefore($paragraph, $run[0]);

            foreach ($run as $node) {
                $paragraph->appendChild($node);
            }
        }
    }

    /**
     * The document back as HTML, without the parsing aids this class adds.
     *
     * ⚠️ EXTRACTED RATHER THAN COPIED. `withBlockDirection()` needs exactly the same skip — the
     * charset `<meta>` is something these methods PREPEND, not content — and a second copy of that
     * rule is a second place for it to be wrong. The `div` wrapper needs no skip here: `sanitize()`
     * unwraps it because `div` is not an allowed tag, and `withBlockDirection()` runs on output that
     * has already been through that.
     */
    private function serialize(DOMNode $parent): string
    {
        $document = $parent instanceof DOMDocument ? $parent : $parent->ownerDocument;

        if ($document === null) {
            return '';
        }

        $out = '';

        foreach (iterator_to_array($parent->childNodes) as $child) {
            if ($child instanceof DOMElement && strtolower($child->nodeName) === 'meta') {
                continue;
            }

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
