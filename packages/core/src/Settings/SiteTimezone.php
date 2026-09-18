<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Settings;

use Kitsune\Core\Tenancy\Context;

/**
 * The timezone an instant is shown and entered in: the current site's resolved `timezone` setting.
 *
 * The settings store's first consumer. Storage does not move — `DateTimeType` stores UTC whatever this says — so the
 * conversion happens at the two edges, the picker an instant is entered with and the cell it is listed in, and
 * `Kitsune\Core\Filament\Schemas\SiteTime` is where the admin builds both.
 *
 * ⚠️ AN INSTANT ONLY. A calendar date has no timezone: `2026-09-18` read as UTC midnight and shown in
 * America/New_York is the seventeenth. So a `date` field is never converted through this, and `Control::Date` lists
 * in a `Cell::Date` of its own rather than sharing the instant's cell.
 */
final class SiteTimezone
{
    /** When nothing supplies a timezone at all: a host whose own `settings` map omits it (see config/kitsune.php). */
    public const FALLBACK = 'UTC';

    /**
     * The resolved timezone of the site in context.
     *
     * ⚠️ NO SITE IS THE DEFAULT, NOT AN ERROR. An org-level page, a console command and a queued job have no site, and
     * each of them may still format a date; they get the platform default.
     *
     * Asked per call rather than captured, so a long-lived worker and a request that changes the setting both see
     * the value as it stands — the resolver's per-request memo is what keeps that cheap.
     */
    public static function current(): string
    {
        $timezone = app(SettingsResolver::class)->get(app(Context::class)->site(), SettingsGuard::TIMEZONE);

        return is_string($timezone) ? $timezone : self::FALLBACK;
    }
}
