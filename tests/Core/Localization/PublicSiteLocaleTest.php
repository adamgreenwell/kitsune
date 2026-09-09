<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
