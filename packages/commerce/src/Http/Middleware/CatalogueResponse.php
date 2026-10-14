<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Http\Middleware;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Kitsune\Commerce\CommerceServiceProvider;
use Kitsune\Commerce\Http\Controllers\CatalogueController;
use Kitsune\Core\Entitlements\EntitlementUnavailable;
use Kitsune\Core\Http\Middleware\ResolveSiteFromRequest;
use Kitsune\Core\Http\Middleware\SetSiteLocale;
use Kitsune\Core\Readers\ReaderAccounts;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** A personalized read never enters a shared cache, and a failed access read never becomes an answer. @internal */
final class CatalogueResponse
{
    public const HEADERS = [
        'Cache-Control' => 'no-store, private',
        'Referrer-Policy' => 'no-referrer',
        'X-Content-Type-Options' => 'nosniff',
        'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' https:; frame-ancestors 'none'; base-uri 'none'; form-action 'self'",
    ];

    public function handle(Request $request, Closure $next): Response
    {
        try {
            CatalogueArea::preparePage($request);
            // Laravel's routing pipeline renders exceptions at each slice. Nest setup directly so
            // its database faults reach this safe boundary; the controller owns its own read faults.
            $response = app(ResolveSiteFromRequest::class)->handle($request,
                fn (Request $request): Response => app(SetSiteLocale::class)->handle($request,
                    fn (Request $request): Response => app(CatalogueArea::class)->handle($request, $next)));
        } catch (EntitlementUnavailable $unavailable) {
            $response = self::unavailable($unavailable->state);
        } catch (QueryException $unavailable) {
            $response = self::unavailable(ReaderAccounts::sqlstate($unavailable));
        } catch (HttpExceptionInterface $refused) {
            $response = response('Not found', $refused->getStatusCode(), $refused->getHeaders());
        }

        foreach (self::HEADERS as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    public static function unavailable(string $state): \Illuminate\Http\Response
    {
        Log::warning('The public catalogue could not be read.', ['sqlstate' => $state]);

        return app()->getProvider(CommerceServiceProvider::class) instanceof CommerceServiceProvider
            ? app(CatalogueController::class)->unavailable()
            : response('Catalogue unavailable. Please try again shortly.', 503);
    }
}
