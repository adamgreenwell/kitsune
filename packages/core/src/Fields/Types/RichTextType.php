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
use Kitsune\Core\Fields\Internal\BlockDirection;

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
     * The largest value whose sanitised copy is worth holding between a conversion and its loss check.
     *
     * ⚠️ A CAP BECAUSE THE READ IS NOT GUARANTEED. `sanitize()` releases the memo when the loss check
     * asks for it, which retains nothing in the ordinary case — and `toStorage()` is a published
     * contract that `fromApi()` also reaches, so an importer or queue worker can convert with no loss
     * check after it and leave one behind. `FieldTypeRegistry` is a singleton, so "left behind" means
     * for the life of the process.
     *
     * 256 KB caps the worst case at half a megabyte per worker — a rounding error against ADR-027's
     * 1 GB floor — while covering every body a person types. Past it the loss check parses again, which
     * measured 17.9 ms at 786 KB: slower than the memo and far cheaper than retaining 1.5 MB for ever.
     */
    private const MEMO_LIMIT = 262144;

    /**
     * The value `sanitize()` was handed for the conversion in flight, and what it returned.
     *
     * ⚠️ HELD FOR ONE READ AND THEN RELEASED, because `FieldTypeRegistry` is a SINGLETON and this
     * instance therefore lives as long as the application. Review found what the first version cost:
     * under Octane, a queue worker or a long import, caching the last submitted body and its sanitised
     * copy pins two copies of unbounded user HTML across requests — a substantial fraction of
     * ADR-027's 1 GB floor, held for nothing, until another value happens to replace it.
     *
     * ⚠️ AND ONLY A CONVERSION FILLS IT, which is the other half. `sanitize()` is public and §6's
     * contract, so any caller can reach it; a memo populated by every call would leave a body behind
     * whenever nobody came back for it. `castToStorage()` arms it for exactly one conversion, the
     * revision's loss check consumes it, and a `sanitize()` call outside a write caches nothing at
     * all.
     *
     * Keyed on the exact string rather than a digest: a collision would return the wrong sanitised
     * HTML out of a security boundary.
     */
    private ?string $memoInput = null;

    private ?string $memoOutput = null;

    /** Whether the next `sanitize()` is the one a conversion will ask about twice. */
    private bool $memoArmed = false;

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
         * ⚠️ THE COST IS A SECOND PARSE, AND THIS SENTENCE USED TO GET IT WRONG TWICE. It said the
         * value was "bounded by `MAX_LENGTH`" and the cost was "microseconds". Review checked both:
         * `MAX_LENGTH` is `Pattern`'s bound on an authored VALIDATION PATTERN and has nothing to do
         * with a rich text value, `scalarValidationRules()` is `['string']` alone, and `apiSchema()`
         * publishes no length — so the value is unbounded from the API and from an import. Measured
         * on a machine much faster than ADR-027's 1 vCPU floor, one parse-and-walk is 0.8 ms at
         * 31 KB, 3.9 ms at 157 KB and 17.9 ms at 786 KB: linear, and milliseconds rather than
         * microseconds.
         *
         * The separation is still worth a second parse — it is a security boundary against a
         * presentation concern — but it is worth it at a price, not for free, and there is no third
         * parse: `sanitize()` keeps this conversion's answer for the loss check that follows, and
         * releases it as soon as that read happens. See the memo's docblock for why the lifetime is
         * one read rather than "the last value seen": the registry is a singleton, so a cache on this
         * instance is a cache for the life of the process.
         */
        if ($input === null) {
            return null;
        }

        /*
         * ⚠️ ARMS THE MEMO FOR THIS ONE CONVERSION, so `sanitize()` keeps its answer for the loss check
         * that follows and for nothing else. See the memo's own docblock: the registry is a singleton,
         * and a cache filled by every caller of a public method is a body retained for the life of the
         * process.
         */
        $this->memoArmed = true;

        /*
         * ⚠️ THE DIRECTION PASS IS NOT CALLED HERE ANY MORE, and review is the reason. It was a private
         * method on this class, so a module registering another type that returns `Control::RichText`
         * got no per-block direction at all — which is precisely what ADR-029 says is inexpressible.
         * `Entry` applies it to every value whose control's `ValueDirection` is `PerBlock`, from a
         * private method that no field type can override or decline.
         *
         * Sanitising stays here because it is this type's own security boundary, and the two steps are
         * still two steps for the reason above.
         */
        return $this->sanitize((string) $input);
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
            // ⚠️ DISARMS ON THE WAY OUT. An empty value needs no memo, and leaving the arming set
            // would hand it to whichever unrelated caller sanitised next — which is the retention this
            // arming exists to prevent, moved one call along.
            $this->memoArmed = false;

            return $html;
        }

        /*
         * ⚠️ THE SAME VALUE WAS PARSED THREE TIMES PER WRITE, which review found. `castToStorage()`
         * sanitises and then stamps directions — two parses, and that second one is argued for above
         * — and then `Entry` asks whether sanitising REMOVED anything, which called this again with
         * the same string. Measured, one parse-and-walk is 17.9 ms at 786 KB on a machine much faster
         * than ADR-027's floor, and the value is unbounded: `scalarValidationRules()` is `['string']`.
         *
         * ⚠️ MEMOISED HERE RATHER THAN ANSWERED ELSEWHERE, because the alternative was a method
         * saying "did the last sanitise lose anything" — new public surface on a field type, which is
         * the thing the two previous rounds of this branch were about. A memo inside the method has
         * no surface at all and serves every caller that repeats itself.
         *
         * ⚠️ KEYED ON THE EXACT STRING, not on a hash. A collision would return the wrong sanitised
         * HTML from a security boundary; the retention is one value that is already in memory for the
         * duration of the write, and one entry is all the access pattern needs.
         */
        if ($html === $this->memoInput) {
            $output = (string) $this->memoOutput;

            // ⚠️ RELEASED ON READ. The write asks twice and never a third time, so holding it beyond
            // the second answer is holding it for nobody.
            $this->memoInput = null;
            $this->memoOutput = null;

            /*
             * ⚠️ AND DISARMED, which review found missing — releasing the strings while leaving the memo
             * armed hands the retention to the NEXT call instead of ending it. The sequence is a
             * standalone `toStorage()` leaving a memo behind, then an ordinary `Entry` write of the same
             * HTML: `castToStorage()` rearms, this branch serves the old entry and clears it, and the
             * loss check then finds nothing cached, reparses, and — still armed — caches again. Measured
             * on exactly that sequence, both properties were populated when the write finished.
             *
             * One arming is one answer. This branch IS that answer, so the arming has been spent.
             */
            $this->memoArmed = false;

            return $output;
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

        $clean = BlockDirection::serialize($document);

        /*
         * ⚠️ AND CAPPED, because "released on read" only bounds the case where the read HAPPENS. Review
         * found the case where it does not: `toStorage()` is the published contract and
         * `BaseFieldType::fromApi()` delegates to it, so an importer or a queue worker can convert a
         * body with no revision loss check after it — measured, 42 KB left on the singleton by one
         * standalone conversion, and it would have been megabytes for a large body.
         *
         * A cap is the honest trade rather than a guess: below it a conversion saves a parse and the
         * worst case is `2 × MEMO_LIMIT` per worker; above it the loss check parses again, which is
         * 17.9 ms at 786 KB and the price of not retaining 1.5 MB indefinitely. `MEMO_LIMIT` puts that
         * boundary where the retention is a rounding error against ADR-027's 1 GB floor.
         */
        /*
         * ⚠️ CLEARED BEFORE THE DECISION, NOT ONLY OVERWRITTEN BY IT, and review found the gap between
         * those. A conversion that reaches this point missed the cache, so whatever is held describes a
         * value nobody is asking about any more — and the condition below declines to replace it when
         * the new body is over the cap or the memo is unarmed. Measured: a standalone conversion cached
         * a 53-byte body, an ordinary write of a DIFFERENT body above the cap followed, and both its
         * parses left the first one in place. It would have stayed for the worker's lifetime.
         *
         * A release is not conditional on having something to put in its place.
         */
        $this->memoInput = null;
        $this->memoOutput = null;

        if ($this->memoArmed && mb_strlen($html) <= self::MEMO_LIMIT) {
            $this->memoInput = $html;
            $this->memoOutput = $clean;
        }

        // Disarmed either way, so an abandoned arming cannot be handed to a later caller.
        $this->memoArmed = false;

        return $clean;
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
