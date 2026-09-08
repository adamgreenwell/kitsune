<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Models\Entry;
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
