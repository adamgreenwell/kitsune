<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRelation;
use Kitsune\Core\Models\EntryRevision;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Relations\GuardedBelongsToMany;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tenancy\ScopeWrites;

/*
 * ADR-020 primitives 2 and 3, which the compliance tooling in v1.1 is built
 * from: an entry type names the field identifying the data subject, and
 * erasure reaches revision history.
 */

beforeEach(function (): void {
    $this->org = Org::create(['name' => 'Clinic', 'slug' => 'clinic']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['org_id' => $this->org->id, 'handle' => 'main', 'slug' => 'clinic-main', 'name' => 'Main']);
    app(Context::class)->setSite($this->site);

    $this->type = EntryType::create([
        'org_id' => $this->org->id, 'handle' => 'patient', 'name' => 'Patient', 'plural_name' => 'Patients',
    ]);

    $this->emailStorage = FieldStorage::create([
        'org_id' => $this->org->id, 'handle' => 'email', 'type' => 'text',
        'pii_class' => 'personal', 'cardinality' => 1,
    ]);

    $this->emailField = Field::create([
        'entry_type_id' => $this->type->id,
        'field_storage_id' => $this->emailStorage->id,
        'label' => 'Email',
    ]);
});

afterEach(fn () => app(Context::class)->forget());

describe('an entry type names the field that identifies the subject', function (): void {
    it('resolves the values key from the nominated field', function (): void {
        $this->type->update(['subject_field_id' => $this->emailField->id]);

        // The handle comes from the STORAGE, because storage owns the handle
        // and the handle is the JSON key.
        expect($this->type->fresh()->subjectHandle())->toBe('email');
    });

    it('reads the subject value off an entry', function (): void {
        $this->type->update(['subject_field_id' => $this->emailField->id]);

        $entry = Entry::create([
            'entry_type_id' => $this->type->id,
            'title' => 'A. Patient',
            'values' => ['email' => 'a@example.test'],
        ]);

        expect($entry->fresh()->subjectValue())->toBe('a@example.test');
    });

    it('answers null when the type nominated nothing, meaning unanswerable', function (): void {
        $entry = Entry::create([
            'entry_type_id' => $this->type->id, 'title' => 'A', 'values' => ['email' => 'a@example.test'],
        ]);

        expect($entry->subjectValue())->toBeNull();
    });

    it('refuses a nomination pointing at another type\'s field', function (): void {
        // Answering a subject-access request with somebody else's data is
        // worse than answering it with nothing.
        $other = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'invoice', 'name' => 'Invoice', 'plural_name' => 'Invoices',
        ]);

        expect(fn () => $other->update(['subject_field_id' => $this->emailField->id]))
            ->toThrow(RuntimeException::class, 'does not belong to this entry type');
    });
});

describe('finding everything held about one person', function (): void {
    beforeEach(function (): void {
        $this->type->update(['subject_field_id' => $this->emailField->id]);
        $this->type->refresh();

        Entry::create(['entry_type_id' => $this->type->id, 'title' => 'One', 'values' => ['email' => 'a@example.test']]);
        Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Two', 'values' => ['email' => 'a@example.test']]);
        Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Three', 'values' => ['email' => 'b@example.test']]);
    });

    it('returns every entry whose subject matches', function (): void {
        expect(Entry::whereSubjectIs($this->type, 'a@example.test')->count())->toBe(2);
    });

    it('returns NOTHING when the type nominates no subject, rather than everything', function (): void {
        // Fail closed. The alternative answers a request about one person
        // with every person in the table.
        $this->type->update(['subject_field_id' => null]);

        expect(Entry::whereSubjectIs($this->type->fresh(), 'a@example.test')->count())->toBe(0);
    });
});

describe('the holes a subject-access request cannot see', function (): void {
    it('lists a type holding personal data with nothing nominated', function (): void {
        expect(EntryType::withoutSubjectIdentifier()->pluck('handle')->all())->toBe(['patient']);
    });

    it('drops it once a subject is nominated', function (): void {
        $this->type->update(['subject_field_id' => $this->emailField->id]);

        expect(EntryType::withoutSubjectIdentifier()->count())->toBe(0);
    });

    it('ignores a type that holds no personal data at all', function (): void {
        // Nothing to answer about, so nothing to nominate.
        $type = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'page', 'name' => 'Page', 'plural_name' => 'Pages',
        ]);
        $storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'body', 'type' => 'textarea',
            'pii_class' => 'none', 'cardinality' => 1,
        ]);
        Field::create(['entry_type_id' => $type->id, 'field_storage_id' => $storage->id, 'label' => 'Body']);

        expect(EntryType::withoutSubjectIdentifier()->pluck('handle')->all())->not->toContain('page');
    });
});

describe('erasure reaches revision history (ADR-020)', function (): void {
    beforeEach(function (): void {
        $this->entry = Entry::create([
            'entry_type_id' => $this->type->id,
            'title' => 'A. Patient',
            'values' => ['email' => 'a@example.test', 'notes' => 'keep me'],
        ]);

        foreach (['a@example.test', 'a@example.test', 'a@example.test'] as $i => $email) {
            EntryRevision::create([
                'entry_id' => $this->entry->id,
                'entry_type_id' => $this->entry->entry_type_id,
                'values' => ['email' => $email, 'notes' => "draft {$i}"],
                'status' => 'draft',
            ]);
        }
    });

    it('erases the field from the entry AND every revision', function (): void {
        // Article revision 4 still holds the name just erased — the exact
        // reason immutable revisions were rejected.
        //
        // Four revisions, not three: creating the entry records one
        // automatically, and that one holds the email too.
        $rewritten = $this->entry->redactField('email');

        expect($rewritten)->toBe(5)
            ->and($this->entry->fresh()->values['email'])->toBeNull()
            ->and(EntryRevision::where('entry_id', $this->entry->id)->get()
                ->every(fn (EntryRevision $r): bool => $r->values['email'] === null))->toBeTrue();
    });

    it('keeps the revision rows, so the history of WHAT CHANGED survives', function (): void {
        $this->entry->redactField('email');

        // Three authored plus the one the create recorded. Erasure replaces
        // in place and adds none of its own — filing the redacted state as a
        // new version would add a row to the history it is clearing.
        expect(EntryRevision::where('entry_id', $this->entry->id)->count())->toBe(4);
    });

    it('leaves other fields alone', function (): void {
        $this->entry->redactField('email');

        expect($this->entry->fresh()->values['notes'])->toBe('keep me')
            ->and(EntryRevision::where('entry_id', $this->entry->id)->where('note', null)
                ->orderBy('id')->skip(1)->first()->values['notes'])->toBe('draft 0');
    });

    it('accepts a replacement rather than only a null', function (): void {
        $this->entry->redactField('email', '[erased]');

        expect($this->entry->fresh()->values['email'])->toBe('[erased]');
    });

    it('reports reaching nothing, which is not the same as succeeding', function (): void {
        // An erasure that silently matched no rows is indistinguishable from
        // one that worked, unless it says so.
        expect($this->entry->redactField('no_such_field'))->toBe(0);
    });
});

/*
 * ⚠️ The nomination guard originally hung off `$type->exists`, which is false
 * on create — so `EntryType::create(['subject_field_id' => ...])` skipped the
 * ownership comparison entirely and accepted any field from any type in any
 * org. Reported in review of this PR.
 */
describe('the ownership guard covers every path, not just update', function (): void {
    it('refuses a nomination supplied at CREATE time', function (): void {
        // A field cannot belong to a type that does not exist yet, so there
        // is no valid nomination to make here.
        expect(fn () => EntryType::create([
            'org_id' => $this->org->id,
            'handle' => 'ticket',
            'name' => 'Ticket',
            'plural_name' => 'Tickets',
            'subject_field_id' => $this->emailField->id,
        ]))->toThrow(RuntimeException::class, 'does not belong to this entry type');
    });

    it('refuses a nomination of a field belonging to ANOTHER ORG', function (): void {
        $other = Org::create(['name' => 'Rival', 'slug' => 'rival']);
        app(Context::class)->setOrg($other);
        $theirType = EntryType::create(['org_id' => $other->id, 'handle' => 'lead', 'name' => 'L', 'plural_name' => 'Ls']);
        $theirStorage = FieldStorage::create([
            'org_id' => $other->id, 'handle' => 'email', 'type' => 'text', 'pii_class' => 'personal', 'cardinality' => 1,
        ]);
        $theirField = Field::create([
            'entry_type_id' => $theirType->id, 'field_storage_id' => $theirStorage->id, 'label' => 'Email',
        ]);
        app(Context::class)->setOrg($this->org);

        expect(fn () => $this->type->update(['subject_field_id' => $theirField->id]))
            ->toThrow(RuntimeException::class, 'does not belong to this entry type');
    });

    it('refuses to MOVE a nominated field to another entry type', function (): void {
        // Nominating is guarded on EntryType; moving would have slipped past
        // it entirely and left the pointer crossing the type boundary.
        $this->type->update(['subject_field_id' => $this->emailField->id]);

        $other = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'invoice', 'name' => 'Invoice', 'plural_name' => 'Invoices',
        ]);

        expect(fn () => $this->emailField->update(['entry_type_id' => $other->id]))
            ->toThrow(RuntimeException::class, 'identifies the data subject');
    });

    it('allows moving a field nobody nominated', function (): void {
        $other = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'invoice', 'name' => 'Invoice', 'plural_name' => 'Invoices',
        ]);

        expect(fn () => $this->emailField->update(['entry_type_id' => $other->id]))
            ->not->toThrow(RuntimeException::class);
    });
});

it('reports holes for the CURRENT ORG only', function (): void {
    // EntryType is #[Unscoped] by declaration, so an unqualified query
    // returns every org's types — and a compliance report is the last place
    // to leak another customer's schema metadata (ADR-021).
    $rival = Org::create(['name' => 'Rival', 'slug' => 'rival-holes']);
    app(Context::class)->setOrg($rival);
    $theirType = EntryType::create(['org_id' => $rival->id, 'handle' => 'lead', 'name' => 'L', 'plural_name' => 'Ls']);
    $theirStorage = FieldStorage::create([
        'org_id' => $rival->id, 'handle' => 'phone', 'type' => 'text', 'pii_class' => 'personal', 'cardinality' => 1,
    ]);
    Field::create(['entry_type_id' => $theirType->id, 'field_storage_id' => $theirStorage->id, 'label' => 'Phone']);

    app(Context::class)->setOrg($this->org);

    expect(EntryType::withoutSubjectIdentifier()->pluck('handle')->all())
        ->toBe(['patient'])
        ->not->toContain('lead');
});

/*
 * ADR-020 names this shape explicitly — "its email or its `person` relation"
 * — and the first implementation read only `values`, so a relational subject
 * returned null. Indistinguishable from "no subject nominated".
 */
describe('a relational subject lives in entry_relations, not in values', function (): void {
    beforeEach(function (): void {
        $this->personType = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'person', 'name' => 'Person', 'plural_name' => 'People',
        ]);
        $this->personStorage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'person', 'type' => 'relation',
            'pii_class' => 'personal', 'cardinality' => 1,
        ]);
        $this->personField = Field::create([
            'entry_type_id' => $this->type->id,
            'field_storage_id' => $this->personStorage->id,
            'label' => 'Person',
        ]);
        $this->type->update(['subject_field_id' => $this->personField->id]);
        $this->type->refresh();

        $this->alice = Entry::create(['entry_type_id' => $this->personType->id, 'title' => 'Alice']);
        $this->bob = Entry::create(['entry_type_id' => $this->personType->id, 'title' => 'Bob']);

        $this->record = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Visit']);
        $this->record->related()->attach($this->alice->id, ['field_storage_id' => $this->personStorage->id]);
    });

    it('reads the subject through the pivot', function (): void {
        expect($this->record->fresh()->subjectValue())->toBe([$this->alice->id]);
    });

    it('finds entries by a relational subject', function (): void {
        Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Other'])
            ->related()->attach($this->bob->id, ['field_storage_id' => $this->personStorage->id]);

        expect(Entry::whereSubjectIs($this->type, $this->alice->id)->pluck('title')->all())
            ->toBe(['Visit']);
    });

    it('erases a relational field by removing the links', function (): void {
        // There is nothing to replace in place, so erasure IS the detach —
        // and the array-key path found nothing and returned 0 while every
        // pivot row survived.
        //
        // TWO rows reached: the live pivot, and the revision that recorded it.
        // A relation change files a version, and `relation_state` holds the
        // target ids — so leaving history alone would let a restore recreate the
        // erased link (ADR-020).
        expect($this->record->redactField('person'))->toBe(2)
            ->and($this->record->fresh()->subjectValue())->toBe([]);
    });

    it('leaves another entry\'s links alone', function (): void {
        $other = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Other']);
        $other->related()->attach($this->bob->id, ['field_storage_id' => $this->personStorage->id]);

        $this->record->redactField('person');

        expect($other->fresh()->subjectValue())->toBe([$this->bob->id]);
    });
});

/*
 * ⚠️ `field_storage` is UNIQUE (org_id, handle) and the model is #[Unscoped],
 * so resolving a field by handle alone can return ANOTHER ORG's row — and a
 * relational erasure then detaches on the wrong field_storage_id, reports 0,
 * and leaves every link intact. Reported in review of this PR.
 */
describe('erasure resolves the field through this entry\'s type', function (): void {
    beforeEach(function (): void {
        // A rival org defines the SAME handle first, so an unscoped lookup
        // ordered by id would find theirs.
        $rival = Org::create(['name' => 'Rival', 'slug' => 'rival-erase']);
        app(Context::class)->setOrg($rival);
        $rivalType = EntryType::create(['org_id' => $rival->id, 'handle' => 'lead', 'name' => 'L', 'plural_name' => 'Ls']);
        $rivalStorage = FieldStorage::create([
            'org_id' => $rival->id, 'handle' => 'contact', 'type' => 'text',
            'pii_class' => 'personal', 'cardinality' => 1,
        ]);
        Field::create(['entry_type_id' => $rivalType->id, 'field_storage_id' => $rivalStorage->id, 'label' => 'Contact']);

        app(Context::class)->setOrg($this->org);
        app(Context::class)->setSite($this->site);

        $this->mineStorage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'contact', 'type' => 'relation',
            'pii_class' => 'personal', 'cardinality' => 1,
        ]);
        Field::create([
            'entry_type_id' => $this->type->id, 'field_storage_id' => $this->mineStorage->id, 'label' => 'Contact',
        ]);

        $this->person = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Alice']);
        $this->record = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Visit']);
        $this->record->related()->attach($this->person->id, ['field_storage_id' => $this->mineStorage->id]);
    });

    it('erases through THIS org\'s storage row, not the other org\'s', function (): void {
        // Resolved by handle alone this found the rival's `text` row, took
        // the inline branch, found no key in `values`, and returned 0 with
        // every link still attached.
        // Two: the live pivot and the revision holding its target id.
        expect($this->record->redactField('contact'))->toBe(2)
            ->and($this->record->related()->count())->toBe(0);
    });
});

/*
 * ⚠️ There are THREE storage strategies, and the first implementation
 * dispatched on two. `SlugType` is Promoted and stores its value in
 * `entries.slug`, so nominating it as a subject returned null and erasing it
 * rewrote JSON that never held it — reporting success either way. Reported in
 * review of this PR.
 */
describe('a promoted subject lives in its own column', function (): void {
    beforeEach(function (): void {
        $this->slugStorage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'slug', 'type' => 'slug',
            'pii_class' => 'personal', 'cardinality' => 1,
        ]);
        $this->slugField = Field::create([
            'entry_type_id' => $this->type->id, 'field_storage_id' => $this->slugStorage->id, 'label' => 'Slug',
        ]);
        $this->type->update(['subject_field_id' => $this->slugField->id]);
        $this->type->refresh();

        $this->entry = Entry::create([
            'entry_type_id' => $this->type->id, 'title' => 'A. Patient', 'slug' => 'a-patient',
        ]);
    });

    it('reads the subject from the real column', function (): void {
        expect($this->entry->fresh()->subjectValue())->toBe('a-patient');
    });

    it('finds entries by a promoted subject', function (): void {
        Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Other', 'slug' => 'other']);

        expect(Entry::whereSubjectIs($this->type, 'a-patient')->pluck('title')->all())->toBe(['A. Patient']);
    });

    it('erases the column rather than a JSON key that never held it', function (): void {
        // Two: the entry's own column, and the revision the create recorded —
        // revisions snapshot the promoted columns too, so erasure has to
        // reach them there as well.
        expect($this->entry->redactField('slug'))->toBe(2)
            ->and($this->entry->fresh()->slug)->toBeNull()
            ->and(EntryRevision::where('entry_id', $this->entry->id)->value('slug'))->toBeNull();
    });

    it('reports reaching nothing when it is already erased', function (): void {
        $this->entry->redactField('slug');

        expect($this->entry->fresh()->redactField('slug'))->toBe(0);
    });

    it('accepts a replacement, as the inline path does', function (): void {
        $this->entry->redactField('slug', 'erased');

        expect($this->entry->fresh()->slug)->toBe('erased');
    });
});

/*
 * Three more from review, and each one made a subject-access request return
 * the wrong thing quietly rather than loudly.
 */
describe('a promoted field writes to its own column, not one named after it', function (): void {
    beforeEach(function (): void {
        // ⚠️ `public_slug`, not `slug`. field_storage accepts any valid
        // handle for a slug field, and the value still lands in entries.slug.
        $this->storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'public_slug', 'type' => 'slug',
            'pii_class' => 'personal', 'cardinality' => 1,
        ]);
        $this->field = Field::create([
            'entry_type_id' => $this->type->id, 'field_storage_id' => $this->storage->id, 'label' => 'Public slug',
        ]);
        $this->type->update(['subject_field_id' => $this->field->id]);
        $this->type->refresh();

        $this->entry = Entry::create([
            'entry_type_id' => $this->type->id, 'title' => 'A. Patient', 'slug' => 'a-patient',
        ]);
    });

    it('reads the column the type declares', function (): void {
        // Handle-as-column read a column that does not exist.
        expect($this->entry->fresh()->subjectValue())->toBe('a-patient');
    });

    it('queries that column too', function (): void {
        expect(Entry::whereSubjectIs($this->type, 'a-patient')->pluck('title')->all())->toBe(['A. Patient']);
    });

    it('erases that column, and the revisions that snapshot it', function (): void {
        // ⚠️ TWO rewrites, not one, and the second is the point of ADR-020
        // primitive 3: creating the entry filed a revision that snapshots the
        // promoted columns, so erasing only the live row would leave the value
        // sitting in history. The count went from 1 to 2 the moment revisions
        // existed — the correct answer changing, not a regression.
        expect($this->entry->redactField('public_slug'))->toBe(2)
            ->and($this->entry->fresh()->slug)->toBeNull()
            ->and($this->entry->revisions()->pluck('slug')->filter()->all())->toBe([]);
    });
});

it('will not count another org\'s relation row as this entry\'s subject', function (): void {
    /*
     * ⚠️ The hostile-row scenario EntrySchemaTest already writes to prove
     * `related()` refuses it. The raw EXISTS filtered source, storage and
     * target only, so a pivot row carrying another org's org_id counted —
     * contaminating subject-access results across the boundary ADR-021 says
     * has no framework safety net.
     */
    $personStorage = FieldStorage::create([
        'org_id' => $this->org->id, 'handle' => 'person', 'type' => 'relation',
        'pii_class' => 'personal', 'cardinality' => 1,
    ]);
    $personField = Field::create([
        'entry_type_id' => $this->type->id, 'field_storage_id' => $personStorage->id, 'label' => 'Person',
    ]);
    $this->type->update(['subject_field_id' => $personField->id]);
    $this->type->refresh();

    $alice = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Alice']);
    $record = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Visit']);
    $record->related()->attach($alice->id, ['field_storage_id' => $personStorage->id]);

    expect(Entry::whereSubjectIs($this->type, $alice->id)->count())->toBe(1);

    // The attacker rewrites the pivot row to another org.
    $rival = Org::create(['name' => 'Rival', 'slug' => 'rival-pivot']);
    DB::table('entry_relations')->update(['org_id' => $rival->id]);

    expect(Entry::whereSubjectIs($this->type, $alice->id)->count())->toBe(0);
});

describe('a subject identifier names ONE subject', function (): void {
    it('refuses a multi-value field', function (): void {
        // values->handle holds a JSON array, so the equality predicate
        // matched nothing at all — which looks exactly like a type with no
        // subject nominated.
        $storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'aliases', 'type' => 'text',
            'pii_class' => 'personal', 'cardinality' => -1,
        ]);
        $field = Field::create([
            'entry_type_id' => $this->type->id, 'field_storage_id' => $storage->id, 'label' => 'Aliases',
        ]);

        expect(fn () => $this->type->update(['subject_field_id' => $field->id]))
            ->toThrow(RuntimeException::class, 'holds many values');
    });

    it('refuses a multi_select, which is array-valued whatever its cardinality', function (): void {
        $storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'tags', 'type' => 'multi_select',
            'pii_class' => 'personal', 'cardinality' => 1,
        ]);
        $field = Field::create([
            'entry_type_id' => $this->type->id, 'field_storage_id' => $storage->id, 'label' => 'Tags',
        ]);

        expect(fn () => $this->type->update(['subject_field_id' => $field->id]))
            ->toThrow(RuntimeException::class, 'holds many values');
    });

    it('still allows a relation, where the subject IS another entry', function (): void {
        $storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'person', 'type' => 'relation',
            'pii_class' => 'personal', 'cardinality' => 1,
        ]);
        $field = Field::create([
            'entry_type_id' => $this->type->id, 'field_storage_id' => $storage->id, 'label' => 'Person',
        ]);

        expect(fn () => $this->type->update(['subject_field_id' => $field->id]))->not->toThrow(RuntimeException::class);
    });
});

/*
 * Three more from review of the fixes above. Each let a subject-access
 * request return somebody else's data, or return nothing while looking fine.
 */
describe('a relational subject has to point somewhere visible', function (): void {
    it('ignores a pivot row pointing at an entry outside this scope', function (): void {
        // `related()` hides an out-of-scope target through Entry's SiteScope,
        // but a raw EXISTS does not — and `attach($id)` never validates the
        // related model, so the ordinary path can create such a row.
        $personStorage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'person', 'type' => 'relation',
            'pii_class' => 'personal', 'cardinality' => 1,
        ]);
        $personField = Field::create([
            'entry_type_id' => $this->type->id, 'field_storage_id' => $personStorage->id, 'label' => 'Person',
        ]);
        $this->type->update(['subject_field_id' => $personField->id]);
        $this->type->refresh();

        $alice = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Alice']);
        $record = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Visit']);
        $record->related()->attach($alice->id, ['field_storage_id' => $personStorage->id]);

        expect(Entry::whereSubjectIs($this->type, $alice->id)->count())->toBe(1);

        // The target is moved out of scope while the pivot stays put.
        Entry::withoutScopeBecause('fixture: the attacker moves the target', function () use ($alice) {
            Entry::whereKey($alice->id)->update(['site_id' => null, 'org_id' => Org::create(['name' => 'R', 'slug' => 'r-target'])->id]);
        });

        expect(Entry::whereSubjectIs($this->type, $alice->id)->count())->toBe(0);
    });
});

describe('a relation subject may point to ONE person', function (): void {
    it('refuses a relation that can hold several targets', function (): void {
        // subjectValue() would return every id, and whereSubjectIs() would
        // return the same record for each — so an export about one person
        // would disclose a record shared with another.
        $storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'people', 'type' => 'relation',
            'pii_class' => 'personal', 'cardinality' => -1,
        ]);
        $field = Field::create([
            'entry_type_id' => $this->type->id, 'field_storage_id' => $storage->id, 'label' => 'People',
        ]);

        expect(fn () => $this->type->update(['subject_field_id' => $field->id]))
            ->toThrow(RuntimeException::class, 'can point to several entries');
    });
});

describe('a nomination cannot be repointed out from under itself', function (): void {
    beforeEach(function (): void {
        $this->type->update(['subject_field_id' => $this->emailField->id]);
    });

    it('refuses to swap the nominated field onto different storage', function (): void {
        // Watching only entry_type_id let a nominated field be repointed at a
        // multi-select — making the holes report call the type answerable
        // while every subject query silently missed.
        $other = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'tags', 'type' => 'multi_select',
            'pii_class' => 'personal', 'cardinality' => 1,
        ]);

        expect(fn () => $this->emailField->update(['field_storage_id' => $other->id]))
            ->toThrow(RuntimeException::class, 'identifies the data subject');
    });

    it('refuses to swap it onto ANOTHER ORG\'s storage', function (): void {
        // FieldStorage is #[Unscoped], so nothing else stops this.
        $rival = Org::create(['name' => 'Rival', 'slug' => 'rival-swap']);
        $theirs = FieldStorage::create([
            'org_id' => $rival->id, 'handle' => 'email', 'type' => 'text',
            'pii_class' => 'personal', 'cardinality' => 1,
        ]);

        expect(fn () => $this->emailField->update(['field_storage_id' => $theirs->id]))
            ->toThrow(RuntimeException::class, 'identifies the data subject');
    });

    it('still allows editing a nominated field\'s presentation', function (): void {
        // Only the two attributes that change WHAT the field is are guarded.
        expect(fn () => $this->emailField->update(['label' => 'Email address']))
            ->not->toThrow(RuntimeException::class);
    });
});

/*
 * Four more from review. Two share a root cause worth naming: `cardinality`
 * and `targetTypes` were METADATA that only validation checked, and
 * `attach()` goes nowhere near validation.
 */
describe('a relation obeys its own rules when rows are WRITTEN', function (): void {
    beforeEach(function (): void {
        $this->one = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'person', 'type' => 'relation',
            'pii_class' => 'personal', 'cardinality' => 1,
        ]);
        $this->alice = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Alice']);
        $this->bob = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Bob']);
        $this->record = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Visit']);
    });

    it('refuses a second target on a single-valued relation', function (): void {
        // Two ordinary attach() calls gave a nominated subject field two
        // targets, so subjectValue() named two people and whereSubjectIs()
        // returned the shared record for either — the exact disclosure the
        // nomination guard was written to prevent, through the normal API.
        $this->record->related()->attach($this->alice->id, ['field_storage_id' => $this->one->id]);

        expect(fn () => $this->record->related()->attach($this->bob->id, ['field_storage_id' => $this->one->id]))
            ->toThrow(RuntimeException::class, 'already has that many');
    });

    it('allows as many as the cardinality permits', function (): void {
        $two = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'people', 'type' => 'relation',
            'pii_class' => 'personal', 'cardinality' => 2,
        ]);

        $this->record->related()->attach($this->alice->id, ['field_storage_id' => $two->id]);

        expect(fn () => $this->record->related()->attach($this->bob->id, ['field_storage_id' => $two->id]))
            ->not->toThrow(RuntimeException::class);
    });

    it('leaves -1 unlimited', function (): void {
        $many = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'links', 'type' => 'relation',
            'pii_class' => 'none', 'cardinality' => -1,
        ]);

        $this->record->related()->attach($this->alice->id, ['field_storage_id' => $many->id]);

        expect(fn () => $this->record->related()->attach($this->bob->id, ['field_storage_id' => $many->id]))
            ->not->toThrow(RuntimeException::class);
    });

    it('refuses a target of a type the field forbids', function (): void {
        // targetTypes was enforced in validation only, and attach() bypasses
        // validation — so a pivot could point at a visible entry of a
        // forbidden type and be treated as the subject.
        $constrained = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'author', 'type' => 'relation',
            'pii_class' => 'personal', 'cardinality' => 1,
            'settings' => ['targetTypes' => ['person']],
        ]);

        expect(fn () => $this->record->related()->attach($this->alice->id, ['field_storage_id' => $constrained->id]))
            ->toThrow(RuntimeException::class, 'accepts only');
    });
});

describe('a nomination survives its storage being edited', function (): void {
    beforeEach(function (): void {
        $this->type->update(['subject_field_id' => $this->emailField->id]);
    });

    it('refuses to widen the cardinality of nominated storage', function (): void {
        // An unlocked single-value relation could become -1, recreating the
        // multi-subject disclosure the nomination guard exists to prevent.
        expect(fn () => $this->emailStorage->update(['cardinality' => -1]))
            ->toThrow(RuntimeException::class, 'backs the data subject identifier');
    });

    it('refuses to rename nominated storage', function (): void {
        // Not a locked shape attribute, so even a LOCKED storage allowed it —
        // and subject queries would then read the wrong key.
        expect(fn () => $this->emailStorage->update(['handle' => 'contact_email']))
            ->toThrow(RuntimeException::class, 'backs the data subject identifier');
    });

    it('refuses to move nominated storage to another org', function (): void {
        $rival = Org::create(['name' => 'R', 'slug' => 'rival-move']);

        expect(fn () => $this->emailStorage->update(['org_id' => $rival->id]))
            ->toThrow(RuntimeException::class, 'backs the data subject identifier');
    });

    it('still allows editing settings on nominated storage', function (): void {
        // Only what changes WHAT THE FIELD IS is frozen.
        expect(fn () => $this->emailStorage->update(['settings' => ['maxLength' => 200]]))
            ->not->toThrow(RuntimeException::class);
    });
});

describe('a rival\'s storage cannot be borrowed at all', function (): void {
    beforeEach(function (): void {
        $rival = Org::create(['name' => 'R', 'slug' => 'rival-storage']);

        $this->theirs = FieldStorage::create([
            'org_id' => $rival->id, 'handle' => 'their_email', 'type' => 'text',
            'pii_class' => 'personal', 'cardinality' => 1,
        ]);
    });

    /*
     * ⚠️ `Field::create()` used to skip the guard entirely — it returned
     * early on a model that does not yet exist — so the create-then-nominate
     * sequence could back a nomination with a rival org's storage, which
     * FieldStorage being #[Unscoped] does nothing to prevent.
     *
     * Refused at attachment now, which also closes the case that needs no
     * nomination: attaching a rival's row to an ordinary type makes the holes
     * report answer a question about THEIR classification.
     */
    it('refuses the attachment itself, before any nomination', function (): void {
        expect(fn () => Field::create([
            'entry_type_id' => $this->type->id, 'field_storage_id' => $this->theirs->id, 'label' => 'Their email',
        ]))->toThrow(RuntimeException::class, 'belongs to another organisation');
    });

    it('refuses to repoint an existing field onto it', function (): void {
        expect(fn () => $this->emailField->update(['field_storage_id' => $this->theirs->id]))
            ->toThrow(RuntimeException::class);
    });

    it('still refuses the nomination for a row that predates the guard', function (): void {
        /*
         * Defence in depth: the attachment guard is new, so a row written before it could already
         * exist. This fabricates exactly that.
         *
         * ⚠️ `ScopeWrites::suspend()` AND NOT `withoutEvents()` ALONE, which stopped being enough
         * once the builder required proof that the guards ran rather than trusting an attribute to be
         * present (issue #60). Suppressing events is what makes this row bad; it is now also what
         * makes the builder refuse to write it, so the fixture needs the reviewable opt-out the guard
         * is designed around. That reads better than it did: the row is deliberately written past a
         * guard, and the test now says so.
         */
        $field = ScopeWrites::suspend(fn () => Field::withoutEvents(fn () => Field::create([
            'entry_type_id' => $this->type->id, 'field_storage_id' => $this->theirs->id, 'label' => 'Their email',
        ])));

        expect(fn () => $this->type->update(['subject_field_id' => $field->id]))
            ->toThrow(RuntimeException::class, "another organisation's storage");
    });
});

it('still allows a nomination backed by GLOBAL storage', function (): void {
    // org_id NULL is legitimate, matching how global entry types work.
    $global = FieldStorage::create([
        'org_id' => null, 'handle' => 'system_ref', 'type' => 'text',
        'pii_class' => 'personal', 'cardinality' => 1,
    ]);
    $field = Field::create([
        'entry_type_id' => $this->type->id, 'field_storage_id' => $global->id, 'label' => 'System ref',
    ]);

    expect(fn () => $this->type->update(['subject_field_id' => $field->id]))->not->toThrow(RuntimeException::class);
});

it('enforces the field\'s rules when a pivot row is MOVED, not only created', function (): void {
    /*
     * ⚠️ The guards ran on `creating` only, so `updateExistingPivot()` could
     * move an existing row onto a different field — a second target from an
     * unlimited relation repointed at a nominated cardinality-one field,
     * recreating the two-subject state through the ordinary API with no row
     * ever being created.
     */
    $unlimited = FieldStorage::create([
        'org_id' => $this->org->id, 'handle' => 'links', 'type' => 'relation',
        'pii_class' => 'none', 'cardinality' => -1,
    ]);
    $one = FieldStorage::create([
        'org_id' => $this->org->id, 'handle' => 'person', 'type' => 'relation',
        'pii_class' => 'personal', 'cardinality' => 1,
    ]);

    $alice = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Alice']);
    $bob = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Bob']);
    $record = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Visit']);

    $record->related()->attach($alice->id, ['field_storage_id' => $one->id]);
    $record->related()->attach($bob->id, ['field_storage_id' => $unlimited->id]);

    expect(fn () => $record->related()->updateExistingPivot($bob->id, ['field_storage_id' => $one->id]))
        ->toThrow(RuntimeException::class, 'already has that many');
});

it('lets a row be moved when the destination has room', function (): void {
    // The row being moved must not count against its own destination.
    $one = FieldStorage::create([
        'org_id' => $this->org->id, 'handle' => 'person', 'type' => 'relation',
        'pii_class' => 'personal', 'cardinality' => 1,
    ]);
    $other = FieldStorage::create([
        'org_id' => $this->org->id, 'handle' => 'reviewer', 'type' => 'relation',
        'pii_class' => 'personal', 'cardinality' => 1,
    ]);

    $alice = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Alice']);
    $record = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Visit']);
    $record->related()->attach($alice->id, ['field_storage_id' => $one->id]);

    expect(fn () => $record->related()->updateExistingPivot($alice->id, ['field_storage_id' => $other->id]))
        ->not->toThrow(RuntimeException::class);
});

it('enforces the field\'s rules when a pivot row moves to a different SOURCE', function (): void {
    /*
     * ⚠️ The update guard watched `field_storage_id` only. Cardinality is
     * counted per (source, field), so `updateExistingPivot()` moving a row
     * from source B onto source A — which already holds a target for the same
     * cardinality-one nominated field — lands two subjects on A with no
     * concurrency involved at all.
     */
    $one = FieldStorage::create([
        'org_id' => $this->org->id, 'handle' => 'person', 'type' => 'relation',
        'pii_class' => 'personal', 'cardinality' => 1,
    ]);

    $alice = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Alice']);
    $bob = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Bob']);
    $visitA = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Visit A']);
    $visitB = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Visit B']);

    $visitA->related()->attach($alice->id, ['field_storage_id' => $one->id]);
    $visitB->related()->attach($bob->id, ['field_storage_id' => $one->id]);

    // Repoint B's row at A, which already has its one person.
    expect(fn () => $visitB->related()->updateExistingPivot($bob->id, ['source_entry_id' => $visitA->id]))
        ->toThrow(RuntimeException::class, 'already has that many');

    expect(EntryRelation::query()->where('source_entry_id', $visitA->id)->count())->toBe(1);
});

it('counts cardinality against the SOURCE when attaching from the other end', function (): void {
    /*
     * ⚠️ `referencedBy()` hangs off the TARGET, so the relation's parent is
     * not the source. The lock was taken on the parent unconditionally, which
     * meant two concurrent calls on different targets attaching the same
     * source took different locks entirely — both passed the count and left
     * that source with two subject targets.
     *
     * The count itself has to be against the source either way, which is what
     * this asserts; the lock now follows the same end.
     */
    $one = FieldStorage::create([
        'org_id' => $this->org->id, 'handle' => 'person', 'type' => 'relation',
        'pii_class' => 'personal', 'cardinality' => 1,
    ]);

    $visit = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Visit']);
    $alice = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Alice']);
    $bob = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Bob']);

    $alice->referencedBy()->attach($visit->id, ['field_storage_id' => $one->id]);

    expect(fn () => $bob->referencedBy()->attach($visit->id, ['field_storage_id' => $one->id]))
        ->toThrow(RuntimeException::class, 'already has that many');

    expect($bob->referencedBy())->toBeInstanceOf(GuardedBelongsToMany::class);
});

describe('the lock reaches every storage strategy, not just inline', function (): void {
    /*
     * ⚠️ The lock arms on the entry write (see #28), but a promoted field's
     * data is a real COLUMN and a relational field's is rows in
     * `entry_relations`. Checking `values` alone would leave a `slug` or a
     * `person` field unlocked forever while holding content — the same
     * emptiness the lock had before anything set it, narrowed rather than
     * fixed.
     */
    it('locks a PROMOTED field once its column holds a value', function (): void {
        $storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'public_slug', 'type' => 'slug',
            'pii_class' => 'personal', 'cardinality' => 1,
        ]);
        Field::create([
            'entry_type_id' => $this->type->id, 'field_storage_id' => $storage->id, 'label' => 'Slug',
        ]);

        Entry::create(['entry_type_id' => $this->type->id, 'title' => 'A', 'slug' => 'a-patient']);

        expect($storage->fresh()->is_locked)->toBeTrue();
    });

    it('leaves a promoted field unlocked while its column is empty', function (): void {
        $storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'public_slug', 'type' => 'slug',
            'pii_class' => 'personal', 'cardinality' => 1,
        ]);
        Field::create([
            'entry_type_id' => $this->type->id, 'field_storage_id' => $storage->id, 'label' => 'Slug',
        ]);

        Entry::create(['entry_type_id' => $this->type->id, 'title' => 'A']);

        expect($storage->fresh()->is_locked)->toBeFalse();
    });

    it('locks a RELATIONAL field once a relation row exists', function (): void {
        $storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'person', 'type' => 'relation',
            'pii_class' => 'personal', 'cardinality' => 1,
        ]);
        Field::create([
            'entry_type_id' => $this->type->id, 'field_storage_id' => $storage->id, 'label' => 'Person',
        ]);

        $alice = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Alice']);
        $visit = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Visit']);

        expect($storage->fresh()->is_locked)->toBeFalse();

        // ⚠️ NO save() afterwards, deliberately. This test used to call one,
        // which masked the defect: `attach()` writes the pivot AFTER the
        // entry was saved and does not save it again, so the entry-side check
        // never ran for the ordinary path and the lock stayed false while
        // relation data existed. The lock arms from the pivot write now.
        $visit->related()->attach($alice->id, ['field_storage_id' => $storage->id]);

        expect($storage->fresh()->is_locked)->toBeTrue();
    });
});

describe('a nomination freezes what the relation ACCEPTS, not only its shape', function (): void {
    /*
     * ⚠️ The projection guard always sees `none` for a relation, so
     * `targetTypes` stayed editable on a nominated one: attach a target while
     * the relation is unconstrained, then narrow it afterwards, and the
     * existing pivot survives while `subjectValue()` and the relational
     * `whereSubjectIs()` branch both keep treating that target as the
     * subject.
     */
    beforeEach(function (): void {
        // Configured BEFORE nominating: restricting an unconstrained relation
        // is itself a narrowing, so doing it afterwards is refused.
        $this->rel = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'person', 'type' => 'relation',
            'pii_class' => 'personal', 'cardinality' => 1,
            'settings' => ['targetTypes' => ['patient', 'article']],
        ]);
        $this->relField = Field::create([
            'entry_type_id' => $this->type->id, 'field_storage_id' => $this->rel->id, 'label' => 'Person',
        ]);
        $this->type->update(['subject_field_id' => $this->relField->id]);
    });

    it('refuses to narrow targetTypes while nominated', function (): void {
        expect(fn () => $this->rel->fresh()->update(['settings' => ['targetTypes' => ['patient']]]))
            ->toThrow(RuntimeException::class, 'targetTypes');
    });

    it('still allows WIDENING, which cannot invalidate an existing row', function (): void {
        expect(fn () => $this->rel->fresh()->update([
            'settings' => ['targetTypes' => ['patient', 'article', 'note']],
        ]))->not->toThrow(RuntimeException::class);
    });

    /*
     * ⚠️ An EMPTY list means UNRESTRICTED, so restricting one is the widest
     * possible narrowing — and a set comparison reads it backwards, seeing
     * `[]` as the smallest accepted set and any addition as a widening. A
     * nominated relation could attach an article while unconstrained and then
     * switch to `['patient']`, and the article went on answering as the
     * subject.
     */
    it('refuses restricting an UNCONSTRAINED relation, which reads as widening', function (): void {
        $open = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'anyone', 'type' => 'relation',
            'pii_class' => 'personal', 'cardinality' => 1,
        ]);
        $field = Field::create([
            'entry_type_id' => $this->type->id, 'field_storage_id' => $open->id, 'label' => 'Anyone',
        ]);
        $this->type->update(['subject_field_id' => $field->id]);

        expect(fn () => $open->fresh()->update(['settings' => ['targetTypes' => ['patient']]]))
            ->toThrow(RuntimeException::class, 'targetTypes');
    });
});

describe('erasure reaches a relation row whatever org stamped it', function (): void {
    /*
     * ⚠️ `related()` carries `withPivotValue('org_id', ...)`, which is right
     * for READING and wrong for erasing. A pivot row written with a different
     * org_id — reachable by overriding it in attach()'s pivot attributes —
     * did not match the predicate, so the detach skipped it and reported 0
     * while the subject link survived. Scoping is the reader's protection; it
     * must not become the attacker's.
     */
    it('deletes a pivot row carrying another org id', function (): void {
        $storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'person', 'type' => 'relation',
            'pii_class' => 'personal', 'cardinality' => 1,
        ]);
        Field::create([
            'entry_type_id' => $this->type->id, 'field_storage_id' => $storage->id, 'label' => 'Person',
        ]);

        $alice = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Alice']);
        $visit = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Visit']);

        $rival = Org::create(['name' => 'X', 'slug' => 'erasure-rival']);

        // Straight at the table, which is what overriding org_id in
        // attach()'s pivot attributes amounts to.
        DB::table('entry_relations')->insert([
            'org_id' => $rival->id, 'source_entry_id' => $visit->id,
            'target_entry_id' => $alice->id, 'field_storage_id' => $storage->id,
        ]);

        expect($visit->redactField('person'))->toBe(1)
            ->and(EntryRelation::query()->where('source_entry_id', $visit->id)->count())->toBe(0);
    });
});

describe('changing an entry\'s type cannot orphan a relation pointing at it', function (): void {
    /*
     * ⚠️ The pivot guards run when a PIVOT changes. Nothing ran when the
     * TARGET changed: a field configured to accept `patient` went on naming a
     * target that had since become something else, and neither
     * `subjectValue()` nor the relational `whereSubjectIs()` branch rechecks
     * `targetTypes`.
     */
    it('refuses the type change while a relation forbids the new type', function (): void {
        $other = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'article', 'name' => 'A', 'plural_name' => 'As',
        ]);
        $storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'person', 'type' => 'relation',
            'pii_class' => 'personal', 'cardinality' => 1,
            'settings' => ['targetTypes' => [$this->type->handle]],
        ]);
        Field::create([
            'entry_type_id' => $this->type->id, 'field_storage_id' => $storage->id, 'label' => 'Person',
        ]);

        $alice = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Alice']);
        $visit = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Visit']);
        $visit->related()->attach($alice->id, ['field_storage_id' => $storage->id]);

        expect(fn () => $alice->update(['entry_type_id' => $other->id]))
            ->toThrow(RuntimeException::class, 'does not accept that type');

        expect($alice->fresh()->entry_type_id)->toBe($this->type->id);
    });

    /*
     * ⚠️ A denial of service, not a tidy-up. `attach()` does not validate
     * target visibility, so org A can create a pivot pointing at org B's
     * entry. An unscoped veto then let A's storage configuration freeze B's
     * record indefinitely — from a row B cannot see and did not create.
     */
    it('ignores a foreign org\'s pivot when vetoing a type change', function (): void {
        $rival = Org::create(['name' => 'V', 'slug' => 'veto-rival']);
        $other = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'article', 'name' => 'A', 'plural_name' => 'As',
        ]);
        $hostile = FieldStorage::create([
            'org_id' => $rival->id, 'handle' => 'person', 'type' => 'relation',
            'pii_class' => 'personal', 'cardinality' => 1,
            'settings' => ['targetTypes' => [$this->type->handle]],
        ]);

        $mine = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Mine']);

        // The attacker's row, written straight at the table.
        DB::table('entry_relations')->insert([
            'org_id' => $rival->id, 'source_entry_id' => $mine->id,
            'target_entry_id' => $mine->id, 'field_storage_id' => $hostile->id,
        ]);

        expect(fn () => $mine->update(['entry_type_id' => $other->id]))
            ->not->toThrow(RuntimeException::class);
    });

    it('ignores a relation whose source has since become invisible', function (): void {
        /*
         * ⚠️ A pivot valid when written can become cross-site later. Move an
         * org-shared or site-A source to another site and its now-invisible
         * relation could still veto a type change here, because the veto was
         * scoped by org and two sites share an org stamp.
         */
        $otherSite = Site::create([
            'org_id' => $this->org->id, 'handle' => 'd', 'slug' => 'veto-site', 'name' => 'D',
        ]);
        $other = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'article', 'name' => 'A', 'plural_name' => 'As',
        ]);
        $storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'person', 'type' => 'relation',
            'pii_class' => 'personal', 'cardinality' => 1,
            'settings' => ['targetTypes' => [$this->type->handle]],
        ]);
        Field::create([
            'entry_type_id' => $this->type->id, 'field_storage_id' => $storage->id, 'label' => 'Person',
        ]);

        $alice = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Alice']);
        $visit = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Visit']);
        $visit->related()->attach($alice->id, ['field_storage_id' => $storage->id]);

        // The source moves away, so the relation is no longer visible here.
        Entry::withoutScopeBecause(
            'fixture: moving the source out of this site',
            fn () => $visit->forceFill(['site_id' => $otherSite->id])->saveQuietly(),
        );

        expect(fn () => $alice->update(['entry_type_id' => $other->id]))
            ->not->toThrow(RuntimeException::class);
    });

    it('allows a type change the relation still accepts', function (): void {
        $other = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'article', 'name' => 'A', 'plural_name' => 'As',
        ]);
        $storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'person', 'type' => 'relation',
            'pii_class' => 'personal', 'cardinality' => 1,
            'settings' => ['targetTypes' => [$this->type->handle, 'article']],
        ]);
        Field::create([
            'entry_type_id' => $this->type->id, 'field_storage_id' => $storage->id, 'label' => 'Person',
        ]);

        $alice = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Alice']);
        $visit = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Visit']);
        $visit->related()->attach($alice->id, ['field_storage_id' => $storage->id]);

        expect(fn () => $alice->update(['entry_type_id' => $other->id]))->not->toThrow(RuntimeException::class);
    });
});

describe('a guard that only runs on a save is a guard on one path', function (): void {
    /*
     * ⚠️ Six guards in this project have been found bypassed by a bulk write.
     * These three were the same shape: `Entry::query()->update(['type_handle'
     * => ...])`, `Field::query()->update(['field_storage_id' => ...])` and
     * `EntryType::query()->update(['subject_field_id' => ...])` all dispatch
     * nothing, so the restamp, the ownership check and the nomination check
     * never ran — and each reached a state its own `save()` refuses.
     *
     * Declared per model now, and refused by the builder, so the model event
     * is the only door rather than the first one.
     */
    it('refuses a bulk write to a derived handle', function (): void {
        $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Forged']);

        expect(fn () => Entry::query()->whereKey($entry->getKey())->update(['type_handle' => 'article']))
            ->toThrow(RuntimeException::class, 'cannot be written in bulk');

        expect($entry->fresh()->type_handle)->toBe($this->type->handle);
    });

    it('refuses a bulk repoint of a field at other storage', function (): void {
        $storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'other', 'type' => 'text',
            'pii_class' => 'none', 'cardinality' => 1,
        ]);

        expect(fn () => Field::query()->whereKey($this->emailField->getKey())
            ->update(['field_storage_id' => $storage->id]))
            ->toThrow(RuntimeException::class, 'cannot be written in bulk');
    });

    it('refuses a bulk nomination', function (): void {
        expect(fn () => EntryType::query()->whereKey($this->type->getKey())
            ->update(['subject_field_id' => $this->emailField->getKey()]))
            ->toThrow(RuntimeException::class, 'cannot be written in bulk');
    });

    it('still allows a bulk write to an unguarded column', function (): void {
        expect(fn () => Entry::query()->update(['status' => 'published']))
            ->not->toThrow(RuntimeException::class);
    });

    it('still allows the ordinary instance save', function (): void {
        expect(fn () => $this->type->update(['name' => 'Renamed']))->not->toThrow(RuntimeException::class);
    });
});

describe('a target that leaves a site and comes back is rechecked', function (): void {
    /*
     * ⚠️ The type veto ignores relations whose source is invisible from here,
     * which is right — otherwise one site could freeze another's records — and
     * it leaves a sequence: move a valid target to site B, change it there to a
     * type site A's field forbids (A's pivot is invisible, so nothing objects),
     * then move it back to A without touching `type_handle`. No pivot guard
     * runs on the final move, and the relation resurfaces with a forbidden
     * target, treated again as the nominated subject.
     */
    it('refuses the move back when the relation would resurface invalid', function (): void {
        $otherSite = Site::create([
            'org_id' => $this->org->id, 'handle' => 'e', 'slug' => 'reentry-site', 'name' => 'E',
        ]);
        $article = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'article', 'name' => 'A', 'plural_name' => 'As',
        ]);
        $storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'person', 'type' => 'relation',
            'pii_class' => 'personal', 'cardinality' => 1,
            'settings' => ['targetTypes' => [$this->type->handle]],
        ]);
        Field::create([
            'entry_type_id' => $this->type->id, 'field_storage_id' => $storage->id, 'label' => 'Person',
        ]);

        $alice = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Alice']);
        $visit = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Visit']);
        $visit->related()->attach($alice->id, ['field_storage_id' => $storage->id]);

        /*
         * Away, changed while the pivot is invisible, and now back.
         *
         * ⚠️ THE TYPE CHANGE IS AN ORDINARY UPDATE IN THE OTHER SITE'S CONTEXT, and it used to be a
         * `saveQuietly()` here. The quiet shortcut is refused now — the relation veto moved to the
         * builder, where a suppressed listener cannot skip it — so keeping it would have made this
         * fixture fail for the right reason and the test unrunnable for the wrong one.
         *
         * ⚠️ AND REWRITING IT MADE THE TEST BETTER RATHER THAN MERELY GREEN, which is the reason to
         * prefer it over staging the row below Eloquent: the sequence is reachable exactly as written,
         * measured. The move needs the hatch because `EnforcesScope` guards a `site_id` change, and the
         * type change needs nothing — from site B, site A's pivot is invisible, so the veto has no
         * relation to consult and allows it. That is the hole this test exists to close, now demonstrated
         * with the writes a real operator would make instead of a shortcut.
         */
        Entry::withoutScopeBecause(
            'fixture: an operator moves the target to another site',
            fn () => $alice->forceFill(['site_id' => $otherSite->id])->save(),
        );

        app(Context::class)->setSite($otherSite);

        Entry::query()->findOrFail($alice->id)->update(['entry_type_id' => $article->id]);

        app(Context::class)->setSite($this->site);

        expect(fn () => $alice->fresh()->update(['site_id' => $this->site->id]))
            ->toThrow(RuntimeException::class, 'does not accept');
    });

    it('allows the move back when the relation is still valid', function (): void {
        $otherSite = Site::create([
            'org_id' => $this->org->id, 'handle' => 'f', 'slug' => 'reentry-ok', 'name' => 'F',
        ]);

        $alice = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Alice']);

        Entry::withoutScopeBecause(
            'fixture: moving the target away',
            fn () => $alice->forceFill(['site_id' => $otherSite->id])->saveQuietly(),
        );

        expect(fn () => $alice->fresh()->update(['site_id' => $this->site->id]))
            ->not->toThrow(RuntimeException::class);
    });
});

describe('a denormalised handle is derived, never accepted', function (): void {
    /*
     * ⚠️ `type_handle` is denormalised and fully mass assignable, and every
     * relational read and write resolves a target's type through IT rather
     * than through `entry_type_id` — guardTargetType(), forbidsTypeChange(),
     * RelationType::elementValidationRules() and scopeOfType() all read the
     * handle. The guard returned early unless `entry_type_id` was dirty, so
     * `update(['type_handle' => 'article'])` reached exactly the state the
     * guard exists to prevent, without it running.
     */
    it('overwrites a forged handle with the type it actually points at', function (): void {
        $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Forged']);

        $entry->update(['type_handle' => 'article']);

        expect($entry->fresh()->type_handle)->toBe($this->type->handle);
    });

    it('runs the relation veto when only the handle was written', function (): void {
        $storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'author', 'type' => 'relation',
            'pii_class' => 'personal', 'cardinality' => 1,
            'settings' => ['targetTypes' => [$this->type->handle]],
        ]);
        Field::create([
            'entry_type_id' => $this->type->id, 'field_storage_id' => $storage->id, 'label' => 'Author',
        ]);

        $alice = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Alice']);
        $post = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Post']);
        $post->related()->attach($alice->id, ['field_storage_id' => $storage->id]);

        // The handle is corrected back, so the relation still names a target
        // of a type the field accepts.
        $alice->update(['type_handle' => 'article']);

        expect($alice->fresh()->type_handle)->toBe($this->type->handle);
    });

    it('runs the relation veto on a quiet type change too', function (): void {
        /*
         * ⚠️ THE VETO WAS A `saving` LISTENER AND A QUIET WRITE SUPPRESSES IT, which review found — and
         * the builder then restamped the forbidden handle and armed the derived proof, so `ScopedBuilder`
         * accepted the write with the invalid pivot still attached. Nothing downstream rechecks
         * `targetTypes`: not `subjectValue()`, not the relational `whereSubjectIs()` branch.
         *
         * Measured before the fix: the ordinary update was refused and `saveQuietly()` was ALLOWED, the
         * entry became an `article`, and the pivot from a `person`-only field stayed.
         */
        $other = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'article', 'name' => 'A', 'plural_name' => 'As',
        ]);
        $storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'author', 'type' => 'relation',
            'pii_class' => 'personal', 'cardinality' => 1,
            'settings' => ['targetTypes' => [$this->type->handle]],
        ]);
        Field::create([
            'entry_type_id' => $this->type->id, 'field_storage_id' => $storage->id, 'label' => 'Author',
        ]);

        $alice = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Alice']);
        $visit = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Visit']);
        $visit->related()->attach($alice->id, ['field_storage_id' => $storage->id]);

        $alice->entry_type_id = $other->id;

        expect(fn () => $alice->saveQuietly())
            ->toThrow(RuntimeException::class, 'does not accept that type')
            ->and((string) DB::table('entries')->where('id', $alice->id)->value('type_handle'))
            ->toBe($this->type->handle, 'a quiet type change moved a related entry to a forbidden type');

        /*
         * ⚠️ AND A FORGED HANDLE ALONE IS CORRECTED RATHER THAN REFUSED, which is the established
         * behaviour and not a gap — I expected a refusal here and the code is right. The column is
         * DERIVED from `entry_type_id`, so whatever a caller writes, the type it points at is the truth:
         * the restamp puts the real handle back, the veto is then asked about a type the field accepts,
         * and there is nothing to refuse. The state this test guards cannot be reached that way.
         */
        $alice->refresh();
        $alice->type_handle = 'article';

        expect($alice->saveQuietly())->toBeTrue()
            ->and((string) DB::table('entries')->where('id', $alice->id)->value('type_handle'))
            ->toBe($this->type->handle, 'a forged handle survived a quiet write');
    });

    it('still restamps when the type id changes, as before', function (): void {
        $other = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'article', 'name' => 'A', 'plural_name' => 'As',
        ]);
        $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Moved']);

        $entry->update(['entry_type_id' => $other->id]);

        expect($entry->fresh()->type_handle)->toBe('article');
    });
});

describe('erasure reaches a SHADOWED handle too, not an arbitrary one', function (): void {
    /*
     * ⚠️ UNIQUE (org_id, handle) lets a GLOBAL row and the org's own row share
     * a handle, and Field::guardStorageOwnership() permits both to attach to
     * one type. A bare `first()` then picked one arbitrarily: taking the
     * relational row detached the pivots and returned, leaving
     * `values['contact']` and every revision copy intact while reporting
     * success; taking the inline row left the pivot instead. Either way
     * personal data remained and the caller's success check passed — and which
     * happened depended on row order, so the same request erased different
     * data on different engines.
     *
     * Precedence would be the wrong fix here. Erasure has to reach the data,
     * and the shadowed row holds data too.
     */
    it('erases BOTH the global and the org row behind one handle', function (): void {
        $global = FieldStorage::create([
            'org_id' => null, 'handle' => 'contact', 'type' => 'text',
            'pii_class' => 'personal', 'cardinality' => 1,
        ]);
        $mine = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'contact', 'type' => 'relation',
            'pii_class' => 'personal', 'cardinality' => 1,
        ]);

        Field::create([
            'entry_type_id' => $this->type->id, 'field_storage_id' => $global->id, 'label' => 'Contact text',
        ]);
        Field::create([
            'entry_type_id' => $this->type->id, 'field_storage_id' => $mine->id, 'label' => 'Contact person',
        ]);

        $alice = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Alice']);
        $visit = Entry::create([
            'entry_type_id' => $this->type->id, 'title' => 'Visit',
            'values' => ['contact' => 'alice@example.com'],
        ]);
        $visit->related()->attach($alice->id, ['field_storage_id' => $mine->id]);

        $rewritten = $visit->redactField('contact');

        expect($rewritten)->toBeGreaterThan(1)
            ->and($visit->fresh()->values['contact'])->toBeNull()
            ->and(EntryRelation::query()->where('source_entry_id', $visit->id)->count())->toBe(0);
    });
});

describe('the pivot guards hold on the bulk path, which had none', function (): void {
    /*
     * ⚠️ Every guard on this pivot hangs off a model event — ownership,
     * endpoint visibility, cardinality, target type — and so does the lock
     * arming. `EntryRelation::query()->insert()` and `->update()` compile
     * straight to SQL and dispatch nothing, so on those paths there were no
     * guards at all. One ordinary statement put a SECOND target on a
     * cardinality-one nominated subject field, of a type that field forbids:
     * the two-subject disclosure ADR-020's cardinality check exists to
     * prevent.
     *
     * Third model in this project to need a builder for this reason. AuditLog
     * and Entry each got one; FieldStorage and this went without, while core
     * itself already writes this table in bulk.
     */
    beforeEach(function (): void {
        $this->one = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'subject', 'type' => 'relation',
            'pii_class' => 'personal', 'cardinality' => 1,
            'settings' => ['targetTypes' => [$this->type->handle]],
        ]);
        Field::create([
            'entry_type_id' => $this->type->id, 'field_storage_id' => $this->one->id, 'label' => 'Subject',
        ]);

        $this->src = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Src']);
        $this->p1 = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'P1']);
        $this->p2 = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'P2']);

        $this->src->related()->attach($this->p1->id, ['field_storage_id' => $this->one->id]);
    });

    it('refuses a bulk insert that would give one field two subjects', function (): void {
        expect(fn () => EntryRelation::query()->insert([[
            'org_id' => $this->org->id, 'source_entry_id' => $this->src->id,
            'target_entry_id' => $this->p2->id, 'field_storage_id' => $this->one->id, 'ordering' => 0,
        ]]))->toThrow(RuntimeException::class, 'cannot be created in bulk');

        expect(EntryRelation::query()->where('source_entry_id', $this->src->id)->count())->toBe(1);
    });

    it('refuses a bulk repoint onto a nominated single-valued field', function (): void {
        $open = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'links', 'type' => 'relation',
            'pii_class' => 'none', 'cardinality' => -1,
        ]);
        $other = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Other']);
        $other->related()->attach($this->p2->id, ['field_storage_id' => $open->id]);

        expect(fn () => EntryRelation::query()
            ->where('field_storage_id', $open->id)
            ->update(['field_storage_id' => $this->one->id]))
            ->toThrow(RuntimeException::class, 'cannot be written in bulk');
    });

    it('still allows an ordinary attach and a pivot move', function (): void {
        // The flag is what separates them: a model save has run its guards, a
        // bulk write dispatched nothing and never could.
        $open = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'links', 'type' => 'relation',
            'pii_class' => 'none', 'cardinality' => -1,
        ]);

        expect(fn () => $this->src->related()->attach($this->p2->id, ['field_storage_id' => $open->id]))
            ->not->toThrow(RuntimeException::class);

        expect(fn () => $this->src->related()->updateExistingPivot($this->p2->id, ['ordering' => 3]))
            ->not->toThrow(RuntimeException::class);
    });

    it('still allows a bulk DELETE, which erasure depends on', function (): void {
        // Removing a relation can only relax a bound, never violate one, and
        // redactField() deletes through this builder because erasure has to
        // reach a row whatever org stamped it.
        // Two: the live pivot and the revision holding its target id.
        expect($this->src->redactField('subject'))->toBe(2)
            ->and(EntryRelation::query()->where('source_entry_id', $this->src->id)->count())->toBe(0);
    });

    it('refuses a truncate, which would detach every org at once', function (): void {
        expect(fn () => EntryRelation::query()->truncate())->toThrow(RuntimeException::class);
    });
});

describe('a pivot cannot reach past what the writer can see', function (): void {
    beforeEach(function (): void {
        $this->rivalOrg = Org::create(['name' => 'W', 'slug' => 'pivot-rival']);

        $this->storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'person', 'type' => 'relation',
            'pii_class' => 'personal', 'cardinality' => -1,
        ]);
        Field::create([
            'entry_type_id' => $this->type->id, 'field_storage_id' => $this->storage->id, 'label' => 'Person',
        ]);

        $this->visit = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Visit']);
    });

    /*
     * ⚠️ `attach()` takes an ID and never loads the model, so a pivot could
     * point anywhere — and every consequence has had to be patched downstream
     * one boundary at a time. Scoping the type-change veto to the target's org
     * closed the cross-ORG case and left the cross-SITE one open, because two
     * sites in one org share an org stamp.
     */
    it('refuses a target on ANOTHER SITE of the same org', function (): void {
        $otherSite = Site::create([
            'org_id' => $this->org->id, 'handle' => 'b', 'slug' => 'other-site', 'name' => 'B',
        ]);

        $theirs = Entry::withoutScopeBecause('fixture: another site\'s row', fn () => Entry::create([
            'org_id' => $this->org->id, 'site_id' => $otherSite->id,
            'entry_type_id' => $this->type->id, 'title' => 'Theirs',
        ]));

        expect(fn () => $this->visit->related()->attach($theirs->id, ['field_storage_id' => $this->storage->id]))
            ->toThrow(RuntimeException::class, 'not visible here');
    });

    it('still allows an ORG-SHARED target, which is what relations are for', function (): void {
        $shared = Entry::create([
            'site_id' => null, 'entry_type_id' => $this->type->id, 'title' => 'Shared',
        ]);

        expect(fn () => $this->visit->related()->attach($shared->id, ['field_storage_id' => $this->storage->id]))
            ->not->toThrow(RuntimeException::class);
    });

    /*
     * ⚠️ `field_storage` is #[Unscoped] and nothing checked ownership, so an
     * org could attach one of its OWN entries using a rival's storage id — a
     * well-formed pivot that armed that rival's lock and froze their schema
     * from a row they cannot see. Guessing an integer was the whole attack.
     */
    it('refuses a rival org\'s storage, which would arm their lock', function (): void {
        $theirs = FieldStorage::create([
            'org_id' => $this->rivalOrg->id, 'handle' => 'person', 'type' => 'relation',
            'pii_class' => 'personal', 'cardinality' => -1,
        ]);
        $alice = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Alice']);

        expect(fn () => $this->visit->related()->attach($alice->id, ['field_storage_id' => $theirs->id]))
            ->toThrow(RuntimeException::class, 'belongs to another organisation');

        expect($theirs->fresh()->is_locked)->toBeFalse();
    });

    it('refuses a rival\'s storage even when the pivot stamp agrees with it', function (): void {
        /*
         * ⚠️ `withPivotValue()` supplies `org_id`, but `attach()` attributes
         * OVERRIDE it. So comparing storage against the pivot's own stamp
         * compared two values the same caller controls: pass org B's
         * `field_storage_id` AND org B's `org_id` and the equality held, right
         * before the created hook armed org B's lock. Ownership is derived
         * from the source entry now.
         */
        $theirs = FieldStorage::create([
            'org_id' => $this->rivalOrg->id, 'handle' => 'person', 'type' => 'relation',
            'pii_class' => 'personal', 'cardinality' => -1,
        ]);
        $alice = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Alice']);

        expect(fn () => $this->visit->related()->attach($alice->id, [
            'field_storage_id' => $theirs->id,
            'org_id' => $this->rivalOrg->id,
        ]))->toThrow(RuntimeException::class);

        expect($theirs->fresh()->is_locked)->toBeFalse();
    });

    it('refuses a guessed SOURCE from the reverse direction', function (): void {
        /*
         * ⚠️ `referencedBy()` inverts the endpoints: the visible parent is the
         * TARGET, and the attached id becomes `source_entry_id`. Checking only
         * the target said nothing about the source, so a site-A caller could
         * attach a guessed site-B source to its own visible target — a row
         * hidden from B's relation reads that still counted in
         * guardCardinality(), letting A fill B's single-valued relation and
         * block its legitimate attach.
         */
        $otherSite = Site::create([
            'org_id' => $this->org->id, 'handle' => 'c', 'slug' => 'reverse-site', 'name' => 'C',
        ]);
        $theirs = Entry::withoutScopeBecause('fixture: another site\'s row', fn () => Entry::create([
            'org_id' => $this->org->id, 'site_id' => $otherSite->id,
            'entry_type_id' => $this->type->id, 'title' => 'Theirs',
        ]));

        $mine = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Mine']);

        expect(fn () => $mine->referencedBy()->attach($theirs->id, ['field_storage_id' => $this->storage->id]))
            ->toThrow(RuntimeException::class, 'cannot be the source');
    });

    it('still allows GLOBAL storage, which every org may use', function (): void {
        $global = FieldStorage::create([
            'org_id' => null, 'handle' => 'system_person', 'type' => 'relation',
            'pii_class' => 'personal', 'cardinality' => -1,
        ]);
        $alice = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Alice']);

        expect(fn () => $this->visit->related()->attach($alice->id, ['field_storage_id' => $global->id]))
            ->not->toThrow(RuntimeException::class);
    });

    it('arms the DESTINATION lock when a row is moved onto it', function (): void {
        // ⚠️ Only the `created` listener armed the lock, so a row moved onto
        // different storage left the destination holding relation data while
        // staying editable — free to change type or cardinality and orphan
        // the links it had just acquired.
        $other = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'reviewer', 'type' => 'relation',
            'pii_class' => 'none', 'cardinality' => -1,
        ]);
        $alice = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Alice']);

        $this->visit->related()->attach($alice->id, ['field_storage_id' => $this->storage->id]);

        expect($other->fresh()->is_locked)->toBeFalse();

        $this->visit->related()->updateExistingPivot($alice->id, ['field_storage_id' => $other->id]);

        expect($other->fresh()->is_locked)->toBeTrue();
    });
});

describe('a null identifier is unanswerable, not a wildcard', function (): void {
    /*
     * ⚠️ `= null` compiles to `IS NULL`, so both the promoted and the inline
     * branch matched every entry whose nominated field is empty — a malformed
     * subject-access request returning a batch of records belonging to no
     * identified subject, from the one query that must never over-answer.
     * `subjectValue()` already defined null as unanswerable.
     */
    it('matches nothing for an inline subject', function (): void {
        Entry::create(['entry_type_id' => $this->type->id, 'title' => 'No email']);

        expect(Entry::whereSubjectIs($this->type, null)->count())->toBe(0);
    });

    it('matches nothing for a promoted subject either', function (): void {
        $storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'public_slug', 'type' => 'slug',
            'pii_class' => 'personal', 'cardinality' => 1,
        ]);
        $field = Field::create([
            'entry_type_id' => $this->type->id, 'field_storage_id' => $storage->id, 'label' => 'Slug',
        ]);
        $this->type->update(['subject_field_id' => $field->id]);
        Entry::create(['entry_type_id' => $this->type->id, 'title' => 'No slug']);

        expect(Entry::whereSubjectIs($this->type->fresh(), null)->count())->toBe(0);
    });
});

describe('storage and its type cannot be walked across the org boundary', function (): void {
    /*
     * ⚠️ Both of these recreate the foreign-storage state that
     * `Field::saving()` refuses, by moving the OTHER side of the
     * relationship. Neither passes through a field save, so neither was
     * checked — and the holes report, being org-scoped, then filters the
     * moved row out and stops naming a type that still holds personal data.
     */
    beforeEach(function (): void {
        $this->rival = Org::create(['name' => 'V', 'slug' => 'org-move-rival']);

        // Its own type, holding exactly one field. The shared fixture type
        // carries several, so a refusal there would not say which field
        // caused it.
        $this->movingType = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'movable_type', 'name' => 'M', 'plural_name' => 'Ms',
        ]);
        $this->moving = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'movable', 'type' => 'text',
            'pii_class' => 'personal', 'cardinality' => 1,
        ]);
        Field::create([
            'entry_type_id' => $this->movingType->id, 'field_storage_id' => $this->moving->id, 'label' => 'Movable',
        ]);
    });

    it('refuses to move attached storage to another org, nominated or not', function (): void {
        expect(fn () => $this->moving->update(['org_id' => $this->rival->id]))
            ->toThrow(RuntimeException::class, 'cannot move to another organisation');
    });

    it('allows attached storage to become GLOBAL, which every org may use', function (): void {
        expect(fn () => $this->moving->update(['org_id' => null]))->not->toThrow(RuntimeException::class);
    });

    it('refuses to move the type while a field is backed by storage staying behind', function (): void {
        expect(fn () => $this->movingType->update(['org_id' => $this->rival->id]))
            ->toThrow(RuntimeException::class, 'cannot move to another organisation');
    });

    it('allows the type to move once its storage is global', function (): void {
        // ⚠️ Through the escape hatch, and that is not a workaround. Moving a
        // row to another org is a cross-scope WRITE, which the tenancy guard
        // now refuses from inside this org's context — correctly, since nothing
        // here can vouch for the destination. Provisioning and cross-org admin
        // tooling are what `withoutScopeBecause()` exists for, and this test
        // asserts the FIELD-ownership guard stands aside once the storage is
        // global, not that the tenancy boundary is open.
        $this->moving->update(['org_id' => null]);

        expect(fn () => EntryType::withoutScopeBecause(
            'test: cross-org admin move, which is what the hatch is for',
            fn () => $this->movingType->fresh()->update(['org_id' => $this->rival->id]),
        ))->not->toThrow(RuntimeException::class);
    });
});

it('locks the source named in a PER-ID attach map, not just the parent', function (): void {
    /*
     * ⚠️ `attach()` also takes an ID-to-attributes map, and each entry can
     * carry its own `source_entry_id`. Reading only the common $attributes
     * meant the pivot was written against the overridden source while just
     * the parent was locked — so the cardinality count was taken against a
     * row nothing had serialised on.
     *
     * ⚠️ THIS TEST USED TO PROVE NOTHING. It asserted the cardinality
     * exception, which `guardCardinality()` throws from the pivot's own
     * `source_entry_id` no matter which rows were locked — so deleting the
     * per-ID override loop entirely left it green, and its second assertion
     * held too. It read as coverage for the defect it names while that defect
     * was reintroduced.
     *
     * So it observes the LOCK now: the query that takes it selects from
     * `entries` by key, and the key has to be the source the row lands on.
     * That select runs on every engine — SQLite compiles the FOR UPDATE away,
     * not the predicate.
     */
    $one = FieldStorage::create([
        'org_id' => $this->org->id, 'handle' => 'person', 'type' => 'relation',
        'pii_class' => 'personal', 'cardinality' => 1,
    ]);

    $visitA = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Visit A']);
    $visitB = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Visit B']);
    $bob = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Bob']);

    // ⚠️ The lock query is identified EXACTLY, and its id list parsed.
    //
    // Two earlier versions of this assertion proved nothing. The first
    // asserted the cardinality exception, which fires from the pivot's own
    // source no matter what was locked. The second matched the id as a
    // substring of the SQL, and an id like "1" appears in every scoped query.
    //
    // `whereKey()` with integers compiles to `in (1, 2)` with NO bindings —
    // Laravel inlines them — so the list has to come out of the SQL, from the
    // one query shaped like the lock.
    $lockedKeys = [];

    DB::listen(function ($query) use (&$lockedKeys): void {
        if (! preg_match('/^select \* from [`"]entries[`"] where [`"]entries[`"]\.[`"]id[`"] in \(([^)]*)\)/', $query->sql, $m)) {
            return;
        }

        foreach (explode(',', $m[1]) as $id) {
            $lockedKeys[] = (int) trim($id);
        }
    });

    // Attached through B's relation, but pointed at A in the per-ID map.
    try {
        $visitB->related()->attach([
            $bob->id => ['field_storage_id' => $one->id, 'source_entry_id' => $visitA->id],
        ]);
    } catch (RuntimeException) {
        // Irrelevant here: what matters is which row was locked first.
    }

    expect($lockedKeys)->not->toBe([], 'the lock query was not observed at all')
        ->and($lockedKeys)->toContain($visitA->id);
});

it('serialises the count-then-insert behind a lock on the source entry', function (): void {
    // ⚠️ The check is two statements, and two of those interleave: concurrent
    // attaches to the same single-valued relation both count zero and both
    // insert. Asserting the SHAPE of the fix rather than racing threads,
    // which a test cannot do deterministically — the relation is the guarded
    // subclass, so every attach runs inside a transaction that locks the
    // parent row first.
    $record = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Visit']);

    expect($record->related())->toBeInstanceOf(GuardedBelongsToMany::class);
});

it('refuses org-owned storage on a GLOBAL entry type', function (): void {
    // A global type is available to every org, so nominating one customer's
    // storage would make every org's subject-access behaviour depend on it —
    // a wider blast radius than the cross-org case, not a narrower one.
    $global = EntryType::create([
        'org_id' => null, 'handle' => 'system_person', 'name' => 'Person', 'plural_name' => 'People',
    ]);
    $mine = FieldStorage::create([
        'org_id' => $this->org->id, 'handle' => 'my_email', 'type' => 'text',
        'pii_class' => 'personal', 'cardinality' => 1,
    ]);
    // The attachment is refused outright now — a global type takes global
    // storage only. Created without events to reach the nomination guard
    // underneath, which still has to hold for rows written before that.
    expect(fn () => Field::create([
        'entry_type_id' => $global->id, 'field_storage_id' => $mine->id, 'label' => 'Email',
    ]))->toThrow(RuntimeException::class, 'belongs to another organisation');

    // ⚠️ Past the builder guard too, for the reason the `predates the guard` test above records.
    $field = ScopeWrites::suspend(fn () => Field::withoutEvents(fn () => Field::create([
        'entry_type_id' => $global->id, 'field_storage_id' => $mine->id, 'label' => 'Email',
    ])));

    expect(fn () => $global->update(['subject_field_id' => $field->id]))
        ->toThrow(RuntimeException::class, 'a global entry type');
});
