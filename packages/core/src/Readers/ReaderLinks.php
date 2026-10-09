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
 * ⚠️ A MAILED LINK IS THE ONE ABSOLUTE URL, and it is built from the site's own address (`kitsune:site address`), never
 * from `url()`, `route()`, `asset()` or the request — a mail read elsewhere has no page to be relative to, and a link
 * built from the request would send a reader's secret to whatever Host a stranger sent. A site with no host of its own
 * mails no link outside `local` and `testing` (ADR-021, amended 2026-10-08), where `APP_URL` stands in for development.
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

    public const REGISTER = '/register';

    public const COMPLETE = '/register/complete';

    public const RECOVER = '/recover';

    public const RESET = '/reset';

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

    /**
     * Where this site's pages are served from — `https://acme.example`, with a port only when its address names one —
     * or null when no mailed link can be made for it.
     *
     * ⚠️ NEVER `base_url` ITSELF, which is stored as typed and could hold a user name and password a write through
     * `tinker` gave it: the scheme and port are read from it, the host is the derived `canonical_host`.
     */
    public static function origin(Site $site): ?string
    {
        $host = $site->canonical_host;

        if ($host === null) {
            return null;
        }

        if ($host === '') {
            return app()->environment(['local', 'testing']) ? self::developmentOrigin() : null;
        }

        $parts = parse_url((string) $site->base_url);
        $scheme = is_array($parts) && is_string($parts['scheme'] ?? null) ? strtolower($parts['scheme']) : null;

        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        return $scheme.'://'.$host.self::port($scheme, $parts['port'] ?? null);
    }

    /** A page's absolute URL for a mail, with its secret as the one query parameter — or null when there is no origin. */
    public static function absolute(Site $site, string $page, #[\SensitiveParameter] ?string $secret = null): ?string
    {
        $origin = self::origin($site);

        if ($origin === null) {
            return null;
        }

        return $origin.self::path($site, $page).($secret !== null ? '?token='.$secret : '');
    }

    /** `APP_URL`'s scheme, host and port, for a host-less site in development — refused if it names a path. */
    private static function developmentOrigin(): ?string
    {
        $parts = parse_url((string) config('app.url'));

        if (! is_array($parts) || ! is_string($parts['host'] ?? null) || $parts['host'] === '') {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $path = $parts['path'] ?? '';

        if (($scheme !== 'http' && $scheme !== 'https') || ($path !== '' && $path !== '/') || isset($parts['user']) || isset($parts['query'])) {
            return null;
        }

        return $scheme.'://'.strtolower($parts['host']).self::port($scheme, $parts['port'] ?? null);
    }

    /** `:8443` when the port is not the scheme's own, else nothing. */
    private static function port(string $scheme, mixed $port): string
    {
        return is_int($port) && $port !== ($scheme === 'https' ? 443 : 80) ? ':'.$port : '';
    }
}
