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
        /*
         * ⚠️ THIS WAS AN ACCEPT CASE, documented as "a bounded outer quantifier caps the exponent, so
         * the ambiguity costs nothing". Review disproved it and measurement placed the correction: the
         * bound is the exponent and the BODY is the base, and nothing bounds the base. On Node
         * 22.23.2 with 40 `a` characters and a failing `!`, `^(a|aa){1,20}$` takes 114 ms,
         * `^(a|aa|aaa){1,16}$` takes 3.4 seconds and `^(a|aa|aaa|aaaa){1,14}$` takes 16.6 seconds —
         * the safe bound falls as the body widens, so a threshold on the bound is a constant a wider
         * body defeats. There is no threshold: anything that can run twice is screened.
         */
        '^(a|aa){1,4}$',
        '^(a|aa){1,32}$',
        '^(a{1,2}){1,8}$',
        '^(a|aa){2}$',
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
    })->with([
        '^[^,]+(?:,[^,]+)*$',
        '^[^;]+(?:;[^;]+)*$',
        '^[a-z]+(?:\.[a-z]+)*$',
        // ⚠️ Reading THROUGH a required group must not become refusing one: the group here holds
        // nothing that can consume a comma, so the list is as exempt as the form without it.
        '^[^,]+(?:,(?:[^,]+))*$',
    ]);

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

    it('sees a variable atom through a required group', function (string $pattern): void {
        /*
         * ⚠️ A BRACKET PAIR WAS ENOUGH TO HIDE IT, which review found after the bounded-atom fix
         * above. The scan jumped from a group's `(` to its `)` and asked only about the group's own
         * quantifier, so an optional delimiter one level down was invisible: `^(?:,(?:,?))*X$` is
         * `^(?:,,?)*X$` — refused on the line above — with brackets around the optional comma, and
         * it was accepted. Measured on the same 40-delimiter subject, Node 22.23.2:
         *
         *   ^(?:,,?)*X$        40 commas + Y    1.3 s
         *   ^(?:,(?:,?))*X$    40 commas + Y   10.5 s
         *
         * A required group is transparent now rather than opaque. A capture behaves the same way, a
         * fixed repetition of the group does too, and nesting does not help.
         */
        expect(Pattern::unpublishable($pattern))
            ->not->toBeNull("[{$pattern}] hides a variable atom inside a required group");
    })->with([
        '^(?:,(?:,?))*X$',
        '^(?:,(,?))*X$',
        '^(?:,(?:(?:,?)))*X$',
        '^(?:,(?:,?){2})*X$',
        '^(?:,(?:,*))*X$',
    ]);

    it('does not exempt ambiguity between the non-delimiter atoms', function (string $pattern): void {
        /*
         * ⚠️ FORCING THE SPLIT BETWEEN ITERATIONS IS NOT ENOUGH, which review found by putting the
         * ambiguity entirely BETWEEN non-delimiter atoms. `^(?:,a*a*)*X$` satisfies the delimiter
         * proof exactly — neither `a*` can match a comma, so every iteration must begin at one and
         * none can consume one — and each `,aa` segment still has three ways to divide `aa` between
         * the two stars. The boundaries are forced; what happens inside them was never checked.
         * Measured on Node 22.23.2, `,aa` repeated then a failing `Y`:
         *
         *   n=12  9 ms      n=16  723 ms      n=20  58.8 SECONDS
         *
         * ⚠️ THE LEFT ATOM IS THE ONE THAT MATTERS. For `A+ s B+` with `s` a required literal: if
         * `A` cannot match `s` then `A+` must stop at the FIRST `s` and the division is forced
         * whatever `B` can match. If `A` can, it may swallow one `s` and leave a later one — the
         * ambiguity. So a separator has to be unmatchable by the atom on its left, which is the
         * delimiter proof applied one level in rather than a second idea.
         */
        expect(Pattern::unpublishable($pattern))
            ->not->toBeNull("[{$pattern}] repeats a body that can match one segment two ways");
    })->with([
        '^(?:,a*a*)*X$',
        // The same body with a bracket pair, which is where the previous round's fix had to reach.
        '^(?:,(?:a*)a*)*X$',
        '^(?:,a+a+)*X$',
        '^(?:,a*b?)*X$',
        // Two atoms with a separator the LEFT one can consume: measured n=24 610 ms and climbing.
        '^(?:,[^,]+-[^,]+)*X$',
    ]);

    it('still exempts two atoms a separator genuinely divides', function (string $pattern): void {
        /*
         * ⚠️ "AT MOST ONE VARIABLE ATOM" WOULD HAVE BEEN SIMPLER AND WOULD REFUSE THESE, and the
         * first of them measures FLAT — n=24 at 0 ms — because `[^,-]` can match neither the comma
         * nor the hyphen, so both boundaries are forced. Refusing a shape that measures linear is
         * the expressiveness cost this project reports rather than accepts by default, so the rule
         * is the precise one.
         */
        expect(Pattern::unpublishable($pattern))->toBeNull("[{$pattern}] divides one way");
    })->with([
        '^(?:,[^,-]+-[^,-]+)*X$',
        // One variable atom with a required literal in front of it: forced by the delimiter alone.
        '^(?:,a[^,]+)*X$',
        '^(?:,ab)*X$',
    ]);

    it('analyses the pattern itself, not only its parenthesised frames', function (string $pattern): void {
        /*
         * ⚠️ EVERY RULE WAS DRIVEN BY `frames()`, so a pattern with no brackets at all was analysed by
         * none of them — review found `^a*a*a*a*a*a*b$` published, and Node 22.23.2 spends 26 SECONDS
         * on 100 characters and a failing one. A screen that is a loop over brackets cannot be the
         * whole screen.
         *
         * ⚠️ THE TOP LEVEL ADMITS TWO WHERE A REPETITION BODY ADMITS ONE, because the cost class
         * differs and both were measured. Here k adjacent atoms give a polynomial of degree k:
         *
         *   k=2  `^a*a*b$`          n=1000    2 ms    n=20000  572 ms
         *   k=3  `^a*a*a*b$`        n=1000  490 ms
         *   k=4  `^a*a*a*a*b$`      n=500   7.9 SECONDS
         *   k=6  `^a*a*a*a*a*a*b$`  n=100  26.4 SECONDS
         *
         * Inside a repetition the same k is exponential — `^(?:,a*a*)*X$` is 59.8 seconds at 20
         * segments — which is why one limit is not two.
         */
        expect(Pattern::unpublishable($pattern))
            ->not->toBeNull("[{$pattern}] runs variable atoms together with nothing to divide them");
    })->with([
        '^a*a*a*b$',
        '^a*a*a*a*a*a*b$',
        '^.*.*.*b$',
        '^[a-z]+[a-z]*[a-z]*$',
    ]);

    it('publishes two adjacent atoms, which is what real patterns are made of', function (string $pattern): void {
        /*
         * ⚠️ THE COST OF DRAWING THE LINE AT ONE would have been these, and they are the patterns
         * people actually write. Both measure 0 ms, and two adjacent atoms are quadratic in the
         * value's length — which `TextType` bounds by its configured `maxLength`, 255 by default.
         */
        expect(Pattern::unpublishable($pattern))->toBeNull("[{$pattern}] is quadratic and affordable");
    })->with([
        '^a*a*b$',
        '^.+\.[a-z]+$',
        '^[^@]+@[^@]+$',
    ]);

    it('sees a variable atom through a group on either side of a pending one', function (string $pattern): void {
        /*
         * ⚠️ THE PENDING ATOM WAS OVERWRITTEN RATHER THAN COMPARED, which review found: the previous
         * version recursed into a required group and ASSIGNED its pending atom from the result,
         * discarding whatever was pending outside — so `,a*(?:a*)` read as one atom rather than two
         * adjacent ones. Node 22.23.2: 59.4 SECONDS on 20 `,aa` segments and a failing `Y`, the same
         * as the ungrouped form.
         *
         * The walk flattens required groups into one list now, so there is no pending state to lose
         * and the bug cannot be written again by construction.
         */
        expect(Pattern::unpublishable($pattern))
            ->not->toBeNull("[{$pattern}] hides an adjacent variable atom inside a group");
    })->with([
        '^(?:,a*(?:a*))*X$',
        '^(?:,(?:a*)a*)*X$',
        '^(?:,(?:a*)(?:a*))*X$',
        '^(?:,(?:(?:a*))a*)*X$',
        /*
         * ⚠️ BOTH ATOMS INSIDE ONE GROUP, AND THIS IS THE CASE THE FLATTENING IS FOR. My first set of
         * cases here were all vacuous, which reverting the flattening is what showed: a required group
         * holding a variable atom reads as a variable atom itself, so every case with a star OUTSIDE
         * the group was already refused by that alone. With both stars INSIDE, the coarse reading sees
         * one atom and publishes it — measured 58 SECONDS on 20 `,aa` segments.
         */
        '^(?:,(?:a*a*))*X$',
        '^(?:,(?:(?:a*a*)))*X$',
    ]);

    it('tests delimiter membership against the pattern that is compiled', function (): void {
        /*
         * ⚠️ THE PROBE COMPILED A CLASS THAT IS NEVER COMPILED. `delimit()` rewrites `\s` to the
         * class ECMAScript means by it, because the dialects disagree on three code points — and
         * the membership probe asked PCRE about the RAW `\s` instead. PCRE's `\s` excludes U+FEFF,
         * so `^(?:<BOM>\s?)*X$` was told its optional atom could not consume the delimiter and was
         * exempted. ECMAScript's `\s` includes the BOM. Measured, Node 22.23.2: 40 BOMs followed by
         * a `Y` takes 17.2 SECONDS.
         *
         * ⚠️ THE SAME DISAGREEMENT CUTS THE OTHER WAY, and the fix has to get that direction right
         * too or it is just a stricter guess. `\S` is normalised as well, so `<BOM>\S?` cannot
         * consume the BOM in EITHER dialect once compiled — the division is forced and the pattern
         * is exempt. Testing the normalised atom is what makes both answers follow from one rule.
         */
        expect(Pattern::unpublishable('^(?:'."\u{FEFF}".'\s?)*X$'))
            ->not->toBeNull('ECMAScript\'s `\s` matches U+FEFF, so the optional atom can eat the delimiter')
            ->and(Pattern::unpublishable('^(?:'."\u{FEFF}".'\S?)*X$'))
            ->toBeNull('the normalised `\S` excludes U+FEFF in both dialects, so the division is forced');
    });

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

describe('ambiguity does not need a quantifier', function (): void {
    it('refuses ambiguous alternations whose combinations multiply', function (string $pattern): void {
        /*
         * ⚠️ A DIFFERENT AXIS FROM EVERY RULE ABOVE, which review found. `^` then thirty copies of
         * `(?:a|a)` then `b$` has no repetition anywhere and no variable-width atom, so nothing looked
         * at it — and each group offers two identical ways to match one character, so thirty offer
         * 2^30. On a 31-character failing subject, PCRE 10.48 exhausts its backtrack limit and Node
         * 22.23.2 takes 50.2 SECONDS. The pattern is 240 characters.
         *
         * ⚠️ A PRODUCT, NOT A COUNT, and measured to be exactly that — about 45 ns per combination on
         * Node, linearly: 3 ms at 2^16, 47 ms at 2^20, 3.1 s at 2^26. So the bound is on the product.
         */
        expect(Pattern::unpublishable($pattern))
            ->not->toBeNull("[{$pattern}] multiplies its ambiguous alternations past the bound");
    })->with([
        '^'.str_repeat('(?:a|a)', 30).'b$',
        '^'.str_repeat('(?:a|a)', 18).'b$',
        '^'.str_repeat('(?:a|ab)', 20).'c$',
    ]);

    it('publishes a pattern whose ambiguity stays under the bound', function (string $pattern): void {
        /*
         * ⚠️ ONE AMBIGUOUS ALTERNATION IS HARMLESS, and refusing it would cost a shape people write:
         * `(?:https|http|ftp)` has `http` as a prefix of `https`, so it IS ambiguous — and a product of
         * three is nothing. Sixteen binary ones are still only 65,536 combinations, at 3 ms.
         *
         * ⚠️ AND PREFIX-FREE BRANCHES DO NOT COUNT AT ALL, however many there are: at most one can
         * match at a position, so thirty copies of `(?:a|b)` are linear rather than 2^30.
         */
        expect(Pattern::unpublishable($pattern))->toBeNull("[{$pattern}] is affordable");
    })->with([
        '^(?:a|a)b$',
        '^'.str_repeat('(?:a|a)', 16).'b$',
        '^'.str_repeat('(?:a|b)', 30).'c$',
        '^(?:https|http|ftp)://[a-z]+$',
    ]);
});

describe('a character class means the same thing in both dialects', function (): void {
    it('refuses a class whose first member is a closing bracket', function (string $pattern): void {
        /*
         * ⚠️ PCRE READS A LITERAL, ECMASCRIPT READS AN EMPTY CLASS — so the dialects disagree about
         * what the pattern IS rather than about what it matches. `[]]` was already refused as "a
         * closing bracket nothing opened", so the scan caught the shape where nothing rebalanced it
         * and missed the shape where something did. Review found it. Measured on PCRE 10.48 with Node
         * 22.23.2, both handed the same source: `[]a[]` compiles in BOTH, PCRE matches `a`, and Node
         * reads an empty class then `a` then another empty class, so it can never match.
         */
        expect(Pattern::unpublishable($pattern))
            ->not->toBeNull("[{$pattern}] opens a class with a closing bracket");
    })->with(['[]a[]', '[^]a]', '[]]', '^[]$']);

    it('refuses a set escape used as a range endpoint', function (string $pattern): void {
        /*
         * ⚠️ THE COST OF ADMITTING A CONSTRUCT BY REWRITING IT, which review found. `delimit()` splices
         * `\s` into a character list, so PCRE compiles `[\b-\s]` and `compiles()` reports true —
         * while ECMAScript under `u` refuses a character-set escape as a range endpoint, so the raw
         * PUBLISHED pattern does not compile for a consumer at all. Measured: PCRE matches U+0008,
         * Node throws at construction.
         *
         * A rewrite is only equivalent where the syntax around it is equivalent too, and inside a range
         * it is not. Both ends are checked, because `[\s-x]` inverts the same mistake.
         */
        expect(Pattern::unpublishable($pattern))
            ->not->toBeNull("[{$pattern}] puts a set at one end of a range");
    })->with(['[\b-\s]', '[\s-x]', '[a-\s]', '[\d-x]']);

    it('leaves the ordinary class shapes alone', function (string $pattern): void {
        // ⚠️ The cost of both rules, bounded: an escaped `]`, a trailing or leading `-`, a set beside
        // a range rather than inside one, and a range of real character escapes.
        expect(Pattern::unpublishable($pattern))->toBeNull("[{$pattern}] is an ordinary class");
    })->with(['[\]]', '^[a-z-]+$', '^[-a-z]+$', '[\x41-\x5A]', '^[\s]+$', '^[a-z\s]+$', '^[^,]+$']);
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

    it('refuses a capture that a repetition runs more than once', function (string $pattern): void {
        /*
         * ⚠️ A FIXED WIDTH IS NOT ENOUGH IF THE CAPTURE RUNS TWICE, which review found after the
         * width rule. A width is a property of one iteration; WHICH iteration's text remains
         * captured is a property of the traversal, and the engines traverse a lookbehind in
         * opposite directions. Measured on PCRE 10.48 with Node 22.23.2, both readers handed
         * identical source text — the earlier attempt at this table was wrong for handing PHP
         * `\\1` through a shell, which is a literal backslash rather than a backreference, and
         * is the instrument error `tools/pattern-parity/README.md` records:
         *
         *                    aba    abb    aa     ab     aabaa  abab
         *   ([ab]){1,2} PCRE  no     MATCH  MATCH  no     MATCH  no
         *               Node  MATCH  no     MATCH  no     no     MATCH
         *   ([ab]){2}   PCRE  no     MATCH  no     no     MATCH  no
         *               Node  MATCH  no     no     no     no     MATCH
         *
         * ⚠️ `{2}` IS THE CASE THAT MAKES THIS A SECOND RULE rather than a wider net on the first.
         * It is a fixed repetition of a fixed-width body — the width rule passes it — and it
         * diverges on three of six subjects.
         *
         * ⚠️ AND AN ANCESTOR'S REPETITION COUNTS, because `(?:([ab])){1,2}` measures exactly like
         * `([ab]){1,2}`: the capture is written once and still runs twice.
         */
        expect(Pattern::unpublishable($pattern))
            ->not->toBeNull("[{$pattern}] repeats a capture inside a lookbehind");
    })->with([
        '(?<=([ab]){1,2})\1$',
        '(?<=([ab]){2})\1$',
        '(?<=(?:([ab])){1,2})\1$',
        '(?<=([ab])+)\1$',
        '(?<=([ab])*)\1$',
        '(?<=(?<x>[ab]){2})\k<x>$',
        // Two groups deep, so the search for a repeating ancestor cannot be a parent-only check.
        '(?<=(?:(?:([ab])){2}))\1$',
    ]);

    it('still publishes a capture that runs exactly once', function (): void {
        /*
         * ⚠️ `{1}` IS NOT A REPETITION, and this is where the line has to be drawn precisely rather
         * than by refusing every quantifier. `([ab]){1}` agrees with `([ab][ab])` and with the two
         * adjacent fixed captures on all six subjects measured above — there is only ever one
         * iteration, so there is nothing to reallocate.
         */
        expect(Pattern::unpublishable('(?<=([ab]){1})\1$'))->toBeNull()
            ->and(Pattern::unpublishable('(?<=([ab][ab]))\1$'))->toBeNull();
    });

    it('leaves a repetition OUTSIDE the lookbehind alone', function (): void {
        /*
         * ⚠️ A repetition outside re-runs the whole assertion rather than reallocating a capture
         * within one traversal, so it is not this rule's business.
         *
         * ⚠️ MEASURED ON SUBJECTS THAT ACTUALLY MATCH, because the first version of this
         * measurement used `^(?:(?<=(ab))c){1,2}$` — anchored at `^`, so the lookbehind can never
         * succeed and the pattern matches nothing at all. Both engines "agreed" that nothing
         * happened, which is not agreement about the construct. `^(?:ab(?<=(ab))){1,2}\1$` matches
         * `abab` and `ababab` in BOTH engines and rejects `a`, `aa`, `aaa`, `ab` and `abababab` in
         * both.
         */
        expect(Pattern::unpublishable('^(?:ab(?<=(ab))){1,2}\1$'))->toBeNull();
    });

    it('leaves a variable-length capture alone OUTSIDE a lookbehind', function (): void {
        // The rule is about traversal direction, which only a lookbehind reverses.
        expect(Pattern::unpublishable('^(a+)\1$'))->toBeNull()
            ->and(Pattern::unpublishable('^(?=(a+))a\1$'))->toBeNull();
    });
});

describe('a lookbehind must match exactly one length', function (): void {
    it('refuses a nested alternation that makes the lookbehind variable', function (string $pattern): void {
        /*
         * ⚠️ RULE 2 CHECKED THE TOP LEVEL AND A NEST SAT OUTSIDE IT — review found it.
         * `(?<=([ab])(?:a|aa))\1$` has no top-level alternation at all, so nothing looked, and both
         * engines compile it while disagreeing about what it matches: PCRE takes `baab` and Node does
         * not, Node takes `baaa` and PCRE does not. The capture is fixed-width; the LOOKBEHIND is not,
         * at two characters or three.
         *
         * ⚠️ THE FIX IS THE SAME RULE STATED PROPERLY, not a second one. "Every alternative the same
         * fixed length" was always an approximation of "the lookbehind has one width", reached by
         * looking at the one place a width usually varies. Asking `fixedWidth()` about the body asks
         * the real question at any depth — and every case rule 2 already refused still fails, so the
         * two are one rule with the narrow message kept for the alternation an author wrote.
         */
        expect(Pattern::unpublishable($pattern))
            ->not->toBeNull("[{$pattern}] can match more than one length behind the position");
    })->with([
        '(?<=([ab])(?:a|aa))\1$',
        '(?<=(?:a|aa)([ab]))\1$',
        '(?<=a(?:b|bb)c)x$',
        '(?<!([ab])(?:a|aa))\1$',
    ]);

    it('still publishes a lookbehind of one fixed width, however it is written', function (string $pattern): void {
        // ⚠️ The cost, bounded: a nest whose branches are all the same width is still one width.
        expect(Pattern::unpublishable($pattern))->toBeNull("[{$pattern}] is one fixed width");
    })->with([
        '(?<=(ab))\1$',
        '(?<=([ab]{2})([bc]{2}))\2\1$',
        '(?<=a(?:b|c)d)x$',
        '(?<=(a{2}?|aa))b\1$',
    ]);
});

describe('nesting is not a Cartesian product', function (): void {
    it('does not multiply an alternation by the one it is nested in', function (): void {
        /*
         * ⚠️ `frames()` APPENDS A GROUP AS IT CLOSES, so a child arrives before its parent and the
         * containment skip ran with nothing recorded yet — review found it. Seventeen nestings of
         * `(?:<previous>|a)` were reported as 131,072 combinations and refused, although the expression
         * has eighteen alternative paths and 100,000 Node matches complete in 3 ms.
         */
        $nested = 'a';

        for ($i = 0; $i < 17; $i++) {
            $nested = '(?:'.$nested.'|a)';
        }

        expect(Pattern::unpublishable('^'.$nested.'$'))->toBeNull('harmless nesting was read as a product');
    });

    it('still multiplies a sequence, wrapped or not', function (): void {
        /*
         * ⚠️ THE HALF THAT MUST NOT MOVE. A group with no top-level alternation contributes no branches
         * and so does not cover its children — thirty sequential `(?:a|a)` inside one wrapper are each
         * counted, which is what keeps the 50-second case refused.
         */
        expect(Pattern::unpublishable('^'.str_repeat('(?:a|a)', 30).'b$'))->not->toBeNull()
            ->and(Pattern::unpublishable('^(?:'.str_repeat('(?:a|a)', 30).')b$'))->not->toBeNull();
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

        /*
         * ⚠️ REFUSED UNTIL REVIEW FOUND WHY, and it belongs on this list above all the others: two
         * lookaheads then a bounded dot is the commonest validation pattern there is. The run
         * traversal read THROUGH required groups, so both assertion bodies were inlined and
         * `.*` `[A-Z]` `.*` `[0-9]` `.{8,64}` read as one run of more than two. It measures 0.02 ms
         * on a 5,000-character failing value.
         */
        '^(?=.*[A-Z])(?=.*[0-9]).{8,64}$',
    ]);
});

describe('an assertion consumes nothing, so it cannot divide a run', function (): void {
    /*
     * ⚠️ THE RUN TRAVERSAL TREATED A LOOKAHEAD LIKE ANY OTHER REQUIRED GROUP, which review found —
     * splicing its body in made a literal inside it look like a separator. Both directions of that
     * are wrong, and both are asserted here, because a fix for either one alone would be half a rule.
     */
    it('refuses a run an assertion appeared to separate', function (string $pattern): void {
        /*
         * `^(?:,a*(?!b)a*)*X$` is `,a*a*` repeated: two variable-width atoms in a row inside a
         * repetition, which `RUN_INSIDE_REPETITION` forbids. Published before this. Node 22.23.2 on
         * `,aa` segments and no `X`:
         *
         *   10 segments   5.9 ms      16 segments    663.4 ms
         *   14 segments  74.6 ms      18 segments  6,018.7 ms
         *
         * ⚠️ AND DELETING THE ASSERTION GIVES THE SAME TIMES — 653.2 ms at sixteen — which is the
         * proof rather than the illustration: if the lookahead cost nothing, it was never there to
         * divide anything either.
         */
        expect(Pattern::unpublishable($pattern))
            ->not->toBeNull("[{$pattern}] hid a run behind a zero-width assertion");
    })->with([
        '^(?:,a*(?!b)a*)*X$',
        '^(?:,a*(?=b)a*)*X$',
        '^(?:,a*(?<!b)a*)*X$',
        '^(?:,a*(?<=b)a*)*X$',
    ]);

    it('still reads a run inside the assertion body itself', function (): void {
        /*
         * ⚠️ THE HALF THAT MAKING AN ASSERTION TRANSPARENT WOULD OTHERWISE LOSE. While the body was
         * spliced, `(?=a*a*a*b)` was visible to the top-level run rule; skipping the assertion
         * outright would hide it. A failing lookahead is re-divided exactly as a failing sequence is,
         * so its body is checked as its own sequence under the same limit.
         */
        expect(Pattern::unpublishable('^(?=a*a*a*b)x$'))
            ->toContain('variable-width atoms in a row');
    });

    it('does not let an assertion body be charged twice', function (): void {
        /*
         * ⚠️ WHICH IS WHY THE DESCENT LIVES IN THE REFUSAL RULE AND NOT IN THE WALK. My first version
         * descended into assertion bodies from the shared walk, so the PRICING caller — whose own
         * recursion already visits every group — charged `(?=a*a*a*b)` twice and refused this for
         * *"ways to retry a failing subject"* instead of naming the run. Same verdict, wrong reason,
         * and an operator sent to fix the wrong thing.
         */
        expect(Pattern::unpublishable('^(?=a*a*a*b)x$'))
            ->not->toContain('ways to retry a failing subject');
    });
});

describe('every spelling of "exactly once" is read through', function (): void {
    /*
     * ⚠️ A LITERAL LIST LET A GROUP HIDE A RUN, which review found after the equal-bounds fix made more
     * spellings possible. The atom traversal spliced a group whose quantifier was `''`, `{1}` or `{1}?`
     * — named one by one — so `{1,1}` and `{01}` stayed whole and the two `a*` inside became invisible:
     *
     *   ^(?:,(?:a*a*){1,1})*X$   accepted, and Node 24 takes ~6 s on sixteen `,aa` segments
     *   ^(?:,(?:a*a*){1})*X$     refused — the SAME expression
     *
     * `fixedRepetitions()` already answers "how many times, exactly", so asking it is both narrower and
     * wider than the list in the right directions, and there is one place deciding what "once" means
     * rather than two that can disagree.
     */
    it('refuses a run hidden behind any exactly-once group', function (string $pattern): void {
        expect(Pattern::unpublishable($pattern))->not->toBeNull("[{$pattern}] hid a run behind a quantifier");
    })->with([
        '^(?:,(?:a*a*){1})*X$',
        '^(?:,(?:a*a*){1,1})*X$',
        '^(?:,(?:a*a*){01})*X$',
        '^(?:,(?:a*a*){1}?)*X$',
    ]);

    it('still keeps a group that runs more than once whole', function (): void {
        /*
         * ⚠️ THE OTHER SIDE, or the splice would read a repeated group as its body run once. `(?:a*){2}`
         * is `a*a*` and must not read as one fixed atom — which is what `flatAtoms()`'s docblock has
         * said since the traversal was written.
         */
        expect(Pattern::unpublishable('^(?:,(?:a*a*){2})*X$'))->not->toBeNull()
            ->and(Pattern::unpublishable('^a*(?:a*){2}b$'))->not->toBeNull();
    });
});

describe('a lookahead may not assert what an adjacent optional atom consumes', function (): void {
    /*
     * ⚠️ I COULD NOT REPRODUCE THE DIVERGENCE, and this refusal is insurance rather than a measurement.
     * Review measured `(?=a)a?a` on PCRE 10.44 with Node 24.15 — PCRE not matching `a` while ECMAScript
     * does, so the published schema would accept what the server rejects. On PHP 8.4.25 / PCRE 10.48 /
     * Node 22.23.2 both engines match, and so do six neighbouring shapes.
     *
     * ⚠️ SO WHY REFUSE SOMETHING THIS PAIR AGREES ON: the shape is REDUNDANT. A lookahead asserting the
     * character an adjacent optional atom consumes constrains nothing that atom does not — it is `a?a`
     * with a no-op beside it. Nobody writes it deliberately, so the expressiveness cost is approximately
     * zero, and `composer.json` requires PHP `^8.4` whose earliest releases bundle PCRE2 10.44. Cheap
     * insurance against a real deployment beats a rule that is right on one pair.
     *
     * ⚠️ FOUR ROUNDS FOUND FOUR WAYS PAST IT AND THEY WERE ALL ONE WAY — the rule read the pattern more
     * narrowly than the shape occurs. The rows below are one per way: a group over the shape, a group
     * over the asserted lead, a quantifier over the lead, and the optional atom on the other side.
     */
    it('refuses the overlap', function (string $pattern): void {
        expect(Pattern::unpublishable($pattern))->toContain('which can match the same');
    })->with([
        '(?=a)a?a',
        '(?=a)a*a',
        '(?=a)a{0,2}a',
        '(?=ab)a?ab',

        /*
         * ⚠️ AND INSIDE A GROUP, WHICH THE FIRST VERSION WALKED PAST. `atomAt()` returns a whole group as
         * ONE atom, so the scan advanced over the nested lookahead and published — a structural rule that
         * a pair of brackets defeats is not a rule. Every frame kind is descended now, assertions
         * included, since a lookahead can hold the shape as readily as a group can.
         */
        '(?:(?=a)a?a)',
        '(?:(?:(?=a)a?a))',
        '(?=(?=a)a?a)x',

        /*
         * ⚠️ AND WITH THE OPTIONAL ATOM ON THE OTHER SIDE, which review found next: the rule asked what
         * FOLLOWED the lookahead, so the same redundancy written backwards published. `a{0}` is the form
         * review measured; `a?` and `(?:a)?` are the same shape and were published too.
         */
        'a?(?=a)a',
        '(?:a)?(?=a)a',

        // ⚠️ And the dead atom no longer SHADOWS the real neighbour: `b{0}` consumes nothing, so the
        // atom before it is what sits beside the lookahead. This row published until it was transparent.
        'a?b{0}(?=a)a',

        /*
         * ⚠️ AND WITH THE LEAD BEHIND BRACKETS OR A QUANTIFIER. `leadingLiteral()` answers a different
         * question — it proves an iteration begins with exactly one delimiter, so a quantifier
         * disqualifies the atom — and using it here meant `(?:a)` and `a+` hid the asserted character.
         */
        '(?=(?:a))(?:a)?a',
        '(?=(?:(?:a)))a?a',
        '(?=a+)a?a',
        '(?=a{2,5})a?a',
        '(?=(?=b)a)a?a',

        // ⚠️ Two atoms written identically match identically, which is the proof for a lead that is a
        // class rather than one character — and `(?=[0-9])[0-9]?[0-9]` was published until it existed.
        '(?=[0-9])[0-9]?[0-9]',
        '(?=[A-Za-z])[A-Za-z]*',
        '(?=\\()\\(?\\(',

        /*
         * ⚠️ AND WITH THE QUANTIFIER ONE LEVEL IN, which is the fifth bracket to defeat a version of
         * this rule — found by probing rather than by review. `(?:a?)` carries no quantifier of its own
         * and matches nothing just as readily as `a?` does, so the question had to become "can this atom
         * match nothing" rather than "is this atom quantified". An empty branch is the same thing said
         * another way.
         */
        '(?=a)(?:a?)a',
        '(?=a)(?:a*)a',
        '(?=a)(?:(?:a?))a',
        '(?=a)(?:|a)a',
        '(?=a)(?:a|)a',
        '(?:a?)(?=a)a',

        /*
         * ⚠️ AND A WRAPPER ROUND THE LOOKAHEAD ALONE, which review found: the recursion checked the
         * assertion with no neighbour inside the wrapper, and the outer walk read the wrapper as an
         * ordinary atom — so the `a?` outside was never compared with the assertion inside. A group
         * that consumes nothing is not a group for adjacency, so such wrappers are unwrapped first, to
         * a fixed point and at any depth. The `^` rows are here because this half of the rule does not
         * care about anchoring: an overlap is an overlap.
         */
        '(?:(?=a))a?a',
        '((?=a))a?a',
        '(?:(?:(?=a)))a?a',
        '(?:(?=a)(?!b))a?a',
        '^(?:(?=a))a?a',

        /*
         * ⚠️ AND THE FORWARD NEIGHBOUR IS THE NEXT ATOM THAT CONSUMES, here too. This arm used a plain
         * `atomAt()` while the unanchored arm used `nextConsuming()` and `$previous` had skipped
         * zero-width atoms since it was written, so `(?=a)(?!b)a?a` was refused only for being
         * unanchored and its anchored spelling published. Three walks, one fact about adjacency.
         */
        '(?=a)(?!b)a?a',
        '^(?=a)(?!b)a?a',
        '^(?=a)(?<!x)a?a',
    ]);

    it('leaves a lookahead that constrains something alone', function (string $pattern): void {
        /*
         * ⚠️ WHERE THE RULE STOPS, and the first row is why it has to be narrow: two lookaheads then a
         * bounded dot is the commonest validation pattern there is, and `.{8,64}` is not optional. The
         * second asserts a character the optional atom cannot match, so the assertion does work.
         *
         * ⚠️ THE ANCHORS ON THESE ROWS ARE LOAD-BEARING and they were added, not written: a separate
         * rule refuses a positive lookahead in front of a nullable atom when NOTHING ANCHORS the search,
         * whatever it asserts — see the block below. Without the `^` these rows would pass for the wrong
         * reason, which is worse than failing.
         */
        expect(Pattern::unpublishable($pattern))->toBeNull("[{$pattern}] has a lookahead that constrains");
    })->with([
        '^(?=.*[A-Z])(?=.*[0-9]).{8,64}$',
        '^(?=b)a?a',
        '(?=a)a+',
        '(?!a)a?a',

        // ⚠️ And the same non-overlapping shapes inside a group, or the recursion would have widened the
        // rule rather than extending its reach.
        '^(?:(?=b)a?a)',
        '(?:(?!a)a?a)',

        /*
         * ⚠️ WHERE THE WIDENING STOPS, and each row is a way the rule could have over-reached instead.
         * `^(?=[A-Za-z])[A-Za-z0-9]*$` is the identifier pattern every schema has: the assertion excludes
         * a leading digit where the neighbour admits one, so it is not redundant and an intersection test
         * would have refused it. An alternation asserts neither branch. A branch boundary is not
         * adjacency. And a LOOKBEHIND is not in the rule at all — the divergence measured is a lookahead.
         */
        '^(?=[A-Za-z])[A-Za-z0-9]*$',
        '^(?=[A-Z])[a-z]*[A-Z]$',
        '^(?=(?:a|b))a?a',
        '^(?=(?:ab))b?ab',
        'a?|(?=a)a',
        '(?=a)|a?a',
        '(?<=a)b?b',

        /*
         * ⚠️ A KNOWN LIMIT RATHER THAN AN OVERSIGHT, asserted so it is a decision. `(?:ab)?` can consume
         * the asserted `a` as its FIRST character, so the shape is redundant here too — and proving that
         * needs reasoning about what follows the neighbour, which is where a wrong answer costs a false
         * refusal: in `(?=a)(?:ax)?y` the assertion excludes every subject starting `y`, so it does
         * constrain something. The overlap test asks whether the neighbour as a WHOLE can match the
         * asserted character, and `(?:b?)` cannot match `a` at all.
         */
        '^(?=a)(?:ab)?a',
        '^(?=a)(?:b?)a',

        // ⚠️ A wrapper that CONSUMES is still a group: the lookahead's neighbour in `(?:(?=a)x)a?a` is
        // the `x` inside it, not the `a?` outside, and unwrapping that would be a false refusal.
        '^(?:(?=a)x)a?a',
    ]);
});

describe('an equal bounded quantifier is one width', function (): void {
    /*
     * ⚠️ A FALSE REFUSAL THE DOCUMENT DOES NOT LICENSE, which review found: `fixedRepetitions()` read
     * only `{n}`, so the lookbehind rule called `{n,n}` variable — while `field-types.md` §3 permits
     * `{n,m}`. Both engines compile it and agree exactly, measured at production fidelity:
     *
     *   (?<=a{1,1})b   PCRE ab=1 aab=1     ECMAScript ab=1 aab=1
     *   (?<=a{2,2})b   PCRE ab=0 aab=1     ECMAScript ab=0 aab=1
     *
     * ⚠️ EQUAL BOUNDS, NOT A BOUNDED FORM, which is where this stops: `{1,2}` really can match two
     * lengths and stays refused. The distinction is the two numbers being the same rather than the comma
     * being present, and getting that wrong in the other direction would admit the rule's whole subject.
     */
    it('accepts a lookbehind whose bound is equal on both sides', function (string $pattern): void {
        expect(Pattern::unpublishable($pattern))->toBeNull("[{$pattern}] is one width");
    })->with([
        '(?<=a{1,1})b',
        '(?<=a{2,2})b',
        '(?<=a{2})b',
    ]);

    it('still refuses a lookbehind that can match two lengths', function (): void {
        expect(Pattern::unpublishable('(?<=a{1,2})b'))->toContain('more than one length');
    });

    it('normalises both bounds, not only the lower one', function (): void {
        /*
         * ⚠️ THE SAME FIX WAS NEEDED IN THE METHOD BESIDE IT, which review found: `isVariableWidth()`
         * compared the RAW upper bound against a normalised lower one — `'01' !== '1'` — so `{01,01}`
         * read as variable while `{1,1}` did not, and `^a*a{01,01}a*b$` was refused while the identical
         * `{1,1}` pattern publishes. The same upgrade hazard as the two `{0}` spellings, one method
         * along, because a padded bound is the same number and only `(int)` on both sides says so.
         */
        expect(Pattern::unpublishable('^a*a{01,01}a*b$'))->toBeNull()
            ->and(Pattern::unpublishable('^a*a{1,1}a*b$'))->toBeNull()
            ->and(Pattern::unpublishable('(?<=a{01,01})b'))->toBeNull();

        // ⚠️ And a genuine range is still variable, in both spellings, or the normalising would have
        // swallowed the rule rather than the padding.
        expect(Pattern::unpublishable('^a*a{1,2}a*b$'))->not->toBeNull()
            ->and(Pattern::unpublishable('^a*a{01,02}a*b$'))->not->toBeNull()
            ->and(Pattern::unpublishable('^a*a{1,}a*b$'))->not->toBeNull();
    });
});

describe('a zero-repeat group may not hold an assertion', function (): void {
    /*
     * ⚠️ A DIVERGENCE RATHER THAN A COST, and review found it inside the `{0}` skip added the round
     * before. Measured at production fidelity on PCRE 10.48 and Node 22.23.2:
     *
     *   (?:a|(?=a)){0}     PCRE no match on "b" AND on ""     ECMAScript matches both
     *   (?:a|(?=z)){0}     PCRE no match                       ECMAScript matches
     *   (?:(?=a)|a){0}     both match           <- the ORDER matters
     *   (?:a|(?<=a)){0}    both match           <- lookAHEAD only
     *   (?:a){0}, a{0}     both match
     *
     * PCRE stops matching when a dead group's alternation ends in a positive lookahead; ECMAScript
     * skips the group outright. A generated client would accept every value this server rejects, which
     * is what rule 3 of the field-type contract forbids.
     */
    it('refuses the shape both engines disagree about', function (string $pattern): void {
        expect(Pattern::unpublishable($pattern))->toContain('bounded at zero repetitions');
    })->with([
        '(?:a|(?=a)){0}',
        '^(?:a|(?=a)){0}$',
        '(?:a|(?=a)){0,0}',
        '(?:(?=a)|a){0}',
    ]);

    it('leaves a dead group with no assertion alone', function (string $pattern): void {
        /*
         * ⚠️ THE RULE IS WIDER THAN THE QUIRK AND THIS IS WHERE THAT STOPS. Encoding "an alternation
         * whose last branch is a positive lookahead" would be a shape nobody can check by reading it,
         * so any assertion in a dead group is refused — but a dead group WITHOUT one still publishes,
         * which is the upgrade hazard the `{0}` skip exists for.
         */
        expect(Pattern::unpublishable($pattern))->toBeNull("[{$pattern}] has no assertion to disagree about");
    })->with([
        '^a*a*(?:a*){0}b$',
        '^a*a*(?:a|b){0}b$',
        '^a*a*(?:ab){0}b$',
    ]);
});

describe('an anchor inside a dead group is an assertion too', function (): void {
    /*
     * ⚠️ MEASURED ON THIS PAIR, so it is a divergence rather than insurance — PCRE 10.48 and Node
     * 22.23.2, at production fidelity:
     *
     *   (?:a|^){0}$     PCRE no match on `a`     ECMAScript matches
     *   (?:^|a){0}$     both match               <- branch order matters, as for the lookahead form
     *   ^(?:a|^){0}$    both refuse              <- and so does what encloses it
     *
     * PCRE's start-anchor optimisation survives the dead group; ECMAScript skips the group outright,
     * so a generated client would accept every value the server rejects. `containsAssertion()` looked
     * only at parenthesised frames, so a group holding an anchor reported none at all.
     */
    it('refuses it wherever the anchor sits', function (string $pattern): void {
        expect(Pattern::unpublishable($pattern))->toContain('a group bounded at zero repetitions');
    })->with([
        '(?:a|^){0}$',
        '(?:^|a){0}$',
        '^(?:a|^){0}$',
        '(?:a|$){0}b',
        '(?:(?:^)){0}b',
    ]);

    it('leaves a literal that merely looks like an anchor alone', function (string $pattern): void {
        /*
         * ⚠️ PARSED RATHER THAN SEARCHED FOR, which is the whole reason this is not `str_contains()`:
         * `\^` is an escaped literal and `[$]` is a class member, and neither asserts anything.
         */
        expect(Pattern::unpublishable($pattern))->toBeNull("[{$pattern}] holds no assertion");
    })->with([
        '(?:a|\\^){0}$',
        '(?:a|[$]){0}b',
        '(?:a){0}b',
        'a{0}b',
    ]);
});

describe('a group bounded at zero repetitions is not there', function (): void {
    /*
     * ⚠️ AN UPGRADE HAZARD RATHER THAN A HOLE, which is why it is a refusal being removed. Review found
     * `^a*a*(?:a*){0}b$` REFUSED while `^a*a*b$` — the same regular expression — is deliberately
     * admitted. A `{0}` group never runs, so it can neither fill a variable-atom run nor cost anything,
     * and on an upgrade `kitsune:audit-patterns --strict` would have blocked a deploy over a pattern
     * that saved yesterday. §4 calls that a broken install rather than a fixed one.
     */
    it('skips a repetition its zero-repeat ancestor can never run', function (): void {
        /*
         * ⚠️ THE FRAMES LOOP READ ONLY THE CURRENT FRAME'S QUANTIFIER, which review found: `^(?:(a|aa)+){0}$`
         * was refused for the inner `+` although the group holding it executes zero times. Both engines
         * match only the empty string, and `--strict` was blocking a deploy over a harmless legacy row.
         * The same argument as the direct `{0}` case, one level out.
         */
        expect(Pattern::unpublishable('^(?:(a|aa)+){0}$'))->toBeNull()
            ->and(Pattern::unpublishable('^(?:(a|aa)+){1}$'))
            ->not->toBeNull('the ancestor runs once, so the inner repetition still counts');
    });

    it('accepts a pattern a zero-repeat group only appears to lengthen', function (string $pattern): void {
        expect(Pattern::unpublishable($pattern))->toBeNull("[{$pattern}] is `^a*a*b$` with dead markup");
    })->with([
        '^a*a*(?:a*){0}b$',
        '^a*(?:a*){0}a*b$',
        '^a*(?:a*){0,0}b$',
        '^a*a*(?:a|a){0}b$',

        /*
         * ⚠️ ZERO-PADDED, which review found the first fix missing: it matched ONE leading zero, so
         * these were still refused although both engines accept them and neither group ever runs. The
         * bound is parsed numerically now, which is what `repeatsMoreThanOnce()` beside it already did.
         */
        '^a*a*(?:a*){00}b$',
        '^a*a*(?:a*){00,00}b$',
    ]);

    it('still refuses what the group would have cost if it ran', function (string $pattern): void {
        /*
         * ⚠️ ONLY AN UPPER BOUND OF ZERO, or the skip would swallow `{0,}` — which is zero-or-MORE and
         * is exactly the unbounded repetition rule 3 exists for. And the genuine three-atom run has to
         * stay refused, or this would have bought its acceptance with a `{0}` somewhere else.
         */
        expect(Pattern::unpublishable($pattern))->not->toBeNull("[{$pattern}] runs and must be priced");
    })->with([
        '^a*(?:a*){0,}a*b$',
        '^a*a*a*b$',

        // ⚠️ And `{1}` runs exactly once, so the group is still an atom in the run.
        '^a*(?:a*){1}a*b$',
    ]);
});

describe('a backreference is not a character this proof can read', function (): void {
    /*
     * ⚠️ TWO DEFECTS IN ONE ATOM, both found by review and both about the SCAN rather than the rules.
     *
     * First, only the leading digit of a numeric reference was consumed, so `\10?` scanned as `\1`
     * followed by a separate `0?` — and the delimiter proof read a required comma then an optional zero,
     * which looks divided.
     *
     * Second, and the one that mattered even after the digits were fixed: the proof PROBES an atom
     * against the delimiter, and probing a backreference in isolation answers a different question. The
     * two spellings answered it by accident in opposite directions:
     *
     *   /^\1$/uD    PCRE cannot compile a reference to a group that is not there, so preg_match()
     *               returns FALSE and the test failed closed — correct, by luck
     *   /^\10$/uD   PCRE reads it as an OCTAL escape instead, compiles, does not match `,`, and the
     *               same test concluded the atom cannot consume the delimiter
     *
     * Measured on Node 22.23.2, 40 commas and no `X`: 1,555 ms for the published pattern and 1,470 ms
     * for `^(?:,,?)*X$` written out, which was already refused. Same cost, opposite verdict.
     */
    it('refuses a repetition a multi-digit reference appeared to delimit', function (): void {
        $pattern = '^()()()()()()()()()(,)(?:,\\10?)*X$';

        expect(mb_strlen($pattern))->toBe(34, 'the pattern under measurement is not the one reported')
            ->and(Pattern::unpublishable($pattern))->not->toBeNull()
            ->and(Pattern::unpublishable('^(,)(?:,\\1?)*X$'))
            ->not->toBeNull('the single-digit spelling was already refused and must stay so');
    });

    it('leaves a required backreference alone', function (string $pattern): void {
        /*
         * ⚠️ ONLY A VARIABLE-WIDTH ONE FAILS CLOSED, or the grammar would lose backreferences entirely.
         * A required reference has no quantifier, so the proof has already moved past it — and these are
         * the shapes a field pattern actually uses one for.
         */
        expect(Pattern::unpublishable($pattern))->toBeNull("[{$pattern}] is an ordinary backreference");
    })->with([
        '^(a)\\1$',
        '^(a)(b)\\2\\1$',
        '^(?<word>[a-z]+)-\\k<word>$',
    ]);
});

describe('a group separates a run only if every branch does', function (): void {
    /*
     * ⚠️ THE FIRST BRANCH WAS TAKEN FOR THE GROUP, which review found. `^a*(?:b|a)*a*c$` read as
     * beginning with `b` — a character `a*` cannot match — so the run reset and the pattern published,
     * although its `a` branch means all three quantified atoms consume the same input.
     *
     * ⚠️ AND THE PROOF IS THAT DELETING THE OTHER BRANCH CHANGES NOTHING, which is the same instrument
     * the assertion finding used. Node 22.23.2, n `a` and no `c`:
     *
     *   ^a*(?:b|a)*a*c$    n=500  61.9 ms    n=1000  490.1 ms    n=2000  3,924.7 ms
     *   ^a*(?:a)*a*c$      n=500  62.2 ms    n=1000  488.5 ms    n=2000  3,877.2 ms
     *
     * Identical, and cubic — eight times per doubling. The `b` branch contributes nothing to the cost,
     * so reading it as the group's lead was reading a separator the engine does not have.
     */
    it('refuses a run a single branch appeared to separate', function (): void {
        expect(Pattern::unpublishable('^a*(?:b|a)*a*c$'))
            ->toContain('variable-width atoms in a row');
    });

    it('still accepts a group every branch of which separates', function (string $pattern): void {
        /*
         * ⚠️ THE OTHER HALF, or the fix would be a ban on alternation inside a repeated group. `b` and
         * `c` are both unmatchable by `a*`, so the boundary is forced whichever branch runs — and the
         * ordinary delimited list is the shape this whole rule exists to keep publishable.
         */
        expect(Pattern::unpublishable($pattern))->toBeNull("[{$pattern}] is separated by every branch");
    })->with([
        '^a*(?:b|c)*a*d$',
        '^[^,]+(?:,[^,]+)*$',
        '^[a-z]+(?:-[a-z]+)*$',
    ]);

    it('treats a branch with no leading literal as no separator at all', function (): void {
        /*
         * ⚠️ NULL RATHER THAN A SHORTER LIST, because a branch that can begin with anything separates
         * nothing — and a list missing that branch would look like proof the group is delimited. Both
         * of these are already refused by the repetition rule, which is why the assertion is on
         * `branchLeads()`'s contract through a shape that reaches the run check instead.
         */
        expect(Pattern::unpublishable('^a*(?:[a-z]|b)*a*c$'))
            ->not->toBeNull('a class branch was read as a separator');
    });
});

describe('a quantified branch costs what it costs', function (): void {
    /*
     * ⚠️ A SEQUENCE'S COST COUNTED ONLY THE GROUPS INSIDE IT, so a branch whose expense is a
     * quantifier read as free — review found 140 alternatives of `a*a*b` published at 845 characters
     * and about 5.0 seconds on a permitted 5,000-character failing value.
     *
     * Each branch pays the quadratic in turn, measured linear in N on the same subject:
     *
     *   N=1    35.7 ms      N=8    291.0 ms      N=32   1,151 ms
     *   N=2    71.6 ms      N=9    324.3 ms      N=140  5,037 ms
     */
    it('charges every run in a sequence, not one per sequence', function (): void {
        /*
         * ⚠️ THIS DISPROVES A LIMIT I STATED AS DELIBERATE, which is why the docblock records it rather
         * than quietly widening. `sequenceCost()` charged one grant per sequence and argued that a
         * second run in the same branch is never reached, because a subject failing in the first stops
         * there. That is wrong the moment the first run's separator MATCHES: the engine goes on, and
         * every allocation of the first run is retried against the second.
         *
         * Measured on Node 22.23.2, `^a*a*ba*a*c$` against `a×n . b . a×n . d`:
         *
         *   1,202 chars     317.5 ms        and one run at 4,802 chars: 33.4 ms
         *   2,402 chars   2,512.9 ms
         *   4,802 chars  20,016.4 ms        within the configured ceiling
         *
         * Eight times per doubling against four for one run.
         */
        expect(Pattern::unpublishable('^a*a*ba*a*c$'))
            ->toContain('ways to retry a failing subject')
            ->and(Pattern::unpublishable('^a*a*b$'))
            ->toBeNull('one run is still the admitted anchor');
    });

    it('still publishes one quadratic run, which is deliberate', function (string $pattern): void {
        /*
         * ⚠️ AND THE GROUPED SPELLING TOO, because charging the run at more than one level would cost
         * the square and refuse one of two ways of writing the same regular expression. That is what
         * `ownAtoms()` exists for.
         */
        expect(Pattern::unpublishable($pattern))->toBeNull("[{$pattern}] is the admitted quadratic");
    })->with([
        '^a*a*b$',
        '^(?:a*a*b)$',
        '^(?:(?:a*a*b))$',
        '^.+\.[a-z]+$',
    ]);

    it('publishes eight quadratic branches and refuses nine', function (): void {
        /*
         * ⚠️ THE BOUNDARY, NOT A SAMPLE, because the constant is the budget divided by the count and an
         * off-by-one in either would move it silently. Eight grants reach 65,536 exactly.
         */
        $branch = fn (int $n): string => '^(?:'.implode('|', array_map(
            fn (int $i): string => 'a*a*'.chr(98 + $i),
            range(0, $n - 1),
        )).')$';

        expect(Pattern::unpublishable($branch(8)))->toBeNull('eight quadratic branches is the bound')
            ->and(Pattern::unpublishable($branch(9)))
            ->toContain('ways to retry a failing subject');
    });

    it('refuses the 140-branch pattern review measured', function (): void {
        $pattern = '^(?:'.implode('|', array_fill(0, 140, 'a*a*b')).')$';

        expect(mb_strlen($pattern))->toBe(845, 'the pattern under measurement is not the one reported')
            ->and(Pattern::unpublishable($pattern))->not->toBeNull();
    });

    it('charges the ambiguity product and the run together', function (): void {
        /*
         * ⚠️ ONE BUDGET, SO THEY COMPOSE. A quadratic branch retried sixteen ways is sixteen
         * quadratics, and sixteen ambiguous binary alternations were admitted on their own. With a run
         * beside them the product passes the budget, which is the model being consistent rather than a
         * new rule: `8,192 × 2^n` reaches 65,536 exactly at n=3 and passes it at n=4.
         */
        $binaries = fn (int $n): string => '^a*a*b'.str_repeat('(?:x|x)', $n).'$';

        expect(Pattern::unpublishable($binaries(3)))->toBeNull()
            ->and(Pattern::unpublishable($binaries(4)))
            ->toContain('ways to retry a failing subject');
    });
});

describe('an unanchored pattern pays for its own search', function (): void {
    /*
     * ⚠️ THE ALLOWANCE'S PREMISE WAS ANCHORING AND THE RULE NEVER ASKED FOR IT, which review found. Two
     * adjacent variable-width atoms are quadratic in the value's length — that is why they are permitted
     * — but an unanchored pattern is retried from EVERY starting position, so the same run is cubic, and
     * cubic is the cost class the limit exists to refuse. Measured on Node 22.23.2, all-`a` subjects
     * that fail:
     *
     *   n         `a*a*b`        `a*a*b$`       `^a*a*b`
     *   500          286.4 ms       285.1 ms        1.8 ms
     *   1,000        489.7 ms       491.5 ms        1.5 ms
     *   2,000      3,941.8 ms     3,875.7 ms        5.8 ms
     *   5,000     60,231.6 ms    60,339.4 ms       36.0 ms   <- `TextType::MAX_CONFIGURABLE_LENGTH`
     *
     * ⚠️ A TRAILING `$` DOES NOT HELP, which is the middle column and a row below: the retry is at the
     * START, so only `^` removes it. Reasoning would have got that wrong in a plausible direction.
     *
     * ⚠️ AND THE SERVER DOES NOT PAY IT. Every cell above is 0.0 ms through `delimit()` on PCRE 10.48,
     * which auto-possessifies the stars and knows the subject must contain a `b`. So the harness cannot
     * see this by comparing verdicts — the engines agree — and it is exactly what rule 3 is about: the
     * published constraint costs the consumer what the server never pays.
     */
    it('refuses two variable-width atoms in a row when nothing anchors the search', function (string $pattern): void {
        expect(Pattern::unpublishable($pattern))->toContain('not anchored');
    })->with([
        'a*a*b',
        'a*a*b$',
        '.+\.[a-z]+',

        // ⚠️ A group is not an anchor, an ALTERNATION of anchors is not one unless every branch anchors,
        // and anchoring is per branch — `a*a*b|^a*a*c` anchors the second branch and not the first.
        '(?:a*a*b)',
        '(?:^|,)a*a*b',
        'a*a*b|^a*a*c',
    ]);

    it('grants the allowance when the branch anchors the search', function (string $pattern): void {
        expect(Pattern::unpublishable($pattern))->toBeNull("[{$pattern}] anchors its search");
    })->with([
        '^a*a*b',
        '^a*a*b$',
        '^.+\.[a-z]+$',
        '^[^@]+@[^@]+$',

        // ⚠️ Read through what is transparent: a required group, and a leading assertion that consumes
        // nothing. Both are anchored patterns written in a way the first version could not see.
        '(?:^a*a*b)',
        '^(?:a*a*b)$',
        '(?=x)^a*a*b',

        // ⚠️ And ONE variable-width atom is linear, so it needs no anchor at all. A rule that asked for
        // one everywhere would refuse most of the patterns in this file for nothing.
        'a*b',
        'a*b$',
    ]);
});

describe('a branch boundary ends a run', function (): void {
    /*
     * ⚠️ A FALSE REFUSAL REVIEW FOUND, and the mechanism is that `|` reached the run walk as an ordinary
     * non-variable atom: `literalCharacter('|')` is null, so nothing reset the state and two mutually
     * exclusive branches read as one sequence of three atoms. No execution path contains three, and
     * `kitsune:audit-patterns --strict` blocks an upgrade on a pattern like this.
     *
     * ⚠️ THE SAME INSIGHT AS THE OTHER TRAVERSAL, one round apart: `lookaheadOverlapsOptional()` had to
     * learn that nothing sits in front of the lookahead in `a?|(?=a)a` for the same reason. Two walks,
     * one fact about alternation, and each had to be told separately — which is an argument for the
     * walks being one, and the reason they are not is that they ask different questions of each atom.
     */
    it('reads two branches as two sequences rather than one', function (string $pattern): void {
        expect(Pattern::unpublishable($pattern))->toBeNull("[{$pattern}] has two atoms per path, not three");
    })->with([
        '^a*a*|^b*',
        '^a*|^b*b*',
        '^(?:a*a*|b*)$',
        '^(?:a*|b*b*)$',
        '^(?:a*a*b|c*c*d)$',
    ]);
});

describe('an unanchored lookahead in front of a nullable atom asserts nothing', function (): void {
    /*
     * ⚠️ ANOTHER SHAPE ON THE 10.44 PAIR, one exemption away from the overlap rule. Review measured
     * `(?=a)b*a` on PCRE 10.44 with Node 24.15 — PCRE rejecting `a` while ECMAScript matches it — and
     * the ANCHORED spelling agreeing. `b*` cannot match the asserted `a`, so the overlap rule passes it
     * on purpose, and the divergence is there anyway. On PHP 8.4.25 / PCRE 10.48 / Node 22.23.2 both
     * engines match, as they do for every shape in the overlap block.
     *
     * ⚠️ AND IT IS REDUNDANT, which is why refusing costs so little. A search may begin wherever it
     * likes, so an unanchored assertion in front of something that can match nothing decides nothing
     * the search had not already decided: `(?=a)b*a` and `b*a` accept the same set of values, and
     * `preg_match()` and a JSON Schema `pattern` both ask only whether a match exists.
     */
    it('refuses the shape wherever nothing anchors the search', function (string $pattern): void {
        expect(Pattern::unpublishable($pattern))->toContain('nothing anchors');
    })->with([
        '(?=a)b*a',
        '(?=a)b*',
        '(?=a)(?:b*)a',
        '(?:(?=a)b*a)',
        'x(?=a)b*a',

        /*
         * ⚠️ THE NEXT CONSUMING ATOM, NOT THE NEXT ATOM, which review found one round after the rule
         * landed: a second assertion in between made the immediate neighbour consume nothing, and the
         * shape published. `$previous` had skipped zero-width atoms since it was written and this side
         * had not — one fact, two places, one of them told.
         */
        '(?=a)(?!b)b*a',
        '(?=a)(?<!x)b*a',
        '(?=a)(?=c)(?!b)b*a',

        // ⚠️ No asserted lead is needed for THIS rule, and a `continue` for the overlap rule's missing
        // lead took this one with it in the first version: `(?=(?:a|b))a?a` published.
        '(?=(?:a|b))a?a',

        // ⚠️ Anchoring is per branch, so a pattern that anchors one branch is refused for the other.
        '^(?=a)b*a|(?=a)b*a',
        '(?=a)b*a|^(?=a)b*a',
    ]);

    it('leaves the anchored spelling and the non-nullable one alone', function (string $pattern): void {
        expect(Pattern::unpublishable($pattern))->toBeNull("[{$pattern}] anchors, or its atom is required");
    })->with([
        '^(?=a)b*a',
        '^(?=a)b*a$',
        '(?:^)(?=a)b*a',
        '^(?:(?=a)b*a|x)',

        // ⚠️ `b+` must consume something, so the assertion is not in front of a nullable atom at all.
        '(?=a)b+a',

        // ⚠️ A lookBEHIND is not in the rule: the measured divergence is a lookahead, and a rule wider
        // than its evidence here would refuse `(?<=a)b*a` for nothing.
        '(?<=a)b*a',

        // ⚠️ And nothing follows the lookahead in either of these, so there is no nullable atom to be
        // in front of — `|` does not consume, and a rule that read it as an atom would refuse both.
        '(?=a)|a?a',
        'a?|(?=a)a',

        // ⚠️ A `^` AFTER the lookahead stops the walk rather than being skipped: the pattern is
        // anchored after all, and `(?=a)^b*a` is unmatchable nonsense either way. Refusing it would be
        // refusing it for a reason that is not true.
        '(?=a)^b*a',
        '^(?=a)(?!b)b*a',

        /*
         * ⚠️ A STATED LIMIT, AND THE STATEMENT IS THE POINT. A nullable atom BEFORE the lookahead is
         * the obvious next shape in this family, and it is published on purpose: the measurements on
         * the 10.44 pair cover the nullable atom AFTER the lookahead and dead markup on either side,
         * and nothing has measured this one.
         *
         * ⚠️ AND THE COST OF REFUSING IT IS NARROW BUT REAL, which took a measurement rather than an
         * assertion to establish. Most spellings are refused by another rule already: an assertion is
         * zero-width, so it does not divide a run, and `\s*(?=[0-9])[A-Za-z0-9]+` unanchored is two
         * variable-width atoms in a row — the anchoring rule takes it. What survives is a fixed-width
         * tail: `\s*(?=[0-9])[A-Za-z0-9]{4}` publishes and says "the first of four alphanumerics is a
         * digit", which cannot be written by dropping the assertion.
         *
         * ⚠️ My first version of this note offered `\s*(?=\d)\w+` as the cost, and that pattern is
         * refused for two unrelated reasons — neither `\d` nor `\w` is portable. A cost example has to
         * be a pattern that publishes, or the argument it supports is decoration.
         *
         * If a measurement arrives, these rows move to the refusal above and the docblock says why.
         * Recorded rather than left as an omission, because an unasserted gap is indistinguishable from
         * one nobody thought about.
         */
        'b*(?=a)a',
        '\s*(?=[0-9])[A-Za-z0-9]{4}',
    ]);
});

describe('dead markup beside an unanchored lookahead is a divergence too', function (): void {
    /*
     * ⚠️ AN ATOM BOUNDED AT ZERO REPETITIONS CONSUMES NOTHING, EVER, and review measured its mere
     * PRESENCE changing what the engines say: `b{0}(?=a)a` is rejected by PCRE 10.44 and matched by
     * Node 24.15, while `^b{0}(?=a)a` agrees. `b` is not what the lookahead asserts, so the overlap
     * rule passes it on purpose and the divergence is there anyway — which is the same shape as
     * `(?=a)b*a` one step further out.
     *
     * ⚠️ THIS NARROWED A ROUND-OLD REFUSAL AND THAT IS DELIBERATE. `a{0}(?=a)a` used to be refused by
     * the overlap rule, because `a{0}` was read as an ordinary optional neighbour that could match the
     * asserted `a`. It cannot match anything at all, so that reading was wrong even where the verdict
     * was right, and the honest refusal is this one. The consequence is that `^(?:a{0}(?=a)a)$` now
     * PUBLISHES — anchored, the engines agree, and the `{0}` allowance exists precisely so dead markup
     * in a stored pattern does not fail an upgrade.
     */
    it('refuses a zero-repeat atom on either side of the lookahead', function (string $pattern): void {
        expect(Pattern::unpublishable($pattern))->toContain('bounded at zero repetitions');
    })->with([
        'b{0}(?=a)a',
        '(?=a)b{0}a',
        'a{0}(?=a)a',
        '(?=a)(?!x)b{0}a',

        /*
         * ⚠️ AND INSIDE THE NEIGHBOUR'S OWN FRONT, which review found: the neighbour of the lookahead
         * in `(?=a)(a{0}a)` is the whole group, so the dead atom adjacent to the assertion across the
         * bracket was lost — while the direct spelling is refused.
         *
         * ⚠️ Hoisting the prefix OUT of the group was my first attempt and it broke three refusals:
         * `(?:(?=a)a?a)` became `(?=a)(?:a?a)`, which pairs the assertion with the group rather than
         * with the `a?` inside it. What is adjacent across a bracket depends on which side the question
         * comes from, so the group stays the neighbour and only the dead markup is read out of it.
         */
        '(?=a)(a{0}a)',
        '(?=a)((a{0})a)',
        '(?=a)(?:a{0}a)',
        '(?=a)(?:(?=x)a{0}a)',
    ]);

    it('leaves the anchored spelling alone', function (string $pattern): void {
        expect(Pattern::unpublishable($pattern))->toBeNull("[{$pattern}] anchors its search");
    })->with([
        '^b{0}(?=a)a',
        '^(?=a)b{0}a',
        '^(?:a{0}(?=a)a)$',
        '^(?=a)(a{0}a)',

        // ⚠️ A STATED LIMIT: the dead atom in `(?=a)(aa{0})` is not in FRONT of anything — the `a`
        // consumes before it — so nothing is adjacent to the assertion across the bracket.
        '(?=a)(aa{0})',

        // ⚠️ Dead markup with no lookahead beside it is not this rule's business, and refusing it
        // would fail an upgrade over a stored pattern that means exactly what it meant yesterday.
        'a{0}b',
        'b{0}a*c',
    ]);
});

describe('an alternation inside a required group keeps the run around it', function (): void {
    /*
     * ⚠️ A HOLE MY OWN FIX OPENED, one round old. Making `|` end a run was right for a real alternation
     * and wrong for a SPLICED one: an exactly-once group's body was flattened into the enclosing linear
     * list, so the body's `|` arrived where it means nothing and the walk treated it as a boundary.
     * `^a*(?:b|a*)a*c$` then read as two short runs, when its second branch is `^a*a*a*c$` — three
     * variable-width atoms in a row. Node 24: about 1.9 seconds on 2,001 characters, past 15 on the
     * 5,000 a `text` field admits.
     *
     * A multi-branch group is no longer spliced. It stays one atom, priced by what it can MATCH — and
     * its own branches are walked separately, because a run inside one branch is still a run.
     */
    it('refuses a run the branches complete', function (string $pattern): void {
        expect(Pattern::unpublishable($pattern))->toContain('variable-width atom');
    })->with([
        '^a*(?:b|a*)a*c$',
        '^a*(?:a*|b)a*c$',
        '^x(?:a*a*a*b|c)y$',

        /*
         * ⚠️ AND A GROUP THAT MATCHES TWO LENGTHS IS VARIABLE-WIDTH, however it is spelled. `(?:a|aa)`
         * holds neither quantifier nor class, so a check that asked what a group CONTAINS called it
         * fixed; `fixedWidth()` asks what it can match, which is the question the run rule has.
         */
        '^a*(?:a|aa)a*b$',
    ]);

    it('leaves a group that separates the run alone', function (string $pattern): void {
        expect(Pattern::unpublishable($pattern))->toBeNull("[{$pattern}] divides its own run");
    })->with([
        // ⚠️ Every branch leads with a literal `a*` cannot match, so the division is forced — the same
        // proof the ordinary delimited list rests on.
        '^a*(?:b|c)a*d$',
        '^[^,]+(?:,[^,]+)*$',

        // ⚠️ And one fixed width across the branches is not variable at all.
        '^(?:a|b)c*d*e$',
        '^(?:19|20)[0-9]{2}$',
    ]);
});

describe('what consumes nothing cannot change what a pattern means', function (): void {
    /*
     * ⚠️ TWO FALSE REFUSALS REVIEW FOUND, both of them the same mistake from opposite ends: a walk
     * stopping at something that consumes nothing. `a{0}` before a `^` made the anchor invisible, so a
     * pattern both engines read as `^a*a*b$` was refused under the stricter unanchored limit; and a
     * literal with a FIXED repetition did not end a run, so `^a*a*b{1}a*$` — the accepted `^a*a*ba*$`
     * written another way — counted three adjacent variable-width atoms.
     *
     * Both cost an upgrade under `kitsune:audit-patterns --strict`, which is the expensive direction
     * for a false refusal: a stored pattern that means what it meant yesterday stops a deploy.
     */
    it('reads through dead markup to find the anchor', function (string $pattern): void {
        expect(Pattern::unpublishable($pattern))->toBeNull("[{$pattern}] is anchored");
    })->with([
        'a{0}^a*a*b$',
        'b{0}^a*a*b$',
        '(?=x)a{0}^a*a*b$',
    ]);

    it('lets a fixed repetition of a literal divide a run', function (string $pattern): void {
        expect(Pattern::unpublishable($pattern))->toBeNull("[{$pattern}] is divided by its literal");
    })->with([
        '^a*a*b{1}a*$',
        '^a*a*b{2}a*$',
        '^a*a*b{1,1}a*$',
    ]);

    it('still refuses a divider that can match nothing', function (string $pattern): void {
        /*
         * ⚠️ WHERE THAT STOPS: a literal that may run ZERO times forces nothing, because the subject
         * can simply not contain it — and one that runs a VARIABLE number of times is a variable-width
         * atom, which the rule above it already counts.
         */
        expect(Pattern::unpublishable($pattern))->toContain('variable-width atom');
    })->with([
        '^a*a*b?a*$',
        '^a*a*b{0,2}a*$',
        '^a*a*b{0}a*$',
    ]);
});

describe('a backreference is as wide as the capture it names', function (): void {
    /*
     * ⚠️ A DIVERGENCE ON THIS PAIR, not only a cost. `\1` carries no quantifier of its own, so the run
     * rule read every backreference as fixed-width and `^(a+)(a+)\1$` counted two variable-width atoms
     * where there are three. Measured at production fidelity on 5,000 `a` — the ceiling a `text` field
     * admits — `preg_match()` exhausts its backtrack limit and returns FALSE while Node 22.23.2 matches
     * in 13.3 ms. The published schema accepted a value the server rejects.
     *
     * ⚠️ THE CAPTURE'S WIDTH IS THE QUESTION, so the fix cannot be "refuse backreferences": a fixed
     * capture makes the reference fixed, and `^(a)(a+)\1$` measures 0.0 ms on both engines.
     */
    it('counts one to a variable capture as variable', function (string $pattern): void {
        expect(Pattern::unpublishable($pattern))->toContain('variable-width atom');
    })->with([
        '^(a+)(a+)\1$',
        '^(?<x>a+)(a+)\1$',

        // ⚠️ `\k<name>` is not resolved at all, so it counts as variable — the conservative direction,
        // and this shape is the same one anyway.
        '^(a+)(a+)\k<x>$',
    ]);

    it('leaves one to a fixed capture alone', function (string $pattern): void {
        expect(Pattern::unpublishable($pattern))->toBeNull("[{$pattern}] refers to a fixed capture");
    })->with([
        '^(a)(a+)\1$',
        '^(a{2})(a+)\1$',
        '^(a+)\1$',
        '^(a+)b\1$',
        '^(a)(b)\2\1$',

        // ⚠️ A non-capturing group takes no number, so `\1` here is still the `(a)`.
        '^(?:x)(a)(a+)\1$',
    ]);
});

describe('an unanchored ambiguity is retried from every position', function (): void {
    /*
     * ⚠️ THIS DISPROVES A MEASURED NEGATIVE THIS SUITE'S OWN DOCUMENT RECORDED. §4 said the ambiguity
     * ceiling already sat low enough to absorb the search factor, on the strength of sixteen copies of
     * `(?:a|a)` measuring 178.6 ms unanchored. That measured ONE shape. With two-character branches the
     * same product costs four times as much — measured on Node 22.23.2 at 5,000 characters, failing:
     *
     *   16 × `(?:a|a)` then `b`        142.7 ms unanchored      0.2 ms anchored
     *   16 × `(?:ab|\x61b)` then `c`   736.2 ms unanchored      0.5 ms anchored
     *   16 × `(?:ab|ab)` then `c`      732.4 ms unanchored
     *
     * 736 ms here is seconds on ADR-027's floor, so the negative was WRONG rather than incomplete.
     *
     * ⚠️ THE BUDGET IS DERIVED, NOT CHOSEN: the anchored product over the longest value a field may
     * hold, 65,536 over 5,000, is 13. My first version refused any ambiguity at all without an anchor
     * and it refused fifteen shapes in this suite — all products of 2, all linear at any length. A
     * false refusal for a real cost is still a false refusal.
     */
    it('refuses a product the retry multiplies past the budget', function (string $pattern): void {
        expect(Pattern::unpublishable($pattern))->toContain('in a pattern nothing anchors');
    })->with([
        str_repeat('(?:ab|\x61b)', 16).'c',
        str_repeat('(?:ab|ab)', 16).'c',
        str_repeat('(?:a|a)', 16).'b',
        str_repeat('(?:ab|ab)', 4).'c',
    ]);

    it('leaves the anchored spelling and a small product alone', function (string $pattern): void {
        expect(Pattern::unpublishable($pattern))->toBeNull("[{$pattern}] is anchored or cheap");
    })->with([
        '^'.str_repeat('(?:ab|\x61b)', 16).'c',
        '^'.str_repeat('(?:a|a)', 16).'b',

        // ⚠️ Three copies of two branches is a product of 8, inside the unanchored budget of 13 — and
        // the rule has to admit it, because 8 × the length is linear work at any length.
        str_repeat('(?:ab|ab)', 3).'c',
        '(?:a|a)b',

        // ⚠️ And distinct branches cost nothing at all: at most one can match at a position, whatever
        // the pattern's length or the number of alternations.
        str_repeat('(?:cat|dog)', 20).'x',
    ]);
});
