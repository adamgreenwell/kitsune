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
use Kitsune\Core\Localization\LocaleResolver;
use Kitsune\Core\Tenancy\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the site's CONTENT locale to the request.
 *
 * This is the axis that decides what language a site's output is in, and it is
 * the site's setting rather than the viewer's: a reader of `golfdom-fr` gets
 * French because that is what the site publishes, whoever they are.
 *
 * ⚠️ SEPARATE FROM `SetUiLocale`, and deliberately not merged with it. ADR-018
 * rule 2 makes the UI locale a viewer preference and the content locale a site
 * setting; they are independent and allowed to disagree. One middleware doing
 * both would have to pick a winner, and picking one is the conflation the ADR
 * names.
 *
 * ⚠️ Reads Kitsune's `Context`, not Filament's tenant (ADR-002). The public side
 * has no panel, so a middleware that asked Filament would work only where it is
 * least needed.
 *
 * ⚠️ It runs PER REQUEST because `setLocale()` is process state. Without that, a
 * multi-site install serves whichever site warmed the worker: under PHP-FPM for
 * the life of that process, under Octane until it restarts. That is the
 * structural consequence recorded in issue #38 and gap G2, and it is why this
 * cannot be a boot-time step.
 */
final class SetSiteLocale
{
    public function __construct(private readonly LocaleResolver $locales) {}

    public function handle(Request $request, Closure $next): Response
    {
        $site = app(Context::class)->site();

        app()->setLocale($this->locales->forSite($site));

        return $next($request);
    }
}
