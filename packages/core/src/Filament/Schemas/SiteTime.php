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
 * be reached the same way. `SiteTimeReachTest` fails when a date-time column or picker is built anywhere else in
 * `Kitsune\Core\Filament`, so a new screen cannot list an instant in UTC by forgetting.
 *
 * ⚠️ PER COMPONENT, NOT `FilamentTimezone::set()`. Filament's timezone manager is one per application, so setting it
 * from core would move every date in a host's OTHER panels as well, and a long-lived worker would carry it between
 * requests. The closure handed to `timezone()` is asked when the component renders, so it reads this request's site.
 *
 * ⚠️ NOT FOR A DATE. `DatePicker` and `TextColumn::date()` are not built here and take no timezone: Filament converts
 * a component only when it has a time (`DateTimePicker::getTimezone()`, `CanFormatState::getTimezone()`), and a
 * calendar date shifted through one lands on the previous day west of UTC.
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
     * `SiteTimezoneTest`, where 09:00 entered in America/New_York stores 13:00 UTC and shows 09:00 again.
     */
    public static function picker(string $statePath): DateTimePicker
    {
        return DateTimePicker::make($statePath)->timezone(SiteTimezone::current(...));
    }
}
