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

    /** The pattern wrapped in a delimiter it does not itself contain, or null. */
    public static function delimit(string $pattern): ?string
    {
        foreach (self::DELIMITERS as $delimiter) {
            if (! str_contains($pattern, $delimiter)) {
                return $delimiter.$pattern.$delimiter.self::MODIFIERS;
            }
        }

        return null;
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
        'C', 'Cc', 'Cf', 'Cn', 'Co', 'Cs',
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
        'ASCII', 'ASCII_Hex_Digit', 'Alphabetic', 'Any', 'Bidi_Control', 'Bidi_Mirrored',
        'Case_Ignorable', 'Cased', 'Changes_When_Casefolded', 'Changes_When_Casemapped',
        'Changes_When_Lowercased', 'Changes_When_Titlecased', 'Changes_When_Uppercased',
        'Dash', 'Default_Ignorable_Code_Point', 'Deprecated', 'Diacritic', 'Emoji',
        'Emoji_Component', 'Emoji_Modifier', 'Emoji_Modifier_Base', 'Emoji_Presentation',
        'Extended_Pictographic', 'Extender', 'Grapheme_Base', 'Grapheme_Extend', 'Hex_Digit',
        'IDS_Binary_Operator', 'IDS_Trinary_Operator', 'ID_Continue', 'ID_Start', 'Ideographic',
        'Join_Control', 'Logical_Order_Exception', 'Lowercase', 'Math', 'Noncharacter_Code_Point',
        'Pattern_Syntax', 'Pattern_White_Space', 'Quotation_Mark', 'Radical', 'Regional_Indicator',
        'Sentence_Terminal', 'Soft_Dotted', 'Terminal_Punctuation', 'Unified_Ideograph', 'Uppercase',
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
        $inClass = false;

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

                // ⚠️ Three families where the LETTER is shared and the form is not,
                // so a lookup table cannot answer them.
                if (($reason = self::escapeFormRefusal($pattern, $i, $escaped, $inClass)) !== null) {
                    return $reason;
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
                $isQuantifier = $closes !== false
                    && preg_match('/^\{[0-9]+(,[0-9]*)?\}$/', mb_substr($pattern, $i, $closes - $i + 1)) === 1;

                if ($isQuantifier && mb_substr($pattern, $closes + 1, 1) === '+') {
                    return 'the possessive quantifier `}+` — ECMAScript has no possessive form';
                }

                continue;
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

        return null;
    }

    /**
     * Whether an escaped non-alphanumeric character is one ECMAScript escapes.
     *
     * ⚠️ `-` is portable INSIDE a character class and not outside one, which is
     * the same positional split the digit escapes have: ECMAScript allows `\-`
     * only as a ClassEscape, and PCRE takes it anywhere. Measured, not assumed.
     */
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
     */
    private static function escapeFormRefusal(string $pattern, int $at, string $escaped, bool $inClass): ?string
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

        // Outside a class a single digit is a backreference both dialects have.
        // More than one is octal to PCRE and a syntax error to ECMAScript.
        return preg_match('/^[0-9]$/', mb_substr($pattern, $at + 1, 1)) === 1
            ? sprintf(
                '\%s%s — a multi-digit escape is octal to PCRE and rejected by ECMAScript. For a '
                .'character use the hex form, e.g. \x41; for a backreference beyond 9, restructure '
                .'the pattern to use fewer groups',
                $escaped,
                $next,
            )
            : null;
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

            // Lookbehind, or a named group — a name starts with a letter or
            // underscore, which is what separates `(?<name>` from `(?<=`.
            if ($third === '=' || $third === '!' || preg_match('/^[A-Za-z_$]$/', $third) === 1) {
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
