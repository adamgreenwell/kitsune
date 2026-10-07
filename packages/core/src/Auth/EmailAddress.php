<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Auth;

use Illuminate\Support\Facades\Validator;

/**
 * An email address a sign-in form accepts — in the browser and on the server — and its one stored spelling.
 *
 * @internal Kitsune's own: the first owner's address (ADR-026) and a reader's (ADR-037).
 *
 * ⚠️ ONE GRAMMAR, TWO CALLERS. The first owner's address is stored as typed, as it always was; a reader's is lower-cased
 * on every write and every lookup, so the HTML grammar's ASCII alphabet leaves nothing MySQL's or MariaDB's collations
 * fold — case, accents, trailing pad space — that could make two accepted values compare equal on one engine and not on
 * another.
 */
final class EmailAddress
{
    /** Anything longer is refused before any rule reads it. */
    public const READ_BOUND = 4096;

    /**
     * The HTML standard's own grammar for `type="email"`, which the sign-in form's field is: an address the browser
     * refuses to submit is one its owner can never sign in with, whatever the server would accept. `D`: no match across
     * a trailing newline.
     */
    private const HTML_EMAIL = '/^[a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/D';

    /** Whether a form would accept this address: the browser's grammar and Laravel's `email|max:255`. */
    public static function isAccepted(#[\SensitiveParameter] string $typed): bool
    {
        return strlen($typed) <= self::READ_BOUND
            && preg_match(self::HTML_EMAIL, $typed) === 1
            && Validator::make(['email' => $typed], ['email' => ['required', 'string', 'email', 'max:255']])->passes();
    }

    /** The one spelling a reader's address is stored and looked up by — lower-case ASCII — or null when it is none. */
    public static function normalise(#[\SensitiveParameter] string $typed): ?string
    {
        return self::isAccepted($typed) ? strtolower($typed) : null;
    }
}
