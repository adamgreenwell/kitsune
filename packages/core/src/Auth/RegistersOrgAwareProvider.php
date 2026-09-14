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
use InvalidArgumentException;

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

    /**
     * Point the named user providers at the org-aware driver.
     *
     * ⚠️ NAME THE PROVIDER BEHIND THE PANEL'S AUTH GUARD, which is only `users` by convention. This rewrote
     * `auth.providers.users` unconditionally, so a host whose panel authenticates through a provider with
     * another name — which is the host's to choose, and which `Permissions::userModel()` was written to honour —
     * kept Laravel's stock provider for that guard. The user model's membership scope then matches nobody before
     * an org exists, and every sign-in failed exactly as a wrong password does. Found by installing the split
     * into a host whose panel guard used a provider called `admins`.
     *
     * ⚠️ A NAME `config/auth.php` DOES NOT DEFINE IS REFUSED. Setting a driver there would create a provider with
     * no model, which fails later and somewhere else; a misspelt name is better met here.
     */
    public static function on(Application $app, string ...$providers): void
    {
        foreach ($providers === [] ? ['users'] : $providers as $provider) {
            if (! is_array($app['config']->get("auth.providers.{$provider}"))) {
                throw new InvalidArgumentException(sprintf(
                    'There is no auth provider named [%s] to make org-aware. Name the provider the panel\'s auth '
                    .'guard uses, as `config/auth.php` defines it under `providers`.',
                    $provider,
                ));
            }

            $app['config']->set("auth.providers.{$provider}.driver", self::DRIVER);
        }

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
