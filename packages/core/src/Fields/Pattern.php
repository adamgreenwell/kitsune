<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Fields;

/**
 * An author-supplied regular expression, and whether it can be used.
 *
 * ⚠️ ONE implementation, shared by three callers that must agree.
 *
 * `TextType` validates values against a `pattern` setting; the builder has to
 * refuse a malformed pattern when it is AUTHORED; and `FieldStorage` has to
 * refuse one however it was written. Three copies of "does this compile" drift,
 * and a validator that disagrees with the guard is worse than either alone —
 * which is the failure invariant 14 exists for.
 *
 * A pattern arrives WITHOUT delimiters, because asking an author to supply them
 * means asking which characters need escaping. So the delimiter is chosen here,
 * and choosing it is part of whether the pattern is usable at all: a pattern
 * containing every candidate cannot be delimited and is refused rather than
 * silently mangled.
 */
final class Pattern
{
    /**
     * Candidate delimiters, in preference order.
     *
     * `/` first because it is what an author who knows regex expects to see.
     */
    /**
     * The longest pattern this screen will accept, in characters.
     *
     * ⚠️ A RESOURCE BOUND, not a style preference, and it is the second half of the
     * denial-of-service fix in `unpublishable()`. Hoisting the span computation removed
     * the quadratic in the number of GROUPS; the scan itself is still quadratic in
     * LENGTH, because `mb_substr($pattern, $i, 1)` walks from the start of the string to
     * find character `$i`. Measured after the hoist: 5 KB in 0.044s, 25 KB in 1.0s. A
     * setting with no length limit therefore still buys a worker's time by the kilobyte.
     *
     * 1,000 is far above any pattern a field validation needs — the value being
     * validated defaults to 255 characters — and puts the worst case near a
     * millisecond. Rewriting the scan to work on bytes or a pre-split array would lift
     * the ceiling, but the ceiling is not the problem: an unbounded input is.
     */
    public const MAX_LENGTH = 1000;

    /**
     * How many variable-width atoms may sit in a row with no forced boundary between them.
     *
     * ⚠️ TWO NUMBERS BECAUSE THERE ARE TWO COST CLASSES, both measured — see `atomRunExceeds()`.
     * Inside a repetition, k adjacent atoms are exponential in the subject and one is the limit. At
     * the top level the same k is a polynomial of degree k: two is quadratic in a value whose length
     * `TextType` bounds, and is what real patterns are made of, while three is cubic and already
     * 490 ms at a length an org can configure.
     */
    /**
     * The escapes that stand for a SET of characters rather than one character.
     *
     * ⚠️ These are the ones a range cannot use as an endpoint. `\s` is here although the grammar
     * admits it — it is admitted by REWRITING, and a rewrite is only equivalent where the syntax
     * around it is (see `rangeEndpointRefusal()`). The others are refused elsewhere for portability
     * and are listed so this answer does not depend on the order the checks happen to run in.
     */
    private const CLASS_SET_ESCAPES = [
        's' => true, 'S' => true, 'd' => true, 'D' => true, 'w' => true, 'W' => true,
        'h' => true, 'H' => true, 'v' => true, 'V' => true, 'R' => true, 'N' => true,
        'p' => true, 'P' => true,
    ];

    private const RUN_INSIDE_REPETITION = 1;

    private const RUN_AT_TOP_LEVEL = 2;

    /**
     * How many adjacent variable-width atoms a sequence may hold for free: one, which is linear.
     *
     * ⚠️ SEPARATE FROM THE TWO ABOVE because it prices rather than refuses. Those two say how long a
     * run may be; this says when a permitted run starts costing something, and the answer is as soon
     * as it is longer than one.
     */
    private const RUN_WITHOUT_COST = 1;

    /**
     * How many sequences may each claim the quadratic allowance before the pattern is refused.
     *
     * ⚠️ REVIEW FOUND THE COST MISSING ENTIRELY, and the hole was that only a GROUP contributed to a
     * sequence's cost — so a branch whose expense is a quantifier rather than an alternation read as
     * free. 140 alternatives of `a*a*b` is 845 characters, was published, and takes about 5.0 seconds on
     * a permitted 5,000-character failing value.
     *
     *   N alternatives of `a*a*b`, 5,000 `a` and no `b`, Node 22.23.2
     *
     *     N=1    35.7 ms     N=8    287.7 ms     N=32   1,151 ms
     *     N=2    71.6 ms     N=12   430.6 ms     N=64   2,302 ms
     *     N=4   143.6 ms     N=16   579.4 ms     N=140  5,037 ms
     *
     * Linear in N, as it has to be — each branch pays the quadratic in turn, and `^a*a*b$` alone
     * measures 39.5 ms, so one branch IS the admitted anchor.
     *
     * ⚠️ THE CEILING IS A CHOICE AND IS STATED AS ONE, because the module had no wall-clock ceiling to
     * read off. At this same 5,000-character subject the admitted `^a*a*b$` costs 35.7 ms and the
     * refused `^a*a*a*b$` costs 60.1 SECONDS, so measurement puts the accepted and refused polynomials
     * a factor of 1,680 apart and says nothing about where between them a total belongs. The two
     * figures this module has already committed to are 490 ms, recorded as a cost it refuses, and 3 ms,
     * what `MAX_AMBIGUITY_PRODUCT` buys. Eight grants is 287.7 ms: the largest power of two under the
     * figure already called too expensive, which makes the budget divide exactly, and about 2.9 seconds
     * on ADR-027's 1 vCPU floor — which is the reason not to go further rather than a comfort.
     *
     * ⚠️ EXACT FOR BRANCHES THAT SHARE A LEAD AND CONSERVATIVE FOR BRANCHES THAT DO NOT, and I had this
     * backwards until I measured it. The claim I was about to write was that prefix-free branches take
     * the max, so only indistinguishable ones sum — which is true of the COST and not of what
     * `branchesAreUnambiguousLiterals()` can PROVE. That function exempts plain literal text only, on
     * purpose, so any branch carrying a quantifier sums whether or not its lead is distinct:
     *
     *   9 × `a*a*b|a*a*c|…`   shared lead     324.3 ms   linear in N — the bound is exact
     *   9 × `a*a*b|c*c*d|…`   distinct leads   35.8 ms   one quadratic, however many branches
     *
     * So nine distinguishable quadratic branches are refused although they cost one grant. That is the
     * over-refusal this bound carries, stated rather than discovered later: an author who meets it can
     * separate the adjacent atoms in one branch, or split the alternation across fields. Proving
     * distinct leads for quantified branches would lift it, and that is the rule two earlier rounds of
     * this file got wrong twice — so it is issue #73 rather than an attempt here.
     *
     * ⚠️ NOT FOLDED IN AT ITS TRUE WEIGHT, and that is deliberate rather than a fudge. Priced in the
     * ambiguity budget's own currency — 45 ns per combination — a quadratic on a 5,000-character value
     * is about 780,000 combinations, which would refuse `^a*a*b$` itself. The run rule is more
     * permissive than the product rule ON PURPOSE, because quadratic work on a value `TextType` caps is
     * what real patterns are made of. This bounds how many times that permission is granted, not what
     * one grant would cost if the stricter rule applied to it.
     */
    private const MAX_QUADRATIC_BRANCHES = 8;

    /**
     * How many ways a pattern's ambiguous alternations may combine before it is refused.
     *
     * ⚠️ MEASURED, at about 45 ns per combination on Node 22.23.2 — 3 ms at 2^16, 47 ms at 2^20 and
     * 3.1 s at 2^26. 65,536 leaves an order of magnitude for ADR-027's 1 vCPU floor while keeping
     * sixteen ambiguous binary alternations publishable, which is far more than any real pattern has.
     */
    private const MAX_AMBIGUITY_PRODUCT = 65536;

    private const DELIMITERS = ['/', '#', '~', '%', '!'];

    /**
     * Modifiers every pattern is compiled with, chosen so PCRE agrees with the
     * dialect the pattern is published in.
     *
     * `u` for UTF-8 and Unicode properties, which the screen's measurements assume.
     *
     * ⚠️ `D` because `$` MEANS SOMETHING DIFFERENT without it, and this was a
     * divergence in the validator rather than in the published text.
     *
     * PCRE lets `$` match before a final newline; ECMAScript's `$` without `m`
     * matches only at the end of input. Measured on PHP 8.4.25/PCRE 10.48 and Node
     * v22.23.2:
     *
     *   `^a$`  on "a\n"   PCRE matches, ECMAScript does not
     *   `^a$D` on "a\n"   neither matches
     *
     * So a field validated `^[a-z]+$` accepted a trailing newline through the API
     * and every generated client rejected the same value — the API being the LAXER
     * of the two, which is the worse direction. `D` is the whole fix: it cannot be
     * expressed in the published pattern, and refusing `$` outright would remove
     * the most common anchor there is.
     *
     * `D` is ignored when `m` is set, and nothing here sets `m`.
     */
    private const MODIFIERS = 'uD';

    /**
     * ECMAScript's `.`, written out.
     *
     * ⚠️ A TRANSLATION rather than a modifier, because no PCRE newline convention
     * matches. Measured on PHP 8.4.25/PCRE 10.48 and Node v22.23.2, for what `.`
     * excludes:
     *
     *                LF   CR   LS   PS   VT   FF   NEL
     *   PCRE default  no   YES  YES  YES  yes  yes  yes
     *   PCRE (*ANY)   no   no   no   no   NO   NO   NO
     *   (*ANYCRLF)    no   no   YES  YES  yes  yes  yes
     *   ECMAScript    no   no   no   no   yes  yes  yes
     *
     * (Capitals mark disagreement with ECMAScript.) The default is too lax on CR,
     * LS and PS — a field validated `^.$` accepted all three and every generated
     * client rejected them. `(*ANY)` fixes those and breaks VT, FF and NEL the other
     * way, which is trading three laxness holes for three strictness ones rather
     * than fixing anything.
     *
     * This class is exact: it agrees with ECMAScript's `.` on all nine characters
     * tried, including an ordinary letter and a non-ASCII one.
     */
    private const ECMASCRIPT_DOT = '[^\n\r\x{2028}\x{2029}]';

    /**
     * The characters ECMAScript's `\s` matches, as a class BODY.
     *
     * ⚠️ I claimed `\s` and `\S` AGREE between the dialects — in a code comment and
     * in a review reply — on the strength of measuring NBSP and ideographic space.
     * They do not. Measured across 28 whitespace and near-whitespace code points,
     * three disagree:
     *
     *   U+0085 NEL                        PCRE matches, ECMAScript does not
     *   U+180E MONGOLIAN VOWEL SEPARATOR  PCRE matches, ECMAScript does not
     *   U+FEFF BYTE ORDER MARK            ECMAScript matches, PCRE does not
     *
     * PHP's `u` modifier sets PCRE2_UCP, so `\s` becomes Unicode's White_Space
     * property; ECMAScript's is a fixed list that includes the BOM and excludes NEL.
     *
     * The claim was not wrong for being unmeasured. It was wrong because the
     * character set it was measured against was too small — the same mistake the
     * escape sweep's alphabet made four times, and the reason a measurement now has
     * to state what it covered.
     *
     * A class BODY rather than a full class, so it can be spliced inside `[...]` as
     * well as wrapped outside one. Verified to agree with ECMAScript's `\s` on all
     * 22 code points retried after the change, the three divergences included.
     */
    private const ECMASCRIPT_SPACE = '\t\n\x0B\f\r \x{A0}\x{1680}\x{2000}-\x{200A}'
        .'\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    /**
     * The pattern wrapped in a delimiter it does not itself contain, or null.
     *
     * ⚠️ What is compiled is not byte-for-byte what is published, and that is the
     * point. The published pattern carries the author's own text; this produces the
     * form that makes PCRE enforce what that text MEANS in the dialect it is
     * published in. `D` for `$` and the dot translation are both that.
     */
    public static function delimit(string $pattern): ?string
    {
        // ⚠️ THE BOUND LIVES HERE, not only in `unpublishable()`, because this is the
        // chokepoint every path shares and that one was not on the first path taken.
        //
        // `TextType::validateSettings()` calls `compiles()` BEFORE `unpublishable()`, and
        // `patternRule()` calls `delimit()` for every validated value — so an over-long
        // pattern reached the quadratic `withEcmaScriptDot()` walk before the limit was
        // ever consulted. Measured on a 100,000-character non-ASCII pattern:
        // `unpublishable()` refused it in 0.001s while `compiles()` spent 6.7s reaching
        // the same conclusion. Submitting the settings form was enough; nothing had to be
        // stored.
        //
        // ⚠️ I had already been asked whether these methods were bounded and answered
        // that they were, having measured `delimit()` at the limit rather than above it.
        // Measuring the safe case cannot show an unbounded one.
        if (self::lengthRefusal($pattern) !== null) {
            return null;
        }

        $compilable = self::withEcmaScriptDot($pattern);

        foreach (self::DELIMITERS as $delimiter) {
            // ⚠️ Chosen against the TRANSLATED text. The class inserted above
            // contains no candidate delimiter today, and picking against the original
            // would silently produce an unescaped delimiter if that ever changed.
            if (! str_contains($compilable, $delimiter)) {
                return $delimiter.$compilable.$delimiter.self::MODIFIERS;
            }
        }

        return null;
    }

    /**
     * Why this pattern is too long to handle at all, or null when it is not.
     *
     * ⚠️ ONE implementation, consulted by `unpublishable()`, by `delimit()` and by
     * `TextType::validateSettings()`. Three call sites each testing the length
     * themselves is three chances for one of them to be added without it — which is
     * exactly how `compiles()` came to be the unbounded path while `unpublishable()`
     * was bounded.
     */
    public static function lengthRefusal(string $pattern): ?string
    {
        $length = mb_strlen($pattern);

        if ($length <= self::MAX_LENGTH) {
            return null;
        }

        return sprintf(
            'a pattern of %d characters — the limit is %d. Screening cost grows faster than '
            .'length, so an unbounded pattern is a way to hold a request open rather than a '
            .'way to describe a value',
            $length,
            self::MAX_LENGTH,
        );
    }

    /**
     * Every bare `.` replaced with the class ECMAScript's `.` actually means.
     *
     * ⚠️ Escape and class context are tracked, for the same reasons
     * `unpublishable()` tracks them: `\.` is a literal dot and must not be
     * translated, and `[.]` is a literal dot inside a class where the translation
     * would be both wrong and nonsensical. The two walks share that discipline and
     * the tests pin it on both sides.
     */
    private static function withEcmaScriptDot(string $pattern): string
    {
        $length = mb_strlen($pattern);
        $inClass = false;
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($pattern, $i, 1);

            if ($char === '\\') {
                $escaped = mb_substr($pattern, $i + 1, 1);
                $i++;

                // ⚠️ `\s` is translated for the same reason the dot is: the dialects
                // disagree about three code points, the disagreement is in the ENGINE
                // rather than in the text, and there is therefore nothing for
                // `unpublishable()` to screen. See `ECMASCRIPT_SPACE`.
                if ($escaped === 's') {
                    // Inside a class the BODY is spliced: `[[...]x]` is not a class
                    // containing a class, it is a bracket.
                    $out .= $inClass ? self::ECMASCRIPT_SPACE : '['.self::ECMASCRIPT_SPACE.']';

                    continue;
                }

                // ⚠️ `\S` outside a class becomes the negated class. INSIDE one it
                // cannot: `[a\S]` is "a or any non-space", and a negation has no
                // spliceable body — so that form is refused by `unpublishable()`
                // instead, with `[^\s]` as the portable spelling.
                if ($escaped === 'S' && ! $inClass) {
                    $out .= '[^'.self::ECMASCRIPT_SPACE.']';

                    continue;
                }

                // The escaped character travels with its backslash, untouched.
                $out .= $char.$escaped;

                continue;
            }

            if ($inClass) {
                $inClass = $char !== ']';
                $out .= $char;

                continue;
            }

            if ($char === '[') {
                $inClass = true;
                $out .= $char;

                continue;
            }

            $out .= $char === '.' ? self::ECMASCRIPT_DOT : $char;
        }

        return $out;
    }

    /**
     * Escapes PCRE understands that the published dialect does not.
     *
     * ⚠️ `\v` and `\h` are absent from THIS list and refused by the next one, and
     * the note here used to argue they should not be refused at all: both exist in
     * ECMAScript with different meanings rather than being rejected, so screening
     * only what a consumer cannot COMPILE would let them through.
     *
     * That argument was wrong, and is recorded rather than deleted because the
     * conclusion it reached is the one this file now rejects. The constraint being
     * enforced is not "the consumer can compile something", it is "the consumer
     * enforces the SAME constraint" — a pattern that compiles into a different
     * rule is the more dangerous of the two failures, because nothing reports it.
     * The rule that settles it: refuse a divergence when a portable equivalent
     * exists, record it when refusing would remove a capability.
     *
     * @var array<string, string>
     */
    private const PCRE_ONLY_ESCAPES = [
        // ⚠️ Screened in a character class as well as outside one. `\E`, `\Q` and
        // `\N` stay ACTIVE inside a class in PCRE — `[a\Q!\E]` and `[a\N{U+41}]`
        // both compile and ECMAScript rejects both — and the anchors PCRE refuses
        // in a class outright, so there is nothing an exemption would protect.
        'A' => 'the \A anchor — ECMAScript has ^',
        'z' => 'the \z anchor — ECMAScript has $',
        'Z' => 'the \Z anchor',
        'K' => '\K',
        'Q' => '\Q...\E literal quoting',
        'E' => '\Q...\E literal quoting',
        'G' => '\G',
        'R' => '\R',
        'X' => '\X',
        'C' => '\C',
        'N' => '\N',
    ];

    /**
     * Escapes that diverge INSIDE a character class as well as outside it.
     *
     * ⚠️ Separate from the list above, and lumping them together was wrong in one
     * direction or the other.
     *
     * The distinction was originally drawn as "anchors are literals inside a class,
     * these are not" — and the first half of that was simply wrong. PCRE REJECTS
     * `[\A]` and `[\z]`, so those never needed an exemption; what they needed was
     * for `compiles()` to answer first, which it does. `\h` inside a class is still
     * horizontal whitespace in PCRE while ECMAScript reads the letter h, so `[\h]+`
     * published a materially different constraint — that part held, and it is why
     * this list exists separately from the anchors at all.
     *
     * @var array<string, string>
     */
    private const DIVERGENT_ANYWHERE = [
        // ⚠️ `\d` and `\w` are the most commonly written escapes of all, and they
        // are the worst offenders — MEASURED on both engines rather than assumed:
        //
        //   PCRE 10.48 under /u   ECMAScript (Node 24, /u)
        //   \d on ١٢     matches           does not
        //   \w on аб     matches           does not
        //
        // PHP's `u` modifier sets PCRE2_UCP as well as UTF, so `\d` becomes "any
        // Unicode decimal digit" while ECMAScript's stays exactly [0-9]. A field
        // published as `^\d+$` therefore accepts Arabic-Indic digits through the
        // API and rejects them in every generated client.
        //
        // Refused, reluctantly, because the rule this settles on says to: a
        // portable equivalent exists and is one character longer. `\s` and `\S`
        // are NOT here — the same measurement found them agreeing on NBSP and
        // ideographic space, so there is nothing to refuse.
        'd' => '\\d — PCRE matches any Unicode digit here; ECMAScript matches only 0-9. Use [0-9]',
        'D' => '\\D — the negation of a class that differs; use [^0-9]',
        'w' => '\\w — PCRE matches Unicode letters here; ECMAScript matches only [A-Za-z0-9_]',
        'W' => '\\W — the negation of a class that differs; use [^A-Za-z0-9_]',
        'h' => '\\h — PCRE horizontal whitespace; ECMAScript reads it as the letter h. Use [ \\t]',
        'H' => '\\H — PCRE non-horizontal-whitespace; ECMAScript reads it as the letter H',
        'v' => '\\v — PCRE vertical whitespace; ECMAScript reads a single vertical tab',
        'V' => '\\V — PCRE non-vertical-whitespace; ECMAScript reads it as the letter V',
        // ⚠️ Found by sweeping the whole escape alphabet on both engines rather
        // than by extending a list of reported cases — which is how `\a` turned up
        // alongside the `\e` that was reported. Both are control characters PCRE
        // spells with a letter and ECMAScript has no escape for at all, inside a
        // character class as well as outside one.
        'a' => '\\a — PCRE\'s alarm/BEL escape; ECMAScript has no \\a. Use \\x07',
        'e' => '\\e — PCRE\'s escape character; ECMAScript has no \\e. Use \\x1B',
        // Every `\g` form is PCRE\'s: `\g{1}`, `\g<1>`, `\g1` and the relative and
        // subroutine variants. ECMAScript has none of them, and `[\g]` is a literal
        // g in PCRE while ECMAScript rejects it, so the class exemption cannot
        // apply either. A plain numbered backreference `\1` still works in both.
        'g' => '\\g — PCRE subroutine and relative-backreference forms; ECMAScript has none. '
            .'Use a plain numbered backreference like \\1, or a named one like \\k<name>',
        // `\o{141}` is PCRE octal. ECMAScript has no `\o`, and inside a class it
        // diverges the same way.
        'o' => '\\o — PCRE\'s octal escape; ECMAScript has no \\o. Use the hex form, e.g. \\x61',
    ];

    /**
     * Escapes that diverge OUTSIDE a character class and agree inside one.
     *
     * ⚠️ A third list, and the two existing ones would each have been wrong. `\b`
     * is not PCRE-only — both dialects have it — so it does not belong with the
     * anchors. And it does not diverge ANYWHERE: inside a character class `[\b]`
     * is the backspace character in both engines, measured and identical, so
     * screening it there would refuse a valid class the way the `\A` case did.
     *
     * ⚠️ I argued for RECORDING these rather than refusing them, on the grounds
     * that a word boundary has no portable spelling. That was wrong, and measuring
     * it is what showed the argument up: the ASCII definition written out as
     * lookarounds agrees with ECMAScript's `\b` on both engines in all 108
     * pattern/input combinations tried, so a portable equivalent does exist and
     * the rule this file settled on says to refuse.
     *
     * The divergence itself is the worst in the file — the two engines give
     * OPPOSITE answers, because `\b` is defined in terms of `\w` and PHP's `u`
     * modifier sets PCRE2_UCP:
     *
     *   `^\b.*\b$` on Cyrillic `аб`   PCRE matches, ECMAScript does not
     *   `^\B.*\B$` on the same        ECMAScript matches, PCRE does not
     *
     * @var array<string, string>
     */
    private const DIVERGENT_OUTSIDE_CLASS = [
        'b' => '\b — PCRE reads a Unicode word boundary here and ECMAScript an ASCII one, so the two '
            .'give opposite answers on non-ASCII text. Spell the ASCII meaning out: '
            .'(?:(?<![A-Za-z0-9_])(?=[A-Za-z0-9_])|(?<=[A-Za-z0-9_])(?![A-Za-z0-9_]))',
        'B' => '\B — the negation of a boundary that differs. Spell the ASCII meaning out: '
            .'(?:(?<=[A-Za-z0-9_])(?=[A-Za-z0-9_])|(?<![A-Za-z0-9_])(?![A-Za-z0-9_]))',
    ];

    /**
     * Punctuation ECMAScript lets a backslash escape.
     *
     * ⚠️ An ALLOWLIST, and the sweep that produced the escape screen missed this
     * surface entirely: it walked `a-z`, `A-Z` and `0-9` and never tried
     * punctuation. Measured across every ASCII punctuation mark, PCRE compiles all
     * of them and ECMAScript rejects 35 under the `u` modifier — `\!`, `\:`, `\_`,
     * `\-`, `\@`, `\~` and an escaped space among them.
     *
     * ECMAScript's rule is exactly its SyntaxCharacter set plus `/`: an identity
     * escape of anything else is a syntax error in Unicode mode, where PCRE treats
     * it as the literal character. So every refusal here has the same trivial
     * portable form — drop the backslash.
     *
     * @var array<string, true>
     */
    private const PORTABLE_ESCAPED_PUNCTUATION = [
        '^' => true, '$' => true, '\\' => true, '.' => true, '*' => true, '+' => true,
        '?' => true, '(' => true, ')' => true, '[' => true, ']' => true, '{' => true,
        '}' => true, '|' => true, '/' => true,
    ];

    /**
     * General_Category short forms, which both dialects accept in this spelling.
     *
     * A closed set of 38 — the seven groups, `LC`, and the thirty specific
     * categories. Fixed by Unicode's own stability policy, so unlike the
     * properties below this list cannot go stale by a release adding to it.
     *
     * ⚠️ The LONG forms (`Letter`, `Uppercase_Letter`) are deliberately absent —
     * PCRE rejects them, so `compiles()` refuses the pattern server-side before it
     * can ever be published. Listing them here would advertise a spelling the
     * validator then refuses, which is the validator/guard disagreement invariant
     * 14 exists to prevent.
     *
     * @var list<string>
     */
    private const PORTABLE_CATEGORIES = [
        /*
         * ⚠️ `Cn` AND `C` ARE BOTH DELIBERATELY ABSENT, and the reason is narrower than "their
         * membership moves between Unicode versions" — EVERY category's membership moves. U+10940
         * is SIDETIC LETTER N01, assigned in Unicode 17.0 with category Lo, so a server at 15.1 and
         * a client at 17.0 enforce different rules on `^\p{L}+$` for that one codepoint. Removing
         * categories cannot fix that, and an allowlist that tried would end up empty: this list
         * decides whether a construct EXISTS and means the same RULE in both dialects, and nothing
         * in a pattern can make two engines share a Unicode table (field-types.md §3 says so).
         *
         * What separates these two from the rest is whether there is a stable rule to converge ON.
         * "A letter" is one: both engines are answering the same question, one of them has a
         * shorter table, and each release brings them closer to what the author meant. "Not yet
         * assigned" is not a rule at all — it is a description of the table's incompleteness, so
         * the answer moves AWAY from the author's intent with every release, in both polarities:
         * `\p{Cn}` matches steadily less, `\P{Cn}` steadily more, and neither converges anywhere.
         *
         * `C` is absent because it CONTAINS `Cn` (Cc|Cf|Co|Cs|Cn) and inherits that exactly. It was
         * on this list while `Cn` was off it, which review correctly called arbitrary — `\p{C}`
         * was the same unportable set with one extra spelling. `Cc`, `Cf`, `Co` and `Cs` stay:
         * each names assigned characters, so each grows like every other category.
         *
         * ⚠️ ONE REMOVAL COVERS FOUR SPELLINGS, because `propertyRefusal()` reads the NAME and does
         * not consult class context: `\p{C}`, `\P{C}`, `[\p{C}]` and `[^\p{C}]` are all refused by
         * `C` being absent here. `Assigned` — the complement of `Cn` under another name — is absent
         * from PORTABLE_PROPERTIES for an unrelated reason (PCRE rejects it), and would have
         * belonged out for this one too.
         *
         * Review demonstrated the divergence on PCRE 10.44 with Node 24. PCRE 10.48 with Node 22
         * agrees on all 1,114,112 codepoints for L, N, Nd, C, Cn, Cf, P, S, Z and M, because both
         * sit at Unicode 17.0 — which is precisely why a measurement on ONE version pair cannot
         * license a portability claim.
         */
        'Cc', 'Cf', 'Co', 'Cs',
        // ⚠️ `LC` is the Cased_Letter GROUP (Ll|Lt|Lu), and leaving it out was a
        // false refusal of a category both dialects have — the exact cost this
        // allowlist trades for, caught by asking both engines rather than by
        // trusting the list. `Cased_Letter`, its long form, is absent for the
        // reason the other long forms are: PCRE rejects it.
        'L', 'LC', 'Ll', 'Lm', 'Lo', 'Lt', 'Lu',
        'M', 'Mc', 'Me', 'Mn',
        'N', 'Nd', 'Nl', 'No',
        'P', 'Pc', 'Pd', 'Pe', 'Pf', 'Pi', 'Po', 'Ps',
        'S', 'Sc', 'Sk', 'Sm', 'So',
        'Z', 'Zl', 'Zp', 'Zs',
    ];

    /**
     * Binary properties both dialects accept, in the one spelling both accept.
     *
     * ⚠️ DERIVED BY MEASUREMENT, not from the ECMAScript table: every name in the
     * spec's binary-property list was compiled on PHP 8.4.25/PCRE 10.48 and Node
     * v22.23.2, and only the 51 that both engines took are here. The measurement
     * earned its keep immediately — `Assigned` and `Changes_When_NFKC_Casefolded`
     * are in ECMAScript and PCRE rejects both, so they are absent for the same
     * reason the long category names are.
     *
     * ⚠️ This list CAN go stale, and that is the safe direction. A future Unicode
     * release adding a property both engines support would be refused here until
     * someone adds it — an author blocked with a message naming what is allowed.
     * The alternative, allowing any name PCRE happens to accept, publishes a
     * schema the consumer cannot compile and reports nothing.
     *
     * @var list<string>
     */
    private const PORTABLE_PROPERTIES = [
        /*
         * ⚠️ `Alpha`, `Lower` and `Upper` ARE POSIX-STYLE ALIASES, added because the harness
         * reported them as an expressiveness cost — both engines honour them and the screen refused
         * them anyway — and field-types.md §3 published that they had been added while they had not.
         *
         * ⚠️ ADDED ON A SET COMPARISON, not on compiling. Compiling proves a name is accepted, not
         * that it means the same thing: each alias was compared with its canonical spelling over all
         * 1,114,112 codepoints in BOTH engines and is exactly equal (Lower/Lowercase 2,595 members;
         * Alpha/Alphabetic 147,421; Upper/Uppercase 2,006). `Space` is deliberately not here — PCRE
         * compiles `\p{Space}` and ECMAScript rejects the name, so the aliases are not portable as a
         * family and were measured one at a time.
         */
        'ASCII', 'ASCII_Hex_Digit', 'Alpha', 'Alphabetic', 'Any', 'Bidi_Control', 'Bidi_Mirrored',
        'Case_Ignorable', 'Cased', 'Changes_When_Casefolded', 'Changes_When_Casemapped',
        'Changes_When_Lowercased', 'Changes_When_Titlecased', 'Changes_When_Uppercased',
        'Dash', 'Default_Ignorable_Code_Point', 'Deprecated', 'Diacritic', 'Emoji',
        'Emoji_Component', 'Emoji_Modifier', 'Emoji_Modifier_Base', 'Emoji_Presentation',
        'Extended_Pictographic', 'Extender', 'Grapheme_Base', 'Grapheme_Extend', 'Hex_Digit',
        'IDS_Binary_Operator', 'IDS_Trinary_Operator', 'ID_Continue', 'ID_Start', 'Ideographic',
        'Join_Control', 'Logical_Order_Exception', 'Lower', 'Lowercase', 'Math', 'Noncharacter_Code_Point',
        'Pattern_Syntax', 'Pattern_White_Space', 'Quotation_Mark', 'Radical', 'Regional_Indicator',
        'Sentence_Terminal', 'Soft_Dotted', 'Terminal_Punctuation', 'Unified_Ideograph', 'Upper',
        'Uppercase',
        'Variation_Selector', 'White_Space', 'XID_Continue', 'XID_Start',
    ];

    /**
     * Property prefixes both dialects accept, in the case both accept.
     *
     * ⚠️ `bc=` and `Bidi_Class=` are absent BECAUSE PCRE HAS THEM: it compiles
     * `\p{bc=AL}` and ECMAScript rejects the name, so an author writing the
     * obvious constraint for Arabic text would have published a schema that throws
     * in every generated client. Nothing else PCRE accepts survived the sweep —
     * `Block=`, `Age=`, `Line_Break=` and the rest are refused by both engines and
     * so are already `compiles()`'s business.
     *
     * @var list<string>
     */
    private const PORTABLE_PROPERTY_PREFIXES = ['Script', 'sc', 'Script_Extensions', 'scx'];

    /**
     * The first construct that will not travel to a JSON Schema consumer, or
     * null when none is found.
     *
     * ⚠️ SYNTAX-AWARE, and a substring scan was unsound in both directions.
     *
     * `\\A` is a literal backslash followed by A — valid everywhere — and was
     * refused because the second slash begins the substring `\A`. `[(?>]` is a
     * character class containing punctuation and was refused for the same reason.
     * Meanwhile `(?i)^abc$` was ACCEPTED: it compiles in PCRE, ECMAScript has no
     * bare inline modifier, and no substring in the old list matched it. So the
     * screen rejected legitimate patterns and published broken ones at once.
     *
     * Group prefixes are ALLOWLISTED rather than denylisted — `(?:`, `(?=`,
     * `(?!`, `(?<=`, `(?<!`, `(?<name>` are what ECMAScript has, and anything
     * else after `(?` is refused whether or not it was anticipated. That covers
     * `(?i)`, `(?P<`, `(?>`, `(?(`, `(?#`, `(?R` and whatever PCRE adds next,
     * which a list of known offenders cannot. It is the same reason the rich-text
     * sanitiser allowlists tags.
     *
     * Still not a dialect parser, and it does not claim to be: it screens what a
     * consumer cannot compile or would read differently, not every construct whose
     * meaning could conceivably drift.
     */
    public static function unpublishable(string $pattern): ?string
    {
        $length = mb_strlen($pattern);

        // ⚠️ Refused BEFORE the scan, because the scan is what costs. See MAX_LENGTH.
        if (($tooLong = self::lengthRefusal($pattern)) !== null) {
            return $tooLong;
        }

        $inClass = false;

        // ⚠️ ONCE PER PATTERN, not once per backreference, and the difference is a
        // denial of service rather than a slow test.
        //
        // Every reference used to rescan the whole pattern from `escapeFormRefusal()`,
        // making the screen quadratic in the number of groups. Measured on PHP 8.4:
        // `str_repeat('(a)\1', 1000)` — a portable pattern both engines accept, and only
        // 5 KB — took 22.6s, against 0.04s at 100 repetitions. A field's `pattern`
        // setting has no length limit, so an authoring request could hold a worker for
        // as long as it liked on a payload that fits in a text input.
        //
        // The spans are a pure function of the pattern, so hoisting cannot change an
        // answer — it is the same value every call site was recomputing.
        $spans = self::capturingGroupSpans($pattern);

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($pattern, $i, 1);

            if ($char === '\\') {
                // The escaped character is consumed either way, which is what
                // makes `\\A` a literal backslash and an A rather than an anchor.
                $escaped = mb_substr($pattern, $i + 1, 1);
                $i++;

                // These diverge wherever they appear, class or not.
                if (isset(self::DIVERGENT_ANYWHERE[$escaped])) {
                    return self::DIVERGENT_ANYWHERE[$escaped];
                }

                // ⚠️ No class exemption, and the one that used to be here rested on
                // a premise that measurement contradicts.
                //
                // The claim was that `\A` inside `[...]` is a literal A in PCRE, so
                // screening it there would refuse a valid class. PCRE REJECTS
                // `[\A]`, `[\z]`, `[\K]` and the rest outright — "not allowed in a
                // character class" — so the exemption never protected a pattern that
                // could be published, while it did let through the two forms that
                // stay ACTIVE in a class:
                //
                //   `[a\E]`        PCRE takes it (a stray \E is a no-op), ES rejects
                //   `[a\Q!\E]`     PCRE quotes inside the class, ES rejects
                //   `[a\N{U+41}]`  PCRE reads a code point, ES rejects
                //
                // Screening everywhere costs nothing for the anchors — a pattern
                // PCRE will not compile never reaches this, because
                // `validateSettings()` asks `compiles()` first — and closes those
                // three.
                if (isset(self::PCRE_ONLY_ESCAPES[$escaped])) {
                    return self::PCRE_ONLY_ESCAPES[$escaped];
                }

                // And inside a class `\b` is a backspace in both engines, which
                // is why it is screened here rather than with the escapes that
                // diverge wherever they appear.
                if (! $inClass && isset(self::DIVERGENT_OUTSIDE_CLASS[$escaped])) {
                    return self::DIVERGENT_OUTSIDE_CLASS[$escaped];
                }

                /*
                 * ⚠️ A SET ESCAPE CANNOT BE A RANGE ENDPOINT, and the normalisation hid it — review
                 * found this. `delimit()` splices `\s` into a character list, so PCRE compiles
                 * `[\b-\s]` happily and `compiles()` reports true; ECMAScript under `u` REJECTS a
                 * character-set escape as a range endpoint, so the raw published pattern does not
                 * compile for a consumer at all. Measured on PCRE 10.48 with Node 22.23.2, both handed
                 * the same source: PCRE matches U+0008, Node throws at construction.
                 *
                 * ⚠️ THIS IS THE COST OF ADMITTING A CONSTRUCT BY REWRITING IT. `\s` is the only
                 * escape the grammar admits that way (§4), and a rewrite is only equivalent where the
                 * SYNTAX around it is equivalent too — inside a range it is not. Both sides are
                 * checked, because `[\s-x]` inverts the same mistake.
                 */
                if ($inClass && ($reason = self::rangeEndpointRefusal($pattern, $i, $escaped)) !== null) {
                    return $reason;
                }

                // ⚠️ Three families where the LETTER is shared and the form is not,
                // so a lookup table cannot answer them.
                if (($reason = self::escapeFormRefusal($pattern, $i, $escaped, $inClass, $spans)) !== null) {
                    return $reason;
                }

                // ⚠️ `\S` inside a character class cannot be TRANSLATED, so it is
                // refused here instead.
                //
                // `\s` splices its body into the class; a negation has no body to
                // splice — `[a\S]` means "a or any non-space", which no single class
                // expresses. The three code points it disagrees on are the same ones,
                // so leaving it alone would publish a constraint the consumer reads
                // differently. `[^\s]` says it portably.
                if ($escaped === 'S' && $inClass) {
                    return '\S inside a character class — PCRE and ECMAScript disagree about which '
                        .'characters are whitespace (U+0085, U+180E and U+FEFF), and a negated class '
                        .'cannot be spliced into another one. Write [^\s] instead';
                }

                // ⚠️ And PUNCTUATION, which the letter-and-digit sweep never
                // reached. Handled last because the letters and digits above have
                // already been answered, so anything still here is punctuation or
                // a non-ASCII character — and ECMAScript escapes neither unless it
                // is a syntax character.
                if (($reason = self::punctuationRefusal($escaped, $inClass)) !== null) {
                    return $reason;
                }

                // ⚠️ `\p{...}` compiles in both dialects and its PROPERTY NAME
                // still has to be one both dialects know. The escape branch
                // consumed `\p` and never looked inside the braces, so every
                // divergence in there was published unchecked.
                //
                // Class context is NOT consulted: `[\p{Arabic}]` means what
                // `\p{Arabic}` means in both engines, so the portability question
                // is identical and the exemption above does not apply.
                if ($escaped === 'p' || $escaped === 'P') {
                    if (($reason = self::propertyRefusal($pattern, $i, $escaped)) !== null) {
                        return $reason;
                    }

                    // ⚠️ Consume the property's own braces, so the quantifier check
                    // below only ever sees a brace in a QUANTIFIER position.
                    // `\p{L}{2}` has two brace groups meaning different things, and
                    // validating the property's as a quantifier would refuse every
                    // Unicode property there is.
                    if (mb_substr($pattern, $i + 1, 1) === '{') {
                        $closes = mb_strpos($pattern, '}', $i);

                        if ($closes !== false) {
                            $i = $closes;
                        }
                    }
                }

                continue;
            }

            if ($inClass) {
                // A `[` inside a class is a literal; only `]` closes it.
                if ($char === ']') {
                    $inClass = false;
                }

                continue;
            }

            if ($char === '[') {
                $inClass = true;

                /*
                 * ⚠️ A `]` IN FIRST POSITION IS A LITERAL TO PCRE AND AN EMPTY CLASS TO ECMASCRIPT,
                 * which review found — and `[]]` was already refused as "a closing bracket nothing
                 * opened", so this scan caught the shape where nothing rebalanced it and missed the
                 * shape where something did. Measured on PCRE 10.48 with Node 22.23.2, both handed
                 * the same source: `[]a[]` compiles in BOTH, PCRE matches `a`, and Node reads an empty
                 * class, then `a`, then another empty class — so it can never match.
                 *
                 * ⚠️ FIRST POSITION IS AFTER AN OPTIONAL `^`, because `[^]a]` is the same trick
                 * negated; that one the old scan happened to refuse for the other reason.
                 */
                $opens = mb_substr($pattern, $i + 1, 1) === '^' ? $i + 2 : $i + 1;

                if (mb_substr($pattern, $opens, 1) === ']') {
                    return sprintf(
                        'a character class whose first member is `]` — as in `%s`. PCRE reads that as a '
                        .'literal `]` inside the class; ECMAScript under `u` reads `[]` as an EMPTY '
                        .'class, which matches nothing, so the two dialects disagree about what the '
                        .'pattern IS rather than about what it matches. Write it as `\]`',
                        self::excerpt($pattern, $i, min($length - 1, $opens + 1)),
                    );
                }

                continue;
            }

            // ⚠️ POSSESSIVE quantifiers, which I declined to detect while the
            // screen was substring-based — `\++` (an escaped plus, then a
            // quantifier) was indistinguishable from `a++` without a parse.
            //
            // The scanner tracks escapes now, so that objection no longer holds:
            // `\+` is consumed above as an escape and never reaches here. `a++`
            // compiles in PCRE and ECMAScript rejects it outright with "Nothing
            // to repeat", so it is exactly the class of thing this screens.
            // ⚠️ `}` counts only when it closed a {n,m} QUANTIFIER. `\p{L}+` is a
            // Unicode property followed by an ordinary `+`, and treating every
            // `}+` as possessive refused it — a false positive of exactly the kind
            // the escape tracking was added to avoid, reintroduced one character
            // along.
            if ($char === '{') {
                $closes = mb_strpos($pattern, '}', $i);
                $brace = $closes === false ? mb_substr($pattern, $i) : mb_substr($pattern, $i, $closes - $i + 1);
                $isQuantifier = $closes !== false
                    && preg_match('/^\{[0-9]+(,[0-9]*)?\}$/', $brace) === 1;

                // ⚠️ The WHOLE brace form is validated, not only checked for a
                // possessive suffix — which is all this did, so every malformed
                // quantifier PCRE tolerates went straight through.
                //
                // Under `u`, ECMAScript accepts only `{n}`, `{n,}` and `{n,m}`: a lone
                // or malformed brace is a syntax error there. PCRE reads several of
                // them as quantifiers and the rest as literal text. Measured, PCRE
                // compiles and ECMAScript rejects all of these:
                //
                //   a{,2}   a{}   a{,}   a{2,4,6}   a{ 2}   a{2 }   a{b}
                //
                // An escaped brace never arrives here — `^\{2\}$` is consumed above
                // and travels fine — and a property's braces are consumed with it, so
                // this only sees a brace in a quantifier position.
                if (! $isQuantifier) {
                    return sprintf(
                        'the brace form `%s` — ECMAScript accepts only {n}, {n,} and {n,m} as a '
                        .'quantifier and reads anything else as a syntax error, while PCRE tolerates '
                        .'it. Write the bound out, as in {0,2}, or escape the brace as \{',
                        mb_strlen($brace) > 12 ? mb_substr($brace, 0, 12).'…' : $brace,
                    );
                }

                if (mb_substr($pattern, $closes + 1, 1) === '+') {
                    return 'the possessive quantifier `}+` — ECMAScript has no possessive form';
                }

                // ⚠️ CONSUMED, so a `}` the loop meets later is one nothing opened.
                // Leaving it for the loop is what made a stray `}` invisible: it fell
                // through as an ordinary character.
                $i = $closes;

                continue;
            }

            // ⚠️ A closing delimiter nothing opened. Measured, PCRE compiles and
            // ECMAScript rejects `a}`, `a]`, `}a`, `a{2,4}b}` and `[]]` — under `u` a
            // lone quantifier bracket is a syntax error there, while PCRE reads it as
            // a literal character.
            //
            // Only the OPENING `{` was validated, and `]` was recognised solely while
            // already inside a class, so both closing forms fell straight through. By
            // the time the scanner reaches here every legitimate one has been
            // consumed: a quantifier's brace above, a property's in the escape branch,
            // a class's by the `$inClass` handling, and an escaped one as an escape.
            if ($char === '}' || $char === ']') {
                return sprintf(
                    'the unmatched `%s` — nothing here opens it, and ECMAScript reads a lone '
                    .'quantifier bracket as a syntax error where PCRE takes it literally. Escape it '
                    .'as `\%s` if you meant the character',
                    $char,
                    $char,
                );
            }

            if (in_array($char, ['*', '+', '?'], true) && mb_substr($pattern, $i + 1, 1) === '+') {
                return sprintf('the possessive quantifier `%s+` — ECMAScript has no possessive form', $char);
            }

            // ⚠️ And control verbs. `(*SKIP)`, `(*PRUNE)`, `(*FAIL)` and the rest
            // are backtracking directives with no ECMAScript equivalent at all,
            // and they open with `(*` rather than `(?` so the group allowlist
            // never saw them.
            if ($char === '(' && mb_substr($pattern, $i + 1, 1) === '*') {
                return sprintf(
                    'the control verb `%s` — ECMAScript has no backtracking directives',
                    rtrim(mb_substr($pattern, $i, (int) (mb_strpos($pattern.')', ')', $i) - $i + 1))),
                );
            }

            if ($char !== '(' || mb_substr($pattern, $i + 1, 1) !== '?') {
                continue;
            }

            if (($reason = self::groupRefusal($pattern, $i)) !== null) {
                return $reason;
            }
        }

        /*
         * ⚠️ LAST, and after the construct scan rather than woven into it, because every rule in
         * there is about a SHAPE spanning several constructs — which the character loop, by
         * design, cannot see. Running it here also means a pattern with an illegal construct is
         * refused for that reason rather than for a structural consequence of it.
         */
        return self::structuralRefusal($pattern);
    }

    /**
     * Whether the pattern's SHAPE is publishable, given every construct in it already is.
     *
     * ⚠️ AN ALLOWLIST OF CONSTRUCTS IS NECESSARY AND NOT SUFFICIENT, and `field-types.md` §3
     * published three rules saying so while enforcing none of them. Measured before this method
     * existed: `^(?=a)+a$`, `(?<=(a|aa))b\1$` and the document's own example
     * `^([a-zA-Z0-9]+\.?)+$` were all accepted by `unpublishable()`. The first two are exactly
     * the two live defects the parity harness reports, which is how the gap was visible the
     * whole time — a published constraint the code did not keep, the failure invariant 14 names.
     *
     * Review then found two more of the same kind, and they are handled here for the same reason
     * rather than bolted on elsewhere: every rule in this method is a property of how the parts
     * FIT TOGETHER, which no per-construct table can express.
     *
     * ⚠️ These are conservative by design. Deciding whether a repetition is genuinely ambiguous
     * is not something to attempt in a validator, so the rules refuse a SHAPE and name the
     * portable way to say the same thing. The expressiveness cost is real and is reported by the
     * harness rather than hidden.
     */
    private static function structuralRefusal(string $pattern): ?string
    {
        $frames = self::frames($pattern);

        foreach ($frames as $frame) {
            $isLookbehind = $frame['kind'] === 'lookbehind' || $frame['kind'] === 'nlookbehind';
            $isAssertion = self::isAssertionKind($frame['kind']);

            /*
             * ⚠️ RULE 1 — no quantifier on an assertion. `(?=a)+` is built from two permitted
             * constructs and does not compile under ECMAScript `u` at all, while PCRE takes it.
             * Checked against `u`-mode specifically, because Annex B makes the unflagged dialect
             * more permissive than the flagged one.
             */
            if ($isAssertion && $frame['quantifier'] !== '') {
                return sprintf(
                    'the quantifier `%s` on the assertion `%s` — an assertion consumes nothing, so '
                    .'ECMAScript rejects a quantifier on one outright while PCRE accepts it. Remove '
                    .'the quantifier, or repeat what the assertion guards instead',
                    $frame['quantifier'],
                    self::excerpt($pattern, $frame['open'], $frame['close']),
                );
            }

            if ($isLookbehind) {
                $branches = self::topLevelBranches($frame['body']);

                /*
                 * ⚠️ RULE 2 — a lookbehind's alternatives must be equal length. PCRE orders them
                 * by length and ECMAScript by written order, so a differing-length alternation
                 * changes which group captured what. `(?<=(a|aa))b\1$` compiles in both and they
                 * disagree about the subject — one of the two live defects the harness reported.
                 */
                if (count($branches) > 1) {
                    $lengths = [];

                    foreach ($branches as $branch) {
                        $lengths[] = self::fixedWidth($branch);
                    }

                    if (in_array(null, $lengths, true) || count(array_unique($lengths, SORT_REGULAR)) > 1) {
                        return sprintf(
                            'the lookbehind `%s`, whose alternatives are not all the same fixed '
                            .'length — PCRE tries them longest-first and ECMAScript in written '
                            .'order, so the two disagree about which alternative matched and about '
                            .'what any group inside it captured. Give every alternative the same '
                            .'fixed length, or use separate lookbehinds',
                            self::excerpt($pattern, $frame['open'], $frame['close']),
                        );
                    }
                }
            }

            /*
             * ⚠️ RULE 2, WIDENED: THE WHOLE LOOKBEHIND MUST BE ONE FIXED WIDTH. Review found that
             * checking the lookbehind's TOP-LEVEL alternatives leaves a nested one unexamined —
             * `(?<=([ab])(?:a|aa))\1$` has no top-level alternation at all, so nothing looked, and both
             * engines compile it while disagreeing about what it matches: PCRE takes `baab` and Node
             * does not, Node takes `baaa` and PCRE does not. The capture is fixed-width; the LOOKBEHIND
             * is not, at 2 or 3 characters.
             *
             * ⚠️ THIS IS THE SAME RULE STATED PROPERLY RATHER THAN A SECOND ONE. "Every alternative the
             * same fixed length" was always an approximation of "the lookbehind has one width", reached
             * by looking at the one place a width usually varies. Asking `fixedWidth()` about the body
             * asks the real question, at any depth, and the branch above is kept only because its
             * message names the alternation an author actually wrote.
             */
            if ($isLookbehind && self::fixedWidth($frame['body']) === null) {
                return sprintf(
                    'the lookbehind `%s`, which can match more than one length — PCRE tries the '
                    .'possibilities longest-first and ECMAScript in written order, so the two disagree '
                    .'about how much text the assertion covered and about what any group inside it '
                    .'captured. Measured, `(?<=([ab])(?:a|aa))\1$` matches `baab` in PCRE and `baaa` in '
                    .'ECMAScript. Give the lookbehind ONE fixed length, or use separate lookbehinds',
                    self::excerpt($pattern, $frame['open'], $frame['close']),
                );
            }

            /*
             * ⚠️ RULE 5 — a capturing group inside a lookbehind must be fixed length. Review
             * found this one, and measurement placed the line precisely: with a FIXED width the
             * engines agree, including two adjacent captures — `(?<=([ab]{2})([bc]{2}))\2\1$`
             * matches in both. Make either capture variable and they part company, because the
             * two engines walk a lookbehind in opposite directions and allocate the variable part
             * to different captures:
             *
             *   `(?<=(a+))\1$`                     on `aaaa`   PCRE errors, ECMAScript matches
             *   `(?<=([ab]{1,2})([bc]{1,2}))\2\1$` on `abcbca` PCRE says no, ECMAScript says yes
             *
             * `(?<=(a{1,2}))\1$` and `(?<=(a?))\1$` happen to agree on the subjects tried, which
             * is subject-dependent luck rather than a guarantee — they are the same construct and
             * are refused with the rest.
             */
            if (($frame['kind'] === 'capture' || $frame['kind'] === 'named') && $frame['inLookbehind']) {
                if (self::fixedWidth($frame['body']) === null) {
                    return sprintf(
                        'the variable-length capturing group `%s` inside a lookbehind — the two '
                        .'engines traverse a lookbehind in opposite directions, so they allocate '
                        .'the variable part to different groups and a backreference to it means '
                        .'different things. Give the group a fixed length, or move the capture '
                        .'outside the lookbehind',
                        self::excerpt($pattern, $frame['open'], $frame['close']),
                    );
                }

                /*
                 * ⚠️ AND A FIXED-WIDTH CAPTURE UNDER A REPETITION IS NOT FIXED EITHER, which review
                 * found next. A width is a property of one iteration; which iteration's text REMAINS
                 * captured is a property of the traversal, and the two engines traverse a lookbehind
                 * in opposite directions. Measured on PCRE 10.48 with Node 22.23.2, both readers
                 * handed identical source text:
                 *
                 *              aba    abb    aa     ab     aabaa  abab
                 *   ([ab]){1,2}  PCRE  no     MATCH  MATCH  no     MATCH  no
                 *                Node  MATCH  no     MATCH  no     no     MATCH
                 *   ([ab]){2}    PCRE  no     MATCH  no     no     MATCH  no
                 *                Node  MATCH  no     no     no     no     MATCH
                 *
                 * `{2}` is a FIXED repetition of a FIXED-width body and diverges on three of six
                 * subjects, so this is not the variable-width rule with a wider net — it is a second
                 * property. `([ab]){1}` and `([ab][ab])` agree everywhere and stay published.
                 *
                 * ⚠️ AN ANCESTOR'S REPETITION COUNTS TOO, because `(?:([ab])){1,2}` measures exactly
                 * like `([ab]){1,2}` — the capture is written once and still runs twice. The frame
                 * list is searched rather than the stack, since a group's quantifier is not known
                 * until after it closes.
                 */
                $repeated = self::repeatingAncestor($frames, $frame);

                if ($repeated !== null) {
                    return sprintf(
                        'the capturing group `%s` inside a lookbehind, repeated by `%s` — a width is '
                        .'a property of one iteration, but which iteration stays captured is a '
                        .'property of the traversal, and the two engines walk a lookbehind in '
                        .'opposite directions. Measured, `(?<=([ab]){2})\\1$` matches `aba` in '
                        .'ECMAScript and `abb` in PCRE. Repeat something outside the lookbehind, or '
                        .'write the repetition out',
                        self::excerpt($pattern, $frame['open'], $frame['close']),
                        $repeated,
                    );
                }
            }

            /*
             * ⚠️ A FINITE BOUND IS NOT A SAFE BOUND, which review found: this asked only whether the
             * quantifier had an upper bound, and skipped every ambiguity check for `{1,32}`. The exponent is the bound and the BASE
             * is how many ways one iteration can match, and nothing bounds the base. Measured on
             * Node 22.23.2, 40 `a` characters and a failing `!`:
             *
             *   `^(a|aa){1,16}$`            7 ms      `^(a|aa){1,20}$`             114 ms
             *   `^(a|aa|aaa){1,12}$`       41 ms      `^(a|aa|aaa){1,16}$`         3.4 SECONDS
             *   `^(a|aa|aaa|aaaa){1,10}$`  70 ms      `^(a|aa|aaa|aaaa){1,14}$`   16.6 SECONDS
             *
             * The safe bound FALLS as the body widens, so a threshold on the bound alone is a
             * constant that a wider body defeats — which is why there is no threshold. Any repetition
             * that can run twice is screened, and `^(a|aa){1,4}$` is refused where it used to be
             * published with the reasoning "a bounded outer quantifier caps the exponent, so the
             * ambiguity costs nothing". It caps the exponent and not the base.
             */
            /*
             * ⚠️ A ZERO-REPEAT GROUP HOLDING AN ASSERTION IS REFUSED, and this is a DIVERGENCE rather
             * than a cost — review found it in the skip added for `{0}` the round before. Measured at
             * production fidelity on PCRE 10.48 and Node 22.23.2:
             *
             *   (?:a|(?=a)){0}      PCRE no match on "b" AND on ""      ECMAScript matches both
             *   (?:a|(?=z)){0}      PCRE no match                        ECMAScript matches
             *   (?:(?=a)|a){0}      both match            <- order matters
             *   (?:a|(?<=a)){0}     both match            <- lookAHEAD only
             *   (?:a){0}, a{0}      both match
             *
             * So PCRE stops matching when a dead group's alternation ENDS in a positive lookahead,
             * while ECMAScript skips the group outright. A generated client would accept every value
             * the server rejects, which is exactly what rule 3 of the field-type contract forbids.
             *
             * ⚠️ THE RULE IS WIDER THAN THE QUIRK, deliberately. Encoding "an alternation whose last
             * branch is a positive lookahead" would be a shape nobody can check by reading it, and the
             * `{0}` allowance exists only so dead markup does not fail an upgrade — a dead group that
             * also contains an assertion is not a pattern anybody wrote on purpose.
             */
            if (self::neverRuns($frame['quantifier']) && self::containsAssertion($frame['body'])) {
                return sprintf(
                    'the assertion inside `%s`, a group bounded at zero repetitions — the two engines '
                    .'disagree about whether such a group runs at all. Measured, `(?:a|(?=a)){0}` '
                    .'matches every subject under ECMAScript and none under PCRE, so the published '
                    .'schema would accept values this server rejects. Remove the dead group, or take '
                    .'the assertion out of it',
                    self::excerpt($pattern, $frame['open'], $frame['close']),
                );
            }

            if (! self::repeatsMoreThanOnce($frame['quantifier'])) {
                continue;
            }

            /*
             * ⚠️ RULE 3 — AN UNBOUNDED REPETITION MUST HAVE ONLY ONE WAY TO DIVIDE ITS SUBJECT.
             * That is the property; everything else here is a way of establishing it. If a group's
             * body can match two different lengths at the same position, a failing subject can be
             * re-divided combinatorially, and neither engine answers: `preg_match()` returns false
             * after exhausting its backtrack limit while ECMAScript is still searching past a
             * deadline. Not a portability problem — the two agree, in that neither gives a verdict —
             * but catastrophic backtracking, and ADR-027's 1 vCPU floor is why the cost cannot be
             * left to the consumer.
             *
             * ⚠️ THIS WAS TWO RULES AND THEY BOTH LEAKED. The published pair was "no unbounded
             * quantifier over a group containing one" plus "not over ambiguous alternation", and
             * review found `^(a{1,2})+$` slipping between them: the inner quantifier is BOUNDED, so
             * the first rule does not fire, and there is no alternation, so the second does not
             * either. Measured — 30 characters takes ECMAScript ~100ms, 40 runs past three seconds,
             * and PCRE exhausts its backtrack limit.
             *
             * Worse, my own test asserted `^([a-z]{1,8})+$` was fine. It is the same shape and it
             * measures the same way. Two rules aimed at symptoms let a third symptom through and
             * blessed a fourth; one rule aimed at the property does not.
             *
             * Three ways to establish it, in cost order:
             *
             *  - FIXED WIDTH, WITH EVERY ALTERNATION INSIDE IT UNAMBIGUOUS. Every match of the body
             *    is the same length, so the division is forced — but a forced division is not
             *    enough on its own, and measuring found why. `(?:[a-z]|x)+` is fixed at one
             *    character, so each iteration consumes exactly one; `x` is nevertheless inside
             *    `[a-z]`, so on a subject of 30 `x` characters BOTH branches match at every
             *    position and there are 2^30 branch choices. Measured: ECMAScript 7.9 seconds,
             *    PCRE's backtrack limit exhausted — while the same pattern on 30 `a` characters is
             *    instant, because only one branch can match there. `(?:ab)+` and `(?:cat|dog)+`
             *    qualify; `(?:[a-z]|x)+` does not.
             *  - PREFIX-FREE LITERALS. No branch is a prefix of another, so at most one can match at
             *    a position. `(?:ab|c)+` qualifies at differing lengths.
             *  - DELIMITED. The body starts with a required literal that no unbounded quantifier
             *    inside it can consume, so the subject's own delimiters force the division.
             *    `(?:,[^,]+)*` qualifies.
             *
             * Anything else is refused with the portable spelling named. `(a?)+` is refused although
             * both engines happen to cope with it — it is ambiguous, `a*` says the same thing, and
             * the project's rule is to refuse a divergence when a portable equivalent exists.
             */
            $body = $frame['body'];

            $forcedDivision = self::fixedWidth($body) !== null
                && self::everyAlternationIsUnambiguous($body);

            if (
                ! $forcedDivision
                && ! self::branchesAreUnambiguousLiterals($body)
                && ! self::repetitionIsDelimited($body)
            ) {
                return sprintf(
                    'the repetition `%s` on `%s`, whose body can match more than one '
                    .'length at the same position — so a failing subject can be re-divided '
                    .'combinatorially, which exhausts PCRE\'s backtrack limit and runs unboundedly '
                    .'in ECMAScript. Give the repeated group a fixed length, make its alternatives '
                    .'distinct literals with none a prefix of another, or start it with a delimiter '
                    .'it cannot itself match. Bounding the repetition does NOT help: the bound is '
                    .'the exponent and the body is the base, and nothing bounds the base',
                    $frame['quantifier'],
                    self::excerpt($pattern, $frame['open'], $frame['close']),
                );
            }
        }

        /*
         * ⚠️ AND THE PATTERN ITSELF, WHICH NOTHING ABOVE REACHES. Every rule so far is driven by
         * `frames()`, so a pattern with no parentheses at all was analysed by none of them — review
         * found `^a*a*a*a*a*a*b$` published, and Node 22 spends 26 SECONDS on 100 characters and a
         * failing one. The loop above cannot be the whole screen when the whole screen is a loop over
         * brackets.
         *
         * The top level admits two adjacent atoms rather than one, for the measured reason
         * `RUN_AT_TOP_LEVEL` carries: here the cost is a polynomial of degree k, while inside a
         * repetition it is exponential.
         */
        /*
         * ⚠️ AMBIGUITY DOES NOT NEED A QUANTIFIER, which review found — and this is a different axis
         * from every rule above. `^` then thirty copies of `(?:a|a)` then `b$` has no repetition
         * anywhere and no variable-width atom for the run check to see, so nothing looked at it. Each
         * group offers two identical ways to match one character, and thirty of them offer 2^30: on a
         * 31-character failing subject, PCRE 10.48 exhausts its backtrack limit and Node 22.23.2 takes
         * 50.2 SECONDS. The pattern is 240 characters.
         *
         * ⚠️ A PRODUCT, NOT A COUNT, because the cost is the product and it is measured to be exactly
         * that. Node spends about 45 ns per combination, linearly:
         *
         *   2^16   65,536 combinations     3 ms
         *   2^18  262,144                12 ms
         *   2^20  1,048,576              47 ms
         *   2^26  67,108,864           3,074 ms
         *
         * So the bound is on the product and `MAX_AMBIGUITY_PRODUCT` is where it sits, with room for
         * ADR-027's floor. One ambiguous alternation is harmless and stays publishable; enough of them
         * to matter is what is refused.
         *
         * ⚠️ ONLY AMBIGUOUS ALTERNATIONS COUNT. `(?:cat|dog)` offers two ways to match but at most one
         * can succeed at a position, so a thousand of them are still linear — the product that matters
         * is over branches that can BOTH match, which is what `everyAlternationIsUnambiguous()`
         * already answers for a repetition body.
         */
        $product = self::ambiguityCost($pattern);

        if ($product > self::MAX_AMBIGUITY_PRODUCT) {
            return sprintf(
                'ways to retry a failing subject that multiply to more than %s — this pattern reaches '
                .'%s. An ambiguous alternation offers more than one way to match the same text, and a '
                .'branch holding two adjacent variable-width atoms offers a re-division of every '
                .'length: a sequence of either costs the product and an alternation of them costs the '
                .'sum. Measured, thirty copies of `(?:a|a)` take ECMAScript 50 seconds on a '
                .'240-character pattern, and 140 alternatives of `a*a*b` take 2.6 seconds on an '
                .'845-character one. Make the branches distinct — none a prefix of another — and '
                .'separate adjacent variable-width atoms with a character none of them can match',
                number_format(self::MAX_AMBIGUITY_PRODUCT),
                $product >= PHP_INT_MAX ? 'the limit of what can be counted' : number_format($product),
            );
        }

        if (self::atomRunExceeds($pattern, self::RUN_AT_TOP_LEVEL)) {
            return sprintf(
                'more than %d variable-width atoms in a row with nothing between them that forces '
                .'where one ends and the next begins — as in `%s`. Each one can give up characters to '
                .'the next, so a subject that fails at the end is retried in every combination: '
                .'measured, `^a*a*a*a*b$` takes ECMAScript 7.9 seconds on 500 characters. Two in a '
                .'row is permitted because it is quadratic rather than polynomial in the count; '
                .'beyond that, separate them with a character none of them can match, or say the '
                .'same thing with one quantifier — `a*a*` means `a*`',
                self::RUN_AT_TOP_LEVEL,
                self::excerpt($pattern, 0, min(mb_strlen($pattern) - 1, 40)),
            );
        }

        return null;
    }

    /**
     * How many ways this pattern can match one position, saturating at PHP_INT_MAX.
     *
     * ⚠️ A RECURSIVE COST MODEL, AFTER TWO FLAT ONES WERE WRONG IN OPPOSITE DIRECTIONS. Review found
     * both, and the second was the fix for the first:
     *
     *   - A flat walk over `frames()` multiplied every level of a nest, because a child arrives before
     *     its parent. Seventeen nestings of `(?:<previous>|a)` were refused as 131,072 combinations,
     *     and 100,000 Node matches complete in 3 ms.
     *   - Sorting outermost-first and skipping covered children then UNDERCOUNTED: an ambiguous outer
     *     alternation suppressed its children while contributing only its own branch count.
     *     `^(?:` + 28 × `(?:a|a)` + `|` + 28 × `a` + `)$` was read as 2 and is 2^28 — 231 characters,
     *     and Node spends 10.8 SECONDS on a 29-character subject.
     *
     * A flat product cannot express either shape, because the cost of a group depends on the cost of
     * what is inside it. Two rules do:
     *
     *   SEQUENCE     the product of its parts — each choice multiplies the ones beside it
     *   ALTERNATION  ambiguous: the SUM of its branches, because every branch must be tried
     *                prefix-free: the MAX, because at most one can match at a position
     *
     * ⚠️ AND THE SUM IS WHAT MAKES NESTING CHEAP AGAIN. `(?:X|a)` costs `cost(X) + 1`, so seventeen
     * nestings cost 18 rather than 2^17 — the harmless case falls out of the model instead of needing a
     * containment rule to rescue it. Every earlier case still lands where measurement puts it: thirty
     * sequential `(?:a|a)` multiply to 2^30, thirty `(?:a|b)` stay at 1, and the nested-in-a-branch case
     * above reaches 2^28.
     *
     * ⚠️ SATURATES RATHER THAN OVERFLOWING. A pattern at the length limit could nominally reach 2^250,
     * which as an int wraps negative and would compare as SMALL.
     */
    private static function ambiguityCost(string $body, int $depth = 0): int
    {
        // The length limit caps nesting at 250; this is the belt to that brace.
        if ($depth > 64) {
            return PHP_INT_MAX;
        }

        $branches = self::topLevelBranches($body);

        if (count($branches) > 1) {
            $costs = array_map(
                static fn (string $branch): int => self::ambiguityCost($branch, $depth + 1),
                $branches,
            );

            // ⚠️ Prefix-free branches cannot both match at a position, so the cost is the worst single
            // branch rather than all of them. Ambiguous ones are all tried, so they add up.
            return self::everyAlternationIsUnambiguous($body)
                ? max($costs)
                : self::saturatingSum($costs);
        }

        return self::sequenceCost($body, $depth);
    }

    /**
     * The cost of a branch with no top-level alternation: the product of the groups inside it.
     *
     * ⚠️ GROUPS AND THE SEQUENCE'S OWN RUN, and the first version had only the first half — review
     * found that a branch of `a*a*b` therefore read as free. A class or a literal on its own offers
     * one way to match one position and is a factor of one; two variable-width atoms side by side
     * offer a division of every length between them, which is the other thing a failing subject is
     * retried over.
     */
    private static function sequenceCost(string $body, int $depth): int
    {
        $length = mb_strlen($body);

        /*
         * ⚠️ THE QUANTIFIERS COST SOMETHING TOO, which review found this function denying — its own
         * docblock said "only groups contribute" and gave the reason as "the quantifier rules are what
         * look at repetition". Those rules look at ONE sequence. They cannot see that an alternation
         * puts 140 of them side by side, each paying the quadratic allowance in turn.
         *
         * ⚠️ CHARGED ONCE PER SEQUENCE, NOT ONCE PER RUN, and that is a stated limit rather than an
         * oversight. `^a*a*ba*a*b$` holds two quadratic runs and is charged for one, because on a
         * subject that fails in the first the second is never reached — the cost is paid per attempt,
         * and an alternation is what multiplies attempts.
         */
        $cost = 1;

        for ($runs = self::quadraticRuns($body); $runs > 0; $runs--) {
            $cost = self::saturatingProduct($cost, self::quadraticBranchCost());
        }

        for ($i = 0; $i < $length; $i++) {
            $token = self::atomAt($body, $i);

            if ($token === null) {
                return PHP_INT_MAX;
            }

            $i = $token['after'] - 1;

            // ⚠️ A group bounded at zero repetitions costs nothing, because it never runs — the same
            // reason the atom traversal skips it.
            if (! str_starts_with($token['atom'], '(') || self::neverRuns($token['quantifier'])) {
                continue;
            }

            $inner = self::frameBody($token['atom']);

            $cost = self::saturatingProduct($cost, self::ambiguityCost($inner, $depth + 1));

            if ($cost === PHP_INT_MAX) {
                return PHP_INT_MAX;
            }
        }

        return $cost;
    }

    /** @param  list<int>  $costs */
    private static function saturatingSum(array $costs): int
    {
        $total = 0;

        foreach ($costs as $cost) {
            if ($cost >= PHP_INT_MAX - $total) {
                return PHP_INT_MAX;
            }

            $total += $cost;
        }

        return $total;
    }

    private static function saturatingProduct(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        return $a > intdiv(PHP_INT_MAX, $b) ? PHP_INT_MAX : $a * $b;
    }

    /**
     * Whether every alternation inside this body, at any depth, can match at most one way.
     *
     * ⚠️ A FORCED DIVISION IS NOT ENOUGH, which measurement established rather than reasoning.
     * `(?:[a-z]|x)+` is fixed at one character wide, so every iteration consumes exactly one and
     * there is only one way to divide the subject — and it is still catastrophic, because `x` lies
     * inside `[a-z]`: on 30 `x` characters both branches match at every position, giving 2^30
     * branch choices. ECMAScript took 7.9 seconds and PCRE exhausted its backtrack limit, while the
     * same pattern on 30 `a` characters finished instantly because only one branch can match there.
     *
     * ⚠️ RECURSIVE, so `(?:a(?:b|c))+` is admitted. Checking only the body's own top-level
     * alternation would refuse it — a nested alternation is invisible there — and `b` and `c` are
     * distinct literals, so it is exactly as safe as `(?:ab|ac)+`. The first version of this rule
     * refused it and would have cost authors a common shape for nothing.
     *
     * ⚠️ CONSERVATIVE WHERE IT CANNOT BE SURE. `(?:a|[b-z])+` has disjoint branches and is refused,
     * because deciding whether two character classes overlap is more analysis than belongs on an
     * authoring request. The message names the portable ways out, and the harness reports the cost.
     */
    private static function everyAlternationIsUnambiguous(string $body): bool
    {
        $branches = self::topLevelBranches($body);

        if (count($branches) > 1 && ! self::branchesAreUnambiguousLiterals($body)) {
            return false;
        }

        foreach ($branches as $branch) {
            if (! self::nestedAlternationsAreUnambiguous($branch)) {
                return false;
            }
        }

        return true;
    }

    /** The same question asked of every group inside one alternation-free branch. */
    private static function nestedAlternationsAreUnambiguous(string $branch): bool
    {
        $length = mb_strlen($branch);
        $inClass = false;

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($branch, $i, 1);

            if ($char === '\\') {
                $i += self::escapeSpan($branch, $i) - 1;

                continue;
            }

            if ($inClass) {
                $inClass = $char !== ']';

                continue;
            }

            if ($char === '[') {
                $inClass = true;

                continue;
            }

            if ($char !== '(') {
                continue;
            }

            $closes = self::groupEndsAt($branch, $i);

            if ($closes === null) {
                return false;
            }

            $kind = self::frameKindAt($branch, $i);
            $prefix = self::framePrefixLength($branch, $i, $kind);

            if (! self::everyAlternationIsUnambiguous(mb_substr($branch, $i + $prefix, $closes - $i - $prefix))) {
                return false;
            }

            $i = $closes;
        }

        return true;
    }

    /**
     * Whether an unbounded repetition of this body is forced to split in exactly one place.
     *
     * ⚠️ THE ORDINARY DELIMITED LIST IS SAFE, and the blunt form of rule 3 refused it:
     * `^[^,]+(?:,[^,]+)*$` nests `+` inside `*` and cannot backtrack catastrophically. This is
     * the exemption that keeps it, and it is a proof rather than a guess.
     *
     * If the body begins with a required literal character and no unbounded quantifier inside the
     * body can match that character, then every iteration must start at an occurrence of it and
     * none can consume one. The positions of that character in the subject therefore FORCE the
     * division into iterations — there is exactly one way to split, so there is nothing to
     * backtrack over and the match stays linear however long the subject is.
     *
     * `(?:,[^,]+)*` qualifies: the delimiter is `,` and `[^,]` cannot match it. `([a-zA-Z0-9]+\.?)+`
     * does not, because it begins with a class rather than a literal — and it is precisely the
     * shape that makes neither engine answer. `(a+)+` does not, because its leading literal is the
     * unbounded atom itself.
     *
     * ⚠️ ALTERNATION DISQUALIFIES IT OUTRIGHT. With two branches there is no single leading
     * character to reason from, and rule 4 is the rule that looks at that case.
     */
    private static function repetitionIsDelimited(string $body): bool
    {
        if (self::containsAlternation($body)) {
            return false;
        }

        $delimiter = self::leadingLiteral($body);

        return $delimiter !== null
            && ! self::variableAtomCanMatch($body, $delimiter)
            && ! self::atomRunExceeds($body, self::RUN_INSIDE_REPETITION);
    }

    /**
     * Every top-level branch's leading required literal, or null when any branch has none.
     *
     * ⚠️ EVERY BRANCH, BECAUSE ONE IS NOT A SEPARATOR — review found the first branch being taken for
     * the group. `^a*(?:b|a)*a*c$` read as beginning with `b`, which `a*` cannot match, so the run reset
     * and the pattern published; its `a` branch means all three quantified atoms consume the same input.
     * Node 24 spent about 1.9 seconds on a failing 2,000-character value and more than 20 seconds at the
     * permitted 5,000.
     *
     * ⚠️ NULL RATHER THAN A SHORTER LIST when a branch has no leading literal, because a branch that can
     * begin with anything is a branch that separates nothing — and a list missing that branch would look
     * like proof the group is delimited. `(?:a|)` returns null for the same reason: an empty branch
     * matches no character, so it cannot force a boundary.
     *
     * A body with no top-level alternation is one branch, so this is the single-lead case unchanged.
     *
     * @return list<string>|null
     */
    private static function branchLeads(string $body): ?array
    {
        $leads = [];

        foreach (self::topLevelBranches($body) as $branch) {
            $lead = self::leadingLiteral($branch);

            if ($lead === null) {
                return null;
            }

            $leads[] = $lead;
        }

        return $leads === [] ? null : $leads;
    }

    /**
     * Whether this atom's own leading literals force a boundary after `$previous`.
     *
     * ⚠️ ALL OR NOTHING. The preceding atom must be unable to match EVERY way this one can begin; one
     * branch it can match is one way the two can consume the same input, which is the whole hazard.
     *
     * @param  list<string>|null  $leads
     */
    private static function separates(?string $previous, ?array $leads): bool
    {
        if ($previous === null || $leads === null) {
            return false;
        }

        foreach ($leads as $lead) {
            if (self::atomMatches($previous, $lead)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether this atom refers to what another group matched, rather than naming characters itself.
     *
     * ⚠️ THE THREE SPELLINGS, and a character test rather than a pattern — my first version WAS a
     * regular expression and it did not compile, which is a poor way to write a guard whose whole job
     * is to fail closed. `\1` is numeric, `\k<name>` is the named form and `\g{1}` the relative one.
     */
    private static function isBackreference(string $atom): bool
    {
        if (! str_starts_with($atom, '\\')) {
            return false;
        }

        $after = mb_substr($atom, 1, 1);

        return $after === 'k' || $after === 'g' || ($after >= '0' && $after <= '9');
    }

    /**
     * The body's first character when it is a required, unquantified literal, else null.
     *
     * ⚠️ A QUANTIFIER ON IT DISQUALIFIES IT, even `{2,}`, because the proof needs each iteration to
     * begin with exactly one occurrence of the delimiter. A class, a group, `.` or an anchor is not
     * a literal and returns null — as does a class shorthand like `\w`, where the backslash is
     * present but the atom is a set.
     */
    private static function leadingLiteral(string $body): ?string
    {
        if ($body === '') {
            return null;
        }

        $first = mb_substr($body, 0, 1);
        $atom = 1;
        $character = $first;

        if ($first === '\\') {
            $character = mb_substr($body, 1, 1);
            $atom = 2;

            // `\d`, `\w`, `\s` and friends are sets rather than characters.
            if (preg_match('/^[A-Za-z0-9]$/', $character) === 1) {
                return null;
            }
        } elseif (in_array($first, ['[', '(', '.', '^', '$', '|', '*', '+', '?'], true)) {
            return null;
        }

        return self::quantifierAt($body, $atom) === '' ? $character : null;
    }

    /**
     * Whether any unbounded-quantified atom in the body could consume `$delimiter`.
     *
     * ⚠️ CLASS MEMBERSHIP IS DELEGATED TO PCRE rather than parsed here: the atom is compiled as
     * an anchored pattern and asked directly whether it matches the one character. Hand-parsing
     * class syntax — ranges, negation, nested shorthands, escapes — to answer a question PCRE
     * already answers exactly is how a subtly wrong "safe" verdict would get written.
     *
     * ⚠️ EVERY VARIABLE-WIDTH ATOM, NOT ONLY THE UNBOUNDED ONES, which is what review found. Asking
     * only about unbounded atoms exempted `^(?:,,?)*X$`: the optional comma is bounded, and it can
     * still either end the current iteration or start the next, so the division is not forced at all.
     * A REQUIRED atom that matches the delimiter is fine — `,a,` still splits one way — because it is
     * the choice about whether to consume one that creates the ambiguity, not the consuming.
     *
     * ⚠️ FAILS CLOSED. An unparseable atom, an unbounded-quantified group, or `.` returns true,
     * which means "not exempt", which means refused. The exemption has to be certain to be worth
     * having. A group with no quantifier is read through instead of refused, because refusing it
     * would take the ordinary `(?:,[^,]+)*` list with it.
     */
    private static function variableAtomCanMatch(string $body, string $delimiter): bool
    {
        $length = mb_strlen($body);

        for ($i = 0; $i < $length; $i++) {
            $token = self::atomAt($body, $i);

            if ($token === null) {
                return true;
            }

            $atom = $token['atom'];
            $quantifier = $token['quantifier'];
            $i = $token['after'] - 1;

            if ($atom === '^' || $atom === '$') {
                continue;
            }

            if (str_starts_with($atom, '(')) {
                /*
                 * ⚠️ A REQUIRED GROUP IS TRANSPARENT, NOT EMPTY, which is what review found next.
                 * Skipping to the closing bracket asked only about the group's own quantifier, so
                 * a variable-width atom one level down was invisible: `^(?:,(?:,?))*X$` was
                 * accepted although it is `^(?:,,?)*X$` — refused directly — with a bracket pair
                 * around the optional comma. Measured on the same 40-delimiter subject that
                 * exposed the direct form: Node 22 spends 10.5 SECONDS deciding it does not match.
                 *
                 * A group carrying a variable-width quantifier still fails closed without being
                 * read, because repeating a body an unbounded number of times can consume the
                 * delimiter however the body is written.
                 */
                if (self::isVariableWidth($quantifier)) {
                    return true;
                }

                $inner = self::frameBody($atom);

                if (self::variableAtomCanMatch($inner, $delimiter)) {
                    return true;
                }

                continue;
            }

            if (! self::isVariableWidth($quantifier)) {
                continue;
            }

            // `.` matches almost everything, including every delimiter this proof admits.
            if ($atom === '.') {
                return true;
            }

            /*
             * ⚠️ A BACKREFERENCE FAILS CLOSED, because its payload is whatever its GROUP matched and
             * that is not visible in the atom. Probing one in isolation answers a different question,
             * and review found the two spellings answering it by accident in opposite directions:
             *
             *   /^\1$/uD    PCRE cannot compile a reference to a group that is not there, so
             *               `preg_match()` returns FALSE and the `!== 0` test below failed closed
             *   /^\10$/uD   PCRE reads it as an OCTAL escape instead, compiles, does not match `,`
             *               and the same test concluded the atom cannot consume the delimiter
             *
             * So `^()()()()()()()()()(,)(?:,\10?)*X$` was published at 34 characters. Group 10 is
             * `(,)`, making the body `(?:,,?)` — whose second comma is optional and therefore no
             * delimiter — and Node 22.23.2 spends 1,555 ms on 40 commas and no `X`, against 1,470 ms
             * for `^(?:,,?)*X$`, which is refused. Same cost, opposite verdict.
             *
             * Only a VARIABLE-WIDTH one reaches here: a required backreference has already continued
             * above, so `^(a)(b)\2\1$` and its kind are untouched.
             */
            if (self::isBackreference($atom)) {
                return true;
            }

            /*
             * ⚠️ THE NORMALISED ATOM, not the published one, because they are not the same pattern
             * and `delimit()` compiles the normalised form. `\s` is rewritten to the class
             * ECMAScript means by it (see `ECMASCRIPT_SPACE`), and the two dialects disagree on
             * three code points — so a raw PCRE probe answered about a class that is never
             * compiled. Review found the direction that matters: PCRE's `\s` excludes U+FEFF, so
             * `^(?:<U+FEFF>\s?)*X$` was told its optional atom could not consume the delimiter and
             * was accepted. ECMAScript's `\s` DOES include the BOM, and Node 22 spends 17.2
             * SECONDS on 40 BOMs followed by a non-match.
             */
            if (@preg_match('/^'.self::withEcmaScriptDot($atom).'$/uD', $delimiter) !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The quantifier that makes this capture run more than once, or null when nothing does.
     *
     * ⚠️ THE CAPTURE ITSELF FIRST, THEN ITS ANCESTORS INSIDE THE LOOKBEHIND. `([ab]){1,2}` repeats
     * itself; `(?:([ab])){1,2}` has the capture written once and run twice, and the two measure
     * identically — so a rule that read only the capture's own quantifier would take the first and
     * leave the second, which is the shape review actually asked about.
     *
     * ⚠️ ONLY ANCESTORS WITH `inLookbehind` SET, which is what confines this to the lookbehind
     * without having to find it. A group nested in the lookbehind carries the flag; the lookbehind
     * itself does not, and a repetition OUTSIDE it re-runs the whole assertion rather than
     * reallocating a capture within one traversal.
     *
     * @param  list<array{open:int, close:int, kind:string, body:string, quantifier:string, inLookbehind:bool}>  $frames
     * @param  array{open:int, close:int, kind:string, body:string, quantifier:string, inLookbehind:bool}  $capture
     */
    private static function repeatingAncestor(array $frames, array $capture): ?string
    {
        if (self::repeatsMoreThanOnce($capture['quantifier'])) {
            return $capture['quantifier'];
        }

        foreach ($frames as $frame) {
            $encloses = $frame['open'] < $capture['open'] && $frame['close'] > $capture['close'];

            if ($encloses && $frame['inLookbehind'] && self::repeatsMoreThanOnce($frame['quantifier'])) {
                return $frame['quantifier'];
            }
        }

        return null;
    }

    /**
     * Whether this quantifier can run its atom twice.
     *
     * ⚠️ NOT `isVariableWidth()`, AND THAT DISTINCTION IS THE FINDING. `{2}` is fixed width and
     * still runs twice, so it reallocates which iteration stays captured — measured divergent on
     * three of six subjects. `?` and `{0,1}` run it at most once and are left alone: they make the
     * lookbehind variable-length, which is the other rule's business, and they cannot reallocate
     * anything because there is only ever one iteration to keep.
     */
    /**
     * Whether this subpattern holds an assertion at any depth.
     *
     * ⚠️ PARSED, NOT SEARCHED FOR. `str_contains($body, '(?=')` would be a pattern matching a pattern,
     * which is the failure `sanitize()`'s allowlist docblock describes in another file — a literal
     * `(?=` inside a character class is not an assertion, and an escaped one is not either. `frames()`
     * already yields every nested frame with its kind.
     */
    private static function containsAssertion(string $body): bool
    {
        foreach (self::frames($body) as $frame) {
            if (self::isAssertionKind($frame['kind'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this quantifier bounds its atom at zero repetitions, so the atom never runs at all.
     *
     * ⚠️ ONLY AN UPPER BOUND OF ZERO. `{0,}` is zero-or-more and runs; `{0}` and `{0,0}` do not, in
     * either greediness. Review found `^a*a*(?:a*){0}b$` REFUSED while `^a*a*b$` — the same regular
     * expression — is deliberately admitted, which on an upgrade is `kitsune:audit-patterns --strict`
     * blocking a deploy over a pattern that saved yesterday. §4 calls that a broken install rather than
     * a fixed one.
     */
    private static function neverRuns(string $quantifier): bool
    {
        if (preg_match('/^\{([0-9]+)(?:,([0-9]*))?\}\??$/', $quantifier, $bound) !== 1) {
            return false;
        }

        /*
         * ⚠️ PARSED NUMERICALLY, NOT MATCHED AS DIGITS, which review found the first version doing:
         * `/^\{0(?:,…/` recognised one leading zero, so `{00}` and `{00,00}` were refused as running
         * groups although both engines accept them and neither ever executes. Same upgrade hazard, one
         * spelling along — and `repeatsMoreThanOnce()` beside this already parses its bound, which is
         * where the shape should have come from.
         *
         * `{0}` is exactly zero; `{0,m}` is zero only when m is zero; `{0,}` is unbounded and runs.
         */
        $upper = ($bound[2] ?? '') !== '' || ! array_key_exists(2, $bound)
            ? (int) ($bound[2] ?? $bound[1])
            : PHP_INT_MAX;

        return $upper === 0;
    }

    private static function repeatsMoreThanOnce(string $quantifier): bool
    {
        if ($quantifier === '' || $quantifier === '?' || $quantifier === '??') {
            return false;
        }

        if (preg_match('/^\{([0-9]+)(?:,([0-9]*))?\}\??$/', $quantifier, $bound) === 1) {
            // `{n}` runs n times; `{n,m}` up to m; `{n,}` without limit.
            $upper = array_key_exists(2, $bound)
                ? ($bound[2] === '' ? PHP_INT_MAX : (int) $bound[2])
                : (int) $bound[1];

            return $upper >= 2;
        }

        // `*`, `+` and their lazy forms.
        return true;
    }

    /**
     * The atom that begins at `$at`, with any quantifier attached to it, or null if unparseable.
     *
     * ⚠️ ONE SCANNER FOR TWO QUESTIONS, deliberately. "Can a variable atom eat the delimiter" and
     * "is every variable atom separated from the next" walk the same syntax and differ only in what
     * they do with each atom — and this project has already spent time on what two copies of one
     * rule cost. Escape spans, class ends and group ends are the parts that would drift, so they
     * live here once.
     *
     * @return array{atom: string, quantifier: string, after: int}|null
     */
    private static function atomAt(string $body, int $at): ?array
    {
        $char = mb_substr($body, $at, 1);
        $atom = $char;
        $ends = $at;

        if ($char === '\\') {
            // The whole escape is the atom: `\x61` is one character, not `\x` then `61`.
            $ends = $at + self::escapeSpan($body, $at) - 1;
            $atom = mb_substr($body, $at, $ends - $at + 1);
        } elseif ($char === '[' || $char === '(') {
            $closes = $char === '['
                ? self::classEndsAt($body, $at)
                : self::groupEndsAt($body, $at);

            if ($closes === null) {
                return null;
            }

            $ends = $closes;
            $atom = mb_substr($body, $at, $closes - $at + 1);
        }

        $quantifier = self::quantifierAt($body, $ends + 1);

        return [
            'atom' => $atom,
            'quantifier' => $quantifier,
            'after' => $ends + 1 + mb_strlen($quantifier),
        ];
    }

    /**
     * Whether more than `$limit` variable-width atoms sit in a row with no forced boundary.
     *
     * ⚠️ ONE TRAVERSAL, TWO LIMITS, AND THE LIMITS ARE MEASURED. Review found three holes in the
     * previous version and all three were the same hole: the walk was not uniform. It ran only over
     * parenthesised frames, so `^a*a*a*a*a*a*b$` was analysed not at all; it skipped any repetition
     * with a finite bound, so `^(a|aa){1,32}$` was too; and it overwrote its pending atom when a
     * required group held another, so a bracket pair hid `^(?:,a*(?:a*))*X$`. Fixing the walk fixes
     * all three, which is why this is a rewrite rather than three patches.
     *
     * ⚠️ THE LIMIT DIFFERS BY CONTEXT BECAUSE THE COST CLASS DOES. Inside a repetition, k adjacent
     * atoms give the repetition k choices per iteration and the total is EXPONENTIAL in the subject.
     * At the top level the same k gives a polynomial of degree k. Measured on Node 22.23.2 with a
     * failing subject:
     *
     *   inside a repetition, `^(?:,a*a*)*X$`   n=12  53 ms   n=16  729 ms   n=20  59.8 SECONDS
     *   top level, `^a*a*b$`        (k=2)      n=1000  2 ms   n=20000  572 ms
     *   top level, `^a*a*a*b$`      (k=3)      n=1000  490 ms
     *   top level, `^a*a*a*a*b$`    (k=4)      n=500  7.9 SECONDS
     *
     * So the repetition body admits ONE and the top level admits TWO. Two at the top level is
     * quadratic in the value's length, and it is what real patterns are made of — `^.+\.[a-z]+$` and
     * `^[^@]+@[^@]+$` both measure 0 ms. Three is cubic and already 490 ms at 1,000 characters.
     *
     * ⚠️ AND THIS PARAGRAPH USED TO SAY "which `TextType` bounds by its configured `maxLength` (255 by
     * default)", which review checked and found was not a bound: the setting had no ceiling, so
     * quadratic meant whatever an org configured — 6.2 SECONDS at 65,535 characters. `TextType` now
     * caps it at `MAX_CONFIGURABLE_LENGTH`, chosen from that measurement, and the allowance here rests
     * on that cap rather than on a default. A claim about a bound has to name the thing that enforces
     * it.
     *
     * ⚠️ ONLY A REQUIRED LITERAL THE LEFT ATOM CANNOT MATCH ENDS A RUN, and a fixed-width atom does
     * not. `a*[a-z]{2}a*` looks divided and is not: the middle is two characters wide but its
     * POSITION is still free, so the subject can be split many ways. The argument is the same one
     * the delimiter proof rests on, and it is about distinguishability rather than width.
     *
     * ⚠️ FAILS CLOSED on anything unparseable.
     */
    private static function atomRunExceeds(string $sequence, int $limit): bool
    {
        $atoms = self::flatAtoms($sequence);

        if ($atoms === null) {
            return true;
        }

        /*
         * ⚠️ AN ASSERTION'S BODY IS A SEQUENCE IN ITS OWN RIGHT, and it is checked HERE rather than in
         * the walk below because only this caller owns the whole pattern. Making an assertion
         * transparent to the run would otherwise hide `(?=a*a*a*b)` from the rule that saw it while it
         * was being spliced — a failing lookahead is re-divided exactly as a failing sequence is, so
         * the enclosing limit applies to it unchanged.
         *
         * The pricing caller must NOT descend the same way: its own recursion already visits every
         * group, assertions included, so descending here as well would charge one body twice.
         */
        foreach ($atoms as $atom) {
            if ($atom['assertion'] && self::atomRunExceeds(self::frameBody($atom['atom']), $limit)) {
                return true;
            }
        }

        return self::runExceeds($atoms, $limit);
    }

    /**
     * Whether this sequence claims the quadratic allowance the top-level run limit grants.
     *
     * ⚠️ ITS OWN ATOMS, NOT THE FLATTENED ONES, so the allowance is charged at exactly one level. The
     * recursion prices every group's body in its own right, and reading through a required group here
     * would price its contents twice — `^(?:a*a*b)$` would cost the square of `^a*a*b$` and be refused
     * while `^a*a*b$` is published, for the same regular expression written two ways.
     *
     * ⚠️ FAILS CLOSED: an unparseable sequence is charged, matching `atomRunExceeds()`, which refuses
     * one.
     */
    private static function quadraticRuns(string $sequence): int
    {
        return self::runsPast(self::ownAtoms($sequence), self::RUN_WITHOUT_COST);
    }

    /**
     * What one sequence pays for claiming the allowance: the budget divided by how many may claim it.
     *
     * ⚠️ DERIVED RATHER THAN WRITTEN DOWN, so the three numbers cannot disagree after any one of them
     * moves: 65,536 divided by 8 is 8,192, eight grants reach the budget exactly and nine pass it.
     * `intdiv()` rather than `/`, because a float cost would propagate through the saturating
     * arithmetic and out through `number_format()` into the refusal an author reads.
     */
    private static function quadraticBranchCost(): int
    {
        return intdiv(self::MAX_AMBIGUITY_PRODUCT, self::MAX_QUADRATIC_BRANCHES);
    }

    /**
     * @param  list<array{atom: string, quantifier: string, variable: bool, leads: list<string>|null, assertion: bool}>|null  $atoms
     */
    private static function runExceeds(?array $atoms, int $limit): bool
    {
        return self::runsPast($atoms, $limit) > 0;
    }

    /**
     * How many separate runs in this sequence are longer than `$limit`.
     *
     * ⚠️ A COUNT RATHER THAN A BOOLEAN, because review disproved the limit I had STATED as deliberate.
     * `sequenceCost()` charged one grant per sequence, and its docblock argued that a second run in the
     * same branch is never reached because a subject failing in the first stops there. That is wrong the
     * moment the first run's separator MATCHES: the engine goes on to the second, and every allocation
     * of the first is retried against it. Measured on Node 22.23.2, `^a*a*ba*a*c$` against
     * `a×n . b . a×n . d`:
     *
     *   1,202 chars     317.5 ms          one run, 4,802 chars:  33.4 ms
     *   2,402 chars   2,512.9 ms
     *   4,802 chars  20,016.4 ms        within the configured ceiling
     *
     * Eight times per doubling against four for one run. So the cost is per run, and the model says so.
     *
     * ⚠️ FAILS CLOSED: an unparseable sequence counts as one, matching the refusal this used to be.
     *
     * @param  list<array{atom: string, quantifier: string, variable: bool, leads: list<string>|null, assertion: bool}>|null  $atoms
     */
    private static function runsPast(?array $atoms, int $limit): int
    {
        if ($atoms === null) {
            return 1;
        }

        $past = 0;
        $counted = false;
        $run = 0;
        $previous = null;

        foreach ($atoms as $atom) {
            /*
             * ⚠️ ZERO-WIDTH MEANS TRANSPARENT, not absent — review found the third possibility being
             * treated as the second. An assertion consumes nothing, so it cannot fill a run and it
             * cannot divide one: `a*(?!b)a*` is `a*a*` and must read as two atoms in a row. Neither
             * `$run` nor `$previous` may move. Its body is a sequence too, and whose business that is
             * depends on the caller — see `atomRunExceeds()`.
             */
            if ($atom['assertion']) {
                continue;
            }

            if ($atom['variable']) {
                /*
                 * ⚠️ A GROUP CAN SEPARATE ITSELF, which is what keeps the ordinary delimited list
                 * publishable at the top level. `[^,]+(?:,[^,]+)*` is two variable-width atoms in a
                 * row, and the second one must BEGIN with a comma the first cannot match — so the
                 * boundary is forced by the group's own leading literal rather than by anything
                 * between them.
                 */
                $separated = self::separates($previous, $atom['leads']);

                if ($separated) {
                    // A forced boundary ends the run before it, so the next one is counted separately.
                    $counted = false;
                }

                $run = $separated ? 1 : $run + 1;

                if ($run > $limit && ! $counted) {
                    $past++;
                    $counted = true;
                }

                $previous = $atom['atom'];

                continue;
            }

            if ($previous === null || $atom['quantifier'] !== '') {
                continue;
            }

            $character = self::literalCharacter($atom['atom']);

            if ($character !== null && ! self::atomMatches($previous, $character)) {
                $run = 0;
                $counted = false;
                $previous = null;
            }
        }

        return $past;
    }

    /**
     * The sequence as a flat list of atoms, reading through groups that run at most once.
     *
     * ⚠️ FLATTENING IS WHAT MAKES THE PENDING STATE SURVIVE A GROUP. The previous version recursed
     * and assigned its pending atom from the recursion's result, which discarded whatever was pending
     * outside — so `,a*(?:a*)` read as one atom rather than two adjacent ones. Review found it. With
     * the contents spliced into one list there is no pending state to lose, and the bug cannot be
     * written again by construction.
     *
     * ⚠️ ONLY A GROUP THAT RUNS AT MOST ONCE AND IS REQUIRED IS SPLICED. `(?:ab)?` must stay whole,
     * or its contents would read as required and `a` would look like a separator that can be absent.
     * A group that repeats stays whole too, and is variable when anything inside it is — `(?:a*){2}`
     * is `a*a*` and must not read as one fixed atom.
     *
     * @return list<array{atom: string, quantifier: string, variable: bool, leads: list<string>|null, assertion: bool}>|null
     */
    private static function flatAtoms(string $sequence, int $depth = 0): ?array
    {
        return self::atomList($sequence, true, $depth);
    }

    /**
     * The sequence's OWN atoms, with every group counted as one rather than read through.
     *
     * ⚠️ THE OTHER VIEW EXISTS SO A RUN IS CHARGED ONCE, and the two answer different questions. A
     * rule that REFUSES a run has to read through required groups, or `(?:a*)(?:a*)` would hide one
     * from it. A rule that PRICES one must not, because the same atoms are priced again when the
     * recursion reaches each group's body — `^(?:a*a*b)$` would pay twice for the one run it has, and
     * be refused while `^a*a*b$` is published.
     *
     * @return list<array{atom: string, quantifier: string, variable: bool, leads: list<string>|null, assertion: bool}>|null
     */
    private static function ownAtoms(string $sequence): ?array
    {
        return self::atomList($sequence, false, 0);
    }

    /**
     * @param  bool  $splice  Whether a required group that runs at most once is read through.
     * @return list<array{atom: string, quantifier: string, variable: bool, leads: list<string>|null, assertion: bool}>|null
     */
    private static function atomList(string $sequence, bool $splice, int $depth): ?array
    {
        // A bound on nesting, so a pathological pattern cannot recurse without end. The length
        // limit already caps depth at 250; this is the belt to that brace.
        if ($depth > 64) {
            return null;
        }

        $length = mb_strlen($sequence);
        $atoms = [];

        for ($i = 0; $i < $length; $i++) {
            $token = self::atomAt($sequence, $i);

            if ($token === null) {
                return null;
            }

            $atom = $token['atom'];
            $quantifier = $token['quantifier'];
            $i = $token['after'] - 1;

            /*
             * ⚠️ AN ANCHOR CONSUMES NOTHING AND A `{0}` GROUP RUNS NEVER, so neither can fill a run or
             * divide one. The second was review's finding: `(?:a*){0}` read as a variable atom between
             * two others, refusing a pattern identical to one that is deliberately admitted.
             */
            if ($atom === '^' || $atom === '$' || self::neverRuns($quantifier)) {
                continue;
            }

            if (! str_starts_with($atom, '(')) {
                $atoms[] = [
                    'atom' => $atom,
                    'quantifier' => $quantifier,
                    'variable' => self::isVariableWidth($quantifier),
                    'leads' => null,
                    'assertion' => false,
                ];

                continue;
            }

            $kind = self::frameKindAt($atom, 0);
            $inner = self::frameBody($atom);

            /*
             * ⚠️ AN ASSERTION IS NEVER SPLICED, and review found this by measuring what splicing one
             * did: `^(?:,a*(?!b)a*)*X$` was published because the `b` inside the lookahead read as a
             * literal between the two `a*`, which is exactly the separator that ends a run. It
             * consumes nothing, so the body is `,a*a*` — two variable atoms in a row inside a
             * repetition. Node 24 took about 2.8 seconds on sixteen `,aa` segments and the cost grows
             * exponentially.
             *
             * It is carried through as an atom rather than dropped, because its BODY is a sequence in
             * its own right and the run rule still has to read it. The caller treats it as
             * zero-width: it neither continues a run nor divides one, and its body is checked
             * separately.
             */
            if (self::isAssertionKind($kind)) {
                $atoms[] = [
                    'atom' => $atom,
                    'quantifier' => $quantifier,
                    'variable' => false,
                    'leads' => null,
                    'assertion' => true,
                ];

                continue;
            }

            /*
             * ⚠️ ANY QUANTIFIER THAT MEANS EXACTLY ONCE, PARSED RATHER THAN LISTED, and review found the
             * literal list letting a group hide a run: `^(?:,(?:a*a*){1,1})*X$` was accepted while the
             * `{1}` spelling of the same expression is refused, and Node 24 spends about 6 seconds on
             * sixteen `,aa` segments. `{01}` is the same gap with a leading zero.
             *
             * `fixedRepetitions()` already answers "how many times, exactly" and handles the padded and
             * equal-bounded forms, so asking it is both narrower and wider than the list in the right
             * directions — and there is now one place that decides what "once" means rather than two
             * that can disagree.
             */
            if ($splice && ($quantifier === '' || self::fixedRepetitions($quantifier) === 1)) {
                $spliced = self::atomList($inner, true, $depth + 1);

                if ($spliced === null) {
                    return null;
                }

                foreach ($spliced as $one) {
                    $atoms[] = $one;
                }

                continue;
            }

            $atoms[] = [
                'atom' => $atom,
                'quantifier' => $quantifier,
                'variable' => self::isVariableWidth($quantifier) || self::variableAtomCanMatchAnything($inner),
                // A repeated group begins where its body begins, so its body's leading literal is
                // what a preceding atom would have to run into.
                'leads' => self::branchLeads($inner),
                'assertion' => false,
            ];
        }

        return $atoms;
    }

    /** Whether this subpattern holds any variable-width atom at all. */
    private static function variableAtomCanMatchAnything(string $body): bool
    {
        $length = mb_strlen($body);

        for ($i = 0; $i < $length; $i++) {
            $token = self::atomAt($body, $i);

            if ($token === null) {
                return true;
            }

            $i = $token['after'] - 1;

            if (self::isVariableWidth($token['quantifier'])) {
                return true;
            }

            if (str_starts_with($token['atom'], '(')) {
                if (self::variableAtomCanMatchAnything(self::frameBody($token['atom']))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The single character this atom is, or null when it is a set, a group or anything wider.
     *
     * ⚠️ A CLASS IS NOT A SEPARATOR even when it happens to hold one character, because deciding
     * that requires parsing class syntax — the thing `variableAtomCanMatch()` delegates to PCRE
     * rather than doing by hand. `[,]` is therefore refused where `,` is accepted: conservative, and
     * the author can write the character.
     */
    private static function literalCharacter(string $atom): ?string
    {
        if (mb_strlen($atom) === 1) {
            return in_array($atom, ['[', '(', '.', '^', '$', '|', '*', '+', '?'], true) ? null : $atom;
        }

        if (! str_starts_with($atom, '\\')) {
            return null;
        }

        $letter = mb_substr($atom, 1, 1);

        // `\d`, `\w`, `\s` and friends are sets; `\.` and `\x41` are characters.
        if (mb_strlen($atom) === 2) {
            return preg_match('/^[A-Za-z0-9]$/', $letter) === 1 ? null : $letter;
        }

        if ($letter === 'x' && preg_match('/^[0-9A-Fa-f]{2}$/', mb_substr($atom, 2, 2)) === 1) {
            $decoded = pack('H*', mb_substr($atom, 2, 2));

            /*
             * ⚠️ VALID UTF-8 ONLY. `\xE9` is one BYTE and not a character: probed with `/u` against
             * an invalid subject `preg_match()` returns false, which `atomMatches()` would read as
             * "it matches" — failing closed, but for the wrong reason and silently. Above 0x7F the
             * portable spelling is the character itself, so returning null costs nothing.
             */
            return mb_check_encoding($decoded, 'UTF-8') ? $decoded : null;
        }

        return null;
    }

    /**
     * Whether this atom matches exactly this one character.
     *
     * ⚠️ THE NORMALISED ATOM, for the reason `variableAtomCanMatch()` records at length: `delimit()`
     * compiles a rewritten `\s`, and probing the published text asks about a class that is never
     * compiled. One helper so the two questions cannot answer differently.
     */
    private static function atomMatches(string $atom, string $character): bool
    {
        if ($atom === '.') {
            return true;
        }

        return @preg_match('/^'.self::withEcmaScriptDot($atom).'$/uD', $character) !== 0;
    }

    /**
     * How many characters the escape starting at `$at` occupies, counting the backslash.
     *
     * ⚠️ EVERY SCANNER IN THIS FILE NEEDS THIS, and each of them used to advance by exactly two.
     * That is right for `\.` and wrong for every escape with a payload, and review found what it
     * cost: `fixedWidth()` read `\x61` as a backslash-x atom followed by the literals `6` and `1`,
     * so it reported width 3 for a one-character escape. `(?<=(\x61|aaa))b\1$` therefore passed
     * the equal-length lookbehind rule — measured, PCRE says no and ECMAScript says yes.
     *
     * ⚠️ The payload is also why a two-character advance is not merely imprecise but WRONG for the
     * structural scans: `\c|` puts a `|` in the payload position, and a scanner that steps over
     * only `\c` reads it as an alternation that is not there.
     *
     * Only the forms the grammar admits are recognised — `\x{41}` is PCRE-only and refused
     * elsewhere, so it is not a case here.
     */
    private static function escapeSpan(string $text, int $at): int
    {
        $letter = mb_substr($text, $at + 1, 1);

        if ($letter === '') {
            // A trailing backslash. `compiles()` has already refused it; this keeps the scan in step.
            return 1;
        }

        // `\x41`, whose portable form is exactly two hex digits.
        if ($letter === 'x' && preg_match('/^[0-9A-Fa-f]{2}$/', mb_substr($text, $at + 2, 2)) === 1) {
            return 4;
        }

        // `\cA`, one character of payload.
        if ($letter === 'c' && mb_substr($text, $at + 2, 1) !== '') {
            return 3;
        }

        if (($letter === 'p' || $letter === 'P') && mb_substr($text, $at + 2, 1) === '{') {
            $closes = mb_strpos($text, '}', $at + 2);

            return $closes === false ? 2 : $closes - $at + 1;
        }

        if ($letter === 'k' && mb_substr($text, $at + 2, 1) === '<') {
            $closes = mb_strpos($text, '>', $at + 2);

            return $closes === false ? 2 : $closes - $at + 1;
        }

        /*
         * ⚠️ EVERY DIGIT OF A NUMERIC REFERENCE, and review found only the first being taken. A
         * two-digit backreference then scanned as `\1` followed by a separate `0?`:
         *
         *   ^()()()()()()()()()(,)(?:,\10?)*X$     34 characters, PUBLISHED
         *
         * The delimiter proof read a required `,` followed by an optional `0`, so the repetition looked
         * divided. Both engines read `\10` as group 10 — which is `(,)` — making the body `(?:,,?)`,
         * whose second comma is optional and therefore no delimiter at all. Measured on Node 22.23.2
         * with 40 commas and no `X`: 1,555 ms for this pattern and 1,470 ms for `^(?:,,?)*X$` written
         * out, which IS refused. Same cost, opposite verdict, which is the whole finding.
         *
         * `\1?` alone was already refused. Only the multi-digit spelling escaped the scanner, which is
         * the kind of gap that survives because the single-digit case is the one anybody tests.
         */
        if (preg_match('/^[0-9]+/', mb_substr($text, $at + 1), $digits) === 1) {
            return 1 + mb_strlen($digits[0]);
        }

        return 2;
    }

    /** A short quotation of the pattern between two offsets, for a refusal message. */
    private static function excerpt(string $pattern, int $open, int $close): string
    {
        $text = mb_substr($pattern, $open, $close - $open + 1);

        return mb_strlen($text) > 32 ? mb_substr($text, 0, 32).'…' : $text;
    }

    /**
     * Every parenthesised group in the pattern, with what encloses it and what follows it.
     *
     * ⚠️ A SECOND SCAN rather than an extension of `capturingGroupSpans()`, which exists to
     * answer whether a BACKREFERENCE can see its group and carries optionality and branch
     * indices for that purpose. The structural rules need the group's kind, its body, the
     * quantifier attached to it and whether a lookbehind encloses it — a different question
     * about the same syntax, and merging them would make one method serve two masters.
     *
     * The group vocabulary is closed by `groupRefusal()` before this runs: `(`, `(?:`, `(?=`,
     * `(?!`, `(?<=`, `(?<!` and `(?<name>` are the only forms that reach here.
     *
     * @return list<array{open:int, close:int, kind:string, body:string, quantifier:string, inLookbehind:bool}>
     */
    private static function frames(string $pattern): array
    {
        $length = mb_strlen($pattern);
        $inClass = false;
        $stack = [];
        $frames = [];

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($pattern, $i, 1);

            if ($char === '\\') {
                $i += self::escapeSpan($pattern, $i) - 1;

                continue;
            }

            if ($inClass) {
                $inClass = $char !== ']';

                continue;
            }

            if ($char === '[') {
                $inClass = true;

                continue;
            }

            if ($char === '(') {
                $stack[] = ['open' => $i, 'kind' => self::frameKindAt($pattern, $i)];

                continue;
            }

            if ($char !== ')' || $stack === []) {
                continue;
            }

            $open = array_pop($stack);

            // ⚠️ Enclosure is read from the STACK as it stands, so this is the lookbehind
            // question answered at the moment the group closes rather than by a second walk.
            $inLookbehind = false;

            foreach ($stack as $ancestor) {
                if ($ancestor['kind'] === 'lookbehind' || $ancestor['kind'] === 'nlookbehind') {
                    $inLookbehind = true;
                }
            }

            $prefix = self::framePrefixLength($pattern, $open['open'], $open['kind']);

            $frames[] = [
                'open' => $open['open'],
                'close' => $i,
                'kind' => $open['kind'],
                'body' => mb_substr($pattern, $open['open'] + $prefix, $i - $open['open'] - $prefix),
                'quantifier' => self::quantifierAt($pattern, $i + 1),
                'inLookbehind' => $inLookbehind,
            ];
        }

        return $frames;
    }

    /** Which of the six permitted group forms opens at `$at`. */
    private static function frameKindAt(string $pattern, int $at): string
    {
        if (mb_substr($pattern, $at + 1, 1) !== '?') {
            return 'capture';
        }

        return match (true) {
            mb_substr($pattern, $at + 2, 1) === ':' => 'group',
            mb_substr($pattern, $at + 2, 1) === '=' => 'lookahead',
            mb_substr($pattern, $at + 2, 1) === '!' => 'nlookahead',
            mb_substr($pattern, $at + 2, 2) === '<=' => 'lookbehind',
            mb_substr($pattern, $at + 2, 2) === '<!' => 'nlookbehind',
            default => 'named',
        };
    }

    /**
     * Whether this frame kind consumes nothing, so it can neither fill nor divide a run of atoms.
     *
     * ⚠️ EXTRACTED RATHER THAN REPEATED, because the second caller is the reason review found the
     * first one's list at all: `flatAtoms()` had no notion of an assertion and spliced one like any
     * other required group, which made a literal inside it look like a separator. Two copies of a
     * four-way comparison would let the next kind be added to one of them.
     */
    private static function isAssertionKind(string $kind): bool
    {
        return $kind === 'lookahead'
            || $kind === 'nlookahead'
            || $kind === 'lookbehind'
            || $kind === 'nlookbehind';
    }

    /**
     * The body inside a frame's own delimiters — `(?:ab)+` gives `ab`, `(?<=x)` gives `x`.
     *
     * ⚠️ FOUR COPIES OF THIS ARITHMETIC EXISTED and a fifth was about to, which is what prompted
     * extracting it: the prefix depends on the frame KIND, so every caller had to remember to ask
     * `frameKindAt()` first and to drop the closing parenthesis at the end. A caller that forgot
     * either would read a body shifted by one and be wrong quietly.
     *
     * Takes an atom that IS a frame, as `atomAt()` returns it, rather than a position in a pattern.
     */
    private static function frameBody(string $atom): string
    {
        $prefix = self::framePrefixLength($atom, 0, self::frameKindAt($atom, 0));

        return mb_substr($atom, $prefix, mb_strlen($atom) - $prefix - 1);
    }

    /** How many characters of the group's opening are syntax rather than body. */
    private static function framePrefixLength(string $pattern, int $at, string $kind): int
    {
        if ($kind === 'capture') {
            return 1;
        }

        if ($kind === 'named') {
            $closes = mb_strpos($pattern, '>', $at);

            return $closes === false ? 3 : $closes - $at + 1;
        }

        return $kind === 'lookbehind' || $kind === 'nlookbehind' ? 4 : 3;
    }

    /** The quantifier written at `$at`, or an empty string when there is none. */
    private static function quantifierAt(string $pattern, int $at): string
    {
        $char = mb_substr($pattern, $at, 1);

        if ($char === '*' || $char === '+' || $char === '?') {
            // The lazy suffix is part of the quantifier; it changes preference, not bounds.
            return mb_substr($pattern, $at + 1, 1) === '?' ? $char.'?' : $char;
        }

        if ($char !== '{') {
            return '';
        }

        $closes = mb_strpos($pattern, '}', $at);

        if ($closes === false) {
            return '';
        }

        $brace = mb_substr($pattern, $at, $closes - $at + 1);

        if (preg_match('/^\{[0-9]+(,[0-9]*)?\}$/', $brace) !== 1) {
            return '';
        }

        /*
         * ⚠️ THE LAZY SUFFIX IS PART OF THE QUANTIFIER, and dropping it made `fixedWidth()` count the
         * `?` as a separate one-character atom. `a{2}?` was therefore measured as width 3, so
         * `(?<=(a{2}?|aaa))b\1$` looked like two equal branches and published — and on `aaabaa`,
         * PCRE says no while ECMAScript says yes. Found by review.
         *
         * The `*`/`+`/`?` branch above already did this; only the braced form did not.
         */
        return mb_substr($pattern, $closes + 1, 1) === '?' ? $brace.'?' : $brace;
    }

    /**
     * Whether a quantifier lets its atom match more than one length.
     *
     * ⚠️ WIDER THAN "HAS NO UPPER BOUND", and the difference is a defect review found. This class had
     * an `isUnbounded()` for exactly that narrower question; it was the last caller's, and it went
     * with the caller — a quantifier having an upper bound stopped meaning anything safe when review
     * showed the bound is only the exponent. The delimiter
     * proof asked only whether an UNBOUNDED atom could consume the delimiter, so `^(?:,,?)*X$` was
     * exempted: the optional comma is bounded, and it can still either end the current iteration or
     * start the next. Measured — 40 commas plus a `Y` takes ECMAScript about 1.3 seconds and grows
     * exponentially.
     *
     * `{n}` is fixed and `{n}?` is fixed-but-lazy, which changes preference rather than width.
     * Everything else — `?`, `*`, `+`, `{n,}`, `{n,m}` with n ≠ m — can match two lengths.
     */
    private static function isVariableWidth(string $quantifier): bool
    {
        if ($quantifier === '') {
            return false;
        }

        if (preg_match('/^\{([0-9]+)(?:,([0-9]*))?\}\??$/', $quantifier, $bound) === 1) {
            // `{n}` names one length; `{n,}` and `{n,m}` name a range unless m equals n.
            return array_key_exists(2, $bound) && $bound[2] !== (string) (int) $bound[1];
        }

        return true;
    }

    /** Whether this subpattern contains an alternation at any depth. */
    private static function containsAlternation(string $body): bool
    {
        $length = mb_strlen($body);
        $inClass = false;

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($body, $i, 1);

            if ($char === '\\') {
                $i += self::escapeSpan($body, $i) - 1;

                continue;
            }

            if ($inClass) {
                $inClass = $char !== ']';

                continue;
            }

            if ($char === '[') {
                $inClass = true;

                continue;
            }

            if ($char === '|') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the body is exactly an alternation of distinct literals, none a prefix of another.
     *
     * ⚠️ THE EXEMPTION IS EXACT, not generous. Prefix-freeness is precisely the condition under
     * which at most one branch can match at a given position: if two branches both matched
     * there, one would have to be a prefix of the other. So the alternation is deterministic,
     * and repeating something deterministic stays linear however long the subject is.
     *
     * ⚠️ An EMPTY branch fails this, and must. `(?:a|)+` can match the empty string at every
     * position, which is unbounded ambiguity of the worst kind — and the empty string is a
     * prefix of everything, so the same test that rejects `a|aa` rejects it too.
     */
    private static function branchesAreUnambiguousLiterals(string $body): bool
    {
        $branches = self::topLevelBranches($body);

        if (count($branches) < 2) {
            return false;
        }

        foreach ($branches as $branch) {
            // Conservative on purpose: a class or a quantified atom is where a wrong answer
            // about ambiguity would be expensive, so only plain literal text is exempted.
            if (preg_match('/^[A-Za-z0-9_-]+$/D', $branch) !== 1) {
                return false;
            }
        }

        foreach ($branches as $one) {
            foreach ($branches as $other) {
                if ($one !== $other && str_starts_with($other, $one)) {
                    return false;
                }
            }
        }

        return count(array_unique($branches)) === count($branches);
    }

    /**
     * The body split on its own top-level `|`, ignoring separators inside groups and classes.
     *
     * @return list<string>
     */
    private static function topLevelBranches(string $body): array
    {
        $length = mb_strlen($body);
        $inClass = false;
        $depth = 0;
        $branches = [];
        $current = '';

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($body, $i, 1);

            if ($char === '\\') {
                $span = self::escapeSpan($body, $i);
                $current .= mb_substr($body, $i, $span);
                $i += $span - 1;

                continue;
            }

            if ($inClass) {
                $inClass = $char !== ']';
                $current .= $char;

                continue;
            }

            if ($char === '[') {
                $inClass = true;
                $current .= $char;

                continue;
            }

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === '|' && $depth === 0) {
                $branches[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $branches[] = $current;

        return $branches;
    }

    /**
     * How many characters this subpattern always consumes, or null when that is not fixed.
     *
     * ⚠️ WIDTH, not length in characters of the pattern: `[ab]` is one, `(?=x)` is zero because
     * an assertion consumes nothing, and `a{3}` is three. Used by the two lookbehind rules,
     * where a variable width is exactly what the engines disagree about.
     *
     * ⚠️ Returns null rather than guessing wherever the answer is not certain — an unterminated
     * class, a backreference (whose width is whatever the group matched), or an alternation whose
     * branches differ. A null is a refusal, so the uncertain case fails closed.
     */
    private static function fixedWidth(string $body): ?int
    {
        $branches = self::topLevelBranches($body);

        if (count($branches) > 1) {
            $widths = [];

            foreach ($branches as $branch) {
                $width = self::fixedWidth($branch);

                if ($width === null) {
                    return null;
                }

                $widths[] = $width;
            }

            return count(array_unique($widths)) === 1 ? $widths[0] : null;
        }

        $body = $branches[0];
        $length = mb_strlen($body);
        $total = 0;

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($body, $i, 1);
            $width = 1;

            if ($char === '\\') {
                $escaped = mb_substr($body, $i + 1, 1);

                /*
                 * ⚠️ THE WHOLE ESCAPE, and reading only its letter is what review found. `\x61` is
                 * one character wide and four characters long; counting the payload as two more
                 * atoms reported width 3, and `(?<=(\x61|aaa))b\1$` then passed the equal-length
                 * lookbehind rule — PCRE says no on `aaaba`, ECMAScript says yes.
                 */
                $i += self::escapeSpan($body, $i) - 1;

                // A backreference's width is whatever its group matched, which is not knowable
                // here — and `\k<name>` is the same thing spelled differently.
                if ($escaped === 'k' || preg_match('/^[1-9]$/', $escaped) === 1) {
                    return null;
                }

                // ⚠️ `\b` and `\B` consume nothing. They are refused elsewhere, so this is for
                // completeness rather than reachability — but a width of 1 for them would be
                // wrong if that ever changed.
                if ($escaped === 'b' || $escaped === 'B') {
                    $width = 0;
                }
            } elseif ($char === '[') {
                $closes = self::classEndsAt($body, $i);

                if ($closes === null) {
                    return null;
                }

                $i = $closes;
            } elseif ($char === '(') {
                $closes = self::groupEndsAt($body, $i);

                if ($closes === null) {
                    return null;
                }

                $kind = self::frameKindAt($body, $i);
                $prefix = self::framePrefixLength($body, $i, $kind);
                $inner = mb_substr($body, $i + $prefix, $closes - $i - $prefix);

                // An assertion consumes nothing, whatever its body does.
                if ($kind === 'lookahead' || $kind === 'nlookahead' || $kind === 'lookbehind' || $kind === 'nlookbehind') {
                    $width = 0;
                } else {
                    $width = self::fixedWidth($inner);

                    if ($width === null) {
                        return null;
                    }
                }

                $i = $closes;
            } elseif ($char === '^' || $char === '$') {
                $width = 0;
            }

            $quantifier = self::quantifierAt($body, $i + 1);

            if ($quantifier !== '') {
                $repeat = self::fixedRepetitions($quantifier);

                if ($repeat === null) {
                    return null;
                }

                $width *= $repeat;
                $i += mb_strlen($quantifier);
            }

            $total += $width;
        }

        return $total;
    }

    /**
     * The exact number of repetitions a quantifier names, or null when it names a range.
     *
     * ⚠️ A LAZY SUFFIX IS PREFERENCE, NOT WIDTH, and this did not accept one — so once
     * `quantifierAt()` began carrying it, `a{2}?` stopped being recognised as fixed and
     * `(?<=(a{2}?|aa))b\1$` was refused for having a variable-length capture. Both branches are two
     * characters wide; `?` changes which match is preferred, not how long it is.
     */
    private static function fixedRepetitions(string $quantifier): ?int
    {
        /*
         * ⚠️ `{n,n}` IS FIXED TOO, and review found this reading only `{n}`. An equal bounded form is
         * one width by construction, both engines compile it and they agree exactly — measured,
         * `(?<=a{1,1})b` matches `ab` in both and `(?<=a{2,2})b` matches `aab` in both and neither
         * matches the other — yet the lookbehind rule called it variable and refused it. The published
         * grammar permits `{n,m}`, so that was a refusal the document does not license.
         *
         * ⚠️ EQUAL BOUNDS, NOT A BOUNDED FORM. `{1,2}` really can match two lengths and stays refused;
         * the distinction is the two numbers being the same rather than the comma being present.
         */
        if (preg_match('/^\{([0-9]+)(?:,([0-9]+))?\}\??$/', $quantifier, $bound) !== 1) {
            return null;
        }

        $lower = (int) $bound[1];

        return ($bound[2] ?? '') === '' || (int) $bound[2] === $lower ? $lower : null;
    }

    /** Where the character class opening at `$at` closes, or null when it does not. */
    private static function classEndsAt(string $text, int $at): ?int
    {
        $length = mb_strlen($text);
        $i = $at + 1;

        // A `^` negates, and a `]` in first position is the literal character.
        if (mb_substr($text, $i, 1) === '^') {
            $i++;
        }

        if (mb_substr($text, $i, 1) === ']') {
            $i++;
        }

        for (; $i < $length; $i++) {
            $char = mb_substr($text, $i, 1);

            if ($char === '\\') {
                $i += self::escapeSpan($text, $i) - 1;

                continue;
            }

            if ($char === ']') {
                return $i;
            }
        }

        return null;
    }

    /** Where the group opening at `$at` closes, or null when it does not. */
    private static function groupEndsAt(string $text, int $at): ?int
    {
        $length = mb_strlen($text);
        $inClass = false;
        $depth = 0;

        for ($i = $at; $i < $length; $i++) {
            $char = mb_substr($text, $i, 1);

            if ($char === '\\') {
                $i += self::escapeSpan($text, $i) - 1;

                continue;
            }

            if ($inClass) {
                $inClass = $char !== ']';

                continue;
            }

            if ($char === '[') {
                $inClass = true;

                continue;
            }

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')' && --$depth === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Whether an escaped non-alphanumeric character is one ECMAScript escapes.
     *
     * ⚠️ `-` is portable INSIDE a character class and not outside one, which is
     * the same positional split the digit escapes have: ECMAScript allows `\-`
     * only as a ClassEscape, and PCRE takes it anywhere. Measured, not assumed.
     */
    /**
     * Whether this in-class escape sits at either end of a range, which ECMAScript refuses.
     *
     * ⚠️ ONLY THE ESCAPES THAT ARE SETS, because a range needs single characters at both ends.
     * `[\x41-\x5A]` is fine — those are characters. `\s` and friends are sets, and a set has no
     * position in a code point ordering.
     *
     * ⚠️ AND ONLY INSIDE A CLASS, since outside one there are no ranges and `-` is a literal.
     *
     * @return string|null the reason, or null when the escape is not a range endpoint
     */
    private static function rangeEndpointRefusal(string $pattern, int $at, string $escaped): ?string
    {
        // `$at` is the index of the escaped LETTER, the scanner having already stepped over the
        // backslash — so the character before the escape is two back.
        if (! isset(self::CLASS_SET_ESCAPES[$escaped])) {
            return null;
        }

        $before = mb_substr($pattern, $at - 2, 1);
        $after = mb_substr($pattern, $at + 1, 1);

        // A `-` immediately before the escape makes it the END of a range; one immediately after
        // makes it the START, unless that `-` is itself the class's closing literal.
        $isEnd = $before === '-';
        $isStart = $after === '-' && mb_substr($pattern, $at + 2, 1) !== ']';

        if (! $isEnd && ! $isStart) {
            return null;
        }

        return sprintf(
            'the set escape `\%s` used as a range endpoint — as in `%s`. A range needs a single '
            .'character at each end, and `\%s` is a SET; ECMAScript under `u` refuses to compile it '
            .'and PCRE accepts it, so the published pattern does not compile for a consumer at all. '
            .'Name the endpoints as characters, or put the set beside the range rather than inside it',
            $escaped,
            self::excerpt($pattern, max(0, $at - 3), min(mb_strlen($pattern) - 1, $at + 2)),
            $escaped,
        );
    }

    private static function punctuationRefusal(string $escaped, bool $inClass): ?string
    {
        if ($escaped === '' || preg_match('/^[A-Za-z0-9]$/', $escaped) === 1) {
            return null;
        }

        if (isset(self::PORTABLE_ESCAPED_PUNCTUATION[$escaped]) || ($inClass && $escaped === '-')) {
            return null;
        }

        return sprintf(
            'the escape `\%s` — ECMAScript escapes only its syntax characters '
            .'(^ $ \ . * + ? ( ) [ ] { } |), a forward slash, and `-` inside a character class. '
            .'PCRE takes the backslash on anything and reads the character literally, so write '
            .'`%s` on its own',
            $escaped,
            $escaped,
        );
    }

    /**
     * Whether an escape whose LETTER is shared is written in a form both dialects
     * accept.
     *
     * ⚠️ These cannot go in the tables above, because the letter alone does not
     * decide it — the form does, and for the digits the CONTEXT does as well.
     * Measured on PHP 8.4.25/PCRE 10.48 and Node v22.23.2:
     *
     *   `\x41`     both        `\x{41}` and `\x4`   PCRE only
     *   `\cA`      both        `\c1` and `\c!`      PCRE only
     *   `\k<n>`    both        `\k{n}` and `\k'n'`  PCRE only
     *   `\0`       both        `\00` and `\101`     PCRE only
     *   `(a)(b)\2` both        `[\1]`               PCRE only
     *
     * The last row is the reason `$inClass` is a parameter. A single-digit escape
     * outside a class is an ordinary backreference in both dialects; INSIDE one,
     * PCRE reads it as an octal character while ECMAScript rejects it outright —
     * so the same two characters are portable in one place and not in the other.
     * `\0` is NUL in both, everywhere.
     *
     * ⚠️ `$spans` is PASSED IN rather than computed here: this method runs once per
     * escape, and recomputing them made the screen quadratic in the number of groups.
     * See `unpublishable()`.
     *
     * @param  array<int, array{open: int, close: int|null, optionalAncestors: list<array{open: int, close: int|null}>, alternatingAncestors: list<array{open: int, close: int|null, separators: list<int>, captureBranch: int}>, name: string|null}>  $spans
     */
    private static function escapeFormRefusal(string $pattern, int $at, string $escaped, bool $inClass, array $spans): ?string
    {
        $next = mb_substr($pattern, $at + 1, 1);

        // ECMAScript's hex escape is exactly two digits. PCRE also takes one
        // (`\xA`) and a braced code point (`\x{1F600}`), and rejects neither.
        if ($escaped === 'x' && preg_match('/^[0-9A-Fa-f]{2}/', mb_substr($pattern, $at + 1, 2)) !== 1) {
            return $next === '{'
                ? '\x{...} — PCRE\'s braced hex escape; ECMAScript spells a code point \u{...}, which '
                    .'PCRE in turn rejects. For a value below 256 use the two-digit form, e.g. \x61'
                : '\x followed by fewer than two hex digits — ECMAScript requires exactly two, as in \x0A';
        }

        // ⚠️ ECMAScript's control escape is `\c` plus an ASCII LETTER. PCRE also
        // takes a digit and any punctuation, reading them by its own rules, and
        // rejects neither — so `\c1` and `\c!` compiled here and were published to
        // a consumer that cannot parse them. Both dialects agree on `\cA`, in a
        // character class as well as outside one, so only the suffix is refused.
        if ($escaped === 'c' && preg_match('/^[A-Za-z]$/', $next) !== 1) {
            return sprintf(
                '`\c%s` — ECMAScript\'s control escape is \c followed by an ASCII letter, as in \cA. '
                .'PCRE reads a digit or punctuation there by its own rules',
                $next,
            );
        }

        // ⚠️ A NAMED reference goes through the participation analysis too. It is a
        // backreference, and the first version of that analysis was reached only from
        // the digit branch — so `^(?<n>a)?\k<n>$` published a pattern the two engines
        // enforce differently.
        if ($escaped === 'k' && ! $inClass && $next === '<') {
            $closes = mb_strpos($pattern, '>', $at);

            if ($closes !== false) {
                $name = mb_substr($pattern, $at + 2, $closes - $at - 2);
                foreach ($spans as $index => $span) {
                    if ($span['name'] === $name) {
                        return self::participationRefusal($spans, $index, '\k<'.$name.'>', $at);
                    }
                }
            }
        }

        // ECMAScript has only `\k<name>`, and only outside a character class.
        if ($escaped === 'k' && ($inClass || $next !== '<')) {
            return $inClass
                ? '\k inside a character class — a backreference cannot appear in a class in '
                    .'ECMAScript, and PCRE reads the letter k there instead'
                : '\k'.$next.' — ECMAScript spells a named backreference \k<name>; the braced and '
                    .'quoted forms are PCRE\'s';
        }

        if (preg_match('/^[0-9]$/', $escaped) !== 1) {
            return null;
        }

        // ⚠️ `\0` is NUL in both dialects, in a class and out of one — but only
        // ALONE. `\00` is octal to PCRE and rejected by ECMAScript, and exempting
        // the whole digit on the strength of the single-character case left that
        // as the one hole the re-sweep still found.
        if ($escaped === '0') {
            return preg_match('/^[0-9]$/', $next) === 1
                ? '\00 — a multi-digit escape is octal to PCRE and rejected by ECMAScript. \0 alone '
                    .'is NUL in both; for any other character use the hex form, e.g. \x01'
                : null;
        }

        if ($inClass) {
            return sprintf(
                '\%s inside a character class — PCRE reads an octal character there and ECMAScript '
                .'rejects it. For the character itself use the hex form, e.g. \x01',
                $escaped,
            );
        }

        // ⚠️ A multi-digit escape is a REAL BACKREFERENCE when the groups exist, and
        // refusing it unconditionally was a false refusal I argued for on purpose.
        //
        // My earlier reply said an author wanting a backreference beyond 9 should
        // "restructure the pattern to use fewer groups" — treating the capability loss
        // as acceptable. Measured, it is not acceptable, because the dialects AGREE
        // here:
        //
        //   ten groups then `\10`      PCRE and ECMAScript both accept
        //   eleven groups then `\11`   both accept
        //   five groups then `\10`     PCRE reads octal, ECMAScript rejects
        //   ten groups then `\11`      PCRE reads octal, ECMAScript rejects
        //   ten groups then `\101`     PCRE reads octal, ECMAScript rejects
        //
        // The line is exactly whether the number names a group that exists: at or
        // below the count both read a backreference, above it PCRE falls back to octal
        // and ECMAScript errors. So the count decides, not the digit count.
        //
        // This is the failure mode the allowlists were adopted to avoid — "a false
        // refusal blocks an author" — and I introduced one anyway, in writing.
        $digits = (string) (preg_match('/^[0-9]+/', mb_substr($pattern, $at), $matched) === 1 ? $matched[0] : '');
        $reference = (int) $digits;
        // Above the group count, PCRE falls back to octal and ECMAScript rejects it.
        if (mb_strlen($digits) > 1 && $reference > count($spans)) {
            return sprintf(
                '\%s — there are only %d capturing groups, so PCRE reads this as an octal character '
                .'while ECMAScript rejects it as a backreference to a group that does not exist. For '
                .'a character use the hex form, e.g. \x41',
                $digits,
                count($spans),
            );
        }

        return self::participationRefusal($spans, $reference, '\\'.$digits, $at);
    }

    /**
     * Whether a reference to group `$index` names something that must participate.
     *
     * ⚠️ Shared by the numeric and NAMED reference paths, because `\k<n>` is a
     * backreference and diverges identically — `^(?<n>a)?\k<n>$` fails in PCRE and
     * matches in ECMAScript, exactly as `^(a)?\1$` does. The first version of this
     * analysis was reached only from the digit branch, so every named reference walked
     * past it.
     *
     * @param  array<int, array{open: int, close: int|null, optionalAncestors: list<array{open: int, close: int|null}>, alternatingAncestors: list<array{open: int, close: int|null, separators: list<int>, captureBranch: int}>, name: string|null}>  $spans
     */
    private static function participationRefusal(array $spans, int $index, string $written, int $at): ?string
    {
        if (! isset($spans[$index])) {
            return null;
        }

        // ⚠️ EXISTING IS NOT ENOUGH, and treating it as enough was my mistake.
        //
        // I allowed a forward reference last round because `\1(a)` COMPILES in both
        // dialects — and this file settled long ago that compiling is not the test,
        // enforcing the same constraint is. Measured, whenever the referenced group has
        // not participated the two disagree completely: PCRE fails the match, and
        // ECMAScript treats the reference as an empty string.
        //
        //   `^\1(a)?$`  on ''    PCRE fails, ECMAScript matches
        //   `^\1(a)$`   on 'a'   PCRE fails, ECMAScript matches
        //   `^(a)?\1$`  on ''    PCRE fails, ECMAScript matches
        //   `^(a)*\1$`  on ''    PCRE fails, ECMAScript matches
        //   `^(a)\1$`   on 'aa'  both match — participation is what matters
        //
        // So a backreference is portable exactly when its group MUST participate, and
        // the two cases provable by scanning are refused here.
        if ($spans[$index]['open'] > $at) {
            return sprintf(
                '%s — a forward reference. Group %d opens after it, so it has not participated when '
                .'the reference is tried: PCRE fails the match and ECMAScript treats it as an empty '
                .'string. Define the group before referring to it',
                $written,
                $index,
            );
        }

        // ⚠️ OPENING BEFORE THE REFERENCE IS NOT THE SAME AS HAVING PARTICIPATED, and
        // `open` alone read as though it were. A group participates when it CLOSES, so a
        // reference sitting inside the group it names is unset in PCRE and empty in
        // ECMAScript, exactly like a forward reference:
        //
        //   `^(a\1)$`         on 'a'    PCRE fails, ECMAScript matches
        //   `^((a\1))$`       on 'a'    PCRE fails, ECMAScript matches
        //   `^(?<n>a\k<n>)$`  on 'a'    PCRE fails, ECMAScript matches
        //   `^(a\1)+$`        on 'aa'   PCRE fails, ECMAScript matches
        //
        // The repeated case is the one worth stating: PCRE does not reset captures
        // between iterations, so a second iteration could plausibly see group 1 set from
        // the first — measured, it does not, and ECMAScript resets them anyway. So there
        // is no shape where an enclosed reference agrees.
        //
        // These still agree and must NOT be refused, which is what `close` buys over
        // "refuse any reference to an enclosing group": `^((a)\2)$` on 'aa',
        // `^(a(b))\2$` on 'abb'. Both references sit AFTER their group's close, inside
        // an outer group that has not closed — the reference's position against its own
        // group's close is the test, not nesting.
        if ($spans[$index]['close'] === null || $spans[$index]['close'] > $at) {
            return sprintf(
                '%s — the reference sits inside group %d, which has not closed yet, so the group has '
                .'not participated when the reference is tried: PCRE fails the match and ECMAScript '
                .'treats it as an empty string. Move the reference after the group closes',
                $written,
                $index,
            );
        }

        // ⚠️ AN OPTIONAL ANCESTOR ONLY COUNTS IF IT CAN BE SKIPPED WITHOUT SKIPPING THE
        // REFERENCE, and collapsing this to one boolean was a false refusal.
        //
        // `^(?:(a)\1)?$` puts the reference INSIDE the optional group. Skipping the group
        // skips the reference too, so whenever the reference executes the capture is set
        // — measured, both engines match '' and 'aa' and reject 'a'. It was refused
        // anyway, which is the third false refusal this screen has produced and the
        // reason `capturingGroupSpans()` now records spans instead of a flag.
        //
        // The distinction is entirely about position:
        //
        //   `^(?:(a)\1)?$`   reference INSIDE  the optional group   both agree, allowed
        //   `^(?!(a)\1)b$`   reference INSIDE  the assertion        both agree, allowed
        //   `^(?:(a))?\1$`   reference OUTSIDE the optional group   diverges, refused
        //   `^(?!(a))\1$`    reference OUTSIDE the assertion        diverges, refused
        // ⚠️ ALTERNATION, which needs no quantifier and was a recorded residual until
        // now. A capture inside one branch is unset whenever another branch is taken, so
        // the reference must sit in the SAME branch of every alternating ancestor:
        //
        //   ^(?:(a)|b\1)?$    on 'b'    PCRE no match, ECMAScript match   refused
        //   ^(?:(a)|b)\1$     on 'b'    PCRE no match, ECMAScript match   refused
        //   ^((a)|b)\2$       on 'b'    PCRE no match, ECMAScript match   refused
        //   ^(a)|b\1$         on 'b'    PCRE no match, ECMAScript match   refused
        //
        //   ^(?:(a)\1|b)?$    same branch                    both agree   allowed
        //   ^((a)|b)\1$       names the group AROUND the |    both agree   allowed
        //   ^(a)\1|b$         same branch at top level       both agree   allowed
        //
        // The last two are why this is not "refuse anything with a `|`". `^((a)|b)\1$`
        // refers to the group that CONTAINS the alternation, and entering that group
        // always captures it — which is why the walk skips the capture's own frame.
        foreach ($spans[$index]['alternatingAncestors'] as $ancestor) {
            if (self::spanEncloses($ancestor, $at)
                && self::branchAt($ancestor['separators'], $at) === $ancestor['captureBranch']) {
                continue;
            }

            return sprintf(
                '%s — group %d sits in one branch of an alternation that the reference does not '
                .'share, so the branch taken can leave it unset: PCRE then fails the match while '
                .'ECMAScript treats the reference as an empty string. Repeat the reference inside '
                .'the same branch, or match the alternatives as separate patterns',
                $written,
                $index,
            );
        }

        foreach ($spans[$index]['optionalAncestors'] as $ancestor) {
            if (self::spanEncloses($ancestor, $at)) {
                continue;
            }

            return sprintf(
                '%s — group %d can go unset, through its own quantifier or an enclosing one: PCRE '
                .'then fails the match while ECMAScript treats the reference as an empty string. Make '
                .'it required, or match the alternatives separately',
                $written,
                $index,
            );
        }

        return null;
    }

    /**
     * Which alternation branch of a frame a position sits in.
     *
     * Just how many of the frame's own top-level separators precede it, so two positions
     * are in the same branch exactly when this returns the same number for both.
     *
     * @param  list<int>  $separators
     */
    private static function branchAt(array $separators, int $position): int
    {
        $branch = 0;

        foreach ($separators as $separator) {
            if ($separator < $position) {
                $branch++;
            }
        }

        return $branch;
    }

    /**
     * Whether `$at` falls inside the span, so skipping the span skips `$at` too.
     *
     * A null close means the group never closes, so everything after its opening is
     * inside it — the pattern does not compile, and `patternRule()` refuses it anyway.
     *
     * @param  array{open: int, close: int|null}  $span
     */
    private static function spanEncloses(array $span, int $at): bool
    {
        return $span['open'] < $at && ($span['close'] === null || $at < $span['close']);
    }

    /**
     * Whether the group opening at `$at` is a NAMED capture.
     *
     * ⚠️ ONE implementation for the two callers, because they were two copies of the
     * same rule and both carried the same bug. `groupRefusal()` used it to tell
     * `(?<name>` from `(?<=`, and `capturingGroups()` to decide what counts toward the
     * total — so an ASCII-only test refused `(?<é>x)` in one place and undercounted it
     * in the other, which is two symptoms of one mistake.
     *
     * ⚠️ UNICODE, measured on both engines rather than assumed. `(?<é>x)`, `(?<日本>x)`,
     * `(?<ключ>x)`, `(?<𝔞>x)`, `(?<ᚠ>x)` and `(?<µ>x)` all compile AND match
     * identically under PHP 8.4.25/PCRE 10.48 and Node v22.23.2. My `[A-Za-z_$]` check
     * refused every one of them.
     *
     * A letter or underscore, and not `=` or `!` which open a lookbehind. Nothing
     * narrower is needed: measured across 23 candidate names, NO name is
     * PCRE-accepted-and-ECMAScript-rejected — `1a`, `a-b`, `a b` and `a.b` are refused
     * by both, and `$a`, a combining mark and a zero-width non-joiner are accepted by
     * ECMAScript and refused by PCRE, so `compiles()` answers first. There is no hole
     * here to screen, only a false refusal to stop making.
     */
    private static function opensNamedGroup(string $pattern, int $at): bool
    {
        return preg_match('/^\(\?<[\p{L}_]/u', mb_substr($pattern, $at, 4)) === 1;
    }

    /**
     * Every capturing group's opening position and whether it can go unset.
     *
     * ⚠️ Spans rather than a count, because a backreference's portability depends on
     * WHERE its group is and whether it must participate — not merely on whether it
     * exists.
     *
     * `(?<name>` counts and `(?:` does not (see `opensNamedGroup()`). Escapes and class
     * context are tracked, so `\(` is not a group and `[(]` is not one either.
     *
     * "Optional" is anything that lets the group match zero times: the quantifier after
     * its closing paren (`?`, `*`, or a zero-minimum brace bound, parsed numerically so
     * `{00}` counts), or the group being a NEGATIVE assertion — whose captures can never
     * participate when it succeeds, because it succeeds by its body not matching.
     *
     * ⚠️ The method this replaced said a forward reference "is legal in both dialects —
     * measured, `\1(a)` compiles in both". Compiling was the wrong test — the rule this
     * file settled for `\h` — because an unset backreference fails the match in PCRE and
     * matches empty in ECMAScript. `open` exists to catch that.
     *
     * ⚠️ ALTERNATION is the third way, and it needs no quantifier — nor even a group,
     * since `^(a)|b\1$` alternates at the top level. `separators` records each frame's own
     * top-level `|` positions, and a branch index is just how many of them precede a
     * position, so the capture and the reference must agree on it. This was a recorded
     * residual claiming the analysis was out of reach; it was the analysis as written that
     * was, not the problem.
     *
     * ⚠️ Optionality is INHERITED from enclosing frames, and every group gets a frame
     * whether it captures or not. Checking only a capture's own quantifier missed
     * `^((a))?\2$` and `^(?:(a))?\1$`, where the capture carries no quantifier and an
     * ancestor carries the one that matters — both diverge.
     *
     * The name is recorded so `\k<name>` can be put through the same analysis: a named
     * reference is a backreference, and `^(?<n>a)?\k<n>$` diverges exactly as `^(a)?\1$`
     * does.
     *
     * ⚠️ `close` is recorded as well as `open`, because a group has not participated
     * until it CLOSES. `open` alone answered "is this a forward reference" and said
     * nothing about a reference sitting inside the group it names — see
     * `participationRefusal()` for the measurements.
     *
     * @return array<int, array{open: int, close: int|null, optionalAncestors: list<array{open: int, close: int|null}>, alternatingAncestors: list<array{open: int, close: int|null, separators: list<int>, captureBranch: int}>, name: string|null}>
     *                                                                                                                                                                                                                                                keyed by 1-based ordinal
     */
    private static function capturingGroupSpans(string $pattern): array
    {
        $length = mb_strlen($pattern);
        $inClass = false;

        // ⚠️ A synthetic ROOT frame, because alternation does not need a group.
        // `^(a)|b\1$` puts the capture in one top-level branch and the reference in the
        // other, and it diverges exactly as the parenthesised forms do — with nothing to
        // hang the analysis on unless the whole pattern is itself a frame. Opening at -1
        // and closing past the end makes every position strictly inside it.
        $frames = [0 => ['parent' => null, 'open' => -1, 'optional' => false, 'separators' => []]];
        $closedAt = [0 => $length];
        $stack = [0];
        $groups = [];
        $ordinal = 0;

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($pattern, $i, 1);

            if ($char === '\\') {
                $i++;

                continue;
            }

            if ($inClass) {
                $inClass = $char !== ']';

                continue;
            }

            if ($char === '[') {
                $inClass = true;

                continue;
            }

            if ($char === '(') {
                // ⚠️ EVERY group gets a frame, capturing or not, because a
                // non-capturing one can be the thing that is optional:
                // `^(?:(a))?\1$` leaves group 1 unset and diverges.
                $id = count($frames);
                $frames[$id] = [
                    'parent' => $stack[count($stack) - 1],
                    'separators' => [],
                    // Recorded so an optional frame's SPAN is known, not just that it is
                    // optional: whether it encloses a given reference is what decides
                    // whether it can leave the capture unset.
                    'open' => $i,
                    // ⚠️ A NEGATIVE assertion's captures can never participate when it
                    // succeeds — the assertion succeeds precisely because its body did
                    // not match. `^(?!(a))\1$` fails in PCRE and matches in ECMAScript,
                    // so the frame starts optional rather than becoming so at its close.
                    //
                    // A POSITIVE assertion is different and must stay required:
                    // `^(?=(a))a\1$` matches in both, because the body did match.
                    'optional' => preg_match('/^\(\?(?:!|<!)/', mb_substr($pattern, $i, 4)) === 1,
                ];
                $stack[] = $id;

                if (mb_substr($pattern, $i + 1, 1) !== '?' || self::opensNamedGroup($pattern, $i)) {
                    $groups[++$ordinal] = [
                        'open' => $i,
                        'frame' => $id,
                        'name' => self::groupNameAt($pattern, $i),
                    ];
                }

                continue;
            }

            if ($char === '|') {
                // A top-level separator of whichever frame is currently innermost. The
                // branch a position sits in is just how many of these precede it.
                $frames[$stack[count($stack) - 1]]['separators'][] = $i;

                continue;
            }

            // count > 1, not "not empty": the root frame is always on the stack and an
            // unbalanced `)` must not pop it.
            if ($char !== ')' || count($stack) <= 1) {
                continue;
            }

            $id = array_pop($stack);
            $closedAt[$id] = $i;
            $after = mb_substr($pattern, $i + 1, 1);

            // ⚠️ `||`, not `=`. A negative assertion is already marked optional at its
            // opening, and overwriting that with the quantifier's answer would un-mark
            // every one that carries no quantifier — which is all of them.
            $frames[$id]['optional'] = $frames[$id]['optional']
                || $after === '?'
                || $after === '*'
                // ⚠️ The REST of the pattern, not a window. This read `, 16)`, and a
                // lower bound may be zero-padded to any width: at 15 digits the window
                // ended before the closing brace, the numeric parse failed to match, and
                // `^(a){000000000000000}\1$` was published as portable — PCRE refusing
                // the empty string where ECMAScript accepts it. A constant window cannot
                // bound a variable-length construct, which is the same mistake in a
                // different place as the 16-character slice it replaces.
                || self::allowsZeroRepetitions(mb_substr($pattern, $i + 1));
        }

        $spans = [];

        foreach ($groups as $index => $group) {
            // ⚠️ Optionality is INHERITED, and it cannot be reduced to a BOOLEAN here.
            //
            // This collected the enclosing frames into a single `optional` flag, which
            // catches `^((a))?\2$` and `^(?:(a))?\1$` — and refused `^(?:(a)\1)?$`,
            // which both engines accept. An optional ancestor only leaves the capture
            // unset if it can be skipped while execution still REACHES the reference;
            // when the reference is inside that same ancestor, skipping it skips the
            // reference too. Deciding that needs the reference's position, which belongs
            // to the caller and not to this scan, so the SPANS are recorded and
            // `participationRefusal()` does the comparison.
            $optionalAncestors = [];

            for ($frame = $group['frame']; $frame !== null; $frame = $frames[$frame]['parent']) {
                if ($frames[$frame]['optional']) {
                    $optionalAncestors[] = [
                        'open' => $frames[$frame]['open'],
                        'close' => $closedAt[$frame] ?? null,
                    ];
                }
            }

            // ⚠️ ALTERNATION IS THE OTHER WAY A CAPTURE GOES UNSET, and it needs no
            // quantifier at all. Walked over STRICT ancestors — the capture's own frame
            // is excluded on purpose, because entering a group always captures it
            // whatever its internal branch does: `^((a)|b)\1$` on 'bb' matches in both
            // and refusing it would be a false refusal, while `^((a)|b)\2$` on 'b'
            // diverges because group 2 lives inside one branch.
            $alternatingAncestors = [];

            for ($frame = $frames[$group['frame']]['parent']; $frame !== null; $frame = $frames[$frame]['parent']) {
                if ($frames[$frame]['separators'] === []) {
                    continue;
                }

                $alternatingAncestors[] = [
                    'open' => $frames[$frame]['open'],
                    'close' => $closedAt[$frame] ?? null,
                    'separators' => $frames[$frame]['separators'],
                    'captureBranch' => self::branchAt($frames[$frame]['separators'], $group['open']),
                ];
            }

            $spans[$index] = [
                'open' => $group['open'],
                // Null when the group never closes, which means the pattern does not
                // compile — `patternRule()` refuses those outright. Treated as "has not
                // closed" below, which is the fail-closed reading.
                'close' => $closedAt[$group['frame']] ?? null,
                'optionalAncestors' => $optionalAncestors,
                'alternatingAncestors' => $alternatingAncestors,
                'name' => $group['name'],
            ];
        }

        return $spans;
    }

    /**
     * Whether a brace quantifier at the start of `$text` permits zero repetitions.
     *
     * ⚠️ Parsed NUMERICALLY, because the lower bound may be zero-padded. The first
     * version tested `/^\{0[,}]/`, which sees the zero in `{0}` and `{0,2}` and misses
     * it in `{00}` and `{00,2}` — both of which the engines accept and disagree about,
     * `^(a){00}\1$` failing in PCRE and matching in ECMAScript.
     *
     * `{01}` and `{1,2}` are NOT zero-minimum and must stay required, which a
     * character test cannot express and a numeric one states directly.
     *
     * ⚠️ `$text` must run to the END of the pattern, not a fixed window. The padding has
     * no width limit, so any constant slice can end mid-bound, and a bound that does not
     * match reads as "not zero-minimum" — a silent publish rather than a refusal. The
     * anchored `^` means the extra text costs nothing.
     *
     * The zero test survives arbitrary width: a string of only zeros casts to 0 however
     * long it is, and one with any non-zero digit casts to something non-zero — a value
     * past `PHP_INT_MAX` saturates rather than wrapping to 0.
     */
    private static function allowsZeroRepetitions(string $text): bool
    {
        return preg_match('/^\{([0-9]+)[,}]/', $text, $bound) === 1 && (int) $bound[1] === 0;
    }

    /** The name of the group opening at `$at`, or null when it is unnamed. */
    private static function groupNameAt(string $pattern, int $at): ?string
    {
        if (! self::opensNamedGroup($pattern, $at)) {
            return null;
        }

        $closes = mb_strpos($pattern, '>', $at);

        return $closes === false ? null : mb_substr($pattern, $at + 3, $closes - $at - 3);
    }

    /**
     * Whether the property named just past `\p` at `$at` is portable.
     *
     * ⚠️ ALLOWLISTED, for the reason the group prefixes are: the divergences here
     * are not a list of known offenders, they are most of the surface. Measured
     * against PHP 8.4.25/PCRE 10.48 and Node v22.23.2, PCRE compiles and
     * ECMAScript rejects ALL of these:
     *
     *   \pL              the braceless form ECMAScript has no parse for at all
     *   \p{Arabic}       a bare script name — ECMAScript needs Script=Arabic
     *   \p{Latn}         a script code, likewise
     *   \p{Xan}          five PCRE inventions (Xan Xps Xsp Xuc Xwd) plus L&
     *   \p{bc=AL}        a property class ECMAScript does not have
     *   \p{^L}           PCRE's internal negation — ECMAScript spells it \P{L}
     *   \p{lu}           PCRE matches names loosely; ECMAScript is exact
     *   \p{Script=latin} the same laxity in the value
     *   \p{Script = Latin}, \p{Old-Italic}   loose matching ignores space and dash
     *
     * A denylist was written first and was wrong in the way that matters: it named
     * the six inventions and let `\p{Arabic}`, `\p{Han}` and `\p{Hebrew}` through
     * — the bare script names, which are exactly what an author reaching for a
     * Unicode property in a product that ships RTL from the start (ADR-018) would
     * write. Enumerating ~170 script names to refuse them would go stale on every
     * Unicode release; enumerating what is PORTABLE goes stale in the direction
     * that refuses rather than the direction that publishes.
     *
     * ⚠️ A residual gap, stated rather than hidden: an alternating spelling like
     * `Script=LaTiN` still passes, because establishing that `Latin` is the
     * canonical casing needs the UCD's own tables and PCRE matches names
     * case-insensitively, so it cannot be asked. Every realistic mistake is
     * caught — `latin`, `arabic`, `ascii`, `LATIN`, `Old-Italic` — and what
     * remains is input no author produces by accident.
     */
    private static function propertyRefusal(string $pattern, int $at, string $letter): ?string
    {
        // ⚠️ `\pL` is not shorthand ECMAScript shares: it throws `Invalid
        // property name`, because the braces are required there.
        if (mb_substr($pattern, $at + 1, 1) !== '{') {
            return sprintf(
                '`\%s` without braces — ECMAScript requires `\%s{...}`, so write `\%s{L}` rather than `\%sL`',
                $letter, $letter, $letter, $letter,
            );
        }

        $closes = mb_strpos($pattern, '}', $at);

        if ($closes === false) {
            // Unterminated, so PCRE will not compile it either. Reporting it here
            // would mask the clearer "cannot be compiled" refusal.
            return null;
        }

        $name = mb_substr($pattern, $at + 2, $closes - $at - 2);

        // ⚠️ Named before the shape check, which would otherwise report a caret as
        // a casing mistake. `\p{^L}` is PCRE's internal negation and ECMAScript
        // spells it with the other letter, so this one has an exact translation.
        if (str_starts_with($name, '^')) {
            return sprintf(
                'the negated property `\%s{%s}` — ECMAScript has no internal negation. Write it as '
                .'`\%s{%s}`',
                $letter, $name, $letter === 'p' ? 'P' : 'p', mb_substr($name, 1),
            );
        }

        if (str_contains($name, '=')) {
            return self::prefixedPropertyRefusal($name);
        }

        if (in_array($name, self::PORTABLE_CATEGORIES, true) || in_array($name, self::PORTABLE_PROPERTIES, true)) {
            return null;
        }

        // ⚠️ Separated from the casing check below so each refusal names its own
        // defect. `L&` is a PCRE construct, not a mis-spelled name, and telling
        // its author about letter case would send them looking in the wrong place.
        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            return sprintf(
                'the property name `%s` in `\%s{%s}` — an ECMAScript property name is letters, digits '
                .'and underscores. PCRE has forms of its own, `L&` and `Xan` among them, which the '
                .'published schema cannot carry',
                $name, $letter, $name,
            );
        }

        // Checked BEFORE the script probe so the message names the actual defect:
        // `\p{arabic}` is a casing mistake, and telling the author to write
        // `Script=arabic` would hand them a second pattern that does not travel.
        if (! self::canonicalShape($name)) {
            return sprintf(
                'the property name `%s` in `\%s{%s}` — PCRE matches names loosely, ignoring case, '
                .'spaces and dashes, while ECMAScript requires the exact Unicode spelling',
                $name, $letter, $name,
            );
        }

        // PCRE can be ASKED whether a name is a script, which saves enumerating
        // every script: if the prefixed form compiles, the bare form was a script
        // and the prefixed form is the portable spelling to point the author at.
        //
        // ⚠️ The name is interpolated into a pattern, so its shape is established
        // first. `canonicalShape()` admits only `[A-Za-z0-9_]`, which cannot close
        // the probe's delimiter or add a construct to it — a pattern setting comes
        // from an authenticated author, but it arrives over HTTP, and invariant 6
        // makes no exception for that.
        if (@preg_match('/\p{Script='.$name.'}/u', '') !== false) {
            return sprintf(
                'the bare script name `\%s{%s}` — ECMAScript accepts a script only in its prefixed '
                .'form. Use `\%s{Script=%s}`',
                $letter, $name, $letter, $name,
            );
        }

        return sprintf(
            'the Unicode property `\%s{%s}` — ECMAScript matches property names exactly and does not '
            .'have this one. It accepts the General_Category short forms (L, Lu, Nd, …), the binary '
            .'properties spelled as Unicode spells them (Alphabetic, White_Space, …), and Script=',
            $letter, $name,
        );
    }

    /** Whether a `prefix=value` property names something both dialects have. */
    private static function prefixedPropertyRefusal(string $name): ?string
    {
        [$prefix, $value] = explode('=', $name, 2);

        if (! in_array($prefix, self::PORTABLE_PROPERTY_PREFIXES, true)) {
            // ⚠️ A portable prefix spelled loosely is a different fault from one
            // ECMAScript does not have, and `\p{Script = Latin}` is the first
            // kind: PCRE ignores the spaces and the case, ECMAScript ignores
            // neither. Reported as the spelling problem it is.
            foreach (self::PORTABLE_PROPERTY_PREFIXES as $portable) {
                if (strcasecmp(trim($prefix), $portable) === 0) {
                    return sprintf(
                        'the property prefix `%s` in `\p{%s}` — PCRE matches prefixes loosely, '
                        .'ignoring case and spaces, while ECMAScript requires exactly `%s=`',
                        $prefix, $name, $portable,
                    );
                }
            }

            return sprintf(
                'the property `\p{%s}` — ECMAScript has only Script=, sc=, Script_Extensions= and '
                .'scx=, and matches the prefix case-sensitively',
                $name,
            );
        }

        if (! self::canonicalShape($value)) {
            return sprintf(
                'the property value `%s` in `\p{%s}` — PCRE matches values loosely, ignoring case, '
                .'spaces and dashes, while ECMAScript requires the exact Unicode spelling. Write it '
                .'as Unicode writes it, e.g. `Script=Old_Italic`',
                $value, $name,
            );
        }

        return null;
    }

    /**
     * Whether a value is spelled the way Unicode spells its own values.
     *
     * Title case, underscore-separated, letters and digits only, and no two
     * capitals in a row. Every name that reaches here is a SCRIPT value — the
     * allowlists have already answered for the categories and binary properties,
     * and `Script=`/`scx=` take nothing else — so the no-consecutive-capitals rule
     * holds: script names are Title_Case words and script codes are `Xxxx`.
     *
     * ⚠️ MEASURED against the strict engine rather than reasoned about: all 57
     * script names and codes tried, `SignWriting`, `Nyiakeng_Puachue_Hmong`,
     * `Khitan_Small_Script`, `Zyyy` and `Cpmn` among them, are accepted by both
     * this test and Node v22.23.2. It rejects `latin`, `LATIN`, `Old-Italic` and
     * `Old Italic` — the forms PCRE's loose matching takes and ECMAScript does not.
     *
     * The capitals rule is what keeps the refusal message honest as well: without
     * it, `\p{LATIN}` was answered with "use `\p{Script=LATIN}`", which does not
     * compile either.
     */
    private static function canonicalShape(string $value): bool
    {
        return preg_match('/^(?![A-Za-z0-9_]*[A-Z]{2})[A-Z][A-Za-z0-9]*(?:_[A-Z][A-Za-z0-9]*)*$/', $value) === 1;
    }

    /**
     * Whether the group opening at `$at` is one ECMAScript has.
     *
     * The allowlist is `(?:` non-capturing, `(?=` and `(?!` lookahead, `(?<=`
     * and `(?<!` lookbehind, and `(?<name>` named capture. Everything else is
     * refused with the text that follows quoted back, so the author can see which
     * construct was rejected rather than being told "something".
     */
    private static function groupRefusal(string $pattern, int $at): ?string
    {
        $after = mb_substr($pattern, $at + 2, 1);

        if ($after === ':' || $after === '=' || $after === '!') {
            return null;
        }

        if ($after === '<') {
            $third = mb_substr($pattern, $at + 3, 1);

            // Lookbehind, or a named group. A name starts with a letter — of ANY
            // script — or an underscore, which is what separates `(?<name>` from
            // `(?<=`. See `opensNamedGroup()` for why ASCII was the wrong test.
            if ($third === '=' || $third === '!' || self::opensNamedGroup($pattern, $at)) {
                return null;
            }
        }

        // Quote the opening back, cut at its `)` so `(?i)^abc$` reads as `(?i)`
        // rather than trailing the pattern into the message.
        $construct = mb_substr($pattern, $at, 5);
        $closes = mb_strpos($construct, ')');

        return sprintf(
            'the group construct `%s` — ECMAScript has only (?:, (?=, (?!, (?<=, (?<! and (?<name>',
            $closes === false ? $construct : mb_substr($construct, 0, $closes + 1),
        );
    }

    /**
     * Whether this pattern can actually be compiled and used.
     *
     * ⚠️ `@preg_match` against the EMPTY string, which is the cheapest way to
     * ask PCRE to compile without caring what it matches. The `@` is load-
     * bearing: a malformed pattern raises a warning, and the point here is to
     * return false rather than to emit anything.
     */
    public static function compiles(string $pattern): bool
    {
        $delimited = self::delimit($pattern);

        return $delimited !== null && @preg_match($delimited, '') !== false;
    }
}
