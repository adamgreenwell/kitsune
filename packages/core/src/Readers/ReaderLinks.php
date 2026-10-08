<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Readers;

use Kitsune\Core\Models\Site;

/**
 * Where a site's reader pages are — built from the site, never from `route()` or the request.
 *
 * ⚠️ PATHS, NOT URLS. A link inside a reader page, a form's action and a redirect's `Location` are all paths under the
 * site's own prefix, so the `Host` header plays no part: a host-less site answers on any Host pointed at the server, and
 * a URL built from the request would carry whatever Host a stranger sent.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class ReaderLinks
{
    /** The segment every reader page lives under, after the site's prefix — reserved under every `base_url`. */
    public const SEGMENT = 'account';

    public const HOME = '';

    public const SIGN_IN = '/sign-in';

    public const SIGN_OUT = '/sign-out';

    /** A reader page's path on this site: its prefix (lower-case, as stored), `/account`, then the page. */
    public static function path(Site $site, string $page = self::HOME): string
    {
        return ($site->path_prefix ?? '').'/'.self::SEGMENT.$page;
    }

    /**
     * Whether a path is one of this site's reader pages, exactly as `path()` spells them — what a stored "intended" page
     * must be before a sign-in sends anyone there. No scheme, no host, no `//`, no query, and no other site's prefix.
     */
    public static function isOwn(Site $site, string $path): bool
    {
        return preg_match('#^'.preg_quote(self::path($site), '#').'(?:/[a-z0-9-]+)*$#D', $path) === 1;
    }
}
