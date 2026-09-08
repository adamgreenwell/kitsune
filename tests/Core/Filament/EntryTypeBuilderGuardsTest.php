<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Kitsune\Core\Filament\Resources\EntryTypes\EntryTypeResource;
use Kitsune\Core\Filament\Resources\EntryTypes\RelationManagers\FieldsRelationManager;
use Kitsune\Core\Models\EntryType;
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
