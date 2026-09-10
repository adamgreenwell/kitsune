<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Fields\Pattern;

/**
 * A published property name means the same thing to both engines — or it is refused.
 *
 * ⚠️ THE LINE IS NOT "ITS MEMBERSHIP MOVES", because every category's membership moves. U+10940 is
 * SIDETIC LETTER N01, assigned in Unicode 17.0 with category Lo — measured with `IntlChar::charAge`
 * — so a server at 15.1 and a client at 17.0 enforce different rules on `^\p{L}+$` for that exact
 * codepoint. An allowlist that excluded everything version-sensitive would be empty.
 *
 * ⚠️ THE LINE IS WHETHER THERE IS A STABLE RULE TO CONVERGE ON. "A letter" is one: both engines are
 * answering the same question, one has a shorter table, and each release moves them toward what the
 * author meant. "Not yet assigned" is not a rule — it describes the table's incompleteness, so the
 * answer moves AWAY from the author's intent every release, in both directions at once.
 *
 * ⚠️ AND A MEASUREMENT COULD NOT HAVE FOUND EITHER EXCLUSION. Review demonstrated the divergence on
 * PCRE 10.44 with Node 24; PCRE 10.48 with Node 22 — the pair the parity harness runs here — agrees
 * on all 1,114,112 codepoints, because both sit at Unicode 17.0. A harness sees the versions it has,
 * so these exclusions are asserted as refusals rather than as measured divergences.
 */
it('refuses the categories that describe the absence of an assignment', function (): void {
    /*
     * ⚠️ `C` IS HERE BECAUSE IT CONTAINS `Cn` (Cc|Cf|Co|Cs|Cn). It was on the allowlist while `Cn`
     * was off it, which review correctly called arbitrary: `\p{C}` was the same unportable set
     * reached by another spelling, so excluding only `Cn` bought nothing.
     *
     * ⚠️ ALL FOUR SPELLINGS OF EACH, because `propertyRefusal()` reads the property NAME and does
     * not consult class context. If it ever started to, `[^\p{C}]` would be the row that fails.
     */
    foreach (['Cn', 'C'] as $category) {
        foreach (['^\p{%s}$', '^\P{%s}$', '^[\p{%s}]$', '^[^\p{%s}]$'] as $spelling) {
            $pattern = sprintf($spelling, $category);

            expect(Pattern::unpublishable($pattern))
                ->not->toBeNull("[{$pattern}] is publishable, and it cannot be");
        }
    }
});

it('still admits the categories that name assigned characters', function (): void {
    /*
     * The other half, so the fix cannot quietly become "refuse all properties". `Cc`, `Cf`, `Co`
     * and `Cs` are siblings of `Cn` that name assigned characters — controls, format characters,
     * private use, surrogates — so they grow like every other category rather than inverting.
     * Asserted by name so removing one by accident fails here rather than surfacing much later as
     * a refused pattern an author cannot explain.
     */
    foreach (['Cc', 'Cf', 'Co', 'Cs', 'L', 'Lu', 'LC', 'N', 'Nd', 'P', 'S', 'Z', 'M'] as $category) {
        expect(Pattern::unpublishable('^\p{'.$category.'}$'))
            ->toBeNull("[\\p{{$category}}] should still be publishable");
    }

    expect(Pattern::unpublishable('^\p{Script=Arabic}$'))->toBeNull();
});

it('admits a property complement, which is no worse than the property', function (): void {
    /*
     * ⚠️ A DELIBERATE DECISION, not an oversight, and review raised it: `\P{L}` and `[^\p{L}]`
     * include unassigned characters, so a newly assigned letter leaves the complement and the two
     * engines disagree about it.
     *
     * They do — and `\p{L}` diverges on the very same codepoint, in the opposite direction. On the
     * pair review measured, U+10940 matches `\P{L}` on the older engine and `\p{L}` on the newer;
     * whichever polarity the author writes, the disagreement is the same size and has the same
     * cause. Excluding the complement while admitting the property would refuse half of a symmetric
     * pair and claim a portability the other half does not have either.
     *
     * So both are admitted and the version-skew limit is disclosed once, in field-types.md §3,
     * rather than half-enforced by an allowlist that cannot reach it. What makes `Cn` and `C`
     * different is not their polarity — it is that neither polarity of them names a stable rule.
     */
    foreach (['^\P{L}+$', '^[^\p{L}]+$', '^\P{Nd}+$', '^[^\p{Nd}]+$'] as $pattern) {
        expect(Pattern::unpublishable($pattern))
            ->toBeNull("[{$pattern}] should be publishable, symmetrically with the property");
    }
});

it('admits the POSIX-style aliases that are synonyms in both engines', function (): void {
    /*
     * ⚠️ THE DOC SAID THESE WERE ADDED WHILE THEY WERE NOT, which is the failure invariant 14 names
     * — a published constraint the code does not keep. The harness had been reporting them as an
     * expressiveness cost the whole time: both engines honour them and the screen refused them.
     *
     * ⚠️ ADMITTED ON A SET COMPARISON, not on compiling, because compiling proves only that a name
     * is accepted. Each was compared with its canonical spelling across all 1,114,112 codepoints in
     * both engines and is exactly equal — Lower/Lowercase 2,595 members, Alpha/Alphabetic 147,421,
     * Upper/Uppercase 2,006.
     */
    foreach (['Lower', 'Alpha', 'Upper'] as $alias) {
        expect(Pattern::unpublishable('^\p{'.$alias.'}$'))
            ->toBeNull("[\\p{{$alias}}] agrees in both engines and should be publishable");
    }

    /*
     * ⚠️ AND `Space` IS WHY THE FAMILY WAS MEASURED ONE NAME AT A TIME. PCRE compiles `\p{Space}`
     * and ECMAScript rejects the name outright, so admitting the aliases as a group would have
     * published a pattern no consumer can compile — the exact failure the allowlist exists to stop.
     */
    expect(Pattern::unpublishable('^\p{Space}$'))
        ->not->toBeNull('\p{Space} compiles only in PCRE and cannot be published');
});
