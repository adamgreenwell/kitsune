<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Kitsune\Core\Http\Middleware\ResolveSiteFromRequest;
use Kitsune\Core\Http\MiddlewarePriority;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;

/*
 * The site before anyone asks who is signed in — ADR-037, as built. Laravel sorts a route's middleware by the kernel's
 * priority list; core puts `ResolveSiteFromRequest` ahead of `AuthenticatesRequests` in it, so a host route
 * `[ResolveSiteFromRequest::class, 'auth:readers']` resolves the org before the reader guard loads anyone.
 */

beforeEach(function (): void {
    $this->world = ReaderFixture::world();
    $this->reader = ReaderFixture::reader($this->world['golfdom'], 'subscriber@kitsune.test');

    // A host's own members-only page, declared as `ReaderGuard`'s contract asks.
    Route::get('golfdom/members', fn (): string => 'reader '.auth('readers')->id())
        ->middleware(['web', ResolveSiteFromRequest::class, 'auth:readers']);
    Route::get('login', static fn (): string => 'the host\'s sign-in')->name('login');
});

/** The middleware a route runs, in the order it runs them. */
function priorityOrder(string $uri): array
{
    // Resolving the kernel is what hands the router its groups, aliases and priority list, as the first request would.
    app(HttpKernel::class);

    $route = app('router')->getRoutes()->match(Request::create($uri));

    return array_values(array_map(
        static fn (string $middleware): string => explode(':', $middleware)[0],
        app('router')->gatherRouteMiddleware($route),
    ));
}

/** The kernel's list without core's entry, as it was before reader accounts. */
function priorityWithoutCore(): void
{
    $kernel = app(HttpKernel::class);
    $kernel->setMiddlewarePriority(array_values(array_diff($kernel->getMiddlewarePriority(), [ResolveSiteFromRequest::class])));
}

it('resolves the site before the reader guard is asked', function (): void {
    $order = priorityOrder('/golfdom/members');

    expect(array_search(ResolveSiteFromRequest::class, $order, true))->toBeLessThan(array_search(Authenticate::class, $order, true));
});

it('lets a host\'s members-only page see a signed-in reader — and the test can tell when it would not', function (): void {
    ReaderFixture::signIn($this->reader);

    $this->readerGet('/golfdom/members')->assertOk()->assertSeeText('reader '.$this->reader->getKey());

    priorityWithoutCore();
    $order = priorityOrder('/golfdom/members');

    expect(array_search(Authenticate::class, $order, true))->toBeLessThan(array_search(ResolveSiteFromRequest::class, $order, true));

    // With no org in context the reader model's scope loads nobody, so the guard sends the reader to sign in.
    $this->readerGet('/golfdom/members')->assertRedirect('/login');
});

it('moves the resolver ahead of SubstituteBindings on the skeleton\'s own site routes, where it binds nothing', function (): void {
    foreach (['/', '/golfdom'] as $uri) {
        $order = priorityOrder($uri);

        expect(array_search(ResolveSiteFromRequest::class, $order, true))->toBeLessThan(array_search(SubstituteBindings::class, $order, true));
    }
});

it('leaves what a provider set on the router alone — a group\'s middleware, an alias', function (): void {
    $router = app('router');
    $kernel = app(HttpKernel::class);
    $router->pushMiddlewareToGroup('web', 'App\\Http\\Middleware\\HostSecurityHeaders');
    $router->aliasMiddleware('auth', 'App\\Http\\Middleware\\HostAuthenticate');

    MiddlewarePriority::placeSiteBeforeAuthentication($kernel, $router);

    expect($router->getMiddlewareGroups()['web'])->toContain('App\\Http\\Middleware\\HostSecurityHeaders')
        ->and($router->getMiddleware()['auth'])->toBe('App\\Http\\Middleware\\HostAuthenticate')
        ->and($router->middlewarePriority)->toContain(ResolveSiteFromRequest::class);
});
