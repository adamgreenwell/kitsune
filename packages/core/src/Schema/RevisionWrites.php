<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Schema;

/**
 * Whether revision recording is currently stood down.
 *
 * ⚠️ Shared, for the reason `ScopeWrites` is shared — and this is the second
 * time the same lesson has been learned in this codebase.
 *
 * `Entry::withoutRevisions()` held the flag as an INSTANCE property, which was
 * right while revisions were recorded only from `created` and `updated`. A bulk
 * builder write dispatches neither, so recording had to move to the builder as
 * well — and a builder has no instance whose property to read. Left instance-
 * private, the escape hatch would have stood down one recorder and not the
 * other, exactly as `withoutScopeBecause()` once did.
 *
 * Erasure depends on this working: `Entry::redactField()` writes inside
 * `withoutRevisions()` precisely so that clearing personal data does not file
 * the redacted state as a new version of the history it is clearing.
 */
final class RevisionWrites
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
