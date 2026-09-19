<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Fields;

/**
 * What KIND of cell lists a value — a kind, never a Filament column class.
 *
 * ⚠️ Derived from `Control`, not declared by a field type. `Control::cell()` maps
 * one to the other, so a type answers a single question and the list follows. Two
 * independent answers would be two things to keep consistent, and the second would
 * be the one nobody updated.
 *
 * A closed set for the reason `LogicalType` is closed: `Kitsune\Core\Filament`
 * matches on it exhaustively, so adding a case is a compile-time obligation rather
 * than a runtime surprise (ADR-029).
 */
enum Cell: string
{
    /** Free text the user wrote. */
    case Text = 'text';

    /** A number, aligned and formatted by the app rather than by the author. */
    case Numeric = 'numeric';

    /** A yes/no, rendered as an icon rather than the words true and false. */
    case Boolean = 'boolean';

    /**
     * An instant — a date and a time — formatted in the current site's timezone.
     *
     * Stored UTC; the conversion is made where it is shown (`SiteTime::column()`).
     */
    case Timestamp = 'timestamp';

    /**
     * A calendar date, formatted as the date it is and in no timezone.
     *
     * ⚠️ NOT A `Timestamp`, and it was one. A date is not an instant: `2026-09-18` read as UTC midnight and shown in
     * America/New_York is September 17th. Sharing the instant's cell is what would have moved it the moment the
     * cell learned the site's timezone, so the two kinds are separate cases and only one of them converts.
     */
    case Date = 'date';

    /**
     * A short constrained value, rendered as a badge.
     *
     * ⚠️ Still `Auto` direction. A badge holds an option LABEL, and a label is text
     * somebody wrote in some language — `status` may be `مسودة` as legitimately as
     * `draft`. Treating a badge as chrome because it looks like chrome is how a
     * translated label ends up laid out backwards.
     */
    case Badge = 'badge';

    /**
     * Not listed at all.
     *
     * For values with no useful one-line form — a rich-text document, a JSON blob.
     * A cell that truncates a document to forty characters is a column that costs a
     * query and tells the reader nothing.
     */
    case None = 'none';

    /**
     * How this cell's direction is decided.
     *
     * ⚠️ EXHAUSTIVE `match` WITH NO DEFAULT, on purpose. A `default` arm would let a
     * new case inherit some other case's answer silently, which is precisely the
     * "correct rule, incomplete reach" failure this vocabulary exists to prevent.
     * PHPStan fails an unhandled case, so the compiler asks the question.
     */
    public function direction(): ValueDirection
    {
        return match ($this) {
            self::Text, self::Badge => ValueDirection::Auto,
            self::Numeric, self::Boolean, self::Timestamp, self::Date => ValueDirection::Neutral,
            // ⚠️ Neutral rather than PerBlock: `None` renders nothing, so there is no
            // element to carry a direction. PerBlock would imply a wrapper exists.
            self::None => ValueDirection::Neutral,
        };
    }

    /** Whether this kind appears in a list at all. */
    public function isListed(): bool
    {
        return $this !== self::None;
    }
}
