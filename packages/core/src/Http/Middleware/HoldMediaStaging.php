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
use Illuminate\Support\Lottery;
use Kitsune\Core\Media\MediaStaging;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

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

    /**
     * Sweep the intake disk after the response, by lottery — `MediaStaging::SWEEP_ODDS`.
     *
     * ⚠️ BECAUSE THE SWEEP AFTER AN UPLOAD LEAVES THE LAST BATCH — Codex, #152. It removes what is stale when someone
     * next uploads, so files staged by an uploader who then stopped would wait for an upload that may never come, and
     * nothing requires a scheduler. Any request may draw the sweep instead, after its response has gone. A sweep that
     * fails is reported, never thrown: the request it follows has been answered.
     */
    public function terminate(Request $request, Response $response): void
    {
        Lottery::odds(...MediaStaging::SWEEP_ODDS)
            ->winner(static function (): void {
                try {
                    MediaStaging::sweep();
                } catch (Throwable $failed) {
                    report($failed);
                }
            })
            ->choose();
    }
}
