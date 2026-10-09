<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * A transport that sends — as far as `ReaderMail::fault()` can tell — and keeps what it was handed, so a production
 * test can see the mail without a network. Its name is `delivering`, which is not one of the transports that keep mail.
 */
final class DeliveringTransport extends AbstractTransport
{
    /** @var list<SentMessage> */
    public static array $sent = [];

    /** Registers the `delivering` transport, and a mailer of that name using it. */
    public static function install(): void
    {
        self::$sent = [];
        app('mail.manager')->extend('delivering', static fn (): self => new self);
        config(['mail.mailers.delivering' => ['transport' => 'delivering']]);
    }

    protected function doSend(SentMessage $message): void
    {
        self::$sent[] = $message;
    }

    public function __toString(): string
    {
        return 'delivering://';
    }
}
