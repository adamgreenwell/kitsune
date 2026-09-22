<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use enshrined\svgSanitize\data\AllowedTags;
use Kitsune\Core\Media\MediaIntake;
use Kitsune\Core\Media\SanitisesSvg;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Modules\ModuleLifecycle;
use Kitsune\Core\Modules\ModuleManifest;
use Kitsune\SvgSanitizer\EnshrinedSvgSanitiser;
use Kitsune\SvgSanitizer\SvgSanitizerServiceProvider;
use Kitsune\SvgSanitizer\TagsWithoutStyle;

/*
 * The sanitiser core declares and deliberately does not ship — ADR-041, Standing Principle #11.
 *
 * ⚠️ NOTHING HERE IS A FIXTURE. `kitsune/svg-sanitizer` is a real package under `packages/`, resolved through
 * the root `composer.json`'s own `packages/*` path repository, and the sanitising below runs the real
 * `enshrined/svg-sanitize`. A stub would assert that a stub strips scripts.
 *
 * ⚠️ EVERY EXPECTATION IN `it strips ...` WAS MEASURED AGAINST THE LIBRARY BEFORE IT WAS WRITTEN, not
 * predicted from its README. Two of them are the reason the adapter looks the way it does: a `<style>` block
 * carrying `url('javascript:…')` survived the library's default allowlist, and an SVG whose only content was
 * a `<script>` came back as a well-formed EMPTY document that an earlier `str_contains($clean, '<svg')` check
 * cheerfully accepted.
 */

const SVG_MODULE = 'kitsune/svg-sanitizer';
const SVG_NS = 'xmlns="http://www.w3.org/2000/svg"';

beforeEach(function (): void {
    ModuleKernel::flush();
});

afterEach(function (): void {
    app()->forgetInstance(SanitisesSvg::class);
});

function svgWith(string $fragment): string
{
    return '<svg '.SVG_NS.'>'.$fragment.'<rect width="1" height="1"/></svg>';
}

it('is discoverable as a module, and its manifest passes the grammar', function (): void {
    $read = ModuleDiscovery::read(SVG_MODULE);

    expect($read)->toBeInstanceOf(ModuleManifest::class)
        ->and($read->provider)->toBe(SvgSanitizerServiceProvider::class)
        /* One container binding and no models, so there is nothing to scope — a claim the verifier checks. */
        ->and($read->scoping)->toBe([]);
});

/**
 * ⚠️ THE WHOLE POINT OF THE MODULE, ASSERTED AS A PAIR. Core refuses `.svg` until something implements
 * `SanitisesSvg`; enabling this module is what makes core say yes. Asserting only the second half would pass
 * against a core that accepted SVG unconditionally.
 */
it('is what turns svg from refused into accepted', function (): void {
    expect(app()->bound(SanitisesSvg::class))->toBeFalse()
        ->and(MediaIntake::acceptedTypes())->not->toHaveKey('svg');

    ModuleLifecycle::install(app(), SVG_MODULE);
    ModuleLifecycle::enable(app(), SVG_MODULE);
    ModuleKernel::boot(app());

    expect(app()->bound(SanitisesSvg::class))->toBeTrue()
        ->and(app(SanitisesSvg::class))->toBeInstanceOf(EnshrinedSvgSanitiser::class)
        ->and(MediaIntake::acceptedTypes())->toHaveKey('svg')
        ->and(MediaIntake::acceptedTypes()['svg'])->toBe(['image/svg+xml']);
});

/**
 * ⚠️ THE PAYLOAD CLASSES, EACH ASSERTED ON THE OUTPUT RATHER THAN ON THE ABSENCE OF AN EXCEPTION. A sanitiser
 * that returned its input unchanged would throw nothing at all.
 */
it('strips every executable construct it is handed', function (string $fragment, string $gone): void {
    $clean = (new EnshrinedSvgSanitiser)->sanitise(svgWith($fragment));

    expect(strtolower($clean))->not->toContain(strtolower($gone))
        /* And it is still a usable image rather than a refusal dressed as a success. */
        ->and($clean)->toContain('<rect');
})->with([
    'script element' => ['<script>alert(1)</script>', '<script'],
    'onload handler' => ['<g onload="alert(1)"><circle r="1"/></g>', 'onload'],
    'onclick handler' => ['<g onclick="alert(1)"><circle r="1"/></g>', 'onclick'],
    'javascript href' => ['<a href="javascript:alert(1)"><circle r="1"/></a>', 'javascript:'],
    'foreignObject' => ['<foreignObject><b>hi</b></foreignObject>', 'foreignobject'],
    'remote image' => ['<image href="https://evil.test/track.png"/>', 'evil.test'],
    'SMIL set href' => ['<a><set attributeName="href" to="javascript:alert(1)"/></a>', 'javascript:'],
    /* ⚠️ Measured: this SURVIVED the library's default allowlist, which is why `<style>` is removed from it. */
    'css javascript url' => ["<style>rect{background:url('javascript:alert(1)')}</style>", 'javascript:'],
    'css unquoted url' => ['<style>rect{background:url(javascript:alert(1))}</style>', 'javascript:'],
    'css entity-encoded' => ["<style>rect{background:url('&#106;avascript:alert(1)')}</style>", 'javascript:'],
    'css remote import' => ["<style>@import url('https://evil.test/x.css');</style>", 'evil.test'],
]);

/**
 * ⚠️ MIXED-CASE `xlink:HrEf` IS ITS OWN CASE, because it is its own CVE. CVE-2025-55166 and GHSA-m9xh-6747-9r6f
 * are both "the filter matched lower-case only" — the single most repeated bug in this library's history.
 */
it('strips a javascript href however it is cased', function (): void {
    $sanitiser = new EnshrinedSvgSanitiser;

    foreach (['xlink:href', 'xlink:HrEf', 'XLINK:HREF'] as $spelling) {
        $clean = $sanitiser->sanitise(
            '<svg '.SVG_NS.' xmlns:xlink="http://www.w3.org/1999/xlink">'
            .'<a '.$spelling.'="javascript:alert(1)"><rect width="1" height="1"/></a></svg>'
        );

        expect(strtolower($clean))->not->toContain('javascript:');
    }
});

/** Entity attacks land on the parse failure rather than expanding — measured, not assumed. */
it('refuses an entity attack rather than expanding it', function (string $payload): void {
    expect(fn () => (new EnshrinedSvgSanitiser)->sanitise($payload))
        ->toThrow(RuntimeException::class, 'could not be parsed as XML');
})->with([
    'xxe' => ['<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
        .'<svg '.SVG_NS.'><text>&xxe;</text></svg>'],
    'billion laughs' => ['<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY a "aaaaaaaaaa">'
        .'<!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;"><!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;&b;&b;">]>'
        .'<svg '.SVG_NS.'><text>&c;</text></svg>'],
]);

/**
 * ⚠️ THE FAILURE THAT LOOKS LIKE SUCCESS, AND THE ONE AN EARLIER VERSION OF THE ADAPTER MISSED. An SVG whose
 * entire content is a `<script>` sanitises to a well-formed, perfectly safe, completely BLANK image. The
 * first check asked `str_contains($clean, '<svg')`, which such a document satisfies — so the docblock claimed
 * a refusal the code did not make. A blank image stored as a successful upload is a broken asset.
 */
it('refuses a document that sanitised down to nothing', function (): void {
    expect(fn () => (new EnshrinedSvgSanitiser)->sanitise('<svg '.SVG_NS.'><script>alert(1)</script></svg>'))
        ->toThrow(RuntimeException::class, 'left nothing that draws');
});

it('refuses an empty file and input that is not XML at all', function (): void {
    $sanitiser = new EnshrinedSvgSanitiser;

    expect(fn () => $sanitiser->sanitise('   '))->toThrow(RuntimeException::class, 'nothing to sanitise')
        ->and(fn () => $sanitiser->sanitise('honestly just text'))
        ->toThrow(RuntimeException::class, 'could not be parsed as XML');
});

/**
 * ⚠️ THE LIBRARY THROWS `LogicException`, WHICH THE CONTRACT DOES NOT NAME. Measured: `<a/>` raises
 * `LogicException("Got 0 svg elements, expected exactly one")`. `SanitisesSvg` documents `@throws
 * RuntimeException`, so a caller catching what the contract names would let this past as an unhandled 500
 * rather than a refusal.
 */
it('wraps an exception type the contract does not name', function (): void {
    expect(fn () => (new EnshrinedSvgSanitiser)->sanitise('<a/>'))
        ->toThrow(RuntimeException::class, 'could not process it');
});

/** A legitimate logo survives intact — the case that makes every refusal above worth having. */
it('leaves an ordinary logo alone', function (): void {
    $logo = '<svg '.SVG_NS.' width="10" height="10"><circle cx="5" cy="5" r="4" fill="#c00"/></svg>';

    $clean = (new EnshrinedSvgSanitiser)->sanitise($logo);

    expect($clean)->toContain('<circle')->toContain('#c00')->toContain('r="4"');
});

/**
 * ⚠️ DERIVED FROM THE LIBRARY'S LIST, NEVER COPIED. A pasted snapshot stops receiving the tag removals the
 * library makes in response to its own advisories, which is the whole reason ADR-041 wanted a maintained
 * library. This asserts the relationship rather than a hard-coded count.
 */
it('narrows the library\'s own allowlist by exactly one tag', function (): void {
    $theirs = AllowedTags::getTags();
    $ours = TagsWithoutStyle::getTags();

    expect(array_values(array_diff($theirs, $ours)))->toBe(['style'])
        ->and(array_diff($ours, $theirs))->toBe([])
        ->and($ours)->toContain('svg')->toContain('circle')->toContain('path');
});

/*
 * ────────────────────────────────  Denial of service, measured  ────────────────────────────────
 */

/** A branching `<use>` graph: layer i has $w nodes, each referencing every node in layer i+1. */
function useGraph(int $width, int $depth): string
{
    $svg = '<svg '.SVG_NS.' xmlns:xlink="http://www.w3.org/1999/xlink">';

    for ($i = 0; $i < $depth; $i++) {
        for ($j = 0; $j < $width; $j++) {
            $svg .= '<g id="n'.$i.'_'.$j.'">';

            if ($i + 1 < $depth) {
                for ($k = 0; $k < $width; $k++) {
                    $svg .= '<use xlink:href="#n'.($i + 1).'_'.$k.'"/>';
                }
            } else {
                $svg .= '<rect width="1" height="1"/>';
            }

            $svg .= '</g>';
        }
    }

    return $svg.'<rect width="1" height="1"/></svg>';
}

/**
 * ⚠️ A NINE-KILOBYTE UPLOAD USED TO BURN A CORE FOR TWENTY-FIVE SECONDS, and at ADR-027's floor of one vCPU
 * that is a denial of service any account that may upload a logo can trigger. `Subject::hasInfiniteLoop()`
 * walks the `<use>` reference graph with an un-memoised depth-first search bounded only by the nesting limit,
 * so cost is roughly width ^ depth.
 *
 * Measured on enshrined/svg-sanitize 1.0.0 before the guard: 25.5s for this exact payload at the library's
 * default nesting limit of 15; 29.0s for a 169KB width-40 depth-5 graph at limit 5, which is why lowering
 * the limit alone is not the fix; 14.9s with `use` removed from the tag allowlist, which is why THAT is not
 * the fix either — the reference graph is built before the allowlist is applied.
 *
 * ⚠️ THE ASSERTION IS A CLOCK, AND IT IS DELIBERATELY LOOSE. A budget of two seconds is more than a hundred
 * times the measured 0.022s and still a hundredth of the old cost, so it cannot flake on a slow runner and
 * cannot pass if the guard is removed.
 */
it('resolves a hostile use graph in well under a second', function (): void {
    $payload = useGraph(6, 10);

    expect(strlen($payload))->toBeLessThan(10_000);

    $started = microtime(true);

    try {
        (new EnshrinedSvgSanitiser)->sanitise($payload);
    } catch (RuntimeException) {
        /* Refusing it is a perfectly good outcome; what must not happen is spending a core on it. */
    }

    expect(microtime(true) - $started)->toBeLessThan(2.0);
});

it('refuses a document carrying more use references than the walk can afford', function (): void {
    $payload = useGraph(40, 5);

    expect(substr_count($payload, '<use'))->toBeGreaterThan(EnshrinedSvgSanitiser::MAX_USE_ELEMENTS);

    expect(fn () => (new EnshrinedSvgSanitiser)->sanitise($payload))
        ->toThrow(RuntimeException::class, '`<use>` references and the ceiling is');
});

/** And the bound is generous enough that ordinary symbol reuse is untouched. */
it('leaves an ordinary reused symbol alone', function (): void {
    $logo = '<svg '.SVG_NS.' xmlns:xlink="http://www.w3.org/1999/xlink">'
        .'<g id="leaf"><rect width="4" height="4" fill="#c00"/></g>'
        .'<g id="pair"><use xlink:href="#leaf"/><use xlink:href="#leaf" x="5"/></g>'
        .'<use xlink:href="#pair"/><rect width="1" height="1"/></svg>';

    $clean = (new EnshrinedSvgSanitiser)->sanitise($logo);

    expect(substr_count($clean, '<use'))->toBe(3)->and($clean)->toContain('#c00');
});

/*
 * ────────────────────────────────  Two more, found by review on #148  ────────────────────────────────
 */

/**
 * ⚠️ THE REFERENCE CAP COUNTS LOCAL NAMES, BECAUSE A PREFIX IS FREE. An uploader may bind a second prefix to
 * the SVG namespace and write every reference as `<s:use>`; the literal `substr_count($svg, '<use')` the cap
 * used to be counts that as zero.
 *
 * ⚠️ AND THE PAYLOAD IS CURRENTLY HARMLESS ANYWAY, WHICH IS WHY THIS ASSERTS THE COUNT AND NOT THE CLOCK.
 * Measured: this library matches its allowlist on the PREFIXED name, so it strips `<s:use>` outright and
 * never walks it — 0.00s against a shape that costs 25s unprefixed. The guard must not depend on that,
 * because what it would be depending on is a third-party allowlist this package re-derives at call time.
 */
it('counts use references whatever namespace prefix they wear', function (): void {
    $prefixed = str_replace('<use ', '<s:use ', useGraph(40, 5));
    $prefixed = str_replace('<svg '.SVG_NS, '<svg '.SVG_NS.' xmlns:s="http://www.w3.org/2000/svg"', $prefixed);

    /* The literal the cap used to use sees none of them. */
    expect(substr_count($prefixed, '<use'))->toBe(0);

    expect(fn () => (new EnshrinedSvgSanitiser)->sanitise($prefixed))
        ->toThrow(RuntimeException::class, '`<use>` references and the ceiling is');
});

/** `<used>` is not `<use>` — the pattern matches an element, not a substring. */
it('does not count a word that merely starts with use', function (): void {
    $logo = '<svg '.SVG_NS.'><desc>'.str_repeat('we use used usefully ', 500).'</desc>'
        .'<rect width="1" height="1"/></svg>';

    expect((new EnshrinedSvgSanitiser)->sanitise($logo))->toContain('<rect');
});

/**
 * ⚠️ AN EMPTY WRAPPER IS NOT CONTENT, and the check that only asked "is there a child element" said it was.
 * `<svg><g><script>…</script></g></svg>` sanitises to `<svg><g></g></svg>`: the dangerous element is gone, a
 * wrapper survives, and the document stores as a successful upload that renders nothing.
 */
it('refuses a document whose only survivor is an empty wrapper', function (string $fragment): void {
    expect(fn () => (new EnshrinedSvgSanitiser)->sanitise('<svg '.SVG_NS.'>'.$fragment.'</svg>'))
        ->toThrow(RuntimeException::class, 'left nothing that draws');
})->with([
    'script inside a group' => ['<g><script>alert(1)</script></g>'],
    'empty group' => ['<g></g>'],
    'empty defs' => ['<defs></defs>'],
    'wrappers all the way down' => ['<g><g><g></g></g></g>'],
    /* A title is a tooltip, not a picture — an SVG carrying only one renders blank. */
    'title alone' => ['<title>a logo, allegedly</title>'],
]);

/** And a wrapper that does contain something is left alone. */
it('accepts content nested inside wrappers', function (): void {
    $clean = (new EnshrinedSvgSanitiser)->sanitise(
        '<svg '.SVG_NS.'><g><g><circle cx="5" cy="5" r="4" fill="#c00"/></g></g></svg>'
    );

    expect($clean)->toContain('<circle')->toContain('#c00');
});
