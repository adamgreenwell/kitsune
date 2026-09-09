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
 * ⚠️ NOT a path SEGMENT, but the query string is fair game — and the distinction is
 * ADR-019's whole point. A path segment identifies which thing you are looking at, so
 * viewer state is ineligible for one; a query parameter is state, which is exactly what
 * a UI locale is. So `?locale=fr` wins over the stored preference, and neither is a
 * third route parameter that every `getUrl()`, breadcrumb and notification would have
 * to carry.
 *
 * ⚠️ Request state only. Nothing here writes the query parameter back to the viewer's
 * stored preference, so opening someone's localized link cannot change your own setting
 * — which is the same reasoning ADR-019 uses to reject baking a sender's locale into a
 * notification URL. The
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
    /**
     * The query parameter carrying an explicit UI locale.
     *
     * Named for what Livewire's `#[Url]` attribute binds, so a component exposing the
     * choice and this middleware agree without either one guessing.
     */
    public const LOCALE_PARAMETER = 'locale';

    public function __construct(private readonly LocaleResolver $locales) {}

    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->locales->forViewer(
            $request->user(),
            app(Context::class)->site(),
            // ⚠️ ADR-019: the UI locale binds via the query string, falling back to the
            // stored preference — so an explicitly localized admin URL renders in that
            // language for whoever opens it. Without this the middleware implemented
            // persisted-only, which is a different decision than the one recorded.
            //
            // ⚠️ `query()`, not `input()`. `input()` also reads the request BODY, so a
            // POST field named `locale` — an ordinary name for a form field, including one
            // that edits this very preference — would silently redirect the chrome
            // mid-submit. The URL is the stated channel; the body is not.
            requested: $request->query(self::LOCALE_PARAMETER),
        ));

        return $next($request);
    }
}
