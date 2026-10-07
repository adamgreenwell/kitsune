<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Kitsune\Core\Http\Middleware\ReaderArea;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Readers\ReaderRoutes;
use Kitsune\Core\Tests\Fixtures\PanelTenancy;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;
use Kitsune\Core\Tests\Fixtures\TestReader;

/*
 * Where the reader pages are, and when they are not — ADR-037, as built. Every misconfiguration is a 404 that names
 * nothing; `kitsune:readers status` is where the operator learns why.
 */

beforeEach(function (): void {
    $this->world = ReaderFixture::world();
    ReaderFixture::mode($this->world['golfdom'], 'sign-in');
    ReaderFixture::mode($this->world['rival'], 'sign-in');
});

/**
 * Every reader page of a site, each as a request that would otherwise answer: GETs and POSTs with the body's token.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function areaPages(string $prefix): array
{
    return [
        'the account page' => ['GET', $prefix.'/account'],
        'the sign-in page' => ['GET', $prefix.'/account/sign-in'],
        'signing in' => ['POST', $prefix.'/account/sign-in'],
        'signing out' => ['POST', $prefix.'/account/sign-out'],
    ];
}

/** Each of a site's reader pages answers with this status. */
function areaAnswers(object $test, string $prefix, int $status): void
{
    foreach (areaPages($prefix) as $name => [$method, $path]) {
        $response = $method === 'GET'
            ? $test->readerGet($path)
            : $test->readerPost($path, ['email' => 'nobody@kitsune.test', 'password' => 'not-the-password-at-all']);

        expect($response->getStatusCode())->toBe($status, "{$name} ({$method} {$path})");
    }
}

/** The route a request would reach, without running it. */
function areaRouteFor(string $method, string $uri, bool $compiled = false): RoutingRoute
{
    $router = app('router');
    $routes = $router->getRoutes();

    if ($compiled) {
        $routes->refreshNameLookups();
        $compiledRoutes = $routes->compile();
        $routes = (new CompiledRouteCollection($compiledRoutes['compiled'], $compiledRoutes['attributes']))
            ->setRouter($router)
            ->setContainer(Container::getInstance());
    }

    return $routes->match(Request::create($uri, $method));
}

// ---- Routes ---------------------------------------------------------------------------------------------------------

it('marks every reader route a fallback, registered ahead of the skeleton\'s catch-all', function (): void {
    $fallbacks = array_values(array_filter(app('router')->getRoutes()->getRoutes(), static fn (RoutingRoute $route): bool => $route->isFallback));
    $uris = array_map(static fn (RoutingRoute $route): string => implode('|', $route->methods()).' '.$route->uri(), $fallbacks);
    $readers = array_filter(app('router')->getRoutes()->getRoutes(), static fn (RoutingRoute $route): bool => str_contains($route->uri(), 'account'));

    expect($readers)->toHaveCount(8)
        ->and(array_filter($readers, static fn (RoutingRoute $route): bool => ! $route->isFallback))->toBe([])
        ->and(end($uris))->toBe('GET|HEAD {fallbackPlaceholder}')
        ->and($uris)->toContain('GET|HEAD account', 'GET|HEAD account/sign-in', 'POST account/sign-in', 'POST account/sign-out')
        ->and($uris)->toContain('GET|HEAD {readerSite}/account', 'POST {readerSite}/account/sign-out');
});

it('builds the site pattern from the model\'s own constants', function (): void {
    expect(ReaderRoutes::sitePattern())->toBe(Site::PREFIX_SEGMENT_PATTERN.'(?:/'.Site::PREFIX_SEGMENT_PATTERN.'){0,'.(Site::MAX_PREFIX_SEGMENTS - 1).'}')
        ->and(areaRouteFor('GET', '/golfdom/account')->wheres)->toBe([ReaderRoutes::SITE_PARAMETER => ReaderRoutes::sitePattern()]);
});

it('lets a panel or host route win at any depth, cached or not — an entry type may be called "account"', function (bool $compiled): void {
    Route::get('admin/{tenant}/c/{type}', static fn (): string => 'panel')->name('test.panel.index');
    Route::get('admin/{tenant}', static fn (): string => 'panel')->name('test.panel.dashboard');

    expect(areaRouteFor('GET', '/admin/golfdom/c/account', $compiled)->getName())->toBe('test.panel.index')
        ->and(areaRouteFor('GET', '/admin/golfdom', $compiled)->getName())->toBe('test.panel.dashboard')
        ->and(areaRouteFor('GET', '/golfdom/account', $compiled)->uri())->toBe('{readerSite}/account')
        ->and(areaRouteFor('POST', '/golfdom/account/sign-in', $compiled)->uri())->toBe('{readerSite}/account/sign-in');
})->with(['uncached' => [false], 'compiled' => [true]]);

it('reaches a reader page under a prefix of four segments, and not five', function (): void {
    expect(areaRouteFor('GET', '/a/b/c/d/account/sign-in')->uri())->toBe('{readerSite}/account/sign-in')
        ->and(areaRouteFor('GET', '/a/b/c/d/e/account/sign-in')->uri())->toBe('{fallbackPlaceholder}');

    ReaderFixture::site($this->world['golfdom'], 'deep', 'Deep', '/news/fr/x/y');
    $this->readerGet('/news/fr/x/y/account/sign-in')->assertOk()->assertSee('<title>Sign in — Deep</title>', false);
});

it('serves a site whose prefix has two segments', function (): void {
    ReaderFixture::site($this->world['golfdom'], 'news-fr', 'News FR', '/news/fr', 'he');

    $this->readerGet('/news/fr/account/sign-in')->assertOk()->assertSee('<html lang="he" dir="rtl">', false);
});

// ---- Fail closed ----------------------------------------------------------------------------------------------------

it('serves every page of a site whose accounts are switched on', function (): void {
    $this->readerGet('/golfdom/account')->assertStatus(303)->assertHeader('Location', '/golfdom/account/sign-in');
    $this->readerGet('/golfdom/account/sign-in')->assertOk();
    $this->readerPost('/golfdom/account/sign-in', ['email' => 'nobody@kitsune.test', 'password' => 'not-the-password-at-all'])->assertStatus(422);
    $this->readerPost('/golfdom/account/sign-out')->assertStatus(303)->assertHeader('Location', '/golfdom/account/sign-in');
});

it('answers a stranger\'s path that resolves no site with 404', function (): void {
    areaAnswers($this, '/nowhere', 404);
    areaAnswers($this, '', 404);
});

it('answers 404 everywhere while accounts are off — the default', function (): void {
    ReaderFixture::mode($this->world['golfdom'], 'off');
    areaAnswers($this, '/golfdom', 404);

    // And with no mode stored anywhere: the platform default.
    $this->world['golfdom']->settings = null;
    $this->world['golfdom']->save();
    ReaderFixture::forget();
    areaAnswers($this, '/golfdom', 404);
});

it('reads a mode nothing could have stored as off', function (string $stored): void {
    // Below Eloquent, as only a hand-written query could: `SettingsGuard` refuses it on every save.
    DB::table('orgs')->where('id', $this->world['golfdom']->getKey())->update(['settings' => json_encode(['reader_accounts' => json_decode($stored)])]);
    ReaderFixture::forget();

    areaAnswers($this, '/golfdom', 404);
})->with([
    'a word it is not' => ['"yes"'],
    'upper case' => ['"OPEN"'],
    'true' => ['true'],
    'a list' => ['["open"]'],
]);

it('decides per site: a site switched off under an org that is on answers 404, and its sibling does not', function (): void {
    ReaderFixture::mode($this->world['sites']['golfdom-fr'], 'off');

    $this->readerGet('/golfdom-fr/account/sign-in')->assertNotFound();
    $this->readerGet('/golfdom/account/sign-in')->assertOk();

    ReaderFixture::mode($this->world['golfdom'], 'off');
    ReaderFixture::mode($this->world['sites']['golfdom-fr'], 'open');

    $this->readerGet('/golfdom-fr/account/sign-in')->assertOk();
    $this->readerGet('/golfdom/account/sign-in')->assertNotFound();
});

it('answers 404 while no reader guard is declared', function (): void {
    config(['kitsune.readers.guard' => null]);

    areaAnswers($this, '/golfdom', 404);
});

it('answers 404 while the guard\'s model does not implement ReaderAccount', function (): void {
    config(['auth.providers.readers.model' => TestReader::class]);

    areaAnswers($this, '/golfdom', 404);
});

it('answers 404 while the guard is not a session guard', function (): void {
    config(['auth.guards.readers.driver' => 'token']);

    areaAnswers($this, '/golfdom', 404);
});

it('answers 404 while the guard is a panel\'s', function (): void {
    PanelTenancy::enter($this->world['sites']['golfdom'])->authGuard('readers');

    areaAnswers($this, '/golfdom', 404);
});

it('answers 404 for a path whose first segment is a panel\'s, even when a site claims that prefix', function (): void {
    // The skeleton's panel is at `/admin`; this fixture's has no path until it is given one.
    PanelTenancy::enter($this->world['sites']['golfdom'])->path('admin');
    ReaderFixture::site($this->world['golfdom'], 'admin-site', 'Admin Site', '/admin');

    areaAnswers($this, '/admin', 404);
    areaAnswers($this, '/ADMIN', 404);
});

it('answers 404 inside a panel whose path has two segments', function (): void {
    PanelTenancy::enter($this->world['sites']['golfdom'])->path('cp/admin');
    ReaderFixture::site($this->world['golfdom'], 'cp-site', 'CP Site', '/cp/admin');

    areaAnswers($this, '/cp/admin', 404);
    $this->readerGet('/golfdom/account/sign-in')->assertOk();
});

it('answers 404 everywhere a panel at the root owns, and nowhere else', function (): void {
    $panel = PanelTenancy::enter($this->world['sites']['golfdom']);

    // At the root, with no domain: the whole host is the panel's.
    areaAnswers($this, '/golfdom', 404);

    // At the root of another host only.
    $panel->domain('admin.example.test');
    $this->readerGet('/golfdom/account/sign-in')->assertOk();
    $this->readerGet('https://admin.example.test/golfdom/account/sign-in')->assertNotFound();
});

it('answers a page once per site: a deeper path under a site\'s prefix is not that site\'s', function (): void {
    areaAnswers($this, '/golfdom/x', 404);
    areaAnswers($this, '/golfdom/golfdom', 404);
});

it('matches the prefix whatever its case', function (): void {
    $this->readerGet('/GOLFDOM/account/sign-in')->assertOk()->assertSee('<title>Sign in — Golfdom</title>', false);
});

it('serves a site with a host on that host only', function (): void {
    $this->readerGet('https://rival.test/shop/account/sign-in')->assertOk()->assertSee('<title>Sign in — Rival Shop</title>', false);
    $this->readerGet('https://elsewhere.test/shop/account/sign-in')->assertNotFound();
});

it('answers with headers for a page that holds a password form', function (): void {
    foreach ([
        $this->readerGet('/golfdom/account/sign-in'),
        $this->readerGet('/golfdom/account'),
        $this->readerPost('/golfdom/account/sign-in', ['email' => 'nobody@kitsune.test', 'password' => 'not-the-password-at-all']),
        $this->readerPost('/golfdom/account/sign-in', [], token: false),
    ] as $response) {
        foreach (ReaderArea::HEADERS as $name => $value) {
            $response->assertHeader($name, $value);
        }
    }

    expect(ReaderArea::HEADERS)->toBe([
        'Cache-Control' => 'no-store, private',
        'Referrer-Policy' => 'no-referrer',
        'X-Content-Type-Options' => 'nosniff',
        'X-Robots-Tag' => 'noindex',
        'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
    ]);
});
