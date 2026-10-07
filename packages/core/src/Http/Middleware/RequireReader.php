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
 * A reader page for a signed-in reader of this site's org — anyone else goes to this site's sign-in, with the page kept
 * to return to.
 *
 * ⚠️ ONLY THE READER GUARD IS ASKED. A staff session in the same cookie is not a reader. Not Laravel's `auth:` either: it
 * redirects a guest to a route named `login`, which a stock skeleton does not have.
 *
 * ⚠️ THE PAGE KEPT IS A PATH OF THIS SITE'S READER PAGES (`ReaderLinks::isOwn()`), never a URL, so a sign-in can send
 * nobody off the site.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class RequireReader
{
    public const INTENDED = 'kitsune.readers.intended';

    public function __construct(
        private readonly Context $context,
        private readonly ReaderSessions $sessions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->sessions->current() !== null) {
            return $next($request);
        }

        $site = $this->context->site();

        abort_if($site === null, 404);

        if (ReaderLinks::isOwn($site, $request->getPathInfo())) {
            $request->session()->put(self::INTENDED, $request->getPathInfo());
        }

        return new RedirectResponse(ReaderLinks::path($site, ReaderLinks::SIGN_IN), 303);
    }
}
