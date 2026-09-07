<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mirrors Filament's resolved tenant into Kitsune's own Context.
 *
 * Two context objects exist on purpose. Filament's tenant is a panel concept
 * and reaches only what the panel touches; Kitsune's Context is what the
 * global scopes read, and it must also work for the REST API, console
 * commands and queued jobs, where Filament is not involved at all (ADR-002).
 *
 * This runs inside tenantMiddleware, so by the time it executes Filament has
 * identified the tenant — which is the earliest point the scopes can safely
 * be given something to enforce.
 */
final class SetKitsuneContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        if ($tenant instanceof Site) {
            app(Context::class)->setSite($tenant);
        }

        return $next($request);
    }
}
