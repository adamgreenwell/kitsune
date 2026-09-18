<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Settings;

use Kitsune\Core\Tenancy\Context;
use RuntimeException;

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
    /**
     * When no level and no configured default supplies a `timezone` at all — a host whose own `settings` map omits
     * the key (see config/kitsune.php). A value that IS supplied and is not a timezone never falls back to this:
     * see `current()`.
     */
    public const FALLBACK = 'UTC';

    /**
     * The resolved timezone of the site in context.
     *
     * ⚠️ NO SITE IS THE DEFAULT, NOT AN ERROR. An org-level page, a console command and a queued job have no site, and
     * each of them may still format a date; they get the platform default.
     *
     * ⚠️ A STORED VALUE THAT IS NOT A TIMEZONE FAILS, CLOSED AND BY NAME. `SettingsGuard` refuses one on every
     * Eloquent path, but a write below Eloquent or inside `withoutScopeBecause()` is not checked, and neither is a
     * value stored before the check existed. Such a value was handled two ways: a string reached Carbon, which threw
     * "Unknown or bad timezone" from every cell and picker; anything else was read as UTC, hiding a valid value set
     * above it, so authors entered instants in the wrong zone and nothing said so. Measured, both. Now each fails
     * here, with the level that holds it — the provenance the resolver already carries.
     *
     * Asked per call rather than captured, so a long-lived worker and a request that changes the setting both see
     * the value as it stands — the resolver's per-request memo is what keeps that cheap.
     */
    public static function current(): string
    {
        $resolved = app(SettingsResolver::class)->resolve(app(Context::class)->site(), SettingsGuard::TIMEZONE);

        if ($resolved === null) {
            return self::FALLBACK;
        }

        if (! SettingsGuard::isTimezone($resolved->value)) {
            throw new RuntimeException(sprintf(
                'The timezone resolved for this site is %s, %s, and it is not a timezone identifier PHP lists. It '
                .'was stored past the check that refuses one — below Eloquent, or inside withoutScopeBecause() — so '
                .'no date is formatted with it. Replace it, or revert it at that level (SettingsWriter::revert()).',
                is_string($resolved->value) ? '"'.$resolved->value.'"' : get_debug_type($resolved->value),
                $resolved->describe(),
            ));
        }

        return $resolved->value;
    }
}
