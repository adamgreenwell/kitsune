<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Illuminate\Mail\Transport\ArrayTransport;
use LogicException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * The mail a reader would have received, as the `array` transport kept it — the bytes Symfony built, not the
 * notification Laravel was handed, so the test reads what a reader's inbox would.
 *
 * ⚠️ `ReaderTestCase` makes `array` the default mailer; a test that sets another reads nothing here.
 */
final class ReaderMailbox
{
    /** @return list<Email> */
    public static function messages(): array
    {
        $messages = [];

        foreach (self::transport()->messages() as $sent) {
            $original = $sent instanceof SentMessage ? $sent->getOriginalMessage() : null;

            if ($original instanceof Email) {
                $messages[] = $original;
            }
        }

        return $messages;
    }

    /**
     * Each mail's recipients and subject, in the order they went.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function sent(): array
    {
        return array_map(static fn (Email $mail): array => [
            implode(', ', array_map(static fn (Address $to): string => $to->getAddress(), $mail->getTo())),
            (string) $mail->getSubject(),
        ], self::messages());
    }

    public static function last(): ?Email
    {
        $messages = self::messages();

        return $messages === [] ? null : $messages[array_key_last($messages)];
    }

    /** The last mailed link's path and query — what a reader's click opens on this site — or null. */
    public static function link(): ?string
    {
        $body = (string) self::last()?->getTextBody();

        return preg_match_all('#https?://[^/\s]+(/\S*/account/(?:register/complete|reset)\?token=[A-Za-z0-9]{43})(?=\s)#', $body, $found) > 0
            ? $found[1][array_key_last($found[1])]
            : null;
    }

    /** The last mailed link's secret, or null. */
    public static function secret(): ?string
    {
        $link = self::link();

        return $link === null ? null : substr($link, -43);
    }

    public static function flush(): void
    {
        self::transport()->flush();
    }

    private static function transport(): ArrayTransport
    {
        $transport = app('mail.manager')->mailer('array')->getSymfonyTransport();

        return $transport instanceof ArrayTransport ? $transport : throw new LogicException('The array mailer has no array transport.');
    }
}
