<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\SvgSanitizer;

use enshrined\svgSanitize\data\AllowedTags;
use enshrined\svgSanitize\data\TagInterface;

/**
 * The library's own tag allowlist, minus `<style>` — ADR-041.
 *
 * ⚠️ THIS IS A NARROWING OF THE LIBRARY'S ALLOWLIST, NOT A PARSER OF OUR OWN, and the distinction is the one
 * ADR-041 draws. That entry forbids hand-rolling an SVG security parser — "core inventing a security parser
 * for a format whose bypasses are discovered by other people, continuously" — and requires the sanitiser's
 * configuration to live in code rather than in settings. Removing one tag through `setAllowedTags()`, the
 * library's own configuration seam, is the second of those without doing the first.
 *
 * ⚠️ WHY `<style>` SPECIFICALLY, MEASURED RATHER THAN ASSUMED. With the library at 1.0.0 and
 * `removeRemoteReferences(true)`, a remote stylesheet reference is stripped and `@import url('https://…')`
 * is stripped — but `javascript:` inside a CSS `url()` survives in every form tried: quoted, unquoted,
 * upper-cased, and HTML-entity-encoded (the entity is decoded back to the literal on the way out). The
 * neighbouring advisory GHSA-qhmf-972w-m957 — unquoted `url()` values bypassing detection — took five years
 * from the community patch that fixed it to a release that shipped it, which is the maintenance rate this
 * narrowing is really a response to.
 *
 * ⚠️ AND IT CLOSES THE ELEMENT, NOT THE VECTOR. The `style` ATTRIBUTE carries the same CSS and is still
 * allowed, so `<rect style="background:url(javascript:alert(1))"/>` survives. That is left open on purpose:
 * an adversarial pass served this sanitiser's real output to a browser as `image/svg+xml` with no security
 * headers — the public-path threat model — with a positive control that fired on an unsanitised script, and
 * the surviving CSS executed nothing and fetched nothing, because no current browser honours `javascript:`
 * in a CSS `url()`. Removing the inline `style` attribute would break ordinary exported artwork to close an
 * inert vector. ADR-041 records it as a named third departure from `field-types.md` §6 rather than leaving a
 * reader to find it.
 *
 * ⚠️ AND KITSUNE HAS NO COMPENSATING CONTROL ON THAT PATH, WHICH IS WHAT MAKES IT WORTH THE COST. ADR-041
 * rejected "serving SVG unsanitised behind headers" in exactly these words: *"A Content-Security-Policy and
 * `Content-Disposition: attachment` hold only while every delivery path remembers them, and a public CDN URL
 * is exactly where one would not."* A **public** SVG is served off the linked disk by the web server with no
 * PHP in the path — so none of `MediaDelivery`'s headers apply to it, and sanitisation is the only thing
 * standing there. The private path's `default-src 'none'; sandbox` is defence in depth, not the defence.
 *
 * ⚠️ THE COST IS REAL AND IS NOT HIDDEN: an SVG that styled itself through a `<style>` block loses that
 * styling and renders with its presentation attributes instead, which the library keeps. Exporters that
 * emit `fill="#c00"` are unaffected; exporters configured to emit CSS classes will look wrong. A blunter
 * alternative — refusing any output containing `javascript:` — was measured and rejected: it also refuses a
 * `<text>` label that legitimately says "use javascript: carefully".
 */
final class TagsWithoutStyle implements TagInterface
{
    /**
     * ⚠️ DERIVED FROM THE LIBRARY'S LIST AT CALL TIME, NEVER COPIED. A pasted copy is a snapshot that stops
     * receiving the tag removals the library makes in response to its own advisories — which is the entire
     * reason ADR-041 wanted a maintained library rather than our own list.
     *
     * @return array<int, string>
     */
    public static function getTags()
    {
        return array_values(array_filter(
            AllowedTags::getTags(),
            static fn (string $tag): bool => $tag !== 'style',
        ));
    }
}
