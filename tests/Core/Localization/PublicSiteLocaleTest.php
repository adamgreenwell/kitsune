<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Http\Request;
use Kitsune\Core\Http\Middleware\ResolveSiteFromRequest;
use Kitsune\Core\Http\Middleware\SetSiteLocale;
use Kitsune\Core\Kitsune;
use Kitsune\Core\Localization\LocaleResolver;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/**
 * A PUBLIC request resolves its site and is served in that site's locale.
 *
 * ⚠️ This is the half of issue #38 that middleware alone could not close. `SetSiteLocale`
 * existed and was tested, but nothing on the public side ever put a Site in Context for
 * it to read — `SetKitsuneContext` mirrors Filament's tenant, and the public side has no
 * panel. So `sites.locale` was applied by nothing where it mattered most.
 */
beforeEach(function (): void {
    $this->org = Org::create(['name' => 'Golfdom', 'slug' => 'golfdom']);
    app(Context::class)->setOrg($this->org);

    $this->english = Site::create([
        'org_id' => $this->org->id, 'handle' => 'en', 'slug' => 'golfdom',
        'name' => 'English', 'locale' => 'en', 'url_strategy' => 'path',
    ]);

    $this->arabic = Site::create([
        'org_id' => $this->org->id, 'handle' => 'ar', 'slug' => 'golfdom-ar',
        'name' => 'Arabic', 'locale' => 'ar', 'url_strategy' => 'path',
    ]);

    $this->hosted = Site::create([
        'org_id' => $this->org->id, 'handle' => 'hosted', 'slug' => 'hosted',
        'name' => 'Hosted', 'locale' => 'he', 'url_strategy' => 'domain',
        'base_url' => 'https://hosted.example.test/',
    ]);

    // ⚠️ Forgotten deliberately. A public request arrives with NO org context — that is
    // the whole bootstrap problem `ResolveSiteFromRequest` has to solve — so a test that
    // left the org set would pass against a scoped query and prove nothing.
    app(Context::class)->forget();
});

afterEach(fn () => app(Context::class)->forget());

/** Run a public request through both middlewares and report the resulting locale. */
function serve(string $uri): string
{
    $request = Request::create($uri);

    (new ResolveSiteFromRequest)->handle(
        $request,
        fn ($passed) => (new SetSiteLocale(new LocaleResolver))->handle($passed, fn () => response('ok')),
    );

    return app()->getLocale();
}

describe('a public request resolves its site with no org context', function (): void {
    it('serves a site in its own locale', function (): void {
        expect(serve('http://localhost/golfdom'))->toBe('en')
            ->and(serve('http://localhost/golfdom-ar'))->toBe('ar');
    });

    /*
     * ⚠️ THE CRITERION THE ISSUE NAMES, and the reason this is a middleware rather than a
     * boot step: "two sites with different locales are served correctly from the same
     * process".
     *
     * `setLocale()` is process state. Resolved once per process, a multi-site install
     * serves whichever site warmed the worker — under PHP-FPM for its life, under Octane
     * until it restarts. Two requests back to back in ONE process is the smallest thing
     * that fails if that regresses, and direction is asserted alongside the tag because
     * direction is what a reader actually sees.
     */
    it('serves two sites with different locales in one process', function (): void {
        $first = serve('http://localhost/golfdom-ar');
        $second = serve('http://localhost/golfdom');
        $third = serve('http://localhost/golfdom-ar');

        expect([$first, $second, $third])->toBe(['ar', 'en', 'ar'])
            ->and(Kitsune::textDirection($first))->toBe('rtl')
            ->and(Kitsune::textDirection($second))->toBe('ltr');
    });

    /*
     * ⚠️ WITHOUT TOUCHING APP_LOCALE, which is the other half of the criterion. The app
     * default is deliberately set to something neither site uses, so a pass cannot come
     * from the environment agreeing by accident.
     */
    it('serves RTL from the site setting while the app default is LTR', function (): void {
        config()->set('app.locale', 'en');
        app()->setLocale('en');

        expect(serve('http://localhost/golfdom-ar'))->toBe('ar')
            ->and(Kitsune::textDirection())->toBe('rtl');
    });

    it('resolves a domain-addressed site from the host', function (): void {
        expect(serve('https://hosted.example.test/'))->toBe('he')
            ->and(Kitsune::textDirection())->toBe('rtl');
    });
});

describe('a site is reachable exactly one way', function (): void {
    /*
     * ⚠️ Matching host and then falling back to path would make a domain-addressed site
     * ALSO answer on `/{slug}` — the same content at two URLs, which splits analytics and
     * lets a search engine canonicalise whichever it saw first. `url_strategy` exists to
     * say which one is real, so it is consulted rather than inferred.
     */
    it('does not serve a domain site from its slug path', function (): void {
        config()->set('app.locale', 'en');
        app()->setLocale('en');

        expect(serve('http://localhost/hosted'))->toBe('en');
        expect(app(Context::class)->site())->toBeNull();
    });

    it('does not serve a path site from an unrelated host', function (): void {
        config()->set('app.locale', 'en');
        app()->setLocale('en');

        // The host matches nothing, and `golfdom` is not in the path either.
        expect(serve('https://elsewhere.example.test/'))->toBe('en');
        expect(app(Context::class)->site())->toBeNull();
    });
});

describe('an unresolvable request is left alone', function (): void {
    /*
     * ⚠️ Absence is reported, not refused. Most of a host application's public routes are
     * not site-scoped — a marketing page, a health check, a webhook — and a middleware
     * that 404'd here could only ever be attached to site routes. The route that REQUIRES
     * a site is the thing entitled to refuse, which is where the `abort_if` lives.
     */
    it('leaves Context empty and falls back to the application default', function (): void {
        config()->set('app.locale', 'en');

        expect(serve('http://localhost/no-such-site'))->toBe('en')
            ->and(app(Context::class)->site())->toBeNull();
    });

    it('handles the root path, which names no site at all', function (): void {
        config()->set('app.locale', 'en');

        expect(serve('http://localhost/'))->toBe('en')
            ->and(app(Context::class)->site())->toBeNull();
    });

    /*
     * ⚠️ A slug is untrusted input off a URL (invariant 6). It reaches a database lookup
     * rather than `setLocale()`, so the risk is different from the UI locale's — but a
     * path segment shaped like an attack must simply not match, rather than erroring.
     */
    it('does not match a hostile path segment', function (): void {
        config()->set('app.locale', 'en');

        foreach (['..', '%2e%2e', 'golfdom%00', "golfdom'--"] as $hostile) {
            expect(serve('http://localhost/'.$hostile))
                ->toBe('en', "[{$hostile}] resolved a site");
        }
    });
});

describe('a base_url is parsed, not string-compared', function (): void {
    /*
     * ⚠️ `base_url` is operator-entered, so it arrives with a scheme, a port, a trailing
     * slash or a path in whatever combination somebody typed. A raw `===` against
     * `getHost()` fails for every one of those, and the site silently stops resolving —
     * a configuration that looks right and does nothing.
     */
    it('matches whatever shape the operator typed', function (): void {
        foreach ([
            'https://shape.example.test',
            'https://shape.example.test/',
            'http://shape.example.test',
            'shape.example.test',
            'https://shape.example.test/some/path',
        ] as $i => $written) {
            app(Context::class)->setOrg($this->org);
            $site = Site::create([
                'org_id' => $this->org->id, 'handle' => 'shape'.$i, 'slug' => 'shape'.$i,
                'name' => 'Shape', 'locale' => 'fa', 'url_strategy' => 'domain', 'base_url' => $written,
            ]);
            app(Context::class)->forget();

            expect(serve('https://shape.example.test/'))->toBe('fa', "[{$written}] did not match");

            $site->forceDelete();
        }
    });
});
