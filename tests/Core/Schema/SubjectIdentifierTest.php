<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRevision;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

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
                'values' => ['email' => $email, 'notes' => "draft {$i}"],
                'status' => 'draft',
            ]);
        }
    });

    it('erases the field from the entry AND every revision', function (): void {
        // Article revision 4 still holds the name just erased — the exact
        // reason immutable revisions were rejected.
        $rewritten = $this->entry->redactField('email');

        expect($rewritten)->toBe(4)
            ->and($this->entry->fresh()->values['email'])->toBeNull()
            ->and(EntryRevision::where('entry_id', $this->entry->id)->get()
                ->every(fn (EntryRevision $r): bool => $r->values['email'] === null))->toBeTrue();
    });

    it('keeps the revision rows, so the history of WHAT CHANGED survives', function (): void {
        $this->entry->redactField('email');

        expect(EntryRevision::where('entry_id', $this->entry->id)->count())->toBe(3);
    });

    it('leaves other fields alone', function (): void {
        $this->entry->redactField('email');

        expect($this->entry->fresh()->values['notes'])->toBe('keep me')
            ->and(EntryRevision::where('entry_id', $this->entry->id)->first()->values['notes'])->toBe('draft 0');
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
        expect($this->record->redactField('person'))->toBe(1)
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
        expect($this->record->redactField('contact'))->toBe(1)
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
        expect($this->entry->redactField('slug'))->toBe(1)
            ->and($this->entry->fresh()->slug)->toBeNull();
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

    it('erases that column', function (): void {
        expect($this->entry->redactField('public_slug'))->toBe(1)
            ->and($this->entry->fresh()->slug)->toBeNull();
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
