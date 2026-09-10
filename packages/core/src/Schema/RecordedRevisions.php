<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Schema;

/**
 * Which revision THIS process just filed for an entry.
 *
 * ⚠️ IT EXISTS BECAUSE INFERRING IT IS NOT POSSIBLE, and two earlier attempts prove it.
 *
 * A form save writes the entry and then its relations (ADR-015), so something has to tell the
 * second half whether the first half filed a revision — to complete that one in place rather
 * than file a second. The obvious answers both failed:
 *
 * - **Carry the id on the page.** For a create the revision is filed by `AuditedBuilder`, on an
 *   instance it constructs rather than the object the page holds, so a property set in
 *   `recordRevision()` is null by the time the page reconciles.
 * - **Compare the newest revision's STATE with the entry's.** Review found this one: the
 *   comparison covers `VERSIONED_COLUMNS` and cannot cover `relation_state`, because the
 *   relations are the thing being written. Two editors saving the same scalars and different
 *   relations therefore both concluded the newest revision was theirs, and the second overwrote
 *   the first's relation snapshot — one save vanishing from history entirely.
 *
 * The defect in both is that they GUESS from shared state. A revision is either one this request
 * wrote or it is not, and that is a fact the writer knows and nothing else can reconstruct. So
 * the writer records it, keyed by entry, and the reconciler takes it.
 *
 * ⚠️ Static for the reason `RevisionWrites` is: revisions are filed from a model event AND from a
 * builder, and a builder has no instance whose property to read. `take()` CLEARS as it reads, so
 * the map holds only entries mid-save and a long-lived worker cannot accumulate ids or hand a
 * stale one to a later save.
 */
final class RecordedRevisions
{
    /** @var array<int, int> Entry id to the newest revision id this process filed for it. */
    private static array $byEntry = [];

    /** Records that this process filed `$revisionId` for `$entryId`. */
    public static function note(int $entryId, int $revisionId): void
    {
        self::$byEntry[$entryId] = $revisionId;
    }

    /**
     * The revision this process filed for `$entryId`, removing it from the register.
     *
     * ⚠️ Removing is what bounds the map and what makes a second call answer honestly: the
     * question is "did MY write file one", and it can only be true once per write.
     */
    public static function take(int $entryId): ?int
    {
        $revisionId = self::$byEntry[$entryId] ?? null;

        unset(self::$byEntry[$entryId]);

        return $revisionId;
    }

    /**
     * Drops anything held for `$entryId` without reading it.
     *
     * ⚠️ Called at the START of a form save, because a write that files a revision and never
     * reconciles — anything that is not a form save — leaves its note behind. Without this, the
     * next form save on that entry would take an id belonging to an earlier write and complete
     * the wrong revision.
     */
    public static function forget(int $entryId): void
    {
        unset(self::$byEntry[$entryId]);
    }
}
