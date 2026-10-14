<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Catalogue;

use Illuminate\Support\Facades\Route;
use Kitsune\Commerce\Http\Controllers\CatalogueController;
use Kitsune\Commerce\Http\Middleware\CatalogueResponse;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Readers\ReaderRoutes;

/**
 * The host delegates only its catalogue leaf, before its catch-all (ADR-040, #192).
 * First-party placement seam only before v1.2; no theme or extension contract.
 */
final class CatalogueRoutes
{
    public const SITE_PARAMETER = 'catalogueSite';

    public const SEGMENT = 'catalogue';

    public static function register(): void
    {
        foreach (['', '{'.self::SITE_PARAMETER.'}/'] as $prefix) {
            $route = Route::get($prefix.self::SEGMENT, CatalogueController::class)
                ->middleware(CatalogueResponse::class)
                ->fallback();

            if ($prefix !== '') {
                $route->where(self::SITE_PARAMETER, ReaderRoutes::sitePattern());
            }
        }
    }

    /** Cart and catalogue placement are separate choices, including when a real route cache is loaded. */
    public static function availableFor(Site $site): bool
    {
        $uri = ($site->path_prefix ?? '') === '' ? self::SEGMENT : '{'.self::SITE_PARAMETER.'}/'.self::SEGMENT;

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->uri() === $uri && in_array('GET', $route->methods(), true)
                && in_array($route->getActionName(), [CatalogueController::class, CatalogueController::class.'@__invoke'], true)) {
                return true;
            }
        }

        return false;
    }
}
