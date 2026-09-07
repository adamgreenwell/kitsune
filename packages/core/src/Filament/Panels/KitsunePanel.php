<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Panels;

use Filament\Facades\Filament;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;
use Kitsune\Core\Http\Middleware\IdentifyEntryType;
use Kitsune\Core\Http\Middleware\SetKitsuneContext;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/**
 * The Kitsune admin panel, applied to a host application's Filament panel.
 *
 * Everything here is load-bearing and was settled by measurement (ADR-012).
 */
final class KitsunePanel
{
    public static function apply(Panel $panel): Panel
    {
        return $panel
            // ADR-021: Filament's tenant IS the Site. Its automatic scope
            // therefore enforces site isolation and NOT org isolation, which
            // is why OrgScope exists.
            // slug, not handle: the route key must be globally unique
            // because /admin/{site} has no org segment (ADR-021 amendment).
            ->tenant(Site::class, slugAttribute: 'slug')
            // isPersistent: true is non-negotiable. Without it every Livewire
            // update 500s with "Missing required parameter: type" the moment
            // a table renders a record link.
            // SetKitsuneContext runs first: the scopes need something to
            // enforce before IdentifyEntryType queries entry types.
            ->tenantMiddleware([SetKitsuneContext::class, IdentifyEntryType::class], isPersistent: true)
            ->resources([EntryResource::class])
            ->pages([Dashboard::class])
            ->navigation(self::navigation(...));
    }

    /**
     * Navigation, supplied explicitly.
     *
     * Runs at render time, after tenant identification — and fires exactly
     * five times per request (layout, sidebar ×2, topbar ×2), measured rather
     * than estimated. Memoised per request, or that is five identical
     * queries on every page.
     */
    private static function navigation(NavigationBuilder $builder): NavigationBuilder
    {
        $site = Filament::getTenant();
        $orgId = $site instanceof Site ? $site->org_id : app(Context::class)->orgId();

        $types = once(fn () => EntryType::query()
            ->where(function ($query) use ($orgId): void {
                $query->whereNull('org_id');

                if ($orgId !== null) {
                    $query->orWhere('org_id', $orgId);
                }
            })
            ->orderBy('ordering')
            ->orderBy('handle')
            ->get()
            // A type disabled for this site 404s in IdentifyEntryType, so
            // rendering a menu item for it offers the operator a link that
            // cannot work. Same resolution the middleware uses (ADR-022).
            ->filter(fn (EntryType $type): bool => EntryTypeAvailability::isEnabledFor(
                $type,
                $site instanceof Site ? $site : null,
            ))
            ->values());

        return $builder->items([
            NavigationItem::make('Dashboard')
                ->icon('heroicon-o-home')
                ->url(fn (): string => Dashboard::getUrl())
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.*.pages.dashboard')),

            ...$types->map(fn (EntryType $type): NavigationItem => NavigationItem::make($type->plural_name)
                ->icon($type->icon ?? 'heroicon-o-rectangle-stack')
                ->url(fn (): string => EntryResource::getUrl('index', ['type' => $type->handle]))
                ->isActiveWhen(fn (): bool => request()->route()?->parameter('type') === $type->handle))->all(),
        ]);
    }
}
