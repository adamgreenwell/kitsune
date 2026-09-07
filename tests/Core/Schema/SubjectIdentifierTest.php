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
