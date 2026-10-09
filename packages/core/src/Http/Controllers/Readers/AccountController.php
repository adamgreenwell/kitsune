<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Http\Controllers\Readers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Kitsune\Core\Readers\ReaderLinks;
use Kitsune\Core\Readers\ReaderPage;
use Kitsune\Core\Readers\ReaderSessions;
use Kitsune\Core\Tenancy\Context;

/**
 * The signed-in reader's page — `GET {prefix}/account`, behind `RequireReader`. Who is signed in, and a way out; nothing
 * else personal. The address is shown to its own session only, as escaped text.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class AccountController
{
    /** The messages this page shows when flashed: a finished sign-up and a reset. */
    private const SHOWN = ['complete.done', 'reset.done'];

    public function __invoke(Request $request, Context $context, ReaderSessions $sessions): Response
    {
        $site = $context->site() ?? abort(404);
        $reader = $sessions->current() ?? abort(404);
        $status = $request->session()->get(SignInController::STATUS);

        return ReaderPage::render($site, 'account', 'home.title', [
            'email' => $reader->readerEmail(),
            'signOut' => ReaderLinks::path($site, ReaderLinks::SIGN_OUT),
            'status' => in_array($status, self::SHOWN, true) ? $status : null,
        ]);
    }
}
