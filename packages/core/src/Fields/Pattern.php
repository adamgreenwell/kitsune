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
     * Constructs PCRE has that ECMAScript does not, and why each matters.
     *
     * ⚠️ Needed because the pattern is PUBLISHED as well as enforced.
     * `TextType::scalarApiSchema()` emits it verbatim as a JSON Schema
     * `pattern`, and JSON Schema's dialect is ECMAScript — so a PCRE-only
     * expression compiles here, enforces correctly server-side, and is invalid
     * for a generated client. Invariant 14 says publish the constraint; a
     * constraint the consumer cannot compile is not published, it is just
     * advertised.
     *
     * ⚠️ A CONSERVATIVE heuristic, and it does not claim otherwise. Verifying
     * ECMAScript compatibility properly needs an ECMAScript engine, which this
     * project does not have and will not add for a settings check. So this
     * catches the unambiguous markers and says so in the refusal.
     *
     * Possessive quantifiers (`a++`) are deliberately NOT here: distinguishing
     * them from an escaped literal followed by a quantifier (`\++`) needs a real
     * parse, and a false refusal of a valid pattern is a worse trade than a rare
     * miss on a construct almost nobody writes by hand.
     *
     * @var array<string, string>
     */
    private const PCRE_ONLY = [
        '(?P<' => 'PHP-style named groups — ECMAScript spells them (?<name>...)',
        '(?P=' => 'PHP-style named backreferences',
        '(?>' => 'atomic groups',
        '(?(' => 'conditional subpatterns',
        '(?#' => 'inline comments',
        '(?R' => 'recursion',
        '\\A' => 'the \\A anchor — ECMAScript has ^',
        '\\z' => 'the \\z anchor — ECMAScript has $',
        '\\Z' => 'the \\Z anchor',
        '\\K' => '\\K',
        '\\Q' => '\\Q...\\E literal quoting',
        '[[:' => 'POSIX character classes',
    ];

    /**
     * The first construct that will not travel to a JSON Schema consumer, or
     * null when none is found.
     */
    public static function unpublishable(string $pattern): ?string
    {
        foreach (self::PCRE_ONLY as $marker => $reason) {
            if (str_contains($pattern, $marker)) {
                return $reason;
            }
        }

        return null;
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
