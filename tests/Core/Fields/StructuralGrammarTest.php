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

describe('rule 3 — no unbounded quantifier over a group containing one', function (): void {
    /*
     * ⚠️ NOT A PORTABILITY PROBLEM — the two engines agree, in the sense that NEITHER gives a
     * verdict. `preg_match()` returns false after exhausting its backtrack limit and ECMAScript is
     * still searching when the deadline expires. It is catastrophic backtracking, and ADR-027's
     * 1 vCPU floor is why the cost cannot be left to the consumer.
     */
    it('refuses nested unbounded repetition', function (string $pattern): void {
        expect(Pattern::unpublishable($pattern))
            ->not->toBeNull("[{$pattern}] nests unbounded quantifiers");
    })->with(['^([a-zA-Z0-9]+\.?)+$', '(a+)+$', '(a*)*b', '(?:a{1,})+', '^(?:[a-z]*)+$', '^([a-z]+)*$']);

    it('accepts a bounded outer quantifier, and a single unbounded one', function (): void {
        // Bounding either end removes the exponential growth, which is what the message says.
        expect(Pattern::unpublishable('^([a-z]+){1,4}$'))->toBeNull()
            ->and(Pattern::unpublishable('^([a-z]{1,8})+$'))->toBeNull()
            ->and(Pattern::unpublishable('^([a-z]+)$'))->toBeNull()
            ->and(Pattern::unpublishable('^[a-z]+$'))->toBeNull();
    });

    /*
     * ⚠️ THE DELIMITED LIST IS EXEMPT, and it has to be: `^[^,]+(?:,[^,]+)*$` nests `+` inside `*`
     * and is the commonest safe shape in the language. The rule as `field-types.md` published it
     * refused it, which is how the exemption came to be written.
     *
     * The exemption is a proof, not a guess. Every iteration must begin at a `,` and `[^,]` cannot
     * consume one, so the commas in the subject FORCE the division into iterations — one way to
     * split, nothing to backtrack over. Measured against a worst case that fails at the very end:
     *
     *   n=1000 items   PCRE 0.01 ms   ECMAScript 0.06 ms
     *   n=5000 items   PCRE 0.04 ms   ECMAScript 0.09 ms
     *   n=20000 items  PCRE (JIT stack limit)   ECMAScript 0.30 ms
     *
     * Linear in both, which is what the proof claims. The JIT stack limit at n=20000 is a
     * 60 KB subject and is a bound on subject LENGTH rather than on ambiguity — a text field
     * defaults to 255 characters, so it is not reachable through a field value.
     */
    it('exempts a delimited repetition, which cannot backtrack', function (string $pattern): void {
        expect(Pattern::unpublishable($pattern))
            ->toBeNull("[{$pattern}] is a delimited list and provably linear");
    })->with(['^[^,]+(?:,[^,]+)*$', '^[^;]+(?:;[^;]+)*$', '^[a-z]+(?:\.[a-z]+)*$']);

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

describe('rule 4 — no unbounded quantifier over ambiguous alternation', function (): void {
    /*
     * ⚠️ RULE 3 CANNOT CATCH THIS, because the repeated group holds no quantifier of its own —
     * which is what review pointed out. `^(a|aa)+$` is the classic shape: measured here, a subject
     * of 40 `a` characters plus `!` exhausts PCRE's backtrack limit while ECMAScript runs past a
     * 1.5-second deadline.
     */
    it('refuses alternatives that can match the same text two ways', function (string $pattern): void {
        expect(Pattern::unpublishable($pattern))
            ->not->toBeNull("[{$pattern}] repeats an ambiguous alternation");
    })->with(['^(a|aa)+$', '^(a|ab)+$', '^(?:a|aa)+$', '^(?:cat|ca)+$', '^(?:a|)+$', '^(?:[a-z]|x)+$', '^(?:(?:a|aa))+$']);

    /*
     * ⚠️ THE EXEMPTION IS EXACT RATHER THAN GENEROUS. Prefix-freeness is precisely the condition
     * under which at most one branch can match at a position — if two both matched, one would have
     * to be a prefix of the other — so the alternation is deterministic and repeating something
     * deterministic stays linear.
     */
    it('accepts prefix-free literal alternatives, which cannot be ambiguous', function (): void {
        expect(Pattern::unpublishable('^(?:cat|dog)+$'))->toBeNull()
            ->and(Pattern::unpublishable('^(?:ab|cd|ef)+$'))->toBeNull()
            ->and(Pattern::unpublishable('^(a|b)+$'))->toBeNull();
    });

    it('accepts an ambiguous alternation under a BOUNDED quantifier', function (): void {
        // The ambiguity is still there; the cost is not, because the exponent is capped.
        expect(Pattern::unpublishable('^(a|aa){1,4}$'))->toBeNull()
            ->and(Pattern::unpublishable('^(a|aa)$'))->toBeNull();
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
