<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * A mail server that refuses everything, with an error that repeats the message it was handed — the recipient and the
 * body, link and all — as a real server's reply can. What `ReaderMail::defer()` must not let reach a log.
 */
final class RefusingTransport extends AbstractTransport
{
    /** Registers the `refusing` transport, and a mailer of that name using it. */
    public static function install(): void
    {
        app('mail.manager')->extend('refusing', static fn (): self => new self);
        config(['mail.mailers.refusing' => ['transport' => 'refusing']]);
    }

    protected function doSend(SentMessage $message): void
    {
        throw new TransportException('550 refused: '.$message->toString());
    }

    public function __toString(): string
    {
        return 'refusing://';
    }
}
