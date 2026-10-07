<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace App\Providers;

use App\Models\Reader;
use Illuminate\Support\ServiceProvider;
use Kitsune\Core\Auth\RegistersOrgAwareProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * The reader guard (ADR-037): this application's guard, provider and model, which core's sign-in pages use once a
     * site's reader accounts are switched on (`php artisan kitsune:readers mode`).
     *
     * ⚠️ HERE, NOT IN A `config/` FILE, which this skeleton deliberately does not have. `config:cache` keeps what this
     * returns, and `register()` states it again on every boot. Public so the test suite uses these exact values.
     *
     * ⚠️ `readers` IS NEVER A PANEL'S GUARD, and never passed to `RegistersOrgAwareProvider` below: a reader's org scope
     * must stay on, and `ReaderGuard` refuses a guard that a panel signs in with.
     *
     * @return array<string, mixed>
     */
    public static function readerConfig(): array
    {
        return [
            'auth.guards.readers' => ['driver' => 'session', 'provider' => 'readers'],
            'auth.providers.readers' => ['driver' => 'eloquent', 'model' => Reader::class],
            // The key by name, not `ReaderGuard::CONFIG`: this file is frozen at `create-project`, and that class is `@internal`.
            'kitsune.readers.guard' => 'readers',
        ];
    }

    public function register(): void
    {
        // `User` is org-scoped through `org_user`, and that scope fails closed
        // with no org context — which is the state authentication runs in,
        // because the org is derived from the site the user is on their way
        // to. The default Eloquent provider would match nobody and login
        // would be impossible.
        //
        // The wiring lives in core so it can be tested against an auth
        // manager that was already resolved — the branch a host provider
        // cannot reach in the normal provider order, and therefore the one
        // that would have rotted silently.
        RegistersOrgAwareProvider::on($this->app);

        config(self::readerConfig());
    }

    public function boot(): void
    {
        //
    }
}
