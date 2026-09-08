<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Kitsune\Core\Filament\Resources\EntryTypes\EntryTypeResource;
use Kitsune\Core\Filament\Resources\EntryTypes\Pages\EditEntryType;
use Kitsune\Core\Filament\Resources\EntryTypes\RelationManagers\FieldsRelationManager;
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

    it('ADOPTS a matching one without rewriting its classification', function (): void {
        $existing = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => 'email', 'type' => 'text',
            'pii_class' => 'personal', 'cardinality' => 1,
            'settings' => ['maxLength' => 320],
        ]);

        $manager = new FieldsRelationManager;

        $manager->writeStorage([
            'storage_handle' => 'email', 'storage_type' => 'text',
            // The form says `none` and 40 characters; the shared row says
            // otherwise, and the shared row wins.
            'storage_pii_class' => 'none', 'storage_settings' => ['maxLength' => 40],
            'storage_is_indexed' => true, 'label' => 'Email',
        ]);

        $fresh = $existing->fresh();

        expect($fresh->pii_class)->toBe('personal')
            ->and($fresh->settings['maxLength'])->toBe(320)
            ->and($fresh->is_indexed)->toBeFalse();
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
