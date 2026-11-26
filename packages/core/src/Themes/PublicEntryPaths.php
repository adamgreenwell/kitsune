<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Themes;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Kitsune\Core\Http\SiteAddressResolver;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Site;

/** @internal Public entry destinations for a selected theme. */
final class PublicEntryPaths
{
    public function __construct(private readonly SiteAddressResolver $addresses, private readonly Router $router) {}

    public static function validType(string $handle): bool
    {
        return preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $handle) === 1;
    }

    public static function validSlug(string $slug): bool
    {
        return $slug !== '' && $slug !== '.' && $slug !== '..' && strlen($slug) <= 255
            && preg_match('~[/\\\\\x00-\x20\x7f]~u', $slug) === 0;
    }

    public static function path(Site $site, string $type, string $slug): string
    {
        return $site->path_prefix.'/'.rawurlencode($type).'/'.rawurlencode($slug);
    }

    /** @return Collection<string, EntryType> */
    public static function publishedTypes(Site $site, SiteTheme $theme): Collection
    {
        return EntryType::visibleFor($site, $site->org_id)
            ->filter(fn (EntryType $type): bool => ! $type->is_media && in_array($type->handle, $theme->entryTypes, true))
            ->keyBy('handle');
    }

    public function ownsPath(Request $request, Site $site, string $path, string $fallback): bool
    {
        $owner = $this->addresses->resolvePaths($request->getHost(), [$path])[0];

        return $owner?->id === $site->id && $this->usesRoute(
            Request::create($request->getSchemeAndHttpHost().$path),
            $this->router->getRoutes()->get('GET'),
            $fallback,
        );
    }

    /**
     * @param  iterable<Entry>  $entries
     * @param  Collection<string, EntryType>  $types
     * @return array<int, string> URL paths keyed by entry ID
     */
    public function forEntries(Request $request, Site $site, SiteTheme $theme, iterable $entries, Collection $types, string $fallback): array
    {
        $paths = [];
        foreach ($entries as $entry) {
            if ($entry->org_id !== $site->org_id || $entry->site_id !== $site->id || $entry->status !== 'published'
                || $entry->slug === null || ! self::validSlug($entry->slug)
                || $types->get($entry->type_handle)?->id !== $entry->entry_type_id
                || $theme->entry($entry->type_handle) === null) {
                continue;
            }

            $paths[$entry->id] = self::path($site, $entry->type_handle, $entry->slug);
        }

        if ($paths === []) {
            return [];
        }

        $owners = $this->addresses->resolvePaths($request->getHost(), array_values($paths));
        $routes = $this->router->getRoutes()->get('GET');
        foreach (array_keys($paths) as $offset => $id) {
            $destination = Request::create($request->getSchemeAndHttpHost().$paths[$id]);
            if ($owners[$offset]?->id !== $site->id || ! $this->usesRoute($destination, $routes, $fallback)) {
                unset($paths[$id]);
            }
        }

        return $paths;
    }

    /** @param array<array-key, Route> $routes */
    private function usesRoute(Request $request, array $routes, string $name): bool
    {
        $firstFallback = null;
        foreach ($routes as $route) {
            if (! $route->matches($request)) {
                continue;
            }

            if (! $route->isFallback) {
                return $route->getName() === $name;
            }

            $firstFallback ??= $route;
        }

        return $firstFallback?->getName() === $name;
    }
}
