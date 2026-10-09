<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Auth;

use Illuminate\Container\Container;

/**
 * What a password must be, for anyone who signs in to Kitsune with one: the first owner (ADR-026) and a reader
 * (ADR-037). One rule set, checked on the exact bytes in one order; each caller words the refusal itself.
 *
 * @internal Kitsune's own.
 *
 * ⚠️ EVERY RULE IS ABOUT SIGNING IN LATER. A password the sign-in form cannot send — a trailing space nobody sees, a
 * control character an arrow key typed, bytes bcrypt never reads — is a locked-out account.
 *
 * ⚠️ THE MINIMUM GOES UP, NEVER DOWN (Adam, 2026-10-09: "configurability, but never weak, even by manual override").
 * `kitsune.passwords.min_characters` may raise it as far as `CEILING_CHARACTERS`; nothing — a config file, an
 * environment variable, a value written past a cache — lowers it below `FLOOR_CHARACTERS`. A value below the floor, or
 * not a whole number, is never used: the floor applies, and `misconfiguration()` says so in words for the console.
 */
final class PasswordRules
{
    /** NIST SP 800-63B-4's minimum for a password that is the only factor — and here it is. No setting goes below it. */
    public const FLOOR_CHARACTERS = 15;

    /** The highest minimum a setting may ask for: 64 characters is still inside bcrypt's 72 bytes when they are ASCII. */
    public const CEILING_CHARACTERS = 64;

    /** Where an installation raises the minimum. */
    public const CONFIG = 'kitsune.passwords.min_characters';

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

        if (mb_strlen($password, 'UTF-8') < self::minCharacters()) {
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

    /** How many characters a new password needs here: the configured minimum, but never below the floor. */
    public static function minCharacters(): int
    {
        return self::read()[0];
    }

    /** Why the configured minimum is not the one in force, in words, or null when it is (or none is configured). */
    public static function misconfiguration(): ?string
    {
        return self::read()[1];
    }

    /**
     * The minimum in force, and what was wrong with the configured one.
     *
     * ⚠️ NO BOOTED APPLICATION NEEDED. `FirstOwnerCredentials` runs in a child process with nothing booted, so with no
     * `config` binding at all this is the floor — the strict answer, never an error.
     *
     * @return array{0: int, 1: ?string}
     */
    private static function read(): array
    {
        $container = Container::getInstance();
        $value = $container->bound('config') ? $container->make('config')->get(self::CONFIG) : null;

        if ($value === null) {
            return [self::FLOOR_CHARACTERS, null];
        }

        // An environment variable arrives as a string; only its digits are a whole number.
        $number = is_int($value) ? $value : (is_string($value) && preg_match('/^\d{1,3}$/D', $value) === 1 ? (int) $value : null);

        return match (true) {
            $number === null => [self::FLOOR_CHARACTERS, sprintf('%s is not a whole number, so the floor of %d characters applies', self::CONFIG, self::FLOOR_CHARACTERS)],
            $number < self::FLOOR_CHARACTERS => [self::FLOOR_CHARACTERS, sprintf('%s asks for %d characters, below the floor of %d, which applies instead — a password can be made stronger here, never weaker', self::CONFIG, $number, self::FLOOR_CHARACTERS)],
            $number > self::CEILING_CHARACTERS => [self::CEILING_CHARACTERS, sprintf('%s asks for %d characters, above the ceiling of %d, which applies instead — a longer password would not fit bcrypt\'s %d bytes', self::CONFIG, $number, self::CEILING_CHARACTERS, self::MAX_BYTES)],
            default => [$number, null],
        };
    }
}
