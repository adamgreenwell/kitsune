<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Readers;

use Illuminate\Support\Facades\Route;
use Kitsune\Core\Http\Controllers\Readers\AccountController;
use Kitsune\Core\Http\Controllers\Readers\SignInController;
use Kitsune\Core\Http\Controllers\Readers\SignOutController;
use Kitsune\Core\Http\Middleware\ReaderArea;
use Kitsune\Core\Http\Middleware\RequireGuest;
use Kitsune\Core\Http\Middleware\RequireReader;
use Kitsune\Core\Http\Middleware\ResolveSiteFromRequest;
use Kitsune\Core\Http\Middleware\SetSiteLocale;
use Kitsune\Core\Models\Site;

/**
 * A site's reader pages, placed by the host with one line in its web routes — ADR-037, as built: the host hands core
 * `/account` under each site's prefix, as it hands core `/admin` with `KitsunePanel::apply()`.
 *
 * ```php
 * ReaderRoutes::register();   // ⚠️ before the catch-all: both are fallbacks, and the earlier one wins
 * ```
 *
 * ⚠️ EVERY ROUTE IS A FALLBACK. The host's web routes register before Filament's, so an ordinary `{readerSite}/account`
 * would answer `/admin/golfdom/c/account` — an entry type may be called `account` — and break that admin page (probed).
 * Marked as fallbacks, they run only when nothing else matched, at any depth, cached or not. A panel or a host route
 * always wins.
 *
 * ⚠️ `{readerSite}` IS A PLACEHOLDER, NOT A LOOKUP KEY, as the skeleton's catch-all says of its own. The site comes from
 * `ResolveSiteFromRequest`; `ReaderArea` then requires the parameter to be that site's prefix exactly, so each page has
 * one address per site. Its pattern is built from `Site`'s own constants, the pair that has drifted from the model twice.
 *
 * ⚠️ UNNAMED. Links are built from the site (`ReaderLinks`), never `route()`, which would build them from the request.
 *
 * Public surface under CONTRIBUTING's third exception, with `ReaderAccount` and `kitsune.readers.guard`.
 */
final class ReaderRoutes
{
    public const SITE_PARAMETER = 'readerSite';

    /** One to `Site::MAX_PREFIX_SEGMENTS` segments of `Site::PREFIX_SEGMENT_PATTERN` — any prefix a site can store. */
    public static function sitePattern(): string
    {
        return Site::PREFIX_SEGMENT_PATTERN.'(?:/'.Site::PREFIX_SEGMENT_PATTERN.'){0,'.(Site::MAX_PREFIX_SEGMENTS - 1).'}';
    }

    public static function register(): void
    {
        $area = [ResolveSiteFromRequest::class, SetSiteLocale::class, ReaderArea::class];

        foreach (['', '{'.self::SITE_PARAMETER.'}/'] as $at) {
            $routes = [
                Route::get($at.ReaderLinks::SEGMENT, AccountController::class)
                    ->middleware([...$area, RequireReader::class]),
                Route::get($at.ReaderLinks::SEGMENT.ReaderLinks::SIGN_IN, [SignInController::class, 'show'])
                    ->middleware([...$area, RequireGuest::class]),
                Route::post($at.ReaderLinks::SEGMENT.ReaderLinks::SIGN_IN, [SignInController::class, 'store'])
                    ->middleware([...$area, RequireGuest::class]),
                Route::post($at.ReaderLinks::SEGMENT.ReaderLinks::SIGN_OUT, SignOutController::class)
                    ->middleware($area),
            ];

            foreach ($routes as $route) {
                $route->fallback();

                if ($at !== '') {
                    $route->where(self::SITE_PARAMETER, self::sitePattern());
                }
            }
        }
    }
}
