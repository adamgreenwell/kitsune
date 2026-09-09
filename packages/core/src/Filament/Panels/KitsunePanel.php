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
use Kitsune\Core\Http\Middleware\SetUiLocale;
use Kitsune\Core\Models\EntryType;
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
            // SetUiLocale runs AFTER SetKitsuneContext, because it falls back to
            // the site's locale and needs a site in Context to fall back to.
            // Before IdentifyEntryType only because nothing there depends on it —
            // the ordering that matters is the one against SetKitsuneContext.
            ->tenantMiddleware([
                SetKitsuneContext::class,
                SetUiLocale::class,
                IdentifyEntryType::class,
            ], isPersistent: true)
            ->resources([EntryResource::class])
            ->pages([Dashboard::class])
            ->navigation(self::navigation(...));
    }

    /**
     * Navigation, supplied explicitly.
     *
     * Runs at render time, after tenant identification — and fires exactly
     * five times per request (layout, sidebar ×2, topbar ×2), measured rather
     * than estimated. The query lives on EntryType and is memoised there, or
     * that is five identical queries on every page.
     */
    private static function navigation(NavigationBuilder $builder): NavigationBuilder
    {
        $site = Filament::getTenant();
        $orgId = $site instanceof Site ? $site->org_id : app(Context::class)->orgId();

        $types = EntryType::visibleFor($site instanceof Site ? $site : null, $orgId);

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
