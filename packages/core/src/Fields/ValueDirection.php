<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Fields;

/**
 * How a value's writing direction is decided when it is rendered.
 *
 * ⚠️ NOT A SETTING, AND NOT SOMETHING A FIELD TYPE CAN ANSWER. Nothing returns a
 * `ValueDirection`; it is derived from `Control` and `Cell`, which is the whole
 * mechanism. Issue #39 shipped `dir="auto"` three times and was short of complete
 * twice — once missing two of three table columns — every time because the reach
 * of a correct rule depended on somebody enumerating the places it applied.
 *
 * A field type that could set this would be a thirteenth chance to leave it out.
 * A field type that cannot mention it has no such chance, and a new `Control`
 * case cannot compile until its direction is decided, because PHPStan fails an
 * unhandled `match` (the same guarantee `LogicalType` gives the three drivers).
 */
enum ValueDirection: string
{
    /**
     * The browser decides per value, from its first strong directional character.
     *
     * For anything a person typed. `dir="auto"` rather than a computed direction
     * because the value's own content is the only reliable evidence: one org has
     * editors working in different languages (ADR-018 rule 2), so the field, the
     * site and the viewer's locale all fail to predict it.
     */
    case Auto = 'auto';

    /**
     * The value has no direction of its own to resolve.
     *
     * ⚠️ A REAL ANSWER, not "we did not think about it", and it is why this is a
     * three-case enum rather than a boolean. A date, a number and a toggle are
     * rendered from data whose glyphs the app chooses, so `dir="auto"` on them is
     * noise at best: `auto` on a bare numeral resolves to the surrounding
     * direction anyway, and on a formatted date it can key off a separator.
     *
     * Distinguishing "no direction needed" from "direction forgotten" is what
     * lets the renderer refuse the second.
     */
    case Neutral = 'neutral';

    /**
     * Each block inside the value carries its own direction.
     *
     * Rich text only. A single `dir` on the editor would force one direction on a
     * document that may legitimately hold an Arabic paragraph and an English one,
     * which is the case ADR-018 rule 2 exists for. `dir` is already in
     * `RichTextType::ALLOWED_ATTRIBUTES`, so per-block direction survives
     * sanitising — verified, `<p dir="rtl">…</p><p dir="ltr">…</p>` round-trips
     * while `style` and `onclick` do not.
     */
    case PerBlock = 'per-block';

    /**
     * Whether a renderer must put `dir="auto"` on the element holding the value.
     *
     * ⚠️ `PerBlock` answers FALSE, and that is the subtle one. Rich text needs
     * direction per block *inside* the value, so a single `dir="auto"` on the
     * wrapper would resolve once from the first block and impose it on the rest —
     * worse than nothing, because it looks handled.
     */
    public function needsAutoAttribute(): bool
    {
        return $this === self::Auto;
    }
}
