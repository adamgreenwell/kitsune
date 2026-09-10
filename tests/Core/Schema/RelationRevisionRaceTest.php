<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Schema\RecordedRevisions;
use Kitsune\Core\Schema\RevisionWrites;
use Kitsune\Core\Tenancy\Context;

/**
 * Two things a form save must not lose when another save is running.
 *
 * ⚠️ BOTH FOUND BY REVIEW, on the commit that made a form save file one revision instead of 1 + N
 * (issue #59). Each is a case where the fix for that issue removed a guarantee the code already
 * had, which is the risk of suppressing behaviour rather than changing it.
 */
beforeEach(function (): void {
    $this->org = Org::create(['name' => 'Golfdom', 'slug' => 'golfdom']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['org_id' => $this->org->id, 'handle' => 's', 'slug' => 's', 'name' => 'S']);
    app(Context::class)->setSite($this->site);

    $this->type = EntryType::create([
        'org_id' => $this->org->id, 'handle' => 'article',
        'name' => 'Article', 'plural_name' => 'Articles',
    ]);

    $this->authors = FieldStorage::create([
        'org_id' => $this->org->id, 'handle' => 'authors', 'type' => 'relation',
        'pii_class' => 'none', 'cardinality' => -1,
    ]);

    $this->one = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'One']);
    $this->two = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Two']);
});

afterEach(fn () => app(Context::class)->forget());

it('does not fold two saves with equal scalars into one revision', function (): void {
    /*
     * ⚠️ REVIEW'S SCENARIO, and the one the state comparison could not survive. Two editors hold
     * the same prior revision. Both save the same TITLE and different RELATIONS. The first save's
     * scalar write files a revision; the second's writes nothing, because nothing scalar changed.
     *
     * Judging "is the newest revision mine?" by comparing `VERSIONED_COLUMNS` then answered YES for
     * both — the scalars are identical by construction — so both completed the same revision and
     * the second overwrote the first's relation snapshot. One save vanished from history, and
     * "restore the previous version" would have reverted relations an author deliberately set.
     *
     * `relation_state` cannot be added to that comparison to fix it: the relations are the thing
     * being written, so the snapshot never matches until after the write it is meant to identify.
     */
    app(RecordedRevisions::class)->open();
    $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Draft']);

    // Editor A: changes the title, and picks `one`.
    app(RecordedRevisions::class)->open((int) $entry->getKey());
    $entry->title = 'Agreed title';
    $entry->save();
    $revisionsAfterA = $entry->revisions()->count();
    $relationsBefore = $entry->relationState();
    RevisionWrites::suspend(fn () => $entry->syncFieldRelations($this->authors, [$this->one->id]));
    $entry->reconcileRevisionAfterRelationSync($relationsBefore);

    $filedByA = $entry->revisions()->orderByDesc('id')->first();

    expect($filedByA->relation_state[(string) $this->authors->getKey()] ?? null)
        ->toBe([$this->one->id], 'A did not record its own relation choice');

    /*
     * Editor B, working from the same form: the SAME title — so the entry write is not dirty and
     * files nothing — and a different relation.
     */
    $fresh = Entry::query()->whereKey($entry->getKey())->sole();
    app(RecordedRevisions::class)->open((int) $fresh->getKey());
    $fresh->title = 'Agreed title';
    $fresh->save();

    // ⚠️ UNCHANGED, and that is the precondition the whole scenario rests on: B writes the same
    // title, so the entry is not dirty and files no revision. Without that there would be two
    // revisions and nothing to fight over.
    expect($fresh->revisions()->count())
        ->toBe($revisionsAfterA, 'B\'s scalar write should have filed nothing');

    $relationsBeforeB = $fresh->relationState();
    RevisionWrites::suspend(fn () => $fresh->syncFieldRelations($this->authors, [$this->two->id]));
    $fresh->reconcileRevisionAfterRelationSync($relationsBeforeB);

    // ⚠️ A's revision is untouched. This is the assertion the state comparison failed.
    expect($filedByA->fresh()->relation_state[(string) $this->authors->getKey()] ?? null)
        ->toBe([$this->one->id], 'B overwrote A\'s relation snapshot');

    // ⚠️ And B is recorded rather than silently dropped, on a revision of its own.
    $filedByB = $fresh->revisions()->orderByDesc('id')->first();

    expect($filedByB->getKey())->not->toBe($filedByA->getKey(), 'B completed A\'s revision')
        ->and($filedByB->relation_state[(string) $this->authors->getKey()] ?? null)
        ->toBe([$this->two->id], 'B did not record its own relation choice');
});

it('keeps the transaction around a relation write whose revision is suspended', function (): void {
    /*
     * ⚠️ REVIEW'S OTHER SCENARIO. `RecordsRelationRevisions::versioned()` used one flag for two
     * questions: its early return was keyed on `RevisionWrites::suspended()`, and that return skips
     * the encompassing transaction and the `lockForUpdate()` as well as the recording.
     *
     * `SyncsFieldRelations` suspends revisions across every relation field to get one revision per
     * save — so every `sync()` in a form save ran with no row lock and no transaction. A `sync([])`
     * became a bare detach, and a replacement sync could compute its detach set while another
     * writer changed the same field: two concurrent saves interleaving into a relation set neither
     * of them chose.
     *
     * ⚠️ ASSERTED ON THE TRANSACTION LEVEL AT THE MOMENT THE WRITE RUNS, because that is
     * driver-independent. The lock itself is not observable this way — SQLite's grammar compiles
     * `FOR UPDATE` to nothing — but it is taken inside this transaction and cannot be taken without
     * one, so the transaction is the thing to pin.
     *
     * ⚠️ AGAINST A BASELINE, not against zero, and the first version of this test asserted zero and
     * was VACUOUS: the suite already runs each test inside a transaction, so the level is at least 1
     * whatever `versioned()` does. It passed against the reverted code, which is how the flaw was
     * found — a regression test that passes before the fix proves nothing. What has to be asserted
     * is the level `versioned()` adds.
     */
    app(RecordedRevisions::class)->open();
    $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Draft']);

    $baseline = DB::transactionLevel();
    $levels = [];

    DB::listen(function ($query) use (&$levels): void {
        if (str_contains($query->sql, 'entry_relations')) {
            $levels[] = DB::transactionLevel();
        }
    });

    RevisionWrites::suspend(fn () => $entry->syncFieldRelations($this->authors, [$this->one->id]));

    expect($levels)->not->toBe([], 'no query touched entry_relations, so this asserts nothing')
        ->and(min($levels))->toBeGreaterThan(
            $baseline,
            'a suspended relation write opened no transaction of its own, so it took no lock',
        );
});

it('still files exactly one revision per relation write when nothing is suspended', function (): void {
    /*
     * ⚠️ THE RE-ENTRANCY GUARANTEE THE SHARED FLAG WAS THERE FOR, so separating the two flags
     * cannot quietly reintroduce the duplicate it prevented. One relation write passes through more
     * than one builder — `attach()` starts on `GuardedBelongsToMany` and lands on `EntryRelation`'s
     * own — and before the shared flag both recorded, so one attach filed two versions and a
     * `sync()` filed three.
     */
    app(RecordedRevisions::class)->open();
    $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Draft']);
    $before = $entry->revisions()->count();

    $entry->syncFieldRelations($this->authors, [$this->one->id]);

    expect($entry->revisions()->count())->toBe($before + 1, 'a single sync filed more than one revision');
});

it('reconciles inside the transaction that wrote the relations', function (): void {
    /*
     * ⚠️ THE RECONCILE RE-READS THE RELATION STATE, so the span it reads over has to be the span
     * that wrote it. `versioned()` locks the entry for each individual sync, so the writes
     * serialise — but that lock is released when its transaction commits, and a reconcile outside
     * it re-reads whatever the last writer left. Measured on the first version of this branch, with
     * a second save's sync landing in the window:
     *
     *     A chose [1]; B chose [2]; A's revision recorded [2]  => MIS-RECORDED
     *
     * Not a lost write — A's revision exists and is A's — but a false record of what A did, which
     * for a history is the same kind of damage.
     *
     * ⚠️ THE ASSERTION IS STRUCTURAL, and deliberately not a demonstration of blocking. Two "saves"
     * in one test share one connection, so a second writer inside the first's transaction is not
     * blocked by the lock — it is the same transaction. What CAN be pinned deterministically is
     * that the revision update and the relation writes happen in one transaction above the
     * baseline, which is the property the lock needs in order to mean anything.
     */
    app(RecordedRevisions::class)->open();
    $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Draft']);

    $baseline = DB::transactionLevel();
    $relationLevels = [];
    $revisionLevels = [];

    /*
     * ⚠️ WRITES ONLY. The first version of this listener matched any statement naming the table, so
     * it caught `relationState()`'s read — which legitimately happens BEFORE the transaction opens —
     * and the minimum was the baseline. The claim is about where the writes land.
     */
    $isWrite = static fn (string $sql): bool => (bool) preg_match('/^\s*(insert|update|delete)\b/i', $sql);

    DB::listen(function ($query) use (&$relationLevels, &$revisionLevels, $isWrite): void {
        if (! $isWrite($query->sql)) {
            return;
        }

        if (str_contains($query->sql, 'entry_relations')) {
            $relationLevels[] = DB::transactionLevel();
        }

        if (str_contains($query->sql, 'entry_revisions')) {
            $revisionLevels[] = DB::transactionLevel();
        }
    });

    $relationsBefore = $entry->relationState();

    app(RecordedRevisions::class)->open((int) $entry->getKey());
    $entry->title = 'Renamed';
    $entry->save();

    // ⚠️ THE REAL METHOD. Rebuilding its transaction here is what made the first version of this
    // test unfalsifiable — it asserted a property of the test's own code.
    $entry->writeRelationsAndReconcile(
        fn () => $entry->syncFieldRelations($this->authors, [$this->one->id]),
        $relationsBefore,
    );

    expect($relationLevels)->not->toBe([], 'nothing wrote entry_relations, so this asserts nothing')
        ->and($revisionLevels)->not->toBe([], 'no revision was completed, so this asserts nothing')
        ->and(min($relationLevels))->toBeGreaterThan($baseline)
        ->and(min($revisionLevels))->toBeGreaterThan(
            $baseline,
            'the reconcile ran outside the transaction that wrote the relations',
        );
});

it('registers nothing for a write that is not a form save', function (): void {
    /*
     * ⚠️ THE DOCBLOCK CLAIMED THIS AND IT WAS FALSE, which review found by reading it. It said
     * `take()` clearing meant "the map holds only entries mid-save" — but `recordRevision()` is the
     * single place every revision is created, so an API write, an importer or a queued job filed a
     * note too, and nothing outside the Filament hooks ever takes one. A long-lived worker revising
     * many distinct entries kept one array element per entry for the life of the process, while the
     * comment asserted it could not.
     *
     * ⚠️ THE FIX IS A WINDOW, NOT A SIZE CAP, and that is a stronger guarantee: the register now
     * describes exactly what it claims to, rather than being a cache that happens to stay small.
     * Only `mutateFormDataBefore*` opens it, so a write with no reconciler never registers.
     */
    expect(app(RecordedRevisions::class)->held())->toBe(0, 'the register did not start empty');

    // Ten ordinary writes, each filing a revision, none of them a form save.
    foreach (range(1, 10) as $i) {
        $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => "Imported {$i}"]);
        $entry->title = "Imported {$i} revised";
        $entry->save();

        expect($entry->revisions()->count())->toBeGreaterThan(0, 'the write filed no revision at all');
    }

    expect(app(RecordedRevisions::class)->held())->toBe(0, 'a non-form write registered a revision nothing will collect');
});

it('closes the window when the reconciler takes its revision', function (): void {
    /*
     * ⚠️ SO A SAVE THAT NEVER RECONCILES CANNOT LEAVE REGISTRATION ON for the rest of the request.
     * `take()` closes as well as reads, which is what bounds the window to one save rather than to
     * one request.
     */
    app(RecordedRevisions::class)->open();
    $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Draft']);

    expect(app(RecordedRevisions::class)->held())->toBe(1, 'the form save did not register its revision');

    $entry->writeRelationsAndReconcile(
        fn () => $entry->syncFieldRelations($this->authors, [$this->one->id]),
        $entry->relationState(),
    );

    expect(app(RecordedRevisions::class)->held())->toBe(0, 'the register still holds a revision after reconciling');

    // And a later ordinary write does not re-register, because the window closed.
    $entry->title = 'Renamed by a job';
    $entry->save();

    expect(app(RecordedRevisions::class)->held())->toBe(0, 'the window stayed open after the reconciler closed it');
});
