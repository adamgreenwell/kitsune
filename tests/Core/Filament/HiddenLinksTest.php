<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Filament\Schemas\FieldValueRenderer;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\PanelTenancy;

/*
 * A link a site cannot see is shown withheld and kept — ADR-042 decision 2, decided by Adam.
 *
 * A shared file is edited from every site of its org, and it can link to an entry only one of them sees. Hydrated
 * through the scoped join, that link was missing from the form at the other sites, and the save detached it without
 * the author ever seeing it. Under `PanelTenancy`, which moves Kitsune's context between the sites as a request would —
 * `SiteScope` is what hides the target — and gives the panel's own scope to the reads that ask for it.
 */

beforeEach(function (): void {
    $this->org = Org::create(['slug' => 'links', 'name' => 'Links']);
    $context = app(Context::class)->setOrg($this->org);
    $this->siteA = Site::create(['handle' => 'a', 'slug' => 'links-a', 'name' => 'A', 'locale' => 'en']);
    $this->siteB = Site::create(['handle' => 'b', 'slug' => 'links-b', 'name' => 'B', 'locale' => 'en']);

    $this->photo = EntryType::create(['org_id' => $this->org->id, 'handle' => 'photo', 'name' => 'Photo', 'plural_name' => 'Photos', 'is_media' => true]);
    $this->article = EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);

    $this->subjects = FieldStorage::create([
        'org_id' => $this->org->id, 'handle' => 'subjects', 'type' => 'relation', 'pii_class' => 'none',
        'cardinality' => -1, 'settings' => ['targetTypes' => ['article']],
    ]);
    $this->field = Field::create(['entry_type_id' => $this->photo->id, 'field_storage_id' => $this->subjects->id, 'label' => 'Subjects']);

    $context->setSite($this->siteA);
    $this->onA = Entry::create(['entry_type_id' => $this->article->id, 'title' => 'On A', 'slug' => 'on-a']);
    $this->alsoOnA = Entry::create(['entry_type_id' => $this->article->id, 'title' => 'Also on A', 'slug' => 'also-on-a']);
    $context->setSite($this->siteB);
    $this->onB = Entry::create(['entry_type_id' => $this->article->id, 'title' => 'On B', 'slug' => 'on-b']);
    $this->photoShared = Entry::create(['entry_type_id' => $this->photo->id, 'site_id' => null, 'title' => 'Shared photo']);
    $context->forget();

    // Linked while edited at A, where its target is visible.
    PanelTenancy::enter($this->siteA);
    $this->photoShared->syncFieldRelations($this->subjects, [$this->onA->id]);

    // Then opened at B, where it is not.
    PanelTenancy::moveTo($this->siteB);
});

afterEach(fn () => app(Context::class)->forget());

function storedLinks(Entry $source): array
{
    return DB::table('entry_relations')->where('source_entry_id', $source->id)->orderBy('ordering')->pluck('target_entry_id')
        ->map(fn ($id): int => (int) $id)->all();
}

it('hydrates a link this site cannot see, which the scoped join leaves out', function (): void {
    expect($this->photoShared->relatedIdsForField($this->subjects))->toBe([])
        ->and($this->photoShared->linkedIdsForField($this->subjects))->toBe([$this->onA->id]);
});

it('labels it as withheld, so the form still validates', function (): void {
    $labels = FieldValueRenderer::relationLabels([$this->onA->id], new FieldConfig($this->subjects, $this->field), $this->photoShared);

    expect($labels)->toBe([$this->onA->id => "Entry #{$this->onA->id} — not visible from this site"]);
});

/** ⚠️ ONLY A LINK THE ENTRY HOLDS. A forged id of an entry this site cannot see is refused as before, not labelled. */
it('labels no id the entry does not already link to', function (): void {
    $labels = FieldValueRenderer::relationLabels([$this->alsoOnA->id], new FieldConfig($this->subjects, $this->field), $this->photoShared);

    expect($labels)->toBe([]);
});

/*
 * ⚠️ NOR ONE WHOSE TYPE THE FIELD NO LONGER ACCEPTS — Codex, #151, by the route Codex named. A retype is refused while a
 * link to the entry is visible from where it happens, and with `photo` switched off at A the shared photo is not: so A
 * may retype its article, and B's form holds a link the field no longer accepts. Labelled, it passed Filament's
 * validation and the save kept its row without asking the type again; unlabelled, the author meets the message a
 * visible one meets.
 */
it('labels no hidden link whose type the field no longer accepts', function (): void {
    $note = EntryType::create(['org_id' => $this->org->id, 'handle' => 'note', 'name' => 'Note', 'plural_name' => 'Notes']);
    EntryTypeAvailability::create(['entry_type_id' => $this->photo->id, 'scope_type' => 'site', 'scope_id' => $this->siteA->id, 'is_enabled' => false]);

    PanelTenancy::moveTo($this->siteA);
    $this->onA->update(['entry_type_id' => $note->id]);
    PanelTenancy::moveTo($this->siteB);

    $labels = FieldValueRenderer::relationLabels([$this->onA->id], new FieldConfig($this->subjects, $this->field), $this->photoShared);

    expect(Entry::withoutGlobalScopes()->whereKey($this->onA->id)->value('type_handle'))->toBe('note')
        ->and($labels)->toBe([]);
});

/** A field that names no target type accepts every one, hidden links included. */
it('labels a hidden link on a field that accepts any type', function (): void {
    $anything = FieldStorage::create([
        'org_id' => $this->org->id, 'handle' => 'anything', 'type' => 'relation', 'pii_class' => 'none', 'cardinality' => -1,
    ]);
    $field = Field::create(['entry_type_id' => $this->photo->id, 'field_storage_id' => $anything->id, 'label' => 'Anything']);

    PanelTenancy::moveTo($this->siteA);
    $this->photoShared->syncFieldRelations($anything, [$this->onA->id]);
    PanelTenancy::moveTo($this->siteB);

    $labels = FieldValueRenderer::relationLabels([$this->onA->id], new FieldConfig($anything, $field), $this->photoShared);

    expect($labels)->toBe([$this->onA->id => "Entry #{$this->onA->id} — not visible from this site"]);
});

/** A link to an entry in the trash is kept, so it keeps its label — the trash hides it as another site does. */
it('labels a hidden link to an entry in the trash', function (): void {
    PanelTenancy::moveTo($this->siteA);
    $this->onA->delete();
    PanelTenancy::moveTo($this->siteB);

    $labels = FieldValueRenderer::relationLabels([$this->onA->id], new FieldConfig($this->subjects, $this->field), $this->photoShared);

    expect($labels)->toBe([$this->onA->id => "Entry #{$this->onA->id} — not visible from this site"]);
});

/**
 * ⚠️ READ PAST THE SCOPES, SO FENCED BY THE ORG. No guard lets a link cross orgs, and this one is written past every
 * guard to show that the label would not follow it there either.
 */
it('labels no link to another org\'s entry, however the row came to exist', function (): void {
    $rival = Org::create(['slug' => 'links-rival', 'name' => 'Rival']);
    $context = app(Context::class)->setOrg($rival);
    $theirSite = Site::create(['handle' => 'r', 'slug' => 'links-r', 'name' => 'R', 'locale' => 'en']);
    $context->setSite($theirSite);
    $rivalArticle = EntryType::create(['org_id' => $rival->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);

    // Quietly, or the panel's creation hook would stamp the site the panel is on — B, which is not the rival's.
    $theirs = Entry::createQuietly([
        'entry_type_id' => $rivalArticle->id, 'org_id' => $rival->id, 'site_id' => $theirSite->id, 'type_handle' => 'article',
        'title' => 'Theirs', 'slug' => 'theirs',
    ]);
    PanelTenancy::moveTo($this->siteB);

    DB::table('entry_relations')->insert([
        'org_id' => $this->org->id, 'source_entry_id' => $this->photoShared->id, 'target_entry_id' => $theirs->id,
        'field_storage_id' => $this->subjects->id, 'ordering' => 1,
    ]);

    $labels = FieldValueRenderer::relationLabels([$theirs->id], new FieldConfig($this->subjects, $this->field), $this->photoShared);

    expect($this->photoShared->linkedIdsForField($this->subjects))->toContain($theirs->id)
        ->and($labels)->toBe([]);
});

it('keeps the link through a save that hands it back, and adds what the author chose here', function (): void {
    $this->photoShared->syncFieldRelations($this->subjects, [$this->onA->id, $this->onB->id]);

    expect(storedLinks($this->photoShared))->toBe([$this->onA->id, $this->onB->id]);
});

/** The control: a link the author removes is removed — keeping hidden links is not refusing to change them. */
it('detaches it when the author takes it out', function (): void {
    $this->photoShared->syncFieldRelations($this->subjects, [$this->onB->id]);

    expect(storedLinks($this->photoShared))->toBe([$this->onB->id]);
});

/*
 * ⚠️ AND A RESTORE, which rebuilt every link and so recreated this one — and creating a link asks whether both ends are
 * visible from here. A shared entry is restored from any site of its org, so the restore was refused everywhere but at A
 * over a link it was not changing.
 */
describe('restoring a revision', function (): void {
    it('keeps a link this site cannot see, and restores what it can', function (): void {
        // The version to go back to: the hidden link, and one to an entry here.
        $this->photoShared->syncFieldRelations($this->subjects, [$this->onA->id, $this->onB->id]);
        $this->photoShared->update(['title' => 'With both']);
        $version = $this->photoShared->revisions()->latest('id')->firstOrFail();

        // Then the entry here is unlinked.
        $this->photoShared->syncFieldRelations($this->subjects, [$this->onA->id]);

        $this->photoShared->fresh()->restoreRevision($version);

        expect(storedLinks($this->photoShared))->toBe([$this->onA->id, $this->onB->id]);
    });

    /** The control: a hidden link the version did not have is removed, as restoring that version says. */
    it('removes a hidden link the version did not have', function (): void {
        // The version: only the entry here.
        $this->photoShared->syncFieldRelations($this->subjects, [$this->onB->id]);
        $this->photoShared->update(['title' => 'Only B']);
        $version = $this->photoShared->revisions()->latest('id')->firstOrFail();

        // Then, at A, the entry there is linked again — which B cannot see.
        PanelTenancy::moveTo($this->siteA);
        $this->photoShared->syncFieldRelations($this->subjects, [$this->onA->id, $this->onB->id]);
        PanelTenancy::moveTo($this->siteB);

        $this->photoShared->fresh()->restoreRevision($version);

        expect(storedLinks($this->photoShared))->toBe([$this->onB->id]);
    });

    /**
     * ⚠️ REMOVED BEFORE CREATED: cardinality counts the rows that are there, so a one-link field restored from one entry
     * back to another would hold both for a moment if the new row came first, and its own guard would refuse it.
     */
    it('restores a one-link field from one entry back to another', function (): void {
        $cover = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'cover', 'type' => 'relation', 'pii_class' => 'none',
            'cardinality' => 1, 'settings' => ['targetTypes' => ['article']],
        ]);
        Field::create(['entry_type_id' => $this->photo->id, 'field_storage_id' => $cover->id, 'label' => 'Cover']);

        app(Context::class)->setSite($this->siteB);
        $alsoOnB = Entry::create(['entry_type_id' => $this->article->id, 'title' => 'Also on B', 'slug' => 'also-on-b']);

        $this->photoShared->syncFieldRelations($cover, [$this->onB->id]);
        $this->photoShared->update(['title' => 'Cover is B']);
        $version = $this->photoShared->revisions()->latest('id')->firstOrFail();

        $this->photoShared->syncFieldRelations($cover, [$alsoOnB->id]);

        $this->photoShared->fresh()->restoreRevision($version);

        expect($this->photoShared->linkedIdsForField($cover))->toBe([$this->onB->id]);
    });

    /** Restoring a link to an entry this site cannot see, which the entry does not hold now, is still refused. */
    it('still refuses to create a link to an entry this site cannot see', function (): void {
        PanelTenancy::moveTo($this->siteA);
        $this->photoShared->syncFieldRelations($this->subjects, [$this->alsoOnA->id]);
        $this->photoShared->update(['title' => 'Also A']);
        $version = $this->photoShared->revisions()->latest('id')->firstOrFail();
        $this->photoShared->syncFieldRelations($this->subjects, []);

        PanelTenancy::moveTo($this->siteB);

        expect(fn () => $this->photoShared->fresh()->restoreRevision($version))->toThrow(RuntimeException::class);

        expect(storedLinks($this->photoShared))->toBe([]);
    });
});
