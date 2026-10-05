<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Auth\EntryPolicy;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Blueprints\BlueprintRegistry;
use Kitsune\Core\Blueprints\FirstParty\BlogBlueprint;
use Kitsune\Core\Blueprints\FirstParty\DamBlueprint;
use Kitsune\Core\Blueprints\FirstParty\MarketingSiteBlueprint;
use Kitsune\Core\Http\Controllers\MediaDownloadController;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\MediaFile;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\RolePermission;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Schema\SchemaManager;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\DamAtAnotherVersion;
use Kitsune\Core\Tests\Fixtures\SchemaManagerStandIn;
use Kitsune\Core\Tests\Fixtures\TestUser;

/*
 * The DAM — the third first-party blueprint (Phase 5, ADR-039, the DAM as built), as it ships.
 *
 * ⚠️ GOLDEN, ON PURPOSE, for Blog's reason: once 1.0.0 is applied in a real org, every column below is what that org
 * has, and the receipt says 1.0.0 — so a change here that keeps the version is a change no applied org will ever
 * receive, and a new version may only add to it (`ReleasedBlueprintsTest`).
 */

beforeEach(function (): void {
    config(['auth.providers.users.model' => TestUser::class]);
    Storage::fake('public');
    Storage::fake(MediaDisks::PRIVATE);
    SchemaManagerStandIn::install()->recordOnly();

    $this->org = Org::create(['slug' => 'library', 'name' => 'Library']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['org_id' => $this->org->getKey(), 'handle' => 'main', 'slug' => 'library-main', 'name' => 'Main', 'locale' => 'en']);
});

afterEach(function (): void {
    app()->forgetInstance(SchemaManager::class);
    app(Context::class)->forget();

    foreach (glob(sys_get_temp_dir().'/kitsune-dam-*') ?: [] as $leftover) {
        @unlink($leftover);
    }
});

function damAsset(?Org $org = null): EntryType
{
    return EntryType::query()->where('org_id', ($org ?? test()->org)->getKey())->where('handle', 'asset')->firstOrFail();
}

/** A member of the org holding one of the DAM's roles — assigned here, because a blueprint assigns nobody. */
function damHolder(Org $org, string $roleHandle): TestUser
{
    /** @var TestUser $user */
    $user = TestUser::create(['email' => $roleHandle.mt_rand(1, 1_000_000).'@kitsune.test']);
    DB::table('org_user')->insert(['org_id' => $org->getKey(), 'user_id' => $user->getKey()]);
    DB::table('role_user')->insert([
        'role_id' => Role::query()->withoutGlobalScopes()->where('org_id', $org->getKey())->where('handle', $roleHandle)->value('id'),
        'user_id' => $user->getKey(),
    ]);
    Permissions::forget();

    return $user;
}

/** Store a file into the org's `asset`, as an upload at a site does, and leave the context holding the org alone. */
function damStore(string $bytes, string $name, ?Site $site = null, bool $siteOnly = false): Entry
{
    $site ??= test()->site;
    $source = tempnam(sys_get_temp_dir(), 'kitsune-dam-');
    file_put_contents($source, $bytes);
    app(Context::class)->setSite($site);
    $entry = MediaLibrary::store($source, $name, damAsset($site->org), siteOnly: $siteOnly);
    app(Context::class)->forget()->setOrg($site->org);

    return $entry;
}

function damPng(): string
{
    return "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\x0f\0\0\x01\x01\0\x05\x18\xd8N\0\0\0\0IEND\xaeB`\x82";
}

function damPdf(): string
{
    return "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
}

/** D1 */
it('is registered by core at dam 1.0.0, and passes its own pre-flight', function (): void {
    $definition = app(BlueprintRegistry::class)->get('dam');

    expect($definition)->toBeInstanceOf(DamBlueprint::class)
        ->and($definition->version())->toBe('1.0.0');

    BlueprintApplier::refuseMalformed($definition);
});

/** D2 */
it('creates the asset type exactly, as a media type taking every format', function (): void {
    BlueprintApplier::apply(new DamBlueprint);

    expect(damAsset()->only(['org_id', 'handle', 'name', 'plural_name', 'icon', 'description', 'ordering', 'is_media', 'settings', 'subject_field_id']))->toBe([
        'org_id' => $this->org->getKey(),
        'handle' => 'asset',
        'name' => 'Asset',
        'plural_name' => 'Assets',
        'icon' => 'heroicon-o-archive-box',
        'description' => 'Files the organisation keeps, with who holds the rights to each and the licence it is used under.',
        'ordering' => 20,
        'is_media' => true,
        'settings' => null,
        'subject_field_id' => null,
    ])
        ->and((bool) damAsset()->getAttribute('is_system'))->toBeFalse()
        ->and(EntryType::query()->where('org_id', $this->org->getKey())->count())->toBe(1);
});

/** D3 */
it('creates each field exactly, and no other', function (): void {
    BlueprintApplier::apply(new DamBlueprint);

    $fields = Field::query()->where('entry_type_id', damAsset()->getKey())->orderBy('ordering')->get()->map(static fn (Field $field): array => [
        ...FieldStorage::query()->whereKey($field->field_storage_id)->firstOrFail()->only(['handle', 'type', 'pii_class', 'cardinality', 'is_indexed', 'is_locked']),
        'settings' => FieldStorage::query()->whereKey($field->field_storage_id)->value('settings'),
        ...$field->only(['label', 'help_text', 'is_required', 'ordering', 'group']),
    ])->all();

    expect($fields)->toBe([
        [
            'handle' => 'asset_rights_holder', 'type' => 'text', 'pii_class' => 'personal', 'cardinality' => 1, 'is_indexed' => false, 'is_locked' => false, 'settings' => [],
            'label' => 'Rights holder', 'help_text' => 'Who owns the rights to this file: a photographer, an agency, or this organisation.', 'is_required' => false, 'ordering' => 10, 'group' => null,
        ],
        [
            'handle' => 'asset_licence', 'type' => 'textarea', 'pii_class' => 'none', 'cardinality' => 1, 'is_indexed' => false, 'is_locked' => false, 'settings' => [],
            'label' => 'Licence', 'help_text' => 'The licence this file is used under, and anything it rules out — for example "CC BY 4.0", or "editorial use only, not in print".', 'is_required' => false, 'ordering' => 20, 'group' => null,
        ],
        [
            'handle' => 'asset_licence_expires', 'type' => 'date', 'pii_class' => 'none', 'cardinality' => 1, 'is_indexed' => false, 'is_locked' => false, 'settings' => [],
            'label' => 'Licence expires', 'help_text' => 'The last day this file may be used. Leave it empty if the licence does not end. Kitsune does not act on this date: nothing is hidden or withdrawn when it passes.', 'is_required' => false, 'ordering' => 30, 'group' => null,
        ],
    ])
        ->and(FieldStorage::query()->where('org_id', $this->org->getKey())->count())->toBe(3);
});

/** D4 */
it('creates the three roles with exactly their grants, held by nobody', function (): void {
    BlueprintApplier::apply(new DamBlueprint);

    $roles = Role::query()->orderBy('handle')->get()->map(static fn (Role $role): array => [
        'handle' => $role->handle,
        'name' => $role->name,
        'is_owner' => (bool) $role->getRawOriginal('is_owner'),
        'grants' => $role->permissions()->orderBy('permission')->pluck('permission')->all(),
    ])->all();

    expect($roles)->toBe([
        ['handle' => 'dam_contributor', 'name' => 'Asset contributor', 'is_owner' => false, 'grants' => ['entry.asset.create', 'entry.asset.publish', 'entry.asset.update', 'entry.asset.view']],
        ['handle' => 'dam_manager', 'name' => 'Asset manager', 'is_owner' => false, 'grants' => ['entry.asset.create', 'entry.asset.delete', 'entry.asset.publish', 'entry.asset.update', 'entry.asset.view']],
        ['handle' => 'dam_viewer', 'name' => 'Asset viewer', 'is_owner' => false, 'grants' => ['entry.asset.view']],
    ])
        ->and(DB::table('role_user')->count())->toBe(0);
});

/** D5 */
it('reports what it created, indexes nothing, and does nothing when applied again', function (): void {
    $result = BlueprintApplier::apply(new DamBlueprint);

    expect($result['created'])->toBe([
        'entry type asset',
        'field storage asset_rights_holder',
        'field storage asset_licence',
        'field storage asset_licence_expires',
        'role dam_manager: entry.asset.create, entry.asset.delete, entry.asset.publish, entry.asset.update, entry.asset.view',
        'role dam_contributor: entry.asset.create, entry.asset.publish, entry.asset.update, entry.asset.view',
        'role dam_viewer: entry.asset.view',
    ])
        ->and($result['adopted'])->toBe([])
        ->and($result['indexed'])->toBe(0)
        ->and($result['roles_created'])->toBe(['dam_manager', 'dam_contributor', 'dam_viewer'])
        ->and(BlueprintApplier::apply(new DamBlueprint)['skipped'])->toBe(['already applied at this version']);
});

/** D6 — what each role may do with a file, asked of the same policy the admin asks. */
it('lets a manager do everything, a contributor upload and describe but not delete, and a viewer only look', function (): void {
    BlueprintApplier::apply(new DamBlueprint);
    $file = damStore(damPng(), 'pixel.png');
    $policy = new EntryPolicy;
    $manager = damHolder($this->org, 'dam_manager');
    $contributor = damHolder($this->org, 'dam_contributor');
    $viewer = damHolder($this->org, 'dam_viewer');
    /* The policy answers about a row only inside a site, as the admin always is: no site, no row, no yes. */
    app(Context::class)->setSite($this->site);

    expect(Permissions::mayUpload($contributor, 'asset'))->toBeTrue()
        ->and(Permissions::mayStageUploads($contributor))->toBeTrue()
        ->and(Permissions::allows($contributor, 'entry.asset.publish'))->toBeTrue()
        ->and($policy->update($contributor, $file))->toBeTrue()
        ->and($policy->delete($contributor, $file))->toBeFalse()
        ->and($policy->restore($contributor, $file))->toBeFalse()
        ->and($policy->forceDelete($contributor, $file))->toBeFalse()
        ->and($policy->forceDelete($manager, $file))->toBeTrue()
        ->and($policy->view($viewer, $file))->toBeTrue()
        ->and(Permissions::mayUpload($viewer, 'asset'))->toBeFalse()
        ->and(Permissions::mayStageUploads($viewer))->toBeFalse()
        ->and($policy->update($viewer, $file))->toBeFalse();

    /*
     * And a viewer downloads a private file: private means behind sign-in and `view`, not managers only. The route
     * is `MediaDeliveryTest`'s shape — no middleware, and the leading segment the real route has — for its reasons.
     */
    Route::get('/test-media/{tenant}/{media}', MediaDownloadController::class)->where('media', '[0-9]+');
    $stored = MediaFile::query()->where('entry_id', $file->getKey())->firstOrFail();
    $response = $this->actingAs($viewer)->get('/test-media/library/'.$file->getKey());

    expect($stored->visibility)->toBe('private')
        ->and($response->getStatusCode())->toBe(200)
        ->and($response->streamedContent())->toBe(damPng());
});

/** D7 — every format, published, shared and private, with nothing filled in. */
it('takes a picture and a document, each stored shared, private and with every field empty', function (): void {
    BlueprintApplier::apply(new DamBlueprint);

    foreach ([[damPng(), 'pixel.png'], [damPdf(), 'terms.pdf']] as [$bytes, $name]) {
        $entry = damStore($bytes, $name);

        expect($entry->getAttribute('status'))->toBe('published')
            ->and($entry->getAttribute('site_id'))->toBeNull()
            ->and(MediaFile::query()->where('entry_id', $entry->getKey())->value('visibility'))->toBe('private')
            ->and(array_intersect_key((array) $entry->values, array_flip(['asset_rights_holder', 'asset_licence', 'asset_licence_expires'])))->toBe([]);
    }
});

/** D8 — personal, so on the report until its owner nominates the rights holder, and off it after. */
it('puts assets on the subject-identifier report until the rights holder is nominated', function (): void {
    BlueprintApplier::apply(new DamBlueprint);

    expect(EntryType::withoutSubjectIdentifier()->pluck('handle')->all())->toContain('asset');

    $type = damAsset();
    $holder = Field::query()->where('entry_type_id', $type->getKey())->whereIn('field_storage_id', FieldStorage::query()->select('id')->where('handle', 'asset_rights_holder'))->firstOrFail();

    expect($type->subjectShapeRefusal($holder))->toBeNull();

    $type->update(['subject_field_id' => $holder->getKey()]);

    expect(EntryType::withoutSubjectIdentifier()->pluck('handle')->all())->not->toContain('asset');
});

/** D9 — Blog, the Marketing Site and the DAM in one org, in either order: nothing collides, each role reaches its own types. */
it('composes with Blog and the Marketing Site in one org, in either order', function (bool $damFirst): void {
    $definitions = [new BlogBlueprint, new MarketingSiteBlueprint];
    $definitions = $damFirst ? [new DamBlueprint, ...$definitions] : [...$definitions, new DamBlueprint];

    foreach ($definitions as $definition) {
        expect(BlueprintApplier::apply($definition)['adopted'])->toBe([]);
    }

    expect(EntryType::query()->where('org_id', $this->org->getKey())->orderBy('handle')->pluck('handle')->all())->toBe(['asset', 'page', 'post', 'tag'])
        ->and(FieldStorage::query()->where('org_id', $this->org->getKey())->count())->toBe(10)
        ->and(Role::query()->count())->toBe(7)
        ->and(RolePermission::query()->withoutGlobalScopes()->count())->toBe(32)
        ->and(Blueprint::query()->whereNotNull('applied_at')->orderBy('handle')->pluck('handle')->all())->toBe(['blog', 'dam', 'marketing-site']);

    foreach (['dam_manager' => ['post', 'tag', 'page'], 'blog_editor' => ['asset'], 'marketing_editor' => ['asset']] as $role => $types) {
        $holder = damHolder($this->org, $role);

        foreach ($types as $type) {
            foreach (Permissions::ACTIONS as $action) {
                expect(Permissions::allows($holder, Permissions::forEntryType($type, $action)))->toBeFalse("{$role} {$type} {$action}");
            }
        }
    }
})->with(['the DAM first' => true, 'the DAM last' => false]);

/** D10 — the demo's global Images beside it: no collision, and no grant on it. */
it('applies beside a global image type, granting nothing on it', function (): void {
    EntryType::create(['handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);

    expect(BlueprintApplier::apply(new DamBlueprint)['adopted'])->toBe([])
        ->and(RolePermission::query()->withoutGlobalScopes()->where('permission', 'like', 'entry.image.%')->exists())->toBeFalse();
});

/** D11 */
it('refuses an asset type the organisation already has, or a global one, writing nothing', function (?bool $theirs): void {
    EntryType::create(['org_id' => $theirs === null ? null : $this->org->getKey(), 'handle' => 'asset', 'name' => 'Theirs', 'plural_name' => 'Theirs', 'is_media' => $theirs ?? true]);

    expect(fn () => BlueprintApplier::apply(new DamBlueprint))->toThrow(RuntimeException::class, $theirs === null
        ? 'Entry type [asset] is a global type'
        : 'Entry type [asset] already exists in this organisation, and this blueprint declares it with onCollision: fail');

    expect(FieldStorage::query()->exists())->toBeFalse()
        ->and(Role::query()->exists())->toBeFalse();
})->with(['theirs, media' => true, 'theirs, ordinary' => false, 'a global one' => null]);

/** D12 — from the side of the one crossing: another org's contributor reaches nothing here, and a file kept to one site is that site's. */
it('keeps one organisation\'s library from another, and a site\'s own file from its other sites', function (): void {
    BlueprintApplier::apply(new DamBlueprint);
    $rival = Org::create(['slug' => 'rival', 'name' => 'Rival']);
    app(Context::class)->setOrg($rival);
    BlueprintApplier::apply(new DamBlueprint);
    $theirs = damHolder($rival, 'dam_contributor');
    $rivalSite = Site::create(['org_id' => $rival->getKey(), 'handle' => 'main', 'slug' => 'rival-main', 'name' => 'Main', 'locale' => 'en']);
    $theirFile = damStore(damPng(), 'theirs.png', $rivalSite);

    app(Context::class)->forget()->setOrg($this->org);

    expect(Permissions::allows($theirs, 'entry.asset.view'))->toBeFalse()
        ->and(Permissions::mayUpload($theirs, 'asset'))->toBeFalse();

    /* Their type, named from here: the store refuses it rather than file into another org. */
    $source = tempnam(sys_get_temp_dir(), 'kitsune-dam-');
    file_put_contents($source, damPng());
    app(Context::class)->setSite($this->site);

    expect(fn () => MediaLibrary::store($source, 'crossing.png', damAsset($rival)))->toThrow(RuntimeException::class, 'that type belongs to org '.$rival->getKey().' and this entry is in org '.$this->org->getKey())
        ->and(Entry::query()->whereKey($theirFile->getKey())->exists())->toBeFalse();

    /* Across sites of one org: shared is everywhere, kept to a site is that site's alone. */
    app(Context::class)->forget()->setOrg($this->org);
    $other = Site::create(['org_id' => $this->org->getKey(), 'handle' => 'other', 'slug' => 'library-other', 'name' => 'Other', 'locale' => 'en']);
    $shared = damStore(damPng(), 'shared.png');
    $kept = damStore(damPng(), 'kept.png', siteOnly: true);
    app(Context::class)->setSite($other);

    expect(Entry::query()->pluck('id')->all())->toContain($shared->getKey())
        ->not->toContain($kept->getKey());
});

/** D13 — a later version may add a field to the media type, and may never make it ordinary. */
it('merges a version adding a field to assets, and refuses one making them ordinary', function (): void {
    BlueprintApplier::apply(new DamBlueprint);

    expect(fn () => BlueprintApplier::apply(new DamAtAnotherVersion(media: false)))->toThrow(RuntimeException::class, 'entry type asset changes its is_media');

    $result = BlueprintApplier::apply(new DamAtAnotherVersion);

    expect($result['created'])->toBe(['field storage asset_credit'])
        ->and(Field::query()->where('entry_type_id', damAsset()->getKey())->count())->toBe(4)
        ->and(damAsset()->is_media)->toBeTrue();
});

/** D14 — files block the reverse, the trash included; once deleted forever, it goes, keeping storage a file once filled. */
it('refuses to reverse while a file remains, and reverses once it is deleted forever', function (): void {
    BlueprintApplier::apply(new DamBlueprint);
    $file = damStore(damPng(), 'credited.png');
    app(Context::class)->setSite($this->site);
    $file->update(['values' => ['asset_rights_holder' => 'Jane Doe']]);
    $path = MediaFile::query()->where('entry_id', $file->getKey())->value('path');
    app(Context::class)->forget()->setOrg($this->org);

    expect(fn () => BlueprintApplier::reverse('dam'))->toThrow(RuntimeException::class,
        'entry type asset still has 1 entry — delete it, then empty the trash with Delete forever'
    );

    app(Context::class)->setSite($this->site);
    $file->fresh()->delete();
    Entry::withTrashed()->findOrFail($file->getKey())->forceDelete();
    app(Context::class)->forget()->setOrg($this->org);

    $result = BlueprintApplier::reverse('dam');

    expect($result['outcome'])->toBe('reversed')
        ->and($result['kept'])->toBe(['field storage asset_rights_holder: locked, because entries once held data for it (ADR-006) — a later apply adopts it as it is'])
        ->and(MediaFile::query()->exists())->toBeFalse()
        ->and(Storage::disk(MediaDisks::PRIVATE)->exists((string) $path))->toBeFalse()
        ->and(EntryType::query()->where('org_id', $this->org->getKey())->exists())->toBeFalse()
        ->and(Role::query()->exists())->toBeFalse();
});

/**
 * D15 — the nomination the DAM invites, then the reverse. `subject_field_id` names a field that cascades from the very
 * type being deleted, a cycle each engine handles its own way; the reverse clears the nomination first, so it never runs.
 */
it('reverses after the rights holder was nominated as the subject', function (array $stored): void {
    /* A neighbour with a nomination of its own, which clearing this one must leave exactly as it is. */
    BlueprintApplier::apply(new BlogBlueprint);
    $post = EntryType::query()->where('org_id', $this->org->getKey())->where('handle', 'post')->firstOrFail();
    $postSubject = Field::query()->where('entry_type_id', $post->getKey())->whereHas('fieldStorage', fn ($q) => $q->where('handle', 'post_excerpt'))->value('id');
    $post->update(['subject_field_id' => $postSubject]);

    BlueprintApplier::apply(new DamBlueprint);
    $type = damAsset();
    $type->update(['subject_field_id' => Field::query()->where('entry_type_id', $type->getKey())->orderBy('ordering')->value('id')]);
    if ($stored !== []) {
        DB::table('entry_types')->where('id', $type->getKey())->update($stored);
    }

    expect(BlueprintApplier::reverse('dam')['outcome'])->toBe('reversed')
        ->and(EntryType::query()->where('org_id', $this->org->getKey())->pluck('handle')->sort()->values()->all())->toBe(['post', 'tag'])
        ->and(Field::query()->where('entry_type_id', $type->getKey())->exists())->toBeFalse()
        ->and($post->fresh()->subject_field_id)->toBe($postSubject);
})->with([
    'as applied' => [[]],
    /*
     * ⚠️ AND WHATEVER ELSE THE ROW HOLDS, which review found: clearing the nomination with a save ran every `saving`
     * guard, so a row they refuse — an icon from a set since uninstalled, formats a bulk write named — could not be
     * reversed once nominated, though it reversed without. Neither column is one a bulk write is refused.
     */
    'an icon no set provides' => [['icon' => 'heroicon-o-not-an-icon-at-all']],
    'formats Kitsune does not store' => [['settings' => json_encode(['accepts' => ['exe']])]],
]);

/**
 * ⚠️ MEASURED ON EVERY ENGINE, NOT RELIED ON: an owner deleting such a type in the admin runs the cycle itself. SQLite
 * deletes it; CI asks PostgreSQL, MySQL and MariaDB the same question.
 */
it('deletes a type whose nominated subject is one of its own fields', function (): void {
    BlueprintApplier::apply(new DamBlueprint);
    $type = damAsset();
    $type->update(['subject_field_id' => Field::query()->where('entry_type_id', $type->getKey())->orderBy('ordering')->value('id')]);

    $type->fresh()->delete();

    expect(EntryType::query()->whereKey($type->getKey())->exists())->toBeFalse()
        ->and(Field::query()->where('entry_type_id', $type->getKey())->exists())->toBeFalse();
});

/** D16 */
it('refuses to reverse while anybody holds one of its roles', function (): void {
    BlueprintApplier::apply(new DamBlueprint);
    damHolder($this->org, 'dam_contributor');

    expect(fn () => BlueprintApplier::reverse('dam'))->toThrow(RuntimeException::class,
        'role dam_contributor is held by 1 account — an owner unassigns it under Roles first, which is audited (ADR-033)'
    );
});
