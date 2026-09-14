<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Filament\Facades\Filament;
use Filament\FilamentManager;
use Filament\Panel;
use Filament\PanelRegistry;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\TestUser;

/*
 * A host with Filament installed and no default panel — ADR-033.
 *
 * ⚠️ THIS SUITE COULD NOT REACH THE CASE, WHICH IS WHY IT SHIPPED. Testbench discovers no packages, so
 * `filament` is unbound here and `Permissions::userModel()` stood aside at its first line. A real host
 * auto-discovers Filament's provider — core requires Filament — so the binding exists whether or not a panel
 * does, and asking the registry for a default that was never set threw from `Role::assignTo()` before it wrote.
 * Found by installing the split into a bare Laravel host.
 *
 * ⚠️ BOUND THE WAY FILAMENT BINDS IT, AND NO FURTHER. These are the two bindings
 * `FilamentServiceProvider::packageRegistered()` makes for the manager and the registry; nothing else that
 * provider does is involved in the question, and booting all of it would be testing Filament.
 */
beforeEach(function (): void {
    app()->scoped('filament', fn (): FilamentManager => new FilamentManager);
    app()->singleton(PanelRegistry::class, fn (): PanelRegistry => new PanelRegistry);

    config(['auth.providers.users.model' => TestUser::class]);
});

/*
 * ⚠️ ON THIS BRANCH THE ANSWER IS THE CONFIGURED MODEL, NOT NULL. `userModel()` carries its own panel-less
 * fallback here — the configured provider — so a host with no usable panel is told what that provider loads,
 * which is the answer a seeder or a console command already gets.
 */
it('names the configured model, rather than throwing, when no panel is registered', function (): void {
    expect(app()->bound('filament'))->toBeTrue()
        ->and(Filament::getPanels())->toBe([])
        ->and(Permissions::userModel())->toBe(TestUser::class);
});

it('names the configured model when panels exist and none of them is the default', function (): void {
    /*
     * ⚠️ The case a `getPanels() !== []` pre-check would miss: the registry is not empty and still has no default.
     *
     * Placed in the registry rather than through `Filament::registerPanel()`, which also registers the panel's
     * Livewire components — a binding this suite does not boot, and nothing to do with which panel is default.
     */
    app(PanelRegistry::class)->panels['side'] = Panel::make()->id('side');

    expect(Filament::getPanels())->toHaveCount(1)
        ->and(Permissions::userModel())->toBe(TestUser::class);
});

it('still assigns a role through the configured model when there is no default panel', function (): void {
    // The consequence the host actually met: the throw stopped the assignment before the fallback was reached.
    $org = Org::create(['slug' => 'alpha', 'name' => 'Alpha']);
    app(Context::class)->setOrg($org);

    /** @var TestUser $user */
    $user = TestUser::create(['email' => 'member@kitsune.test']);
    DB::table('org_user')->insert(['org_id' => $org->getKey(), 'user_id' => $user->getKey()]);

    $role = Role::create(['handle' => 'editor', 'name' => 'Editor']);
    $role->assignTo($user->getKey());

    expect(DB::table('role_user')->where('role_id', $role->getKey())->where('user_id', $user->getKey())->exists())
        ->toBeTrue();
});
