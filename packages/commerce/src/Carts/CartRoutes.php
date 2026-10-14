<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Carts;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Kitsune\Commerce\CommerceServiceProvider;
use Kitsune\Commerce\Http\Controllers\CartController;
use Kitsune\Commerce\Http\Middleware\CartResponse;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Readers\ReaderRoutes;

/** A host delegates its cart leaf independently from the catalogue; first-party placement only. @internal */
final class CartRoutes
{
    public const SITE_PARAMETER = 'cartSite';

    public const SEGMENT = 'cart';

    public static function register(): void
    {
        foreach (['root' => '', 'site' => '{'.self::SITE_PARAMETER.'}/'] as $scope => $prefix) {
            foreach (['show' => '', 'add' => '/add', 'update' => '/update', 'remove' => '/remove'] as $action => $suffix) {
                $route = Route::match($action === 'show' ? ['GET', 'HEAD'] : ['POST'], $prefix.self::SEGMENT.$suffix, [CartController::class, $action])
                    ->middleware(CartResponse::class)
                    // The host check runs before route middleware and can skip tokens for same-origin metadata.
                    // This leaf owns a stricter body+session check after request sanitization, including in tests.
                    ->withoutMiddleware(PreventRequestForgery::class)
                    ->name(self::name($scope, $action))->fallback();

                if ($prefix !== '') {
                    $route->where(self::SITE_PARAMETER, ReaderRoutes::sitePattern());
                }
            }
        }
    }

    /** Cached route collections are the evidence: a process-local flag would disappear on the next boot. */
    public static function availableFor(Site $site): bool
    {
        if (! app()->getProvider(CommerceServiceProvider::class) instanceof CommerceServiceProvider) {
            return false;
        }

        $scope = ($site->path_prefix ?? '') === '' ? 'root' : 'site';

        foreach (['show', 'add', 'update', 'remove'] as $action) {
            $route = Route::getRoutes()->getByName(self::name($scope, $action));

            if (! $route instanceof RoutingRoute || $route->getActionName() !== CartController::class.'@'.$action) {
                return false;
            }
        }

        return true;
    }

    public static function path(Site $site): string
    {
        return ($site->path_prefix ?? '').'/'.self::SEGMENT;
    }

    private static function name(string $scope, string $action): string
    {
        return 'commerce.cart.'.$scope.'.'.$action;
    }
}
