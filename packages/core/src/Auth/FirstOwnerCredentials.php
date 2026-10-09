<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Auth;

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
 * prompt that cannot hide what is typed, on a terminal or on a pipe, is refused rather than allowed to show it.
 *
 * ⚠️ EVERY RULE IS ABOUT SIGNING IN LATER. There is no password reset for staff yet, and no second factor, so a password the
 * sign-in form cannot send — a trailing space nobody sees, a control character an arrow key typed, bytes bcrypt never
 * reads — is a locked-out owner, and the only one this installation has.
 *
 * ⚠️ NO CONTAINER BUT THE EMAIL CHECK, so the terminal tests can run it in a child process with nothing booted.
 */
final class FirstOwnerCredentials
{
    /** bcrypt reads no further, whatever `hashing.bcrypt.limit` says. */
    public const MAX_BYTES = PasswordRules::MAX_BYTES;

    /** How much of a line is read from standard input at most; anything this long is refused by `MAX_BYTES` anyway. */
    public const READ_BOUND = 4096;

    /** `%s` the address, `%d` the minimum in force (`PasswordRules::minCharacters()`). */
    public const PROMPT = 'Choose a password for %s, the first owner (hidden; at least %d characters)';

    public const CONFIRM = 'Type the same password again (hidden)';

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
     * Refuse to prompt where what is typed would be shown: on the process's own standard input, when it is not a terminal.
     *
     * ⚠️ SYMFONY HIDES A TERMINAL, AND ONLY A TERMINAL. On a pipe — `ssh host 'php artisan …'` with no `-t`, `docker exec
     * -i`, `kubectl exec -i` — `stty` cannot reach the input, so the hidden question is read like any other, while the
     * person at the far end types into a terminal of their own that still echoes: both answers on screen, under a prompt
     * that says "hidden". The hidden fallback being off does not catch it, because Symfony refuses only a terminal it
     * cannot hide, and a pipe is not one. Automation that pipes a password in has `--owner-password-stdin` for it — found
     * by review, reproduced through `cat |` in front of the real command.
     *
     * A stream other than the process's own — one a caller set on the input on purpose — is read as given.
     *
     * @param  resource  $stream  the stream the question will be read from
     *
     * @throws RuntimeException
     */
    public static function refuseUnlessPromptable($stream): void
    {
        if ((stream_get_meta_data($stream)['uri'] ?? null) === 'php://stdin' && ! @stream_isatty($stream)) {
            throw new RuntimeException(
                'Refusing to ask for the password: standard input is not a terminal, so what is typed at the prompt '
                .'could not be hidden — under `ssh`, `docker exec` or `kubectl exec`, add `-t`. To pipe the password in, '
                .'use `--owner-password-stdin`. Nothing was written.'
            );
        }
    }

    /**
     * Ask twice, hidden: the first answer is checked before the second is asked for, so a refused password is never typed twice.
     *
     * @throws RuntimeException
     */
    public static function ask(SymfonyStyle $io, string $email): string
    {
        try {
            $first = self::answered($io->askQuestion(self::question(sprintf(self::PROMPT, $email, PasswordRules::minCharacters()))));
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

    /**
     * Why this password cannot be the first owner's, or null — `PasswordRules`, worded for the console. Checked on the
     * exact bytes, in its order.
     */
    public static function passwordRefusal(#[\SensitiveParameter] string $password, string $email): ?string
    {
        return match (PasswordRules::refusal($password, $email)) {
            null => null,
            PasswordRefusal::Encoding => 'The password is not valid UTF-8, which is what the sign-in form sends, so it could never be typed there. '
                .'Check this terminal\'s locale. Nothing was written.',
            PasswordRefusal::Control => 'The password contains a control character — a Tab, or an arrow key pressed at the hidden prompt, types '
                .'one — which the sign-in form cannot send. Nothing was written.',
            PasswordRefusal::Edges => 'The password begins or ends with whitespace, or with a character nobody can see — a byte-order mark a '
                .'file was saved with, a zero-width space. The sign-in form sends it exactly as typed, a character nobody '
                .'can see is one nobody types again, and there is no password reset for staff yet. Nothing was written.',
            PasswordRefusal::Short => 'The password is shorter than '.PasswordRules::minCharacters().' characters. Kitsune has no second factor '
                .'yet, so the password is all that protects the owner of this installation. Nothing was written.',
            PasswordRefusal::Long => 'The password is longer than 72 bytes. bcrypt reads no further, so everything after the 72nd byte would '
                .'protect nothing. Nothing was written.',
            PasswordRefusal::IsEmail => 'The password is the owner\'s email address. Nothing was written.',
        };
    }

    /**
     * Why this cannot be the first owner's address, or null: it must be one the sign-in form accepts, in the browser and
     * on the server. Stored as typed — sign-in compares it exactly on two of the four engines.
     */
    public static function emailRefusal(string $email): ?string
    {
        return EmailAddress::isAccepted($email) ? null : 'Refusing `--owner`: that is not an email address the sign-in form accepts — both the '
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
