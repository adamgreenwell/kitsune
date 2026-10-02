<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Blueprints\Declarations\EntryTypeDeclaration;
use Kitsune\Core\Blueprints\Declarations\FieldDeclaration;
use Kitsune\Core\Blueprints\Declarations\RoleDeclaration;
use Kitsune\Core\Blueprints\OnCollision;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\RolePermission;
use Kitsune\Core\Schema\SchemaManager;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\FixtureBlueprint;
use Kitsune\Core\Tests\Fixtures\SchemaManagerStandIn;

/*
 * An apply that stopped after its rows committed is finished by the next run — ADR-039's receipt, as built.
 *
 * ⚠️ THE MANIFEST COMMITS WITH THE ROWS OR NOT AT ALL. So `manifest` set with `applied_at` null means exactly
 * "the rows are there and the finish did not run", and that is the one state the next run may complete rather
 * than redo. Before this, the manifest was written after the commit, and an apply stopped between the two left an
 * org whose every re-run refused the rows it had itself written.
 */

beforeEach(function (): void {
    FixtureBlueprint::reset();
    FixtureBlueprint::$override = [
        new EntryTypeDeclaration(handle: 'dispatch', name: 'Dispatch', pluralName: 'Dispatches', fields: [
            new FieldDeclaration(handle: 'dispatch_code', type: 'text', label: 'Code', piiClass: 'none', isIndexed: true),
        ]),
    ];
    FixtureBlueprint::$roles = [new RoleDeclaration('dispatcher', 'Dispatcher', ['dispatch' => ['view', 'update']])];

    $this->org = Org::create(['slug' => 'acme', 'name' => 'Acme']);

    app(Context::class)->setOrg($this->org);
});

afterEach(function (): void {
    app()->forgetInstance(SchemaManager::class);
    app(Context::class)->forget();
});

it('finishes an apply that stopped after its rows committed, writing none of them twice', function (): void {
    SchemaManagerStandIn::install()->throwOnce(new RuntimeException('the index sync stopped here'));

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))
        ->toThrow(RuntimeException::class, 'the index sync stopped here');

    $receipt = Blueprint::receiptFor('fixture');

    expect(EntryType::query()->where('handle', 'dispatch')->count())->toBe(1)
        ->and(Role::query()->where('handle', 'dispatcher')->count())->toBe(1)
        ->and($receipt->applied_at)->toBeNull()
        ->and($receipt->manifest)->not->toBeNull()
        ->and($receipt->manifest['entry_types'][0]['id'])->toBe(EntryType::query()->where('handle', 'dispatch')->value('id'));

    $result = BlueprintApplier::apply(new FixtureBlueprint);

    expect($result['indexed'])->toBe(1)
        ->and($result['created'])->toBe([])
        ->and($result['roles_created'])->toBe([])
        ->and($result['skipped'])->toBe(['rows: written by an earlier run that stopped before it finished; finished now'])
        ->and(EntryType::query()->where('handle', 'dispatch')->count())->toBe(1)
        ->and(FieldStorage::query()->where('handle', 'dispatch_code')->count())->toBe(1)
        ->and(Role::query()->where('handle', 'dispatcher')->count())->toBe(1)
        ->and(RolePermission::query()->withoutGlobalScopes()->count())->toBe(2)
        ->and(Blueprint::receiptFor('fixture')->applied_at)->not->toBeNull();

    /* And a third run is the ordinary no-op: finished means finished. */
    expect(BlueprintApplier::apply(new FixtureBlueprint)['skipped'])->toContain('already applied at this version');
});

/** A refusal inside the transaction rolls the manifest back with the rows, so the next run starts over. */
it('leaves the manifest unwritten when a refusal rolls the rows back, and applies afresh once the obstacle is gone', function (): void {
    $theirs = Role::create(['handle' => 'dispatcher', 'name' => 'Theirs']);

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))
        ->toThrow(RuntimeException::class, 'Role [dispatcher] already exists');

    $receipt = Blueprint::receiptFor('fixture');

    expect($receipt->applied_at)->toBeNull()
        ->and($receipt->manifest)->toBeNull()
        ->and(EntryType::query()->where('handle', 'dispatch')->exists())->toBeFalse();

    $theirs->delete();

    $result = BlueprintApplier::apply(new FixtureBlueprint);

    expect($result['created'])->toContain('entry type dispatch')
        ->and($result['roles_created'])->toBe(['dispatcher'])
        ->and($result['indexed'])->toBe(1)
        ->and(Blueprint::receiptFor('fixture')->applied_at)->not->toBeNull();
});

/**
 * ⚠️ A MANIFEST IS CHECKED, NOT TRUSTED. It names rows by id, and an id this org does not have is either another
 * org's row or nobody's — finishing on it would record as applied a blueprint whose rows are not here.
 */
it('refuses to finish a receipt whose manifest names rows this organisation does not have', function (string $which): void {
    $other = Org::create(['slug' => 'rival', 'name' => 'Rival']);
    app(Context::class)->setOrg($other);
    $theirType = EntryType::create(['org_id' => $other->getKey(), 'handle' => 'dispatch', 'name' => 'Theirs', 'plural_name' => 'Theirs']);
    $theirRole = Role::create(['handle' => 'dispatcher', 'name' => 'Theirs']);
    app(Context::class)->setOrg($this->org);

    $manifest = [
        'version' => '1.0.0',
        'entry_types' => [['handle' => 'dispatch', 'id' => $which === 'type' ? $theirType->getKey() : 999_999, 'outcome' => 'created', 'fields' => []]],
        'roles' => [['handle' => 'dispatcher', 'id' => $which === 'role' ? $theirRole->getKey() : 999_998, 'outcome' => 'created']],
    ];

    Blueprint::create(['handle' => 'fixture', 'version' => '1.0.0', 'manifest' => $manifest, 'applied_at' => null]);

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))
        ->toThrow(RuntimeException::class, 'records rows this organisation does not have');

    expect(Blueprint::receiptFor('fixture')->applied_at)->toBeNull()
        ->and(EntryType::query()->where('org_id', $this->org->getKey())->exists())->toBeFalse()
        ->and(Role::query()->exists())->toBeFalse();
})->with(['another org\'s type' => 'type', 'another org\'s role' => 'role']);

/** A skipped role is recorded with no id, and the finish does not look for one. */
it('finishes a receipt that skipped a role, without asking for its id', function (): void {
    FixtureBlueprint::$roles = [new RoleDeclaration('dispatcher', 'Dispatcher', ['dispatch' => ['view']], OnCollision::Skip)];
    Role::create(['handle' => 'dispatcher', 'name' => 'Theirs']);

    SchemaManagerStandIn::install()->throwOnce(new RuntimeException('stopped'));

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(RuntimeException::class, 'stopped');

    expect(BlueprintApplier::apply(new FixtureBlueprint)['indexed'])->toBe(1)
        ->and(Blueprint::receiptFor('fixture')->applied_at)->not->toBeNull();
});
