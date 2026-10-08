<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Http;

use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Routing\Router;
use Kitsune\Core\Http\Middleware\ResolveSiteFromRequest;

/**
 * The site before anyone asks who is signed in — ADR-037, as built.
 *
 * Laravel sorts a route's middleware by the kernel's priority list, which holds `AuthenticatesRequests` and not the
 * resolver, so a host route declared `[ResolveSiteFromRequest::class, 'auth:readers']` ran the guard first — with no
 * org in context, the reader model's org scope loaded nobody. With the resolver ahead of it, `ReaderGuard`'s promise
 * holds. On the skeleton's own site routes it also moves the resolver ahead of `SubstituteBindings`, which binds nothing
 * there.
 *
 * ⚠️ THE PRIORITY LIST AND NOTHING ELSE (review). The kernel's own method ends by copying every middleware group and
 * alias it knows onto the router, which silently undid what an earlier provider had set on the router directly — a
 * middleware pushed into `web`, an `auth` alias replaced. So the router's groups and aliases are put back as they were.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class MiddlewarePriority
{
    public static function placeSiteBeforeAuthentication(HttpKernel $kernel, Router $router): void
    {
        if (! method_exists($kernel, 'addToMiddlewarePriorityBefore')) {
            return;
        }

        $groups = $router->getMiddlewareGroups();
        $aliases = $router->getMiddleware();

        $kernel->addToMiddlewarePriorityBefore(AuthenticatesRequests::class, ResolveSiteFromRequest::class);

        foreach ($groups as $name => $middleware) {
            $router->middlewareGroup($name, $middleware);
        }

        foreach ($aliases as $name => $class) {
            $router->aliasMiddleware($name, $class);
        }
    }
}
