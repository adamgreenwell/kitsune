<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Auth\AuthManager;
use Illuminate\Support\ServiceProvider;
use Kitsune\Core\Auth\OrgAwareUserProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // `User` is org-scoped through `org_user`, and that scope fails closed
        // with no org context — which is the state authentication runs in,
        // because the org is derived from the site the user is on their way
        // to. The default Eloquent provider would match nobody and login
        // would be impossible.
        //
        // ⚠️ Both halves in register(), and in this order, because they are
        // one change. Naming the driver here while registering its factory in
        // boot() leaves a window: a provider that resolves a guard during its
        // own register() gets "Authentication user provider [kitsune-eloquent]
        // is not defined", and one that resolves it earlier still caches the
        // DEFAULT provider — leaving authentication fail-closed after boot.
        config(['auth.providers.users.driver' => 'kitsune-eloquent']);

        $register = static fn (AuthManager $auth): AuthManager => $auth->provider(
            'kitsune-eloquent',
            fn ($app, array $config): OrgAwareUserProvider => new OrgAwareUserProvider(
                $app['hash'],
                $config['model'],
            ),
        );

        // resolving(), so the factory is there the moment the manager is
        // built, without forcing it to be built now.
        $this->app->resolving('auth', $register);

        // ⚠️ And the already-resolved case, which resolving() does NOT
        // replay. An auto-discovered package that touches auth before this
        // provider registers leaves two distinct problems behind, and each
        // needs its own line:
        //
        //  - the manager exists without the factory, so naming the driver
        //    above gives "user provider [kitsune-eloquent] is not defined";
        //  - any guard it already built cached the DEFAULT provider, which is
        //    the unscoped one — so authentication would keep working while
        //    silently bypassing the org scope, which is worse than failing.
        if ($this->app->resolved('auth')) {
            $auth = $this->app->make('auth');

            $register($auth);
            $auth->forgetGuards();
        }
    }

    public function boot(): void
    {
        //
    }
}
