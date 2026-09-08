<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Auth;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Foundation\Application;

/**
 * Wires `OrgAwareUserProvider` in, whenever the auth manager happens to exist.
 *
 * ⚠️ In core rather than in the host's service provider, and that is what
 * makes it testable. The interesting branch — an auth manager ALREADY
 * resolved before this runs — cannot be reached from a host provider in the
 * normal provider order, so deleting either line there left every test green
 * while restoring a silently unscoped provider.
 *
 * The two cases fail differently, and only one of them is loud:
 *
 * - **Manager without the factory** → "Authentication user provider
 *   [kitsune-eloquent] is not defined". Loud, and therefore harmless.
 * - **A guard already built** cached the DEFAULT provider, which is the
 *   unscoped one. Authentication keeps working while bypassing the org scope
 *   entirely, which reads as success. `forgetGuards()` discards them.
 */
final class RegistersOrgAwareProvider
{
    public const DRIVER = 'kitsune-eloquent';

    public static function on(Application $app): void
    {
        $app['config']->set('auth.providers.users.driver', self::DRIVER);

        $register = static fn (AuthManager $auth): AuthManager => $auth->provider(
            self::DRIVER,
            static fn ($container, array $config): OrgAwareUserProvider => new OrgAwareUserProvider(
                $container['hash'],
                $config['model'],
            ),
        );

        // For the manager that does not exist yet.
        $app->resolving('auth', $register);

        // And for the one that already does, which `resolving()` never
        // replays. Both halves are needed; neither covers the other.
        if ($app->resolved('auth')) {
            $auth = $app->make('auth');

            $register($auth);
            $auth->forgetGuards();
        }
    }
}
