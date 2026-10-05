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
use Kitsune\Core\Blueprints\BlueprintDefinition;
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
        'role dispatcher: renamed dispatch_lead since this blueprint wrote it; left as it is',
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
        ->toThrow(RuntimeException::class, "The receipt for [fixture] cannot be finished: {$named}. Its manifest is not "
            .'this organisation\'s record of this blueprint\'s rows, so nothing was written and the receipt is left as it '
            .'is. `kitsune:blueprint reverse fixture` clears a receipt like this one, removing it and nothing else, because '
            .'a manifest that is not this organisation\'s record names no row a reverse may remove.');

    expect(Blueprint::receiptFor('fixture')->applied_at)->toBeNull()
        ->and(EntryType::query()->where('org_id', $this->org->getKey())->where('handle', 'dispatch')->exists())->toBeFalse()
        ->and(Role::query()->exists())->toBeFalse()
        ->and($this->schema->syncedHandles)->toBe([]);
})->with(['another org\'s type' => 'type', 'another org\'s role' => 'role', 'this org\'s type of another handle' => 'handle']);

/** Nor a manifest that records nothing of what the definition declares: that would finish an apply with no rows. */
it('refuses to finish a receipt whose manifest records none of what is declared', function (): void {
    Blueprint::create(['handle' => 'fixture', 'version' => '1.0.0', 'applied_at' => null, 'manifest' => ['version' => '1.0.0']]);

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))
        /* A manifest it cannot read: the reverse clears the receipt alone, and the refusal says so. */
        ->toThrow(RuntimeException::class, 'cannot be finished: entry type dispatch is not recorded; role dispatcher is not '
            .'recorded. Its manifest is not this organisation\'s record of this blueprint\'s rows, so nothing was written '
            .'and the receipt is left as it is. `kitsune:blueprint reverse fixture` clears a receipt like this one, removing '
            .'it and nothing else');

    expect(Blueprint::receiptFor('fixture')->applied_at)->toBeNull();
});

/**
 * ⚠️ BUT A ROW THE OPERATOR REMOVED IS NOT A FORGERY. The rows are live in the admin while the finish is owed, and
 * removing one after a finish costs nothing — so removing it before one must not leave an org that no command can
 * finish or clear (the reverse now clears one, but only by removing what it can prove is this blueprint's). It is
 * reported, and the finish goes ahead without writing it again.
 */
it('finishes over a row the operator removed while the finish was owed, and says so', function (): void {
    resumeInterrupted();

    Role::query()->where('handle', 'dispatcher')->firstOrFail()->delete();

    $result = BlueprintApplier::apply(new FixtureBlueprint);

    expect($result['skipped'])->toBe([
        'rows: written by an earlier run that stopped before it finished; finished now',
        'role dispatcher: removed since this blueprint wrote it; not written again',
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

/*
 * ⚠️ A RECEIPT A REVERSE REMOVED IS NAMED AS REMOVED — ADR-039, the reverse as built. Before the reverse nothing deleted
 * a receipt, so the apply neither re-read its intent record inside the rows' transaction nor checked that its finish
 * wrote anything: a save to a deleted row writes nothing and returns true.
 */

/** Delete the receipt below Eloquent, as a reverse in another process would. */
function resumeReverseNow(): void
{
    DB::table('blueprints')->where('org_id', test()->org->getKey())->delete();
}

/** No row of the fixture's in the org — what an apply that wrote nothing leaves. */
function resumeNothingWritten(): void
{
    expect(Blueprint::query()->exists())->toBeFalse()
        ->and(EntryType::query()->where('handle', 'dispatch')->exists())->toBeFalse()
        ->and(FieldStorage::query()->where('handle', 'dispatch_code')->exists())->toBeFalse()
        ->and(Role::query()->exists())->toBeFalse()
        ->and(RolePermission::query()->withoutGlobalScopes()->exists())->toBeFalse();
}

it('stops a fresh apply whose intent record was removed before its rows, writing none of them', function (): void {
    $staged = false;
    DB::listen(function (QueryExecuted $query) use (&$staged): void {
        if (! $staged && str_starts_with($query->sql, 'insert') && str_contains($query->sql, 'blueprints')) {
            $staged = true;
            resumeReverseNow();
        }
    });

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(RuntimeException::class,
        'The apply of [fixture] stopped and wrote nothing: this organisation\'s receipt for it was removed while it ran — '
        .'`kitsune:blueprint reverse` reached it at the same moment. Run it again to apply afresh.'
    );

    expect($staged)->toBeTrue();
    resumeNothingWritten();

    /* And the run it asks for applies afresh. */
    expect(BlueprintApplier::apply(new FixtureBlueprint)['roles_created'])->toBe(['dispatcher']);
});

it('refuses to mark finished a receipt removed after its rows committed, in an apply, a merge and a finish', function (string $run): void {
    $expected = 'The apply of [fixture] committed its rows, and its receipt was removed before this run could mark it '
        .'finished — `kitsune:blueprint reverse` reached it at the same moment and reversed them. `kitsune:blueprint '
        .'status` shows where this organisation stands, and `kitsune:schema-sync --force` drops any generated column '
        .'left for a field that no longer exists.';

    match ($run) {
        'merge' => (function (): void {
            BlueprintApplier::apply(new FixtureBlueprint);
            resumeNextVersion();
        })(),
        'finish' => resumeInterrupted(),
        'apply' => null,
    };

    $this->schema->onSync = static fn () => resumeReverseNow();

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(RuntimeException::class, $expected);

    /* Nothing re-created the receipt: a plain save of `applied_at` to the deleted row wrote nothing, and said so. */
    expect(Blueprint::query()->exists())->toBeFalse();
})->with(['apply', 'merge', 'finish']);

it('names a receipt removed under a merge as removed, not as rows another apply committed', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    resumeNextVersion();

    $staged = false;
    DB::listen(function (QueryExecuted $query) use (&$staged): void {
        if (! $staged && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'blueprints')) {
            $staged = true;
            resumeReverseNow();
        }
    });

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(RuntimeException::class,
        'The apply of [fixture] stopped and wrote nothing: this organisation\'s receipt for it was removed while it ran'
    );

    expect($staged)->toBeTrue()
        ->and(FieldStorage::query()->where('handle', 'dispatch_ref')->exists())->toBeFalse();
});

/** ⚠️ BY VERSION, NOT BY `applied_at` NULL: two finishes of one owed receipt are harmless, and neither claims a removal. */
it('lets two finishes of one owed receipt both succeed', function (): void {
    resumeInterrupted();

    $inner = null;
    $this->schema->onSync = function () use (&$inner): void {
        if ($inner === null) {
            $inner = false;
            $inner = BlueprintApplier::apply(new FixtureBlueprint);
        }
    };

    $outer = BlueprintApplier::apply(new FixtureBlueprint);

    expect($inner['skipped'])->toBe(['rows: written by an earlier run that stopped before it finished; finished now'])
        ->and($outer['skipped'])->toBe(['rows: written by an earlier run that stopped before it finished; finished now'])
        ->and(Blueprint::receiptFor('fixture')->applied_at)->not->toBeNull();
});

/*
 * ⚠️ THE INTENT RECORD'S RE-READ, FOR UPDATE, REFUSES ONE ANOTHER RUN HAS MOVED — not only one a reverse removed. Before,
 * the catch caught most of these as the loser refused the winner's type; a definition the winner's rows did not collide
 * with went on to commit under a receipt naming another version (found by review).
 */
it('stops a fresh apply whose intent record another run moved before its rows, writing none of them', function (): void {
    $staged = false;
    DB::listen(function (QueryExecuted $query) use (&$staged): void {
        if (! $staged && str_starts_with($query->sql, 'insert') && str_contains($query->sql, 'blueprints')) {
            $staged = true;
            DB::table('blueprints')->update(['version' => '1.1.0']);
        }
    });

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(new RuntimeException(
        'The apply of [fixture] stopped, and this organisation\'s receipt for it now records rows this run did not '
        .'commit: another apply of it ran at the same moment, or this run\'s own commit landed as it failed. Re-run it — '
        .'it finishes what the receipt records, merges over it, or does nothing if that is done.'
    ));

    expect($staged)->toBeTrue()
        ->and(EntryType::query()->where('handle', 'dispatch')->exists())->toBeFalse()
        ->and(FieldStorage::query()->where('handle', 'dispatch_code')->exists())->toBeFalse()
        ->and(Role::query()->exists())->toBeFalse()
        ->and(Blueprint::receiptFor('fixture')->manifest)->toBeNull();
});

/**
 * ⚠️ AN INTENT RECORD ALREADY HERE TAKES THIS RUN'S VERSION UNDER ITS LOCK, NOT BEFORE IT. Saved before, a run that read it
 * while another apply committed over it wrote its version onto the other's finished receipt — 1.1.0 over a 1.0.0
 * manifest — and then stopped, leaving a receipt no merge could read (found by review).
 */
it('leaves another apply\'s finished receipt as it wrote it when an older intent record was read', function (): void {
    $theirs = Role::create(['handle' => 'dispatcher', 'name' => 'Theirs']);
    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(RuntimeException::class, 'Role [dispatcher] already exists');
    $theirs->delete();
    $winner = resumeFrozen();
    FixtureBlueprint::$version = '1.1.0';

    $staged = false;
    DB::listen(function (QueryExecuted $query) use (&$staged, $winner): void {
        if (! $staged && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'blueprints')) {
            $staged = true;
            BlueprintApplier::apply($winner);
        }
    });

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(RuntimeException::class, 'another apply of it ran at the same moment');

    $receipt = Blueprint::receiptFor('fixture');

    expect($staged)->toBeTrue()
        ->and($receipt->version)->toBe('1.0.0')
        ->and($receipt->manifest['version'])->toBe('1.0.0')
        ->and($receipt->applied_at)->not->toBeNull();
});

/** The fixture as it stands, frozen — an apply staged inside another must not see the other's version. */
function resumeFrozen(): BlueprintDefinition
{
    $definition = new FixtureBlueprint;
    $parts = [$definition->version(), $definition->entryTypes(), $definition->roles()];

    return new class(...$parts) implements BlueprintDefinition
    {
        public function __construct(private string $version, private array $types, private array $roles) {}

        public function handle(): string
        {
            return 'fixture';
        }

        public function version(): string
        {
            return $this->version;
        }

        public function entryTypes(): array
        {
            return $this->types;
        }

        public function roles(): array
        {
            return $this->roles;
        }
    };
}

/** A receipt still here at another version was moved, not reversed — and the message says so (found by review). */
it('says the receipt moved, not that it was reversed, when another run changed its version after the commit', function (): void {
    $this->schema->onSync = static fn () => DB::table('blueprints')->update(['version' => '9.9.9']);

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(new RuntimeException(
        'The apply of [fixture] committed its rows at 1.0.0, and before this run could mark them finished another run '
        .'moved this organisation\'s receipt for it to 9.9.9. Nothing was reversed. `kitsune:blueprint status` shows where '
        .'this organisation stands.'
    ));
});

/**
 * ⚠️ THE WAY OUT IS SAID AS THE REVERSE WILL TAKE IT. A manifest that only fails to record what the definition declares is
 * one the reverse trusts and reverses in full — so the finish's refusal no longer tells the operator it removes the
 * receipt and nothing else (found by review).
 */
it('names a full reverse, not a receipt-only one, when the finish refuses only what the manifest does not record', function (): void {
    resumeInterrupted();
    FixtureBlueprint::$override[] = new EntryTypeDeclaration(handle: 'notice', name: 'Notice', pluralName: 'Notices', fields: []);

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(new RuntimeException(
        'The receipt for [fixture] cannot be finished: entry type notice is not recorded. Its manifest is not this '
        .'organisation\'s record of this blueprint\'s rows, so nothing was written and the receipt is left as it is. '
        .'`kitsune:blueprint reverse fixture` removes what this receipt records this blueprint created, while nothing '
        .'holds data or authority for it, and clears the receipt — or refuses, naming what is in the way; a row it does '
        .'not record is left as it is.'
    ));
});

/** And a manifest it reads, naming another org's row, is one the reverse clears alone — so that is what it says. */
it('names a receipt-only reverse when the finish refuses another organisation\'s row in a manifest it can read', function (): void {
    $rival = Org::create(['slug' => 'rival', 'name' => 'Rival']);
    $theirs = EntryType::create(['org_id' => $rival->getKey(), 'handle' => 'dispatch', 'name' => 'Theirs', 'plural_name' => 'Theirs']);
    resumeInterrupted();
    $receipt = Blueprint::receiptFor('fixture');
    $manifest = $receipt->manifest;
    $manifest['entry_types'][0]['id'] = $theirs->getKey();
    DB::table('blueprints')->where('id', $receipt->getKey())->update(['manifest' => json_encode($manifest)]);

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(new RuntimeException(
        "The receipt for [fixture] cannot be finished: entry type id {$theirs->getKey()} is not this organisation's "
        .'dispatch. Its manifest is not this organisation\'s record of this blueprint\'s rows, so nothing was written and '
        .'the receipt is left as it is. `kitsune:blueprint reverse fixture` clears a receipt like this one, removing it '
        .'and nothing else, because a manifest that is not this organisation\'s record names no row a reverse may remove.'
    ));
});
