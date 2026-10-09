<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Media\MediaDisks;

/*
 * Kitsune's own configuration. `KitsuneServiceProvider` merges it BENEATH a host's `config/kitsune.php`, so a
 * host overrides any key here by declaring it there.
 */
return [
    /*
     * Where uploaded bytes live — ADR-041.
     *
     * Two disks, because visibility decides delivery: a public file gets a direct URL a CDN can cache, and a
     * private one is streamed by a controller that authorises first. `public` is Laravel's own published disk,
     * which `deploy/release.sh` already links and proves resolves. The private disk is core's own and is never
     * served (ADR-042 decision 4a, `MediaDisks`) — not Laravel's `local`, which is served to anyone holding a
     * signed URL. Rows already naming `local` stay valid: delivery and disposal read each row's own `disk`.
     *
     * ⚠️ `mergeConfigFrom()` MERGES THE TOP LEVEL ONLY, as the note on `settings` below records. A host that
     * declares `media` in its own `config/kitsune.php` replaces this whole map rather than the keys it names,
     * so a host overriding one disk restates the other.
     */
    'media' => [
        'disks' => [
            'public' => 'public',
            'private' => MediaDisks::PRIVATE,
        ],
    ],

    /*
     * The platform defaults: the level beneath org, site group and site (ADR-022).
     *
     * A key here is what a site resolves when no level above it stores one, and it resolves with the
     * provenance "platform default". Each value is held to the rules a stored override is held to
     * (`SettingsGuard`), checked when the resolver is built.
     *
     * ⚠️ Laravel's `mergeConfigFrom()` merges the TOP level only. A host whose `config/kitsune.php` declares
     * `settings` replaces this whole map, not the keys it names — so a host that overrides one default
     * restates the others. `SiteTimezone` falls back to UTC if `timezone` is then missing.
     */
    'settings' => [
        // Where nothing overrides it, the zone the admin shows and takes instants in. Storage is UTC regardless.
        'timezone' => 'UTC',
        // Which reader-account pages a site serves (ADR-037): `off`, `sign-in` or `open`. ⚠️ `off` UNTIL AN OPERATOR
        // SAYS OTHERWISE, per org or per site (`kitsune:readers mode`): a stock install serves no reader page, and a
        // host map that drops the key reads as `off` too.
        'reader_accounts' => 'off',
    ],

    /*
     * The guard a reader signs in with — ADR-037, as ADR-040's entitlements built it.
     *
     * ⚠️ NO DEFAULT, deliberately: with nothing declared there is no reader, every entitlement check answers no and
     * every grant is refused. Never a panel's guard: a reader is not a panel user. Its model is `#[OrgScoped]` with
     * `EnforcesScope` and its own `org_id` — never `#[OrgScopedThroughPivot]` — and its keys are printable ASCII of at
     * most 255 bytes, never re-issued to another reader: a re-issued key inherits the old reader's access unless
     * `kitsune:entitlements forget` ran first. A route that asks runs `ResolveSiteFromRequest` before anything asks
     * the guard, its `auth` middleware included, or the reader's org scope has no org and loads nobody — in the
     * priority list, which core arranges (ADR-037, as built). The skeleton declares `readers`, in its
     * `AppServiceProvider::readerConfig()`, and core's sign-in pages use it once a model implements `ReaderAccount`.
     *
     * Named in CONTRIBUTING as an exception to the v1.2 surface rule: it obliges the host's code, not just its config.
     *
     * ⚠️ `mergeConfigFrom()` MERGES THE TOP LEVEL ONLY, as the notes above record: a host declaring `readers` restates
     * the whole map.
     */
    'readers' => [
        'guard' => null,
    ],

    /*
     * Passwords, for everyone who sets one — the first owner and readers (`PasswordRules`).
     *
     * `min_characters` raises the minimum length a new password needs, up to 64. It cannot lower it: anything below 15,
     * NIST SP 800-63B-4's minimum for a password that is the only factor, is never used — 15 applies, and
     * `kitsune:readers status` says why (Adam, 2026-10-09: never weak, even by manual override).
     */
    'passwords' => [
        'min_characters' => env('KITSUNE_PASSWORD_MIN_CHARACTERS', 15),
    ],
];
