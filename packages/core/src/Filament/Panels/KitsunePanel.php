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
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Filament\Avatars\InitialsAvatarProvider;
use Kitsune\Core\Filament\Icons;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;
use Kitsune\Core\Filament\Resources\EntryTypes\EntryTypeResource;
use Kitsune\Core\Filament\Resources\Roles\RoleResource;
use Kitsune\Core\Filament\Widgets\EntryCountsWidget;
use Kitsune\Core\Filament\Widgets\RecentEntriesWidget;
use Kitsune\Core\Http\Middleware\IdentifyEntryType;
use Kitsune\Core\Http\Middleware\SetKitsuneContext;
use Kitsune\Core\Http\Middleware\SetUiLocale;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\AdminSurface;
use Kitsune\Core\Tenancy\Context;

/**
 * The Kitsune admin panel, applied to a host application's Filament panel.
 *
 * Everything here is load-bearing and was settled by measurement (ADR-012).
 */
final class KitsunePanel
{
    /**
     * The container key holding the panel Kitsune was applied to.
     *
     * ⚠️ IN THE CONTAINER RATHER THAN A STATIC, so it lives exactly as long as the application that configured the
     * panel, and one test's application cannot inherit another's.
     */
    public const PANEL_BINDING = 'kitsune.panel';

    public static function apply(Panel $panel): Panel
    {
        /*
         * ⚠️ RECORDED, BECAUSE OUTSIDE A REQUEST NOTHING ELSE KNOWS WHICH PANEL THIS IS. A console command, a queued
         * job and a seeder have no current panel, and `Permissions::userModel()` fell back to Filament's global
         * default — on a host that runs a second panel marked default, that panel, whose provider may name another
         * model. An audit row would then name an unrelated row with the same id. Found by the completeness check on
         * #8's split install.
         *
         * ⚠️ THE PANEL ITSELF, NOT ITS ID — review found the id read too early. A host may call
         * `KitsunePanel::apply($panel)->id('admin')`, and reading `getId()` before `id()` is an uninitialised
         * property that aborts the panel's registration. This is the same object Filament registers, and by the time
         * anything asks, it is configured.
         */
        app()->instance(self::PANEL_BINDING, $panel);

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
            /*
             * ⚠️ CORE'S THREE, THEN WHATEVER ENABLED MODULES ADDED — ADR-038 decision H. A disabled module's
             * provider is never registered, so it never fills the surface and contributes nothing here: the
             * switch in `modules` is what decides, and this reads the result rather than asking again.
             *
             * `resources()` APPENDS rather than replaces (`$this->resources[] = $resource`, with only the
             * model lookup reset), measured on Filament v5.7.8 — so the spread is additive in the way it
             * reads, and a module cannot displace core's own resources by registering first.
             */
            ->resources([
                EntryResource::class,
                EntryTypeResource::class,
                RoleResource::class,
                ...app(AdminSurface::class)->resources(),
            ])
            ->pages([Dashboard::class])
            ->widgets([EntryCountsWidget::class, RecentEntriesWidget::class])
            ->navigation(self::navigation(...))
            // Drawn here rather than fetched. Filament's default sends every signed-in user's initials and the site's
            // to ui-avatars.com on each page, and breaks on a host with no outbound network (ADR-020, ADR-027).
            ->defaultAvatarProvider(InitialsAvatarProvider::class);
    }

    /**
     * The entry types this admin offers the signed-in user on the current site.
     *
     * The sidebar's list and the dashboard's, resolved from the request; `viewableTypes()` holds the rule.
     *
     * @return Collection<int, EntryType>
     */
    public static function viewableTypesHere(): Collection
    {
        $site = Filament::getTenant();
        $site = $site instanceof Site ? $site : null;

        return self::viewableTypes(
            $site,
            $site !== null ? $site->org_id : app(Context::class)->orgId(),
            Permissions::currentUser(),
        );
    }

    /**
     * The entry types a user is offered on a site: those the site enables, filtered by the `view` permission.
     *
     * ⚠️ FILTERED BY THE `view` PERMISSION, and it has to happen HERE rather than in `EntryPolicy`.
     * Navigation is supplied explicitly, so Filament never asks a resource whether each item should
     * appear — and `EntryResource` is ONE resource for every type, so a single `viewAny` could not
     * answer per item anyway. Without this a user sees a sidebar full of links that 403 when clicked.
     *
     * ⚠️ THE LINK IS NOT THE GUARANTEE. Hiding an item an authenticated user could still reach by
     * typing the URL is the classic version of this bug, and ADR-024 says the PHP suite structurally
     * cannot see it — so `e2e/permissions.spec.js` asserts the refusal at the URL as well as the
     * absent link.
     *
     * ⚠️ ONE LIST FOR THE SIDEBAR AND THE DASHBOARD. A dashboard counting a type the sidebar hides would say how
     * much content sits behind a URL that refuses the reader, and two copies of this filter are two places for
     * one to fall behind the other.
     *
     * ⚠️ THE SCOPE AND THE USER ARE ARGUMENTS so the PHP suite can ask this directly: resolving them from the
     * request needs Filament's tenant, which core's tests never bind (ADR-024).
     *
     * @return Collection<int, EntryType>
     */
    public static function viewableTypes(?Site $site, ?int $orgId, ?Authenticatable $user): Collection
    {
        if ($user === null) {
            return collect();
        }

        return EntryType::visibleFor($site, $orgId)
            ->filter(fn (EntryType $type): bool => Permissions::allows(
                $user, Permissions::forEntryType($type->handle, 'view'),
            ))
            ->values();
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
        $types = self::viewableTypesHere();

        return $builder->items([
            NavigationItem::make('Dashboard')
                ->icon('heroicon-o-home')
                ->url(fn (): string => Dashboard::getUrl())
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.*.pages.dashboard')),

            ...$types->map(fn (EntryType $type): NavigationItem => NavigationItem::make($type->plural_name)
                // ⚠️ Through Icons::orFallback(), because navigation renders on
                // EVERY admin page and Blade Icons throws on a name it cannot
                // resolve. A free-text icon holding a typo returned 500 from
                // every page in the org's admin — including the one that could
                // have fixed it. The form now offers a select, and this still
                // holds: a seed, an import or a direct SQL write can put any
                // string here, and a renderer must not trust its data.
                ->icon(Icons::orFallback($type->icon))
                ->url(fn (): string => EntryResource::getUrl('index', ['type' => $type->handle]))
                ->isActiveWhen(fn (): bool => request()->route()?->parameter('type') === $type->handle))->all(),

            /*
             * The builder, grouped away from content on purpose: it is where the schema is changed, not
             * where the day's work happens.
             *
             * ⚠️ AND HIDDEN FROM SOMEBODY WHO MAY NOT USE IT, which review found missing: the content links
             * above were filtered while this one was added unconditionally, so the copy-editor's sidebar
             * offered the one link that mattered most. `EntryTypeResource::canViewAny()` is the boundary —
             * it gates the URL — and this is the half that stops the link advertising a refusal.
             */
            ...(EntryTypeResource::canViewAny() ? [
                NavigationItem::make('Entry types')
                    ->group('Structure')
                    ->icon('heroicon-o-squares-2x2')
                    ->url(fn (): string => EntryTypeResource::getUrl('index'))
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.*.resources.entry-types.*')),
            ] : []),

            // Roles sit beside the builder, and are hidden by the same rule for the same reason: both are
            // owner-only, and `canViewAny()` is the URL's boundary rather than this link's.
            ...(RoleResource::canViewAny() ? [
                NavigationItem::make('Roles')
                    ->group('Structure')
                    ->icon('heroicon-o-key')
                    ->url(fn (): string => RoleResource::getUrl('index'))
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.*.resources.roles.*')),
            ] : []),

            /*
             * Whatever enabled modules added — ADR-038's `@internal` seam, and LAST on purpose: core's own
             * navigation keeps its order whatever modules are installed, so a module cannot rearrange the
             * sidebar by registering early.
             *
             * ⚠️ NO VISIBILITY FILTER HERE, AND THAT IS NOT AN OVERSIGHT. Every link above is hidden by the
             * rule that gates its URL — `canViewAny()` — because a link advertising a refusal is the defect
             * review found in this very method. A module's subject is not something core's permission
             * vocabulary can name (`Permissions::ACTIONS` has one subject, `entry`; a module's own is a v1.2
             * question), so the module gates its own item with `NavigationItem::visible()` and its own
             * resource with `canViewAny()`. Core inventing a second authorization layer for subjects it
             * cannot express would be guessing on the module's behalf.
             */
            ...app(AdminSurface::class)->navigationItems(),
        ]);
    }
}
