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
     * ⚠️ ONE MECHANISM FOR ALL THREE STRATEGIES, which is what ADR-021 actually says:
     * "one mechanism expresses all three URL strategies with no special cases, including
     * path prefixes, where `base_url` is simply `https://example.com/fr`".
     *
     * The first version of this method matched a `path` site against its admin `slug` and
     * dropped `subdomain` into a `default => false` arm. Both were wrong. A slug is the
     * ADMIN route key — `/admin/{site}` — and using it publicly exposed every site at
     * `/{slug}` on every host while leaving a site configured with a real `base_url`
     * unreachable at its own URL. And `subdomain` is a documented third value, so a site
     * declaring it resolved to nothing at all. Under `base_url` there is no third case:
     * a subdomain is just a host.
     *
     * ⚠️ ONE INDEXED QUERY, not a scan. The previous version loaded every site and
     * compared in PHP, which is O(total sites) in time and memory on every public request
     * — against the 1 vCPU / 1 GB floor of ADR-027, and unbounded as an installation
     * grows. `canonical_host` and `path_prefix` are stored and uniquely indexed, so the
     * four candidate pairs are one `whereIn` returning at most four rows.
     */
    private function resolve(Request $request): ?Site
    {
        $host = $this->canonicalHost($request->getHost());
        $prefixes = $this->pathPrefixes($request);

        /*
         * ⚠️ ORDERED BY SPECIFICITY, and the order is the whole guarantee. A request for
         * `https://golfdom.test/news/fr` could legitimately match several configurations,
         * and without a stated precedence the winner is whichever row the database returned
         * — so deleting an unrelated site could silently change which org a URL served.
         *
         * Most specific first: this host with the longest prefix, down to this host at its
         * root, then any host with the longest prefix, down to any host at its root. A named
         * host always beats a host-less claim, and a longer prefix always beats a shorter
         * one.
         */
        $candidates = [];

        foreach ($prefixes as $prefix) {
            $candidates[] = [$host, $prefix];
        }

        foreach ($prefixes as $prefix) {
            $candidates[] = ['', $prefix];
        }

        /*
         * ⚠️ UNSCOPED, and this is the bootstrap case rather than a shortcut.
         *
         * `Site` is `#[OrgScoped]`, so this query is normally constrained to the current
         * org — but on a public request there IS no current org yet, and the org is derived
         * FROM the site this returns. Scoped, it matches nothing and every public request
         * falls back to the default locale, which is the failure this middleware exists to
         * fix. Same bootstrap `User::getTenants()` documents for the admin, and safe for
         * the same reason: which public site a URL names is not a permission question.
         */
        $matches = Site::withoutScopeBecause(
            'public site resolution: the org context is derived from the site this returns, so it cannot constrain it',
            fn ($query) => $query
                ->whereNotNull('canonical_host')
                ->where(function ($inner) use ($candidates): void {
                    foreach ($candidates as [$candidateHost, $candidatePrefix]) {
                        $inner->orWhere(function ($pair) use ($candidateHost, $candidatePrefix): void {
                            $pair->where('canonical_host', $candidateHost)
                                ->where('path_prefix', $candidatePrefix);
                        });
                    }
                })
                ->get(),
        );

        foreach ($candidates as [$candidateHost, $candidatePrefix]) {
            foreach ($matches as $site) {
                if ($site->canonical_host === $candidateHost && $site->path_prefix === $candidatePrefix) {
                    return $site;
                }
            }
        }

        return null;
    }

    /**
     * A request host reduced to the same spelling `Site` stores.
     *
     * ⚠️ It must match `Site::canonicalHost()` exactly or nothing ever resolves, which is
     * why both drop the port and lowercase: `getHost()` already excludes the port, and a
     * configured `https://Example.Test:8443/` must find the same row as a request to
     * `example.test`.
     */
    private function canonicalHost(string $host): string
    {
        return rtrim(mb_strtolower(trim($host)), '.');
    }

    /**
     * Every path prefix this request could be claiming, longest first, ending with the root.
     *
     * ⚠️ NOT JUST THE FIRST SEGMENT, and that version was broken. A `base_url` of
     * `https://example.test/news/fr` stores `path_prefix = '/news/fr'`, while a resolver
     * that only ever built `/news` could never match it — not for `/news/fr/article`, and
     * not even for `/news/fr` itself. The site was configured, saved, indexed and
     * unreachable. Found by review.
     *
     * ⚠️ BOUNDED BY `Site::MAX_PREFIX_SEGMENTS`, because these become candidate pairs in one
     * query and the path is chosen by whoever sends the request (invariant 6): unbounded, a
     * long URL would decide how much work the database does, on the 1 vCPU floor of ADR-027.
     * The same constant makes `Site::canonicalPrefix()` REFUSE a deeper prefix, so this
     * bound can never be the reason a saved site cannot be found.
     *
     * ⚠️ Read from the path rather than from a route parameter, so this works whether or not
     * the application declared one. A route with `{site}` gets the same answer; a group with
     * a literal prefix still resolves.
     *
     * @return array<int, string> prefixes, longest first, always including `''`
     */
    private function pathPrefixes(Request $request): array
    {
        $segments = array_values(array_filter(
            explode('/', trim($request->path(), '/')),
            static fn (string $segment): bool => $segment !== '',
        ));

        $segments = array_slice($segments, 0, Site::MAX_PREFIX_SEGMENTS);

        $prefixes = [];

        for ($depth = count($segments); $depth >= 1; $depth--) {
            $prefixes[] = '/'.mb_strtolower(implode('/', array_slice($segments, 0, $depth)));
        }

        // The site root, which is a real claim rather than an absent one.
        $prefixes[] = '';

        return $prefixes;
    }
}
