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
 * ⚠️ `\p{Cn}` IS THE ONE CATEGORY THAT CANNOT BE PORTABLE EVEN IN PRINCIPLE, and it was on the
 * allowlist. It means *"not yet assigned"*, so its membership **shrinks** with every Unicode
 * release: a codepoint unassigned to one engine's tables is assigned in the other's the moment
 * their Unicode versions differ, and the two then enforce opposite rules on the same input.
 *
 * ⚠️ AND A MEASUREMENT COULD NOT HAVE FOUND THIS. Review demonstrated it on PCRE 10.44 with
 * Node 24 (U+10940); PCRE 10.48 with Node 22 — the pair the parity harness runs here — AGREES.
 * A harness sees the versions it has. That is why this exclusion is on principle, and why this
 * test asserts the refusal rather than a measured divergence.
 */
it('refuses a category whose membership inverts between Unicode versions', function (): void {
    expect(Pattern::unpublishable('^\p{Cn}$'))
        ->not->toBeNull('\p{Cn} is published as portable, and it cannot be');
});

it('still admits the categories that only ever grow', function (): void {
    /*
     * The other half, so the fix cannot quietly become "refuse all properties". Every other
     * category gains members with a Unicode release rather than inverting — a weaker version of
     * the same hazard, stated in `field-types.md` §3 rather than hidden, because refusing the
     * whole mechanism would cost far more than it buys.
     */
    foreach (['^\p{L}$', '^\p{Lu}$', '^\p{Cc}$', '^\p{Script=Arabic}$'] as $pattern) {
        expect(Pattern::unpublishable($pattern))
            ->toBeNull("[{$pattern}] should still be publishable");
    }
});

it('names the sibling C categories that stay portable', function (): void {
    // `Cn` left the list; `C`, `Cc`, `Cf`, `Co` and `Cs` did not. Asserted by name so removing
    // one by accident fails here rather than surfacing as a refused pattern much later.
    foreach (['C', 'Cc', 'Cf', 'Co', 'Cs'] as $category) {
        expect(Pattern::unpublishable('^\p{'.$category.'}$'))
            ->toBeNull("[\\p{{$category}}] should still be publishable");
    }
});
