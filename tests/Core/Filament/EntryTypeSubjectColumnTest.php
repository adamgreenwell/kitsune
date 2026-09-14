<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Filament\Resources\EntryTypes\EntryTypeResource;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tenancy\ScopeWrites;

/*
 * The entry type list's Subject column — ADR-020's compliance surface, where an operator actually looks.
 *
 * ⚠️ A FRESH INSTALL SHOWED THE WARNING ON EVERY TYPE, found by the alpha pass: the column flagged each type with no
 * subject nominated, whether or not it held personal data, so four alarm icons on a brand-new install read as
 * breakage and taught the reader to ignore the one that means something. It has three states now, and `missing` is
 * asked with the same predicate as `EntryType::withoutSubjectIdentifier()`.
 */

beforeEach(function (): void {
    $this->org = Org::create(['name' => 'Clinic', 'slug' => 'clinic']);
    app(Context::class)->setOrg($this->org);
});

afterEach(fn () => app(Context::class)->forget());

/**
 * An entry type in this org with one field whose storage is classified as given.
 *
 * @return array{0: EntryType, 1: Field}
 */
function subjectColumnType(Org $org, string $handle, string $piiClass): array
{
    $type = EntryType::create([
        'org_id' => $org->id, 'handle' => $handle, 'name' => ucfirst($handle), 'plural_name' => ucfirst($handle).'s',
    ]);

    $storage = FieldStorage::create([
        'org_id' => $org->id, 'handle' => $handle.'_identifier', 'type' => 'text', 'pii_class' => $piiClass, 'cardinality' => 1,
    ]);

    $field = Field::create(['entry_type_id' => $type->id, 'field_storage_id' => $storage->id, 'label' => 'Identifier']);

    return [$type, $field];
}

/**
 * Every type's Subject state, the way the list computes it.
 *
 * @return array<string, string>
 */
function subjectColumnStates(): array
{
    return EntryTypeResource::withPersonalDataFlag(EntryType::constrainToCurrentOrg(EntryType::query()))
        ->get()
        ->mapWithKeys(static fn (EntryType $type): array => [$type->handle => EntryTypeResource::subjectState($type)])
        ->sortKeys()
        ->all();
}

it('warns only about a type that holds personal data with no subject nominated', function (): void {
    subjectColumnType($this->org, 'patient', 'personal');
    subjectColumnType($this->org, 'page', 'none');

    [$customer, $email] = subjectColumnType($this->org, 'customer', 'sensitive');
    $customer->update(['subject_field_id' => $email->getKey()]);

    // A type with no fields at all holds nothing about anybody.
    EntryType::create(['org_id' => $this->org->id, 'handle' => 'empty', 'name' => 'Empty', 'plural_name' => 'Empties']);

    expect(subjectColumnStates())->toBe([
        'customer' => 'nominated',
        'empty' => 'not-needed',
        'page' => 'not-needed',
        'patient' => 'missing',
    ]);
});

it('calls missing exactly the types the compliance report lists', function (): void {
    /*
     * ⚠️ THE LIST AND THE REPORT ANSWER THE SAME QUESTION, so they must not be able to disagree. Both ask
     * `EntryType::constrainToPersonalData()`; this pins the claim rather than trusting the shared call.
     */
    subjectColumnType($this->org, 'patient', 'personal');
    subjectColumnType($this->org, 'referral', 'sensitive');
    subjectColumnType($this->org, 'page', 'none');

    $missing = collect(subjectColumnStates())
        ->filter(static fn (string $state): bool => $state === 'missing')
        ->keys()
        ->sort()
        ->values()
        ->all();

    expect($missing)->toBe(['patient', 'referral'])
        ->and(EntryType::withoutSubjectIdentifier()->pluck('handle')->sort()->values()->all())->toBe($missing);
});

it('lets no other org\'s types or storage decide this org\'s subject states', function (): void {
    /*
     * ⚠️ `EntryType` AND `FieldStorage` ARE BOTH UNSCOPED, so the list's projection has to be scoped by query — and review
     * found it tested only from the current org. A rival's own type holding personal data must not appear, and a field
     * of this org's backed by the rival's personal storage must not make this org's type a hole on the rival's
     * classification. The `Field` guard refuses that attachment now, so it is written past both guards, as a row
     * that predates them — the opt-out `SubjectIdentifierTest` uses for the same fixture.
     */
    $rival = Org::create(['name' => 'Rival', 'slug' => 'rival-subjects']);
    app(Context::class)->setOrg($rival);
    [, $theirField] = subjectColumnType($rival, 'lead', 'personal');

    app(Context::class)->setOrg($this->org);
    $page = EntryType::create(['org_id' => $this->org->id, 'handle' => 'page', 'name' => 'Page', 'plural_name' => 'Pages']);
    ScopeWrites::suspend(static fn (): Field => Field::withoutEvents(static fn (): Field => Field::create([
        'entry_type_id' => $page->id, 'field_storage_id' => $theirField->field_storage_id, 'label' => 'Borrowed',
    ])));

    expect(subjectColumnStates())->toBe(['page' => 'not-needed']);
});
