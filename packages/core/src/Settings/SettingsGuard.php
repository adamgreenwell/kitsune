<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Settings;

use DateTimeZone;
use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Models\SiteGroup;
use Kitsune\Core\Readers\ReaderAddresses;
use Kitsune\Core\Readers\ReaderMode;
use LogicException;
use RuntimeException;

/**
 * What a settings map may hold (ADR-022).
 *
 * Asked in two places: `HoldsSettings` asks it on `saving` for an org, a site group or a site —
 * `FieldStorage::guardShape()`'s pattern — and `KitsuneServiceProvider` asks it of the configured defaults when it
 * builds the resolver. Between them they cover every value that reaches the resolver through Eloquent or through
 * configuration, and not every value that can: see below.
 *
 * ⚠️ THE `saving` HOOK IS THE ONLY DOOR THROUGH ELOQUENT BECAUSE THE BUILDER MAKES IT ONE. `settings` is listed in
 * `columnsRequiringModelSave()` on all three models, so a bulk write, a quiet save or a hand-rolled insert that
 * names it — under any spelling the database accepts — is refused by `ScopedBuilder` rather than stored unchecked.
 * Below Eloquent — `toBase()`, `DB::table()`, raw SQL — nothing at the model layer can stand, which is the boundary
 * `ScopedBuilder` already states; and `withoutScopeBecause()` stands the builder's per-row refusals down, as it
 * does for every other guarded column. A timezone stored by either is refused when it is read, by
 * `SiteTimezone::current()`, rather than used.
 */
final class SettingsGuard
{
    /** A key the platform itself reads: the zone the admin shows and takes instants in. */
    public const TIMEZONE = 'timezone';

    /**
     * A key the platform itself reads: which reader-account pages a site serves (ADR-037, as built) — `off`, `sign-in`
     * or `open`. Identity is per org; this switch is per site, and decides only which pages a site serves.
     */
    public const READER_ACCOUNTS = 'reader_accounts';

    /**
     * A key the platform itself reads: whether owners see readers' addresses on the entitlements page (ADR-037 decision
     * 1) — `hidden` or `shown`. Written by the operator's console alone; anything else reads as `hidden`.
     *
     * ⚠️ THE ORGANISATION'S ALONE, never a site group's or a site's. A reader is the org's, and Comp takes any reader of
     * it, so a site set to `shown` named a reader who held nothing there, and a site set to `hidden` beside a `shown`
     * sibling hid nothing from an owner of both — the identifiers are the same on both pages (measured, review). A
     * switch that claimed to hide what it did not would be worse than none.
     */
    public const READER_ADDRESSES = 'reader_addresses';

    /** The keys only an organisation, or the configured defaults, may hold. */
    private const ORG_ONLY = [self::READER_ADDRESSES];

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
     * @param  'default'|'org'|'site_group'|'site'  $level  the level it holds it at, as `Resolved::$origin` names them
     */
    public static function check(mixed $settings, string $holder, string $level = 'default'): void
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

        // `ReaderMode`'s values, the one list of them.
        if (array_key_exists(self::READER_ACCOUNTS, $settings)
            && (! is_string($settings[self::READER_ACCOUNTS]) || ReaderMode::tryFrom($settings[self::READER_ACCOUNTS]) === null)) {
            throw new RuntimeException(sprintf(
                'Refusing [reader_accounts] on %s: it is off, sign-in or open, and %s is none of them. To inherit '
                .'instead, revert the key rather than storing an empty one.',
                $holder,
                is_string($settings[self::READER_ACCOUNTS]) ? '"'.$settings[self::READER_ACCOUNTS].'"' : get_debug_type($settings[self::READER_ACCOUNTS]),
            ));
        }

        foreach (self::ORG_ONLY as $key) {
            if (array_key_exists($key, $settings) && ! in_array($level, ['default', 'org'], true)) {
                throw new RuntimeException(sprintf(
                    'Refusing [%s] on %s: it is set for a whole organisation, whose readers they are — never for a site '
                    .'group or a site, where a sibling would name the same readers. Set it on the organisation instead.',
                    $key,
                    $holder,
                ));
            }
        }

        // `ReaderAddresses`' values, the one list of them.
        if (array_key_exists(self::READER_ADDRESSES, $settings)
            && (! is_string($settings[self::READER_ADDRESSES]) || ReaderAddresses::tryFrom($settings[self::READER_ADDRESSES]) === null)) {
            throw new RuntimeException(sprintf(
                'Refusing [reader_addresses] on %s: it is hidden or shown, and %s is neither. To inherit instead, '
                .'revert the key rather than storing an empty one.',
                $holder,
                is_string($settings[self::READER_ADDRESSES]) ? '"'.$settings[self::READER_ADDRESSES].'"' : get_debug_type($settings[self::READER_ADDRESSES]),
            ));
        }
    }

    /**
     * The level a holder stores its settings at, as `Resolved::$origin` names them.
     *
     * @return 'org'|'site_group'|'site'
     */
    public static function levelOf(Model $holder): string
    {
        return match (true) {
            $holder instanceof Org => 'org',
            $holder instanceof SiteGroup => 'site_group',
            $holder instanceof Site => 'site',
            default => throw new LogicException(sprintf('%s holds no settings (ADR-022).', $holder::class)),
        };
    }

    /**
     * ⚠️ A CANONICAL IANA IDENTIFIER AS PHP LISTS THEM, spelled exactly — `DateTimeZone::listIdentifiers()`, which is
     * the list Laravel's own `timezone` rule checks by default, and which leaves out the database's aliases
     * (`Etc/UTC`, `GMT`, `US/Eastern`). `new DateTimeZone()` would accept far more (`EST`, `+05:00`,
     * `america/new_york`), and an offset is not a timezone: it has no daylight-saving rules, so a site configured
     * with one shows the wrong hour for half of every year.
     *
     * Null is refused too. ADR-022 has no "unset" sentinel — absent means inherit — so a key is reverted by removing
     * it, not by storing nothing in it.
     */
    private static function checkTimezone(mixed $value, string $holder): void
    {
        if (self::isTimezone($value)) {
            return;
        }

        /*
         * ⚠️ WHAT IS CHECKED, NOT "IANA". PHP's list is the canonical IANA names; the IANA database also carries
         * `Etc/UTC`, `GMT` and backward links such as `US/Eastern`, which the list leaves out — and this refused
         * each of them as "not an IANA timezone identifier", which they are.
         */
        throw new RuntimeException(sprintf(
            'Refusing [timezone] on %s: %s is not a timezone identifier PHP lists (DateTimeZone::listIdentifiers()), '
            .'such as Europe/London or America/New_York, spelled exactly. To inherit instead, revert the key rather '
            .'than storing an empty one.',
            $holder,
            is_string($value) ? '"'.$value.'"' : get_debug_type($value),
        ));
    }

    /**
     * Whether a value is a timezone this store accepts — see `checkTimezone()`.
     *
     * @phpstan-assert-if-true =string $value
     */
    public static function isTimezone(mixed $value): bool
    {
        return is_string($value) && in_array($value, DateTimeZone::listIdentifiers(), true);
    }
}
