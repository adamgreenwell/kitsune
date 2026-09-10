<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Fields\Pattern;

/**
 * A pattern made only of permitted constructs can still be unpublishable.
 *
 * ⚠️ `field-types.md` §3 PUBLISHED THREE OF THESE RULES AND THE CODE ENFORCED NONE OF THEM.
 * Measured before this file existed: `^(?=a)+a$`, `(?<=(a|aa))b\1$` and the document's own
 * example `^([a-zA-Z0-9]+\.?)+$` were all accepted by `Pattern::unpublishable()`. The first two
 * are exactly the two live defects the parity harness reported, so the gap was visible the whole
 * time in the instrument built to find it — a published constraint the code did not keep, which
 * is the failure invariant 14 exists for.
 *
 * Review then found two more of the same kind. All five are properties of how constructs FIT
 * TOGETHER, which no per-construct allowlist can express, and they are asserted here as one
 * family for that reason.
 */
describe('rule 1 — no quantifier on an assertion', function (): void {
    /*
     * ⚠️ Checked against `u`-mode specifically: an assertion consumes nothing, so ECMAScript
     * rejects a quantifier on one outright while PCRE accepts it. Annex B makes the unflagged
     * dialect more permissive than the flagged one, which is why the flag matters to the claim.
     */
    it('refuses a quantifier on every assertion form', function (string $pattern): void {
        expect(Pattern::unpublishable($pattern))
            ->not->toBeNull("[{$pattern}] carries a quantifier on an assertion");
    })->with(['^(?=a)+a$', '(?=a)*b', '(?=a)?b', '(?!a)+b', '(?<=a)+b', '(?<!a)?b', '(?=a){2}b']);

    it('leaves an unquantified assertion alone', function (): void {
        expect(Pattern::unpublishable('^(?=a)a$'))->toBeNull()
            ->and(Pattern::unpublishable('(?<=ab)c'))->toBeNull()
            ->and(Pattern::unpublishable('^(?!x)[a-z]+$'))->toBeNull();
    });
});

describe('rule 2 — a lookbehind\'s alternatives must be equal length', function (): void {
    /*
     * ⚠️ PCRE tries a lookbehind's alternatives LONGEST FIRST and ECMAScript in WRITTEN ORDER, so
     * a differing-length alternation changes which alternative matched and what any group inside
     * it captured. `(?<=(a|aa))b\1$` compiles in both engines and they disagree on the subject.
     */
    it('refuses alternatives of differing length', function (string $pattern): void {
        expect(Pattern::unpublishable($pattern))
            ->not->toBeNull("[{$pattern}] has unequal lookbehind alternatives");
    })->with(['(?<=(a|aa))b\1$', '(?<=a|aa)b', '(?<!x|xyz)a', '(?<=ab|c)d']);

    it('accepts alternatives that are all the same fixed length', function (): void {
        expect(Pattern::unpublishable('(?<=(a|b))\1$'))->toBeNull()
            ->and(Pattern::unpublishable('(?<=(ab|cd))\1$'))->toBeNull()
            ->and(Pattern::unpublishable('(?<=ab|cd)e'))->toBeNull();
    });
});

describe('rule 3 — an unbounded repetition must divide its subject one way only', function (): void {
    /*
     * ⚠️ NOT A PORTABILITY PROBLEM — the two engines agree, in the sense that NEITHER gives a
     * verdict. `preg_match()` returns false after exhausting its backtrack limit and ECMAScript is
     * still searching when the deadline expires. It is catastrophic backtracking, and ADR-027's
     * 1 vCPU floor is why the cost cannot be left to the consumer.
     *
     * ⚠️ THIS WAS TWO RULES AND THEY BOTH LEAKED. The published pair was "no unbounded quantifier
     * over a group containing one" plus "not over ambiguous alternation". `^(a{1,2})+$` slips
     * between them — the inner quantifier is BOUNDED so the first does not fire, and there is no
     * alternation so the second does not either. Review found it; measured, 30 characters takes
     * ECMAScript ~100ms, 40 runs past three seconds, and PCRE exhausts its backtrack limit.
     *
     * ⚠️ AND MY OWN TEST ASSERTED `^([a-z]{1,8})+$` WAS FINE, in the block this replaces, under the
     * heading "accepts a bounded outer quantifier". It is the same shape and measures the same way:
     * PCRE's backtrack limit, ECMAScript past a two-second deadline. Two rules aimed at symptoms let
     * a third symptom through and blessed a fourth. One rule aimed at the property does not.
     */
    it('refuses a body that can match more than one length', function (string $pattern): void {
        expect(Pattern::unpublishable($pattern))
            ->not->toBeNull("[{$pattern}] repeats an ambiguously divisible body");
    })->with([
        '^([a-zA-Z0-9]+\.?)+$',
        '(a+)+$',
        '(a*)*b',
        '(?:a{1,})+',
        '^(?:[a-z]*)+$',
        '^([a-z]+)*$',
        // Review's case: the inner quantifier is bounded, so the old rule 3 never fired.
        '^(a{1,2})+$',
        // Mine, asserted as ACCEPTABLE by the test this replaces.
        '^([a-z]{1,8})+$',
        // Ambiguous although both engines happen to cope: `a*` says the same thing portably.
        '^(a?)+$',
    ]);

    it('refuses alternatives that can match the same text two ways', function (string $pattern): void {
        // Rule 4 as it was published, now the same rule: overlapping branches are one way for a
        // body to be ambiguously divisible.
        expect(Pattern::unpublishable($pattern))
            ->not->toBeNull("[{$pattern}] repeats an ambiguous alternation");
    })->with(['^(a|aa)+$', '^(a|ab)+$', '^(?:a|aa)+$', '^(?:cat|ca)+$', '^(?:a|)+$', '^(?:(?:a|aa))+$']);

    it('refuses overlapping branches even at a fixed width', function (): void {
        /*
         * ⚠️ A FORCED DIVISION IS NOT ENOUGH, and measuring is what established that. `(?:[a-z]|x)+`
         * is fixed at one character, so every iteration consumes exactly one and there is only one
         * way to divide the subject — and it is still catastrophic, because `x` lies inside
         * `[a-z]`: on 30 `x` characters both branches match at every position, giving 2^30 branch
         * choices.
         *
         *   30 x's:  ECMAScript 7.9 s        PCRE backtrack limit exhausted
         *   30 a's:  ECMAScript 0 ms         PCRE 0 ms
         *
         * The same pattern, two subjects, and only one of them is affordable — which is why the rule
         * is about the construct rather than about any subject.
         */
        expect(Pattern::unpublishable('^(?:[a-z]|x)+$'))->not->toBeNull()
            ->and(Pattern::unpublishable('^(?:(?:[a-z]|x))+$'))->not->toBeNull();

        // ⚠️ Conservative where it cannot be sure: these branches are disjoint and safe, and are
        // refused because deciding whether two classes overlap is more analysis than belongs on an
        // authoring request. The cost is reported by the harness rather than hidden.
        expect(Pattern::unpublishable('^(?:a|[b-z])+$'))->not->toBeNull();
    });

    it('accepts a body whose division is forced', function (string $pattern): void {
        expect(Pattern::unpublishable($pattern))->toBeNull("[{$pattern}] has one way to divide");
    })->with([
        // Fixed width.
        '^(?:ab)+$', '^(?:ab){2,8}$', '^(a|b)+$', '^(?:cat|dog)+$',
        // ⚠️ RECURSIVELY fixed: a nested alternation of distinct literals is as safe as `(?:ab|ac)+`,
        // and a check that looked only at the body's own top level refused it for nothing.
        '^(?:a(?:b|c))+$',
        // Prefix-free literals, at differing lengths, so at most one matches at a position.
        '^(?:ab|c)+$',
        // A bounded outer quantifier caps the exponent, so the ambiguity costs nothing.
        '^(a|aa){1,4}$',
        // One unbounded quantifier is not a nest.
        '^([a-z]+)$', '^[a-z]+$', '^[a-z]{1,10}$',
    ]);

    /*
     * ⚠️ THE DELIMITED LIST IS EXEMPT, and it has to be: `^[^,]+(?:,[^,]+)*$` nests `+` inside `*`
     * and is the commonest safe shape in the language. The rule as `field-types.md` published it
     * refused it, which is how the exemption came to be written.
     *
     * The exemption is a proof, not a guess. Every iteration must begin at a `,` and `[^,]` cannot
     * consume one, so the commas in the subject FORCE the division — one way to split, nothing to
     * backtrack over. Measured against a worst case that fails at the very end:
     *
     *   n=1000 items   PCRE 0.01 ms   ECMAScript 0.06 ms
     *   n=5000 items   PCRE 0.04 ms   ECMAScript 0.09 ms
     *   n=20000 items  PCRE (JIT stack limit)   ECMAScript 0.30 ms
     *
     * Linear in both. The JIT stack limit at n=20000 is a 60 KB subject and is a bound on subject
     * LENGTH rather than on ambiguity — a text field defaults to 255 characters, so it is not
     * reachable through a field value.
     */
    it('exempts a delimited repetition, which cannot backtrack', function (string $pattern): void {
        expect(Pattern::unpublishable($pattern))
            ->toBeNull("[{$pattern}] is a delimited list and provably linear");
    })->with(['^[^,]+(?:,[^,]+)*$', '^[^;]+(?:;[^;]+)*$', '^[a-z]+(?:\.[a-z]+)*$']);

    it('does not exempt a delimiter a BOUNDED atom can also consume', function (string $pattern): void {
        /*
         * ⚠️ THE PROOF ASKED THE WRONG QUESTION, and review found it. It checked whether an UNBOUNDED
         * atom could consume the delimiter, so `^(?:,,?)*X$` was exempted — the optional comma is
         * bounded, and it can still either end the current iteration or start the next, which is
         * exactly the ambiguity the proof is meant to rule out. Measured: 40 commas plus a `Y` takes
         * ECMAScript about 1.3 seconds and grows exponentially.
         *
         * What matters is whether the atom can match TWO LENGTHS, not whether it has an upper bound.
         * A required atom that matches the delimiter is still fine — `,a,` splits one way — because
         * the ambiguity comes from the CHOICE about consuming one, not from consuming it.
         */
        expect(Pattern::unpublishable($pattern))
            ->not->toBeNull("[{$pattern}] lets a variable atom consume its own delimiter");
    })->with(['^(?:,,?)*X$', '^(?:,,{0,2})*X$', '^(?:,,*)*X$', '^(?:,[,a]?)*X$']);

    /*
     * ⚠️ AND THE EXEMPTION DOES NOT LEAK. Each of these fails one clause of the proof: the first
     * begins with a class rather than a literal, the second's leading literal IS the unbounded
     * atom, and the third's unbounded atom can match the delimiter.
     */
    it('does not exempt a repetition that only looks delimited', function (string $pattern): void {
        expect(Pattern::unpublishable($pattern))
            ->not->toBeNull("[{$pattern}] does not satisfy the delimiter proof");
    })->with(['^([a-zA-Z0-9]+\.?)+$', '^(,+)*$', '^(?:,[^;]+)*$']);

    /*
     * ⚠️ A `+` INSIDE A CLASS IS THE CHARACTER, not a quantifier, and a substring search for `+`
     * would refuse this. Same reason the main scan tracks escapes rather than matching text.
     */
    it('does not mistake a literal plus for a quantifier', function (): void {
        expect(Pattern::unpublishable('^([a+]+)$'))->toBeNull()
            ->and(Pattern::unpublishable('^(a\+)+$'))->toBeNull();
    });
});

describe('rule 5 — a capturing group in a lookbehind must be fixed length', function (): void {
    /*
     * ⚠️ MEASUREMENT PLACED THIS LINE, rather than a blanket ban on captures in lookbehinds. With
     * a FIXED width the engines agree, including two adjacent captures —
     * `(?<=([ab]{2})([bc]{2}))\2\1$` matches in both. Make either variable and they part company,
     * because the two engines walk a lookbehind in opposite directions and allocate the variable
     * part to different captures:
     *
     *   `(?<=(a+))\1$`                     on `aaaa`   PCRE errors, ECMAScript matches
     *   `(?<=([ab]{1,2})([bc]{1,2}))\2\1$` on `abcbca` PCRE says no, ECMAScript says yes
     */
    it('refuses a variable-length capture inside a lookbehind', function (string $pattern): void {
        expect(Pattern::unpublishable($pattern))
            ->not->toBeNull("[{$pattern}] captures a variable width inside a lookbehind");
    })->with([
        '(?<=(a+))\1$',
        '(?<=([ab]{1,2})([bc]{1,2}))\2\1$',
        '(?<=(a{1,2}))\1$',
        '(?<=(a?))\1$',
        '(?<!(a*))\1$',
    ]);

    /*
     * ⚠️ `(?<=(a{1,2}))\1$` AND `(?<=(a?))\1$` AGREE ON THE SUBJECTS TRIED, and are refused
     * anyway. That agreement is subject-dependent luck rather than a property of the construct —
     * they are the same variable-width capture as the two that demonstrably diverge, and a rule
     * that admitted them would be drawing its line at the subjects I happened to pick.
     */
    it('accepts a fixed-length capture inside a lookbehind', function (): void {
        expect(Pattern::unpublishable('(?<=(ab))\1$'))->toBeNull()
            ->and(Pattern::unpublishable('(?<=([ab]))\1$'))->toBeNull()
            ->and(Pattern::unpublishable('(?<=(a{2}))\1$'))->toBeNull()
            ->and(Pattern::unpublishable('(?<=([ab]{2})([bc]{2}))\2\1$'))->toBeNull();
    });

    it('leaves a variable-length capture alone OUTSIDE a lookbehind', function (): void {
        // The rule is about traversal direction, which only a lookbehind reverses.
        expect(Pattern::unpublishable('^(a+)\1$'))->toBeNull()
            ->and(Pattern::unpublishable('^(?=(a+))a\1$'))->toBeNull();
    });
});

describe('a lazy quantifier is part of the quantifier', function (): void {
    /*
     * ⚠️ `quantifierAt()` DROPPED THE LAZY SUFFIX ON A BRACED FORM, so `fixedWidth()` counted the
     * trailing `?` as a separate one-character atom. `a{2}?` was measured as width 3, which made
     * `(?<=(a{2}?|aaa))b\1$` look like two equal branches — and on `aaabaa`, PCRE says no while
     * ECMAScript says yes. The `*`/`+`/`?` branch already carried its suffix; only the brace did not.
     */
    it('measures a lazy braced quantifier at its real width', function (): void {
        expect(Pattern::unpublishable('(?<=(a{2}?|aaa))b\1$'))
            ->not->toBeNull('a{2}? is two characters wide, not three');
    });

    it('still accepts branches that are equal once the suffix is counted', function (): void {
        // ⚠️ The other half: `a{2}?` and `aa` ARE both two wide, so this is genuinely equal-length and
        // must publish. A fix that simply refused anything lazy would pass the test above and be wrong.
        expect(Pattern::unpublishable('(?<=(a{2}|aa))b\1$'))->toBeNull()
            ->and(Pattern::unpublishable('(?<=(a{2}?|aa))b\1$'))->toBeNull();
    });

    it('treats a lazy suffix as preference, not as width', function (): void {
        // `{n}?` names one length; laziness changes which match is preferred, not how long it is.
        expect(Pattern::unpublishable('^(?:ab){2}?$'))->toBeNull()
            ->and(Pattern::unpublishable('^[a-z]{3}?$'))->toBeNull();
    });
});

describe('a multi-character escape is one character wide', function (): void {
    /*
     * ⚠️ EVERY WIDTH IN THIS FILE DEPENDS ON THIS, and the scanners all advanced past a backslash by
     * exactly two characters. That is right for `\.` and wrong for every escape with a payload:
     * `fixedWidth()` read `\x61` as a `\x` atom followed by the literals `6` and `1` and reported
     * width 3 for a one-character escape.
     *
     * The consequence was a live divergence, found by review. `(?<=(\x61|aaa))b\1$` was published
     * because the branches looked equal at 3 — and on `aaaba`, PCRE says no while ECMAScript says
     * yes. Both lookbehind rules were bypassed by one arithmetic error.
     */
    it('refuses a lookbehind whose alternatives are unequal once escapes are counted', function (string $pattern): void {
        expect(Pattern::unpublishable($pattern))
            ->not->toBeNull("[{$pattern}] has unequal alternatives once the escape is measured");
    })->with([
        '(?<=(\x61|aaa))b\1$',
        '(?<=(\cA|aa))b\1$',
        '(?<=(\x61\x62|aaa))b\1$',
    ]);

    it('accepts one whose alternatives really are equal', function (): void {
        // ⚠️ The other half: `\x61` IS one character, so these are genuinely equal and both engines
        // agree on them — measured, PCRE 1 and ECMAScript 1 on `aba` and `abbab` respectively.
        expect(Pattern::unpublishable('(?<=(\x61|a))b\1$'))->toBeNull()
            ->and(Pattern::unpublishable('(?<=(\x61\x62|ab))b\1$'))->toBeNull();
    });

    it('does not read an escape payload as a metacharacter', function (): void {
        /*
         * ⚠️ NOT MERELY IMPRECISE BUT WRONG, and this is the case that shows it. `\c|` puts a `|` in
         * the payload position: a scanner stepping over only `\c` reads an alternation that is not
         * there, and `\x2A` puts the characters `2A` where a two-character step would land.
         */
        expect(Pattern::unpublishable('^(?:\cA)+$'))->toBeNull()
            ->and(Pattern::unpublishable('^(?:\x61)+$'))->toBeNull();
    });
});

describe('the rules do not refuse ordinary field patterns', function (): void {
    /*
     * ⚠️ THE COST OF FIVE CONSERVATIVE RULES, asserted rather than assumed. These are the shapes a
     * field validation actually uses, and a structural rule that refused one of them would be
     * paying too much — the expressiveness cost is reported by the harness, and it should not
     * include anything on this list.
     */
    it('accepts the shapes a field validation is actually written with', function (string $pattern): void {
        expect(Pattern::unpublishable($pattern))->toBeNull("[{$pattern}] is an ordinary pattern");
    })->with([
        '^[a-z]+$',
        '^[a-z0-9-]{3,32}$',
        '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$',
        '^(?:19|20)[0-9]{2}$',
        '^[0-9]{3}-[0-9]{4}$',
        '^\p{L}+$',
        '^(a)(b)\2\1$',
        '^(?<year>[0-9]{4})-(?<month>[0-9]{2})$',
        '^#[0-9A-Fa-f]{6}$',
        '^[^,]+(?:,[^,]+)*$',
    ]);
});
