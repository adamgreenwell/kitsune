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
 * ⚠️ IT ONLY RECORDS WHILE A FORM SAVE IS OPEN, which review found by reading the docblock that used
 * to be here. That one said `take()` clearing meant "the map holds only entries mid-save", and it was
 * false: `recordRevision()` is the single place every revision is created, so an API write, an
 * importer or a queued job filed a note too — and nothing outside the Filament hooks ever takes one.
 * A long-lived worker revising many distinct entries kept one array element per entry for the life of
 * the process, while the comment asserted it could not.
 *
 * `open()` is called from `mutateFormDataBefore*` and `take()` closes the window, so a write outside
 * that path never registers. That is a stronger guarantee than a size cap: the register describes
 * exactly what it claims to, rather than being a cache that happens to stay small.
 *
 * ⚠️ SCOPED, NOT STATIC, and that is the second half of the same finding. `RevisionWrites` can be
 * static because it always restores its flag in a `finally`; this holds state ACROSS hooks by design,
 * so a request that opens a window and dies before reconciling would carry both the flag and the map
 * into the next job on a long-lived worker. `Context` is bound `scoped` for exactly that reason and
 * this follows it — which also means the test suite gets a fresh register per test instead of
 * inheriting one, and the first version of the bound test failed by finding two entries already in it.
 *
 * ⚠️ Resolved through the container by `Entry::recordRevision()` rather than held anywhere: a
 * revision is filed from a model event AND from `AuditedBuilder`, and a builder has no instance whose
 * property to read — the problem the static version was solving, solved by the container instead.
 */
final class RecordedRevisions
{
    /** @var array<int, int> Entry id to the newest revision id this request filed for it. */
    private array $byEntry = [];

    /**
     * Whether a form save is in progress and its revision is worth registering.
     *
     * ⚠️ NOT A COUNTER. A form save touches one entry, and two overlapping saves in one request is
     * not a thing Filament does — where it would matter is a queued job filing many revisions, and
     * that path never opens the window at all.
     */
    private bool $open = false;

    /**
     * Begins a form save's window, discarding anything an earlier one left.
     *
     * ⚠️ A CREATE HAS NOTHING TO FORGET AND STILL HAS TO OPEN THE WINDOW: the entry has no key until
     * it is inserted, so `forget()` cannot be keyed on one, but `recordRevision()` still needs
     * permission to file the note it makes with the real key a moment later.
     */
    public function open(?int $entryId = null): void
    {
        $this->open = true;

        if ($entryId !== null) {
            unset($this->byEntry[$entryId]);
        }
    }

    /**
     * Records that this process filed `$revisionId` for `$entryId`.
     *
     * ⚠️ IGNORED OUTSIDE A FORM SAVE. Every revision in the system passes through here — API,
     * importer, queue, console — and only a form save has a reconciler that will come back for the
     * answer. Registering the rest was an unbounded map with no reader.
     */
    public function note(int $entryId, int $revisionId): void
    {
        if (! $this->open) {
            return;
        }

        $this->byEntry[$entryId] = $revisionId;
    }

    /**
     * The revision this process filed for `$entryId`, removing it from the register.
     *
     * ⚠️ Removing is what bounds the map and what makes a second call answer honestly: the
     * question is "did MY write file one", and it can only be true once per write.
     */
    public function take(int $entryId): ?int
    {
        $revisionId = $this->byEntry[$entryId] ?? null;

        unset($this->byEntry[$entryId]);

        // ⚠️ CLOSES THE WINDOW as well as reading, so a save that files a revision and never
        // reconciles cannot leave registration switched on for the rest of the request.
        $this->open = false;

        return $revisionId;
    }

    /** How many entries the register is holding — for tests that assert it stays bounded. */
    public function held(): int
    {
        return count($this->byEntry);
    }
}
