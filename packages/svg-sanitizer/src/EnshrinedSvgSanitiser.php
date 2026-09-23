<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\SvgSanitizer;

use DOMDocument;
use DOMElement;
use DOMText;
use enshrined\svgSanitize\Sanitizer;
use Kitsune\Core\Media\SanitisesSvg;
use RuntimeException;
use Throwable;

/**
 * `enshrined/svg-sanitize` behind core's seam — ADR-041.
 *
 * ⚠️ THIS ADAPTER IS THE WHOLE REASON THE MODULE EXISTS, AND IT IS DELIBERATELY THIN. Core must not hold a
 * GPL-2.0-or-later dependency (see `SanitisesSvg`), and the value ADR-041 was buying is the library's
 * population of people who find SVG bypasses — not anything this class could add. Every line of cleverness
 * here is a line the library's maintainers and their CVE reporters are not reviewing.
 *
 * ⚠️ THE LIBRARY'S OWN ADVISORY HISTORY IS WHY THE VERSION FLOOR IS `^1.0`. **Seven** published advisories
 * (counted from the repository's own security-advisories API, none withdrawn), and every one of them is a
 * bypass of its own filter rather than a memory or dependency bug — `xlink:HrEf` skipping a lower-case-only
 * check, a DTD entity expanding to `#` so `href="#javascript:…"` read safe, an unquoted `url()` slipping
 * external-reference removal five years after the community patch that fixed it. 1.0.0 (2026-09-01) closed
 * four at once. A floor below it ships known bypasses.
 */
final class EnshrinedSvgSanitiser implements SanitisesSvg
{
    /**
     * How many `<use>` elements a document may carry, and how deep they may nest.
     *
     * ⚠️ THESE ARE A DENIAL-OF-SERVICE GUARD, MEASURED, AND THE LIBRARY'S DEFAULTS ARE NOT SAFE AT ADR-027's
     * FLOOR. `Subject::hasInfiniteLoop()` walks the `<use>` reference graph with an un-memoised depth-first
     * search bounded only by the nesting limit, so a branching graph costs roughly (width ^ depth) paths.
     * Measured on this library at 1.0.0, PHP 8.4:
     *
     *   default limit 15, 9,405-byte file (width 6, depth 10) ...... 25.5 s of CPU
     *   limit 5,        169,587-byte file (width 40, depth 5) ...... 29.0 s of CPU
     *   limit 6 + 200-use cap, worst shape found in a sweep ........  0.022 s
     *
     * A ten-kilobyte upload that burns a core for twenty-five seconds is a denial of service against an
     * operator running on one vCPU, and it is reachable by any account that may upload a logo. Lowering the
     * nesting limit alone does not fix it — the attacker widens the graph instead, which is what the second
     * row is — and removing `<use>` from the tag allowlist does not either, because the reference graph is
     * built from the parsed document BEFORE the allowlist is applied. Measured: 14.9 s with `use` removed.
     *
     * So both levers are used together, and the cap is applied to the raw bytes before the library parses
     * anything. Real artwork is nowhere near either bound: an icon sheet reuses a handful of symbols two or
     * three deep.
     */
    public const MAX_USE_ELEMENTS = 200;

    public const USE_NESTING_LIMIT = 6;

    /** `<use`, with or without a namespace prefix — `<use>`, `<s:use>`, `<svg:use />`. */
    private const USE_ELEMENT = '/<(?:[A-Za-z_][\w.-]*:)?use[\s\/>]/i';

    /*
     * ────────────────  What counts as painting, for the emptiness check  ────────────────
     *
     * ⚠️ AN ALLOWLIST OF WHAT PAINTS, NOT A LIST OF WHAT DOES NOT, and five review rounds are why. The check
     * began as "is there a child element?", then grew a denylist of wrappers, then of descriptions, then of
     * definitions, and each round found one more element that painted nothing and fell through to the
     * default — which was DRAWABLE. The fifth was `<view>`: allowed by the library, non-rendering, in neither
     * list, so `<view><script>…</script></view>` lost its script and stored as a blank success. That is the
     * denylist failure `MediaIntake` names in its own docblock — *"a denylist is a list of the attacks
     * somebody thought of"* — reproduced one layer down, in the method that exists to catch a stripped upload.
     *
     * So the default is inverted. An element counts only if it is on one of the four lists below; anything
     * else, including a tag nobody has classified, paints nothing. A mistake now makes a blank upload
     * REFUSED, which is visible and explicable, instead of ACCEPTED, which is silent.
     *
     * ⚠️ EVERY ENTRY WAS MEASURED, by rasterising in Chromium and counting painted pixels against a control
     * rect at 400: shapes, an `<image>` holding a data URI, a `<use>` of a defs rect and a nested `<svg>`
     * holding a rect all paint 400; `view`, a bare `<use>`, a bare `<image>`, `tref`, `altGlyph`, a lone
     * animation element, a lone `stop`, and a rect INSIDE a `view` all paint 0. The last one is why an
     * unclassified element is not descended into either.
     */

    /** Graphics elements that paint where they sit. Their geometry is the renderer's business, not this one's. */
    public const PAINTS = ['rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'path'];

    /**
     * Graphics elements that paint something they point at, so they count only while they still point.
     *
     * ⚠️ THIS IS THE SHELL THE CONTRACT EXISTS FOR, and it was the next finding waiting. The library does not
     * remove a `<use>` or `<image>` whose `href` is hostile — it removes the `href`. Measured:
     * `javascript:`, a remote URL and an off-document fragment all leave a bare `<use></use>` or
     * `<image></image>`, which paints nothing. So the element counts only while a reference survives.
     *
     * ⚠️ AND THE REFERENCE IS NOT FOLLOWED, deliberately. Asking whether `#a` exists and whether IT draws
     * anything means walking the reference graph, and that walk is the denial of service `MAX_USE_ELEMENTS`
     * exists to bound. A refusal check must not reopen the attack a resource guard just closed.
     */
    public const PAINTS_A_REFERENCE = ['use', 'image'];

    /**
     * The only elements whose character data SVG paints.
     *
     * ⚠️ TEXT OUTSIDE THESE IS NOT DRAWN. `<svg>hello</svg>` and `<svg><g>hello</g></svg>` paint 0 pixels
     * against 315 for the same word inside `<text>`, and an EMPTY `<text>` paints 0 too, so a text element
     * counts only when it carries characters. `tref` and `altGlyph` are NOT here: both are gone from SVG 2
     * and Chromium paints 0 for each, even with text inside `altGlyph`.
     */
    public const TEXT_CONTENT = ['text', 'tspan', 'textpath'];

    /**
     * Elements that paint nothing themselves but whose children are painted where they sit.
     *
     * A nested `<svg>` is one — measured at 400 with a rect inside it — which the earlier version of this
     * list left out, so a logo wrapped in an inner viewport would have been refused.
     */
    public const CONTAINERS = ['g', 'a', 'switch', 'svg'];

    /**
     * Every other tag the library allows, each considered and found to paint nothing where it sits.
     *
     * ⚠️ THE CODE NEVER CONSULTS THIS LIST. An element outside the four lists above paints nothing, whether it
     * is here or not, so a mistake here cannot make a blank document pass. The list exists so that
     * `SvgSanitizerModuleTest` can prove every tag the library allows was LOOKED AT by a person: if a future
     * release adds a tag, that test fails until someone decides which list it belongs on, rather than the
     * tag quietly defaulting to anything. `TagsWithoutStyle::getTags()` re-derives the allowlist at call time
     * on purpose, so this is the one place a library change is noticed.
     *
     * The families and why each paints nothing here: descriptions (`title`, `desc`, `metadata`) are
     * tooltips and accessible names; definitions (`defs`, `symbol`, `marker`, `pattern`, `clipPath`, `mask`,
     * gradients and their `stop`s, `filter` and every `fe*` primitive) draw only where something references
     * them; animation (`animate*`, `mpath`) changes other elements and draws nothing itself; `view` sets a
     * viewport; and the SVG 1.1 font and glyph elements, `tref` and `altGlyph` are gone from SVG 2 and
     * Chromium paints none of them. `style` and `script` are listed for completeness, though neither
     * survives sanitising.
     */
    public const DRAWS_NOTHING = [
        'title', 'desc', 'metadata', 'style', 'script',
        'defs', 'symbol', 'marker', 'pattern', 'clippath', 'mask',
        'lineargradient', 'radialgradient', 'stop', 'filter',
        'feblend', 'fecolormatrix', 'fecomponenttransfer', 'fecomposite', 'feconvolvematrix',
        'fediffuselighting', 'fedisplacementmap', 'fedistantlight', 'feflood', 'fefunca', 'fefuncb',
        'fefuncg', 'fefuncr', 'fegaussianblur', 'femerge', 'femergenode', 'femorphology', 'feoffset',
        'fepointlight', 'fespecularlighting', 'fespotlight', 'fetile', 'feturbulence',
        'animatecolor', 'animatemotion', 'animatetransform', 'mpath',
        'view',
        'font', 'glyph', 'glyphref', 'hkern', 'vkern', 'altglyph', 'altglyphdef', 'altglyphitem', 'tref',
    ];

    /** Where text inside a text element is still painted: nested runs, paths, and links within the text. */
    private const TEXT_DESCENDS_INTO = ['tspan', 'textpath', 'a'];

    /**
     * ⚠️ A FRESH `Sanitizer` PER CALL, NOT A SHARED ONE. It carries parser state — the `<use>` nesting graph
     * and the issue list it accumulates — and the advisory that fixed the nesting-DoS check turned on the
     * ORDER in which that graph was built relative to attribute normalisation. A long-lived instance shared
     * across uploads in an Octane worker is state one upload can leave behind for the next, in the one class
     * where that matters most.
     */
    public function sanitise(string $svg): string
    {
        if (trim($svg) === '') {
            throw new RuntimeException('Refusing an empty SVG: there is nothing to sanitise and nothing to draw.');
        }

        self::refuseIfTooManyReferences($svg);

        $sanitizer = new Sanitizer;

        /*
         * ⚠️ NEITHER OF THESE IS THE DEFAULT. `removeRemoteReferences` strips references that reach off-site,
         * which is what stops a stored SVG phoning home from every page that renders it — a tracking pixel
         * with extra steps, and a live target for whoever owns that domain later. Measured at 1.0.0: it also
         * removes `@import url('https://…')` from CSS, which is the half of the CSS problem it can solve.
         *
         * `minify` re-serialises through the library's own writer, which is what removes the DOCTYPE that
         * the entity-collision advisory (GHSA-9rjx-3jch-6vjf) turned on: a document that keeps its DTD is one
         * where the parser and the browser can be made to disagree about what a reference expands to.
         */
        $sanitizer->removeRemoteReferences(true);
        $sanitizer->minify(true);
        $sanitizer->setAllowedTags(new TagsWithoutStyle);
        $sanitizer->setUseNestingLimit(self::USE_NESTING_LIMIT);

        /*
         * ⚠️ THE LIBRARY THROWS, AND NOT ONLY `RuntimeException` — measured: `<a/>` raises a bare
         * `LogicException("Got 0 svg elements, expected exactly one")`. `SanitisesSvg` documents
         * `@throws RuntimeException`, and a caller that catches what the contract names would let a
         * `LogicException` past as an unhandled 500 rather than a refusal. Wrapping is what makes the seam's
         * promise true rather than aspirational.
         */
        try {
            $clean = $sanitizer->sanitize($svg);
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Refusing this SVG: the sanitiser could not process it — '.$e->getMessage().'. Nothing was stored.',
                previous: $e,
            );
        }

        /*
         * ⚠️ `false` MEANS THE PARSE FAILED, AND IT IS A REFUSAL RATHER THAN AN EMPTY FILE. The library
         * returns `false` when libxml cannot make a document of the input at all — which is what a renamed
         * archive, a truncated upload, or an entity bomb looks like from here. Measured: an XXE payload and a
         * billion-laughs payload both land on this branch rather than expanding.
         */
        if ($clean === false) {
            throw new RuntimeException(
                'Refusing this SVG: it could not be parsed as XML, so nothing can vouch for what a browser '
                .'would make of it. Nothing was stored.'
            );
        }

        self::refuseIfNothingIsLeft($clean);

        return $clean;
    }

    /**
     * Refuse a document carrying more `<use>` elements than the reference walk can afford.
     *
     * ⚠️ COUNTED ON THE RAW BYTES, BEFORE ANY PARSING, WHICH IS THE ONLY PLACE IT HELPS. The cost this
     * bounds is incurred inside the library's own parse-and-walk, so a check that needed a DOM first would
     * pay the bill it is trying to avoid. `substr_count` on a string is not a security decision and makes no
     * claim about what the document means — it is a resource guard, and it is deliberately cruder than
     * anything that tries to understand the markup.
     *
     * ⚠️ IT MATCHES THE LOCAL NAME, SO A NAMESPACE PREFIX DOES NOT SLIP PAST IT — review found the literal
     * `substr_count($svg, '<use')` it used to be. An uploader may bind a second prefix to the SVG namespace
     * and write every reference as `<s:use>`, which counts zero against the literal. Measured, that file is
     * currently harmless: this library matches its allowlist on the PREFIXED name, so it strips `<s:use>`
     * outright and never walks it — 0.00s against a payload that costs 25s unprefixed. But the guard should
     * not depend on that, because the thing it depends on is a third-party allowlist this package
     * deliberately re-derives at call time; a release that matched on local name instead would restore the
     * denial of service with no change here.
     *
     * ⚠️ IT OVER-COUNTS ON PURPOSE. The pattern also matches inside a comment or a text node, so a document
     * that merely talks about `<use>` can be refused. That direction is the safe one: the alternative is
     * parsing to find out, which is the work being bounded.
     *
     * @throws RuntimeException
     */
    private static function refuseIfTooManyReferences(string $svg): void
    {
        $references = preg_match_all(self::USE_ELEMENT, $svg);

        if ($references <= self::MAX_USE_ELEMENTS) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing this SVG: it carries %d `<use>` references and the ceiling is %d. Resolving a large '
            .'reference graph costs more CPU than an upload is allowed to spend — a ten-kilobyte file has '
            .'been measured at twenty-five seconds — so it is refused rather than sanitised. Artwork that '
            .'genuinely needs this many instances should be flattened by its exporter.',
            $references,
            self::MAX_USE_ELEMENTS,
        ));
    }

    /**
     * Refuse a document that sanitised down to no drawable content.
     *
     * ⚠️ THE FIRST VERSION OF THIS CHECKED `str_contains($clean, '<svg')` AND THAT IS WHY THIS METHOD EXISTS.
     * An SVG whose entire content was a `<script>` element comes back as `<svg xmlns="…"></svg>` — a
     * well-formed, technically safe, completely blank image that contains the string `<svg>` and passed. The
     * docblock claimed a refusal the code did not make, which is a shape this project has recorded before,
     * and it is worse than having no check: a reader believes it.
     *
     * So the result is parsed and asked whether anything survived. A blank image stored as a successful
     * upload is a broken asset an operator cannot explain, and the upload it came from was almost certainly
     * hostile.
     *
     * ⚠️ THE QUESTION IS STRUCTURAL — "DID AN ELEMENT THAT PAINTS SURVIVE?" — AND IT STOPS THERE ON PURPOSE.
     * It is not "will this render pixels?", which only a renderer can answer. Five review rounds each found
     * one more blank shape the structural check accepted: an empty wrapper, a lone `<title>`, content inside
     * `<defs>`, bare character data, a surviving `<view>`. Each was a structural gap, and the fifth showed
     * they had a common cause — the check defaulted to "drawable" — so the default is now inverted and the
     * painting elements are an allowlist (see `PAINTS`). But rasterising in
     * Chromium also shows `<circle r="0"/>` and `<rect display="none"/>` painting 0 pixels, and behind those
     * sit `visibility`, `opacity`, `fill="none"` with no stroke, geometry placed off the canvas, and every
     * CSS rule that can reach any of them. Refusing those means building a style cascade and a geometry
     * engine into a refusal check, which is a renderer with fewer tests. So they are ACCEPTED, and a test
     * pins that, so the next review measures the contract against what it says rather than against pixels.
     *
     * The limit costs little, because of what this refusal is for. The failure it catches is a hostile
     * upload whose executable content was stripped, leaving a shell that stores as a success, and that is
     * always a structural shape: the parts that were removed are gone from the tree, not hidden in it. A file
     * built to be blank through its geometry was never going to execute anything.
     *
     * @throws RuntimeException
     */
    private static function refuseIfNothingIsLeft(string $clean): void
    {
        $document = new DOMDocument;

        /*
         * ⚠️ `LIBXML_NONET`, THOUGH THESE BYTES HAVE ALREADY BEEN SANITISED. This parse exists to count what
         * survived, and a parser that could be talked into a network fetch while counting would be a second
         * attack surface opened to close a first. Entity substitution is left off, which is libxml's default.
         */
        $loaded = @$document->loadXML($clean, LIBXML_NONET);

        if ($loaded === false || ! $document->documentElement instanceof DOMElement) {
            throw new RuntimeException(
                'Refusing this SVG: the sanitised result is not a document. Nothing was stored.'
            );
        }

        if (self::drawsSomething($document->documentElement)) {
            return;
        }

        throw new RuntimeException(
            'Refusing this SVG: sanitising left nothing that draws, so everything it carried was either '
            .'removed or an empty wrapper. That is a blank image rather than the one that was uploaded, so '
            .'it is refused rather than stored as a success.'
        );
    }

    /**
     * Does an element that paints survive anywhere under this one?
     *
     * ⚠️ EACH BRANCH IS AN ALLOWLIST, AND FALLING THROUGH ALL OF THEM MEANS "NOTHING HERE". A shape settles it;
     * a reference element settles it while it still points somewhere; a text element settles it while it
     * carries characters; a container is descended into. Anything else — a description, a definition, an
     * animation, a `view`, or a tag no list names — is skipped WITHOUT descending, because its children are
     * not painted where they sit either (measured: a rect inside a `view` paints 0 pixels).
     *
     * Character data sitting directly in the root or a container is not counted at all: SVG paints text only
     * inside `TEXT_CONTENT`.
     */
    private static function drawsSomething(DOMElement $element): bool
    {
        foreach ($element->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $name = strtolower($child->localName ?? '');

            if (in_array($name, self::PAINTS, true)) {
                return true;
            }

            if (in_array($name, self::PAINTS_A_REFERENCE, true) && self::carriesReference($child)) {
                return true;
            }

            if (in_array($name, self::TEXT_CONTENT, true) && self::carriesText($child)) {
                return true;
            }

            if (in_array($name, self::CONTAINERS, true) && self::drawsSomething($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does this `<use>` or `<image>` still point at something?
     *
     * Both spellings are read: SVG 2's plain `href` and SVG 1.1's `xlink:href`. The xlink one is read by
     * namespace rather than by prefix, because the prefix is whatever the document bound it to.
     */
    private static function carriesReference(DOMElement $element): bool
    {
        $href = $element->getAttribute('href');

        if (trim($href) === '') {
            $href = $element->getAttributeNS('http://www.w3.org/1999/xlink', 'href');
        }

        return trim($href) !== '';
    }

    /**
     * Does this text element hold any character data that would be painted?
     *
     * ⚠️ IT DESCENDS ONLY WHERE TEXT IS STILL PAINTED — `TEXT_DESCENDS_INTO` — and skips everything else, the
     * same allowlist shape as `drawsSomething()`. So `<text><title>hi</title></text>` does not count (a title
     * inside a text element is still a tooltip) and neither does text inside `altGlyph` (Chromium paints 0).
     * `DOMCdataSection` extends `DOMText`, so `<text><![CDATA[hi]]></text>` counts, and a comment is
     * neither, so it does not.
     */
    private static function carriesText(DOMElement $element): bool
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMText && trim($child->textContent) !== '') {
                return true;
            }

            if ($child instanceof DOMElement
                && in_array(strtolower($child->localName ?? ''), self::TEXT_DESCENDS_INTO, true)
                && self::carriesText($child)) {
                return true;
            }
        }

        return false;
    }
}
