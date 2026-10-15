<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Orders;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Kitsune\Commerce\Carts\CartRoutes;
use Kitsune\Commerce\Http\Controllers\OrderController;
use Kitsune\Commerce\Http\Middleware\OrderResponse;
use Kitsune\Commerce\Payments\CheckoutRoutes;
use Kitsune\Commerce\Payments\StripeWebhookRoutes;
use Kitsune\Core\Readers\ReaderRoutes;

/** CartRoutes delegates its checkout/order leaves here; no public route is installed by the provider. @internal */
final class OrderRoutes
{
    public static function register(): void
    {
        $leaves = ['index' => ['GET', '/orders'], 'checkout' => ['GET', '/checkout'], 'quote' => ['POST', '/quote'], 'prepare' => ['POST', '/orders'],
            'show' => ['GET', '/orders/{reference}'], 'access' => ['GET', '/orders/{reference}/access'],
            'consume' => ['POST', '/orders/{reference}/access'], 'link' => ['POST', '/orders/{reference}/link'],
            'cancel' => ['POST', '/orders/{reference}/cancel'], 'export' => ['POST', '/orders/{reference}/export'], 'erase' => ['POST', '/orders/{reference}/erase']];
        foreach (['root' => '', 'site' => '{'.CartRoutes::SITE_PARAMETER.'}/'] as $scope => $prefix) {
            foreach ($leaves as $action => [$method, $suffix]) {
                $route = Route::match($method === 'GET' ? ['GET', 'HEAD'] : ['POST'], $prefix.'cart'.$suffix, [OrderController::class, $action])
                    ->middleware(OrderResponse::class)->withoutMiddleware(PreventRequestForgery::class)
                    ->where('reference', '[a-f0-9]{32}')->name('commerce.orders.'.$scope.'.'.$action)->fallback();
                if ($prefix !== '') {
                    $route->where(CartRoutes::SITE_PARAMETER, ReaderRoutes::sitePattern());
                }
            }
        }
        CheckoutRoutes::register();
        StripeWebhookRoutes::register();
    }
}
