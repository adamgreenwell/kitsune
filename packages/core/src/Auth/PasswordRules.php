<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Auth;

/**
 * What a password must be, for anyone who signs in to Kitsune with one: the first owner (ADR-026) and a reader
 * (ADR-037). One rule set, checked on the exact bytes in one order; each caller words the refusal itself.
 *
 * @internal Kitsune's own.
 *
 * ⚠️ EVERY RULE IS ABOUT SIGNING IN LATER. A password the sign-in form cannot send — a trailing space nobody sees, a
 * control character an arrow key typed, bytes bcrypt never reads — is a locked-out account.
 */
final class PasswordRules
{
    /** NIST SP 800-63B-4's minimum for a password that is the only factor — and here it is. */
    public const MIN_CHARACTERS = 15;

    /** bcrypt reads no further, whatever `hashing.bcrypt.limit` says. */
    public const MAX_BYTES = 72;

    /** Anything longer is refused as too long before any other rule reads it — a form can post megabytes. */
    public const READ_BOUND = 4096;

    /** Why this password cannot be used with this address, or null. Checked on the exact bytes, in this order. */
    public static function refusal(#[\SensitiveParameter] string $password, #[\SensitiveParameter] string $email): ?PasswordRefusal
    {
        if (strlen($password) > self::READ_BOUND) {
            return PasswordRefusal::Long;
        }

        if (! mb_check_encoding($password, 'UTF-8')) {
            return PasswordRefusal::Encoding;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $password) === 1) {
            return PasswordRefusal::Control;
        }

        /*
         * `Cf` too: a byte-order mark a password file was saved with, a zero-width space pasted in with it. Only at the
         * ends — inside, a zero-width joiner is part of an emoji somebody typed, and types again.
         */
        if (preg_match('/^[\s\p{Z}\p{Cf}]|[\s\p{Z}\p{Cf}]$/u', $password) === 1) {
            return PasswordRefusal::Edges;
        }

        if (mb_strlen($password, 'UTF-8') < self::MIN_CHARACTERS) {
            return PasswordRefusal::Short;
        }

        if (strlen($password) > self::MAX_BYTES) {
            return PasswordRefusal::Long;
        }

        if (mb_strtolower($password, 'UTF-8') === mb_strtolower($email, 'UTF-8')) {
            return PasswordRefusal::IsEmail;
        }

        return null;
    }
}
