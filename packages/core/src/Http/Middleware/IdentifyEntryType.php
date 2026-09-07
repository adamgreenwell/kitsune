<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Http\Middleware;

use Closure;

use function Filament\Support\original_request;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Tenancy\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * {type} is user-controlled URL input and is validated on every request.
 *
 * This is a security boundary, not a convenience. Nothing stops someone
 * hitting /admin/{site}/c/anything, so the middleware 404s a type that does
 * not exist, belongs to another org, or is disabled for the current site
 * (ADR-012 invariant 2, extended by ADR-022).
 *
 * It also sets URL::defaults(['type' => ...]). Without that, Resource::getUrl()
 * injects only tenant and record, and every Livewire update 500s with
 * "Missing required parameter: type" the moment a table renders a record link.
 *
 * NOTE original_request() is imported explicitly. It lives in Filament\Support,
 * not the global namespace, and a bare call fatals — invisibly on the initial
 * render, because ?? short-circuits, and only on the first Livewire update.
 */
final class IdentifyEntryType
{
    public function handle(Request $request, Closure $next): Response
    {
        $type = $request->route()?->parameter('type')
            ?? original_request()->route()?->parameter('type');

        if (! is_string($type) || $type === '') {
            return $next($request);
        }

        $orgId = app(Context::class)->orgId();

        $entryType = EntryType::query()
            ->where('handle', $type)
            ->where(function ($query) use ($orgId): void {
                $query->whereNull('org_id');

                if ($orgId !== null) {
                    $query->orWhere('org_id', $orgId);
                }
            })
            ->first();

        abort_if($entryType === null, 404, "Unknown entry type [{$type}].");

        // ADR-022: a type may exist and belong to this org and still be
        // disabled for this site — a French edition dropping a section the
        // English one carries. Same middleware, one more condition, same
        // security posture, because {type} remains user-controlled input.
        //
        // This docblock claimed the check existed before the check did.
        abort_unless(
            EntryTypeAvailability::isEnabledFor($entryType, app(Context::class)->site()),
            404,
            "Entry type [{$type}] is not enabled for this site."
        );

        URL::defaults(['type' => $type]);
        app()->instance(EntryType::class, $entryType);

        return $next($request);
    }
}
