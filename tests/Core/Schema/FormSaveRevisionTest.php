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
use Kitsune\Core\Schema\RevisionWrites;
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
 * What the page does: remember the newest revision BEFORE the write, sync every field with
 * revisions suspended, then reconcile exactly one.
 *
 * ⚠️ The "before" id has to be captured before the entry write, which is why the trait takes it
 * in `mutateFormDataBefore*` — the last hook that runs first. Reading it afterwards cannot tell
 * a revision this save filed from one that was already there, and the first version of this
 * measurement got that wrong and reported 2 where the real flow gives 1.
 */
function formSave(Entry $entry, ?int $revisionIdBefore, array $sync): void
{
    $relationsBefore = $entry->relationState();

    RevisionWrites::suspend(function () use ($entry, $sync): void {
        foreach ($sync as [$storage, $ids]) {
            $entry->syncFieldRelations($storage, $ids);
        }
    });

    $entry->reconcileRevisionAfterRelationSync($revisionIdBefore, $relationsBefore);
}

it('records ONE revision for a create that fills two relation fields', function (): void {
    // A create has no prior revision, which is what the null says.
    $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Source']);

    formSave($entry, null, [
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
    formSave($entry, null, [[$this->authors, [$this->target->id]]]);

    $before = $entry->revisions()->max('id');
    $entry->title = 'Renamed';
    $entry->save();
    formSave($entry, $before, [[$this->authors, []]]);

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
    formSave($entry, null, [[$this->authors, [$this->target->id]]]);

    $before = $entry->revisions()->max('id');

    formSave($entry, $before, [[$this->authors, []]]);

    expect($entry->revisions()->count())->toBe(2, 'a relations-only edit filed no revision')
        ->and($entry->revisions()->orderByDesc('id')->first()->relation_state)->toBe([]);
});

it('files nothing when a save changed no relations at all', function (): void {
    // Otherwise every no-op save costs a version off a bounded history — the same argument the
    // JSON key-order duplicate already established.
    $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Source']);
    formSave($entry, null, [[$this->authors, [$this->target->id]]]);

    $count = $entry->revisions()->count();
    $before = $entry->revisions()->max('id');

    formSave($entry, $before, [[$this->authors, [$this->target->id]]]);

    expect($entry->revisions()->count())->toBe($count, 'a no-op save filed a revision');
});

it('leaves a revision alone when it describes a state this save did not produce', function (): void {
    /*
     * ⚠️ THE CONCURRENCY CASE, and the reason identity is judged by STATE rather than by id.
     * Two editors saving the same entry means another request can file a revision between this
     * one capturing "the newest before my write" and reconciling. An id comparison alone would
     * then complete SOMEBODY ELSE'S revision — overwriting their relation state and leaving this
     * save unrecorded.
     *
     * ⚠️ Carrying the created id forward instead does NOT work, which is worth a test comment
     * because it is the obvious fix: for a create the revision is filed by `AuditedBuilder`, on an
     * instance it constructs rather than the one the page holds, so a property set in
     * `recordRevision()` is null by the time the page reconciles. Measured.
     */
    $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Source']);
    formSave($entry, null, [[$this->authors, [$this->target->id]]]);

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

    formSave($entry, $before, [[$this->authors, []]]);

    // The intruder is untouched, because its snapshot is not the state this save produced.
    expect($intruder->fresh()->relation_state)->toBe(['999' => [1, 2, 3]])
        ->and($intruder->fresh()->title)->toBe('Saved by somebody else');

    // And this save is still recorded rather than silently lost.
    $mine = $entry->revisions()->where('id', '>', $intruder->getKey())->orderByDesc('id')->first();

    expect($mine)->not->toBeNull('this save left no revision of its own')
        ->and($mine->relation_state)->toBe([]);
});
