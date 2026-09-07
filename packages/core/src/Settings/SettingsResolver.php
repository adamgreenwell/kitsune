<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Settings;

use Kitsune\Core\Models\Site;

/**
 * Resolves a setting through org → site group → site (ADR-022).
 *
 * Sparse overrides: a level stores a key only if it overrides it, and absent
 * means inherit. That is what makes an intentional override distinguishable
 * from a stale duplicate — the failure mode of copying full config to every
 * level, where nobody can safely change anything at the top.
 *
 * Shallow merge on top-level keys only. A setting is atomic: you override the
 * whole key, never part of it. Half-inherited nested objects are the single
 * most confusing thing a config system can produce, and deep merging is
 * exactly the pain Magento's debugging extension exists to relieve.
 */
final class SettingsResolver
{
    /** @var array<string, array<string, Resolved>> */
    private array $memo = [];

    /**
     * @param  array<string, mixed>  $defaults
     */
    public function __construct(private readonly array $defaults = []) {}

    public function get(Site $site, string $key, mixed $fallback = null): mixed
    {
        $resolved = $this->resolve($site, $key);

        return $resolved === null ? $fallback : $resolved->value;
    }

    /** The same lookup, carrying where the value came from. */
    public function resolve(Site $site, string $key): ?Resolved
    {
        return $this->all($site)[$key] ?? null;
    }

    /**
     * Every resolved key for a site, each with provenance.
     *
     * Memoised per request: resolution reads at most three rows and is cheap,
     * but it is on every request and must not be repeated per lookup.
     *
     * @return array<string, Resolved>
     */
    public function all(Site $site): array
    {
        $cacheKey = 'site:'.$site->getKey();

        if (isset($this->memo[$cacheKey])) {
            return $this->memo[$cacheKey];
        }

        $resolved = [];

        foreach ($this->defaults as $key => $value) {
            $resolved[$key] = new Resolved($key, $value, 'default');
        }

        foreach ((array) ($site->org->settings ?? []) as $key => $value) {
            $resolved[$key] = new Resolved($key, $value, 'org');
        }

        $group = $site->siteGroup;

        if ($group !== null) {
            foreach ((array) ($group->settings ?? []) as $key => $value) {
                $resolved[$key] = new Resolved($key, $value, 'site_group', $group->name);
            }
        }

        foreach ((array) ($site->settings ?? []) as $key => $value) {
            $resolved[$key] = new Resolved($key, $value, 'site', $site->name);
        }

        return $this->memo[$cacheKey] = $resolved;
    }

    /**
     * Drop memoised values for a site and everything beneath it.
     *
     * "I changed the setting and nothing happened" is a well-known support
     * burden in scope-based config systems, caused by caching. A write
     * invalidates the resolved cache for that scope and below.
     */
    public function forget(?Site $site = null): void
    {
        if ($site === null) {
            $this->memo = [];

            return;
        }

        unset($this->memo['site:'.$site->getKey()]);
    }
}
