<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Blueprints\Declarations\EntryTypeDeclaration;
use Kitsune\Core\Blueprints\Declarations\FieldDeclaration;
use Kitsune\Core\Blueprints\Declarations\RoleDeclaration;
use Kitsune\Core\Blueprints\OnCollision;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaFormats;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\MediaFile;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Schema\SchemaManager;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\FixtureBlueprint;
use Kitsune\Core\Tests\Fixtures\SchemaManagerStandIn;
use Kitsune\Core\Tests\Fixtures\TestUser;

/*
 * A blueprint declares a media type — ADR-039, the DAM as built.
 *
 * ⚠️ ONE PARAMETER, DECIDED ONCE. `isMedia` is written when the apply creates a type and never again: the model locks the
 * flag (ADR-042 decision 1), a merge refuses a version that changes it either way, and `Skip` refuses to adopt a type on
 * the other side of the line, because an adopted type keeps the flag it was created with for good. These tests hold
 * each of those, and the manifest that records it.
 */

beforeEach(function (): void {
    FixtureBlueprint::reset();
    FixtureBlueprint::$override = [blueprintMediaType()];
    config(['auth.providers.users.model' => TestUser::class]);
    Storage::fake('public');
    Storage::fake(MediaDisks::PRIVATE);
    SchemaManagerStandIn::install()->recordOnly();

    $this->org = Org::create(['slug' => 'media', 'name' => 'Media']);
    app(Context::class)->setOrg($this->org);
});

afterEach(function (): void {
    app()->forgetInstance(SchemaManager::class);
    app(Context::class)->forget();

    foreach (glob(sys_get_temp_dir().'/kitsune-blueprint-media-*') ?: [] as $leftover) {
        @unlink($leftover);
    }
});

/** `library`, declared media unless told otherwise, with one field. */
function blueprintMediaType(array $changes = []): EntryTypeDeclaration
{
    return new EntryTypeDeclaration(...array_replace([
        'handle' => 'library',
        'name' => 'Library item',
        'pluralName' => 'Library',
        'fields' => [new FieldDeclaration(handle: 'library_credit', type: 'text', label: 'Credit', piiClass: 'none')],
        'isMedia' => true,
    ], $changes));
}

function blueprintMediaRow(string $handle = 'library'): EntryType
{
    return EntryType::query()->where('org_id', app(Context::class)->orgId())->where('handle', $handle)->firstOrFail();
}

/** Store bytes as an upload would, in a site of the org. */
function blueprintMediaStore(EntryType $type, string $bytes, string $name): void
{
    $source = tempnam(sys_get_temp_dir(), 'kitsune-blueprint-media-');
    file_put_contents($source, $bytes);
    $org = app(Context::class)->org();
    app(Context::class)->setSite(Site::query()->where('org_id', $org->getKey())->first() ?? Site::create(['org_id' => $org->getKey(), 'handle' => 'main', 'slug' => "{$org->slug}-main", 'name' => 'Main', 'locale' => 'en']));
    MediaLibrary::store($source, $name, $type);
    app(Context::class)->forget()->setOrg($org);
}

function blueprintMediaPng(): string
{
    return "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\x0f\0\0\x01\x01\0\x05\x18\xd8N\0\0\0\0IEND\xaeB`\x82";
}

function blueprintMediaPdf(): string
{
    return "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
}

/** Every row an apply might write, and the receipt as the database holds it. */
function blueprintMediaSnapshot(): array
{
    $rows = [];

    foreach (['entry_types', 'field_storage', 'fields', 'roles', 'role_permissions'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
    }

    return $rows;
}

/** F1 */
it('creates a declared media type as one, taking every format, and an undeclared one as ordinary', function (): void {
    FixtureBlueprint::$override = [blueprintMediaType(), blueprintMediaType(['handle' => 'note', 'name' => 'Note', 'pluralName' => 'Notes', 'fields' => [], 'isMedia' => false])];

    BlueprintApplier::apply(new FixtureBlueprint);
    $library = blueprintMediaRow();

    expect($library->is_media)->toBeTrue()
        ->and($library->settings)->toBeNull()
        ->and(blueprintMediaRow('note')->is_media)->toBeFalse()
        ->and((new EntryTypeDeclaration(handle: 'plain', name: 'Plain', pluralName: 'Plains'))->isMedia)->toBeFalse();

    blueprintMediaStore($library, blueprintMediaPng(), 'pixel.png');
    blueprintMediaStore($library, blueprintMediaPdf(), 'terms.pdf');

    expect(MediaFile::query()->count())->toBe(2);
});

/** F2 — S1, both ways: a type adopted on the other side of the line stays there for good, so it is refused. */
it('refuses to adopt under Skip a type on the other side of the media line, writing nothing', function (bool $theirsIsMedia): void {
    $theirs = EntryType::create(['org_id' => $this->org->getKey(), 'handle' => 'library', 'name' => 'Theirs', 'plural_name' => 'Theirs', 'is_media' => $theirsIsMedia]);
    $columns = $theirs->fresh()->toArray();
    FixtureBlueprint::$override = [blueprintMediaType(['isMedia' => ! $theirsIsMedia, 'onCollision' => OnCollision::Skip])];
    $before = blueprintMediaSnapshot();

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(new RuntimeException(sprintf(
        'Entry type [library] already exists in this organisation as %s, and this blueprint declares it as %s, with '
        .'onCollision: skip. Whether a type holds uploaded files is decided when it is created and never changed '
        .'(ADR-042), so it cannot be adopted as declared. Nothing was written. Give the declared type another handle, '
        .'or remove the one that is there.',
        $theirsIsMedia ? 'a media type' : 'a type that holds no files',
        $theirsIsMedia ? 'one that holds no files' : 'a media type',
    )));

    expect(blueprintMediaSnapshot())->toBe($before)
        ->and(Blueprint::receiptFor('fixture')->manifest)->toBeNull()
        ->and($theirs->fresh()->toArray())->toBe($columns);
})->with(['theirs is media, declared ordinary' => true, 'theirs is ordinary, declared media' => false]);

/** F3 — on the same side it adopts, as before, and the formats the owner chose stay theirs. */
it('adopts under Skip a type on the same side of the media line, keeping the formats it accepts', function (bool $media): void {
    $theirs = EntryType::create(['org_id' => $this->org->getKey(), 'handle' => 'library', 'name' => 'Theirs', 'plural_name' => 'Theirs', 'is_media' => $media, 'settings' => $media ? [MediaFormats::SETTING => ['png']] : null]);
    FixtureBlueprint::$override = [blueprintMediaType(['isMedia' => $media, 'onCollision' => OnCollision::Skip])];

    $result = BlueprintApplier::apply(new FixtureBlueprint);

    expect($result['skipped'])->toBe(['entry type library'])
        ->and(Field::query()->where('entry_type_id', $theirs->getKey())->count())->toBe(1)
        ->and($theirs->fresh()->settings)->toBe($media ? [MediaFormats::SETTING => ['png']] : null)
        ->and($theirs->fresh()->name)->toBe('Theirs');
})->with(['media' => true, 'ordinary' => false]);

/** F4 */
it('refuses a media type on a global type\'s handle, whatever the policy', function (OnCollision $policy): void {
    EntryType::create(['handle' => 'library', 'name' => 'Global', 'plural_name' => 'Globals', 'is_media' => true]);
    FixtureBlueprint::$override = [blueprintMediaType(['onCollision' => $policy])];

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(RuntimeException::class, 'Entry type [library] is a global type');

    expect(EntryType::query()->where('org_id', $this->org->getKey())->exists())->toBeFalse();
})->with(['fail' => OnCollision::Fail, 'skip' => OnCollision::Skip]);

/** F5 — the type row's key set pinned exactly, so a key cannot arrive or leave unnoticed; MySQL re-sorts the keys. */
it('records whether each type is media, as declared, in a type row of exactly these keys', function (): void {
    FixtureBlueprint::$override = [blueprintMediaType(), blueprintMediaType(['handle' => 'note', 'name' => 'Note', 'pluralName' => 'Notes', 'fields' => [], 'isMedia' => false])];

    BlueprintApplier::apply(new FixtureBlueprint);
    $manifest = Blueprint::receiptFor('fixture')->manifest;

    foreach ($manifest['entry_types'] as $row) {
        expect(array_keys($row))->toEqualCanonicalizing(['handle', 'id', 'outcome', 'name', 'plural_name', 'icon', 'description', 'ordering', 'on_collision', 'is_media', 'fields']);
    }

    expect(array_column($manifest['entry_types'], 'is_media', 'handle'))->toBe(['library' => true, 'note' => false]);
});

/** F6 — never changed by a merge, either way; added with a version that adds the type. */
it('refuses a version dropping the media flag, and merges one adding a media type and a role on it', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    FixtureBlueprint::$version = '1.1.0';
    FixtureBlueprint::$override = [blueprintMediaType(['isMedia' => false])];

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(RuntimeException::class, 'entry type library changes its is_media');

    FixtureBlueprint::$override = [blueprintMediaType(), blueprintMediaType(['handle' => 'clip', 'name' => 'Clip', 'pluralName' => 'Clips', 'fields' => []])];
    FixtureBlueprint::$roles = [new RoleDeclaration('archivist', 'Archivist', ['clip' => ['view', 'create', 'publish'], 'library' => ['view']])];

    $result = BlueprintApplier::apply(new FixtureBlueprint);

    expect($result['roles_created'])->toBe(['archivist'])
        ->and(blueprintMediaRow('clip')->is_media)->toBeTrue()
        ->and(Role::query()->where('handle', 'archivist')->firstOrFail()->permissions()->orderBy('permission')->pluck('permission')->all())
        ->toBe(['entry.clip.create', 'entry.clip.publish', 'entry.clip.view', 'entry.library.view'])
        ->and(Blueprint::receiptFor('fixture')->version)->toBe('1.1.0');
});

/**
 * F7 — a present null is what the manifest says: it is not filled, and compares as the change it is.
 *
 * ⚠️ BOTH FLAGS, AND THE ORDINARY ONE IS THE ONE THAT COUNTS — review found F7 asking only the media one. A null filled
 * to `false` compares unequal to a declared `true` in the same words as a null left alone, so only a type declared
 * ordinary tells `array_key_exists` from `??`: filled, the forged manifest would merge.
 */
it('refuses to merge over a manifest whose media flag was forged to null', function (bool $media): void {
    FixtureBlueprint::$override = [blueprintMediaType(['isMedia' => $media])];
    BlueprintApplier::apply(new FixtureBlueprint);
    $receipt = Blueprint::receiptFor('fixture');
    $manifest = $receipt->manifest;
    Arr::set($manifest, 'entry_types.0.is_media', null);
    DB::table('blueprints')->where('id', $receipt->getKey())->update(['manifest' => json_encode($manifest)]);
    FixtureBlueprint::$version = '1.1.0';

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(RuntimeException::class, 'entry type library changes its is_media')
        ->and(Blueprint::receiptFor('fixture')->version)->toBe('1.0.0');
})->with(['declared media' => true, 'declared ordinary' => false]);

/** F8 — the merge sends a type a later version adds through the same adoption, so S1 holds there too. */
it('refuses a later version adopting under Skip a type across the media line, leaving the receipt where it was', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    EntryType::create(['org_id' => $this->org->getKey(), 'handle' => 'clip', 'name' => 'Theirs', 'plural_name' => 'Theirs', 'is_media' => false]);
    $before = blueprintMediaSnapshot();
    FixtureBlueprint::$version = '1.1.0';
    FixtureBlueprint::$override = [blueprintMediaType(), blueprintMediaType(['handle' => 'clip', 'name' => 'Clip', 'pluralName' => 'Clips', 'fields' => [], 'onCollision' => OnCollision::Skip])];

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(RuntimeException::class, 'Entry type [clip] already exists in this organisation as a type that holds no files');

    expect(blueprintMediaSnapshot())->toBe($before)
        ->and(Blueprint::receiptFor('fixture')->version)->toBe('1.0.0');
});
