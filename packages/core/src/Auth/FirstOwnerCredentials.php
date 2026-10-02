<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Auth;

use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Symfony\Component\Console\Exception\MissingInputException;
use Symfony\Component\Console\Exception\RuntimeException as ConsoleRuntimeException;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The first owner's address and password, read and checked before anything is written — ADR-026, as amended.
 *
 * @internal Kitsune's own: `kitsune:blueprint apply --owner` reads through it today, and Phase 6's onboarding is its
 *           second caller.
 *
 * ⚠️ THE PASSWORD IS TYPED, OR PIPED, AND NOTHING ELSE. Never an argument or an environment variable, which other local
 * users read through `ps` and `/proc` and which shell history keeps; never generated, because a generated value has to
 * be printed to be used. So it comes from a hidden prompt, twice, or from the first line of standard input — and a
 * terminal that cannot hide what is typed is refused rather than allowed to show it.
 *
 * ⚠️ EVERY RULE IS ABOUT SIGNING IN LATER. There is no password reset yet, and no second factor, so a password the
 * sign-in form cannot send — a trailing space nobody sees, a control character an arrow key typed, bytes bcrypt never
 * reads — is a locked-out owner, and the only one this installation has.
 *
 * ⚠️ NO CONTAINER BUT THE EMAIL CHECK, so the terminal tests can run it in a child process with nothing booted.
 */
final class FirstOwnerCredentials
{
    /** NIST SP 800-63B-4's minimum for a password that is the only factor — and here it is. */
    public const MIN_CHARACTERS = 15;

    /** bcrypt reads no further, whatever `hashing.bcrypt.limit` says. */
    public const MAX_BYTES = 72;

    /** How much of a line is read from standard input at most; anything this long is refused by `MAX_BYTES` anyway. */
    public const READ_BOUND = 4096;

    public const PROMPT = 'Choose a password for %s, the first owner (hidden; at least 15 characters)';

    public const CONFIRM = 'Type the same password again (hidden)';

    /**
     * The HTML standard's own grammar for `type="email"`, which the sign-in form's field is: an address the browser
     * refuses to submit is one its owner can never sign in with, whatever the server would accept. `D`: no match across
     * a trailing newline.
     */
    private const HTML_EMAIL = '/^[a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/D';

    /**
     * A hidden question, refused rather than shown where it cannot be hidden, and kept exactly as typed.
     *
     * ⚠️ NOT `secret()`. Laravel's turns the hidden fallback ON, and the fallback is to read the password visibly; and
     * trimming would store a different password from the one the sign-in form sends, which keeps surrounding spaces.
     */
    public static function question(string $prompt): Question
    {
        return (new Question($prompt))->setHidden(true)->setHiddenFallback(false)->setTrimmable(false);
    }

    /**
     * Ask twice, hidden: the first answer is checked before the second is asked for, so a refused password is never typed twice.
     *
     * @throws RuntimeException
     */
    public static function ask(SymfonyStyle $io, string $email): string
    {
        try {
            $first = self::answered($io->askQuestion(self::question(sprintf(self::PROMPT, $email))));
            self::refuse(self::passwordRefusal($first, $email));

            $second = self::answered($io->askQuestion(self::question(self::CONFIRM)));
        } catch (MissingInputException) {
            /* First: it is a console RuntimeException too, and an answer that never came is not a terminal that cannot hide one. */
            throw new RuntimeException('No password was entered. Nothing was written.');
        } catch (ConsoleRuntimeException) {
            throw new RuntimeException(
                'Refusing to ask for the password: this terminal cannot hide what is typed, so it would be shown. Pipe it in '
                .'with `--owner-password-stdin` instead. Nothing was written.'
            );
        }

        if (! hash_equals($first, $second)) {
            throw new RuntimeException('The two passwords did not match. Nothing was written.');
        }

        return $first;
    }

    /**
     * The first line of a stream, which must not be a terminal.
     *
     * @param  resource  $stream
     *
     * @throws RuntimeException
     */
    public static function fromStream($stream, string $email): string
    {
        /* Before reading: a terminal would show the password as it is typed. */
        if (@stream_isatty($stream)) {
            throw new RuntimeException(
                'Refusing `--owner-password-stdin`: standard input is a terminal, where the password would be shown as it '
                .'is typed. Leave the option off to be asked at a hidden prompt, or pipe the password in. Nothing was written.'
            );
        }

        $line = fgets($stream, self::READ_BOUND + 1);
        $password = $line === false ? '' : self::withoutLineEnd($line);

        if ($password === '') {
            throw new RuntimeException(
                'Refusing `--owner-password-stdin`: standard input had no password on its first line. Under `docker run`, '
                .'pass `-i` — without it a container\'s input is closed. Nothing was written.'
            );
        }

        self::refuse(self::passwordRefusal($password, $email));

        return $password;
    }

    /** One line ending off, and only one: `"\r\n"`, else `"\n"`. Anything else is part of the password. */
    public static function withoutLineEnd(#[\SensitiveParameter] string $line): string
    {
        if (str_ends_with($line, "\r\n")) {
            return substr($line, 0, -2);
        }

        return str_ends_with($line, "\n") ? substr($line, 0, -1) : $line;
    }

    /** Why this password cannot be the first owner's, or null. Checked on the exact bytes, in this order. */
    public static function passwordRefusal(#[\SensitiveParameter] string $password, string $email): ?string
    {
        if (! mb_check_encoding($password, 'UTF-8')) {
            return 'The password is not valid UTF-8, which is what the sign-in form sends, so it could never be typed there. '
                .'Check this terminal\'s locale. Nothing was written.';
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $password) === 1) {
            return 'The password contains a control character — a Tab, or an arrow key pressed at the hidden prompt, types '
                .'one — which the sign-in form cannot send. Nothing was written.';
        }

        if (preg_match('/^[\s\p{Z}]|[\s\p{Z}]$/u', $password) === 1) {
            return 'The password begins or ends with whitespace. The sign-in form sends it exactly as typed, a space nobody '
                .'can see is one nobody types again, and there is no password reset yet. Nothing was written.';
        }

        if (mb_strlen($password, 'UTF-8') < self::MIN_CHARACTERS) {
            return 'The password is shorter than 15 characters. Kitsune has no second factor yet, so the password is all '
                .'that protects the owner of this installation. Nothing was written.';
        }

        if (strlen($password) > self::MAX_BYTES) {
            return 'The password is longer than 72 bytes. bcrypt reads no further, so everything after the 72nd byte would '
                .'protect nothing. Nothing was written.';
        }

        if (mb_strtolower($password, 'UTF-8') === mb_strtolower($email, 'UTF-8')) {
            return 'The password is the owner\'s email address. Nothing was written.';
        }

        return null;
    }

    /**
     * Why this cannot be the first owner's address, or null: it must be one the sign-in form accepts, in the browser and
     * on the server. Stored as typed — sign-in compares it exactly on two of the four engines.
     */
    public static function emailRefusal(string $email): ?string
    {
        $accepted = preg_match(self::HTML_EMAIL, $email) === 1
            && Validator::make(['email' => $email], ['email' => ['required', 'string', 'email', 'max:255']])->passes();

        return $accepted ? null : 'Refusing `--owner`: that is not an email address the sign-in form accepts — both the '
            .'browser\'s check and the server\'s — so its owner could never sign in. The value is not repeated here, in '
            .'case it was something other than an address. Nothing was written.';
    }

    private static function answered(#[\SensitiveParameter] mixed $answer): string
    {
        $password = is_string($answer) ? self::withoutLineEnd($answer) : '';

        if ($password === '') {
            throw new RuntimeException('No password was entered. Nothing was written.');
        }

        return $password;
    }

    private static function refuse(?string $refusal): void
    {
        if ($refusal !== null) {
            throw new RuntimeException($refusal);
        }
    }
}
