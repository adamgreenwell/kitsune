<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use App\Http\Controllers\WelcomeController;
use Illuminate\Support\Facades\Route;
use Kitsune\Core\Http\Middleware\ResolveSiteFromRequest;
use Kitsune\Core\Http\Middleware\SetSiteLocale;

/*
 * The public placeholder. The admin is Kitsune's first release and lives under the panel's
 * own path; a public site that renders entries is theming, which ADR-011 moved to v1.1.
 * `WelcomeController` says so on the page, and links to the admin.
 *
 * It also earns its place by being something a browser can actually assert on (ADR-024): it
 * reports that the framework boots and that kitsune/core is installed and resolvable.
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
Route::middleware([ResolveSiteFromRequest::class, SetSiteLocale::class])
    ->get('/', [WelcomeController::class, 'home'])
    ->name('home');

/*
 * A SITE-SCOPED public route, which is what `sites.locale` needed in order to mean
 * anything (issue #38, gap G2).
 *
 * ⚠️ The middleware lives in kitsune/core and the ROUTE lives here, on purpose. Core
 * registers nothing in the APPLICATION's URL space — that space is the host's, and a
 * package that claimed `/{site}` would collide with whatever the application already
 * serves there. So core supplies the mechanism and the application says where it
 * applies, which is the same division the panel uses for `SetKitsuneContext`.
 *
 * ⚠️ The panel is the one space where that is reversed, and it is reversed by the HOST.
 * Calling `KitsunePanel::apply()` hands core `/admin` to shape, which is where
 * `EntryResource` puts `/{type}/{record}/edit` and where ADR-041's media download route
 * lives. The rule is about whose URL space it is, not about the word "route" — a
 * security-critical path does not belong in the file an operator is invited to edit.
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
/*
 * ⚠️ A FALLBACK, NOT A PARAMETERISED PATH, and three attempts got here.
 *
 * `/{site}` matched ONE segment while `Site::MAX_PREFIX_SEGMENTS` is 4 and the resolver builds
 * candidates to that depth — so `base_url=https://example.test/news/fr` saved, was resolvable, and
 * could never be reached. Widening it to `{site}` with a multi-segment pattern fixed that and broke
 * the admin: `/admin/golfdom` matches a two-segment pattern, this file's routes are registered
 * BEFORE Filament's panel routes, and the dashboard started returning 404. The browser suite caught
 * it; the unit test I wrote for the widening did not, because it asserted the file still contained
 * the phrase "declaration order" rather than asserting that the admin still resolved.
 *
 * A fallback removes the question. It runs only when no other route matched, so it cannot shadow
 * the panel, or anything a host application adds later, at any depth — and it needs no vocabulary
 * and no depth of its own, which is the pair that has now drifted from the model twice.
 *
 * ⚠️ `{site}` WAS ONLY EVER A PLACEHOLDER. `ResolveSiteFromRequest` matches on the canonical host
 * and path prefix a site's `base_url` declares (ADR-021) and reads the request path itself, so
 * removing the parameter removes nothing the resolver used.
 */
// ⚠️ `Route::fallback()` FIRST, then the middleware: `fallback` lives on the Router rather than on
// `RouteRegistrar`, so `Route::middleware(...)->fallback(...)` is a BadMethodCallException at boot.
Route::fallback([WelcomeController::class, 'site'])
    ->middleware([ResolveSiteFromRequest::class, SetSiteLocale::class])
    ->name('site.home');
