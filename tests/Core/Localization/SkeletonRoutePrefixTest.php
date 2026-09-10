<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

/**
 * The skeleton's public route can reach every prefix the model stores, and shadows nothing.
 *
 * ⚠️ THIS ROUTE HAS BEEN WRONG THREE TIMES AND REVIEW FOUND ALL THREE. Its constraint was NARROWER
 * than `Site::canonicalPrefix()`'s vocabulary, so a site at `/.well-known` saved and returned 404.
 * Then it matched ONE segment while `Site::MAX_PREFIX_SEGMENTS` is 4, so `/news/fr` saved, was
 * resolvable, and could never be reached. Then widening it to a multi-segment pattern shadowed
 * Filament: `/admin/golfdom` matches a two-segment pattern, this file's routes are registered BEFORE
 * the panel's, and the dashboard began returning 404.
 *
 * ⚠️ THE THIRD FAILURE IS THE ONE WORTH KEEPING IN MIND, because the test I wrote for the second one
 * did not catch it. That test asserted the route file still contained the phrase "declaration order".
 * A string is not a behaviour: it passed while the admin was broken, and the browser suite is what
 * found it — and even there, only after a retry had masked it once.
 *
 * ⚠️ SO THE ROUTE HAS NO GRAMMAR AT ALL NOW. `Route::fallback()` runs only when nothing else
 * matched, so it cannot shadow the panel or anything a host application adds later, at any depth —
 * and it carries no vocabulary and no depth to drift from the model's. This file asserts that
 * absence, because a future parameterised path would reintroduce all three failures at once.
 */
$routeSource = static fn (): string => (string) file_get_contents(__DIR__.'/../../../skeleton/routes/web.php');

it('claims no path shape of its own', function () use ($routeSource): void {
    /*
     * ⚠️ ASSERTED AS AN ABSENCE, which is unusual and is the point. Every previous failure came from
     * this route describing paths — a character class, a segment count, a parameter. It should
     * describe none, because `ResolveSiteFromRequest` matches on the canonical host and path prefix a
     * site's `base_url` declares and reads the request path itself.
     */
    $source = $routeSource();

    expect($source)->toContain('Route::fallback(')
        ->and($source)->not->toContain("->get('/{site}'", 'the site route is a parameterised path again')
        ->and($source)->not->toContain("->where('site'", 'the site route carries a path grammar again');
});

it('registers the fallback with the middleware that resolves a site', function () use ($routeSource): void {
    /*
     * ⚠️ ORDER MATTERS IN THE CALL, not in the file. `fallback` lives on the Router rather than on
     * `RouteRegistrar`, so `Route::middleware(...)->fallback(...)` is a `BadMethodCallException` at
     * boot — which is how the first version of this fix failed, taking the whole application down
     * rather than just the route.
     */
    $source = $routeSource();
    $fallback = mb_strpos($source, 'Route::fallback(');

    expect($fallback)->not->toBeFalse();

    $tail = mb_substr($source, (int) $fallback);

    expect($tail)->toContain('ResolveSiteFromRequest::class')
        ->and($tail)->toContain('SetSiteLocale::class')
        ->and($tail)->toContain("->name('site.home')");
});

it('refuses the request itself when no site matches', function () use ($routeSource): void {
    /*
     * ⚠️ A FALLBACK MATCHES EVERYTHING, so the 404 has to come from the route body. The middleware
     * reports absence by leaving Context empty — it must, because most public routes are not
     * site-scoped — and a route that REQUIRES a site is the thing entitled to refuse. Without this
     * the fallback would render the placeholder for every unmatched URL in the application.
     */
    expect($routeSource())->toContain('abort_if(app(Context::class)->site() === null, 404)');
});
