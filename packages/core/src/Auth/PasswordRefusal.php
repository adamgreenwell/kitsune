<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Auth;

/**
 * Why a password cannot be used — one rule set, worded by each caller in its own vocabulary: the first owner's console
 * sentences, and a reader's form.
 *
 * @internal Kitsune's own.
 */
enum PasswordRefusal
{
    /** Not valid UTF-8, which is what a form sends. */
    case Encoding;

    /** A control character, which no form sends. */
    case Control;

    /** Whitespace, or a character nobody can see, at either end. */
    case Edges;

    /** Fewer than `PasswordRules::minCharacters()` characters — never fewer than its floor. */
    case Short;

    /** More than `PasswordRules::MAX_BYTES` bytes, which bcrypt never reads. */
    case Long;

    /** The account's own email address. */
    case IsEmail;
}
