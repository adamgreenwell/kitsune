<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Settings;

use DateTimeZone;
use RuntimeException;

/**
 * What a settings map may hold (ADR-022).
 *
 * Asked in two places, which between them are every way a value can become resolvable: `HoldsSettings` asks it on
 * `saving` for an org, a site group or a site — `FieldStorage::guardShape()`'s pattern — and
 * `KitsuneServiceProvider` asks it of the configured defaults when it builds the resolver.
 *
 * ⚠️ THE `saving` HOOK IS THE ONLY DOOR BECAUSE THE BUILDER MAKES IT ONE. `settings` is listed in
 * `columnsRequiringModelSave()` on all three models, so a bulk write, a quiet save or a hand-rolled insert that
 * names it is refused by `ScopedBuilder` rather than stored unchecked. Below Eloquent — `toBase()`, `DB::table()`,
 * raw SQL — nothing at the model layer can stand, which is the boundary `ScopedBuilder` already states; and
 * `withoutScopeBecause()` stands the builder's per-row refusals down, as it does for every other guarded column.
 */
final class SettingsGuard
{
    /** The one key the platform itself reads today. */
    public const TIMEZONE = 'timezone';

    /**
     * Why a bulk write may not name `settings` — the sentence `ScopedBuilder` quotes when it refuses one, through
     * each holder's `columnsRequiringModelSave()`.
     */
    public const REFUSED_IN_BULK = 'it is validated when the model saves, and the save is also what invalidates '
        .'the settings resolved from it (ADR-022). A bulk write skips both: it stores a value nothing checked, and '
        .'every site that already resolved keeps the old one.';

    /**
     * Refuse a settings map that is not one.
     *
     * @param  string  $holder  who holds the map, for the message — "Site 3", "the configured defaults"
     */
    public static function check(mixed $settings, string $holder): void
    {
        if ($settings === null) {
            return;
        }

        if (! is_array($settings)) {
            throw new RuntimeException(sprintf(
                'Refusing the settings of %s: settings are a map from a key to its value, and this is %s.',
                $holder,
                get_debug_type($settings),
            ));
        }

        foreach (array_keys($settings) as $key) {
            // ⚠️ `(array)` on a list or a bare string would otherwise resolve keys named "0", "1", ….
            if (! is_string($key) || $key === '') {
                throw new RuntimeException(sprintf(
                    'Refusing the settings of %s: every key is a name, and [%s] is not one.',
                    $holder,
                    (string) $key,
                ));
            }
        }

        if (array_key_exists(self::TIMEZONE, $settings)) {
            self::checkTimezone($settings[self::TIMEZONE], $holder);
        }
    }

    /**
     * ⚠️ AN IANA IDENTIFIER AS PHP LISTS THEM, spelled exactly — `DateTimeZone::listIdentifiers()`, which is the list
     * Laravel's own `timezone` rule checks by default. `new DateTimeZone()` would accept far more (`EST`, `+05:00`,
     * `america/new_york`), and an offset is not a timezone: it has no daylight-saving rules, so a site configured
     * with one shows the wrong hour for half of every year.
     *
     * Null is refused too. ADR-022 has no "unset" sentinel — absent means inherit — so a key is reverted by removing
     * it, not by storing nothing in it.
     */
    private static function checkTimezone(mixed $value, string $holder): void
    {
        if (is_string($value) && in_array($value, DateTimeZone::listIdentifiers(), true)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing [timezone] on %s: %s is not an IANA timezone identifier, such as Europe/London or '
            .'America/New_York, spelled exactly. To inherit instead, revert the key rather than storing an empty one.',
            $holder,
            is_string($value) ? '"'.$value.'"' : get_debug_type($value),
        ));
    }
}
