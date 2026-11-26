<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Filament\Notifications\NotificationMailbox;

final class FilamentNotices
{
    private const SNAPSHOT = 'kitsune.tests.filament_notices';

    /** @return list<array<string, mixed>> */
    public static function snapshot(): array
    {
        $mailbox = app(NotificationMailbox::class);
        $mailbox->flush();
        $notices = [...session()->get(self::SNAPSHOT, []), ...$mailbox->pull()];
        session()->put(self::SNAPSHOT, $notices);

        return $notices;
    }

    public static function clear(): void
    {
        self::snapshot();
        session()->forget(self::SNAPSHOT);
    }
}
