<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Schema\RecordedRevisions;
use Kitsune\Core\Tenancy\Context;

/**
 * One form save is one revision, however many relation fields it touches.
 *
 * ⚠️ IT WAS 1 + N, ONE PER RELATION FIELD (issue #59). Measured before the fix:
 * `created=1  afterRelationSync=2  afterSecondField=3`. Two write paths each file one
 * legitimately — the entry write through its model event, and `GuardedBelongsToMany::sync()`
 * through `recordRevisionForRelationChange()`, which exists because a pivot write fires no
 * `Entry` event. What was new is that a FORM save performs both, because relation state is
 * written after the entry exists (ADR-015).
 *
 * The cost was phantom history — revision 1 of a create asserting "no relations" for a save that
 * had them — and a bounded 50-version budget consumed at twice the rate or worse.
 *
 * ⚠️ BOTH OBVIOUS FIXES ARE WRONG, and the third case below is the one that proves it.
 * Suppressing the sync's revision alone leaves `relation_state` describing a state the author
 * never saved; suppressing both and always recording one loses a relations-only edit entirely,
 * because the entry write is not dirty and files nothing.
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
    $this->tags = FieldStorage::create([
        'org_id' => $this->org->id, 'handle' => 'tags', 'type' => 'relation',
        'pii_class' => 'none', 'cardinality' => -1,
    ]);

    $this->target = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Target']);
});

afterEach(fn () => app(Context::class)->forget());

/**
 * What the page does: clear the register, sync every field with revisions suspended, then
 * reconcile exactly one.
 *
 * ⚠️ NO "BEFORE" ID IS PASSED ANY MORE, and the two attempts that needed one are why. The trait
 * first carried the newest revision id from before the write and compared ids; review showed a
 * concurrent save can file one in that window, so the comparison completes somebody else's
 * revision. Comparing STATE replaced it, and review showed that cannot see `relation_state` —
 * the thing being written — so two saves with equal scalars and different relations both claimed
 * the same revision. `RecordedRevisions` is what the writer actually filed, so there is nothing
 * to infer. `RelationRevisionRaceTest` holds both scenarios.
 */
/**
 * What the page does BEFORE the entry write, in `mutateFormDataBefore*`.
 *
 * ⚠️ SEPARATE FROM `formSave()` BECAUSE THE ORDER IS THE SUBTLE PART, and folding it in made this
 * file lie: it cleared the register AFTER the entry write, discarding the note that write had just
 * filed, and three tests failed with one revision too many. Production cannot make that mistake —
 * `mutateFormDataBeforeSave()` is by definition before the write — but a helper that runs both
 * halves in one call can, and did.
 */
function beginFormSave(Entry $entry): void
{
    if ($entry->exists) {
        RecordedRevisions::forget((int) $entry->getKey());
    }
}

function formSave(Entry $entry, array $sync): void
{
    $relationsBefore = $entry->relationState();

    /*
     * ⚠️ THE REAL METHOD, not a rebuild of its shape. `writeRelationsAndReconcile()` is where the
     * transaction and the lock live, precisely so a test can reach them — the trait's own method
     * needs a Filament form, and a helper that opened its own transaction would assert a property
     * of the helper.
     */
    $entry->writeRelationsAndReconcile(function () use ($entry, $sync): void {
        foreach ($sync as [$storage, $ids]) {
            $entry->syncFieldRelations($storage, $ids);
        }
    }, $relationsBefore);
}

it('records ONE revision for a create that fills two relation fields', function (): void {
    // A create has no prior revision, which is what the null says.
    $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Source']);

    formSave($entry, [
        [$this->authors, [$this->target->id]],
        [$this->tags, [$this->target->id]],
    ]);

    expect($entry->revisions()->count())->toBe(1, 'a form save filed more than one revision');

    // ⚠️ And it describes the relations AS SAVED. The entry write's snapshot was taken before
    // they existed, so a fix that only suppressed the sync's revision would leave this empty.
    $state = $entry->revisions()->sole()->relation_state;

    expect($state[(string) $this->authors->getKey()] ?? null)->toBe([$this->target->id])
        ->and($state[(string) $this->tags->getKey()] ?? null)->toBe([$this->target->id]);
});

it('records ONE revision for an edit that changes a scalar and a relation together', function (): void {
    $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Source']);
    formSave($entry, [[$this->authors, [$this->target->id]]]);

    $before = $entry->revisions()->max('id');

    beginFormSave($entry);
    $entry->title = 'Renamed';
    $entry->save();
    formSave($entry, [[$this->authors, []]]);

    expect($entry->revisions()->count())->toBe(2)
        ->and($entry->revisions()->orderByDesc('id')->first()->title)->toBe('Renamed')
        ->and($entry->revisions()->orderByDesc('id')->first()->relation_state)->toBe([]);
});

it('still records a revision when relations are the ONLY change', function (): void {
    /*
     * ⚠️ THE CASE THAT RULES OUT BLANKET SUPPRESSION. Nothing on the entry is dirty, so the entry
     * write files nothing at all — meaning the sync's revision is the only possible record. A fix
     * that suspended both and trusted the entry write would lose the change silently, and
     * "restore the latest version" would then revert relations the author had deliberately set.
     */
    $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Source']);
    formSave($entry, [[$this->authors, [$this->target->id]]]);

    $before = $entry->revisions()->max('id');

    beginFormSave($entry);
    formSave($entry, [[$this->authors, []]]);

    expect($entry->revisions()->count())->toBe(2, 'a relations-only edit filed no revision')
        ->and($entry->revisions()->orderByDesc('id')->first()->relation_state)->toBe([]);
});

it('files nothing when a save changed no relations at all', function (): void {
    // Otherwise every no-op save costs a version off a bounded history — the same argument the
    // JSON key-order duplicate already established.
    $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Source']);
    formSave($entry, [[$this->authors, [$this->target->id]]]);

    $count = $entry->revisions()->count();
    $before = $entry->revisions()->max('id');

    beginFormSave($entry);
    formSave($entry, [[$this->authors, [$this->target->id]]]);

    expect($entry->revisions()->count())->toBe($count, 'a no-op save filed a revision');
});

it('leaves another request\'s revision alone', function (): void {
    /*
     * ⚠️ THE CONCURRENCY CASE. Two editors saving the same entry means another request can file a
     * revision between this one starting and reconciling, and completing it would overwrite their
     * relation state and leave this save unrecorded.
     *
     * ⚠️ THREE MECHANISMS HAVE ANSWERED THIS, and the first two were wrong:
     *
     * - An id comparison — "the newest revision is newer than the one before my write" — completes
     *   the intruder, because the intruder is newer.
     * - A STATE comparison replaced it, and review found the hole: it covers `VERSIONED_COLUMNS`
     *   and cannot cover `relation_state`, so two saves with equal scalars and different relations
     *   both claim the same revision. `RelationRevisionRaceTest` is that scenario.
     * - `RecordedRevisions` holds what this process actually filed, so the intruder is never a
     *   candidate. This test passes under it for a stronger reason than it used to: not "the
     *   intruder's snapshot differs from mine" but "this save filed nothing, so it owns nothing".
     *
     * ⚠️ Carrying the created id on the page does NOT work either, which is worth recording
     * because it is the obvious fix: for a create the revision is filed by `AuditedBuilder`, on an
     * instance it constructs rather than the one the page holds, so a property set in
     * `recordRevision()` is null by the time the page reconciles. Measured.
     */
    $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Source']);
    formSave($entry, [[$this->authors, [$this->target->id]]]);

    $before = $entry->revisions()->max('id');

    // Another request's save lands in the window: a newer revision describing a DIFFERENT title.
    $intruder = $entry->revisions()->create([
        'entry_type_id' => $entry->entry_type_id,
        'title' => 'Saved by somebody else',
        'slug' => $entry->slug,
        'status' => $entry->status,
        'values' => $entry->values,
        'relation_state' => ['999' => [1, 2, 3]],
    ]);

    beginFormSave($entry);
    formSave($entry, [[$this->authors, []]]);

    // The intruder is untouched, because this save filed no revision and so owns none.
    expect($intruder->fresh()->relation_state)->toBe(['999' => [1, 2, 3]])
        ->and($intruder->fresh()->title)->toBe('Saved by somebody else');

    // And this save is still recorded rather than silently lost.
    $mine = $entry->revisions()->where('id', '>', $intruder->getKey())->orderByDesc('id')->first();

    expect($mine)->not->toBeNull('this save left no revision of its own')
        ->and($mine->relation_state)->toBe([]);
});
