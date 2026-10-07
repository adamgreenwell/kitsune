<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Http\Controllers\Readers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Kitsune\Core\Readers\ReaderForm;
use Kitsune\Core\Readers\ReaderLinks;
use Kitsune\Core\Readers\ReaderSessions;
use Kitsune\Core\Tenancy\Context;

/**
 * A reader signs out of this browser — `POST {prefix}/account/sign-out`. The body's token (419), then
 * `ReaderSessions::signOut()`, then a 303 to the sign-in page saying so. Idempotent for a guest, and not throttled.
 *
 * ⚠️ A STAFF SESSION IN THE SAME COOKIE SURVIVES, AND SO DOES ITS CSRF TOKEN — `signOut()` says why.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class SignOutController
{
    public function __invoke(Request $request, Context $context, ReaderSessions $sessions): RedirectResponse
    {
        $site = $context->site() ?? abort(404);

        abort_unless(ReaderForm::tokenMatches($request), 419);

        $sessions->signOut();
        $request->session()->flash(SignInController::STATUS, 'sign_out.done');

        return new RedirectResponse(ReaderLinks::path($site, ReaderLinks::SIGN_IN), 303);
    }
}
