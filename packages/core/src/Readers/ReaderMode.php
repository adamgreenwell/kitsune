<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Readers;

/**
 * Which reader pages a site serves — the `reader_accounts` setting (ADR-022, ADR-037 as built).
 *
 * Identity is per org; this switch is per site and decides only which pages a site serves. `Open` on one site and
 * `SignIn` on its sibling means sign-up on the first only, and sign-in on both.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
enum ReaderMode: string
{
    /** No reader page answers: every reader route is a 404. The platform default. */
    case Off = 'off';

    /** Sign-in, sign-out, the account page and recovery. */
    case SignIn = 'sign-in';

    /** All of `SignIn`, and sign-up. */
    case Open = 'open';

    /** Whether a reader may sign in here: `sign-in` or `open`. */
    public function signsIn(): bool
    {
        return $this !== self::Off;
    }

    /** Whether someone new may create an account here: `open` only. */
    public function signsUp(): bool
    {
        return $this === self::Open;
    }
}
