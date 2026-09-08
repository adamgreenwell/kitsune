<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Auth\OrgAwareUserProvider;
use Kitsune\Core\Auth\RegistersOrgAwareProvider;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\PivotScopedThing;

/*
 * ⚠️ These tests exist because the branch they cover is unreachable from a
 * host service provider in the normal provider order — so deleting either
 * line in it left every test green while restoring a silently unscoped
 * provider. That is the "test that never fails" shape, and the fix was to
 * move the wiring somewhere a test can actually put the container into the
 * awkward state first.
 */

beforeEach(function (): void {
    config(['auth.providers.users.model' => User::class]);
});

it('registers the driver for an auth manager that does not exist yet', function (): void {
    RegistersOrgAwareProvider::on($this->app);

    expect(config('auth.providers.users.driver'))->toBe(RegistersOrgAwareProvider::DRIVER)
        ->and($this->app->make('auth')->createUserProvider('users'))
        ->toBeInstanceOf(OrgAwareUserProvider::class);
});

it('registers it for a manager that was ALREADY resolved', function (): void {
    // An auto-discovered package touching auth before this runs.
    $this->app->make('auth');

    RegistersOrgAwareProvider::on($this->app);

    // Without the already-resolved branch this throws:
    // "Authentication user provider [kitsune-eloquent] is not defined".
    expect($this->app->make('auth')->createUserProvider('users'))
        ->toBeInstanceOf(OrgAwareUserProvider::class);
});

it('discards a guard that already cached the DEFAULT, unscoped provider', function (): void {
    /*
     * ⚠️ The dangerous half, and the reason `forgetGuards()` is not optional.
     *
     * A guard built before this runs holds the stock EloquentUserProvider —
     * so authentication keeps working while bypassing the org scope
     * entirely. It reads as success, which is worse than the loud failure the
     * other branch produces.
     */
    $before = $this->app->make('auth')->guard('web')->getProvider();
    expect($before)->not->toBeInstanceOf(OrgAwareUserProvider::class);

    RegistersOrgAwareProvider::on($this->app);

    expect($this->app->make('auth')->guard('web')->getProvider())
        ->toBeInstanceOf(OrgAwareUserProvider::class);
});

it('stands the org scope down for the queries authentication makes', function (): void {
    /*
     * The whole reason the provider exists: the scope fails closed with no
     * org context, and authentication runs before any org exists — so a
     * scoped provider matches nobody and login is impossible.
     *
     * Asserted by BEHAVIOUR rather than by inspecting the removed scopes:
     * with no context the model itself finds nothing, and the provider's
     * query finds the rows anyway.
     */
    $org = Org::create(['name' => 'A', 'slug' => 'auth-a']);
    $thing = PivotScopedThing::create(['name' => 'Someone']);
    DB::table('pivot_scoped_thing_org')->insert(['org_id' => $org->id, 'pivot_scoped_thing_id' => $thing->id]);

    app(Context::class)->forget();

    expect(PivotScopedThing::count())->toBe(0);

    $provider = new OrgAwareUserProvider(app('hash'), PivotScopedThing::class);
    $query = (new ReflectionMethod($provider, 'newModelQuery'))->invoke($provider);

    expect($query->count())->toBe(1);
});
