<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Kitsune\Commerce\Http\Controllers\OrderController;
use Kitsune\Core\Http\Middleware\ResolveSiteFromRequest;
use Kitsune\Core\Http\Middleware\SetSiteLocale;
use Kitsune\Core\Readers\ReaderForm;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Invoice inputs and link secrets are cleared before any site resolution, refusal, previous URL or error page. @internal */
final class OrderResponse
{
    public const INPUT = 'commerce.order.input';

    public const TOKEN = 'commerce.order.csrf';

    public function handle(Request $request, Closure $next): Response
    {
        try {
            self::prepare($request);
            $response = app(ResolveSiteFromRequest::class)->handle($request,
                fn (Request $request): Response => app(SetSiteLocale::class)->handle($request,
                    fn (Request $request): Response => app(CartArea::class)->handle($request, $next)));
        } catch (HttpExceptionInterface $failure) {
            $response = response('Not found', $failure->getStatusCode());
        } catch (Throwable) {
            $response = app(OrderController::class)->unavailable();
        }
        foreach (CatalogueResponse::HEADERS as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    private static function prepare(Request $request): void
    {
        $body = $request->request->all();
        $input = [];
        foreach (['email', 'billing_name', 'billing_line1', 'billing_line2', 'billing_city', 'billing_region', 'billing_postal_code', 'billing_country',
            'different_delivery', 'delivery_name', 'delivery_line1', 'delivery_line2', 'delivery_city', 'delivery_region', 'delivery_postal_code', 'delivery_country', 'quote', 'rate'] as $key) {
            $value = $body[$key] ?? null;
            $input[$key] = is_string($value) && strlen($value) <= 4096 ? $value : null;
        }
        $page = $request->query('page', '1');
        $input['page'] = is_string($page) && strlen($page) <= 4 ? $page : null;
        $input['hasSecret'] = $request->query->has('token');
        $secret = $request->query('token');
        $input['secret'] = is_string($secret) && preg_match('/\A[a-f0-9]{64}\z/D', $secret) === 1 ? $secret : null;
        $request->attributes->set(self::INPUT, $input);
        $request->attributes->set(self::TOKEN, ReaderForm::tokenMatches($request));
        $server = $request->server->all();
        $server['REQUEST_URI'] = $request->getPathInfo();
        unset($server['QUERY_STRING']);
        $request->initialize([], [], $request->attributes->all(), $request->cookies->all(), [], $server, '');
        $request->setJson(new InputBag);
        $_GET = $_POST = $_REQUEST = [];
    }
}
