<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Http;

use Illuminate\Http\Request;
use Kitsune\Core\Models\Site;

/** @internal The public Site claim lookup for incoming requests and generated links. */
final class SiteAddressResolver
{
    public function resolve(Request $request): ?Site
    {
        return $this->resolvePaths($request->getHost(), [$request->path()])[0];
    }

    /**
     * Resolve encoded URL paths on one host, in input order, without changing request context.
     *
     * @param  list<string>  $paths
     * @return list<Site|null>
     */
    public function resolvePaths(string $host, array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        // The request spelling accepts hosts a stored claim would refuse.
        $host = Site::requestHost($host);
        $candidates = array_map($this->pathPrefixes(...), $paths);
        $prefixes = [];
        foreach ($candidates as $pathPrefixes) {
            foreach ($pathPrefixes as $prefix) {
                $prefixes[$prefix] = true;
            }
        }

        /** @var array<string, array<string, Site>> $matches */
        $matches = [];
        // Bound both the parameters per query and the number of possible keys per path.
        foreach (array_chunk(array_keys($prefixes), 200) as $chunk) {
            $sites = Site::withoutScopeBecause(
                'public site resolution: the org context is derived from the site this returns, so it cannot constrain it',
                fn ($query) => $query
                    ->whereIn('canonical_host', [$host, ''])
                    ->whereIn('path_prefix', $chunk)
                    // A soft-deleted Org keeps its Site rows but releases its public claims.
                    ->whereHas('org')
                    ->get(),
            );

            foreach ($sites as $site) {
                $matches[$site->canonical_host][$site->path_prefix] = $site;
            }
        }

        return array_map(static function (array $pathPrefixes) use ($host, $matches): ?Site {
            // A named host takes precedence over a hostless claim, even with a shorter prefix.
            foreach ([$host, ''] as $candidateHost) {
                foreach ($pathPrefixes as $prefix) {
                    if (isset($matches[$candidateHost][$prefix])) {
                        return $matches[$candidateHost][$prefix];
                    }
                }
            }

            return null;
        }, $candidates);
    }

    /** @return list<string> Prefixes in descending specificity, ending at the Site root. */
    private function pathPrefixes(string $path): array
    {
        $segments = array_values(array_filter(
            explode('/', trim($path, '/')),
            static fn (string $segment): bool => $segment !== '',
        ));

        $segments = array_slice($segments, 0, Site::MAX_PREFIX_SEGMENTS);

        $prefixes = [];
        for ($depth = count($segments); $depth >= 1; $depth--) {
            $prefixes[] = '/'.mb_strtolower(implode('/', array_slice($segments, 0, $depth)));
        }
        $prefixes[] = '';

        return $prefixes;
    }
}
