<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identifies which Site a PUBLIC request is for, and puts it in Context.
 *
 * ⚠️ The public counterpart of `SetKitsuneContext`, and it cannot reuse it.
 * That one mirrors Filament's resolved tenant, which only exists inside the
 * panel; the public side has no panel (ADR-002/ADR-008 put the admin in
 * Filament, not the front end), so a public request had no way to say which
 * site it was for. That is why `sites.locale` was applied by nothing on the
 * public side — gap G2, and the half of issue #38 that middleware alone could
 * not close.
 *
 * ⚠️ THIS RESOLVES IDENTITY ONLY. It sets no locale — `SetSiteLocale` does
 * that, reading the Context this leaves behind. Two middlewares because they
 * are two concerns, and because the locale one is already used by tests that
 * have nothing to do with routing.
 *
 * ⚠️ It does NOT 404 when no site matches. A host application's public routes
 * are its own, and most of them are not site-scoped: a marketing page, a
 * health check, a webhook. Refusing the request here would make this
 * middleware unattachable to anything but a site route, and attaching it to
 * one is how an application says "a site is required". Absence is reported by
 * leaving Context empty, which `SetSiteLocale` reads as "use the application
 * default".
 */
final class ResolveSiteFromRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        if (($site = $this->resolve($request)) !== null) {
            app(Context::class)->setSite($site);
        }

        return $next($request);
    }

    /**
     * The site this request addresses, or null when it addresses none.
     *
     * ⚠️ A SITE IS REACHABLE ONE WAY ONLY, decided by its own `url_strategy`.
     *
     * Matching on host and then falling back to path would make a
     * domain-addressed site ALSO answer on `/{slug}`, so the same content would
     * live at two URLs — duplicate canonical URLs, split analytics, and a
     * search engine picking whichever it saw first. The column exists to say
     * which one is real, so it is consulted rather than inferred, and a match
     * that contradicts it is not a match.
     */
    private function resolve(Request $request): ?Site
    {
        $host = $request->getHost();
        $segment = $this->firstSegment($request);

        /*
         * ⚠️ UNSCOPED, and this is the bootstrap case rather than a shortcut.
         *
         * `Site` is `#[OrgScoped]`, so this query is normally constrained to the
         * current org — but on a public request there IS no current org yet, and
         * the org is derived FROM the site this returns. Scoped, it matches
         * nothing and every public request falls back to the default locale,
         * which is the failure this middleware exists to fix.
         *
         * It is the same bootstrap `User::getTenants()` documents for the admin,
         * and it is safe for the same reason: authorisation is not what is being
         * decided. Resolving which public site a URL names is not a permission
         * question — the site is public.
         */
        return Site::withoutScopeBecause(
            'public site resolution: the org context is derived from the site this returns, so it cannot constrain it',
            function ($query) use ($host, $segment): ?Site {
                foreach ($query->get() as $site) {
                    if ($this->addresses($site, $host, $segment)) {
                        return $site;
                    }
                }

                return null;
            },
        );
    }

    /** Whether this site is the one addressed, by the strategy it declares. */
    private function addresses(Site $site, string $host, ?string $segment): bool
    {
        return match ($site->url_strategy) {
            'domain' => $this->hostOf($site->base_url) === $host,
            // `slug` and not `handle`: the slug is globally unique, while handle
            // is unique only within an org (ADR-021 amendment), so two orgs may
            // legitimately both have a site handled `golfdom`. Only one can own
            // a URL, and the slug is the column that says which.
            'path' => $segment !== null && $segment !== '' && $site->slug === $segment,
            default => false,
        };
    }

    /**
     * The host component of a configured base URL, or null when there is none.
     *
     * ⚠️ Parsed rather than compared as a string. `base_url` is operator-entered
     * and may carry a scheme, a port, a trailing slash or a path — all of which
     * make a raw `===` against `getHost()` fail for a site that is configured
     * perfectly correctly.
     */
    private function hostOf(?string $baseUrl): ?string
    {
        if ($baseUrl === null || trim($baseUrl) === '') {
            return null;
        }

        // A bare `example.test` has no scheme, and parse_url reads it as a path
        // rather than a host — so one is added when it is missing.
        $candidate = str_contains($baseUrl, '//') ? $baseUrl : 'https://'.$baseUrl;

        $host = parse_url($candidate, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    /**
     * The first path segment, or null when the request addresses the root.
     *
     * ⚠️ Read from the path rather than from a route parameter, so this works
     * whether or not the application declared one. A route with `{site}` gets
     * the same answer; a group with a literal prefix still resolves.
     */
    private function firstSegment(Request $request): ?string
    {
        $path = trim($request->path(), '/');

        if ($path === '' || $path === '/') {
            return null;
        }

        return explode('/', $path)[0];
    }
}
