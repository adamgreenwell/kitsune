<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Blueprints\Declarations\EntryTypeDeclaration;
use Kitsune\Core\Blueprints\Declarations\FieldDeclaration;
use Kitsune\Core\Blueprints\OnCollision;
use Kitsune\Core\Media\MediaFormats;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Schema\SchemaManager;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\FixtureBlueprint;
use Kitsune\Core\Tests\Fixtures\SchemaManagerStandIn;

beforeEach(function (): void {
    FixtureBlueprint::reset();
    SchemaManagerStandIn::install()->recordOnly();
    $this->org = Org::create(['slug' => 'accepts', 'name' => 'Accepts']);
    app(Context::class)->setOrg($this->org);
});

afterEach(function (): void {
    app()->forgetInstance(SchemaManager::class);
    app(Context::class)->forget();
});

function blueprintAcceptsType(?array $accepts = null, bool $media = true, array $fields = [], OnCollision $policy = OnCollision::Fail): EntryTypeDeclaration
{
    return new EntryTypeDeclaration(
        handle: 'pictures', name: 'Picture', pluralName: 'Pictures', fields: $fields,
        onCollision: $policy, isMedia: $media, accepts: $accepts,
    );
}

function blueprintAcceptsStoredType(): EntryType
{
    return EntryType::query()->where('org_id', app(Context::class)->orgId())->where('handle', 'pictures')->firstOrFail();
}

function blueprintAcceptsRemoveLegacyKey(): void
{
    $receipt = Blueprint::receiptFor('fixture');
    $manifest = $receipt->manifest;
    unset($manifest['entry_types'][0]['accepts']);
    DB::table('blueprints')->where('id', $receipt->getKey())->update(['manifest' => json_encode($manifest, JSON_THROW_ON_ERROR)]);
}

it('writes explicit media formats at creation and records the declared list', function (): void {
    FixtureBlueprint::$override = [blueprintAcceptsType(['jpeg', 'png'])];
    BlueprintApplier::apply(new FixtureBlueprint);

    expect(blueprintAcceptsStoredType()->settings)->toBe([MediaFormats::SETTING => ['jpeg', 'png']])
        ->and(Blueprint::receiptFor('fixture')->manifest['entry_types'][0]['accepts'])->toBe(['jpeg', 'png']);
});

it('rejects malformed or non-media format declarations before writing an intent receipt', function (array $accepts, bool $media): void {
    FixtureBlueprint::$override = [blueprintAcceptsType($accepts, $media)];

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(InvalidArgumentException::class, 'accepts');
    expect(Blueprint::query()->count())->toBe(0)->and(EntryType::query()->count())->toBe(0);
})->with([
    'empty' => [[], true],
    'unknown' => [['tiff'], true],
    'duplicate' => [['png', 'png'], true],
    'not a list' => [['image' => 'png'], true],
    'not a format string' => [[1], true],
    'ordinary type' => [['png'], false],
]);

it('keeps null as unrestricted media formats and records it for ordinary types too', function (bool $media): void {
    FixtureBlueprint::$override = [blueprintAcceptsType(media: $media)];
    BlueprintApplier::apply(new FixtureBlueprint);

    expect(blueprintAcceptsStoredType()->settings)->toBeNull()
        ->and(Blueprint::receiptFor('fixture')->manifest['entry_types'][0]['accepts'])->toBeNull();
})->with([false, true]);

it('keeps an adopted media types own formats instead of imposing a declaration', function (): void {
    $existing = EntryType::create([
        'org_id' => $this->org->getKey(), 'handle' => 'pictures', 'name' => 'Theirs', 'plural_name' => 'Theirs',
        'is_media' => true, 'settings' => [MediaFormats::SETTING => ['pdf']],
    ]);
    FixtureBlueprint::$override = [blueprintAcceptsType(['png'], policy: OnCollision::Skip)];
    BlueprintApplier::apply(new FixtureBlueprint);

    expect(blueprintAcceptsStoredType()->getKey())->toBe($existing->getKey())
        ->and($existing->fresh()->settings)->toBe([MediaFormats::SETTING => ['pdf']])
        ->and(Blueprint::receiptFor('fixture')->manifest['entry_types'][0]['accepts'])->toBe(['png']);
});

it('adds a later field while preserving the owners current media formats', function (): void {
    FixtureBlueprint::$override = [blueprintAcceptsType(['png'])];
    BlueprintApplier::apply(new FixtureBlueprint);
    blueprintAcceptsStoredType()->update(['settings' => [MediaFormats::SETTING => ['jpeg']]]);

    FixtureBlueprint::$version = '1.1.0';
    FixtureBlueprint::$override = [blueprintAcceptsType(['png'], fields: [
        new FieldDeclaration(handle: 'picture_credit', type: 'text', label: 'Credit', piiClass: 'none'),
    ])];
    BlueprintApplier::apply(new FixtureBlueprint);

    expect(blueprintAcceptsStoredType()->settings)->toBe([MediaFormats::SETTING => ['jpeg']])
        ->and(blueprintAcceptsStoredType()->fields()->count())->toBe(1)
        ->and(Blueprint::receiptFor('fixture')->manifest['entry_types'][0]['accepts'])->toBe(['png']);
});

it('refuses a later declaration that changes its recorded formats', function (?array $before, ?array $after): void {
    FixtureBlueprint::$override = [blueprintAcceptsType($before)];
    BlueprintApplier::apply(new FixtureBlueprint);
    $manifest = Blueprint::receiptFor('fixture')->manifest;
    FixtureBlueprint::$version = '1.1.0';
    FixtureBlueprint::$override = [blueprintAcceptsType($after)];

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(RuntimeException::class, 'changes its accepts');
    expect(Blueprint::receiptFor('fixture')->version)->toBe('1.0.0')
        ->and(Blueprint::receiptFor('fixture')->manifest)->toBe($manifest);
})->with([
    'different formats' => [['png'], ['jpeg']],
    'widening to all formats' => [['png'], null],
    'narrowing an old unrestricted declaration' => [null, ['png']],
]);

it('merges an old media receipt without accepts as the unrestricted declaration it meant', function (): void {
    FixtureBlueprint::$override = [blueprintAcceptsType()];
    BlueprintApplier::apply(new FixtureBlueprint);
    blueprintAcceptsRemoveLegacyKey();
    blueprintAcceptsStoredType()->update(['settings' => [MediaFormats::SETTING => ['jpeg']]]);
    FixtureBlueprint::$version = '1.1.0';
    FixtureBlueprint::$override = [blueprintAcceptsType(fields: [
        new FieldDeclaration(handle: 'picture_credit', type: 'text', label: 'Credit', piiClass: 'none'),
    ])];
    BlueprintApplier::apply(new FixtureBlueprint);

    expect(Blueprint::receiptFor('fixture')->version)->toBe('1.1.0')
        ->and(Blueprint::receiptFor('fixture')->manifest['entry_types'][0]['accepts'])->toBeNull()
        ->and(blueprintAcceptsStoredType()->settings)->toBe([MediaFormats::SETTING => ['jpeg']]);
});

it('does not infer an image restriction from an old receipt that lacked accepts', function (): void {
    FixtureBlueprint::$override = [blueprintAcceptsType()];
    BlueprintApplier::apply(new FixtureBlueprint);
    blueprintAcceptsRemoveLegacyKey();
    FixtureBlueprint::$version = '1.1.0';
    FixtureBlueprint::$override = [blueprintAcceptsType(['png'])];

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(RuntimeException::class, 'changes its accepts');
    expect(blueprintAcceptsStoredType()->settings)->toBeNull();
});

it('fully reverses an unused old media receipt without accepts', function (): void {
    FixtureBlueprint::$override = [blueprintAcceptsType()];
    BlueprintApplier::apply(new FixtureBlueprint);
    blueprintAcceptsRemoveLegacyKey();
    BlueprintApplier::reverse('fixture');

    expect(Blueprint::receiptFor('fixture'))->toBeNull()->and(EntryType::query()->count())->toBe(0);
});
