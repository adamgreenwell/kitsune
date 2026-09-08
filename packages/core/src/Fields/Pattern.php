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

    /** The pattern wrapped in a delimiter it does not itself contain, or null. */
    public static function delimit(string $pattern): ?string
    {
        foreach (self::DELIMITERS as $delimiter) {
            if (! str_contains($pattern, $delimiter)) {
                return $delimiter.$pattern.$delimiter.'u';
            }
        }

        return null;
    }

    /**
     * Escapes PCRE understands that the published dialect does not.
     *
     * ⚠️ `\v` and `\h` are deliberately absent. Both exist in ECMAScript with
     * DIFFERENT meanings (`\v` is a vertical tab, `\h` an identity escape for
     * the letter h) rather than being rejected, so refusing them would block a
     * pattern that compiles for every consumer. This screens what a consumer
     * cannot COMPILE, not everything that might mean something subtly different —
     * that set is unbounded, and the docblock below says so rather than pretending
     * otherwise.
     *
     * @var array<string, string>
     */
    private const PCRE_ONLY_ESCAPES = [
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
     * `\A` inside `[...]` is a literal A in PCRE, so screening it there would
     * refuse a valid class — which is why class context is skipped for those. But
     * `\h` inside a class is STILL horizontal whitespace in PCRE while ECMAScript
     * still reads the letter h, so `[\h]+` published a materially different
     * constraint and the class exemption let it through. The difference is whether
     * the escape means anything inside a class at all.
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
    ];

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
     * consumer cannot compile, not every construct whose MEANING differs.
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

                // Inside a class these are literals, not anchors.
                if (! $inClass && isset(self::PCRE_ONLY_ESCAPES[$escaped])) {
                    return self::PCRE_ONLY_ESCAPES[$escaped];
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
