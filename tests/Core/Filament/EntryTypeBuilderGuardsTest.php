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
        ]))->toThrow(RuntimeException::class, 'not a regular expression that can be compiled');
    });

    it('refuses a pattern that cannot be delimited at all', function (): void {
        // Every candidate delimiter appears in the pattern, so there is nothing
        // left to wrap it in — refused rather than silently mangled.
        expect(fn () => FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'code2', 'type' => 'text',
            'pii_class' => 'none', 'cardinality' => 1,
            'settings' => ['pattern' => 'a/b#c~d%e!f'],
        ]))->toThrow(RuntimeException::class, 'not a regular expression that can be compiled');
    });

    it('accepts a valid pattern, and an absent one', function (): void {
        $constrained = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'code3', 'type' => 'text',
            'pii_class' => 'none', 'cardinality' => 1,
            'settings' => ['pattern' => '^[A-Z]{2}-\d+$'],
        ]);
        $unconstrained = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'code4', 'type' => 'text',
            'pii_class' => 'none', 'cardinality' => 1, 'settings' => ['maxLength' => 20],
        ]);

        expect($constrained->settings['pattern'])->toBe('^[A-Z]{2}-\d+$')
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
        expect(Pattern::compiles('^[A-Z]{2}-\d+$'))->toBeTrue()
            ->and(Pattern::compiles('^[A-Z'))->toBeFalse()
            ->and(Pattern::compiles('a/b#c~d%e!f'))->toBeFalse()
            ->and(Pattern::delimit('^[a-z]+$'))->toBe('/^[a-z]+$/u')
            // The first delimiter the pattern does not itself contain.
            ->and(Pattern::delimit('a/b'))->toBe('#a/b#u');
    });
});
