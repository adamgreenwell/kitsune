<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Relations;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Schema\RevisionWrites;

/**
 * Filing a version for a write that changes an entry's relations.
 *
 * ⚠️ Shared by TWO builders, because relations have two supported write paths
 * and the first fix only covered one. `GuardedBelongsToMany` handles
 * `attach`/`detach`/`sync`; `GuardedRelationBuilder` handles the ordinary
 * Eloquent surface — `EntryRelation::create()`, a predicate delete (which
 * `Entry::redactField()` uses), and an `ordering` update, which that builder
 * explicitly permits. A relation changed through the second left the newest
 * revision stale, so restoring it silently undid the change.
 *
 * One implementation rather than two, for the reason this project keeps
 * relearning: a rule maintained in two places drifts, and the halves then
 * disagree about what a version is.
 */
trait RecordsRelationRevisions
{
    /**
     * Run a relation write and file a revision for whatever it changed.
     *
     * ⚠️ ONE transaction, and the LOCK is taken before the before-state is read.
     *
     * A write that opens its own transaction commits and releases the source lock
     * before the after-state can be read, leaving a window in which a second
     * writer commits — the first operation's revision then snapshots both, so one
     * version goes missing and another is recorded twice. The lock has to span
     * read-write-read, not just the write.
     *
     * CHANGED, not merely attempted: the before and after states are compared, so
     * a delete that matched nothing files nothing.
     *
     * ⚠️ `$sources` is a CALLABLE, invoked inside the transaction. It was an
     * array computed by the caller beforehand, which left a window: a concurrent
     * attach could create a matching pivot under a source that had not been
     * locked or included in the recording, and the statement then wrote it
     * anyway. Deciding what a statement affects has to happen under the same lock
     * as the statement — the same reason `AuditedBuilder` captures its keys inside
     * its transaction and constrains the write to them.
     *
     * @param  callable(): list<mixed>  $sources
     * @param  callable(): mixed  $write
     */
    /**
     * Whether an enclosing `versioned()` frame already holds the transaction and the lock.
     *
     * ⚠️ SEPARATE FROM `RevisionWrites`, which is what review's finding was about. That flag says
     * whether a revision should be RECORDED — a question the caller owns, and one an external
     * `suspend()` legitimately answers "no" to. This one says whether the serialisation is already
     * in hand, which only `versioned()` itself can know. Sharing one flag for both meant every
     * suspended caller silently lost its row lock.
     *
     * Static rather than a property for the reason the other one is: a single relation write
     * passes through several builder instances, so a per-object flag stands nothing down.
     */
    private static bool $insideVersionedFrame = false;

    private function versioned(callable $sources, callable $write): mixed
    {
        /*
         * ⚠️ KEYED ON AN ENCLOSING FRAME, NOT ON `RevisionWrites::suspended()`, and review found
         * why that distinction matters: this early return skips the transaction and the lock as
         * well as the recording, so an EXTERNAL caller asking only for silence lost the
         * serialisation too.
         *
         * `SyncsFieldRelations` wraps a form save's relation writes in `RevisionWrites::suspend()`
         * to get one revision per save (issue #59). With the flag doing double duty, every
         * `sync()` in that block ran with no row lock and no encompassing transaction — so a
         * `sync([])` performed a bare detach, and a replacement sync could compute its detach set
         * while another writer changed the same field. Two concurrent form saves could interleave
         * into a relation set neither of them chose.
         *
         * The two questions were never the same question. "Is an outer frame already handling
         * this?" is re-entrancy — one relation write passes through more than one builder, since
         * `attach()` starts on `GuardedBelongsToMany` and lands on `EntryRelation`'s own builder,
         * a DIFFERENT object, so both recorded and one attach filed two versions. "Should a
         * revision be recorded at all?" is the caller's business. Only the first justifies
         * skipping the lock, because only then does something else already hold it.
         */
        if (self::$insideVersionedFrame) {
            return $write();
        }

        return DB::transaction(function () use ($sources, $write): mixed {
            $sources = $sources();

            if ($sources !== []) {
                // withoutGlobalScopes: this is a lock, not a read that reaches a
                // caller. A source in another scope must still serialise, and a
                // scoped query that matched nothing would take no lock at all.
                Entry::query()->withoutGlobalScopes()
                    ->whereKey($sources)
                    ->lockForUpdate()
                    ->get();
            }

            /*
             * ⚠️ RECORDING IS A SEPARATE QUESTION FROM SERIALISING, which is the whole point of
             * the change above. A suspended caller still gets the lock and the transaction; it
             * just gets no revision — and the `before` snapshot it would be compared against is
             * not read either, since nothing will use it.
             */
            $recording = ! RevisionWrites::suspended();
            $entries = new Collection;
            $before = [];

            if ($recording) {
                $entries = Entry::query()->withoutGlobalScopes()->whereKey($sources)->get();
                $before = $entries->mapWithKeys(
                    fn (Entry $entry): array => [$entry->getKey() => $entry->relationState()],
                )->all();
            }

            // Suspended for the WRITE only: whatever builders it passes through
            // see recording stood down, and this frame records afterwards.
            $result = self::insideVersionedFrame(static fn (): mixed => RevisionWrites::suspend($write));

            foreach ($entries as $entry) {
                $entry->recordRevisionForRelationChange($before[$entry->getKey()] ?? []);
            }

            return $result;
        });
    }

    /**
     * Runs `$callback` with the enclosing-frame flag raised.
     *
     * ⚠️ Restores the PREVIOUS value rather than clearing, for the same reason
     * `RevisionWrites::suspend()` does: a nested frame must not stand the outer one down on its
     * way out. Reached only from `versioned()`, which is why it is private.
     */
    private static function insideVersionedFrame(callable $callback): mixed
    {
        $previous = self::$insideVersionedFrame;
        self::$insideVersionedFrame = true;

        try {
            return $callback();
        } finally {
            self::$insideVersionedFrame = $previous;
        }
    }
}
