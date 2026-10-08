<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Kitsune\Core\Readers\ReaderLinks;
use Kitsune\Core\Readers\ReaderSessions;
use Kitsune\Core\Tenancy\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * A page for someone not yet signed in as a reader of this site's org — a reader is sent to their account page instead.
 * A reader of ANOTHER org is a guest here (`ReaderSessions::current()` fences the org), and signing in replaces them.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class RequireGuest
{
    public function __construct(
        private readonly Context $context,
        private readonly ReaderSessions $sessions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $site = $this->context->site();

        if ($site !== null && $this->sessions->current() !== null) {
            return new RedirectResponse(ReaderLinks::path($site), 303);
        }

        return $next($request);
    }
}
