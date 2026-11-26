<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Http\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Kitsune\Core\Http\Middleware\ResolveSiteFromRequest;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/** @internal The skeleton calls this outside its session-backed web route group. */
final class SiteApiRoutes
{
    public static function register(): void
    {
        if (! SiteTokens::enabled()) {
            return;
        }

        $middleware = [ResolveSiteFromRequest::class, 'auth:'.SiteTokens::GUARD];
        $segment = Site::PREFIX_SEGMENT_PATTERN;
        $pattern = $segment.'(?:/'.$segment.'){0,'.(Site::MAX_PREFIX_SEGMENTS - 1).'}';

        foreach (['', '/{apiSite}'] as $prefix) {
            $api = Route::prefix($prefix.'/api/v1')->middleware($middleware);
            if ($prefix !== '') {
                $api->where(['apiSite' => $pattern]);
            }

            $api->group(static function (): void {
                Route::get('/schema', ContentSchemaController::class);
                Route::post('/media/{type}', ContentMediaController::class);
                Route::get('/entries/{type}', [ContentEntriesController::class, 'index']);
                Route::post('/entries/{type}', [ContentEntriesController::class, 'store']);
                Route::get('/entries/{type}/{id}', [ContentEntriesController::class, 'show'])->whereNumber('id');
                Route::patch('/entries/{type}/{id}', [ContentEntriesController::class, 'update'])->whereNumber('id');
            });
        }
    }

    public static function type(Request $request, Context $context, string $action): EntryType
    {
        $site = self::site($request, $context);
        $handle = $request->route('type');
        $type = EntryType::visibleFor($site, $context->orgId())->first(
            static fn (EntryType $candidate): bool => $candidate->handle === $handle,
        );
        abort_unless($type instanceof EntryType && SiteTokens::allows($request, $type->handle, $action), 404);

        return $type;
    }

    public static function site(Request $request, Context $context): Site
    {
        $site = $context->site();
        $prefix = $request->route('apiSite');
        abort_unless($site !== null && ($site->path_prefix ?? '') === ($prefix === null ? '' : '/'.$prefix), 404);

        return $site;
    }
}
