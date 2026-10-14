<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Console;

use Kitsune\Core\Credentials\Secret;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/** A credential is typed without echo or read from the first bounded stdin line; never argv or environment. @internal */
final class CredentialInput
{
    public const MAX_BYTES = 1024;

    public static function read(InputInterface $input, SymfonyStyle $output, bool $stdin): Secret
    {
        $stream = ($input instanceof StreamableInputInterface ? $input->getStream() : null) ?? STDIN;
        if ($stdin) {
            if (@stream_isatty($stream)) {
                throw new RuntimeException('Pipe the credential with --stdin, or use the hidden interactive prompt.');
            }

            return self::fromStream($stream);
        }
        if (! $input->isInteractive() || ! @stream_isatty($stream)) {
            throw new RuntimeException('A hidden terminal prompt or explicit --stdin is required for a credential.');
        }

        try {
            $value = $output->askQuestion(self::question());
        } catch (Throwable) {
            throw new RuntimeException('The credential could not be read at a hidden prompt.');
        }

        return self::value($value);
    }

    public static function question(): Question
    {
        return (new Question('New credential value (hidden)'))->setHidden(true)->setHiddenFallback(false)->setTrimmable(false);
    }

    /** @param resource $stream */
    public static function fromStream($stream): Secret
    {
        // Up to the maximum value plus CRLF, with no unbounded stream_get_contents() or line read.
        $value = fgets($stream, self::MAX_BYTES + 3);
        if (is_string($value) && str_ends_with($value, "\n")) {
            $value = substr($value, 0, -1);
            if (str_ends_with($value, "\r")) {
                $value = substr($value, 0, -1);
            }
        }

        return self::value($value);
    }

    private static function value(#[\SensitiveParameter] mixed $value): Secret
    {
        if (! is_string($value) || $value === '' || strlen($value) > self::MAX_BYTES) {
            throw new RuntimeException('No bounded credential value was supplied.');
        }

        return new Secret($value);
    }
}
