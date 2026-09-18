<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Tables\Columns\TextColumn;
use Kitsune\Core\Settings\SiteTimezone;

/**
 * The admin's only constructors for an instant — a column that lists one and a picker that enters one — both in the
 * current site's timezone.
 *
 * ⚠️ ONE PLACE, FOR THE REASON `FieldValueRenderer` IS ONE PLACE. Issue #39 put `dir="auto"` on one title column, then
 * on three more found in review, because every screen decided for itself; a timezone applied screen by screen would
 * be reached the same way. `SiteTimeReachTest` fails when anything else in `packages/core/src` formats an instant's
 * time, sets a timezone, or builds a date-time or time picker. It cannot tell an instant from a date, so an instant
 * listed with `date()` — its UTC calendar day — or formatted by hand passes it; its docblock says so.
 *
 * ⚠️ PER COMPONENT, NOT `FilamentTimezone::set()`. Filament's timezone manager is one per application, so setting it
 * from core would move every date in a host's OTHER panels as well, and a long-lived worker would carry it between
 * requests. The closure handed to `timezone()` is asked when the component renders, so it reads this request's site.
 *
 * ⚠️ NOT FOR A DATE, AND FILAMENT WOULD CONVERT ONE IF ASKED. `DateTimePicker::getTimezone()` and
 * `CanFormatState::getTimezone()` return an explicit timezone first, for a date-only component as much as for any
 * other; having a time decides only the DEFAULT they fall back to. Measured: `DatePicker::make()->timezone(…)` in New
 * York hydrated `2026-09-18` as the seventeenth. So a date stays put because it is never handed one — `DatePicker`
 * and `TextColumn::date()` are not built here, and `SiteTimezoneTest` would see a date move.
 */
final class SiteTime
{
    /** A table column listing an instant, formatted in the site's timezone. */
    public static function column(string $name): TextColumn
    {
        return TextColumn::make($name)->dateTime()->timezone(SiteTimezone::current(...));
    }

    /**
     * A picker entering an instant in the site's timezone.
     *
     * Filament's `DateTimeStateCast` converts at both edges: the stored UTC value is shown in this timezone, and what
     * the author enters is read as wall-clock time here and handed back in the application's — measured in
     * `SiteTimezoneTest`, where 09:00 entered in America/New_York stores 13:00 UTC and shows 09:00 again. A field
     * holding several instants gets the same conversion per item from `FieldValueRenderer`, because Filament's
     * simple repeater skips the hydrating half.
     *
     * ⚠️ THE PICKER HOLDS A WALL-CLOCK TIME AND NO OFFSET, AND TWO CASES FOLLOW THAT ARE NOT RESOLVED. Measured in
     * `SiteTimezoneTest`, both in America/New_York:
     *
     * - In the hour a zone repeats, one wall-clock time names two instants. 05:30 and 06:30 UTC on 2026-11-01 both
     *   show as 01:30 and both save as the first, 05:30 — so an entry holding the second moves an hour when it is
     *   saved, even untouched, and a revision is recorded.
     * - In the hour a zone skips, a wall-clock time names none. 02:30 entered on 2026-03-08 is stored as 07:30 UTC
     *   and shows as 03:30, with no message.
     *
     * And the zone is read when the form is built and again when it is saved, so a form rendered before the site's
     * timezone changes reads its untouched instants in the new zone when saved. Nothing in the admin can change a
     * timezone yet, so that needs a write from outside it while the form is open.
     */
    public static function picker(string $statePath): DateTimePicker
    {
        return DateTimePicker::make($statePath)->timezone(SiteTimezone::current(...));
    }
}
