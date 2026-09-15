<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Forwarding headers are trusted from a proxy on this host only, and only for the client address (ADR-034).
         *
         * ⚠️ NO env() HERE. On a web request this closure runs when the HTTP kernel is resolved, BEFORE that kernel's
         * bootstrappers load .env, so env() sees only the process environment. A feature test boots the console kernel
         * first, so env() here would pass in a test and still be null in production. The list is a constant because
         * the topology makes it one: a same-host proxy (cloudflared) connects from loopback, and a server with nothing
         * in front of PHP has a loopback peer only in its own local processes, which are trusted too (ADR-034).
         * A proxy on any other address must connect from addresses only the operator controls, append the connecting
         * address to X-Forwarded-For or overwrite it, reach the web server over TLS, and have its address resolved
         * there before PHP (nginx realip); realip fixes only the address. Cloudflare's ranges never qualify, since
         * every Cloudflare account shares them: the operator's proxied hostnames use a tunnel. ADR-034 lists every host
         * condition this line relies on.
         *
         * ⚠️ THE EXPLICIT LIST ALSO REPLACES LARAVEL'S AUTOMATIC TRUST OF EVERY PEER on *.on-forge.com and
         * *.on-vapor.com hosts and under LARAVEL_CLOUD=1. Measured against this bootstrap with an *.on-forge.com Host:
         * without it, every peer is trusted, so a client-written X-Forwarded-For entry becomes ip(). An install on
         * Laravel Cloud or Vapor reviews this line.
         *
         * ⚠️ NEVER PROTO, HOST, PORT OR PREFIX. The scheme comes from the web server, which terminates TLS on every
         * supported hostname; a trusted X-Forwarded-Proto would let any local process make a request insecure. The
         * public site is resolved from getHost() (ADR-021); a trusted X-Forwarded-Host would let whoever sets a header
         * pick the site and the root of every generated URL.
         */
        $middleware->trustProxies(
            at: ['127.0.0.1', '::1'],
            headers: Request::HEADER_X_FORWARDED_FOR,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
