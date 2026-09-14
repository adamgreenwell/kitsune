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
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Filament\Icons;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;
use Kitsune\Core\Filament\Resources\EntryTypes\EntryTypeResource;
use Kitsune\Core\Filament\Resources\Roles\RoleResource;
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
         * property that aborts the panel's registration; an id changed after `apply()` would also leave a stale one
         * recorded. This is the same object Filament registers, and by the time anything asks, it is configured.
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
            ->resources([EntryResource::class, EntryTypeResource::class, RoleResource::class])
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

        /*
         * ⚠️ FILTERED BY THE `view` PERMISSION, and it has to happen HERE rather than in `EntryPolicy`.
         * Navigation is supplied explicitly, so Filament never asks a resource whether each item should
         * appear — and `EntryResource` is ONE resource for every type, so a single `viewAny` could not
         * answer per item anyway. Without this a user sees a sidebar full of links that 403 when clicked.
         *
         * ⚠️ THE LINK IS NOT THE GUARANTEE. Hiding an item an authenticated user could still reach by
         * typing the URL is the classic version of this bug, and ADR-024 says the PHP suite structurally
         * cannot see it — so `e2e/permissions.spec.js` asserts the refusal at the URL as well as the
         * absent link.
         */
        $user = Permissions::currentUser();

        $types = EntryType::visibleFor($site instanceof Site ? $site : null, $orgId)
            ->filter(fn (EntryType $type): bool => $user !== null && Permissions::allows(
                $user, Permissions::forEntryType($type->handle, 'view'),
            ));

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
        ]);
    }
}
