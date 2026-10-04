<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
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
 *
 * ⚠️ NO REAL DDL HERE. The schema manager is a stand-in that records each sync and runs none: a generated column is
 * DDL, which on MySQL and MariaDB commits `RefreshDatabase`'s own wrapper. `tests/LevelZero` asks for the real one.
 */

beforeEach(function (): void {
    FixtureBlueprint::reset();
    FixtureBlueprint::$override = [
        new EntryTypeDeclaration(handle: 'dispatch', name: 'Dispatch', pluralName: 'Dispatches', fields: [
            new FieldDeclaration(handle: 'dispatch_code', type: 'text', label: 'Code', piiClass: 'none', isIndexed: true),
        ]),
    ];
    FixtureBlueprint::$roles = [new RoleDeclaration('dispatcher', 'Dispatcher', ['dispatch' => ['view', 'update']])];

    $this->schema = SchemaManagerStandIn::install()->recordOnly();
    $this->org = Org::create(['slug' => 'acme', 'name' => 'Acme']);

    app(Context::class)->setOrg($this->org);
});

afterEach(function (): void {
    app()->forgetInstance(SchemaManager::class);
    app(Context::class)->forget();
});

/** Stop the next apply after its rows commit, as a kill or a failed index sync would. */
function resumeInterrupted(): void
{
    test()->schema->throwOnce(new RuntimeException('the index sync stopped here'));

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(RuntimeException::class, 'the index sync stopped here');
}

it('finishes an apply that stopped after its rows committed, writing none of them twice', function (): void {
    resumeInterrupted();

    $receipt = Blueprint::receiptFor('fixture');

    expect(EntryType::query()->where('handle', 'dispatch')->count())->toBe(1)
        ->and(Role::query()->where('handle', 'dispatcher')->count())->toBe(1)
        ->and($receipt->applied_at)->toBeNull()
        ->and($receipt->manifest)->not->toBeNull()
        ->and($receipt->manifest['entry_types'][0]['id'])->toBe(EntryType::query()->where('handle', 'dispatch')->value('id'))
        ->and($this->schema->syncedHandles)->toBe([]);

    $result = BlueprintApplier::apply(new FixtureBlueprint);

    expect($result['indexed'])->toBe(1)
        /* The finish really asked for the index, rather than counting what it would have asked for. */
        ->and($this->schema->syncedHandles)->toBe(['dispatch_code'])
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
 * ⚠️ AND A RECEIPT THAT WROTE NO ROWS IS NO VERSION'S. Blog's version moves with core's, so the obstacle removed after
 * an upgrade meets a newer definition than the one that stopped — and with no row of the old version committed, there
 * is nothing to merge: the new one applies afresh, and the receipt takes its version.
 */
it('applies afresh at a new version over a receipt that wrote no rows', function (): void {
    $theirs = Role::create(['handle' => 'dispatcher', 'name' => 'Theirs']);

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(RuntimeException::class, 'Role [dispatcher] already exists');

    $theirs->delete();
    FixtureBlueprint::$version = '1.0.1';

    $result = BlueprintApplier::apply(new FixtureBlueprint);
    $receipt = Blueprint::receiptFor('fixture');

    expect($result['version'])->toBe('1.0.1')
        ->and($result['roles_created'])->toBe(['dispatcher'])
        ->and($receipt->version)->toBe('1.0.1')
        ->and($receipt->applied_at)->not->toBeNull()
        ->and(Blueprint::query()->count())->toBe(1);
});

/**
 * ⚠️ AND ONE WHOSE ROWS COMMITTED IS FINISHED AT THE VERSION IT RECORDS. Refusing it — the newer definition is not the
 * version that wrote the rows — stranded the org, because an operator cannot get an older core's Blog back. So the
 * finish completes the recorded version, says so, and the next run merges the newer one ~~is refused as any other
 * version over an applied receipt is~~.
 */
it('finishes the recorded version under a newer definition, and merges the newer one on the next run', function (): void {
    resumeInterrupted();
    $manifest = Blueprint::receiptFor('fixture')->manifest;

    FixtureBlueprint::$version = '1.1.0';
    FixtureBlueprint::$roles[] = new RoleDeclaration('router', 'Router', ['dispatch' => ['view']]);

    $result = BlueprintApplier::apply(new FixtureBlueprint);
    $receipt = Blueprint::receiptFor('fixture');

    expect($result['version'])->toBe('1.0.0')
        ->and($result['skipped'])->toContain('version: finished at 1.0.0, which the receipt records; this definition is 1.1.0 — run it again to merge it over 1.0.0')
        ->and($result['indexed'])->toBe(1)
        ->and($receipt->version)->toBe('1.0.0')
        ->and($receipt->applied_at)->not->toBeNull()
        ->and($receipt->manifest)->toBe($manifest)
        ->and(Role::query()->where('handle', 'router')->exists())->toBeFalse();

    $merged = BlueprintApplier::apply(new FixtureBlueprint);

    expect($merged['version'])->toBe('1.1.0')
        ->and($merged['roles_created'])->toBe(['router'])
        ->and($merged['indexed'])->toBe(0)
        ->and(Blueprint::receiptFor('fixture')->version)->toBe('1.1.0');
});

/** Version 1.1.0 of the fixture: 1.0.0 with an indexed field more, so the merge has a sync to stop in. */
function resumeNextVersion(): void
{
    FixtureBlueprint::$version = '1.1.0';
    FixtureBlueprint::$override = [
        new EntryTypeDeclaration(handle: 'dispatch', name: 'Dispatch', pluralName: 'Dispatches', fields: [
            new FieldDeclaration(handle: 'dispatch_code', type: 'text', label: 'Code', piiClass: 'none', isIndexed: true),
            new FieldDeclaration(handle: 'dispatch_ref', type: 'text', label: 'Reference', piiClass: 'none', isIndexed: true),
        ]),
    ];
}

/**
 * ⚠️ A MERGE COMMITS ITS VERSION AND MANIFEST WITH ITS ROWS, AND `applied_at` NULL. So one stopped in its index sync is
 * exactly an interrupted apply at the new version — state 2 — and the next run finishes it as it finishes any other.
 */
it('finishes a merge stopped after its rows committed, at the new version', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    resumeNextVersion();

    resumeInterrupted();

    $receipt = Blueprint::receiptFor('fixture');

    expect($receipt->version)->toBe('1.1.0')
        ->and($receipt->applied_at)->toBeNull()
        ->and($receipt->manifest['version'])->toBe('1.1.0')
        ->and(array_column($receipt->manifest['entry_types'][0]['fields'], 'handle'))->toBe(['dispatch_code', 'dispatch_ref'])
        ->and(FieldStorage::query()->where('handle', 'dispatch_ref')->count())->toBe(1);

    $result = BlueprintApplier::apply(new FixtureBlueprint);

    /* Every indexed field the manifest records, re-synced — idempotent, and what the stopped merge never reached. */
    expect($result['version'])->toBe('1.1.0')
        ->and($result['skipped'])->toBe(['rows: written by an earlier run that stopped before it finished; finished now'])
        ->and($result['indexed'])->toBe(2)
        ->and(array_slice($this->schema->syncedHandles, -2))->toBe(['dispatch_code', 'dispatch_ref'])
        ->and(Blueprint::receiptFor('fixture')->applied_at)->not->toBeNull()
        ->and(FieldStorage::query()->where('handle', 'dispatch_ref')->count())->toBe(1)
        ->and(BlueprintApplier::apply(new FixtureBlueprint)['skipped'])->toBe(['already applied at this version']);
});

/**
 * ⚠️ A ROLE RENAMED IN THIS ORG IS ITS OWNER'S EDIT, ~~A FORGERY~~. An interrupted merge records roles an earlier version
 * created long before, and the admin edits a role's handle; refusing the rename left the org at state 2 for good. The
 * finish writes nothing to roles, so it goes ahead and says so.
 */
it('finishes over a role its operator renamed, saying so', function (bool $merging): void {
    if ($merging) {
        BlueprintApplier::apply(new FixtureBlueprint);
        resumeNextVersion();
    }

    resumeInterrupted();
    Role::query()->where('handle', 'dispatcher')->firstOrFail()->update(['handle' => 'dispatch_lead']);

    $result = BlueprintApplier::apply(new FixtureBlueprint);

    expect($result['skipped'])->toBe([
        'rows: written by an earlier run that stopped before it finished; finished now',
        'role dispatcher: renamed dispatch_lead since the interrupted apply wrote it; left as it is',
    ])
        ->and(Role::query()->pluck('handle')->all())->toBe(['dispatch_lead'])
        ->and(Blueprint::receiptFor('fixture')->applied_at)->not->toBeNull();
})->with(['an interrupted merge' => true, 'an interrupted fresh apply' => false]);

/**
 * ⚠️ A MANIFEST IS CHECKED, NOT TRUSTED. It names rows by id, and a row under that id that is another org's — or this
 * org's under another handle — means the manifest is not this org's record of these rows. Each row isolates one rule:
 * the other half of its manifest is a skipped declaration, which records no id to check.
 */
it('refuses to finish a receipt whose manifest names a row that is not this organisation\'s', function (string $which): void {
    $rival = Org::create(['slug' => 'rival', 'name' => 'Rival']);
    app(Context::class)->setOrg($rival);
    $theirType = EntryType::create(['org_id' => $rival->getKey(), 'handle' => 'dispatch', 'name' => 'Theirs', 'plural_name' => 'Theirs']);
    $theirRole = Role::create(['handle' => 'dispatcher', 'name' => 'Theirs']);
    app(Context::class)->setOrg($this->org);
    $ourOther = EntryType::create(['org_id' => $this->org->getKey(), 'handle' => 'notice', 'name' => 'Notice', 'plural_name' => 'Notices']);

    [$typeId, $roleId, $named] = match ($which) {
        'type' => [$theirType->getKey(), null, 'entry type id '.$theirType->getKey().' is not this organisation\'s dispatch'],
        'role' => [null, $theirRole->getKey(), 'role id '.$theirRole->getKey().' is not this organisation\'s dispatcher'],
        'handle' => [$ourOther->getKey(), null, 'entry type id '.$ourOther->getKey().' is not this organisation\'s dispatch'],
    };

    Blueprint::create(['handle' => 'fixture', 'version' => '1.0.0', 'applied_at' => null, 'manifest' => [
        'version' => '1.0.0',
        'entry_types' => [['handle' => 'dispatch', 'id' => $typeId, 'outcome' => $typeId === null ? 'skipped' : 'created', 'fields' => []]],
        'roles' => [['handle' => 'dispatcher', 'id' => $roleId, 'outcome' => $roleId === null ? 'skipped' : 'created']],
    ]]);

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))
        ->toThrow(RuntimeException::class, "The receipt for [fixture] cannot be finished: {$named}.");

    expect(Blueprint::receiptFor('fixture')->applied_at)->toBeNull()
        ->and(EntryType::query()->where('org_id', $this->org->getKey())->where('handle', 'dispatch')->exists())->toBeFalse()
        ->and(Role::query()->exists())->toBeFalse()
        ->and($this->schema->syncedHandles)->toBe([]);
})->with(['another org\'s type' => 'type', 'another org\'s role' => 'role', 'this org\'s type of another handle' => 'handle']);

/** Nor a manifest that records nothing of what the definition declares: that would finish an apply with no rows. */
it('refuses to finish a receipt whose manifest records none of what is declared', function (): void {
    Blueprint::create(['handle' => 'fixture', 'version' => '1.0.0', 'applied_at' => null, 'manifest' => ['version' => '1.0.0']]);

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))
        ->toThrow(RuntimeException::class, 'cannot be finished: entry type dispatch is not recorded; role dispatcher is not recorded.');

    expect(Blueprint::receiptFor('fixture')->applied_at)->toBeNull();
});

/**
 * ⚠️ BUT A ROW THE OPERATOR REMOVED IS NOT A FORGERY. The rows are live in the admin while the finish is owed, and
 * removing one after a finish costs nothing — so removing it before one must not leave an org that no command can
 * finish or clear. It is reported, and the finish goes ahead without writing it again.
 */
it('finishes over a row the operator removed while the finish was owed, and says so', function (): void {
    resumeInterrupted();

    Role::query()->where('handle', 'dispatcher')->firstOrFail()->delete();

    $result = BlueprintApplier::apply(new FixtureBlueprint);

    expect($result['skipped'])->toBe([
        'rows: written by an earlier run that stopped before it finished; finished now',
        'role dispatcher: removed since the interrupted apply wrote it; not written again',
    ])
        ->and(Role::query()->where('handle', 'dispatcher')->exists())->toBeFalse()
        ->and(Blueprint::receiptFor('fixture')->applied_at)->not->toBeNull();
});

/** A skipped role is recorded with no id, and the finish does not look for one. */
it('finishes a receipt that skipped a role, without asking for its id', function (): void {
    FixtureBlueprint::$roles = [new RoleDeclaration('dispatcher', 'Dispatcher', ['dispatch' => ['view']], OnCollision::Skip)];
    Role::create(['handle' => 'dispatcher', 'name' => 'Theirs']);

    resumeInterrupted();

    expect(BlueprintApplier::apply(new FixtureBlueprint)['indexed'])->toBe(1)
        ->and(Blueprint::receiptFor('fixture')->applied_at)->not->toBeNull();
});

/** ⚠️ Field storage is `#[Unscoped]`: the finish indexes this org's, and not another org's of the same handle. */
it('indexes only this organisation\'s storage when it finishes', function (): void {
    $rival = Org::create(['slug' => 'rival', 'name' => 'Rival']);
    app(Context::class)->setOrg($rival);
    BlueprintApplier::apply(new FixtureBlueprint);
    app(Context::class)->setOrg($this->org);

    resumeInterrupted();
    $before = count($this->schema->syncedHandles);

    expect(BlueprintApplier::apply(new FixtureBlueprint)['indexed'])->toBe(1)
        ->and(count($this->schema->syncedHandles) - $before)->toBe(1);
});

/**
 * ⚠️ TWO APPLIES AT ONCE, AND THE ONE THAT LOSES SAYS SO. Integrity holds without help — every row has a unique index,
 * and a stale intent record saves nothing — but the loser used to report a raw constraint error, or refuse "a type it
 * did not create" that the same blueprint had created a moment before. Each interleaving is staged by running the
 * other apply in full at the moment this one has read the receipt.
 */
it('names a concurrent apply, rather than a constraint or a type it did not create', function (bool $receiptFirst): void {
    FixtureBlueprint::$override = [new EntryTypeDeclaration(handle: 'dispatch', name: 'Dispatch', pluralName: 'Dispatches', fields: [
        new FieldDeclaration(handle: 'dispatch_body', type: 'textarea', label: 'Body', piiClass: 'none'),
    ])];

    if ($receiptFirst) {
        /* The other apply's intent record, committed before this one read it. */
        Blueprint::create(['handle' => 'fixture', 'version' => '1.0.0', 'manifest' => null, 'applied_at' => null]);
    }

    $staged = false;
    DB::listen(function (QueryExecuted $query) use (&$staged): void {
        if (! $staged && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'blueprints')) {
            $staged = true;
            BlueprintApplier::apply(new FixtureBlueprint);
        }
    });

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))
        ->toThrow(RuntimeException::class, 'another apply of it ran at the same moment');
})->with(['before either receipt' => false, 'after the other\'s intent record' => true]);
