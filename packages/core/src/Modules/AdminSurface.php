<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Modules;

use Filament\Navigation\NavigationItem;

/**
 * What enabled modules have added to the admin — ADR-038's decision H.
 *
 * @internal First-party modules only. There is no third-party plugin path before v1.2 (no validation CLI, no
 * tenancy audit, no allowlist), so nothing outside this repository may rely on this class existing or keeping
 * its shape.
 *
 * ⚠️ CORE DECLINED TO OPEN THIS DOOR TWICE, AND WHAT CHANGED IS THE VERSION RATHER THAN THE ARGUMENT.
 * `RoleResource` was pulled into core specifically to avoid an extension point before the extension API
 * existed, and that reasoning was right. It is still right for a PUBLIC API — and 0.x carries no stability
 * promise, the v1.2 freeze is three releases away, and the alternative was a Phase 3 deliverable ("one entity
 * type end to end as a normal module") that stops short of the admin and proves the stack only halfway.
 *
 * ⚠️ IT IS A DUMB CONTAINER, DELIBERATELY. No permission logic, no panel resolution, no ordering rules. A
 * module's own Filament `Resource::canViewAny()` gates its resource and a `NavigationItem::visible()` closure
 * gates its link — both Filament's own mechanisms — so core is not inventing a second authorization layer for
 * subjects its permission vocabulary cannot even name (`Permissions::ACTIONS` has one subject, `entry`, and a
 * module's own subject is a v1.2 question).
 *
 * ⚠️ AND FILLING IT IS IN TIME, WHICH WAS MEASURED RATHER THAN ASSUMED. Three designs believed a module's
 * resources had to be registered before the host's panel provider ran. `Filament\PanelProvider::register()`
 * registers a CLOSURE, which the facade defers through `$app->resolving(PanelRegistry::class, …)`, so
 * `KitsunePanel::apply()` runs when the registry is first RESOLVED — after every provider has registered and
 * booted, and therefore after the kernel's `booted()` callback has registered every enabled module. Measured
 * on Filament v5.7.8: `panel()` had not run after `app()->register()` and the provider's `boot()`, and had run
 * after `app(PanelRegistry::class)`.
 */
final class AdminSurface
{
    /** @var list<class-string> */
    private array $resources = [];

    /** @var list<NavigationItem> */
    private array $navigationItems = [];

    /** @param class-string $resource */
    public function resource(string $resource): self
    {
        $this->resources[] = $resource;

        return $this;
    }

    public function navigationItem(NavigationItem $item): self
    {
        $this->navigationItems[] = $item;

        return $this;
    }

    /** @return list<class-string> */
    public function resources(): array
    {
        return $this->resources;
    }

    /** @return list<NavigationItem> */
    public function navigationItems(): array
    {
        return $this->navigationItems;
    }

    /**
     * Test seam.
     *
     * Production never forgets: a module registers once per process, and a surface cleared mid-request would
     * be a panel that lost half its links depending on when it was built.
     */
    public function flush(): void
    {
        $this->resources = [];
        $this->navigationItems = [];
    }
}
