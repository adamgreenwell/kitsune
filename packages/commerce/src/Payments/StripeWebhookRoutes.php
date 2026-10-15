<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Payments;

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Kitsune\Commerce\Carts\CartRoutes;
use Kitsune\Commerce\Http\Controllers\StripeWebhookController;
use Kitsune\Core\Readers\ReaderRoutes;

/** Internal CartRoutes leaf. A host registers one canonical URL with Stripe for each Org. @internal */
final class StripeWebhookRoutes
{
    public const SUFFIX = '/cart/stripe/webhook';

    public static function register(): void
    {
        foreach (['root' => '', 'site' => '{'.CartRoutes::SITE_PARAMETER.'}/'] as $scope => $prefix) {
            $route = Route::post($prefix.ltrim(self::SUFFIX, '/'), StripeWebhookController::class)
                ->withoutMiddleware([PreventRequestForgery::class, StartSession::class, ShareErrorsFromSession::class,
                    EncryptCookies::class, AddQueuedCookiesToResponse::class])
                ->name('commerce.stripe.webhook.'.$scope)->fallback();
            if ($prefix !== '') {
                $route->where(CartRoutes::SITE_PARAMETER, ReaderRoutes::sitePattern());
            }
        }
    }
}
