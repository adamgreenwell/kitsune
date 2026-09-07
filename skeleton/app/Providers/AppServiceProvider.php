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

        // resolving(), not a direct Auth::provider() call: this registers the
        // factory the moment the auth manager is built, whenever that is,
        // without forcing it to be built now.
        $this->app->resolving('auth', function (AuthManager $auth): void {
            $auth->provider(
                'kitsune-eloquent',
                fn ($app, array $config): OrgAwareUserProvider => new OrgAwareUserProvider(
                    $app['hash'],
                    $config['model'],
                ),
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
