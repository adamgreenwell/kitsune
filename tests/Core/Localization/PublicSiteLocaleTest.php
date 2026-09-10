<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
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

    // ⚠️ HOST-LESS base URLs, which is what a `path` site is: this prefix on whatever host
    // serves the installation (ADR-021 amendment). The slug is the ADMIN route key and is
    // deliberately DIFFERENT from the prefix here, because the first implementation matched
    // on the slug and this is what would have caught it.
    $this->english = Site::create([
        'org_id' => $this->org->id, 'handle' => 'en', 'slug' => 'admin-en',
        'name' => 'English', 'locale' => 'en', 'url_strategy' => 'path',
        'base_url' => '/golfdom',
    ]);

    $this->arabic = Site::create([
        'org_id' => $this->org->id, 'handle' => 'ar', 'slug' => 'admin-ar',
        'name' => 'Arabic', 'locale' => 'ar', 'url_strategy' => 'path',
        'base_url' => '/golfdom-ar',
    ]);

    $this->hosted = Site::create([
        'org_id' => $this->org->id, 'handle' => 'hosted', 'slug' => 'admin-hosted',
        'name' => 'Hosted', 'locale' => 'he', 'url_strategy' => 'domain',
        'base_url' => 'https://hosted.example.test/',
    ]);

    // ⚠️ A documented THIRD strategy, which the first implementation dropped into a
    // `default => false` arm so it resolved to nothing. Under `base_url` a subdomain is
    // just a host, so it needs no case of its own — which is the point.
    $this->subdomain = Site::create([
        'org_id' => $this->org->id, 'handle' => 'sub', 'slug' => 'admin-sub',
        'name' => 'Subdomain', 'locale' => 'fa', 'url_strategy' => 'subdomain',
        'base_url' => 'https://fa.example.test',
    ]);

    // A site with NO public URL: reachable through the admin only.
    $this->private = Site::create([
        'org_id' => $this->org->id, 'handle' => 'priv', 'slug' => 'admin-priv',
        'name' => 'Private', 'locale' => 'ur',
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

    it('resolves the host whatever case or trailing dot the request uses', function (): void {
        // The request side has to canonicalise identically to the stored side, or a
        // correctly configured site silently stops resolving.
        expect(serve('https://HOSTED.example.test/'))->toBe('he')
            ->and(serve('https://hosted.example.test./'))->toBe('he');
    });
});

describe('base_url is the only address, and the slug is not one', function (): void {
    /*
     * ⚠️ THE DEFECT THIS REPLACED. The first implementation matched a `path` site against
     * its admin `slug`, so every site answered at `/{slug}` on every host — while a site
     * configured with a real `base_url` was unreachable at its own URL. The fixtures give
     * each site a slug that differs from its prefix precisely so this can fail.
     */
    it('does not serve a site from its admin slug', function (): void {
        config()->set('app.locale', 'en');
        app()->setLocale('en');

        expect(serve('http://localhost/admin-ar'))->toBe('en')
            ->and(app(Context::class)->site())->toBeNull();
    });

    it('serves a subdomain site, which is just a host', function (): void {
        // The third documented strategy, and it needs no case of its own.
        expect(serve('https://fa.example.test/'))->toBe('fa')
            ->and(app(Context::class)->site()?->handle)->toBe('sub');
    });

    it('does not serve a site that declares no public URL', function (): void {
        config()->set('app.locale', 'en');
        app()->setLocale('en');

        // `base_url` null means admin-only. Both derived columns are null, and NULLs
        // compare distinct in the unique index so any number of these coexist.
        expect(serve('http://localhost/admin-priv'))->toBe('en')
            ->and(app(Context::class)->site())->toBeNull();
    });

    it('prefers the most specific match, deterministically', function (): void {
        /*
         * ⚠️ Four configurations can match one request, and without a stated precedence the
         * winner is whichever row the database returned — so deleting an unrelated site
         * could silently change which org a URL served. Most specific first.
         */
        app(Context::class)->setOrg($this->org);
        Site::create([
            'org_id' => $this->org->id, 'handle' => 'anyhost', 'slug' => 'admin-anyhost',
            'name' => 'Any host', 'locale' => 'de', 'base_url' => '/shared',
        ]);
        Site::create([
            'org_id' => $this->org->id, 'handle' => 'thishost', 'slug' => 'admin-thishost',
            'name' => 'This host', 'locale' => 'it', 'base_url' => 'https://specific.example.test/shared',
        ]);
        app(Context::class)->forget();

        // The host-qualified one wins on its own host; the host-less one serves elsewhere.
        expect(serve('https://specific.example.test/shared'))->toBe('it')
            ->and(serve('http://localhost/shared'))->toBe('de');
    });

    it('resolves a NESTED path prefix, and prefers it over a shorter one', function (): void {
        /*
         * ⚠️ A CONFIGURED SITE THAT NO REQUEST COULD REACH. The resolver built candidates
         * from the request's FIRST segment only, so a `base_url` of `/news/fr` stored
         * `path_prefix = '/news/fr'` and was matched by nothing — not by `/news/fr/article`,
         * and not even by `/news/fr` itself. Saved, indexed, unreachable. Found by review.
         *
         * The shorter sibling is here on purpose: it proves candidates are ordered longest
         * first rather than merely that a nested prefix matches at all. Without the ordering,
         * `/news/fr` would be served by whichever of the two the database happened to return.
         */
        app(Context::class)->setOrg($this->org);
        Site::create([
            'org_id' => $this->org->id, 'handle' => 'news', 'slug' => 'admin-news',
            'name' => 'News', 'locale' => 'de', 'base_url' => '/news',
        ]);
        Site::create([
            'org_id' => $this->org->id, 'handle' => 'newsfr', 'slug' => 'admin-newsfr',
            'name' => 'News FR', 'locale' => 'fr', 'base_url' => '/news/fr',
        ]);
        app(Context::class)->forget();

        expect(serve('http://localhost/news/fr'))->toBe('fr')
            ->and(serve('http://localhost/news/fr/article-1'))->toBe('fr')
            ->and(serve('http://localhost/news'))->toBe('de')
            ->and(serve('http://localhost/news/de'))->toBe('de');
    });
});

describe('an equivalent host cannot be claimed twice', function (): void {
    /*
     * ⚠️ TWO ORGS MUST NOT BOTH OWN A HOSTNAME. `base_url` accepts equivalent spellings —
     * scheme, port, trailing slash, letter case, a trailing dot — so a uniqueness
     * constraint on `base_url` itself would let two orgs each hold what looks like a
     * distinct value and both answer on one host, with row order deciding which. That is
     * cross-org URL theft, the class ADR-021 says has no framework safety net.
     */
    it('refuses a second site whose base URL canonicalises the same', function (): void {
        $rival = Org::create(['name' => 'Rival', 'slug' => 'rival']);
        app(Context::class)->setOrg($rival);

        /*
         * Same host as `$this->hosted`, spelled four legitimate ways: a different scheme,
         * upper case, a trailing dot, and an explicit port.
         *
         * ⚠️ Caught by hand rather than with `toThrow()`, because Pest reads its second
         * argument as the EXPECTED MESSAGE — passing `''` there asserts an empty message and
         * fails against a real exception. That is how the first version of this test failed
         * while the constraint was working perfectly.
         *
         * ⚠️ EACH ATTEMPT GETS ITS OWN SAVEPOINT, and on PostgreSQL the test is broken
         * without one. A statement that fails inside a Postgres transaction aborts the WHOLE
         * transaction: every later statement returns `25P02 current transaction is aborted`
         * until a rollback, so the second spelling onwards stopped testing the constraint and
         * the poisoned connection surfaced later as an unrelated failure in
         * `RefreshDatabase`'s own migration check. SQLite and MySQL tolerate the pattern,
         * which is exactly why it survived: three of four engines agreed it was fine.
         *
         * A nested `DB::transaction()` inside `RefreshDatabase`'s transaction issues a
         * SAVEPOINT and rolls back to it, so a refused INSERT leaves the outer transaction
         * usable and every spelling is genuinely tested on every engine.
         */
        foreach (['http://hosted.example.test', 'https://HOSTED.example.test/', 'https://hosted.example.test.', 'https://hosted.example.test:8443'] as $spelling) {
            $claimed = false;

            try {
                DB::transaction(function () use ($rival, $spelling): void {
                    Site::create([
                        'org_id' => $rival->id, 'handle' => 'steal', 'slug' => 'steal-'.md5($spelling),
                        'name' => 'Steal', 'locale' => 'en', 'base_url' => $spelling,
                    ]);
                });
                $claimed = true;
            } catch (Throwable) {
                // The database refused it, which is the point.
            }

            expect($claimed)->toBeFalse("[{$spelling}] was allowed to claim a host another org holds");
        }

        app(Context::class)->forget();
    });

    it('canonicalises the spellings it accepts', function (): void {
        // The positive side: each of those spellings names the SAME host and prefix, which
        // is why the constraint above can see the collision at all.
        foreach ([
            'https://shape.example.test' => ['shape.example.test', ''],
            'http://shape.example.test/' => ['shape.example.test', ''],
            'https://SHAPE.example.test./fr/' => ['shape.example.test', '/fr'],
            '/fr' => ['', '/fr'],
            'fr' => ['', '/fr'],
            '/' => ['', ''],
        ] as $written => $expected) {
            expect(Site::deriveUrlParts($written, 'path'))->toBe($expected, "[{$written}]");
        }

        // And no public URL at all stays null in both, which is what keeps admin-only
        // sites out of the unique index.
        expect(Site::deriveUrlParts(null, 'path'))->toBe([null, null])
            ->and(Site::deriveUrlParts('  ', 'path'))->toBe([null, null]);
    });

});

describe('base_url derives the host and prefix a site claims', function (): void {
    it('reads a BARE value as a host when the strategy says the site is addressed by one', function (): void {
        /*
         * ⚠️ THE AMBIGUITY ONLY `url_strategy` CAN SETTLE, and reading it wrong was silent.
         * `x.test` and `fr` are the same shape. Judged on the string alone, a `domain` site
         * written bare was stored as host `''` with prefix `/x.test` — unreachable at
         * `https://x.test/`, and claiming `http://any-host/x.test` instead, which also
         * contradicted this project's own written promise that all three spellings of
         * `x.test` name one host. Found by review.
         */
        foreach ([
            ['x.test', 'domain', ['x.test', '']],
            ['X.Test.', 'domain', ['x.test', '']],
            ['news.x.test', 'subdomain', ['news.x.test', '']],
            ['x.test/fr', 'domain', ['x.test', '/fr']],
            // The same string under `path` is a prefix, which is the whole point.
            ['x.test', 'path', ['', '/x.test']],
            // An explicit scheme outranks the column: the operator has said where the host
            // ends, so a `path` site written as a full URL keeps its host.
            ['https://x.test/fr', 'path', ['x.test', '/fr']],
        ] as [$written, $strategy, $expected]) {
            expect(Site::deriveUrlParts($written, $strategy))->toBe($expected, "[{$written}] as {$strategy}");
        }
    });

    it('refuses dot segments, which a browser resolves away before asking', function (): void {
        /*
         * ⚠️ CROSS-ORG URL THEFT THROUGH A DOOR THE OVERLAP CHECK CANNOT SEE, found by review
         * AFTER that check landed. A browser normalises the path before sending it, so a site
         * configured as `/a/../b` is requested as `/b` — a different stored key, unrelated under
         * `prefixesOverlap()`, and served by whoever holds `/b`.
         *
         * ⚠️ An allowlist of CHARACTERS cannot close this: every character in `..` is permitted.
         * That is worth remembering while implementing #44's grammar, which rests on the same
         * idea — a construct allowlist is necessary and not sufficient.
         */
        foreach (['/a/../b', '/./b', '/..', '/a/.'] as $written) {
            expect(fn () => Site::deriveUrlParts($written, 'path'))
                ->toThrow(RuntimeException::class, 'dot segments');
        }

        // And a name that merely CONTAINS a dot is ordinary and stays legal.
        expect(Site::deriveUrlParts('/.well-known/acme', 'path'))->toBe(['', '/.well-known/acme'])
            ->and(Site::deriveUrlParts('/v1.2', 'path'))->toBe(['', '/v1.2']);
    });

    it('refuses a base_url it cannot parse, rather than silently withdrawing the address', function (): void {
        /*
         * ⚠️ `[null, null]` MEANS "deliberately no public URL", and returning it for something
         * unparseable conflated two different things. The save SUCCEEDED while withdrawing the
         * site's address: `base_url` still visibly set, resolution excluding it, no uniqueness
         * claim made, and nothing reporting any of it. Found by review.
         */
        foreach (['http://', 'https://x.test:notaport/'] as $written) {
            expect(fn () => Site::deriveUrlParts($written, 'domain'))
                ->toThrow(RuntimeException::class, 'cannot be parsed');
        }

        // An intentionally admin-only site still means both null — the case that representation
        // is actually for.
        expect(Site::deriveUrlParts(null, 'path'))->toBe([null, null])
            ->and(Site::deriveUrlParts('   ', 'path'))->toBe([null, null]);
    });

    it('refuses a percent-escaped host, which a browser resolves before asking', function (): void {
        /*
         * ⚠️ ANOTHER SPELLING OF ONE HOST, and storing it literally let two orgs claim one
         * address. `parse_url('https://%65xample.test')` keeps the host as `%65xample.test` —
         * measured — while a browser normalises it to `example.test` before sending. The two
         * passed both the unique index and the overlap check as unrelated claims, and whoever
         * held the encoded form became unreachable the moment the ordinary spelling was taken.
         *
         * ⚠️ Refused rather than decoded, for the same reason a path prefix is: `%2E` becomes a
         * label separator, so decoding lets a host gain labels it was never given. A host is the
         * outermost boundary here, and re-segmentation is the last thing to permit at it.
         */
        expect(fn () => Site::deriveUrlParts('https://%65xample.test/', 'domain'))
            ->toThrow(RuntimeException::class, 'percent-escape');

        // The ordinary spelling is untouched, so the guard costs nothing in the normal case.
        expect(Site::deriveUrlParts('https://example.test/', 'domain'))->toBe(['example.test', '']);
    });

    it('refuses a public address that is not HTTP', function (): void {
        /*
         * ⚠️ `parse_url()` ACCEPTS ANY SCHEME, and every one of them was being stored as an HTTP
         * claim. `file://example.test/news`, `javascript://example.test/news` and
         * `ftp://example.test/` all parsed with a host and saved `example.test` + `/news`, so the
         * site answered a public URL that the configured `base_url` cannot produce — the visible
         * configuration and the address actually served describing different things. Found by
         * review.
         */
        foreach (['file://example.test/news', 'javascript://example.test/x', 'ftp://example.test/'] as $wrongScheme) {
            expect(fn () => Site::deriveUrlParts($wrongScheme, 'domain'))
                ->toThrow(RuntimeException::class, 'served over HTTP');
        }

        // ⚠️ AND IT MUST NOT STEAL THE UNPARSEABLE REFUSAL. `parse_url('http://')` returns false
        // outright, so there is no scheme to object to and "this is not a URL" says more.
        expect(fn () => Site::deriveUrlParts('http://', 'domain'))
            ->toThrow(RuntimeException::class, 'cannot be parsed');

        /*
         * ⚠️ THIS TEST REPLACED ONE ASSERTING `file:///news` IS REFUSED FOR HAVING "no host at
         * all". That refusal still exists in `deriveUrlParts()` and is now SHADOWED: the scheme
         * check runs first, and I could not construct any value that reaches the host-less branch
         * once explicit addresses are restricted to http and https. It is kept rather than deleted
         * because `parse_url()`'s behaviour varies between PHP versions and the guard costs
         * nothing — but a test asserting a message nothing can produce would be worse than no test.
         */
        $case = fn (string $url, string $strategy): string => rescue(
            function () use ($url, $strategy): string {
                Site::deriveUrlParts($url, $strategy);

                return 'accepted';
            },
            fn (Throwable $e): string => $e->getMessage(),
        );

        expect($case('file:///news', 'domain'))->toContain('served over HTTP');

        // Case is not significant in a scheme, so an uppercase one is accepted rather than refused.
        expect(Site::deriveUrlParts('HTTPS://example.test/x', 'domain'))->toBe(['example.test', '/x']);

        // A host-less PATH prefix is still legitimate — that is what a path site is.
        expect(Site::deriveUrlParts('/news', 'path'))->toBe(['', '/news']);
    });

    it('refuses a scheme that names no authority', function (): void {
        /*
         * ⚠️ A ONE-SLASH TYPO BECAME A HOSTNAME. `https:/news` contains no `://`, so it was not
         * treated as an explicit URL and went on to be read as a bare address — storing a site
         * whose claimed host was the literal string `https`.
         *
         * ⚠️ A `host:port` VALUE IS UNAFFECTED, which is why the guard tests the PARSED scheme
         * rather than looking for a colon: `parse_url()` reads `example.test:8080` as a host and a
         * port with no scheme at all.
         */
        foreach (['https:/news', 'http:/x'] as $strayScheme) {
            expect(fn () => Site::deriveUrlParts($strayScheme, 'domain'))
                ->toThrow(RuntimeException::class, 'single slash');
        }

        expect(Site::deriveUrlParts('example.test:8080/news', 'domain'))->toBe(['example.test', '/news'])
            ->and(Site::deriveUrlParts('localhost:3000', 'domain'))->toBe(['localhost', '']);
    });

    it('refuses a base_url containing a control character', function (): void {
        /*
         * ⚠️ NASTIER THAN THE BACKSLASH, because what PHP produces looks entirely plausible.
         * Measured against the WHATWG URL parser, which is what a browser implements:
         *
         *   https://exa<TAB>mple.test/
         *     parse_url  host=exa_mple.test   ← an underscore, a LEGAL host character
         *     browser    host=example.test    ← stripped
         *
         * Tab, newline and carriage return all behave that way; NUL gives PHP the same underscore
         * and is rejected outright by a browser. In the PATH it is the same substitution — a tab in
         * `/news` stores the prefix `/_news` while the request arrives for `/news`.
         *
         * So the claim is on an address nobody can reach, another org can take the address the
         * operator meant without colliding, and the uniqueness and overlap checks guard a string the
         * browser never sends. Found by review.
         */
        foreach ([9, 10, 13, 0, 31, 127] as $code) {
            $hostile = 'https://exa'.chr($code).'mple.test/';

            expect(fn () => Site::deriveUrlParts($hostile, 'domain'))
                ->toThrow(RuntimeException::class, 'control character');
        }

        // ⚠️ AND IN THE PATH TOO, which the host-shaped examples above would not have caught.
        expect(fn () => Site::deriveUrlParts('https://example.test/'.chr(9).'news', 'domain'))
            ->toThrow(RuntimeException::class, 'control character');

        /*
         * ⚠️ AN UNDERSCORE AN OPERATOR ACTUALLY TYPED IS FINE, and this is the row that makes the
         * refusal a fix rather than a blanket ban: `exa_mple.test` is a legal host, a browser
         * requests it unchanged, and it is only the SUBSTITUTED underscore that lies.
         */
        expect(Site::deriveUrlParts('https://exa_mple.test/', 'domain'))->toBe(['exa_mple.test', ''])
            ->and(Site::deriveUrlParts('https://example.test/news', 'domain'))->toBe(['example.test', '/news']);
    });

    it('refuses a base_url containing a backslash', function (): void {
        /*
         * ⚠️ A BACKSLASH IS A PATH SEPARATOR TO A BROWSER AND DATA TO `parse_url()`, so the two
         * derive different addresses from one string. Measured:
         *
         *   https://example.test\@evil.test/x
         *     parse_url  host=evil.test     path=/x
         *     browser    host=example.test  path=/%5C@evil.test/x
         *
         * An operator entering that stored a claim on `evil.test` — a host they may not own, and
         * one another org could legitimately hold — while their own browser went to `example.test`.
         * Found by review.
         *
         * ⚠️ Refused rather than rewritten to `/`, because the two readings disagree about where
         * the AUTHORITY ends: "fixing" it means choosing which host the operator meant.
         */
        foreach (['https://example.test\@evil.test/x', 'https://user\@example.test/news', 'https://example.test/a\b'] as $slashed) {
            expect(fn () => Site::deriveUrlParts($slashed, 'domain'))
                ->toThrow(RuntimeException::class, 'backslash');
        }

        expect(Site::deriveUrlParts('https://example.test/news', 'domain'))->toBe(['example.test', '/news']);
    });

    it('refuses a host that survives parsing but not canonicalisation', function (): void {
        /*
         * ⚠️ AND ONE STEP PAST *THAT*, because a host being PRESENT is not the same as a host
         * being a host. `parse_url('https://./news')` returns the host `'.'` — measured, a
         * non-empty string, so the presence guard above is satisfied — and canonicalising strips
         * the trailing dot, leaving `''`: the same host-less wildcard the presence guard exists to
         * refuse, reached by a different road.
         *
         * The consequence is identical too. The site saved, answered at `/news` on every host, and
         * the unique index and overlap check both went on protecting a claim to `''` that the
         * operator never made — so the next operator to legitimately configure a path site at
         * `/news` collided with a wildcard nobody could see. Found by review of the commit that
         * added the presence guard, which is why both tests live here.
         */
        foreach (['domain', 'subdomain'] as $strategy) {
            foreach (['https://./news', 'https://../news', 'https://.'] as $address) {
                expect(fn () => Site::deriveUrlParts($address, $strategy))
                    ->toThrow(RuntimeException::class, 'reduces to nothing');
            }
        }

        // A trailing dot on a REAL host is still just that host — the root label is not the host.
        expect(Site::deriveUrlParts('https://x.test./news', 'domain'))->toBe(['x.test', '/news']);
    });

    it('refuses a numeric host in any spelling but the one a browser sends', function (): void {
        /*
         * ⚠️ ONE ADDRESS, FIVE SPELLINGS, and a browser sends exactly one. Measured through the
         * WHATWG URL parser, which is the algorithm browsers implement:
         *
         *   127.1 → 127.0.0.1        0x7f.0.0.1 → 127.0.0.1     2130706433 → 127.0.0.1
         *   010.1 → 8.0.0.1          0177.0.0.1 → 127.0.0.1
         *
         * Stored verbatim, each non-canonical spelling was a claim no request could reach — and
         * worse than merely broken, because another org holding `127.0.0.1` passed the unique index
         * as an unrelated claim and received the first operator's audience. ADR-021 records that
         * cross-org URL theft has no framework safety net, so it is refused at the boundary.
         *
         * ⚠️ Refused rather than normalised, unlike the IDN case: normalising would mean
         * reimplementing the WHATWG IPv4 parser — decimal, octal and hex labels, the last
         * absorbing the remainder — and getting that subtly wrong would CREATE an alias.
         */
        foreach (['127.1', '010.1', '0x7f.0.0.1', '2130706433', '0177.0.0.1', '1.2.3.4.5'] as $alias) {
            expect(fn () => Site::deriveUrlParts('https://'.$alias.'/', 'domain'))
                ->toThrow(RuntimeException::class, 'numeric');
        }

        // The spelling a browser actually sends is stored, so the guard costs nothing in practice.
        expect(Site::deriveUrlParts('https://127.0.0.1/news', 'domain'))->toBe(['127.0.0.1', '/news'])
            ->and(Site::deriveUrlParts('https://255.255.255.255/', 'domain'))->toBe(['255.255.255.255', '']);

        // ⚠️ And a NAME whose last label is not numeric is untouched — no DNS top-level label is
        // all digits, so this rule cannot refuse a legitimate name.
        expect(Site::deriveUrlParts('https://x1.test/', 'domain'))->toBe(['x1.test', '']);
    });

    it('refuses an IPv6 host in any spelling but the compressed one', function (): void {
        /*
         * ⚠️ SAME DEFECT, AND HERE BOTH SPELLINGS PASS THE FRAMEWORK, which makes it the cleaner
         * instance: `[0:0:0:0:0:0:0:1]`, `[0::1]` and `[::0:1]` are all requested as `[::1]`.
         *
         * ⚠️ THE IPv4-MAPPED RANGE IS REFUSED OUTRIGHT, because PHP and browsers spell it
         * differently — measured across twelve forms, `inet_ntop(inet_pton(...))` matches the
         * WHATWG serialisation everywhere except there, where PHP writes `::ffff:127.0.0.1` and a
         * browser sends `::ffff:7f00:1`. Requiring PHP's form would reject the spelling that
         * arrives and accept one that never does.
         */
        foreach (['[0:0:0:0:0:0:0:1]', '[0::1]', '[::0:1]'] as $alias) {
            expect(fn () => Site::deriveUrlParts('https://'.$alias.'/', 'domain'))
                ->toThrow(RuntimeException::class, 'one spelling a browser sends');
        }

        foreach (['[::ffff:127.0.0.1]', '[::ffff:7f00:1]'] as $mapped) {
            expect(fn () => Site::deriveUrlParts('https://'.$mapped.'/', 'domain'))
                ->toThrow(RuntimeException::class, 'IPv4-mapped');
        }

        expect(fn () => Site::deriveUrlParts('https://[notv6]/', 'domain'))
            ->toThrow(RuntimeException::class, 'not');

        // The compressed form is stored, brackets and all, because that is what `Host` carries.
        expect(Site::deriveUrlParts('https://[::1]/news', 'domain'))->toBe(['[::1]', '/news'])
            ->and(Site::deriveUrlParts('https://[2001:db8::1]/', 'domain'))->toBe(['[2001:db8::1]', '']);
    });

    it('refuses a host shape the framework answers 400 for', function (): void {
        /*
         * ⚠️ THE SYMMETRIC HALF of the request-path test below, and review found it by reading
         * that test: if `Request::getHost()` answers 400 for `x..test` before any middleware runs,
         * then storing `x..test` stores a site unreachable at the address its operator configured.
         *
         * The rule is a copy of Symfony's, and `HostValidityParityTest` asserts the copy still
         * agrees with Symfony rather than leaving that to hope.
         */
        foreach (['x..test', '.x.test', 'x test', 'x,test'] as $undeliverable) {
            expect(fn () => Site::deriveUrlParts('https://'.$undeliverable.'/', 'domain'))
                ->toThrow(RuntimeException::class, 'shape a request can carry');
        }

        /*
         * ⚠️ AND TWO SHAPES NEVER REACH THIS RULE, which the first version of this test asserted
         * wrongly: `parse_url()` resolves them before the host is examined. `https://x/test/` has
         * the host `x` and the path `/test/`, and `https://x@test/` has the host `test` with `x` as
         * userinfo. Both hosts are legitimate, so the refusal has nothing to refuse — recorded
         * because a test that expects a throw here is testing its own mistake.
         */
        expect(Site::deriveUrlParts('https://x/test/', 'domain'))->toBe(['x', '/test'])
            ->and(Site::deriveUrlParts('https://x@test/', 'domain'))->toBe(['test', '']);

        // Underscores and leading hyphens are ugly and deliverable, so they are not this rule's
        // business — the test is reachability, not taste.
        expect(Site::deriveUrlParts('https://a_b.test/', 'domain'))->toBe(['a_b.test', ''])
            ->and(Site::deriveUrlParts('https://-x.test/', 'domain'))->toBe(['-x.test', '']);
    });

    it('refuses an unknown strategy rather than reading it as a path', function (): void {
        // Fail closed and loud: a typo would otherwise store a host as a prefix and leave
        // the site unreachable at its own address, with nothing on screen to say so.
        expect(fn () => Site::deriveUrlParts('x.test', 'doamin'))
            ->toThrow(RuntimeException::class, 'Unknown url_strategy');
    });

    it('refuses a path prefix deeper than the resolver can ask for', function (): void {
        /*
         * ⚠️ The two halves share `Site::MAX_PREFIX_SEGMENTS` on purpose. The resolver builds
         * candidate prefixes from the request path and is bounded, so accepting a deeper
         * prefix here would save a site that no request could ever reach — the failure mode
         * the single-segment resolver had, moved one layer down.
         */
        $deep = '/'.implode('/', array_fill(0, Site::MAX_PREFIX_SEGMENTS + 1, 'x'));

        expect(fn () => Site::deriveUrlParts($deep, 'path'))
            ->toThrow(RuntimeException::class, 'at most');

        // And the deepest ALLOWED prefix still derives, so the bound is off-by-one correct.
        $atLimit = '/'.implode('/', array_fill(0, Site::MAX_PREFIX_SEGMENTS, 'x'));

        expect(Site::deriveUrlParts($atLimit, 'path'))->toBe(['', $atLimit]);
    });

    it('stores an internationalised host in the ASCII form a browser actually sends', function (): void {
        /*
         * ⚠️ TWO SPELLINGS OF ONE DOMAIN, and review found them counted as two claims. One org
         * configuring `https://bücher.example` and another claiming
         * `https://xn--bcher-kva.example` stored different `canonical_host` values, so neither
         * the unique index nor the overlap check saw a collision — and because a browser sends
         * the A-label in `Host`, the Unicode-configured site was unreachable at its own address
         * while the other org answered for its domain.
         *
         * ⚠️ ASSERTED BOTH WAYS ON PURPOSE. `ext-intl` is NOT a declared requirement of this
         * package and CI does not install it, so a test that simply asserted the conversion
         * would fail there. Where the extension exists the conversion is asserted; where it does
         * not, the REFUSAL is — because the alternative, storing the U-label, is the defect
         * above, and failing closed is what keeps the claim honest either way.
         */
        $unicode = 'bücher.example';
        $ascii = 'xn--bcher-kva.example';

        if (! function_exists('idn_to_ascii')) {
            expect(fn () => Site::deriveUrlParts('https://'.$unicode, 'domain'))
                ->toThrow(RuntimeException::class, 'intl extension');

            // And an ASCII host is untouched, so the guard costs nothing in the common case.
            expect(Site::deriveUrlParts('https://'.$ascii, 'domain'))->toBe([$ascii, '']);

            return;
        }

        expect(Site::deriveUrlParts('https://'.$unicode, 'domain'))->toBe([$ascii, ''])
            ->and(Site::deriveUrlParts('https://'.$ascii, 'domain'))->toBe([$ascii, '']);

        // The two spellings are therefore ONE claim, which is the whole point.
        app(Context::class)->setOrg($this->org);
        Site::create([
            'org_id' => $this->org->id, 'handle' => 'idn', 'slug' => 'admin-idn',
            'name' => 'IDN', 'locale' => 'de', 'url_strategy' => 'domain',
            'base_url' => 'https://'.$unicode,
        ]);

        $rival = Org::create(['name' => 'Rival IDN', 'slug' => 'rival-idn']);
        app(Context::class)->setOrg($rival);

        $claimed = false;

        try {
            Site::create([
                'org_id' => $rival->id, 'handle' => 'punycode', 'slug' => 'admin-punycode',
                'name' => 'Punycode', 'locale' => 'en', 'url_strategy' => 'domain',
                'base_url' => 'https://'.$ascii,
            ]);
            $claimed = true;
        } catch (Throwable) {
            // Refused, which is the point.
        }

        expect($claimed)->toBeFalse('a rival org claimed the same domain in its other spelling');

        app(Context::class)->forget();
    });

    it('refuses a public URL that OVERLAPS one another org already holds', function (): void {
        /*
         * ⚠️ THE UNIQUE INDEX WAS NOT ENOUGH, and the amendment above briefly claimed it was.
         * It compares the pair exactly, so two orgs cannot hold the SAME
         * `(canonical_host, path_prefix)` — and could still hold overlapping ones.
         *
         * Demonstrated before the fix: org A owned `https://example.test`, org B was allowed to
         * claim `https://example.test/news`, and a request to `example.test/news/article-1` on
         * org A's own hostname was served by ORG B — because the resolver prefers the longest
         * matching prefix. That is the cross-org URL theft ADR-021 says has no framework safety
         * net, reached through the front door. Found by review.
         */
        $rival = Org::create(['name' => 'Rival', 'slug' => 'rival']);

        app(Context::class)->setOrg($this->org);
        Site::create([
            'org_id' => $this->org->id, 'handle' => 'held', 'slug' => 'admin-held',
            'name' => 'Held', 'locale' => 'en', 'url_strategy' => 'domain',
            'base_url' => 'https://claimed.example.test/news',
        ]);

        // The same org may arrange its own sites however it likes, including nesting.
        Site::create([
            'org_id' => $this->org->id, 'handle' => 'nested', 'slug' => 'admin-nested',
            'name' => 'Nested', 'locale' => 'fr', 'url_strategy' => 'path',
            'base_url' => 'https://claimed.example.test/news/fr',
        ]);

        app(Context::class)->setOrg($rival);

        foreach ([
            'a deeper path under it' => 'https://claimed.example.test/news/de',
            'the host root above it' => 'https://claimed.example.test',
            'the identical claim' => 'https://claimed.example.test/news',
        ] as $what => $url) {
            $claimed = false;

            try {
                Site::create([
                    'org_id' => $rival->id, 'handle' => 'steal'.md5($url), 'slug' => 'steal'.md5($url),
                    'name' => 'Steal', 'locale' => 'de', 'url_strategy' => 'path', 'base_url' => $url,
                ]);
                $claimed = true;
            } catch (Throwable) {
                // Refused, which is the point.
            }

            expect($claimed)->toBeFalse("a rival org claimed {$what} [{$url}]");
        }

        /*
         * ⚠️ AND THE BOUNDARY, on the SAME host, because a naive `str_starts_with` would refuse
         * this too. `/newsletter` is not a path under `/news` — it is a sibling whose name merely
         * begins with the same letters, and refusing it would take expressiveness for nothing.
         */
        $sibling = Site::create([
            'org_id' => $rival->id, 'handle' => 'sibling', 'slug' => 'admin-sibling',
            'name' => 'Sibling', 'locale' => 'de', 'url_strategy' => 'path',
            'base_url' => 'https://claimed.example.test/newsletter',
        ]);

        expect($sibling->exists)->toBeTrue('a sibling prefix that is not a path prefix was refused');

        app(Context::class)->forget();
    });

    it('judges overlap by the EFFECTIVE org, not one the caller happened to pass', function (): void {
        /*
         * ⚠️ THE BLIND SPOT EVERY OTHER TEST HERE SHARES: they all pass `org_id` explicitly.
         * `EnforcesScope` stamps it from Context on `creating`, which Eloquent fires AFTER
         * `saving` — so on a create that does not name the org, `$site->org_id` is still NULL when
         * the overlap check runs. `(int) null` is `0`, which matches no org, so every rival looked
         * like a different one and an org nesting under its own host was refused as theft.
         *
         * It failed SAFE — the cross-org refusal still held — but it refused a documented,
         * legitimate arrangement, and no test could see it because none exercised the stamping
         * path. Found by review.
         */
        $rival = Org::create(['name' => 'Rival Effective', 'slug' => 'rival-effective']);

        app(Context::class)->setOrg($this->org);
        Site::create([
            'org_id' => $this->org->id, 'handle' => 'root', 'slug' => 'admin-root',
            'name' => 'Root', 'locale' => 'en', 'url_strategy' => 'domain',
            'base_url' => 'https://effective.example.test',
        ]);

        // No `org_id`: exactly how a Filament form or a seeder relying on Context creates one.
        $nested = Site::create([
            'handle' => 'nested-fr', 'slug' => 'admin-nested-fr',
            'name' => 'Nested FR', 'locale' => 'fr', 'url_strategy' => 'path',
            'base_url' => 'https://effective.example.test/fr',
        ]);

        expect($nested->exists)->toBeTrue('an org was refused a nest under its own host')
            ->and((int) $nested->org_id)->toBe((int) $this->org->id);

        // And the guard still holds for a genuine rival on the same path, also without org_id.
        app(Context::class)->setOrg($rival);

        $stolen = false;

        try {
            Site::create([
                'handle' => 'steal-effective', 'slug' => 'admin-steal-effective',
                'name' => 'Steal', 'locale' => 'de', 'url_strategy' => 'path',
                'base_url' => 'https://effective.example.test/news',
            ]);
            $stolen = true;
        } catch (Throwable) {
            // Refused, which is the point.
        }

        expect($stolen)->toBeFalse('a rival org claimed an overlapping URL via Context stamping');

        app(Context::class)->forget();
    });

    it('refuses a BULK write to the columns the derived pair comes from', function (): void {
        /*
         * ⚠️ THE `saving` HOOK IS NOT ENOUGH ON ITS OWN, which review found here.
         * `Site::query()->update(...)` dispatches no model events, so the derivation is skipped
         * and `canonical_host` keeps the OLD address: the site answers on a URL it no longer
         * declares and cannot be reached at the one it now stores. Nothing reports that.
         *
         * `RequiresModelSave` exists for exactly this — its docblock says the same defect had
         * already been found on six guards — so the fix is to name the columns rather than to
         * invent a second mechanism.
         */
        app(Context::class)->setOrg($this->org);

        $site = Site::create([
            'org_id' => $this->org->id, 'handle' => 'bulk', 'slug' => 'admin-bulk',
            'name' => 'Bulk', 'locale' => 'en', 'base_url' => 'https://bulk.example.test',
        ]);

        /*
         * ⚠️ THE DERIVED COLUMNS TOO, not only their sources, and omitting them was a hole review
         * found. `update(['canonical_host' => 'stolen.example.test'])` was ALLOWED and wrote a
         * hostname the model never declared — measured. The site then answers on a URL its own
         * `base_url` does not name, and the global uniqueness index guards that stolen value,
         * which is the cross-org URL theft ADR-021 says has no framework safety net reached
         * through the back door.
         */
        foreach ([
            'base_url' => 'https://moved.example.test',
            'url_strategy' => 'domain',
            'canonical_host' => 'stolen.example.test',
            'path_prefix' => '/stolen',
        ] as $column => $value) {
            expect(fn () => Site::query()->whereKey($site->id)->update([$column => $value]))
                ->toThrow(RuntimeException::class, 'cannot be written in bulk');
        }

        // ⚠️ Asserted on the ROW, not only on the exception: a guard that throws after writing
        // would satisfy the expectation above and still have moved the site.
        expect($site->fresh()->canonical_host)->toBe('bulk.example.test');

        // And the path that IS supported still derives, so this refuses a shape rather than
        // the operation.
        $site->base_url = 'https://moved.example.test';
        $site->save();

        expect($site->fresh()->canonical_host)->toBe('moved.example.test');

        app(Context::class)->forget();
    });
});

describe('resolution does not scale with the number of sites', function (): void {
    /*
     * ⚠️ The first implementation loaded EVERY site and compared in PHP, so every public
     * request was O(total sites) in time and memory — unbounded as an installation grows,
     * against the 1 vCPU / 1 GB floor of ADR-027.
     *
     * ⚠️ ASSERTED ON THE QUERY'S BINDINGS, NOT ITS COUNT, and counting was the mistake.
     * A full table scan is ALSO one query — `select * from sites` — so a query-count
     * assertion passed against the very scan it was written to forbid. Verified by
     * reverting the fix: the count test stayed green.
     *
     * What separates a lookup from a scan is that the DATABASE does the filtering, which
     * shows up as bound candidate values. A scan binds nothing.
     *
     * Not a timing assertion, because a timing on a loaded CI runner is a false failure
     * waiting to happen.
     */
    it('resolves with one query however many sites exist', function (): void {
        app(Context::class)->setOrg($this->org);

        for ($i = 0; $i < 25; $i++) {
            Site::create([
                'org_id' => $this->org->id, 'handle' => 'bulk'.$i, 'slug' => 'bulk-'.$i,
                'name' => 'Bulk '.$i, 'locale' => 'en', 'base_url' => '/bulk-'.$i,
            ]);
        }

        app(Context::class)->forget();

        $lookups = [];
        DB::listen(function ($query) use (&$lookups): void {
            $sql = (string) preg_replace('/[`"]/', '', $query->sql);

            if (str_starts_with($sql, 'select * from sites')) {
                $lookups[] = ['sql' => $sql, 'bindings' => count($query->bindings)];
            }
        });

        expect(serve('http://localhost/golfdom-ar'))->toBe('ar')
            ->and($lookups)->toHaveCount(1, 'site resolution should be a single query');

        /*
         * ⚠️ `toContain()` IS VARIADIC — every argument is another needle, not a failure
         * message. Passing an explanation there asserted the SQL contained that sentence,
         * so this failed against a perfectly correct query. Same trap as `toThrow()`'s
         * second argument being the expected exception message, which broke the
         * canonicalisation test above. Two Pest signatures, two assertions that looked
         * fine and tested something else.
         */
        expect($lookups[0]['sql'])->toContain('canonical_host');

        expect($lookups[0]['bindings'])
            ->toBeGreaterThanOrEqual(8, 'the four candidate host/prefix pairs should be bound, not scanned');
    });
});

describe('the application default survives a request that changed the locale', function (): void {
    it('does not inherit the previous site\'s language on a site-less request', function (): void {
        /*
         * ⚠️ `config('app.locale')` IS RUNTIME STATE, NOT A DEFAULT, and that is the defect.
         * `Application::setLocale()` does `config->set('app.locale', ...)`, so after serving an
         * Arabic site the "application default" IS Arabic — measured. Under Octane or any
         * long-lived worker, the next site-less request then inherited it while appearing to fall
         * back correctly. Found by review.
         *
         * ⚠️ The fix had to be captured EAGERLY. A lazily-resolved singleton reads the value on
         * first use, which in a worker is during the first request that needs a fallback — after
         * the pollution. Measured too: with a singleton the leak persisted unchanged.
         */
        app(Context::class)->setOrg($this->org);
        Site::create([
            'org_id' => $this->org->id, 'handle' => 'leak-ar', 'slug' => 'admin-leak-ar',
            'name' => 'Leak AR', 'locale' => 'ar', 'url_strategy' => 'path',
            'base_url' => '/leak-arabic',
        ]);
        app(Context::class)->forget();

        expect(app('kitsune.default_locale'))->toBe('en', 'the captured default is already polluted');

        /*
         * ⚠️ `Context` IS FORGOTTEN BETWEEN REQUESTS, AND THAT IS WHAT MAKES THIS FAITHFUL.
         * It is bound as `scoped`, so Octane and queue workers rebuild it per request — while
         * `config` is a singleton and genuinely survives. Reusing one Context across calls would
         * test a leak production does not have, and would hide the one it does: the first version
         * of this test failed for exactly that reason, and the failure was the test's, not the
         * code's.
         */
        $worker = function (string $uri): string {
            app(Context::class)->forget();

            return serve($uri);
        };

        // Same process, same config, three requests in sequence — what a worker actually does.
        expect($worker('http://localhost/leak-arabic'))->toBe('ar')
            ->and($worker('http://localhost/'))->toBe('en')
            ->and($worker('http://localhost/leak-arabic'))->toBe('ar');
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
    /*
     * ⚠️ THE FRAMEWORK REFUSES A MALFORMED `Host` BEFORE THIS CODE SEES IT, and this pins that,
     * because `ResolveSiteFromRequest` depends on it. `Site::canonicalHost()` throws on a
     * percent-escaped or Unicode host — right when an operator is SAVING an address, and a 500 if
     * a stranger could reach it, since anyone can put anything in a `Host` header (invariant 6).
     *
     * Review raised exactly that 500. Measurement says it cannot happen: `Request::getHost()`
     * accepts only `[a-zA-Z0-9-:\]_]+\.?` runs, so every host reaching the middleware is ASCII
     * with no `%`, which `canonicalHost()` returns early — probing all 9,261 three-character hosts
     * over an alphabet including `%`, `\0`, `é` and the delimiters found zero Symfony accepts and
     * `canonicalHost()` refuses. The refused ones are a 400.
     *
     * So the middleware has no `try` in it, and this test is what keeps that honest: if Symfony
     * ever widens its host validation, a host that throws becomes reachable and this fails.
     */
    it('is never reached by a host the framework will not accept', function (): void {
        Route::middleware([ResolveSiteFromRequest::class, SetSiteLocale::class])
            ->get('/probe', fn () => app()->getLocale());

        // ⚠️ SET ON THE HEADER, not in the URI: `Request::create()` overwrites `HTTP_HOST` from the
        // URI it parses, so passing the host there measures nothing. A client sets a header.
        $send = function (string $host, string $path = '/probe'): int|string {
            $request = Request::create('http://placeholder'.$path);
            $request->headers->set('Host', $host);
            $request->server->set('HTTP_HOST', $host);

            try {
                return app(Kernel::class)->handle($request)->getStatusCode();
            } catch (Throwable $e) {
                return 'uncaught '.$e::class;
            }
        };

        // Every spelling the framework itself rejects: a client error from it, not a 500.
        foreach (['%65xample.test', 'x.test%00', 'π.test', 'x..test', '.'] as $refused) {
            expect($send($refused))->toBe(400, "[{$refused}] was not refused upstream");
        }

        /*
         * ⚠️ AND EVERY SPELLING THE FRAMEWORK DELIVERS BUT STORAGE REFUSES MUST NOT 500 — which is
         * where I put one. I declined a `catch` here on the strength of probing all 9,261
         * three-character hosts, then added the numeric and IPv6 storage rules in the next commit;
         * those refuse seven spellings Symfony delivers happily, and a three-character corpus
         * cannot contain a bracketed IPv6 address. The middleware calls `Site::requestHost()`,
         * which normalises what it can and never throws.
         */
        foreach ([
            '2130706433', '[0:0:0:0:0:0:0:1]', '[0::1]', '[::0:1]',
            '[::ffff:127.0.0.1]', '[::ffff:7f00:1]', '[2001:0db8::1]', '[::1]', '127.0.0.1',
        ] as $deliverable) {
            expect($send($deliverable))
                ->toBe(200, "[{$deliverable}] produced a server error rather than resolving nothing");
        }

        /*
         * ⚠️ AND A HOST SYMFONY ACCEPTS RESOLVES OR DOESN'T — it never errors. `xn--a` is invalid
         * punycode, so `idn_to_ascii()` returns false for it, yet Symfony accepts every character:
         * it is the closest thing to a host that reaches `canonicalHost()` and could surprise it.
         */
        expect($send('xn--a'))->toBe(200)
            ->and($send('ok.test'))->toBe(200);
    });

    it('does not match a hostile path segment', function (): void {
        config()->set('app.locale', 'en');

        foreach (['..', '%2e%2e', 'golfdom%00', "golfdom'--"] as $hostile) {
            expect(serve('http://localhost/'.$hostile))
                ->toBe('en', "[{$hostile}] resolved a site");
        }
    });
});
