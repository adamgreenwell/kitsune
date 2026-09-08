<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRelation;
use Kitsune\Core\Models\EntryRevision;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Schema\RevisionWrites;
use Kitsune\Core\Tenancy\Context;

/*
 * A revision per saved version, so "what did this say last Tuesday" is
 * answerable — and so ADR-020's erasure has somewhere to reach.
 */

beforeEach(function (): void {
    $this->org = Org::create(['name' => 'Publisher', 'slug' => 'rev-pub']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['org_id' => $this->org->id, 'handle' => 'main', 'slug' => 'rev-main', 'name' => 'Main']);
    app(Context::class)->setSite($this->site);

    $this->type = EntryType::create([
        'org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles',
    ]);
});

afterEach(fn () => app(Context::class)->forget());

function anEntry(array $attributes = []): Entry
{
    return Entry::create([
        'entry_type_id' => test()->type->id,
        'title' => 'First',
        'values' => ['body' => 'one'],
        ...$attributes,
    ]);
}

describe('a revision per saved version', function (): void {
    it('records one when the entry is created', function (): void {
        $entry = anEntry();

        expect($entry->revisions()->count())->toBe(1)
            ->and($entry->revisions()->first()->title)->toBe('First');
    });

    it('records another on every content change', function (): void {
        $entry = anEntry();

        $entry->update(['title' => 'Second']);
        $entry->update(['values' => ['body' => 'two']]);

        expect($entry->revisionHistory()->pluck('title')->all())->toBe(['Second', 'Second', 'First']);
    });

    it('records NOTHING when nothing versioned changed', function (): void {
        // Touching a timestamp is not a new version of the content, and
        // recording one would fill the history with versions nobody authored.
        $entry = anEntry();

        $entry->touch();
        $entry->update(['author_id' => 7]);

        expect($entry->revisions()->count())->toBe(1);
    });

    it('snapshots the promoted columns, not only values', function (): void {
        // A revision holding only `values` restores an entry with no title,
        // which is worse than having no revisions at all.
        $entry = anEntry(['slug' => 'first', 'status' => 'published']);

        $revision = $entry->revisions()->first();

        expect($revision->title)->toBe('First')
            ->and($revision->slug)->toBe('first')
            ->and($revision->status)->toBe('published')
            ->and($revision->values)->toBe(['body' => 'one']);
    });

    it('records after the write, not before', function (): void {
        // A revision describing a save that then failed is a history of
        // things that never happened.
        $entry = anEntry();

        expect($entry->revisions()->first()->created_at)->not->toBeNull()
            ->and(Entry::whereKey($entry->getKey())->exists())->toBeTrue();
    });
});

describe('restoring puts state back as a NEW version', function (): void {
    it('writes the old state onto the entry', function (): void {
        $entry = anEntry();
        $original = $entry->revisions()->first();

        $entry->update(['title' => 'Changed', 'values' => ['body' => 'different']]);
        $entry->restoreRevision($original);

        expect($entry->fresh()->title)->toBe('First')
            ->and($entry->fresh()->values)->toBe(['body' => 'one']);
    });

    it('ADDS a version rather than rewriting history', function (): void {
        // Restoring version 1 must not delete version 2. Rewriting history
        // makes "what did this say last Tuesday" unanswerable, which is the
        // question revisions exist to answer.
        $entry = anEntry();
        $original = $entry->revisions()->first();

        $entry->update(['title' => 'Changed']);
        $entry->restoreRevision($original);

        expect($entry->revisions()->count())->toBe(3)
            ->and($entry->revisionHistory()->pluck('title')->all())->toBe(['First', 'Changed', 'First']);
    });

    it('refuses a revision belonging to another entry', function (): void {
        $mine = anEntry();
        $theirs = anEntry(['title' => 'Theirs', 'slug' => 'theirs']);

        expect(fn () => $mine->restoreRevision($theirs->revisions()->first()))
            ->toThrow(RuntimeException::class, 'belongs to another entry');
    });
});

it('keeps a bounded history, because full JSON snapshots are not free', function (): void {
    // The decision log lists revision storage growth as an open question and
    // it still is. Retention is the honest interim answer: a bound applied on
    // write, rather than a cost accumulating quietly while the question waits.
    $entry = anEntry();

    for ($i = 0; $i < Entry::KEEP_REVISIONS + 5; $i++) {
        $entry->update(['title' => "Version {$i}"]);
    }

    expect($entry->revisions()->count())->toBe(Entry::KEEP_REVISIONS)
        // The OLDEST go, not the newest.
        ->and($entry->revisionHistory()->first()->title)->toBe('Version '.(Entry::KEEP_REVISIONS + 4))
        ->and(EntryRevision::where('entry_id', $entry->getKey())->where('title', 'First')->exists())->toBeFalse();
});

it('leaves no revision behind for work that is not an authored change', function (): void {
    $entry = anEntry();

    $entry->withoutRevisions(fn (Entry $e) => $e->update(['title' => 'Silent']));

    expect($entry->revisions()->count())->toBe(1)
        ->and($entry->fresh()->title)->toBe('Silent');
});

it('restores revision recording afterwards, even when the work throws', function (): void {
    $entry = anEntry();

    try {
        $entry->withoutRevisions(fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
        // expected
    }

    $entry->update(['title' => 'After']);

    expect($entry->revisions()->count())->toBe(2);
});

describe('erasure reaches revisions by STRATEGY, not by guessing at the handle', function (): void {
    /*
     * ⚠️ `EntryRevision::redact()` decided whether a key was a promoted column
     * or a JSON key by testing it against `SNAPSHOT_ATTRIBUTES`. Nothing
     * reserves those names: `FieldStorage::guardHandle()` checks length and
     * snake_case and no more, so an INLINE field may legitimately be handled
     * `status`, `title`, `slug` or `published_at`.
     *
     * Erasing one wrote NULL into the revision's promoted column instead of its
     * `values` key, and on `status` — which is NOT NULL — the write threw. The
     * live entry had already been erased by then, so the outcome was: current
     * row cleared, every revision still holding the personal data, and a
     * QueryException instead of a count. A retry threw in the same place, so
     * the history could not be erased through this path at all.
     *
     * The caller knew the strategy the whole time. It now says so.
     */
    $inlineFieldHandled = function (string $handle): FieldStorage {
        $storage = FieldStorage::create([
            'org_id' => test()->org->id, 'handle' => $handle, 'type' => 'text',
            'pii_class' => 'personal', 'cardinality' => 1,
        ]);

        Field::create([
            'entry_type_id' => test()->type->id,
            'field_storage_id' => $storage->id,
            'label' => 'Note',
        ]);

        return $storage;
    };

    it('erases an inline field whose handle collides with a snapshot column', function () use ($inlineFieldHandled): void {
        $inlineFieldHandled('status');

        $entry = anEntry(['status' => 'published', 'values' => ['status' => 'Jane Doe, 12 Elm St']]);
        $entry->update(['values' => ['status' => 'Jane Doe, 14 Oak St']]);

        // Two revisions, both holding personal data in `values`.
        expect($entry->revisions()->count())->toBe(2);

        $rewritten = $entry->redactField('status', null);

        // The live row, and BOTH revisions.
        expect($rewritten)->toBe(3)
            ->and($entry->fresh()->values['status'])->toBeNull();

        foreach ($entry->revisions()->get() as $revision) {
            expect($revision->values['status'])->toBeNull()
                // ⚠️ And the workflow column is untouched. Erasing a field
                // that happens to share its name must not rewrite the
                // revision's publication state — that is history, not content.
                ->and($revision->status)->toBe('published');
        }
    });

    it('leaves the entry\'s own status column alone', function () use ($inlineFieldHandled): void {
        $inlineFieldHandled('status');

        $entry = anEntry(['status' => 'published', 'values' => ['status' => 'personal']]);

        $entry->redactField('status', null);

        expect($entry->fresh()->status)->toBe('published');
    });

    it('still erases a genuinely PROMOTED field from its column', function (): void {
        // `slug` is the one column a field type actually promotes to, so this
        // is the path the collision case must not be confused with.
        $storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'public_slug', 'type' => 'slug',
            'pii_class' => 'personal', 'cardinality' => 1,
        ]);
        Field::create([
            'entry_type_id' => $this->type->id, 'field_storage_id' => $storage->id, 'label' => 'Slug',
        ]);

        $entry = anEntry(['slug' => 'jane-doe']);
        $entry->update(['title' => 'Second']);

        // ⚠️ By the DECLARED column, not the handle — the field is handled
        // `public_slug` and writes `entries.slug`.
        $rewritten = $entry->redactField('public_slug', null);

        expect($rewritten)->toBeGreaterThan(0)
            ->and($entry->fresh()->slug)->toBeNull();

        foreach ($entry->revisions()->get() as $revision) {
            expect($revision->slug)->toBeNull();
        }
    });

    it('refuses to erase a column no promoted field can project to', function (): void {
        $entry = anEntry();
        $revision = $entry->revisions()->first();

        // Fail closed: Eloquent would set an unknown attribute happily and then
        // fail at the database, or succeed against a column erasure has no
        // business writing.
        expect(fn () => $revision->redactColumn('note', null))
            ->toThrow(RuntimeException::class, 'no erasable column');
    });

    it('refuses to erase the type DISCRIMINATOR, which it snapshots', function (): void {
        // ⚠️ Snapshotted and NOT erasable, which is why the two lists are
        // separate. Blanking a discriminator turns a revision into values with
        // no schema — worse than leaving the data, because the row then looks
        // restorable and is not.
        $entry = anEntry();
        $revision = $entry->revisions()->first();

        expect(fn () => $revision->redactColumn('entry_type_id', null))
            ->toThrow(RuntimeException::class, 'no erasable column')
            ->and($revision->fresh()->entry_type_id)->toBe($entry->entry_type_id);
    });
});

describe('a version records the schema its values were written against', function (): void {
    /*
     * ⚠️ `entry_type_id` is MUTABLE — the model supports changing it, restamping
     * `type_handle` and rechecking inbound relations — and revisions did not
     * record it.
     *
     * Two consequences. A type change filed no version at all, because the
     * versioned surface did not include the discriminator. And restoring a
     * revision authored under the old type wrote its `values` back onto an entry
     * that now resolves a DIFFERENT field set, so the same JSON was read against
     * the wrong schema: the quiet content destruction ADR-006 locks storage
     * shape to prevent, arriving through another door.
     */
    it('snapshots the type', function (): void {
        $entry = anEntry();

        expect($entry->revisions()->first()->entry_type_id)->toBe($this->type->id);
    });

    it('records a version when the TYPE changes', function (): void {
        $other = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'note', 'name' => 'Note', 'plural_name' => 'Notes',
        ]);
        $entry = anEntry();

        $entry->update(['entry_type_id' => $other->id]);

        expect($entry->revisions()->count())->toBe(2)
            ->and($entry->revisions()->latest('id')->first()->entry_type_id)->toBe($other->id);
    });

    it('REFUSES a restore across a type change', function (): void {
        $other = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'note2', 'name' => 'Note', 'plural_name' => 'Notes',
        ]);
        $entry = anEntry(['values' => ['body' => 'authored as an article']]);
        $original = $entry->revisions()->first();

        $entry->update(['entry_type_id' => $other->id]);

        expect(fn () => $entry->restoreRevision($original))
            ->toThrow(RuntimeException::class, 'read them against a different schema');

        // And nothing moved: a refused restore must not half-apply.
        expect($entry->fresh()->entry_type_id)->toBe($other->id);
    });

    it('still allows a restore within the same type', function (): void {
        $entry = anEntry(['values' => ['body' => 'one']]);
        $first = $entry->revisions()->first();

        $entry->update(['values' => ['body' => 'two']]);

        $entry->restoreRevision($first);

        expect($entry->fresh()->values['body'])->toBe('one');
    });

    it('allows a restore once the type is changed BACK', function (): void {
        // The refusal is not a dead end — it names the way through.
        $other = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'note3', 'name' => 'Note', 'plural_name' => 'Notes',
        ]);
        $entry = anEntry(['values' => ['body' => 'one']]);
        $original = $entry->revisions()->first();

        $entry->update(['entry_type_id' => $other->id, 'values' => ['body' => 'two']]);
        $entry->update(['entry_type_id' => $this->type->id]);

        $entry->restoreRevision($original);

        expect($entry->fresh()->values['body'])->toBe('one')
            ->and($entry->fresh()->entry_type_id)->toBe($this->type->id);
    });
});

describe('a bulk write is still a version', function (): void {
    /*
     * ⚠️ Revisions were recorded from `created` and `updated`. A bulk builder
     * write dispatches neither, and `Entry::query()->update(...)` is a write
     * this project deliberately ALLOWS and audits — `AuditLogTest` asserts it
     * produces an audit row, and `SubjectIdentifierTest` asserts the per-row
     * guards do not refuse it.
     *
     * So a bulk publish moved a versioned column with no version recorded. The
     * gap is not merely cosmetic: the newest revision no longer described the
     * entry, so "restore the latest version" silently reverted the bulk change.
     *
     * This is the sixth time in this project that a guard on a model event has
     * turned out to be a guard on one path. Recording moved to the builder,
     * which is where the write actually is.
     */
    it('records one for a bulk update to a versioned column', function (): void {
        $entry = anEntry();

        expect($entry->revisions()->count())->toBe(1);

        Entry::query()->whereKey($entry->getKey())->update(['status' => 'published']);

        expect($entry->revisions()->count())->toBe(2)
            ->and($entry->revisions()->latest('id')->first()->status)->toBe('published');
    });

    it('records one for a bulk write to `values`', function (): void {
        $entry = anEntry();

        Entry::query()->whereKey($entry->getKey())->update(['values' => json_encode(['body' => 'two'])]);

        expect($entry->revisions()->count())->toBe(2)
            ->and($entry->revisions()->latest('id')->first()->values['body'])->toBe('two');
    });

    it('records NOTHING for a bulk write that changes no version', function (): void {
        $entry = anEntry();

        // `updated_at` is not content, and a soft delete is not an authored
        // version either.
        Entry::query()->whereKey($entry->getKey())->touch();
        $entry->delete();

        expect($entry->revisions()->count())->toBe(1);
    });

    it('records NOTHING when the bulk write matches but alters nothing', function (): void {
        // ⚠️ CHANGED, not merely matched. Setting a column to the value it
        // already holds matches every row and alters none, and one revision per
        // matched row would fill the history with copies of its predecessor.
        $entry = anEntry(['status' => 'published']);

        Entry::query()->whereKey($entry->getKey())->update(['status' => 'published']);

        expect($entry->revisions()->count())->toBe(1);
    });

    it('records exactly ONE for an ordinary instance save', function (): void {
        /*
         * ⚠️ The trap. `Model::performUpdate()` writes through this same
         * builder, so recording in the builder as well as in `updated` files
         * TWO revisions for one save.
         *
         * That is not hypothetical — adding the audit builder alongside the
         * audit listeners double-recorded every entry write, and no test caught
         * it because they asserted a row exists and two satisfy that. So this
         * asserts the COUNT.
         */
        $entry = anEntry();

        $entry->update(['title' => 'Second']);

        expect($entry->revisions()->count())->toBe(2);
    });

    it('records one per affected row, not one per statement', function (): void {
        $first = anEntry(['slug' => 'a']);
        $second = anEntry(['slug' => 'b']);
        $unaffected = anEntry(['slug' => 'c', 'status' => 'published']);

        Entry::query()->where('status', 'draft')->update(['status' => 'published']);

        expect($first->revisions()->count())->toBe(2)
            ->and($second->revisions()->count())->toBe(2)
            // Already published, so it matched nothing and gets no version.
            ->and($unaffected->revisions()->count())->toBe(1);
    });

    it('is stood down by withoutRevisions, which the builder can now see', function (): void {
        /*
         * ⚠️ The escape hatch has to reach BOTH recorders, and this is the
         * second time that lesson has been learned here — `withoutScopeBecause()`
         * once stood down the model event and not the builder. `withoutRevisions()`
         * held its flag on the instance, which a builder has no way to read.
         *
         * Erasure depends on it: `redactField()` writes inside
         * `withoutRevisions()` so that clearing personal data does not file the
         * redacted state as a new version of the history being cleared.
         */
        $entry = anEntry();

        RevisionWrites::suspend(function () use ($entry): void {
            Entry::query()->whereKey($entry->getKey())->update(['status' => 'published']);
        });

        expect($entry->revisions()->count())->toBe(1)
            ->and($entry->fresh()->status)->toBe('published');
    });

    it('prunes the bulk-recorded history like any other', function (): void {
        // The bulk path goes through the model's own snapshot logic rather than
        // inserting rows itself, so KEEP_REVISIONS still bounds growth — a bulk
        // write loop must not be a way around the cap.
        $entry = anEntry();

        foreach (range(1, Entry::KEEP_REVISIONS + 5) as $i) {
            Entry::query()->whereKey($entry->getKey())->update(['title' => "Bulk {$i}"]);
        }

        expect($entry->revisions()->count())->toBe(Entry::KEEP_REVISIONS);
    });
});

describe('a version includes the third storage strategy', function (): void {
    /*
     * ⚠️ ADR-006 has THREE storage strategies and revisions handled two.
     *
     * A relational field's data is rows in `entry_relations` — not a key in
     * `values`, not a promoted column. So two things were wrong at once. A pivot
     * write fires no `Entry` event, so changing a relation filed no version at
     * all; and the snapshot omitted relations, so restoring a revision left every
     * relation at its CURRENT value while telling the author the entry now
     * matched the version they picked.
     *
     * This project has been burned by the same "handled two of the three"
     * omission twice already — a subject identifier that answered null for a
     * relational field, and an erasure that reported success while every link
     * survived.
     */
    $relationalField = function (): FieldStorage {
        $storage = FieldStorage::create([
            'org_id' => test()->org->id, 'handle' => 'people', 'type' => 'relation',
            'pii_class' => 'none', 'cardinality' => -1,
        ]);

        Field::create([
            'entry_type_id' => test()->type->id,
            'field_storage_id' => $storage->id,
            'label' => 'People',
        ]);

        return $storage;
    };

    it('snapshots the related ids, in order', function () use ($relationalField): void {
        $storage = $relationalField();
        $entry = anEntry();
        $alice = anEntry(['title' => 'Alice']);
        $bob = anEntry(['title' => 'Bob']);

        $entry->related()->attach($alice->id, ['field_storage_id' => $storage->id, 'ordering' => 0]);
        $entry->related()->attach($bob->id, ['field_storage_id' => $storage->id, 'ordering' => 1]);

        $latest = $entry->revisions()->latest('id')->first();

        expect($latest->relation_state)->toBe([(string) $storage->id => [$alice->id, $bob->id]]);
    });

    it('records a version when a relation is ATTACHED', function () use ($relationalField): void {
        $storage = $relationalField();
        $entry = anEntry();
        $alice = anEntry(['title' => 'Alice']);

        $before = $entry->revisions()->count();

        $entry->related()->attach($alice->id, ['field_storage_id' => $storage->id]);

        expect($entry->revisions()->count())->toBe($before + 1);
    });

    it('records a version when a relation is DETACHED', function () use ($relationalField): void {
        // Detaching never needed the cardinality lock, so `detach()` was not
        // overridden at all — but it changes the entry just as much.
        $storage = $relationalField();
        $entry = anEntry();
        $alice = anEntry(['title' => 'Alice']);
        $entry->related()->attach($alice->id, ['field_storage_id' => $storage->id]);

        $before = $entry->revisions()->count();

        $entry->related()->detach($alice->id);

        expect($entry->revisions()->count())->toBe($before + 1);
    });

    it('records NOTHING for a detach that matched nothing', function () use ($relationalField): void {
        $relationalField();
        $entry = anEntry();
        $unrelated = anEntry(['title' => 'Unrelated']);

        $before = $entry->revisions()->count();

        $entry->related()->detach($unrelated->id);

        expect($entry->revisions()->count())->toBe($before);
    });

    it('records ONE version for a sync, not one per inner call', function () use ($relationalField): void {
        // ⚠️ `sync()` calls `attach()` and `detach()` on the same instance, so
        // recording in each would file two or three versions for one sync.
        $storage = $relationalField();
        $entry = anEntry();
        $alice = anEntry(['title' => 'Alice']);
        $bob = anEntry(['title' => 'Bob']);
        $entry->related()->attach($alice->id, ['field_storage_id' => $storage->id]);

        $before = $entry->revisions()->count();

        $entry->related()->sync([$bob->id => ['field_storage_id' => $storage->id]]);

        expect($entry->revisions()->count())->toBe($before + 1);
    });

    it('RESTORES the relations a revision recorded', function () use ($relationalField): void {
        $storage = $relationalField();
        $entry = anEntry();
        $alice = anEntry(['title' => 'Alice']);
        $bob = anEntry(['title' => 'Bob']);

        $entry->related()->attach($alice->id, ['field_storage_id' => $storage->id]);
        $withAlice = $entry->revisions()->latest('id')->first();

        $entry->related()->detach($alice->id);
        $entry->related()->attach($bob->id, ['field_storage_id' => $storage->id]);

        expect($entry->related()->pluck('entries.id')->all())->toBe([$bob->id]);

        $entry->restoreRevision($withAlice);

        expect($entry->related()->pluck('entries.id')->all())->toBe([$alice->id]);
    });

    it('restores the ORDER, not just the set', function () use ($relationalField): void {
        // `ordering` is what the author arranged, so a restore that puts the
        // right entries back in the wrong sequence has still lost the version.
        $storage = $relationalField();
        $entry = anEntry();
        $alice = anEntry(['title' => 'Alice']);
        $bob = anEntry(['title' => 'Bob']);

        $entry->related()->attach($alice->id, ['field_storage_id' => $storage->id, 'ordering' => 0]);
        $entry->related()->attach($bob->id, ['field_storage_id' => $storage->id, 'ordering' => 1]);
        $aliceFirst = $entry->revisions()->latest('id')->first();

        $entry->related()->detach();
        $entry->related()->attach($bob->id, ['field_storage_id' => $storage->id, 'ordering' => 0]);
        $entry->related()->attach($alice->id, ['field_storage_id' => $storage->id, 'ordering' => 1]);

        $entry->restoreRevision($aliceFirst);

        expect(
            EntryRelation::query()
                ->where('source_entry_id', $entry->getKey())
                ->orderBy('ordering')
                ->pluck('target_entry_id')
                ->all(),
        )->toBe([$alice->id, $bob->id]);
    });

    it('REFUSES a restore whose related entry is gone', function () use ($relationalField): void {
        // ⚠️ Refused rather than partially applied. Quietly restoring the subset
        // would report success for a version the author cannot actually have
        // back — and `entry_relations` cascades on delete, so the row would fail
        // its foreign key anyway. This says why instead.
        $storage = $relationalField();
        $entry = anEntry();
        $alice = anEntry(['title' => 'Alice']);

        $entry->related()->attach($alice->id, ['field_storage_id' => $storage->id]);
        $withAlice = $entry->revisions()->latest('id')->first();

        $alice->forceDelete();

        expect(fn () => $entry->restoreRevision($withAlice))
            ->toThrow(RuntimeException::class, 'no longer exist');
    });

    it('leaves relations alone for a revision that recorded none', function (): void {
        // A revision written before the column existed holds null, and clearing
        // an entry's relations on that basis would destroy data the revision
        // never claimed to describe.
        $storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'people2', 'type' => 'relation',
            'pii_class' => 'none', 'cardinality' => -1,
        ]);
        Field::create([
            'entry_type_id' => $this->type->id, 'field_storage_id' => $storage->id, 'label' => 'People',
        ]);

        $entry = anEntry();
        $alice = anEntry(['title' => 'Alice']);
        $revision = $entry->revisions()->first();
        $revision->forceFill(['relation_state' => null])->save();

        $entry->related()->attach($alice->id, ['field_storage_id' => $storage->id]);

        $entry->restoreRevision($revision);

        expect($entry->related()->pluck('entries.id')->all())->toBe([$alice->id]);
    });

    it('is stood down by withoutRevisions', function () use ($relationalField): void {
        $storage = $relationalField();
        $entry = anEntry();
        $alice = anEntry(['title' => 'Alice']);

        $before = $entry->revisions()->count();

        RevisionWrites::suspend(function () use ($entry, $alice, $storage): void {
            $entry->related()->attach($alice->id, ['field_storage_id' => $storage->id]);
        });

        expect($entry->revisions()->count())->toBe($before)
            ->and($entry->related()->pluck('entries.id')->all())->toBe([$alice->id]);
    });
});

describe('a quiet save is still a version', function (): void {
    /*
     * ⚠️ `saveQuietly()` and `updateQuietly()` suppress the `updated` listener
     * while still writing through the builder — and the builder skipped every
     * instance save to avoid double-recording. So NEITHER recorder ran: the
     * entry moved on and its newest revision stayed stale, which makes "restore
     * the latest version" revert a change nobody recorded.
     *
     * `withoutRevisions()` is meant to be the explicit opt-out. A quiet save is
     * not that: it says "do not fire events", not "do not keep history".
     *
     * The discriminator is Laravel's own mechanism — `withoutEvents()` unsets
     * the dispatcher — so the builder records exactly when no listener will.
     */
    it('records one for updateQuietly', function (): void {
        $entry = anEntry();

        $entry->updateQuietly(['title' => 'Quietly changed']);

        expect($entry->revisions()->count())->toBe(2)
            ->and($entry->revisions()->latest('id')->first()->title)->toBe('Quietly changed');
    });

    it('records one for saveQuietly', function (): void {
        $entry = anEntry();

        $entry->values = ['body' => 'quiet'];
        $entry->saveQuietly();

        expect($entry->revisions()->count())->toBe(2)
            ->and($entry->revisions()->latest('id')->first()->values['body'])->toBe('quiet');
    });

    it('records NOTHING for a quiet save that changes nothing versioned', function (): void {
        $entry = anEntry();

        $entry->updateQuietly(['type_handle' => $entry->type_handle]);

        expect($entry->revisions()->count())->toBe(1);
    });

    it('still records exactly ONE for a noisy instance save', function (): void {
        // The regression the `exists` skip existed to prevent. The dispatcher
        // test has to keep preventing it, or every ordinary save files two.
        $entry = anEntry();

        $entry->update(['title' => 'Loudly changed']);

        expect($entry->revisions()->count())->toBe(2);
    });

    it('is still stood down by withoutRevisions, quietly or not', function (): void {
        $entry = anEntry();

        $entry->withoutRevisions(fn (Entry $e) => $e->updateQuietly(['title' => 'Silent']));

        expect($entry->revisions()->count())->toBe(1)
            ->and($entry->fresh()->title)->toBe('Silent');
    });
});

describe('every revision names the schema it was written against', function (): void {
    it('cannot be written without a type', function (): void {
        // ⚠️ NOT NULL, and nullable put a restore-time constraint violation one
        // row away: `snapshot()` includes this column and `restoreRevision()`
        // fills the entry from it, while `entries.entry_type_id` is NOT NULL —
        // so a discriminator-less revision ended the restore in a database
        // error. Every revision is written from an entry whose own column is
        // NOT NULL, so the schema states the invariant instead.
        $entry = anEntry();

        // ⚠️ Inside its own transaction, which is a SAVEPOINT here.
        //
        // PostgreSQL aborts the entire transaction when a statement fails —
        // `SQLSTATE[25P02] current transaction is aborted, commands ignored` —
        // and `RefreshDatabase` has already opened one, so provoking a
        // constraint violation poisoned everything after it. The test passed
        // alone and failed in the full suite, which is the least helpful way for
        // a failure to present. Rolling back to a savepoint clears the aborted
        // state and leaves the surrounding transaction usable.
        expect(fn () => DB::transaction(fn () => EntryRevision::create([
            'entry_id' => $entry->id,
            'values' => ['body' => 'orphaned'],
            // ⚠️ The COLUMN name only. Each engine words a not-null violation
            // differently — SQLite says `entry_revisions.entry_type_id`,
            // PostgreSQL says `column "entry_type_id"`, MySQL says
            // `Column 'entry_type_id' cannot be null` — so matching more than
            // this passes on SQLite and fails the other three legs. Found by
            // the matrix, which is what it is for.
        ])))->toThrow(QueryException::class, 'entry_type_id');
    });
});

describe('a restore is one version, atomic, and authoritative', function (): void {
    $relationalField = function (string $handle = 'people'): FieldStorage {
        $storage = FieldStorage::create([
            'org_id' => test()->org->id, 'handle' => $handle, 'type' => 'relation',
            'pii_class' => 'personal', 'cardinality' => -1,
        ]);

        Field::create([
            'entry_type_id' => test()->type->id, 'field_storage_id' => $storage->id, 'label' => 'People',
        ]);

        return $storage;
    };

    it('treats an EMPTY snapshot as authoritative, not as silence', function () use ($relationalField): void {
        // ⚠️ `[]` is a statement: at that version this entry had no relations.
        // Treating it as "nothing recorded" left every current link attached
        // while reporting a successful restore. Only `null` — a revision written
        // before the column existed — says nothing.
        $storage = $relationalField();
        $entry = anEntry();
        $alice = anEntry(['title' => 'Alice']);

        $empty = $entry->revisions()->first();
        expect($empty->relation_state)->toBe([]);

        $entry->related()->attach($alice->id, ['field_storage_id' => $storage->id]);
        expect($entry->related()->count())->toBe(1);

        $entry->restoreRevision($empty);

        expect($entry->related()->count())->toBe(0);
    });

    it('REPLACES rather than patching, so a later field is not left behind', function () use ($relationalField): void {
        // ⚠️ The rebuild deleted only the storage ids present in the snapshot, so
        // a relation added to a DIFFERENT field after the revision was taken
        // survived the restore.
        $people = $relationalField('people');
        $entry = anEntry();
        $alice = anEntry(['title' => 'Alice']);
        $bob = anEntry(['title' => 'Bob']);

        $entry->related()->attach($alice->id, ['field_storage_id' => $people->id]);
        $onlyAlice = $entry->revisions()->latest('id')->first();

        $editors = $relationalField('editors');
        $entry->related()->attach($bob->id, ['field_storage_id' => $editors->id]);

        $entry->restoreRevision($onlyAlice);

        expect($entry->related()->pluck('entries.id')->all())->toBe([$alice->id]);
    });

    it('records ONE version, describing the entry as restored', function () use ($relationalField): void {
        // ⚠️ The scalar save fired `updated`, so the revision it filed described
        // the entry with its OLD relations — and when nothing scalar changed it
        // filed nothing at all, leaving the newest revision not describing the
        // entry. Restoring that would revert the relations again.
        $storage = $relationalField();
        $entry = anEntry();
        $alice = anEntry(['title' => 'Alice']);

        $entry->related()->attach($alice->id, ['field_storage_id' => $storage->id]);
        $withAlice = $entry->revisions()->latest('id')->first();

        $entry->related()->detach($alice->id);
        $before = $entry->revisions()->count();

        $entry->restoreRevision($withAlice);

        expect($entry->revisions()->count())->toBe($before + 1)
            ->and($entry->revisions()->latest('id')->first()->relation_state)
            ->toBe([(string) $storage->id => [$alice->id]]);
    });

    it('records NOTHING for a restore that changes nothing', function () use ($relationalField): void {
        $relationalField();
        $entry = anEntry();
        $newest = $entry->revisions()->latest('id')->first();

        $before = $entry->revisions()->count();

        $entry->restoreRevision($newest);

        expect($entry->revisions()->count())->toBe($before);
    });

    it('changes NOTHING when a recorded target is gone', function () use ($relationalField): void {
        // ⚠️ The refusal says "nothing has been changed", and validating after
        // the entry save made that a lie for the scalar half: the title was
        // already persisted when the rebuild threw.
        $storage = $relationalField();
        $entry = anEntry(['title' => 'Original']);
        $alice = anEntry(['title' => 'Alice']);

        $entry->related()->attach($alice->id, ['field_storage_id' => $storage->id]);
        $withAlice = $entry->revisions()->latest('id')->first();

        $entry->update(['title' => 'Current']);
        $alice->forceDelete();

        expect(fn () => $entry->restoreRevision($withAlice))
            ->toThrow(RuntimeException::class, 'no longer exist');

        expect($entry->fresh()->title)->toBe('Current');
    });
});

describe('erasure reaches the relations recorded in history', function (): void {
    /*
     * ⚠️ A new place to store relations is a new place erasure has to sweep.
     *
     * The relational branch deleted the live pivots and returned, so every erased
     * target id stayed in `relation_state` — and restoring one of those revisions
     * would recreate the relation, undoing the erasure. ADR-020 requires erasure
     * to reach revision history, and that requirement does not care which column
     * the data is in.
     */
    it('removes the erased field from every revision snapshot', function (): void {
        $storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'patient', 'type' => 'relation',
            'pii_class' => 'sensitive', 'cardinality' => -1,
        ]);
        Field::create([
            'entry_type_id' => $this->type->id, 'field_storage_id' => $storage->id, 'label' => 'Patient',
        ]);

        $entry = anEntry();
        $alice = anEntry(['title' => 'Alice']);

        $entry->related()->attach($alice->id, ['field_storage_id' => $storage->id]);
        $recorded = $entry->revisions()->latest('id')->first();

        expect($recorded->relation_state)->toBe([(string) $storage->id => [$alice->id]]);

        $entry->redactField('patient');

        foreach ($entry->revisions()->get() as $revision) {
            expect($revision->relation_state ?? [])->not->toHaveKey((string) $storage->id);
        }
    });

    it('so restoring an erased version cannot bring the link back', function (): void {
        // The consequence, asserted end to end — which is what ADR-020 is about.
        $storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'patient2', 'type' => 'relation',
            'pii_class' => 'sensitive', 'cardinality' => -1,
        ]);
        Field::create([
            'entry_type_id' => $this->type->id, 'field_storage_id' => $storage->id, 'label' => 'Patient',
        ]);

        $entry = anEntry();
        $alice = anEntry(['title' => 'Alice']);
        $entry->related()->attach($alice->id, ['field_storage_id' => $storage->id]);
        $recorded = $entry->revisions()->latest('id')->first();

        $entry->redactField('patient2');
        $entry->restoreRevision($recorded->fresh());

        expect($entry->related()->count())->toBe(0);
    });
});

describe('quiet creation still files an initial version', function (): void {
    it('records one for createQuietly', function (): void {
        // ⚠️ The creation half of the quiet-save gap. A quiet UPDATE is treated
        // as a version, so an entry arriving with no initial revision at all made
        // the two halves disagree about what a quiet write means.
        // ⚠️ Every DERIVED column is explicit, because `createQuietly()`
        // suppresses the listeners that normally stamp them: `EnforcesScope`'s
        // `creating` supplies `org_id` and `site_id`, and `Entry::saving()`
        // restamps `type_handle` from the type. A quiet create has to supply all
        // three or the insert fails its own NOT NULL constraints — which is
        // exactly why an importer is the realistic caller for this path, and why
        // it needs an initial revision as much as any other.
        $entry = Entry::createQuietly([
            'org_id' => $this->org->id,
            'site_id' => $this->site->id,
            'entry_type_id' => $this->type->id,
            'type_handle' => $this->type->handle,
            'title' => 'Imported',
            'values' => ['body' => 'from a feed'],
        ]);

        expect($entry->revisions()->count())->toBe(1)
            ->and($entry->revisions()->first()->title)->toBe('Imported');
    });

    it('still records exactly ONE for an ordinary create', function (): void {
        // The other half of the discriminator: recording in both the listener and
        // the builder would file two for one insert.
        $entry = anEntry();

        expect($entry->revisions()->count())->toBe(1);
    });
});

describe('every supported relation write files a version', function (): void {
    /*
     * ⚠️ Recording lived only on `GuardedBelongsToMany`, so the ordinary Eloquent
     * surface bypassed it — `EntryRelation::create()`, a predicate delete, and an
     * `ordering` update, which `GuardedRelationBuilder` explicitly permits. Each
     * changes relational content, and each left the newest revision stale so
     * restoring it silently undid the change.
     */
    $relationalField = function (): FieldStorage {
        $storage = FieldStorage::create([
            'org_id' => test()->org->id, 'handle' => 'people', 'type' => 'relation',
            'pii_class' => 'none', 'cardinality' => -1,
        ]);

        Field::create([
            'entry_type_id' => test()->type->id, 'field_storage_id' => $storage->id, 'label' => 'People',
        ]);

        return $storage;
    };

    it('records one for a direct EntryRelation::create', function () use ($relationalField): void {
        $storage = $relationalField();
        $entry = anEntry();
        $alice = anEntry(['title' => 'Alice']);

        $before = $entry->revisions()->count();

        EntryRelation::create([
            'org_id' => $this->org->id,
            'source_entry_id' => $entry->getKey(),
            'target_entry_id' => $alice->getKey(),
            'field_storage_id' => $storage->getKey(),
            'ordering' => 0,
        ]);

        expect($entry->revisions()->count())->toBe($before + 1)
            ->and($entry->revisions()->latest('id')->first()->relation_state)
            ->toBe([(string) $storage->id => [$alice->id]]);
    });

    it('records one for a predicate delete', function () use ($relationalField): void {
        $storage = $relationalField();
        $entry = anEntry();
        $alice = anEntry(['title' => 'Alice']);
        $entry->related()->attach($alice->id, ['field_storage_id' => $storage->id]);

        $before = $entry->revisions()->count();

        EntryRelation::query()->where('source_entry_id', $entry->getKey())->delete();

        expect($entry->revisions()->count())->toBe($before + 1)
            ->and($entry->revisions()->latest('id')->first()->relation_state)->toBe([]);
    });

    it('records one for an ORDERING update, which that builder permits', function () use ($relationalField): void {
        // Order is part of what a revision records: restoring the right targets in
        // the wrong sequence has still lost the version.
        $storage = $relationalField();
        $entry = anEntry();
        $alice = anEntry(['title' => 'Alice']);
        $bob = anEntry(['title' => 'Bob']);
        $entry->related()->attach($alice->id, ['field_storage_id' => $storage->id, 'ordering' => 0]);
        $entry->related()->attach($bob->id, ['field_storage_id' => $storage->id, 'ordering' => 1]);

        $before = $entry->revisions()->count();

        EntryRelation::query()
            ->where('source_entry_id', $entry->getKey())
            ->where('target_entry_id', $alice->getKey())
            ->update(['ordering' => 5]);

        expect($entry->revisions()->count())->toBe($before + 1)
            ->and($entry->revisions()->latest('id')->first()->relation_state)
            ->toBe([(string) $storage->id => [$bob->id, $alice->id]]);
    });

    it('records ONE for an attach, not one per builder it passes through', function () use ($relationalField): void {
        // ⚠️ A per-instance depth counter did not hold: one attach starts on
        // `GuardedBelongsToMany` and lands on `EntryRelation`'s own builder, a
        // different object with its own counter — so both recorded. The shared
        // suspension makes the outermost write the only recorder.
        $storage = $relationalField();
        $entry = anEntry();
        $alice = anEntry(['title' => 'Alice']);

        $before = $entry->revisions()->count();

        $entry->related()->attach($alice->id, ['field_storage_id' => $storage->id]);

        expect($entry->revisions()->count())->toBe($before + 1);
    });

    it('files NOTHING for the relations an erasure clears', function () use ($relationalField): void {
        // ⚠️ Adding a recorder added a place erasure has to stand it down. An
        // erasure is not an authored version, and filing one would add a row to
        // the history it is clearing.
        $storage = $relationalField();
        $entry = anEntry();
        $alice = anEntry(['title' => 'Alice']);
        $entry->related()->attach($alice->id, ['field_storage_id' => $storage->id]);

        $before = $entry->revisions()->count();

        $entry->redactField('people');

        expect($entry->revisions()->count())->toBe($before);
    });
});

describe('the restore does not fail over schema that has since gone', function (): void {
    it('discards a snapshot entry whose storage was deleted', function (): void {
        /*
         * ⚠️ Deleting a FieldStorage nulls its pivots (`nullOnDelete`) but leaves
         * its id in every snapshot. Rebuilding supplied that id and hit the
         * foreign key, so the History action failed because an unrelated field had
         * been removed.
         *
         * Discarded rather than refused, unlike a missing target: the relation is
         * gone as a concept — there is no field left to restore it into — and
         * refusing would make every revision written before that removal
         * permanently unrestorable.
         */
        $storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'doomed', 'type' => 'relation',
            'pii_class' => 'none', 'cardinality' => -1,
        ]);
        Field::create([
            'entry_type_id' => $this->type->id, 'field_storage_id' => $storage->id, 'label' => 'Doomed',
        ]);

        $entry = anEntry(['title' => 'Before']);
        $alice = anEntry(['title' => 'Alice']);
        $entry->related()->attach($alice->id, ['field_storage_id' => $storage->id]);
        $recorded = $entry->revisions()->latest('id')->first();

        $entry->update(['title' => 'After']);

        // Removing the storage nulls the pivot and orphans the snapshot's id.
        RevisionWrites::suspend(fn () => $storage->delete());

        $entry->restoreRevision($recorded->fresh());

        // The scalar half restored, and the vanished field simply is not rebuilt.
        expect($entry->fresh()->title)->toBe('Before')
            ->and(EntryRelation::query()->where('source_entry_id', $entry->getKey())->whereNotNull('field_storage_id')->count())
            ->toBe(0);
    });
});

describe('an arithmetic write is still a version', function (): void {
    it('records one when $extra assigns a versioned column', function (): void {
        // ⚠️ Laravel's arithmetic methods take an `$extra` map of ordinary
        // assignments, and those columns were never passed to revision detection —
        // so `increment(..., ['status' => 'published'])` was audited, changed a
        // versioned column, and filed no version. Restoring the latest revision
        // would then have reverted the publication.
        $entry = anEntry();

        $before = $entry->revisions()->count();

        // ⚠️ The key incremented by ZERO, which is the shape that makes this a
        // real exposure rather than a curiosity: `entries` has no ordinary numeric
        // column, so the arithmetic itself is a no-op and the `$extra` assignment
        // is the whole point. Audited, and until now unversioned.
        Entry::query()->whereKey($entry->getKey())->increment('id', 0, ['status' => 'published']);

        expect($entry->revisions()->count())->toBe($before + 1)
            ->and($entry->revisions()->latest('id')->first()->status)->toBe('published');
    });

    it('records NOTHING when the arithmetic touches nothing versioned', function (): void {
        $entry = anEntry();

        $before = $entry->revisions()->count();

        Entry::query()->whereKey($entry->getKey())->increment('id', 0);

        expect($entry->revisions()->count())->toBe($before);
    });
});
