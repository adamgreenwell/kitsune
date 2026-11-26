<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Themes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Kitsune\Core\Audit\Auditor;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Models\SiteMenu;
use Kitsune\Core\Tenancy\Context;

/**
 * @internal
 *
 * @phpstan-import-type MenuItem from SiteMenuItems
 *
 * @phpstan-type PublicLink array{label: string, path: string}
 * @phpstan-type PublicItem array{label: string, path: string, children: list<PublicLink>}
 */
final class SiteMenus
{
    public function __construct(
        private readonly Context $context,
        private readonly Auditor $audit,
        private readonly PublicEntryPaths $paths,
    ) {}

    /** @param array<array-key, mixed> $items */
    public function save(Site $site, string $handle, string $name, array $items, ?int $version): SiteMenu
    {
        abort_unless($site->id === $this->context->siteId() && $site->org_id === $this->context->orgId(), 404);
        $name = trim($name);
        $parsed = SiteMenuItems::parse($items);
        if (! PublicEntryPaths::validType($handle) || $name === '' || mb_strlen($name) > 120 || $parsed === null) {
            $this->refuse(__('kitsune::navigation.invalid_menu'));
        }

        return $site->getConnection()->transaction(function () use ($site, $handle, $name, $parsed, $version): SiteMenu {
            $query = $this->query($site)->where('handle', $handle)->useWritePdo();
            $previous = $query->first();
            if ($previous?->version !== $version) {
                $this->refuse(__('kitsune::navigation.stale_menu'));
            }

            $retained = SiteMenuItems::parse($previous?->items) ?? [];
            $targets = array_values(array_diff(SiteMenuItems::targets($parsed), SiteMenuItems::targets($retained), ['home']));
            if (array_diff($targets, array_keys($this->entries($site, $targets))) !== []) {
                $this->refuse(__('kitsune::navigation.invalid_target'));
            }

            if ($previous === null) {
                try {
                    $menu = SiteMenu::query()->create(['handle' => $handle, 'name' => $name, 'items' => $parsed, 'version' => 1]);
                } catch (UniqueConstraintViolationException) {
                    $this->refuse(__('kitsune::navigation.stale_menu'));
                }
            } else {
                $previous->fill(['name' => $name, 'items' => $parsed, 'version' => $previous->version + 1]);
                if ($query->where('version', $version)->update($previous->getDirty()) !== 1) {
                    $this->refuse(__('kitsune::navigation.stale_menu'));
                }
                $menu = $previous->refresh();
            }

            $this->audit->recordOrFail('site.menu_saved', $menu);

            return $menu;
        }, attempts: 3);
    }

    public function remove(Site $site, SiteMenu $menu, int $version): void
    {
        abort_unless($site->id === $this->context->siteId() && $site->org_id === $this->context->orgId(), 404);
        $site->getConnection()->transaction(function () use ($site, $menu, $version): void {
            $removed = $this->query($site)->whereKey($menu->id)->where('version', $version)->delete();
            if ($removed !== 1) {
                $this->refuse(__('kitsune::navigation.stale_removal'));
            }

            $this->audit->recordOrFail('site.menu_removed', $menu);
        }, attempts: 3);
    }

    /** @return list<PublicItem> */
    public function render(Request $request, Site $site, SiteTheme $theme, string $handle, string $entryRoute, string $homeRoute): array
    {
        $menu = $this->query($site)->where('handle', $handle)->first();
        $items = SiteMenuItems::parse($menu?->items);
        if ($items === null || $items === []) {
            return [];
        }

        $targets = SiteMenuItems::targets($items);
        $entries = $this->entries($site, $targets);
        $paths = $this->paths->forEntries(
            $request, $site, $theme, $entries, PublicEntryPaths::publishedTypes($site, $theme), $entryRoute,
        );
        $links = [];
        foreach ($entries as $target => $entry) {
            if (isset($paths[$entry->id])) {
                $links[$target] = $paths[$entry->id];
            }
        }

        $home = $site->path_prefix ?: '/';
        if (in_array('home', $targets, true) && $this->paths->ownsPath($request, $site, $home, $home === '/' ? $homeRoute : $entryRoute)) {
            $links['home'] = $home;
        }

        return $this->publicItems($items, $links);
    }

    /** @return Builder<SiteMenu> */
    private function query(Site $site): Builder
    {
        return SiteMenu::query()->where('site_id', $site->id)->where('org_id', $site->org_id);
    }

    /**
     * @param  list<string>  $targets
     * @return array<string, Entry>
     */
    private function entries(Site $site, array $targets): array
    {
        $pins = [];
        $groups = [];
        foreach ($targets as $target) {
            if (str_starts_with($target, 'entry:')) {
                $pins[] = (int) substr($target, 6);
            } elseif (str_starts_with($target, 'group:')) {
                $groups[] = substr($target, 6);
            }
        }
        if ($pins === [] && $groups === []) {
            return [];
        }

        $entries = Entry::query()->where('site_id', $site->id)->where('org_id', $site->org_id)
            ->where(fn (Builder $query) => $query->whereIntegerInRaw('id', $pins)->orWhereIn('translation_group', $groups))
            ->get();
        $resolved = [];
        foreach ($entries as $entry) {
            $resolved['entry:'.$entry->id] = $entry;
            if ($entry->translation_group !== null) {
                $resolved['group:'.strtolower($entry->translation_group)] = $entry;
            }
        }

        return array_intersect_key($resolved, array_fill_keys($targets, true));
    }

    /**
     * @param  list<MenuItem>  $items
     * @param  array<string, string>  $links
     * @return list<PublicItem>
     */
    private function publicItems(array $items, array $links): array
    {
        $public = [];
        foreach ($items as $item) {
            $children = [];
            foreach ($item['children'] as $child) {
                if (isset($links[$child['target']])) {
                    $children[] = ['label' => $child['label'], 'path' => $links[$child['target']]];
                }
            }

            if (isset($links[$item['target']])) {
                $public[] = ['label' => $item['label'], 'path' => $links[$item['target']], 'children' => $children];

                continue;
            }

            // Keep reachable children when their parent link cannot be served.
            foreach ($children as $child) {
                $public[] = $child + ['children' => []];
            }
        }

        return $public;
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['items' => $message]);
    }
}
