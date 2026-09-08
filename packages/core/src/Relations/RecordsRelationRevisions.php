<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Relations;

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
     * @param  list<mixed>  $sources
     * @param  callable(): mixed  $write
     */
    private function versioned(array $sources, callable $write): mixed
    {
        // ⚠️ The SHARED flag, not a counter on this object — a per-instance depth
        // was the first attempt and it did not hold.
        //
        // One relation write passes through more than one builder: `attach()`
        // starts on `GuardedBelongsToMany` and lands on `EntryRelation`'s own
        // builder, which is a DIFFERENT object with its own counter — so both
        // recorded and one attach filed two versions. `sync()` filed three.
        // `RevisionWrites` is already the project's shared answer to exactly this
        // question, so the outermost write stands the inner ones down and records
        // once itself.
        if (RevisionWrites::suspended()) {
            return $write();
        }

        return DB::transaction(function () use ($sources, $write): mixed {
            if ($sources !== []) {
                // withoutGlobalScopes: this is a lock, not a read that reaches a
                // caller. A source in another scope must still serialise, and a
                // scoped query that matched nothing would take no lock at all.
                Entry::query()->withoutGlobalScopes()
                    ->whereKey($sources)
                    ->lockForUpdate()
                    ->get();
            }

            $entries = Entry::query()->withoutGlobalScopes()->whereKey($sources)->get();
            $before = $entries->mapWithKeys(
                fn (Entry $entry): array => [$entry->getKey() => $entry->relationState()],
            )->all();

            // Suspended for the WRITE only: whatever builders it passes through
            // see recording stood down, and this frame records afterwards.
            $result = RevisionWrites::suspend($write);

            foreach ($entries as $entry) {
                $entry->recordRevisionForRelationChange($before[$entry->getKey()] ?? []);
            }

            return $result;
        });
    }
}
