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
    ],
];
