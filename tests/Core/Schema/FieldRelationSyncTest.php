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
use Kitsune\Core\Tenancy\Context;

/**
 * Reading and writing the relations behind ONE field (issue #39's relational leg).
 *
 * ⚠️ An entry relates through several fields at once — `authors` and `tags` both live in
 * `entry_relations` — so every operation here has to be scoped by `field_storage_id`. A
 * sync that ignored it would silently detach every other field's relations while appearing
 * to save one, and nothing would report it.
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

    $this->source = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Source']);
    $this->one = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'One']);
    $this->two = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Two']);
    $this->three = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Three']);
});

afterEach(fn () => app(Context::class)->forget());

it('keeps the order the author arranged', function (): void {
    /*
     * ⚠️ The arrangement IS the content. `entry_relations.ordering` exists for it, and a
     * reader without an ORDER BY hands back whatever the database felt like — so a list
     * reshuffles between page loads and an author cannot tell whether their edit saved.
     */
    $this->source->syncFieldRelations($this->authors, [$this->three->id, $this->one->id, $this->two->id]);

    expect($this->source->relatedIdsForField($this->authors))
        ->toBe([$this->three->id, $this->one->id, $this->two->id]);

    // Reordered, not re-added: the same three in a different arrangement.
    $this->source->syncFieldRelations($this->authors, [$this->two->id, $this->three->id, $this->one->id]);

    expect($this->source->relatedIdsForField($this->authors))
        ->toBe([$this->two->id, $this->three->id, $this->one->id])
        ->and(DB::table('entry_relations')->count())->toBe(3);
});

it('does not touch another field on the same entry', function (): void {
    /*
     * ⚠️ THE ONE THAT WOULD DESTROY DATA. Both fields' rows live in one table with one
     * source entry, so a sync that dropped the `field_storage_id` constraint would detach
     * `tags` while saving `authors` — and the author would see a saved form.
     */
    $this->source->syncFieldRelations($this->authors, [$this->one->id]);
    $this->source->syncFieldRelations($this->tags, [$this->two->id, $this->three->id]);

    // Replace authors entirely. Tags must be exactly as they were.
    $this->source->syncFieldRelations($this->authors, [$this->three->id]);

    expect($this->source->relatedIdsForField($this->authors))->toBe([$this->three->id])
        ->and($this->source->relatedIdsForField($this->tags))->toBe([$this->two->id, $this->three->id]);
});

it('detaches everything when given an empty set', function (): void {
    // Clearing a relation field is a legitimate edit, and it must not be mistaken for
    // "no change" — which is what a sync that skipped empty input would do.
    $this->source->syncFieldRelations($this->authors, [$this->one->id]);
    $this->source->syncFieldRelations($this->tags, [$this->two->id]);

    $this->source->syncFieldRelations($this->authors, []);

    expect($this->source->relatedIdsForField($this->authors))->toBe([])
        ->and($this->source->relatedIdsForField($this->tags))->toBe([$this->two->id]);
});

it('collapses a duplicate rather than storing two rows for one target', function (): void {
    /*
     * A select can hand back the same entry twice. Two rows pointing at one target is a
     * state nothing else in the schema expects — and refusing the save would lose every
     * other edit on the form over a mistake the UI permitted.
     */
    $this->source->syncFieldRelations($this->authors, [$this->one->id, $this->one->id, $this->two->id]);

    expect($this->source->relatedIdsForField($this->authors))->toBe([$this->one->id, $this->two->id]);
});

it('stamps org_id and the field storage on every row it writes', function (): void {
    // org_id is NOT NULL and the pivot has no model, so nothing but the relation can stamp
    // it — going through related() rather than the table is what supplies it.
    $this->source->syncFieldRelations($this->authors, [$this->one->id]);

    $row = DB::table('entry_relations')->first();

    expect($row->org_id)->toBe($this->org->id)
        ->and($row->field_storage_id)->toBe($this->authors->id)
        ->and($row->ordering)->toBe(0);
});

it('reads nothing for a field with no relations', function (): void {
    expect($this->source->relatedIdsForField($this->tags))->toBe([]);
});
