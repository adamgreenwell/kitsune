<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Kitsune\Core\Http\Middleware\ResolveSiteFromRequest;
use Kitsune\Core\Http\Middleware\SetSiteLocale;
use Kitsune\Core\Kitsune;
use Kitsune\Core\Tenancy\Context;

/*
 * Placeholder front end. Kitsune has no admin panel yet - that arrives with
 * the tenancy kernel in Phase 2 and the schema engine in Phase 4.
 *
 * Until then this route earns its place by being something a browser can
 * actually assert on, which is what unblocks the Playwright job (ADR-024).
 * It reports the two facts worth proving at this stage: the framework boots,
 * and kitsune/core is installed and resolvable.
 */
/*
 * ⚠️ `SetSiteLocale` RUNS HERE TOO, even though `/` addresses no site. Review found the gap:
 * under Octane or any long-lived worker, `app()->setLocale()` persists across requests in the
 * same process, so a request to `/golfdom-ar` left the locale Arabic and a following request to
 * `/` rendered the welcome page with the previous site's `lang` and `dir`.
 *
 * The middleware resolves absence as "use the application default", so attaching it to a
 * site-less route is exactly how the locale gets RESET rather than inherited. `ResolveSiteFromRequest`
 * comes first for the same reason it does below: it reports absence by leaving Context empty,
 * which is what `SetSiteLocale` then reads.
 */
Route::middleware([ResolveSiteFromRequest::class, SetSiteLocale::class])->get('/', function () {
    return response()->view('welcome', [
        'version' => Kitsune::version(),
        'phase' => 'Phase 0 — foundations',
        // ⚠️ The page emitted `lang` and no `dir`, so an RTL locale served
        // RTL text in a left-to-right document. Filament supplies this for
        // the admin from its own translations; the public side has no panel
        // and needs Kitsune's own answer (ADR-018).
        'direction' => Kitsune::textDirection(),
    ]);
})->name('home');

/*
 * A SITE-SCOPED public route, which is what `sites.locale` needed in order to mean
 * anything (issue #38, gap G2).
 *
 * ⚠️ The middleware lives in kitsune/core and the ROUTE lives here, on purpose. Core
 * registers no routes at all — a host application's URL space is its own, and a package
 * that claimed `/{site}` would collide with whatever the application already serves
 * there. So core supplies the mechanism and the application says where it applies, which
 * is the same division the panel uses for `SetKitsuneContext`.
 *
 * ⚠️ REGISTERED LAST, because `{site}` matches one segment of anything. Laravel resolves
 * in declaration order, so `/` above and every route Filament registers for `/admin` are
 * already claimed by the time this is reached. Declaring it earlier would swallow the
 * admin.
 *
 * ⚠️ The `{site}` segment is a PLACEHOLDER, not the lookup key. `ResolveSiteFromRequest`
 * matches on the canonical host and path prefix a site's `base_url` declares (ADR-021), so
 * this parameter exists only to let one route shape accept a one-segment prefix. Using it
 * as the key is what the first version did, and it exposed every site at `/{slug}` on every
 * host while leaving a properly configured site unreachable.
 *
 * ⚠️ This is NOT the front end. Phase 6 owns menus, routing, slugs and redirects; this
 * route renders the same placeholder as `/` and exists to prove one thing that could not
 * be proved before — that a public request resolves a Site and is served in that site's
 * locale, per request, without touching APP_LOCALE.
 */
Route::middleware([ResolveSiteFromRequest::class, SetSiteLocale::class])
    ->get('/{site}', function () {
        // ⚠️ 404 HERE rather than in the middleware. The middleware resolves identity and
        // reports absence by leaving Context empty, because most public routes are not
        // site-scoped and it must be attachable to them. A route that REQUIRES a site is
        // the thing entitled to refuse.
        abort_if(app(Context::class)->site() === null, 404);

        return response()->view('welcome', [
            'version' => Kitsune::version(),
            'phase' => 'Phase 0 — foundations',
            // Resolved from the SITE's locale by the middleware above, so two sites with
            // different locales are served correctly from one process.
            'direction' => Kitsune::textDirection(),
        ]);
    })
    ->where('site', '[a-z0-9-]+')
    ->name('site.home');
