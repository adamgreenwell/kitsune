<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use Kitsune\Core\Auth\OrgAwareUserProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // `User` is org-scoped through `org_user`, and that scope fails
        // closed with no org context — which is the state authentication runs
        // in, because the org is derived from the site the user is on their
        // way to. The default Eloquent provider would match nobody and login
        // would be impossible.
        //
        // In register(), not boot(): the auth manager reads this config when
        // it first resolves a guard, which can happen before boot().
        config(['auth.providers.users.driver' => 'kitsune-eloquent']);
    }

    public function boot(): void
    {
        Auth::provider(
            'kitsune-eloquent',
            fn ($app, array $config): OrgAwareUserProvider => new OrgAwareUserProvider(
                $app['hash'],
                $config['model'],
            ),
        );
    }
}
