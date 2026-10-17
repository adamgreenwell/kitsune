<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Payments;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Kitsune\Commerce\Carts\CartRoutes;
use Kitsune\Commerce\Http\Controllers\CheckoutController;
use Kitsune\Commerce\Http\Middleware\OrderResponse;
use Kitsune\Core\Readers\ReaderRoutes;

/** Internal leaves of OrderRoutes, under the existing CartRoutes host opt-in. @internal */
final class CheckoutRoutes
{
    public static function register(): void
    {
        $leaves = ['show' => ['GET', ''], 'review' => ['POST', '/review'], 'start' => ['POST', '/start'],
            'completeFree' => ['POST', '/complete-free'], 'refresh' => ['POST', '/refresh'], 'expire' => ['POST', '/expire'],
            'returned' => ['GET', '/return'], 'cancelled' => ['GET', '/cancel']];
        foreach (['root' => '', 'site' => '{'.CartRoutes::SITE_PARAMETER.'}/'] as $scope => $prefix) {
            foreach ($leaves as $action => [$method, $suffix]) {
                $route = Route::match($method === 'GET' ? ['GET', 'HEAD'] : ['POST'], $prefix.'cart/orders/{reference}/payment'.$suffix, [CheckoutController::class, $action])
                    ->middleware(OrderResponse::class)->withoutMiddleware(PreventRequestForgery::class)
                    // The controller rejects noncanonical references. A regex here would fall through to host pages.
                    ->name('commerce.payments.'.$scope.'.'.$action)->fallback();
                if ($prefix !== '') {
                    $route->where(CartRoutes::SITE_PARAMETER, ReaderRoutes::sitePattern());
                }
            }
        }
    }
}
