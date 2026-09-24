<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Filament\Facades\Filament;
use Filament\FilamentManager;
use Filament\Panel;
use Filament\PanelRegistry;
use Filament\Resources\ResourceConfiguration;
use Illuminate\Support\Once;
use Kitsune\Core\Filament\Panels\KitsunePanel;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/**
 * Kitsune's panel, with Filament's tenancy switched on, inside the PHP suite — ADR-042 decision 2.
 *
 * ⚠️ THE SUITE NEVER SAW FILAMENT'S SCOPE, AND THIS IS WHY IT CAN NOW. Testbench discovers no packages, so no panel
 * is registered, no `admin_tenancy` global scope is on `Entry`, and no `creating` listener stamps the site: every
 * core test measured `SiteScope` alone, and ADR-042 once said the PHP suite "cannot see Filament's scope". It can,
 * once something registers it — which is all this does.
 *
 * ⚠️ IT MIRRORS THE TENANCY BLOCK OF `Panel::boot()`, AND NOTHING ELSE OF IT: the same resources, collected the same
 * way and in the same order, each asked to observe creation and then to register its scope. The rest of `boot()`
 * reaches colour, icon and view registries Testbench does not provide, and none of them bears on which rows a query
 * returns. The real boot is exercised where it runs — the browser suite.
 *
 * Bound the way `PanelLessHostTest` binds Filament: the manager and the registry, and no further. Registrations do not
 * outlive the test — each test gets a fresh application, and with it fresh model event and scope registrations.
 */
final class PanelTenancy
{
    public static function enter(Site $site): Panel
    {
        app()->scoped('filament', fn (): FilamentManager => new FilamentManager);
        app()->singleton(PanelRegistry::class, fn (): PanelRegistry => new PanelRegistry);

        $panel = KitsunePanel::apply(Panel::make())->id('admin');
        app(PanelRegistry::class)->panels['admin'] = $panel;

        Filament::setCurrentPanel($panel);

        $resources = array_unique([
            ...$panel->getResources(),
            ...array_map(
                static fn (ResourceConfiguration $configuration): string => $configuration->getResource(),
                $panel->getResourceConfigurations(),
            ),
        ]);

        foreach ($resources as $resource) {
            $resource::observeTenancyModelCreation($panel);
            $resource::registerTenancyModelGlobalScope($panel);
        }

        self::moveTo($site);

        return $panel;
    }

    /**
     * Change the current site, as a request to another site's panel would — Filament's tenant and Kitsune's context
     * together, and the request's memos dropped, since a memo keyed on one site's availability outlives nothing else.
     */
    public static function moveTo(Site $site): void
    {
        Filament::setTenant($site, isQuiet: true);

        $context = app(Context::class);
        $context->setOrg($site->org);
        $context->setSite($site);

        Once::flush();
    }
}
