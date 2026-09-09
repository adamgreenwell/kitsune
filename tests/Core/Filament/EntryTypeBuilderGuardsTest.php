<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Kitsune\Core\Fields\Pattern;
use Kitsune\Core\Filament\Icons;
use Kitsune\Core\Filament\Resources\EntryTypes\EntryTypeResource;
use Kitsune\Core\Filament\Resources\EntryTypes\Pages\EditEntryType;
use Kitsune\Core\Filament\Resources\EntryTypes\RelationManagers\FieldsRelationManager;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRevision;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Validation\Rule;

/*
 * The builder is an admin surface over org-owned schema, and EntryType is
 * #[Unscoped] — so nothing about isolation here is automatic. These are the
 * three places that turned out to matter.
 */

beforeEach(function (): void {
    $this->org = Org::create(['name' => 'B', 'slug' => 'builder-org']);
    $this->rival = Org::create(['name' => 'V', 'slug' => 'builder-rival']);

    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['org_id' => $this->org->id, 'handle' => 's', 'slug' => 'builder-s', 'name' => 'S']);
    app(Context::class)->setSite($this->site);
});

afterEach(fn () => app(Context::class)->forget());

describe('handle uniqueness is the org\'s, not everyone\'s', function (): void {
    /*
     * ⚠️ `ScopedUnique` starts from `EntryType::newQuery()`, and EntryType is
     * #[Unscoped] — so the rule saw EVERY org's types. It rejected a handle
     * another customer happens to use, which both reveals that they use it and
     * refuses a combination `UNIQUE (org_id, handle)` explicitly permits. The
     * resource's own constrained listing query is not inherited by validation.
     */
    $rule = fn () => Rule::scopedUnique(
        EntryType::class,
        'handle',
        null,
        fn ($query) => $query->where('org_id', app(Context::class)->orgId()),
    );

    it('ALLOWS a handle another org already uses', function () use ($rule): void {
        EntryType::create(['org_id' => $this->rival->id, 'handle' => 'article', 'name' => 'A', 'plural_name' => 'As']);

        expect(Validator::make(['handle' => 'article'], ['handle' => [$rule()]])->fails())->toBeFalse();
    });

    it('still refuses one this org uses', function () use ($rule): void {
        EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'A', 'plural_name' => 'As']);

        expect(Validator::make(['handle' => 'article'], ['handle' => [$rule()]])->fails())->toBeTrue();
    });

    it('ALLOWS shadowing a GLOBAL handle with the org\'s own', function () use ($rule): void {
        // A global type is available to every org, and an org shadowing it with
        // its own is the documented precedence rather than a collision.
        EntryType::create(['org_id' => null, 'handle' => 'page', 'name' => 'P', 'plural_name' => 'Ps']);

        expect(Validator::make(['handle' => 'page'], ['handle' => [$rule()]])->fails())->toBeFalse();
    });
});

describe('a global entry type is visible and not writable', function (): void {
    /*
     * ⚠️ `getEloquentQuery()` deliberately includes `org_id IS NULL` so an org
     * sees the system types it shares. With unconditional actions it could edit
     * or bulk-delete schema every other org depends on, and hiding the edit
     * page's delete button was not enough — an ordinary save and a table bulk
     * delete both went through.
     */
    it('reports a global type as not owned', function (): void {
        $global = EntryType::create(['org_id' => null, 'handle' => 'system_page', 'name' => 'P', 'plural_name' => 'Ps']);

        expect(EntryTypeResource::ownsRecord($global))->toBeFalse();
    });

    it('reports the org\'s own type as owned', function (): void {
        $mine = EntryType::create(['org_id' => $this->org->id, 'handle' => 'mine', 'name' => 'M', 'plural_name' => 'Ms']);

        expect(EntryTypeResource::ownsRecord($mine))->toBeTrue();
    });

    it('reports ANOTHER org\'s type as not owned either', function (): void {
        // No escape hatch needed: EntryType is #[Unscoped], so there is no
        // read scope to stand down — which is exactly why isolation here has
        // to be explicit in every query.
        $theirs = EntryType::create([
            'org_id' => $this->rival->id, 'handle' => 'theirs', 'name' => 'T', 'plural_name' => 'Ts',
        ]);

        expect(EntryTypeResource::ownsRecord($theirs))->toBeFalse();
    });
});

describe('reusing a handle adopts the field, so the shape has to match', function (): void {
    /*
     * ⚠️ `field_storage` is reused across entity types by design (ADR-006), and
     * the reuse path kept the existing type and cardinality while OVERWRITING
     * `pii_class`, `settings` and `is_indexed` from the new form. So every other
     * field sharing that storage changed behaviour, and the field just created
     * was not even the type its author selected.
     */
    it('refuses a reuse whose type does not match', function (): void {
        FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'email', 'type' => 'text',
            'pii_class' => 'personal', 'cardinality' => 1,
        ]);

        $manager = new FieldsRelationManager;

        expect(fn () => $manager->writeStorage([
            'storage_handle' => 'email', 'storage_type' => 'number',
            'storage_pii_class' => 'none', 'label' => 'Email',
        ]))->toThrow(RuntimeException::class, 'already describes a text field');
    });

    it('ADOPTS a MATCHING one without duplicating it', function (): void {
        $existing = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'email', 'type' => 'text',
            'pii_class' => 'personal', 'cardinality' => 1,
            'settings' => ['maxLength' => 320],
        ]);

        $presentation = (new FieldsRelationManager)->writeStorage([
            'storage_handle' => 'email', 'storage_type' => 'text',
            // The submission agrees with the stored row, which is what adoption
            // means: one definition, reused.
            'storage_pii_class' => 'personal', 'storage_settings' => ['maxLength' => 320],
            'storage_is_indexed' => false, 'label' => 'Email',
        ]);

        expect($presentation['field_storage_id'])->toBe($existing->getKey())
            ->and(FieldStorage::query()->where('handle', 'email')->count())->toBe(1)
            ->and($existing->fresh()->pii_class)->toBe('personal');
    });

    it('REFUSES an adoption that would silently discard the submission', function (): void {
        /*
         * ⚠️ Adoption keeping the stored definition is right; doing it silently
         * was not. Selecting `personal` for a handle stored as `none` reported
         * success and attached `none` — so an erasure would never reach that
         * field — and choosing a different format gave the author a field that
         * behaves unlike the one they described.
         *
         * The shape check already refused a mismatched type or cardinality for
         * this exact reason. Classification and settings define observable
         * behaviour just as much.
         */
        FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'email', 'type' => 'text',
            'pii_class' => 'none', 'cardinality' => 1, 'settings' => ['maxLength' => 320],
        ]);

        expect(fn () => (new FieldsRelationManager)->writeStorage([
            'storage_handle' => 'email', 'storage_type' => 'text',
            'storage_pii_class' => 'personal', 'storage_settings' => ['maxLength' => 320],
            'label' => 'Email',
        ]))->toThrow(RuntimeException::class, 'privacy classification differs');
    });

    it('names every attribute that diverges, not just the first', function (): void {
        FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'email', 'type' => 'text',
            'pii_class' => 'none', 'cardinality' => 1, 'settings' => ['maxLength' => 320],
        ]);

        expect(fn () => (new FieldsRelationManager)->writeStorage([
            'storage_handle' => 'email', 'storage_type' => 'text',
            'storage_pii_class' => 'sensitive', 'storage_settings' => ['maxLength' => 40],
            'storage_is_indexed' => true, 'label' => 'Email',
        ]))->toThrow(RuntimeException::class, 'privacy classification and indexing and settings differ');
    });

    it('does not refuse an adoption over a cast', function (): void {
        // Same reasoning as the shared-storage guard: the form returns `'320'`
        // where the row holds `320`, and refusing that would make every
        // legitimate adoption fail.
        FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'email', 'type' => 'text',
            'pii_class' => 'none', 'cardinality' => 1, 'settings' => ['maxLength' => 320],
        ]);

        expect(fn () => (new FieldsRelationManager)->writeStorage([
            'storage_handle' => 'email', 'storage_type' => 'text',
            'storage_pii_class' => 'none', 'storage_settings' => ['maxLength' => '320'],
            'storage_is_indexed' => '0', 'label' => 'Email',
        ]))->not->toThrow(RuntimeException::class);
    });
});

describe('the boundary is the ROUTE, not the button', function (): void {
    /*
     * ⚠️ `EditAction::visible()` decides whether a LINK is drawn.
     *
     * `/entry-types/{id}/edit` is a URL, and AGENTS.md invariant 6 says
     * anything reachable from one is untrusted. An admin typing a global
     * type's id got the form, the save and the field relation manager, and
     * could rewrite schema every other org depends on — hiding the link had
     * removed the link. `EditRecord` calls `canEdit()` from
     * `authorizeAccess()` on mount and again from `hydrate()`, so this gates
     * every Livewire update rather than only the initial GET.
     *
     * The 403 itself is asserted in the browser layer (ADR-024): there is no
     * HTTP or Livewire harness in this suite, so what is testable here is the
     * predicate Filament aborts on.
     */
    it('refuses to authorize editing a GLOBAL type', function (): void {
        $global = EntryType::create(['org_id' => null, 'handle' => 'system_page', 'name' => 'P', 'plural_name' => 'Ps']);

        expect(EntryTypeResource::canEdit($global))->toBeFalse()
            ->and(EntryTypeResource::canDelete($global))->toBeFalse();
    });

    it('refuses to authorize editing ANOTHER org\'s type', function (): void {
        $theirs = EntryType::create([
            'org_id' => $this->rival->id, 'handle' => 'theirs', 'name' => 'T', 'plural_name' => 'Ts',
        ]);

        expect(EntryTypeResource::canEdit($theirs))->toBeFalse()
            ->and(EntryTypeResource::canDelete($theirs))->toBeFalse();
    });

    it('authorizes the org\'s own type', function (): void {
        $mine = EntryType::create(['org_id' => $this->org->id, 'handle' => 'mine', 'name' => 'M', 'plural_name' => 'Ms']);

        expect(EntryTypeResource::canEdit($mine))->toBeTrue()
            ->and(EntryTypeResource::canDelete($mine))->toBeTrue();
    });

    it('refuses the FIELD relation manager for a global type', function (): void {
        // A relation manager is its own Livewire component with its own mount,
        // so gating the parent page gates the parent page. Adding fields to
        // shared schema is the same write by a different door.
        $global = EntryType::create(['org_id' => null, 'handle' => 'system_media', 'name' => 'M', 'plural_name' => 'Ms']);
        $mine = EntryType::create(['org_id' => $this->org->id, 'handle' => 'ours', 'name' => 'O', 'plural_name' => 'Os']);

        expect(FieldsRelationManager::canViewForRecord($global, EditEntryType::class))->toBeFalse()
            ->and(FieldsRelationManager::canViewForRecord($mine, EditEntryType::class))->toBeTrue();
    });
});

describe('editing a field writes its own storage', function (): void {
    /*
     * ⚠️ `writeStorage()` was bound to the edit action as well as create, and
     * on edit its lookup always found the field's OWN storage — so it took the
     * adoption branch and returned without applying anything. `storage_handle`
     * is `disabled()` on edit, so this was not an edge case: EVERY storage
     * edit was discarded while the save reported success.
     *
     * `pii_class` is the reason it matters. It drives erasure and revision
     * redaction (ADR-020), so an author who correctly reclassified a field as
     * `personal` was told it saved and a later erasure request would not reach
     * it. Adoption is right for a handle somebody else defined and wrong for
     * the row being edited.
     */
    $fieldOn = function (int $orgId, string $handle, array $storage = []): Field {
        $row = FieldStorage::create([
            'org_id' => $orgId, 'handle' => $handle, 'type' => 'text',
            'pii_class' => 'none', 'cardinality' => 1, ...$storage,
        ]);

        $type = EntryType::create([
            'org_id' => $orgId, 'handle' => 'holder_'.$handle, 'name' => 'H', 'plural_name' => 'Hs',
        ]);

        return Field::create([
            'entry_type_id' => $type->id, 'field_storage_id' => $row->id, 'label' => 'Notes',
        ]);
    };

    it('APPLIES a reclassification instead of silently adopting', function () use ($fieldOn): void {
        $field = $fieldOn($this->org->id, 'customer_notes');

        (new FieldsRelationManager)->updateStorage([
            'storage_handle' => 'customer_notes', 'storage_type' => 'text',
            'storage_pii_class' => 'sensitive', 'label' => 'Notes',
        ], $field);

        expect($field->fieldStorage->fresh()->pii_class)->toBe('sensitive');
    });

    it('applies a settings change and an index change', function () use ($fieldOn): void {
        $field = $fieldOn($this->org->id, 'headline', ['settings' => ['maxLength' => 100]]);

        (new FieldsRelationManager)->updateStorage([
            'storage_handle' => 'headline', 'storage_type' => 'text',
            'storage_pii_class' => 'none', 'storage_settings' => ['maxLength' => 240],
            'storage_is_indexed' => true, 'label' => 'Headline',
        ], $field);

        $fresh = $field->fieldStorage->fresh();

        expect($fresh->settings['maxLength'])->toBe(240)
            ->and($fresh->is_indexed)->toBeTrue();
    });

    it('leaves a value ALONE when the form did not submit it', function () use ($fieldOn): void {
        // The create path reads `?? false` and `?? []`, where an absent key
        // means "not requested". Here it would mean "drop the index" and
        // "erase the settings" — every one of these is dehydrated(), so an
        // absent key is a form-shape bug and must not also be data loss.
        $field = $fieldOn($this->org->id, 'tagline', ['settings' => ['maxLength' => 80], 'is_indexed' => true]);

        (new FieldsRelationManager)->updateStorage([
            'storage_handle' => 'tagline', 'storage_type' => 'text',
            'storage_pii_class' => 'none', 'label' => 'Tagline',
        ], $field);

        $fresh = $field->fieldStorage->fresh();

        expect($fresh->settings['maxLength'])->toBe(80)
            ->and($fresh->is_indexed)->toBeTrue();
    });

    it('still lets a LOCKED row be indexed, because the model decides that', function () use ($fieldOn): void {
        // The edit path writes through `save()` rather than around it, so
        // `FieldStorage::guardShape()` is what rules on a locked row — the
        // lock is not reimplemented here. Indexing stays allowed while locked
        // because it is expensive rather than unsafe (ADR-006).
        $field = $fieldOn($this->org->id, 'locked_note', ['is_locked' => true]);

        (new FieldsRelationManager)->updateStorage([
            'storage_handle' => 'locked_note', 'storage_type' => 'text',
            'storage_pii_class' => 'none', 'label' => 'Locked',
            'storage_is_indexed' => true,
        ], $field);

        expect($field->fieldStorage->fresh()->is_indexed)->toBeTrue();
    });
});

describe('shared storage is not one org\'s to change', function (): void {
    /*
     * ⚠️ Introduced BY the fix that made editing work at all, and caught in
     * the next review round.
     *
     * `Field::guardStorageOwnership()` deliberately permits an org-owned type to
     * attach GLOBAL storage (`org_id IS NULL`), the same way it permits a global
     * entry type. So making the edit path write meant one org could rewrite the
     * classification, indexing and settings of a row every org depends on — a
     * wider blast radius than the cross-org case, not a narrower one. The create
     * path never had this hole, because adoption never wrote to the adopted row.
     *
     * The refusal is for the STORAGE half only: the `Field` row is this type's
     * own, so relabelling a global field here stays allowed. That separation is
     * the point of ADR-006's split.
     */
    $globalFieldOn = function (int $typeId, array $storage = []): Field {
        $row = FieldStorage::create([
            'org_id' => null, 'handle' => 'global_email', 'type' => 'text',
            'pii_class' => 'personal', 'cardinality' => 1, 'settings' => ['maxLength' => 320], ...$storage,
        ]);

        return Field::create([
            'entry_type_id' => $typeId, 'field_storage_id' => $row->id, 'label' => 'Email',
        ]);
    };

    it('refuses a reclassification of GLOBAL storage', function () use ($globalFieldOn): void {
        $type = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'people', 'name' => 'P', 'plural_name' => 'Ps',
        ]);
        $field = $globalFieldOn($type->id);

        expect(fn () => (new FieldsRelationManager)->updateStorage([
            'storage_handle' => 'global_email', 'storage_type' => 'text',
            'storage_pii_class' => 'none', 'label' => 'Email',
        ], $field))->toThrow(RuntimeException::class, 'shared by every organisation');

        expect($field->fieldStorage->fresh()->pii_class)->toBe('personal');
    });

    it('refuses an indexing change to GLOBAL storage', function () use ($globalFieldOn): void {
        $type = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'people2', 'name' => 'P', 'plural_name' => 'Ps',
        ]);
        $field = $globalFieldOn($type->id);

        expect(fn () => (new FieldsRelationManager)->updateStorage([
            'storage_handle' => 'global_email', 'storage_type' => 'text',
            'storage_pii_class' => 'personal', 'storage_is_indexed' => true, 'label' => 'Email',
        ], $field))->toThrow(RuntimeException::class, 'indexing is not this organisation\'s to change');

        expect($field->fieldStorage->fresh()->is_indexed)->toBeFalse();
    });

    it('ALLOWS a presentation-only edit of a global field', function () use ($globalFieldOn): void {
        // The Field row is this type's own. Refusing this would make a shared
        // field unusable rather than merely uneditable.
        $type = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'people3', 'name' => 'P', 'plural_name' => 'Ps',
        ]);
        $field = $globalFieldOn($type->id);

        $presentation = (new FieldsRelationManager)->updateStorage([
            'storage_handle' => 'global_email', 'storage_type' => 'text',
            // Unchanged — the values the form round-tripped out of the row.
            'storage_pii_class' => 'personal', 'storage_settings' => ['maxLength' => 320],
            'storage_is_indexed' => false,
            'label' => 'Work email', 'help_text' => 'Used for invoices', 'is_required' => true,
        ], $field);

        expect($presentation['label'])->toBe('Work email')
            ->and($presentation['is_required'])->toBeTrue()
            ->and($field->fieldStorage->fresh()->pii_class)->toBe('personal');
    });

    it('does not refuse a no-op edit because of a cast', function () use ($globalFieldOn): void {
        // ⚠️ The form returns `"320"` where the row holds `320`. Comparing by
        // hand, a strict test refuses an edit that changes nothing and a loose
        // one lets a real change through — so the model's own dirty check
        // decides, on a clone.
        $type = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'people4', 'name' => 'P', 'plural_name' => 'Ps',
        ]);
        $field = $globalFieldOn($type->id);

        expect(fn () => (new FieldsRelationManager)->updateStorage([
            'storage_handle' => 'global_email', 'storage_type' => 'text',
            'storage_pii_class' => 'personal', 'storage_settings' => ['maxLength' => '320'],
            'storage_is_indexed' => '0', 'label' => 'Email',
        ], $field))->not->toThrow(RuntimeException::class);
    });

    it('still refuses a GENUINE settings change on shared storage', function () use ($globalFieldOn): void {
        // The loose comparison exists to ignore `'320'` versus `320`. It must
        // not also ignore 320 versus 40.
        $type = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'people6', 'name' => 'P', 'plural_name' => 'Ps',
        ]);
        $field = $globalFieldOn($type->id);

        expect(fn () => (new FieldsRelationManager)->updateStorage([
            'storage_handle' => 'global_email', 'storage_type' => 'text',
            'storage_pii_class' => 'personal', 'storage_settings' => ['maxLength' => 40],
            'label' => 'Email',
        ], $field))->toThrow(RuntimeException::class, 'settings is not this organisation');

        expect($field->fieldStorage->fresh()->settings['maxLength'])->toBe(320);
    });

    it('still lets the org edit its OWN storage', function (): void {
        $storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'mine_email', 'type' => 'text',
            'pii_class' => 'none', 'cardinality' => 1,
        ]);
        $type = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'people5', 'name' => 'P', 'plural_name' => 'Ps',
        ]);
        $field = Field::create([
            'entry_type_id' => $type->id, 'field_storage_id' => $storage->id, 'label' => 'Email',
        ]);

        (new FieldsRelationManager)->updateStorage([
            'storage_handle' => 'mine_email', 'storage_type' => 'text',
            'storage_pii_class' => 'sensitive', 'label' => 'Email',
        ], $field);

        expect($storage->fresh()->pii_class)->toBe('sensitive');
    });
});

describe('the subject selector offers only fields that can be saved', function (): void {
    /*
     * ⚠️ Every field was listed, and `guardSubjectShape()` refuses a
     * multi-valued one — so choosing it produced a save that threw. An option
     * presented as valid that cannot be saved.
     *
     * The predicate is now shared with the guard rather than reimplemented,
     * because a second copy drifts: review found exactly that failure four
     * times in one PR, where a field type's validation and its published schema
     * were maintained separately (invariant 14).
     */
    $fieldOfCardinality = function (int $typeId, string $handle, int $cardinality): Field {
        $storage = FieldStorage::create([
            'org_id' => test()->org->id, 'handle' => $handle, 'type' => 'text',
            'pii_class' => 'personal', 'cardinality' => $cardinality,
        ]);

        return Field::create([
            'entry_type_id' => $typeId, 'field_storage_id' => $storage->id, 'label' => ucfirst($handle),
        ]);
    };

    it('reports no refusal for a single-valued field', function () use ($fieldOfCardinality): void {
        $type = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'subj1', 'name' => 'S', 'plural_name' => 'Ss',
        ]);
        $field = $fieldOfCardinality($type->id, 'email', 1);

        expect($type->subjectShapeRefusal($field))->toBeNull();
    });

    it('reports a refusal for a MULTI-valued field, with the reason', function () use ($fieldOfCardinality): void {
        $type = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'subj2', 'name' => 'S', 'plural_name' => 'Ss',
        ]);
        $field = $fieldOfCardinality($type->id, 'aliases', -1);

        expect($type->subjectShapeRefusal($field))->toContain('holds many values');
    });

    it('is the SAME predicate the model refuses on', function () use ($fieldOfCardinality): void {
        // The point of the refactor: if these two could disagree, the selector
        // would offer a field the save rejects — which is the bug.
        $type = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'subj3', 'name' => 'S', 'plural_name' => 'Ss',
        ]);
        $field = $fieldOfCardinality($type->id, 'aliases', -1);

        expect($type->subjectShapeRefusal($field))->not->toBeNull()
            ->and(fn () => $type->update(['subject_field_id' => $field->getKey()]))
            ->toThrow(RuntimeException::class, 'holds many values');
    });
});

describe('an icon nobody can resolve must not brick the admin', function (): void {
    /*
     * ⚠️ `entry_types.icon` was free text rendered into the navigation on EVERY
     * admin page, and Blade Icons throws `SvgNotFound` for a name it cannot
     * resolve. Measured: setting one type's icon to `heroicon-o-this-does-not-exist`
     * returned 500 from `/admin/{site}`, `/admin/{site}/entry-types` AND
     * `/admin/{site}/c/{type}` — so an author could brick their own admin with a
     * typo and had no page left through which to correct it.
     *
     * Three layers, and they are not redundant. `Icons::orFallback()` at the
     * render boundary is what prevents the outage and holds however the value
     * arrived — seed, import, or direct SQL. The form's select stops the common
     * path. This guard makes a bad write REPORTED rather than silently rendered
     * as some other icon, which would send the author looking in the stylesheet.
     */
    it('refuses to save an icon no installed set provides', function (): void {
        expect(fn () => EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'bad_icon', 'name' => 'B', 'plural_name' => 'Bs',
            'icon' => 'heroicon-o-this-does-not-exist',
        ]))->toThrow(RuntimeException::class, 'not an icon any installed set provides');
    });

    it('accepts a real one, and an empty one', function (): void {
        $withIcon = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'good_icon', 'name' => 'G', 'plural_name' => 'Gs',
            'icon' => 'heroicon-o-photo',
        ]);
        $without = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'no_icon', 'name' => 'N', 'plural_name' => 'Ns',
        ]);

        expect($withIcon->icon)->toBe('heroicon-o-photo')
            ->and($without->icon)->toBeNull();
    });

    it('falls back at the RENDER boundary, whatever the source of the value', function (): void {
        // The layer that actually prevents the outage. A row can reach this
        // state without passing the guard — a seeder, an importer, a direct
        // UPDATE, or a row written before the guard existed.
        expect(Icons::orFallback('heroicon-o-this-does-not-exist'))->toBe(Icons::DEFAULT_ENTRY_TYPE)
            ->and(Icons::orFallback(null))->toBe(Icons::DEFAULT_ENTRY_TYPE)
            ->and(Icons::orFallback(''))->toBe(Icons::DEFAULT_ENTRY_TYPE)
            ->and(Icons::orFallback('heroicon-o-photo'))->toBe('heroicon-o-photo');
    });

    it('offers only names that resolve', function (): void {
        // A select whose options include an unresolvable name would move the
        // failure rather than remove it.
        $options = Icons::options();

        expect($options)->toHaveKeys(['Outlined', 'Solid']);

        foreach ([...array_keys($options['Outlined']), ...array_keys($options['Solid'])] as $name) {
            expect(Icons::resolves($name))->toBeTrue("[{$name}] does not resolve");
        }
    });
});

describe('the builder can express a finite cardinality', function (): void {
    /*
     * ⚠️ The Values control offered One and Many only, while every layer below
     * honours a finite bound: `max:{n}` in validation, `maxItems` in the
     * published API schema, and the count the relation writer serialises
     * against. So "at most three authors" was expressible everywhere except in
     * the flagship builder, and an author needing it had to choose unlimited.
     */
    it('stores a finite maximum from the form\'s two controls', function (): void {
        (new FieldsRelationManager)->writeStorage([
            'storage_handle' => 'authors', 'storage_type' => 'text',
            'storage_cardinality' => 'max', 'storage_cardinality_max' => 3,
            'storage_pii_class' => 'none', 'label' => 'Authors',
        ]);

        $storage = FieldStorage::query()->where('handle', 'authors')->first();

        expect($storage->cardinality)->toBe(3)
            ->and($storage->isMultiValue())->toBeTrue();
    });

    it('still stores one and unlimited', function (): void {
        $manager = new FieldsRelationManager;

        $manager->writeStorage([
            'storage_handle' => 'single', 'storage_type' => 'text',
            'storage_cardinality' => 1, 'storage_pii_class' => 'none', 'label' => 'S',
        ]);
        $manager->writeStorage([
            'storage_handle' => 'unbounded', 'storage_type' => 'text',
            'storage_cardinality' => -1, 'storage_pii_class' => 'none', 'label' => 'U',
        ]);

        expect(FieldStorage::query()->where('handle', 'single')->value('cardinality'))->toBe(1)
            ->and(FieldStorage::query()->where('handle', 'unbounded')->value('cardinality'))->toBe(-1);
    });

    it('floors the maximum at two rather than trusting the input', function (): void {
        // A maximum of one IS cardinality one, and `minValue(2)` is a client and
        // validation concern — this is the value that reaches the column.
        (new FieldsRelationManager)->writeStorage([
            'storage_handle' => 'floored', 'storage_type' => 'text',
            'storage_cardinality' => 'max', 'storage_cardinality_max' => 0,
            'storage_pii_class' => 'none', 'label' => 'F',
        ]);

        expect(FieldStorage::query()->where('handle', 'floored')->value('cardinality'))->toBe(2);
    });

    it('adopts a bounded storage row rather than calling it a mismatch', function (): void {
        // ⚠️ The reuse check reads the cardinality too. Casting the raw form
        // value would read the `max` sentinel as 0, so adopting a row with a
        // finite bound would look like a shape mismatch and be refused.
        FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'bounded', 'type' => 'text',
            'pii_class' => 'none', 'cardinality' => 4,
        ]);

        expect(fn () => (new FieldsRelationManager)->writeStorage([
            'storage_handle' => 'bounded', 'storage_type' => 'text',
            'storage_cardinality' => 'max', 'storage_cardinality_max' => 4,
            'storage_pii_class' => 'none', 'label' => 'B',
        ]))->not->toThrow(RuntimeException::class);
    });

    it('still refuses a reuse whose bound differs', function (): void {
        FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'bounded2', 'type' => 'text',
            'pii_class' => 'none', 'cardinality' => 4,
        ]);

        expect(fn () => (new FieldsRelationManager)->writeStorage([
            'storage_handle' => 'bounded2', 'storage_type' => 'text',
            'storage_cardinality' => 'max', 'storage_cardinality_max' => 9,
            'storage_pii_class' => 'none', 'label' => 'B',
        ]))->toThrow(RuntimeException::class, 'already describes');
    });
});

describe('a bulk delete is all or nothing', function (): void {
    /*
     * ⚠️ `refuseGlobal()` checks the whole selection up front, but each delete
     * then ran its own cascade refusal — so a selection holding an entry-free
     * type followed by one that still has entries deleted the first and threw on
     * the second. The author saw a failure after part of their schema was
     * already gone, which is the worst way to report one.
     */
    it('deletes NOTHING when a later record refuses', function (): void {
        $empty = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'empty_type', 'name' => 'E', 'plural_name' => 'Es',
        ]);
        $occupied = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'busy_type', 'name' => 'B', 'plural_name' => 'Bs',
        ]);

        Entry::create([
            'entry_type_id' => $occupied->id, 'title' => 'Holds data', 'values' => [],
        ]);

        // Ordered deliberately: the deletable one FIRST, so a non-atomic
        // implementation commits it before the refusal fires.
        expect(fn () => EntryTypeResource::deleteSelected(collect([$empty, $occupied])))
            ->toThrow(RuntimeException::class, 'would delete them by cascade');

        expect(EntryType::query()->whereKey($empty->getKey())->exists())->toBeTrue()
            ->and(EntryType::query()->whereKey($occupied->getKey())->exists())->toBeTrue();
    });

    it('deletes ALL of them when every record is deletable', function (): void {
        $first = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'gone_one', 'name' => 'G', 'plural_name' => 'Gs',
        ]);
        $second = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'gone_two', 'name' => 'G', 'plural_name' => 'Gs',
        ]);

        EntryTypeResource::deleteSelected(collect([$first, $second]));

        expect(EntryType::query()->whereKey([$first->getKey(), $second->getKey()])->count())->toBe(0);
    });

    it('still refuses a selection containing a GLOBAL type, before deleting any', function (): void {
        $mine = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'mine_bulk', 'name' => 'M', 'plural_name' => 'Ms',
        ]);
        $global = EntryType::create([
            'org_id' => null, 'handle' => 'system_bulk', 'name' => 'S', 'plural_name' => 'Ss',
        ]);

        expect(fn () => EntryTypeResource::deleteSelected(collect([$mine, $global])))
            ->toThrow(RuntimeException::class, 'shared by every organisation');

        expect(EntryType::query()->whereKey($mine->getKey())->exists())->toBeTrue();
    });
});

describe('a pattern that cannot compile is refused where it is authored', function (): void {
    /*
     * ⚠️ `TextType::patternRule()` refuses EVERY value when the pattern will not
     * compile, which is correct — an uncheckable constraint must not pass. But
     * the builder accepted the pattern, so the outcome was a field nothing could
     * be stored in until someone went back and repaired its settings. The rule
     * failing closed was right; accepting the setting was not.
     *
     * Enforced from the field type's own declaration (`format => regex`) rather
     * than a `pattern` special case, so a new type declaring one is covered with
     * no further change — and in the model as well as the form, because a form
     * is one door.
     */
    it('refuses an uncompilable pattern on save', function (): void {
        expect(fn () => FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'code', 'type' => 'text',
            'pii_class' => 'none', 'cardinality' => 1,
            // Unbalanced group: PCRE cannot compile it.
            'settings' => ['maxLength' => 20, 'pattern' => '^[A-Z'],
        ]))->toThrow(RuntimeException::class, 'That pattern cannot be compiled');
    });

    it('refuses a pattern that cannot be delimited at all', function (): void {
        // Every candidate delimiter appears in the pattern, so there is nothing
        // left to wrap it in — refused rather than silently mangled.
        expect(fn () => FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'code2', 'type' => 'text',
            'pii_class' => 'none', 'cardinality' => 1,
            'settings' => ['pattern' => 'a/b#c~d%e!f'],
        ]))->toThrow(RuntimeException::class, 'That pattern cannot be compiled');
    });

    it('accepts a valid pattern, and an absent one', function (): void {
        $constrained = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'code3', 'type' => 'text',
            'pii_class' => 'none', 'cardinality' => 1,
            'settings' => ['pattern' => '^[A-Z]{2}-[0-9]+$'],
        ]);
        $unconstrained = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'code4', 'type' => 'text',
            'pii_class' => 'none', 'cardinality' => 1, 'settings' => ['maxLength' => 20],
        ]);

        expect($constrained->settings['pattern'])->toBe('^[A-Z]{2}-[0-9]+$')
            ->and($unconstrained->settings)->not->toHaveKey('pattern');
    });

    it('ignores the declaration for a type that has none', function (): void {
        // The guard reads what the TYPE declared, so a number field carries no
        // regex constraint and is unaffected.
        $number = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'price', 'type' => 'number',
            'pii_class' => 'none', 'cardinality' => 1,
            'settings' => ['format' => 'decimal', 'precision' => 12, 'scale' => 2],
        ]);

        expect($number->exists)->toBeTrue();
    });

    it('agrees with the rule that validates values against it', function (): void {
        // ⚠️ The point of one shared implementation. If the guard and the rule
        // could disagree, a pattern the builder accepted could still refuse every
        // value — which is the defect, restated.
        expect(Pattern::compiles('^[A-Z]{2}-[0-9]+$'))->toBeTrue()
            ->and(Pattern::compiles('^[A-Z'))->toBeFalse()
            ->and(Pattern::compiles('a/b#c~d%e!f'))->toBeFalse()
            ->and(Pattern::delimit('^[a-z]+$'))->toBe('/^[a-z]+$/u')
            // The first delimiter the pattern does not itself contain.
            ->and(Pattern::delimit('a/b'))->toBe('#a/b#u');
    });
});

describe('settings that contradict themselves are refused', function (): void {
    /*
     * ⚠️ The interesting constraints are not per-setting, which is why they live
     * in `FieldType::validateSettings()` rather than in a descriptor key.
     *
     * A minimum above a maximum leaves NO value that can be stored —
     * `NumberType::scalarValidationRules()` emits both bounds — and neither
     * control is individually wrong, so no per-setting rule could see it. Same
     * unusable outcome as an uncompilable pattern, reached by another route.
     */
    it('refuses a minimum above the maximum', function (): void {
        expect(fn () => FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'price', 'type' => 'number',
            'pii_class' => 'none', 'cardinality' => 1,
            'settings' => ['format' => 'decimal', 'precision' => 12, 'scale' => 2, 'min' => 100, 'max' => 10],
        ]))->toThrow(RuntimeException::class, 'no value could ever be stored');
    });

    it('accepts bounds that can both be satisfied, including equal ones', function (): void {
        $range = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'price2', 'type' => 'number',
            'pii_class' => 'none', 'cardinality' => 1,
            'settings' => ['format' => 'decimal', 'precision' => 12, 'scale' => 2, 'min' => 1, 'max' => 100],
        ]);
        $exact = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'price3', 'type' => 'number',
            'pii_class' => 'none', 'cardinality' => 1,
            // A single permitted value is narrow, not unusable. The bar is "no
            // value can satisfy this", not "this looks odd".
            'settings' => ['format' => 'decimal', 'precision' => 12, 'scale' => 2, 'min' => 5, 'max' => 5],
        ]);

        expect($range->exists)->toBeTrue()->and($exact->exists)->toBeTrue();
    });

    it('ignores a bound that is absent or not a number', function (): void {
        $open = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'price4', 'type' => 'number',
            'pii_class' => 'none', 'cardinality' => 1,
            'settings' => ['format' => 'decimal', 'precision' => 12, 'scale' => 2, 'min' => 1],
        ]);

        expect($open->exists)->toBeTrue();
    });

    it('refuses a range that is ordered but contains no representable value', function (): void {
        /*
         * ⚠️ ORDERED is not INHABITED, and checking only the ordering let an empty
         * range through. `scalarValidationRules()` emits the format alongside both
         * bounds, so an `integer` field with min 0.1 and max 0.9 accepts nothing
         * at all — the same unusable outcome as an uncompilable pattern, reached
         * by arithmetic instead of syntax.
         */
        expect(fn () => FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'count', 'type' => 'number',
            'pii_class' => 'none', 'cardinality' => 1,
            'settings' => ['format' => 'integer', 'precision' => 12, 'scale' => 0, 'min' => 0.1, 'max' => 0.9],
        ]))->toThrow(RuntimeException::class, 'No value this field can represent');
    });

    it('refuses a range outside the PROJECTION\'s own bound', function (): void {
        // ⚠️ The rules emit `lt:10^(precision-scale)` for a decimal field, so
        // precision 2 / scale 1 admits nothing at or above 10 — and min = max = 10
        // was accepted because that constraint was not part of the interval being
        // tested. Ordered, on the grid, and still empty.
        expect(fn () => FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'bounded_rate', 'type' => 'number',
            'pii_class' => 'none', 'cardinality' => 1,
            'settings' => ['format' => 'decimal', 'precision' => 2, 'scale' => 1, 'min' => 10, 'max' => 10],
        ]))->toThrow(RuntimeException::class, 'No value this field can represent');
    });

    it('refuses a ONE-SIDED range the projection closes', function (): void {
        // ⚠️ Requiring both bounds let this through: `lt:10^(precision-scale)` is
        // emitted regardless, so precision 2 / scale 1 with `min = 10` and no
        // maximum admits nothing. The projection supplies the other side, and the
        // message names it rather than leaving a blank where a setting is not.
        expect(fn () => FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'one_sided', 'type' => 'number',
            'pii_class' => 'none', 'cardinality' => 1,
            'settings' => ['format' => 'decimal', 'precision' => 2, 'scale' => 1, 'min' => 10],
        ]))->toThrow(RuntimeException::class, 'between 10 and 9.9');
    });

    it('leaves an integer field genuinely open-ended', function (): void {
        // An integer field has no projection bound, so one-sided really is open.
        $open = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'one_sided2', 'type' => 'number',
            'pii_class' => 'none', 'cardinality' => 1,
            'settings' => ['format' => 'integer', 'min' => 10],
        ]);

        expect($open->exists)->toBeTrue();
    });

    it('accepts a SINGLETON range a float multiply would have refused', function (): void {
        // ⚠️ `0.29 * 100` is `28.999999999999996`, so `ceil()` gave 29 and
        // `floor()` gave 28 for the same number — 29 > 28, and a range containing
        // exactly 0.29 was refused. Moving the comparisons to integers fixed the
        // comparisons and left the CONVERSION in floats, which is where the
        // imprecision was. It is parsed from the decimal text now.
        $singleton = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'exact', 'type' => 'number',
            'pii_class' => 'none', 'cardinality' => 1,
            'settings' => ['format' => 'decimal', 'precision' => 12, 'scale' => 2, 'min' => 0.29, 'max' => 0.29],
        ]);
        $negative = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'exact_negative', 'type' => 'number',
            'pii_class' => 'none', 'cardinality' => 1,
            'settings' => ['format' => 'decimal', 'precision' => 12, 'scale' => 2, 'min' => -0.29, 'max' => -0.29],
        ]);

        expect($singleton->exists)->toBeTrue()->and($negative->exists)->toBeTrue();
    });

    it('still refuses a singleton BELOW the grid', function (): void {
        // The counterpart: 0.295 is not representable at scale 2, so a range
        // holding only it is empty. Rounding direction has to differ for the two
        // ends, and this is what proves it does.
        expect(fn () => FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'inexact', 'type' => 'number',
            'pii_class' => 'none', 'cardinality' => 1,
            'settings' => ['format' => 'decimal', 'precision' => 12, 'scale' => 2, 'min' => 0.295, 'max' => 0.295],
        ]))->toThrow(RuntimeException::class, 'No value this field can represent');
    });

    it('accepts the largest value that bound DOES admit', function (): void {
        $edge = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'bounded_rate2', 'type' => 'number',
            'pii_class' => 'none', 'cardinality' => 1,
            'settings' => ['format' => 'decimal', 'precision' => 2, 'scale' => 1, 'min' => 9.9, 'max' => 9.9],
        ]);

        expect($edge->exists)->toBeTrue();
    });

    it('refuses a step whose candidates never land on the scale grid', function (): void {
        // ⚠️ The step is offset from `min`, so scale 2 with min 0.001 and step 0.01
        // offers 0.001, 0.011, 0.021 … and none has two decimals. An earlier
        // version rounded the offset UP onto the grid and thereby invented a
        // candidate the runtime rule would never accept.
        expect(fn () => FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'stepped', 'type' => 'number',
            'pii_class' => 'none', 'cardinality' => 1,
            'settings' => ['format' => 'decimal', 'precision' => 12, 'scale' => 2,
                'min' => 0.001, 'max' => 0.1, 'step' => 0.01],
        ]))->toThrow(RuntimeException::class, 'never lands on a value this field can store');
    });

    it('accepts a step aligned with the scale', function (): void {
        $aligned = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'stepped2', 'type' => 'number',
            'pii_class' => 'none', 'cardinality' => 1,
            'settings' => ['format' => 'decimal', 'precision' => 12, 'scale' => 2,
                'min' => 0.05, 'max' => 0.1, 'step' => 0.01],
        ]);

        expect($aligned->exists)->toBeTrue();
    });

    it('is exact at a scale where a float tolerance would not be', function (): void {
        // ⚠️ The previous check used an absolute 1e-9 tolerance, which at scale 14
        // is larger than every value being compared — it accepted min = max = 5e-15
        // on a 1e-14 grid. The check is computed in integer quanta now, so there is
        // no tolerance to be wrong about.
        expect(fn () => FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'tiny', 'type' => 'number',
            'pii_class' => 'none', 'cardinality' => 1,
            'settings' => ['format' => 'decimal', 'precision' => 15, 'scale' => 14,
                'min' => 5e-15, 'max' => 5e-15],
        ]))->toThrow(RuntimeException::class, 'No value this field can represent');
    });

    it('refuses a decimal range narrower than its own scale', function (): void {
        // The same emptiness at a finer grain: with scale 2 the values are
        // multiples of 0.01, so [0.001, 0.002] holds none of them.
        expect(fn () => FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'rate', 'type' => 'number',
            'pii_class' => 'none', 'cardinality' => 1,
            'settings' => ['format' => 'decimal', 'precision' => 12, 'scale' => 2, 'min' => 0.001, 'max' => 0.002],
        ]))->toThrow(RuntimeException::class, 'multiples of 0.01');
    });

    it('accepts a narrow range that DOES contain one', function (): void {
        // ⚠️ 0.29 / 0.01 is not exactly 29 in binary floating point, so a naive
        // ceil() rounds it to 30 and refuses a range that holds 0.29, 0.30 and
        // 0.31. The epsilon exists for this case, and this test is why.
        $narrow = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'rate2', 'type' => 'number',
            'pii_class' => 'none', 'cardinality' => 1,
            'settings' => ['format' => 'decimal', 'precision' => 12, 'scale' => 2, 'min' => 0.29, 'max' => 0.31],
        ]);

        expect($narrow->exists)->toBeTrue();
    });

    it('does not lose the digits that decide whether a range is inhabited', function (): void {
        /*
         * ⚠️ THE THIRD ROUND on the same conversion, and each fix moved the
         * imprecision rather than removing it.
         *
         * First a float multiply: `0.29 * 100` is `28.999999999999996`, so ceil()
         * gave 29, floor() gave 28, and a singleton range at 0.29 was refused.
         * Then integer comparisons, which fixed the comparing and left the
         * CONVERTING in floats. Then `sprintf('%.4F', ...)` — two guard digits past
         * the scale — which renders `0.2900001` as `0.2900`, so the check saw
         * nothing below the quantum when there were five digits of it.
         *
         * `min = max = 0.2900001` at scale 2 therefore read as the inhabited
         * singleton 29 quanta. No value on the 0.01 grid equals 0.2900001, so the
         * range holds nothing and the field could never be written to — the exact
         * condition this guard exists to refuse, waved through by the guard.
         *
         * Two guard digits answer the question for numbers with at most two digits
         * below the grid, which is not the question. The number decides how many
         * digits it has, so the text has to carry all of them.
         */
        expect(fn () => FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'sliver', 'type' => 'number',
            'pii_class' => 'none', 'cardinality' => 1,
            'settings' => ['format' => 'decimal', 'precision' => 12, 'scale' => 2,
                'min' => 0.2900001, 'max' => 0.2900001],
        ]))->toThrow(RuntimeException::class, 'No value this field can represent');
    });

    it('reads a numeric setting submitted as text, which is how it arrives', function (): void {
        // ⚠️ Filament submits numeric inputs as STRINGS, so this is the ordinary
        // path rather than an edge case — and the author's own text is the more
        // faithful record: `0.1` as text is exactly one tenth, as a float it is not.
        expect(fn () => FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'sliver2', 'type' => 'number',
            'pii_class' => 'none', 'cardinality' => 1,
            'settings' => ['format' => 'decimal', 'precision' => 12, 'scale' => 2,
                'min' => '0.2900001', 'max' => '0.2900001'],
        ]))->toThrow(RuntimeException::class, 'No value this field can represent');

        // And a range that IS inhabited still goes through when given as text.
        $ok = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'rate3', 'type' => 'number',
            'pii_class' => 'none', 'cardinality' => 1,
            'settings' => ['format' => 'decimal', 'precision' => 12, 'scale' => 2,
                'min' => '0.29', 'max' => '0.31'],
        ]);

        expect($ok->exists)->toBeTrue();
    });

    it('keeps its precision on a value only exponent notation can write', function (): void {
        // `1.0E-15` has no decimal point to shift, so the digits are expanded out
        // of the exponent first. A scale-2 field cannot represent it, and the
        // refusal has to say so rather than reading it as zero and accepting.
        expect(fn () => FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'tiny2', 'type' => 'number',
            'pii_class' => 'none', 'cardinality' => 1,
            'settings' => ['format' => 'decimal', 'precision' => 12, 'scale' => 2,
                'min' => 1.0E-15, 'max' => 5.0E-15],
        ]))->toThrow(RuntimeException::class, 'multiples of 0.01');
    });

    it('refuses a pattern PCRE understands and JSON Schema does not', function (): void {
        /*
         * ⚠️ The pattern is PUBLISHED as well as enforced.
         * `TextType::scalarApiSchema()` emits it verbatim as a JSON Schema
         * `pattern`, and that dialect is ECMAScript — so a PCRE-only expression
         * compiles here, enforces correctly server-side, and hands a generated
         * client a constraint it cannot compile. Invariant 14: publish the
         * constraint, and a constraint the consumer cannot read is not published.
         */
        expect(fn () => FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'ref', 'type' => 'text',
            'pii_class' => 'none', 'cardinality' => 1,
            'settings' => ['maxLength' => 30, 'pattern' => '(?P<code>[A-Z]{2})'],
        ]))->toThrow(RuntimeException::class, 'the JSON Schema dialect does not');
    });

    it('accepts the ECMAScript spelling of a named group', function (): void {
        // The point of naming constructs rather than rejecting anything unusual:
        // `(?<name>...)` is valid in both dialects and must go through.
        $named = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'ref2', 'type' => 'text',
            'pii_class' => 'none', 'cardinality' => 1,
            'settings' => ['maxLength' => 30, 'pattern' => '^(?<code>[A-Z]{2})-[0-9]+$'],
        ]);

        expect($named->settings['pattern'])->toBe('^(?<code>[A-Z]{2})-[0-9]+$');
    });

    it('screens with SYNTAX awareness, not substrings', function (): void {
        /*
         * ⚠️ A substring scan was unsound in both directions, which is worse
         * than being unsound in one.
         *
         * `\\A` is a literal backslash then an A — valid everywhere — and was
         * refused because the second slash begins the substring `\A`. `[(?>]` is
         * a character class of punctuation and was refused the same way. Meanwhile
         * `(?i)^abc$` was ACCEPTED: it compiles in PCRE, ECMAScript has no bare
         * inline modifier, and no substring in the old list matched it.
         */
        expect(Pattern::unpublishable('^[A-Z]+$'))->toBeNull()
            // Escaped backslash: two literals, not an anchor.
            ->and(Pattern::unpublishable('\\\\A'))->toBeNull()
            // Inside a character class these are literals.
            ->and(Pattern::unpublishable('[(?>]'))->toBeNull()
            ->and(Pattern::unpublishable('[0-9\\-]+'))->toBeNull()
            // Every group form ECMAScript actually has.
            ->and(Pattern::unpublishable('(?:ab)+'))->toBeNull()
            ->and(Pattern::unpublishable('(?=x)y'))->toBeNull()
            ->and(Pattern::unpublishable('(?<=a)b'))->toBeNull()
            ->and(Pattern::unpublishable('^(?<name>a)$'))->toBeNull();
    });

    it('refuses what a consumer could not compile', function (): void {
        expect(Pattern::unpublishable('\Ax'))->toContain('\A anchor')
            // ⚠️ The false NEGATIVE the substring scan had: a bare inline
            // modifier compiles in PCRE and has no ECMAScript equivalent.
            ->and(Pattern::unpublishable('(?i)^abc$'))->toContain('`(?i)`')
            ->and(Pattern::unpublishable('(?P<a>x)'))->toContain('(?P<')
            ->and(Pattern::unpublishable('(?>x)'))->toContain('(?>')
            ->and(Pattern::unpublishable('(?#c)x'))->toContain('(?#c)')
            ->and(Pattern::unpublishable('(?(1)a|b)'))->toContain('(?(1)');
    });

    it('refuses possessive quantifiers, which tracking escapes made possible', function (): void {
        /*
         * ⚠️ I declined to detect these while the screen was substring-based,
         * because `\++` — an escaped plus followed by a quantifier — could not be
         * told from `a++` without a parse, and a false refusal is the worse trade.
         *
         * The scanner tracks escapes now, so the objection no longer holds: the
         * escaped plus is consumed as an escape and never reaches the quantifier
         * check. That is the case asserted first, because it is the one the
         * earlier decision was protecting.
         */
        expect(Pattern::unpublishable('\\++'))->toBeNull()
            ->and(Pattern::unpublishable('a\\+\\+b'))->toBeNull()
            // Ordinary and lazy quantifiers are untouched.
            ->and(Pattern::unpublishable('a+'))->toBeNull()
            ->and(Pattern::unpublishable('a+?'))->toBeNull()
            ->and(Pattern::unpublishable('[+]+'))->toBeNull()
            // And the possessive forms are refused.
            ->and(Pattern::unpublishable('a++'))->toContain('possessive')
            ->and(Pattern::unpublishable('a*+'))->toContain('possessive')
            ->and(Pattern::unpublishable('x{2,3}+'))->toContain('possessive');
    });

    it('refuses backtracking control verbs', function (): void {
        // ⚠️ These open with `(*` rather than `(?`, so the group allowlist never
        // saw them — a whole family of PCRE-only syntax slipping past a screen
        // built around one prefix.
        expect(Pattern::unpublishable('(*SKIP)a'))->toContain('(*SKIP)')
            ->and(Pattern::unpublishable('(*FAIL)'))->toContain('(*FAIL)')
            ->and(Pattern::unpublishable('a(*PRUNE)b'))->toContain('(*PRUNE)');
    });

    it('refuses escapes that mean different things on each side', function (): void {
        /*
         * ⚠️ I argued for leaving these out, on the grounds that this screens what
         * a consumer cannot COMPILE and all four compile in ECMAScript. That was
         * the wrong test: compiling is not the goal, enforcing the same constraint
         * is. `^\h+$` accepts spaces in PCRE and matches the letter h in
         * ECMAScript, so the published schema advertises a different rule from the
         * one the API applies — worse than a pattern that fails loudly.
         *
         * All four have trivial portable equivalents, so refusing them redirects
         * the author rather than removing a capability. `\p{...}` is the case where
         * that is not true, so the CONSTRUCT is accepted — while its property NAME
         * is screened separately, because an unknown name is a compile failure
         * rather than a difference of meaning.
         */
        expect(Pattern::unpublishable('^\h+$'))->toContain('horizontal whitespace')
            ->and(Pattern::unpublishable('^\H$'))->toContain('non-horizontal')
            ->and(Pattern::unpublishable('^\v$'))->toContain('vertical whitespace')
            ->and(Pattern::unpublishable('^\V$'))->toContain('non-vertical')
            // The portable spellings must go through.
            ->and(Pattern::unpublishable('^[ \t]+$'))->toBeNull()
            ->and(Pattern::unpublishable('^\n$'))->toBeNull()
            // And a Unicode property is accepted deliberately: refusing it would
            // remove the capability rather than redirect it.
            ->and(Pattern::unpublishable('^\p{L}+$'))->toBeNull()
            ->and(Pattern::unpublishable('^\p{Lu}{2,}$'))->toBeNull();
    });

    it('screens divergent escapes inside a character class too', function (): void {
        /*
         * ⚠️ The class exemption was right for anchors and wrong for these, and
         * lumping them into one list was wrong in one direction or the other.
         *
         * `\A` inside `[...]` is a literal A in PCRE, so screening it there would
         * refuse a valid class. `\h` inside a class is STILL horizontal whitespace
         * in PCRE while ECMAScript still reads the letter h — so `[\h]+` published
         * a materially different constraint and the exemption let it through. The
         * difference is whether the escape means anything inside a class at all.
         */
        expect(Pattern::unpublishable('[\h]+'))->toContain('horizontal whitespace')
            ->and(Pattern::unpublishable('[\v]'))->toContain('vertical whitespace')
            ->and(Pattern::unpublishable('[\H\V]'))->not->toBeNull()
            // A literal inside a class, and it must stay allowed.
            ->and(Pattern::unpublishable('[\A]'))->toBeNull()
            ->and(Pattern::unpublishable('[0-9\-]+'))->toBeNull()
            ->and(Pattern::unpublishable('[ \t]+'))->toBeNull();
    });

    it('refuses the Unicode shorthands, measured on both engines', function (): void {
        /*
         * ⚠️ `\d` and `\w` are the most commonly written escapes of all, and the
         * worst offenders. MEASURED rather than assumed, on PCRE 10.48 under the
         * `u` modifier `delimit()` adds and on Node's ECMAScript with `u`:
         *
         *   \d on Arabic-Indic ١٢   PCRE matches, ECMAScript does not
         *   \w on Cyrillic аб       PCRE matches, ECMAScript does not
         *
         * PHP's `u` sets PCRE2_UCP as well as UTF, so `\d` becomes "any Unicode
         * digit" while ECMAScript's stays exactly [0-9]. A field published as
         * `^\d+$` accepts ١٢ through the API and rejects it in every client.
         *
         * `\s` and `\S` are NOT refused — the same measurement found them
         * agreeing on NBSP and ideographic space, so there is nothing to refuse.
         */
        expect(Pattern::unpublishable('^\d+$'))->toContain('any Unicode digit')
            ->and(Pattern::unpublishable('^\w+$'))->toContain('Unicode letters')
            ->and(Pattern::unpublishable('^\D$'))->not->toBeNull()
            ->and(Pattern::unpublishable('^\W$'))->not->toBeNull()
            // Inside a class too, where they still diverge.
            ->and(Pattern::unpublishable('[\d]'))->not->toBeNull()
            // The portable spellings, and the classes that agree.
            ->and(Pattern::unpublishable('^[0-9]+$'))->toBeNull()
            ->and(Pattern::unpublishable('^[A-Za-z0-9_]+$'))->toBeNull()
            ->and(Pattern::unpublishable('^\s+$'))->toBeNull()
            ->and(Pattern::unpublishable('^\S+$'))->toBeNull();
    });

    it('refuses word boundaries, which agree only on ASCII', function (): void {
        /*
         * ⚠️ I argued for RECORDING these rather than refusing them — a word
         * boundary has no portable spelling, so refusing it would remove a
         * capability rather than redirect it, which is the trade this file settled.
         *
         * MEASURING it is what showed the argument up. The ASCII definition written
         * out as lookarounds agrees with ECMAScript's `\b` on both engines across
         * all 108 pattern/input combinations tried, so a portable equivalent does
         * exist and the settled rule says refuse. The last assertion here is the
         * one that makes the refusal honest: the replacement the message names has
         * to pass this same screen, or the author is sent to a second dead end.
         *
         * The divergence is the worst in the file — the engines give OPPOSITE
         * answers, because `\b` is defined in terms of `\w` and PHP's `u` modifier
         * sets PCRE2_UCP:
         *
         *   ^\b.*\b$ on Cyrillic аб   PCRE matches, ECMAScript does not
         *   ^\B.*\B$ on the same      ECMAScript matches, PCRE does not
         */
        expect(Pattern::unpublishable('^\b[A-Za-z]+\b$'))->toContain('Unicode word boundary')
            ->and(Pattern::unpublishable('^\B$'))->toContain('negation of a boundary')
            // ⚠️ INSIDE a class `\b` is the backspace character in both engines —
            // measured, identical — so screening it there would refuse a valid
            // class, which is the mistake the `\A` exemption exists to avoid.
            ->and(Pattern::unpublishable('^[\b]$'))->toBeNull()
            ->and(Pattern::unpublishable('^[A-Za-z\b]+$'))->toBeNull()
            // And the spelling the refusal points at must itself go through.
            ->and(Pattern::unpublishable(
                '^(?:(?<![A-Za-z0-9_])(?=[A-Za-z0-9_])|(?<=[A-Za-z0-9_])(?![A-Za-z0-9_]))[A-Za-z]+$'
            ))->toBeNull();
    });

    it('screens the property NAME, not only the \p{} syntax', function (): void {
        /*
         * ⚠️ `\p{...}` was accepted whole, and the name inside it was never read.
         *
         * MEASURED on PHP 8.4.25/PCRE 10.48 and Node v22.23.2: PCRE compiles and
         * ECMAScript REJECTS every form asserted below. The first draft of this
         * screen was a denylist of six PCRE inventions, and it was wrong in the
         * way that matters — `\p{Arabic}`, `\p{Han}` and `\p{Hebrew}` went
         * straight through, and a bare script name is exactly what an author
         * reaching for a Unicode property in a product that ships RTL from the
         * start (ADR-018) writes first.
         *
         * So the portable set is allowlisted instead, as the group prefixes are.
         * ~170 script names cannot be enumerated to refuse them, and a list that
         * tried would go stale on every Unicode release; enumerating what is
         * PORTABLE goes stale in the direction that refuses rather than the
         * direction that publishes a schema no consumer can compile.
         */
        expect(Pattern::unpublishable('^\p{Arabic}+$'))->toContain('bare script name')
            ->and(Pattern::unpublishable('^\p{Han}+$'))->toContain('bare script name')
            // A script CODE is the same mistake in four letters.
            ->and(Pattern::unpublishable('^\p{Latn}+$'))->toContain('bare script name')
            // The five PCRE inventions and the category-with-ampersand form.
            ->and(Pattern::unpublishable('^\p{Xan}+$'))->toContain('does not')
            ->and(Pattern::unpublishable('^\p{Xwd}+$'))->toContain('does not')
            // Not a mis-spelled name but a PCRE construct, and told apart so the
            // refusal does not send its author looking at letter case.
            ->and(Pattern::unpublishable('^\p{L&}$'))->toContain('letters, digits')
            // ⚠️ A property CLASS PCRE has and ECMAScript does not — and the one
            // an author constraining Arabic text would reach for first.
            ->and(Pattern::unpublishable('^\p{bc=AL}+$'))->toContain('Script=')
            ->and(Pattern::unpublishable('^\p{Bidi_Class=L}$'))->toContain('Script=')
            // PCRE's internal negation, which ECMAScript spells with \P.
            ->and(Pattern::unpublishable('^\p{^L}+$'))->toContain('no internal negation')
            // PCRE matches names loosely; ECMAScript is exact.
            ->and(Pattern::unpublishable('^\p{lu}+$'))->toContain('loosely')
            ->and(Pattern::unpublishable('^\p{LATIN}$'))->toContain('loosely')
            ->and(Pattern::unpublishable('^\p{Script=latin}$'))->toContain('loosely')
            ->and(Pattern::unpublishable('^\p{Script=Old-Italic}$'))->toContain('loosely')
            ->and(Pattern::unpublishable('^\p{Script = Latin}$'))->toContain('loosely')
            // ⚠️ And the braceless shorthand, which is not shorthand ECMAScript
            // shares: it throws `Invalid property name` there.
            ->and(Pattern::unpublishable('^\pL+$'))->toContain('without braces')
            ->and(Pattern::unpublishable('^\PL+$'))->toContain('without braces');
    });

    it('keeps every property form both dialects do share', function (): void {
        // The other half, and the half a denylist gets right by accident: a screen
        // that refused the portable spellings would remove the capability this
        // whole trade-off was protecting.
        expect(Pattern::unpublishable('^\p{L}+$'))->toBeNull()
            ->and(Pattern::unpublishable('^\p{Lu}{2}$'))->toBeNull()
            ->and(Pattern::unpublishable('^\P{Nd}+$'))->toBeNull()
            ->and(Pattern::unpublishable('^\p{Script=Arabic}+$'))->toBeNull()
            ->and(Pattern::unpublishable('^\p{sc=Latn}+$'))->toBeNull()
            ->and(Pattern::unpublishable('^\p{scx=Hebr}+$'))->toBeNull()
            ->and(Pattern::unpublishable('^\p{Script_Extensions=Greek}$'))->toBeNull()
            // Multi-word and internal-capital script names, which the shape test
            // has to keep accepting.
            ->and(Pattern::unpublishable('^\p{Script=Old_Italic}$'))->toBeNull()
            ->and(Pattern::unpublishable('^\p{Script=SignWriting}$'))->toBeNull()
            ->and(Pattern::unpublishable('^\p{Script=Nyiakeng_Puachue_Hmong}$'))->toBeNull()
            // Binary properties, spelled as Unicode spells them.
            ->and(Pattern::unpublishable('^\p{Alphabetic}+$'))->toBeNull()
            ->and(Pattern::unpublishable('^\p{White_Space}$'))->toBeNull()
            ->and(Pattern::unpublishable('^\p{ASCII}+$'))->toBeNull()
            // Inside a character class the answer is the same, because the
            // construct means the same thing in both engines there.
            ->and(Pattern::unpublishable('[\p{L}\p{Nd}]+'))->toBeNull()
            ->and(Pattern::unpublishable('[\p{Arabic}]+'))->toContain('bare script name');
    });

    it('names the spelling that works rather than only refusing', function (): void {
        // ⚠️ A refusal that hands the author a second broken pattern is worse than
        // one that says less. `\p{LATIN}` was answered with "use `\p{Script=LATIN}`"
        // until the shape test learned that consecutive capitals are never a
        // canonical script value — so the suggestion is only made where it holds.
        expect(Pattern::unpublishable('^\p{Arabic}$'))->toContain('`\p{Script=Arabic}`')
            ->and(Pattern::unpublishable('^\p{^Nd}$'))->toContain('`\P{Nd}`')
            ->and(Pattern::unpublishable('^\P{^Nd}$'))->toContain('`\p{Nd}`')
            ->and(Pattern::unpublishable('^\p{LATIN}$'))->not->toContain('Script=LATIN');
    });

    it('agrees with ECMAScript about every name it allows', function (): void {
        /*
         * ⚠️ The allowlists were DERIVED by measurement, so they are guarded by
         * measurement — invariant 15. Reasoning about the two dialects' property
         * tables is what produced the wrong answer twice: `Assigned` is in
         * ECMAScript and PCRE rejects it, `Greek` is in PCRE and ECMAScript
         * rejects it, and neither is guessable from the specifications.
         *
         * This asserts the whole set in both directions at once, so a typo, a
         * PCRE release dropping a name, or a V8 release tightening one fails here
         * rather than in a consumer's generated client.
         *
         * Skipped rather than failed without Node, because the default suite has
         * to run on a bare clone (invariant 11). CI has it — Playwright needs it.
         */
        $pattern = new ReflectionClass(Pattern::class);

        /** @var list<string> $names */
        $names = [
            ...$pattern->getConstant('PORTABLE_CATEGORIES'),
            ...$pattern->getConstant('PORTABLE_PROPERTIES'),
        ];

        // Every prefix, against a value both engines certainly know.
        foreach ($pattern->getConstant('PORTABLE_PROPERTY_PREFIXES') as $prefix) {
            $names[] = $prefix.'=Latin';
        }

        // PCRE first, in the same `u` mode `delimit()` adds.
        $pcreRefuses = array_values(array_filter(
            $names,
            fn (string $name): bool => @preg_match('/\p{'.$name.'}/u', '') === false,
        ));

        expect($pcreRefuses)->toBe([]);

        // Then the strict engine, which is the one the schema is published to.
        $script = 'const names = JSON.parse(process.argv[1]);'
            .'console.log(JSON.stringify(names.filter(n => {'
            .'  try { new RegExp("\\\\p{" + n + "}", "u"); return false } catch { return true }'
            .'})));';

        exec(
            'node -e '.escapeshellarg($script).' '.escapeshellarg((string) json_encode($names)).' 2>/dev/null',
            $output,
            $status,
        );

        expect($status)->toBe(0)
            ->and(json_decode(implode('', $output), true))->toBe([]);

        // And the screen itself accepts what both engines accepted, which is the
        // property the two lists exist to provide.
        foreach ($names as $name) {
            expect(Pattern::unpublishable('^\p{'.$name.'}+$'))->toBeNull();
        }

        /*
         * ⚠️ THE OTHER DIRECTION, which the assertions above cannot see.
         *
         * Everything so far asks "is what we allow portable?" — and a name MISSING
         * from the lists passes that trivially. `LC`, the Cased_Letter group,
         * compiles on both engines and was absent, so `\p{LC}` was refused for no
         * reason. That is the cost this allowlist knowingly trades for, and it is
         * only a defensible trade if something notices.
         *
         * So: over a corpus that deliberately includes names NOT in the lists, any
         * name both engines accept must be one the screen accepts too. It is not
         * every property name in Unicode — nothing here could be — but it covers
         * the group categories, the long forms, and the aliases an author reaches
         * for, which is where an omission actually costs someone.
         */
        $corpus = [
            // Category groups and the two-letter group, the LC omission's family.
            'C', 'L', 'LC', 'M', 'N', 'P', 'S', 'Z',
            // Long forms, which PCRE rejects — so both-accept is false and the
            // screen is free to refuse them.
            'Letter', 'Cased_Letter', 'Uppercase_Letter', 'Decimal_Number', 'Punctuation',
            // Binary properties in and out of the list.
            'Alphabetic', 'ASCII', 'White_Space', 'Assigned', 'Changes_When_NFKC_Casefolded',
            'Emoji', 'Math', 'Any', 'Bidi_Mirrored', 'Grapheme_Base',
            // Prefixed forms, including the prefixes ECMAScript lacks.
            'Script=Latin', 'sc=Latn', 'scx=Hebr', 'Script_Extensions=Greek',
            'General_Category=Lu', 'gc=Lu', 'bc=AL', 'Block=Greek',
            // And the PCRE-only spellings, which must stay refused.
            'Xan', 'Xwd', 'L&', 'Arabic', 'lu',
        ];

        $pcreTakes = array_values(array_filter(
            $corpus,
            fn (string $name): bool => @preg_match('/\p{'.$name.'}/u', '') !== false,
        ));

        $script = 'const names = JSON.parse(process.argv[1]);'
            .'console.log(JSON.stringify(names.filter(n => {'
            .'  try { new RegExp("\\\\p{" + n + "}", "u"); return true } catch { return false }'
            .'})));';

        exec(
            'node -e '.escapeshellarg($script).' '.escapeshellarg((string) json_encode($pcreTakes)).' 2>/dev/null',
            $bothTake,
            $bothStatus,
        );

        expect($bothStatus)->toBe(0);

        /** @var list<string> $portable */
        $portable = json_decode(implode('', $bothTake), true);

        // Non-empty, or the assertion below would pass by testing nothing.
        expect($portable)->not->toBe([]);

        $falselyRefused = array_values(array_filter(
            $portable,
            fn (string $name): bool => Pattern::unpublishable('^\p{'.$name.'}+$') !== null,
        ));

        expect($falselyRefused)->toBe([]);
    })->skip(function (): bool {
        exec('command -v node', $found, $status);

        return $status !== 0;
    }, 'node is not installed, so ECMAScript cannot be measured');

    it('allowlists group prefixes rather than listing offenders', function (): void {
        // The point of the allowlist: a construct nobody anticipated is refused
        // too, which a list of known offenders cannot do. Same reasoning as the
        // rich-text sanitiser allowlisting tags.
        expect(Pattern::unpublishable('(?~x)'))->toContain('(?~x)')
            ->and(Pattern::unpublishable('(?J)a'))->toContain('(?J)');
    });
});

describe('a field holding data cannot just be detached', function (): void {
    /*
     * ⚠️ Deleting a `Field` removed the configuration and left the DATA.
     *
     * Inline JSON keys and `entry_relations` rows survive against the shared
     * FieldStorage row — and become unreachable, because `Entry::redactField()`
     * resolves storage through `whereHas('fields')` on the entry type. Once the
     * field row is gone the lookup finds nothing, falls through to the inline
     * path, and reports 0 while a relation holding personal data survives. An
     * erasure request would be answered successfully and truthfully report that
     * it reached nothing (ADR-020).
     */
    $fieldOfType = function (string $handle, string $type): Field {
        $storage = FieldStorage::create([
            'org_id' => test()->org->id, 'handle' => $handle, 'type' => $type,
            'pii_class' => 'personal', 'cardinality' => $type === 'relation' ? -1 : 1,
        ]);

        return Field::create([
            'entry_type_id' => test()->entryType->id,
            'field_storage_id' => $storage->id,
            'label' => ucfirst($handle),
        ]);
    };

    beforeEach(function (): void {
        $this->entryType = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'record', 'name' => 'R', 'plural_name' => 'Rs',
        ]);
    });

    it('refuses while an INLINE field holds a value', function () use ($fieldOfType): void {
        $field = $fieldOfType('notes', 'text');

        Entry::create([
            'entry_type_id' => $this->entryType->id, 'title' => 'Has notes',
            'values' => ['notes' => 'Jane Doe, 12 Elm St'],
        ]);

        expect(fn () => $field->delete())
            ->toThrow(RuntimeException::class, 'still hold data for it');

        expect(Field::query()->whereKey($field->getKey())->exists())->toBeTrue();
    });

    it('refuses while a RELATIONAL field holds links', function () use ($fieldOfType): void {
        // ⚠️ The strategy that made this an erasure defect rather than a tidiness
        // one: a relational field's data is rows, and they are what survives.
        $field = $fieldOfType('patient', 'relation');

        $source = Entry::create(['entry_type_id' => $this->entryType->id, 'title' => 'Visit']);
        $target = Entry::create(['entry_type_id' => $this->entryType->id, 'title' => 'Alice']);
        $source->related()->attach($target->id, ['field_storage_id' => $field->field_storage_id]);

        expect(fn () => $field->delete())
            ->toThrow(RuntimeException::class, 'still hold data for it');
    });

    it('refuses while a SOFT-DELETED entry holds a value', function () use ($fieldOfType): void {
        // Trashed data still exists and erasure still has to reach it, so it is
        // a reason to refuse.
        $field = $fieldOfType('notes2', 'text');

        $entry = Entry::create([
            'entry_type_id' => $this->entryType->id, 'title' => 'Trashed',
            'values' => ['notes2' => 'personal'],
        ]);
        $entry->delete();

        expect(fn () => $field->delete())
            ->toThrow(RuntimeException::class, 'still hold data for it');
    });

    it('ALLOWS removing a field nothing holds data for', function () use ($fieldOfType): void {
        $field = $fieldOfType('unused', 'text');

        Entry::create([
            'entry_type_id' => $this->entryType->id, 'title' => 'Other data',
            'values' => ['something_else' => 'x'],
        ]);

        $field->delete();

        expect(Field::query()->whereKey($field->getKey())->exists())->toBeFalse();
    });

    it('ALLOWS it once the field has been erased', function () use ($fieldOfType): void {
        // The message names this route, so it has to work: erase first, which is
        // audited, then remove.
        $field = $fieldOfType('notes3', 'text');

        $entry = Entry::create([
            'entry_type_id' => $this->entryType->id, 'title' => 'Erase me',
            'values' => ['notes3' => 'Jane Doe'],
        ]);

        $entry->redactField('notes3');

        // ⚠️ Erasure leaves the KEY present with a null value, which still counts
        // as holding data — so it has to be removed outright before the field can
        // go. Stated here because the difference matters to an operator following
        // the message.
        $entry->update(['values' => []]);

        $field->delete();

        expect(Field::query()->whereKey($field->getKey())->exists())->toBeFalse();
    });
});

describe('the field-deletion guard holds on every path', function (): void {
    /*
     * ⚠️ The refusal started as a `deleting` event, which is ONE path.
     * `Field::query()->delete()`, `deleteQuietly()` and anything inside
     * `withoutEvents()` dispatch straight past it — the seventh time this project
     * has found that shape. `Field` now implements `RefusesCascadingDeletes`, so
     * `ScopedBuilder` runs the same rule for every row a bulk delete would
     * remove, under a lock.
     */
    beforeEach(function (): void {
        $this->holder = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'holder', 'name' => 'H', 'plural_name' => 'Hs',
        ]);

        $storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'secret', 'type' => 'text',
            'pii_class' => 'personal', 'cardinality' => 1,
        ]);

        $this->guarded = Field::create([
            'entry_type_id' => $this->holder->id, 'field_storage_id' => $storage->id, 'label' => 'Secret',
        ]);

        Entry::create([
            'entry_type_id' => $this->holder->id, 'title' => 'Holds it',
            'values' => ['secret' => 'Jane Doe'],
        ]);
    });

    it('refuses a BULK delete, which dispatched no event', function (): void {
        expect(fn () => Field::query()->whereKey($this->guarded->getKey())->delete())
            ->toThrow(RuntimeException::class, 'still hold data for it');

        expect(Field::query()->whereKey($this->guarded->getKey())->exists())->toBeTrue();
    });

    it('refuses a QUIET delete, which suppresses the event', function (): void {
        expect(fn () => $this->guarded->deleteQuietly())
            ->toThrow(RuntimeException::class, 'still hold data for it');

        expect(Field::query()->whereKey($this->guarded->getKey())->exists())->toBeTrue();
    });

    it('still refuses the ordinary instance delete', function (): void {
        expect(fn () => $this->guarded->delete())
            ->toThrow(RuntimeException::class, 'still hold data for it');
    });
});

describe('a field whose data survives only in history cannot be deleted', function (): void {
    /*
     * ⚠️ Checking the live row alone was a hole big enough to lose personal data
     * through: clearing a value and then removing the field left the old value in
     * every revision snapshot — and removing the field removes the schema metadata
     * needed to FIND it, so only a caller who already knew the deleted handle
     * could reach it again. ADR-020 seen from the deletion side.
     */
    it('refuses while a REVISION still holds the value', function (): void {
        $type = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'historic', 'name' => 'H', 'plural_name' => 'Hs',
        ]);
        $storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'old_note', 'type' => 'text',
            'pii_class' => 'personal', 'cardinality' => 1,
        ]);
        $field = Field::create([
            'entry_type_id' => $type->id, 'field_storage_id' => $storage->id, 'label' => 'Note',
        ]);

        $entry = Entry::create([
            'entry_type_id' => $type->id, 'title' => 'Had a note',
            'values' => ['old_note' => 'Jane Doe, 12 Elm St'],
        ]);

        // ⚠️ The revision is written by hand, because nothing on THIS branch
        // records one — the recorder is the revisions-and-drafts work. The table
        // and the model are here, so the guard is testable directly, and testing
        // it here is the point: the guard has to hold whatever put the row there.
        EntryRevision::create([
            'entry_id' => $entry->getKey(),
            'values' => ['old_note' => 'Jane Doe, 12 Elm St'],
        ]);

        // The live value is gone; the version that recorded it is not.
        $entry->update(['values' => []]);

        expect($entry->fresh()->values)->toBe([]);
        expect(fn () => $field->delete())
            ->toThrow(RuntimeException::class, 'still hold data for it');
    });

    it('ALLOWS it once the value is erased from history too', function (): void {
        // `redactField()` sweeps the entry AND its revisions, which is exactly
        // the route the refusal recommends.
        $type = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'historic2', 'name' => 'H', 'plural_name' => 'Hs',
        ]);
        $storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'gone_note', 'type' => 'text',
            'pii_class' => 'personal', 'cardinality' => 1,
        ]);
        $field = Field::create([
            'entry_type_id' => $type->id, 'field_storage_id' => $storage->id, 'label' => 'Note',
        ]);

        $entry = Entry::create([
            'entry_type_id' => $type->id, 'title' => 'Erase me',
            'values' => ['gone_note' => 'Jane Doe'],
        ]);
        EntryRevision::create([
            'entry_id' => $entry->getKey(),
            'values' => ['gone_note' => 'Jane Doe'],
        ]);

        // `redactField()` sweeps the entry AND its revisions, which is what makes
        // the route the refusal recommends actually work.
        $entry->redactField('gone_note');
        $entry->update(['values' => []]);

        $field->delete();

        expect(Field::query()->whereKey($field->getKey())->exists())->toBeFalse();
    });
});

describe('the cascade guard sees the whole row, whatever the caller selected', function (): void {
    /*
     * ⚠️ `get()` inherits the caller's projection, so
     * `Field::query()->select('id')->delete()` handed the guard a model with no
     * `field_storage_id` — and a DELETE ignores a SELECT list, so the row went
     * and its data stranded. The same failure as the key-only instance, arriving
     * through the caller instead of the machinery.
     */
    it('refuses a delete whose query selected only the key', function (): void {
        $type = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'projected', 'name' => 'P', 'plural_name' => 'Ps',
        ]);
        $storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'kept', 'type' => 'text',
            'pii_class' => 'personal', 'cardinality' => 1,
        ]);
        $field = Field::create([
            'entry_type_id' => $type->id, 'field_storage_id' => $storage->id, 'label' => 'Kept',
        ]);

        Entry::create([
            'entry_type_id' => $type->id, 'title' => 'Holds it', 'values' => ['kept' => 'Jane Doe'],
        ]);

        expect(fn () => Field::query()->select('id')->whereKey($field->getKey())->delete())
            ->toThrow(RuntimeException::class, 'still hold data for it');

        expect(Field::query()->whereKey($field->getKey())->exists())->toBeTrue();
    });

    it('ALLOWS a projected delete when nothing holds data', function (): void {
        // The projection must not become a reason to refuse either.
        $type = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'projected2', 'name' => 'P', 'plural_name' => 'Ps',
        ]);
        $storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'spare', 'type' => 'text',
            'pii_class' => 'none', 'cardinality' => 1,
        ]);
        $field = Field::create([
            'entry_type_id' => $type->id, 'field_storage_id' => $storage->id, 'label' => 'Spare',
        ]);

        Field::query()->select('id')->whereKey($field->getKey())->delete();

        expect(Field::query()->whereKey($field->getKey())->exists())->toBeFalse();
    });

    it('lets an unused PROMOTED field be removed', function (): void {
        // ⚠️ Adding a revision check for promoted columns made even an unused
        // promoted field unremovable: `entry_revisions` holds `values` and
        // revision metadata, and no promoted columns — so the query was a
        // missing-column error rather than a refusal.
        $type = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'promoted_type', 'name' => 'P', 'plural_name' => 'Ps',
        ]);
        $storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'public_slug', 'type' => 'slug',
            'pii_class' => 'none', 'cardinality' => 1,
        ]);
        $field = Field::create([
            'entry_type_id' => $type->id, 'field_storage_id' => $storage->id, 'label' => 'Slug',
        ]);

        $field->delete();

        expect(Field::query()->whereKey($field->getKey())->exists())->toBeFalse();
    });

    it('still refuses a promoted field whose column holds a value', function (): void {
        $type = EntryType::create([
            'org_id' => $this->org->id, 'handle' => 'promoted_type2', 'name' => 'P', 'plural_name' => 'Ps',
        ]);
        $storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'public_slug2', 'type' => 'slug',
            'pii_class' => 'personal', 'cardinality' => 1,
        ]);
        $field = Field::create([
            'entry_type_id' => $type->id, 'field_storage_id' => $storage->id, 'label' => 'Slug',
        ]);

        Entry::create(['entry_type_id' => $type->id, 'title' => 'Has a slug', 'slug' => 'jane-doe']);

        expect(fn () => $field->delete())
            ->toThrow(RuntimeException::class, 'still hold data for it');
    });
});
