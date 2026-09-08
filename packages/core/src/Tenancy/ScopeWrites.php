<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tenancy;

/**
 * Whether the scope's WRITE guards are currently stood down.
 *
 * ⚠️ One flag, read by two enforcers. The guard started on the model event and
 * the trait held the flag privately; when the same guard moved to the builder
 * — because a mass update dispatches no events — the builder could not see it,
 * so `withoutScopeBecause()` stood down half of it. Provisioning code that had
 * been explicit about crossing the boundary then failed anyway, which teaches
 * callers to stop using the reviewable path.
 */
final class ScopeWrites
{
    private static bool $suspended = false;

    public static function suspended(): bool
    {
        return self::$suspended;
    }

    /** Nested calls restore the previous state rather than clearing it. */
    public static function suspend(callable $callback): mixed
    {
        $previous = self::$suspended;
        self::$suspended = true;

        try {
            return $callback();
        } finally {
            self::$suspended = $previous;
        }
    }
}
