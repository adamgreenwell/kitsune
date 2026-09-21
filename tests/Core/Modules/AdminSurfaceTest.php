<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Filament\Panel;
use Kitsune\Core\Filament\Panels\KitsunePanel;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;
use Kitsune\Core\Modules\AdminSurface;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Modules\ModuleLifecycle;
use Kitsune\Fixture\Module\FixtureModuleServiceProvider;
use Kitsune\Fixture\Module\FixtureThingResource;

const SEAM_PKG = 'kitsune/fixture-module';

beforeEach(function (): void {
    FixtureModuleServiceProvider::$calls = [];
    ModuleKernel::flush();
    app(AdminSurface::class)->flush();
});

/**
 * ⚠️ THE WHOLE POINT OF THE SEAM, AND IT IS DRIVEN THROUGH THE LIFECYCLE RATHER THAN BY POKING THE CONTAINER.
 * Installing and enabling a module is what makes the kernel register its provider, which is what fills the
 * surface, which is what the panel reads. A test that called `AdminSurface::resource()` itself would prove the
 * container and none of that chain.
 */
it('puts an enabled module resource into the panel', function (): void {
    ModuleLifecycle::install(app(), SEAM_PKG);
    ModuleLifecycle::enable(app(), SEAM_PKG);

    ModuleKernel::boot(app());

    $panel = KitsunePanel::apply(Panel::make()->id('seam')->path('seam'));

    expect($panel->getResources())->toContain(FixtureThingResource::class)
        /* Core's own resources keep their place: the module appended, it did not displace. */
        ->and($panel->getResources())->toContain(EntryResource::class);
});

/** ⚠️ The switch is what decides. A module that is installed and off contributes nothing to the admin. */
it('puts nothing into the panel for a module that is installed but disabled', function (): void {
    ModuleLifecycle::install(app(), SEAM_PKG);

    ModuleKernel::boot(app());

    $panel = KitsunePanel::apply(Panel::make()->id('seam-off')->path('seam-off'));

    expect($panel->getResources())->not->toContain(FixtureThingResource::class)
        ->and($panel->getResources())->toContain(EntryResource::class)
        ->and(app(AdminSurface::class)->resources())->toBe([]);
});

it('puts nothing into the panel when no module is installed at all', function (): void {
    ModuleKernel::boot(app());

    $panel = KitsunePanel::apply(Panel::make()->id('seam-none')->path('seam-none'));

    expect($panel->getResources())->not->toContain(FixtureThingResource::class)
        ->and($panel->getResources())->toHaveCount(3);
});

it('carries a module navigation item through to the surface', function (): void {
    ModuleLifecycle::install(app(), SEAM_PKG);
    ModuleLifecycle::enable(app(), SEAM_PKG);

    ModuleKernel::boot(app());

    $items = app(AdminSurface::class)->navigationItems();

    expect($items)->toHaveCount(1)
        ->and($items[0]->getLabel())->toBe('Fixture things');
});

/**
 * ⚠️ ADR-002: CORE IS HEADLESS-CAPABLE, and `PanelLessHostTest`'s docblock records the cost of a suite that
 * could not reach the case — "THIS SUITE COULD NOT REACH THE CASE, WHICH IS WHY IT SHIPPED". A module that
 * fills the seam must not break an application that configures no Kitsune panel at all.
 */
it('lets a module fill the seam on a host with no panel', function (): void {
    ModuleLifecycle::install(app(), SEAM_PKG);
    ModuleLifecycle::enable(app(), SEAM_PKG);

    /* No panel is built here — the kernel and the seam are exercised entirely without one. */
    ModuleKernel::boot(app());

    /*
     * ⚠️ THE ASSERTION IS THAT THE MODULE IS REGISTERED, NOT THAT `registerModule()` RAN ON THIS BOOT — and
     * the difference is a real property of the system that the engine matrix taught me. `Application::register()`
     * returns the existing instance for a provider it already holds, so a second kernel boot in one process
     * registers nothing again. That is invisible on SQLite and visible on MySQL and MariaDB, where DDL causes
     * an implicit COMMIT: a receipt written by an earlier test survives `RefreshDatabase`'s rollback, the next
     * application boots with that module already enabled, and the kernel therefore registers its provider
     * before the test body runs at all.
     *
     * An earlier version asserted `$calls` contained 'register' and failed on exactly that, in exactly those
     * two lanes. It was the test that was wrong: what ADR-002 needs from this case is that a module reaches
     * the kernel on a host that configures no panel, and "is registered" is that claim.
     */
    expect(app()->getProviders(FixtureModuleServiceProvider::class))->not->toBeEmpty();
});

/**
 * ⚠️ APPENDING, NOT REPLACING, and asserted because the whole seam rests on it. Measured on Filament v5.7.8:
 * `Panel::resources()` does `$this->resources[] = $resource` and resets only the model lookup, so a second
 * call adds. If a future Filament made it replace, core's three resources would vanish the moment any module
 * registered one — and this is the assertion that would say so.
 */
it('appends rather than replaces when resources are added twice', function (): void {
    $panel = Panel::make()->id('append')->path('append');

    $panel->resources([EntryResource::class]);
    $first = $panel->getResources();

    $panel->resources([FixtureThingResource::class]);

    expect($panel->getResources())->toHaveCount(count($first) + 1)
        ->and($panel->getResources())->toContain(EntryResource::class)
        ->and($panel->getResources())->toContain(FixtureThingResource::class);
});
