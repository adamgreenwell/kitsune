<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Kitsune\Core\Http\SiteAddressResolver;
use Kitsune\Core\Tenancy\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identifies which Site a PUBLIC request is for, and puts it in Context.
 *
 * ⚠️ The public counterpart of `SetKitsuneContext`, and it cannot reuse it.
 * That one mirrors Filament's resolved tenant, which only exists inside the
 * panel; the public side has no panel (ADR-002/ADR-008 put the admin in
 * Filament, not the front end), so a public request had no way to say which
 * site it was for. That is why `sites.locale` was applied by nothing on the
 * public side — gap G2, and the half of issue #38 that middleware alone could
 * not close.
 *
 * ⚠️ THIS RESOLVES IDENTITY ONLY. It sets no locale — `SetSiteLocale` does
 * that, reading the Context this leaves behind. Two middlewares because they
 * are two concerns, and because the locale one is already used by tests that
 * have nothing to do with routing.
 *
 * ⚠️ It does NOT 404 when no site matches. A host application's public routes
 * are its own, and most of them are not site-scoped: a marketing page, a
 * health check, a webhook. Refusing the request here would make this
 * middleware unattachable to anything but a site route, and attaching it to
 * one is how an application says "a site is required". Absence is reported by
 * leaving Context empty, which `SetSiteLocale` reads as "use the application
 * default".
 */
final class ResolveSiteFromRequest
{
    public function __construct(private readonly SiteAddressResolver $addresses) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (($site = $this->addresses->resolve($request)) !== null) {
            app(Context::class)->setSite($site);
        }

        return $next($request);
    }
}
