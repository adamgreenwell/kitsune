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
 * Applies the VIEWER's UI locale to an admin request.
 *
 * ⚠️ The viewer's preference wins over the site's locale, which is the whole
 * point rather than an implementation detail. ADR-018 rule 2: a Swiss agency has
 * German, French and Italian editors on one org, so the language of the chrome
 * is a property of who is looking, not of what they are looking at. A French
 * editor administering an Arabic site gets a French admin around Arabic content.
 *
 * ⚠️ NOT a path segment. ADR-019 makes viewer state ineligible for the URL — the
 * URL identifies the resource, and two editors must be able to share a link to
 * the same entry without one of them changing the other's language. The
 * preference is read from the authenticated user, through Laravel's own
 * `HasLocalePreference`, so core never learns which column the app keeps it in
 * (ADR-002).
 *
 * ⚠️ It also runs PER REQUEST, for the same reason `SetSiteLocale` does, with one
 * extra edge: two editors with different preferences hitting the same worker
 * back to back. Whichever signed in first would otherwise decide the language
 * for both.
 */
final class SetUiLocale
{
    public function __construct(private readonly LocaleResolver $locales) {}

    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->locales->forViewer(
            $request->user(),
            app(Context::class)->site(),
        ));

        return $next($request);
    }
}
