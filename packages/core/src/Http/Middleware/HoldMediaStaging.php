<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Kitsune\Core\Media\MediaStaging;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hold Livewire's staging and core's disks as core set them, on every request — ADR-042 decision 4.
 *
 * @internal
 *
 * ⚠️ GLOBAL AND FIRST, BECAUSE THIS IS THE POINT NOTHING BEFORE IT CAN UNDO. It runs once the application has booted
 * and every `booted()` callback has run — a host's included, which the check at boot cannot outlast — and before the
 * router reads a route's middleware. `MediaStaging::enforce()` says what it holds and where that stops.
 */
final class HoldMediaStaging
{
    public function handle(Request $request, Closure $next): Response
    {
        MediaStaging::enforce(config());

        return $next($request);
    }
}
