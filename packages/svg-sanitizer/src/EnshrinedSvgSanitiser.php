<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\SvgSanitizer;

use DOMDocument;
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
     * ⚠️ IT OVER-COUNTS ON PURPOSE. `<use` also matches inside a comment or a text node, so a document that
     * merely talks about `<use>` can be refused. That direction is the safe one: the alternative is parsing
     * to find out, which is the work being bounded.
     *
     * @throws RuntimeException
     */
    private static function refuseIfTooManyReferences(string $svg): void
    {
        $references = substr_count($svg, '<use');

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

        if ($loaded === false || ! $document->documentElement instanceof \DOMElement) {
            throw new RuntimeException(
                'Refusing this SVG: the sanitised result is not a document. Nothing was stored.'
            );
        }

        foreach ($document->documentElement->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                return;
            }

            if ($child instanceof \DOMText && trim($child->textContent) !== '') {
                return;
            }
        }

        throw new RuntimeException(
            'Refusing this SVG: sanitising left an empty document, so everything it carried was something the '
            .'sanitiser removes. That is a blank image rather than the one that was uploaded, so it is '
            .'refused rather than stored as a success.'
        );
    }
}
