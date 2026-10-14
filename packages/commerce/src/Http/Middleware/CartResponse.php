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
use Kitsune\Commerce\Http\Controllers\CartController;
use Kitsune\Core\Entitlements\EntitlementUnavailable;
use Kitsune\Core\Http\Middleware\ResolveSiteFromRequest;
use Kitsune\Core\Http\Middleware\SetSiteLocale;
use Kitsune\Core\Readers\ReaderAccounts;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** The cart's private response and redacted failure boundary, including identity/site setup. @internal */
final class CartResponse
{
    public const HEADERS = CatalogueResponse::HEADERS;

    public function handle(Request $request, Closure $next): Response
    {
        try {
            CartArea::prepare($request);
            $response = app(ResolveSiteFromRequest::class)->handle($request,
                fn (Request $request): Response => app(SetSiteLocale::class)->handle($request,
                    fn (Request $request): Response => app(CartArea::class)->handle($request, $next)));
        } catch (EntitlementUnavailable $unavailable) {
            $response = self::unavailable($unavailable->state);
        } catch (QueryException $unavailable) {
            $response = self::unavailable(ReaderAccounts::sqlstate($unavailable));
        } catch (HttpExceptionInterface $refused) {
            $response = $refused->getStatusCode() === 419 && app()->getProvider(CommerceServiceProvider::class) instanceof CommerceServiceProvider
                ? app(CartController::class)->sessionExpired()
                : response('Not found', $refused->getStatusCode(), $refused->getHeaders());
        }

        foreach (self::HEADERS as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    public static function unavailable(string $state): \Illuminate\Http\Response
    {
        Log::warning('The public cart could not be read or changed.', ['sqlstate' => $state]);

        return app()->getProvider(CommerceServiceProvider::class) instanceof CommerceServiceProvider
            ? app(CartController::class)->unavailable()
            : response('Cart unavailable. Please try again shortly.', 503);
    }
}
