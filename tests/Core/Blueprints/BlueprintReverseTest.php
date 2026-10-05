<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Kitsune\Core\Auth\RegistersOrgAwareProvider;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Blueprints\Declarations\EntryTypeDeclaration;
use Kitsune\Core\Blueprints\Declarations\FieldDeclaration;
use Kitsune\Core\Blueprints\Declarations\RoleDeclaration;
use Kitsune\Core\Blueprints\FirstOrg;
use Kitsune\Core\Blueprints\FirstParty\BlogBlueprint;
use Kitsune\Core\Blueprints\FirstParty\MarketingSiteBlueprint;
use Kitsune\Core\Blueprints\OnCollision;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRelation;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\RolePermission;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Schema\SchemaManager;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\FirstOwnerUser;
use Kitsune\Core\Tests\Fixtures\FixtureBlueprint;
use Kitsune\Core\Tests\Fixtures\SchemaManagerStandIn;
use Kitsune\Core\Tests\Fixtures\TestUser;

/*
 * ADR-039's reverse, as built: a refusal that names what is in the way, not a rollback.
 *
 * ⚠️ IT REMOVES ONLY WHAT THE MANIFEST PROVES THIS BLUEPRINT CREATED, AND ONLY WHILE NOTHING RESTS ON IT. Content (live
 * or trashed, in any site), a stray revision, a holder, an Owner flag, the operator's own field or grant refuses the
 * whole reverse, every obstacle named and counted, nothing written. What the organisation took over is kept, and so is
 * storage whose lock says data existed. A receipt that records no rows, or whose manifest this org cannot trust, is
 * cleared alone. These tests hold each of those, and each from the side of whoever it protects.
 *
 * ⚠️ NO REAL DDL. The schema manager is a stand-in that records each sync and each drop: a generated column commits
 * `RefreshDatabase`'s wrapper on MySQL and MariaDB. `tests/LevelZero` asks for the real one.
 */

beforeEach(function (): void {
    FixtureBlueprint::reset();
    FixtureBlueprint::$override = reverseTypes();
    FixtureBlueprint::$roles = [reverseDispatcher()];
    config(['auth.providers.users.model' => TestUser::class]);

    $this->schema = SchemaManagerStandIn::install()->recordOnly();
    $this->org = Org::create(['slug' => 'acme', 'name' => 'Acme']);
    $this->rival = Org::create(['slug' => 'rival', 'name' => 'Rival']);

    app(Context::class)->setOrg($this->org);
});

afterEach(function (): void {
    app()->forgetInstance(SchemaManager::class);
    app(Context::class)->forget();
});

/**
 * `dispatch` — an indexed text field, a textarea and a relation targeting `bulletin` — and `bulletin`.
 *
 * @param  array<string, mixed>  $links  changes to `dispatch_links`
 * @return list<EntryTypeDeclaration>
 */
function reverseTypes(array $links = [], OnCollision $bulletin = OnCollision::Fail): array
{
    return [
        new EntryTypeDeclaration(handle: 'dispatch', name: 'Dispatch', pluralName: 'Dispatches', fields: [
            new FieldDeclaration(handle: 'dispatch_code', type: 'text', label: 'Code', piiClass: 'none', isIndexed: true, ordering: 10),
            new FieldDeclaration(handle: 'dispatch_body', type: 'textarea', label: 'Body', piiClass: 'none', ordering: 20),
            new FieldDeclaration(...array_replace([
                'handle' => 'dispatch_links',
                'type' => 'relation',
                'label' => 'Links',
                'piiClass' => 'none',
                'cardinality' => -1,
                'settings' => ['targetTypes' => ['bulletin']],
                'ordering' => 30,
            ], $links)),
        ]),
        new EntryTypeDeclaration(handle: 'bulletin', name: 'Bulletin', pluralName: 'Bulletins', fields: [
            new FieldDeclaration(handle: 'bulletin_text', type: 'text', label: 'Text', piiClass: 'none'),
        ], onCollision: $bulletin),
    ];
}

function reverseDispatcher(): RoleDeclaration
{
    return new RoleDeclaration('dispatcher', 'Dispatcher', ['dispatch' => ['view', 'update'], 'bulletin' => ['view']]);
}

/** Every table a reverse might write, in every org — the receipts as the database holds them, byte for byte. */
function reverseSnapshot(): array
{
    $rows = [];

    foreach (['entry_types', 'field_storage', 'fields', 'entry_type_availability', 'roles', 'role_permissions', 'role_user', 'audit_log', 'entries', 'entry_revisions', 'entry_relations'] as $table) {
        $rows[$table] = DB::table($table)->count();
    }

    return $rows + [
        'receipts' => DB::table('blueprints')->orderBy('id')->get(['org_id', 'handle', 'version', 'manifest', 'applied_at'])
            ->map(static fn (object $row): array => (array) $row)->all(),
    ];
}

/** The reverse's refusal of the fixture, around the items it names. */
function reverseRefusal(string $items, string $version = '1.0.0'): string
{
    return "Blueprint [fixture] cannot be reversed in this organisation: {$items}. A reverse removes only what this "
        .'blueprint created, and only while nothing holds data or authority for it and nothing this organisation added '
        .'rests on it — it never deletes content and never takes a role from anybody (ADR-039). Nothing was written, and '
        ."the receipt still says {$version}.";
}

/** A user of the org holding the role, written as the merge's tests write one. */
function reverseHolder(Org $org, Role $role): TestUser
{
    /** @var TestUser $user */
    $user = TestUser::create(['email' => 'holder'.mt_rand(1, 1_000_000_000).'@kitsune.test']);
    DB::table('org_user')->insert(['org_id' => $org->getKey(), 'user_id' => $user->getKey()]);
    DB::table('role_user')->insert(['role_id' => $role->getKey(), 'user_id' => $user->getKey()]);

    return $user;
}

function reverseSite(Org $org, string $handle): Site
{
    return Site::create(['org_id' => $org->getKey(), 'handle' => $handle, 'slug' => "{$org->slug}-{$handle}", 'name' => ucfirst($handle)]);
}

/** An entry of this org's type, saved as an editor saves one in that site — and the context left holding the org alone. */
function reverseEntry(string $type, Site $site, array $values = []): Entry
{
    app(Context::class)->setSite($site);
    $entry = Entry::create(['entry_type_id' => reverseType($type)->getKey(), 'title' => 'An entry', 'values' => $values]);
    reverseOrgOnly();

    return $entry;
}

/** The context as the command leaves it: the org, and no site. */
function reverseOrgOnly(): void
{
    $org = app(Context::class)->org();
    app(Context::class)->forget()->setOrg($org);
}

function reverseType(string $handle): EntryType
{
    return EntryType::query()->where('org_id', app(Context::class)->orgId())->where('handle', $handle)->firstOrFail();
}

function reverseRole(string $handle): Role
{
    return Role::query()->where('handle', $handle)->firstOrFail();
}

/** The receipt, rewritten below Eloquent — a forgery, or a bulk write nothing refuses for this column. */
function reverseForge(callable $change): void
{
    $receipt = Blueprint::receiptFor('fixture');

    DB::table('blueprints')->where('id', $receipt->getKey())->update(['manifest' => json_encode($change($receipt->manifest))]);
}

function reverseSet(array $manifest, string $key, mixed $value): array
{
    Arr::set($manifest, $key, $value);

    return $manifest;
}

/** A manifest without what only an org's own apply can say — its ids and its prose outcome — keys sorted, for MySQL. */
function reverseOracle(array $manifest): array
{
    unset($manifest['outcome']);

    foreach (['entry_types', 'roles'] as $key) {
        foreach ($manifest[$key] as $i => $row) {
            unset($manifest[$key][$i]['id']);
        }
    }

    $sort = static function (array $value) use (&$sort): array {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $sort($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    };

    return $sort($manifest);
}

/** One org's rows as the database holds them, ids aside — for an org another org's reverse must not touch. */
function reverseOrgRows(Org $org): array
{
    $roles = DB::table('roles')->where('org_id', $org->getKey())->pluck('id');
    $rows = static fn (string $table, string $column, mixed $value): array => DB::table($table)
        ->whereIn($column, (array) $value)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();

    return [
        $rows('entry_types', 'org_id', $org->getKey()),
        $rows('field_storage', 'org_id', $org->getKey()),
        $rows('roles', 'org_id', $org->getKey()),
        $rows('role_permissions', 'role_id', $roles->all()),
        $rows('blueprints', 'org_id', $org->getKey()),
        $rows('audit_log', 'org_id', $org->getKey()),
        DB::table('fields')->whereIn('entry_type_id', DB::table('entry_types')->where('org_id', $org->getKey())->select('id'))
            ->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
    ];
}

/** Stop the next apply after its rows commit, as a kill or a failed index sync would. */
function reverseInterrupted(): void
{
    test()->schema->throwOnce(new RuntimeException('the index sync stopped here'));

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(RuntimeException::class, 'the index sync stopped here');
}

/*
 * ── States ──────────────────────────────────────────────────────────────────────────────────────────────────────────
 */

it('refuses a blueprint never applied in this organisation, though another organisation has it', function (): void {
    app(Context::class)->setOrg($this->rival);
    BlueprintApplier::apply(new FixtureBlueprint);
    app(Context::class)->setOrg($this->org);
    $before = reverseSnapshot();

    expect(fn () => BlueprintApplier::reverse('fixture'))->toThrow(RuntimeException::class,
        'Blueprint [fixture] has not been applied in this organisation, so there is no receipt to reverse. Nothing was '
        .'written. `kitsune:blueprint status` lists every organisation\'s receipts; if an earlier reverse of it stopped '
        .'after its commit, its rows are already gone, and `kitsune:schema-sync --force` drops any generated column it '
        .'left.'
    );

    expect(reverseSnapshot())->toBe($before);
});

it('refuses with no organisation in context', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    $before = reverseSnapshot();
    app(Context::class)->forget();

    expect(fn () => BlueprintApplier::reverse('fixture'))->toThrow(RuntimeException::class,
        'Cannot reverse [fixture]: no organisation is in context, and a blueprint is reversed out of one (ADR-039). Set '
        .'it — app(Context::class)->setOrg(...) — first.'
    );

    expect(reverseSnapshot())->toBe($before);
});

/** The INTERRUPTED row `status` shows after a refused apply — cleared, and the operator's own type untouched. */
it('removes only the receipt of an apply that committed no rows, leaving the operator\'s own type exactly as it was', function (): void {
    $theirs = EntryType::create(['org_id' => $this->org->getKey(), 'handle' => 'dispatch', 'name' => 'Theirs', 'plural_name' => 'Theirs']);
    $storage = FieldStorage::create(['org_id' => $this->org->getKey(), 'handle' => 'their_note', 'type' => 'text', 'pii_class' => 'none', 'cardinality' => 1]);
    Field::create(['entry_type_id' => $theirs->getKey(), 'field_storage_id' => $storage->getKey(), 'label' => 'Note']);
    reverseEntry('dispatch', reverseSite($this->org, 'main'), ['their_note' => 'kept']);

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(RuntimeException::class, 'Entry type [dispatch] already exists');

    $receipt = Blueprint::receiptFor('fixture');
    expect($receipt->manifest)->toBeNull()->and($receipt->applied_at)->toBeNull();
    $before = reverseSnapshot();

    $result = BlueprintApplier::reverse('fixture');

    expect($result)->toBe([
        'handle' => 'fixture',
        'version' => '1.0.0',
        'outcome' => 'abandoned',
        'removed' => [],
        'kept' => [],
        'gone' => [],
        'notes' => [],
        'problems' => [],
        'dropped' => 0,
        'undropped' => [],
    ])
        ->and(Blueprint::query()->exists())->toBeFalse()
        ->and(Arr::except(reverseSnapshot(), 'receipts'))->toBe(Arr::except($before, 'receipts'));
});

it('reverses an apply whose finish is owed from its manifest, with no sync', function (): void {
    reverseInterrupted();
    expect(Blueprint::receiptFor('fixture')->applied_at)->toBeNull();

    $result = BlueprintApplier::reverse('fixture');

    expect($result['outcome'])->toBe('reversed')
        ->and($this->schema->syncedHandles)->toBe([])
        ->and($this->schema->droppedHandles)->toBe(['dispatch_code'])
        ->and($result['dropped'])->toBe(1)
        ->and(Blueprint::query()->exists())->toBeFalse()
        ->and(EntryType::query()->exists())->toBeFalse()
        ->and(Role::query()->exists())->toBeFalse();
});

it('removes every row the apply created, its receipt last, and leaves the org as it was before the apply', function (): void {
    $before = Arr::except(reverseSnapshot(), ['audit_log', 'receipts']);

    BlueprintApplier::apply(new FixtureBlueprint);
    $synced = $this->schema->syncedHandles;

    /* The operator indexes a field after the apply: the row's flag decides the drop, not the manifest's. */
    FieldStorage::query()->where('org_id', $this->org->getKey())->where('handle', 'bulletin_text')->firstOrFail()->update(['is_indexed' => true]);

    $result = BlueprintApplier::reverse('fixture');

    expect($result)->toBe([
        'handle' => 'fixture',
        'version' => '1.0.0',
        'outcome' => 'reversed',
        'removed' => [
            'entry type dispatch, with fields dispatch_code, dispatch_body, dispatch_links',
            'entry type bulletin, with field bulletin_text',
            'field storage dispatch_code',
            'field storage dispatch_body',
            'field storage dispatch_links',
            'field storage bulletin_text',
            'role dispatcher, revoking its 3 grants',
        ],
        'kept' => [],
        'gone' => [],
        'notes' => [],
        'problems' => [],
        'dropped' => 2,
        'undropped' => [],
    ])
        ->and(Arr::except(reverseSnapshot(), ['audit_log', 'receipts']))->toBe($before)
        ->and(Blueprint::query()->exists())->toBeFalse()
        ->and($this->schema->droppedHandles)->toBe(['dispatch_code', 'bulletin_text'])
        ->and($this->schema->syncedHandles)->toBe($synced);
});

/** The oracle: a reverse and a fresh apply end where a fresh apply on an org that never had it ends. */
it('ends a reverse and a fresh apply where a fresh apply ends', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    reverseType('dispatch')->update(['name' => 'Renamed by the operator']);
    BlueprintApplier::reverse('fixture');
    BlueprintApplier::apply(new FixtureBlueprint);
    $ours = Blueprint::receiptFor('fixture')->manifest;
    $types = EntryType::query()->where('org_id', $this->org->getKey())->orderBy('handle')->get(['handle', 'name', 'plural_name'])->toArray();

    app(Context::class)->setOrg($this->rival);
    BlueprintApplier::apply(new FixtureBlueprint);

    expect(reverseOracle($ours))->toBe(reverseOracle(Blueprint::receiptFor('fixture')->manifest))
        ->and($types)->toBe(EntryType::query()->where('org_id', $this->rival->getKey())->orderBy('handle')->get(['handle', 'name', 'plural_name'])->toArray())
        ->and(collect($ours['outcome']['created'])->sort()->values()->all())->toBe(collect(Blueprint::receiptFor('fixture')->manifest['outcome']['created'])->sort()->values()->all());
});

it('clears a receipt whose manifest it cannot read, and removes nothing', function (string $which, string $problem): void {
    BlueprintApplier::apply(new FixtureBlueprint);

    match ($which) {
        'version' => reverseForge(static fn (array $m): array => reverseSet($m, 'version', '0.9.0')),
        'type id' => reverseForge(static function (array $m): array {
            unset($m['entry_types'][0]['id']);

            return $m;
        }),
        'role outcome' => reverseForge(static fn (array $m): array => reverseSet($m, 'roles.0.outcome', 'pending')),
        'field outcome' => reverseForge(static fn (array $m): array => reverseSet($m, 'entry_types.0.fields.1.outcome', 'skipped')),
        'twice' => reverseForge(static function (array $m): array {
            $m['entry_types'][] = $m['entry_types'][1];

            return $m;
        }),
        'format 141' => reverseForge(static function (array $m): array {
            foreach (['entry_types', 'roles'] as $key) {
                foreach ($m[$key] as $i => $row) {
                    unset($m[$key][$i]['id'], $m[$key][$i]['outcome']);
                }
            }

            return $m;
        }),
        'null' => DB::table('blueprints')->update(['manifest' => null]),
        'role id' => reverseForge(static fn (array $m): array => reverseSet($m, 'roles.0.id', null)),
        'grants' => reverseForge(static fn (array $m): array => reverseSet($m, 'roles.0.grants', 'entry.dispatch.view')),
        'fields' => reverseForge(static fn (array $m): array => reverseSet($m, 'entry_types.0.fields', ['body' => $m['entry_types'][0]['fields'][0]])),
        'types' => reverseForge(static fn (array $m): array => reverseSet($m, 'entry_types', ['dispatch' => $m['entry_types'][0]])),
    };

    $before = Arr::except(reverseSnapshot(), 'receipts');

    $result = BlueprintApplier::reverse('fixture');

    expect($result['outcome'])->toBe('receipt-only')
        ->and($result['problems'])->toBe(explode('; ', $problem))
        ->and($result['notes'])->toBe(["manifest: not this organisation's record of what this blueprint wrote — {$problem}"])
        ->and($result['removed'])->toBe([])
        ->and($this->schema->droppedHandles)->toBe([])
        ->and(Blueprint::query()->exists())->toBeFalse()
        ->and(Arr::except(reverseSnapshot(), 'receipts'))->toBe($before);
})->with([
    'its version is not the receipt\'s' => ['version', 'it records version 0.9.0'],
    'a type with no id' => ['type id', 'entry type dispatch has no id'],
    'a role outcome no apply writes' => ['role outcome', 'role dispatcher has the outcome "pending"'],
    'a field outcome no apply writes' => ['field outcome', 'field dispatch_body on dispatch has the outcome "skipped"'],
    'a handle recorded twice' => ['twice', 'it records entry type bulletin twice'],
    '#141\'s format' => ['format 141', 'entry type dispatch has no id, outcome; entry type bulletin has no id, outcome; role dispatcher has no id, outcome'],
    'no manifest, finished' => ['null', 'it records nothing'],
    'a created role with no id' => ['role id', 'role dispatcher has no id'],
    'grants that are not a list' => ['grants', 'role dispatcher\'s grants are not a list'],
    'fields that are not a list' => ['fields', 'its fields on dispatch are not a list'],
    'types that are not a list' => ['types', 'its entry_types are not a list'],
]);

/** Asserted from the other organisation's side: a manifest naming a row this org does not hold as recorded removes nothing. */
it('clears a receipt whose manifest names a row that is not this organisation\'s, and touches no other organisation', function (string $which): void {
    app(Context::class)->setOrg($this->rival);
    BlueprintApplier::apply(new FixtureBlueprint);
    $theirType = reverseType('dispatch');
    $theirRole = reverseRole('dispatcher');
    app(Context::class)->setOrg($this->org);

    BlueprintApplier::apply(new FixtureBlueprint);
    $bulletin = reverseType('bulletin');

    [$forged, $named] = match ($which) {
        'created type' => [static fn (array $m): array => reverseSet($m, 'entry_types.0.id', $theirType->getKey()), "entry type id {$theirType->getKey()} is not this organisation's dispatch"],
        'skipped type' => [static fn (array $m): array => reverseSet(reverseSet($m, 'entry_types.0.id', $theirType->getKey()), 'entry_types.0.outcome', 'skipped'), "entry type id {$theirType->getKey()} is not this organisation's dispatch"],
        'role' => [static fn (array $m): array => reverseSet($m, 'roles.0.id', $theirRole->getKey()), "role id {$theirRole->getKey()} is not this organisation's dispatcher"],
        'handle' => [static fn (array $m): array => reverseSet($m, 'entry_types.0.id', $bulletin->getKey()), "entry type id {$bulletin->getKey()} is not this organisation's dispatch"],
    };

    reverseForge($forged);
    $before = [Arr::except(reverseSnapshot(), 'receipts'), reverseOrgRows($this->rival)];

    $result = BlueprintApplier::reverse('fixture');

    expect($result['outcome'])->toBe('receipt-only')
        ->and($result['problems'])->toBe([$named])
        ->and($result['removed'])->toBe([])
        ->and(Blueprint::receiptFor('fixture'))->toBeNull()
        ->and([Arr::except(reverseSnapshot(), 'receipts'), reverseOrgRows($this->rival)])->toBe($before);
})->with([
    'another org\'s created type' => 'created type',
    'another org\'s skipped type' => 'skipped type',
    'another org\'s role' => 'role',
    'this org\'s type of another handle' => 'handle',
]);

/** A relation storage the apply marked indexed, though it projects to nothing — the finish threw there (DL:3381). */
it('reverses an owed apply with an indexed field that projects to nothing, dropping nothing for it', function (): void {
    FixtureBlueprint::$override = reverseTypes(['isIndexed' => true]);
    reverseInterrupted();

    $result = BlueprintApplier::reverse('fixture');

    expect($result['outcome'])->toBe('reversed')
        ->and($result['undropped'])->toBe([])
        ->and($result['dropped'])->toBe(1)
        ->and($this->schema->droppedHandles)->toBe(['dispatch_code'])
        ->and(FieldStorage::query()->where('handle', 'dispatch_links')->exists())->toBeFalse();
});

/*
 * ── Blockers ────────────────────────────────────────────────────────────────────────────────────────────────────────
 * Each is asked with the org in context and no site, as the command asks it.
 */

it('refuses while a type it created has entries in any site, the trash included, and writes nothing', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    $north = reverseSite($this->org, 'north');
    $south = reverseSite($this->org, 'south');
    reverseEntry('dispatch', $north);
    $trashed = reverseEntry('dispatch', $north);
    reverseEntry('dispatch', $south);
    reverseEntry('dispatch', $south);
    app(Context::class)->setSite($north);
    $trashed->delete();
    reverseOrgOnly();
    $before = reverseSnapshot();

    expect(fn () => BlueprintApplier::reverse('fixture'))->toThrow(RuntimeException::class, reverseRefusal(
        'entry type dispatch still has 4 entries, 1 of them in the trash — delete the rest, then empty the trash with '
        .'Delete forever'
    ));

    expect(reverseSnapshot())->toBe($before)
        ->and($this->schema->droppedHandles)->toBe([]);
});

it('names what is in the trash, and what is not', function (int $live, int $trashed, string $item): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    $site = reverseSite($this->org, 'main');

    for ($i = 0; $i < $live + $trashed; $i++) {
        $entry = reverseEntry('dispatch', $site);

        if ($i >= $live) {
            app(Context::class)->setSite($site);
            $entry->delete();
            reverseOrgOnly();
        }
    }

    expect(fn () => BlueprintApplier::reverse('fixture'))->toThrow(RuntimeException::class, reverseRefusal($item));
})->with([
    'one, live' => [1, 0, 'entry type dispatch still has 1 entry — delete it, then empty the trash with Delete forever'],
    'two, live' => [2, 0, 'entry type dispatch still has 2 entries — delete them, then empty the trash with Delete forever'],
    'one, trashed' => [0, 1, 'entry type dispatch still has 1 entry in the trash — empty the trash with Delete forever'],
    'two, trashed' => [0, 2, 'entry type dispatch still has 2 entries in the trash — empty the trash with Delete forever'],
]);

/** Delete forever takes an entry's revisions; it cannot take those of an entry that moved to another type. */
it('refuses on revisions of entries since moved to another type, and counts no revision of an entry still there', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    EntryType::create(['org_id' => $this->org->getKey(), 'handle' => 'notice', 'name' => 'Notice', 'plural_name' => 'Notices']);
    $site = reverseSite($this->org, 'main');
    $moved = reverseEntry('dispatch', $site);
    app(Context::class)->setSite($site);
    $moved->update(['entry_type_id' => reverseType('notice')->getKey()]);
    reverseOrgOnly();
    $stray = 'entry type dispatch: 1 revision of entries since moved to another type still records it — nothing in '
        .'Kitsune deletes a revision (ADR-020), and they go only when those entries are deleted forever';

    expect(fn () => BlueprintApplier::reverse('fixture'))->toThrow(RuntimeException::class, reverseRefusal($stray));

    /* An entry still of the type, with its own revision: counted as an entry, never as a stray revision. */
    reverseEntry('dispatch', $site);

    expect(fn () => BlueprintApplier::reverse('fixture'))->toThrow(RuntimeException::class, reverseRefusal(
        'entry type dispatch still has 1 entry — delete it, then empty the trash with Delete forever; '.$stray
    ));
});

it('refuses while a type it created carries a field the operator added, and goes ahead once it is removed', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    $theirs = FieldStorage::create(['org_id' => $this->org->getKey(), 'handle' => 'dispatch_extra', 'type' => 'text', 'pii_class' => 'none', 'cardinality' => 1]);
    $field = Field::create(['entry_type_id' => reverseType('dispatch')->getKey(), 'field_storage_id' => $theirs->getKey(), 'label' => 'Extra']);
    $before = reverseSnapshot();

    expect(fn () => BlueprintApplier::reverse('fixture'))->toThrow(RuntimeException::class, reverseRefusal(
        'entry type dispatch carries field dispatch_extra, which this blueprint did not write — remove it from dispatch '
        .'in the admin first'
    ));

    expect(reverseSnapshot())->toBe($before);

    $field->delete();

    expect(BlueprintApplier::reverse('fixture')['outcome'])->toBe('reversed')
        ->and(FieldStorage::query()->whereKey($theirs->getKey())->exists())->toBeTrue();
});

it('refuses while a type it adopted under Skip carries a field it attached, and then keeps the type', function (): void {
    $theirs = EntryType::create(['org_id' => $this->org->getKey(), 'handle' => 'bulletin', 'name' => 'Theirs', 'plural_name' => 'Theirs']);
    $storage = FieldStorage::create(['org_id' => $this->org->getKey(), 'handle' => 'their_note', 'type' => 'text', 'pii_class' => 'none', 'cardinality' => 1]);
    Field::create(['entry_type_id' => $theirs->getKey(), 'field_storage_id' => $storage->getKey(), 'label' => 'Note']);
    FixtureBlueprint::$override = reverseTypes(bulletin: OnCollision::Skip);
    FixtureBlueprint::$roles = [new RoleDeclaration('dispatcher', 'Dispatcher', ['dispatch' => ['view']])];
    BlueprintApplier::apply(new FixtureBlueprint);
    $before = reverseSnapshot();

    expect(fn () => BlueprintApplier::reverse('fixture'))->toThrow(RuntimeException::class, reverseRefusal(
        'entry type bulletin was this organisation\'s before this blueprint adopted it (onCollision: skip), and a '
        .'reverse removes nothing from a type it did not create — remove field bulletin_text from it in the admin first'
    ));

    expect(reverseSnapshot())->toBe($before);

    Field::query()->where('entry_type_id', $theirs->getKey())->whereIn('field_storage_id', FieldStorage::query()->select('id')->where('handle', 'bulletin_text'))->firstOrFail()->delete();
    $kept = EntryType::query()->whereKey($theirs->getKey())->firstOrFail()->toArray();

    $result = BlueprintApplier::reverse('fixture');

    expect($result['kept'])->toBe(['entry type bulletin: this organisation\'s before this blueprint adopted it (onCollision: skip)'])
        ->and($result['removed'])->toContain('field storage bulletin_text')
        ->and(EntryType::query()->whereKey($theirs->getKey())->firstOrFail()->toArray())->toBe($kept)
        ->and(Field::query()->where('entry_type_id', $theirs->getKey())->count())->toBe(1)
        ->and(FieldStorage::query()->whereKey($storage->getKey())->exists())->toBeTrue();
});

it('refuses while anybody holds a role it created, taking it from nobody', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    reverseHolder($this->org, reverseRole('dispatcher'));
    reverseHolder($this->org, reverseRole('dispatcher'));
    $before = reverseSnapshot();

    expect(fn () => BlueprintApplier::reverse('fixture'))->toThrow(RuntimeException::class, reverseRefusal(
        'role dispatcher is held by 2 accounts — an owner unassigns it under Roles first, which is audited (ADR-033)'
    ));

    expect(reverseSnapshot())->toBe($before);
});

it('refuses a role it created that now has Owner turned on', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    reverseRole('dispatcher')->update(['is_owner' => true]);
    $before = reverseSnapshot();

    expect(fn () => BlueprintApplier::reverse('fixture'))->toThrow(RuntimeException::class, reverseRefusal(
        'role dispatcher has Owner turned on, and no blueprint creates an owner role — turn it off under Roles first, or '
        .'rename the role to keep it as your own'
    ));

    expect(reverseSnapshot())->toBe($before);
});

it('refuses a role holding a grant it did not make, and revokes only what is left of its own', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    $role = reverseRole('dispatcher');
    $role->grant('entry.dispatch.delete');
    $role->grant('entry.bulletin.update');
    $before = reverseSnapshot();

    expect(fn () => BlueprintApplier::reverse('fixture'))->toThrow(RuntimeException::class, reverseRefusal(
        'role dispatcher holds entry.bulletin.update, entry.dispatch.delete, which this blueprint did not grant — revoke '
        .'them under Roles first, or rename the role to keep it as your own'
    ));

    expect(reverseSnapshot())->toBe($before);

    $role->revoke('entry.dispatch.delete');
    $role->revoke('entry.bulletin.update');
    $role->revoke('entry.dispatch.update');
    $audited = AuditLog::query()->where('action', 'role.revoked')->count();

    $result = BlueprintApplier::reverse('fixture');

    expect($result['removed'])->toContain('role dispatcher, revoking its 2 grants')
        ->and(AuditLog::query()->where('action', 'role.revoked')->count() - $audited)->toBe(2);
});

it('names every obstacle in one refusal, in order', function (): void {
    EntryType::create(['org_id' => $this->org->getKey(), 'handle' => 'bulletin', 'name' => 'Theirs', 'plural_name' => 'Theirs']);
    EntryType::create(['org_id' => $this->org->getKey(), 'handle' => 'notice', 'name' => 'Notice', 'plural_name' => 'Notices']);
    FixtureBlueprint::$override = reverseTypes(bulletin: OnCollision::Skip);
    FixtureBlueprint::$roles = [new RoleDeclaration('dispatcher', 'Dispatcher', ['dispatch' => ['view']])];
    BlueprintApplier::apply(new FixtureBlueprint);

    $site = reverseSite($this->org, 'main');
    $moved = reverseEntry('dispatch', $site);
    app(Context::class)->setSite($site);
    $moved->update(['entry_type_id' => reverseType('notice')->getKey()]);
    reverseOrgOnly();
    reverseEntry('dispatch', $site);
    $extra = FieldStorage::create(['org_id' => $this->org->getKey(), 'handle' => 'dispatch_extra', 'type' => 'text', 'pii_class' => 'none', 'cardinality' => 1]);
    Field::create(['entry_type_id' => reverseType('dispatch')->getKey(), 'field_storage_id' => $extra->getKey(), 'label' => 'Extra']);
    $role = reverseRole('dispatcher');
    reverseHolder($this->org, $role);
    $role->update(['is_owner' => true]);
    $role->grant('entry.notice.view');
    $before = reverseSnapshot();

    expect(fn () => BlueprintApplier::reverse('fixture'))->toThrow(RuntimeException::class, reverseRefusal(
        'entry type dispatch still has 1 entry — delete it, then empty the trash with Delete forever; entry type '
        .'dispatch: 1 revision of entries since moved to another type still records it — nothing in Kitsune deletes a '
        .'revision (ADR-020), and they go only when those entries are deleted forever; entry type dispatch carries field '
        .'dispatch_extra, which this blueprint did not write — remove it from dispatch in the admin first; entry type '
        .'bulletin was this organisation\'s before this blueprint adopted it (onCollision: skip), and a reverse removes '
        .'nothing from a type it did not create — remove field bulletin_text from it in the admin first; role dispatcher '
        .'is held by 1 account — an owner unassigns it under Roles first, which is audited (ADR-033); role dispatcher '
        .'has Owner turned on, and no blueprint creates an owner role — turn it off under Roles first, or rename the role '
        .'to keep it as your own; role dispatcher holds entry.notice.view, which this blueprint did not grant — revoke it '
        .'under Roles first, or rename the role to keep it as your own'
    ));

    expect(reverseSnapshot())->toBe($before);
});

/*
 * ── What it keeps, and what it says ─────────────────────────────────────────────────────────────────────────────────
 */

it('keeps a role it skipped and one the operator renamed, and reports what the operator removed', function (): void {
    $crier = Role::create(['handle' => 'crier', 'name' => 'Theirs']);
    $crier->grant('entry.*.view');
    reverseHolder($this->org, $crier);
    FixtureBlueprint::$roles = [
        reverseDispatcher(),
        new RoleDeclaration('crier', 'Crier', ['dispatch' => ['view']], OnCollision::Skip),
        new RoleDeclaration('herald', 'Herald', ['bulletin' => ['view']]),
    ];
    BlueprintApplier::apply(new FixtureBlueprint);
    $crierRows = reverseOrgRows($this->org)[2];
    reverseRole('dispatcher')->update(['handle' => 'dispatch_lead']);
    reverseRole('herald')->delete();
    $lead = reverseRole('dispatch_lead');
    $grants = RolePermission::query()->whereIn('role_id', [$crier->getKey(), $lead->getKey()])->orderBy('id')->get()->toArray();
    $holders = DB::table('role_user')->orderBy('user_id')->get()->map(static fn (object $row): array => (array) $row)->all();
    reverseType('bulletin')->delete();

    $result = BlueprintApplier::reverse('fixture');

    expect($result['removed'])->toBe([
        'entry type dispatch, with fields dispatch_code, dispatch_body, dispatch_links',
        'field storage dispatch_code',
        'field storage dispatch_body',
        'field storage dispatch_links',
        'field storage bulletin_text',
    ])
        ->and($result['kept'])->toBe([
            'role dispatcher: renamed dispatch_lead since this blueprint wrote it, so it is this organisation\'s; left as '
            .'it is, with its grants',
            'role crier: this organisation\'s before this blueprint was applied (onCollision: skip)',
        ])
        ->and($result['gone'])->toBe([
            'entry type bulletin: removed since this blueprint wrote it',
            'role herald: removed since this blueprint wrote it',
        ])
        ->and(RolePermission::query()->whereIn('role_id', [$crier->getKey(), $lead->getKey()])->orderBy('id')->get()->toArray())->toBe($grants)
        ->and(DB::table('role_user')->orderBy('user_id')->get()->map(static fn (object $row): array => (array) $row)->all())->toBe($holders)
        ->and(Role::query()->orderBy('handle')->pluck('handle')->all())->toBe(['crier', 'dispatch_lead'])
        ->and(collect(reverseOrgRows($this->org)[2])->firstWhere('handle', 'crier'))->toBe(collect($crierRows)->firstWhere('handle', 'crier'));
});

it('keeps storage it adopted, storage another type uses, storage relation rows name, and locked storage', function (): void {
    /* Adopted: the operator's own, identical to what the blueprint declares. */
    $adopted = FieldStorage::create(['org_id' => $this->org->getKey(), 'handle' => 'dispatch_body', 'type' => 'textarea', 'pii_class' => 'none', 'cardinality' => 1]);
    FixtureBlueprint::$override = reverseTypes(['settings' => []]);
    BlueprintApplier::apply(new FixtureBlueprint);
    $notice = EntryType::create(['org_id' => $this->org->getKey(), 'handle' => 'notice', 'name' => 'Notice', 'plural_name' => 'Notices']);
    $site = reverseSite($this->org, 'main');

    /* Used: the operator attaches the blueprint's storage to a type of their own. */
    Field::create(['entry_type_id' => $notice->getKey(), 'field_storage_id' => FieldStorage::query()->where('handle', 'bulletin_text')->value('id'), 'label' => 'Text']);

    /* Named by a relation row of an entry of another type — the storage is the org's, so the pivot is allowed. */
    $from = reverseEntry('notice', $site);
    $to = reverseEntry('notice', $site);
    app(Context::class)->setSite($site);
    EntryRelation::create(['org_id' => $this->org->getKey(), 'source_entry_id' => $from->getKey(), 'target_entry_id' => $to->getKey(), 'field_storage_id' => FieldStorage::query()->where('handle', 'dispatch_links')->value('id'), 'ordering' => 0]);
    reverseOrgOnly();

    /* Locked: a dispatch held a code, then went for good. */
    $gone = reverseEntry('dispatch', $site, ['dispatch_code' => 'D-1']);
    app(Context::class)->setSite($site);
    $gone->delete();
    $gone->forceDelete();
    reverseOrgOnly();

    $result = BlueprintApplier::reverse('fixture');

    expect($result['removed'])->toBe([
        'entry type dispatch, with fields dispatch_code, dispatch_body, dispatch_links',
        'entry type bulletin, with field bulletin_text',
        'role dispatcher, revoking its 3 grants',
    ])
        ->and($result['kept'])->toBe([
            'field storage dispatch_code: locked, because entries once held data for it (ADR-006) — a later apply adopts it as it is',
            'field storage dispatch_links: 1 relation row still points at it',
            'field storage bulletin_text: entry type notice uses it',
            'field storage dispatch_body: adopted, not created, by this blueprint',
        ])
        ->and($result['dropped'])->toBe(0)
        ->and(FieldStorage::query()->where('org_id', $this->org->getKey())->orderBy('handle')->pluck('handle')->all())
        ->toBe(['bulletin_text', 'dispatch_body', 'dispatch_code', 'dispatch_links'])
        ->and(FieldStorage::query()->whereKey($adopted->getKey())->exists())->toBeTrue();

    /* And the next apply adopts every one of them as it is. */
    $again = BlueprintApplier::apply(new FixtureBlueprint);

    expect($again['created'])->not->toContain('field storage dispatch_code')
        ->and($again['adopted'])->toContain('field storage dispatch_code', 'field storage dispatch_body');
});

it('notes a grant and a relation that name a handle no type will hold, and changes neither', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    $lead = Role::create(['handle' => 'content_lead', 'name' => 'Content lead']);
    $lead->grant('entry.dispatch.update');
    $lead->grant('entry.dispatch.view');
    $lead->grant('entry.*.publish');
    $refs = FieldStorage::create(['org_id' => $this->org->getKey(), 'handle' => 'notice_refs', 'type' => 'relation', 'pii_class' => 'none', 'cardinality' => -1, 'settings' => ['targetTypes' => ['dispatch', 'other']]]);
    $settings = $refs->fresh()->settings;

    $result = BlueprintApplier::reverse('fixture');

    expect($result['notes'])->toBe([
        'role content_lead holds entry.dispatch.update, entry.dispatch.view on dispatch, which this reverse removes: they '
        .'stay, and reach whatever type takes dispatch next — a later apply of fixture included',
        'field storage notice_refs targets dispatch, which this reverse removes: it stays, and targets whatever type '
        .'takes dispatch next',
    ])
        ->and(RolePermission::query()->where('role_id', $lead->getKey())->orderBy('permission')->pluck('permission')->all())
        ->toBe(['entry.*.publish', 'entry.dispatch.update', 'entry.dispatch.view'])
        ->and($refs->fresh()->settings)->toBe($settings);
});

it('notes a handle the operator removed, and none a type holds again', function (bool $reused): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    $lead = Role::create(['handle' => 'content_lead', 'name' => 'Content lead']);
    $lead->grant('entry.bulletin.view');
    reverseType('bulletin')->delete();

    if ($reused) {
        EntryType::create(['org_id' => $this->org->getKey(), 'handle' => 'bulletin', 'name' => 'Theirs', 'plural_name' => 'Theirs']);
    }

    expect(BlueprintApplier::reverse('fixture')['notes'])->toBe($reused ? [] : [
        'role content_lead holds entry.bulletin.view on bulletin, which this organisation has removed: it stays, and '
        .'reaches whatever type takes bulletin next — a later apply of fixture included',
    ]);
})->with(['removed' => false, 'reused by the operator' => true]);

/** `_` is a `LIKE` wildcard: `entry.my_type.%` matches `entry.myxtype.view`. */
it('matches grants by their exact strings', function (): void {
    FixtureBlueprint::$override = [new EntryTypeDeclaration(handle: 'my_type', name: 'Mine', pluralName: 'Mine', fields: [])];
    FixtureBlueprint::$roles = [];
    BlueprintApplier::apply(new FixtureBlueprint);
    EntryType::create(['org_id' => $this->org->getKey(), 'handle' => 'myxtype', 'name' => 'X', 'plural_name' => 'X']);
    $lead = Role::create(['handle' => 'content_lead', 'name' => 'Content lead']);
    $lead->grant('entry.my_type.view');
    $lead->grant('entry.myxtype.view');
    reverseType('myxtype')->delete();

    expect(BlueprintApplier::reverse('fixture')['notes'])->toBe([
        'role content_lead holds entry.my_type.view on my_type, which this reverse removes: it stays, and reaches '
        .'whatever type takes my_type next — a later apply of fixture included',
    ]);
});

/*
 * ── Audit ───────────────────────────────────────────────────────────────────────────────────────────────────────────
 */

/** ADR-033: authority changes on the first grant, recorded — so a grant that stops existing is recorded too. */
it('records one role.revoked per grant of each role it removes, and nothing else', function (): void {
    FixtureBlueprint::$roles = [reverseDispatcher(), new RoleDeclaration('crier', 'Crier', ['bulletin' => ['view', 'update']])];
    BlueprintApplier::apply(new FixtureBlueprint);
    $last = (int) AuditLog::query()->max('id');

    BlueprintApplier::reverse('fixture');

    $rows = AuditLog::query()->where('id', '>', $last)->orderBy('id')->get();

    expect($rows->pluck('action')->all())->toBe(array_fill(0, 5, 'role.revoked'))
        ->and($rows->pluck('org_id')->unique()->all())->toBe([$this->org->getKey()])
        ->and($rows->pluck('site_id')->unique()->all())->toBe([null])
        ->and($rows->pluck('actor_id')->unique()->all())->toBe([null]);
});

/*
 * ── Tenancy and coexistence ─────────────────────────────────────────────────────────────────────────────────────────
 */

it('leaves another organisation\'s same blueprint exactly as it was', function (): void {
    app(Context::class)->setOrg($this->rival);
    BlueprintApplier::apply(new FixtureBlueprint);
    app(Context::class)->setOrg($this->org);
    BlueprintApplier::apply(new FixtureBlueprint);
    $theirs = reverseOrgRows($this->rival);

    expect(BlueprintApplier::reverse('fixture')['outcome'])->toBe('reversed')
        ->and(reverseOrgRows($this->rival))->toBe($theirs);
});

it('reverses Blog beside the Marketing Site without touching it, and then the Marketing Site', function (): void {
    BlueprintApplier::apply(new BlogBlueprint);
    BlueprintApplier::apply(new MarketingSiteBlueprint);
    $marketing = static fn (): array => [
        DB::table('entry_types')->where('handle', 'page')->get()->map(static fn (object $row): array => (array) $row)->all(),
        DB::table('field_storage')->where('handle', 'like', 'page%')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
        DB::table('roles')->where('handle', 'like', 'marketing%')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
        DB::table('role_permissions')->whereIn('role_id', DB::table('roles')->where('handle', 'like', 'marketing%')->select('id'))->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
        DB::table('blueprints')->where('handle', 'marketing-site')->get()->map(static fn (object $row): array => (array) $row)->all(),
    ];
    $before = $marketing();

    $blog = BlueprintApplier::reverse('blog');

    expect($blog['outcome'])->toBe('reversed')
        ->and($blog['removed'])->toContain('entry type post, with fields post_body, post_excerpt, post_tags', 'role blog_editor, revoking its 10 grants', 'role blog_writer, revoking its 4 grants')
        ->and($marketing())->toBe($before)
        ->and(BlueprintApplier::reverse('marketing-site')['outcome'])->toBe('reversed')
        ->and(EntryType::query()->where('org_id', $this->org->getKey())->exists())->toBeFalse()
        ->and(Role::query()->exists())->toBeFalse();
});

it('leaves the first owner, their organisation and their role exactly as they were', function (): void {
    Site::query()->withoutGlobalScopes()->forceDelete();
    Org::query()->withoutGlobalScopes()->forceDelete();
    config(['auth.providers.users.model' => FirstOwnerUser::class]);
    FirstOwnerUser::reset();
    RegistersOrgAwareProvider::on($this->app);
    $org = FirstOrg::createWithOwner('first', null, null, 'en', 'owner@kitsune.test', Hash::make('correct-horse-battery-staple'));
    app(Context::class)->setOrg($org);
    $owner = static fn (): array => [
        DB::table('orgs')->get()->map(static fn (object $row): array => (array) $row)->all(),
        DB::table('sites')->get()->map(static fn (object $row): array => (array) $row)->all(),
        DB::table('users')->get()->map(static fn (object $row): array => (array) $row)->all(),
        DB::table('org_user')->get()->map(static fn (object $row): array => (array) $row)->all(),
        DB::table('roles')->get()->map(static fn (object $row): array => (array) $row)->all(),
        DB::table('role_user')->get()->map(static fn (object $row): array => (array) $row)->all(),
        DB::table('audit_log')->where('action', 'role.owner_assigned')->get()->map(static fn (object $row): array => (array) $row)->all(),
    ];
    $before = $owner();

    BlueprintApplier::apply(new BlogBlueprint);

    expect(BlueprintApplier::reverse('blog')['outcome'])->toBe('reversed')
        ->and($owner())->toBe($before)
        ->and(fn () => FirstOrg::refuseUnlessEmpty(FirstOrg::ownerModel()))->toThrow(RuntimeException::class);

    FirstOwnerUser::reset();
});

/*
 * ── The transaction ─────────────────────────────────────────────────────────────────────────────────────────────────
 */

it('rolls every write back with the receipt\'s, revocations included, and says it stopped', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    $before = reverseSnapshot();
    Blueprint::deleting(static function (): never {
        throw new RuntimeException('The receipt would not go.');
    });

    expect(fn () => BlueprintApplier::reverse('fixture'))->toThrow(RuntimeException::class,
        'The reverse of [fixture] stopped: The receipt would not go. Nothing was written, and the receipt still says 1.0.0.'
    );

    expect(reverseSnapshot())->toBe($before)
        ->and($this->schema->droppedHandles)->toBe([]);
});

/** The model guards are a second, independent check: one refusing inside the transaction undoes it all. */
it('wraps a model guard\'s own refusal, with nothing written', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    $site = reverseSite($this->org, 'main');
    $before = reverseSnapshot();

    $staged = false;
    DB::listen(function (QueryExecuted $query) use (&$staged, $site): void {
        if (! $staged && preg_match('/^select [`"]entry_types[`"]\.\*/', $query->sql) === 1) {
            $staged = true;
            reverseEntry('dispatch', $site);
        }
    });

    expect(fn () => BlueprintApplier::reverse('fixture'))->toThrow(RuntimeException::class,
        'The reverse of [fixture] stopped: Entry type [dispatch] still has 1 entry, and the database would delete them '
        .'by cascade'
    );

    expect($staged)->toBeTrue()
        ->and(reverseSnapshot())->toBe($before);
});

it('stops when the receipt moves between its read and its lock', function (string $change): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    $after = null;

    $staged = false;
    DB::listen(function (QueryExecuted $query) use (&$staged, &$after, $change): void {
        if (! $staged && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'blueprints')) {
            $staged = true;

            if ($change === 'removed') {
                DB::table('blueprints')->delete();
            } else {
                FixtureBlueprint::$version = '1.1.0';
                BlueprintApplier::apply(new FixtureBlueprint);
            }

            $after = reverseSnapshot();
        }
    });

    expect(fn () => BlueprintApplier::reverse('fixture'))->toThrow(RuntimeException::class,
        'The reverse of [fixture] stopped: this organisation\'s receipt for it changed while it ran — an apply, a merge or '
        .'another reverse reached it at the same moment. Run it again; it reverses whatever the receipt then records.'
    );

    expect($staged)->toBeTrue()
        ->and(reverseSnapshot())->toBe($after);
})->with(['removed', 'merged']);

/*
 * ── The scope trap, pinned (AGENTS §15) ─────────────────────────────────────────────────────────────────────────────
 * ADR-039's Decision quoted `person`'s docblock: `withoutScopeBecause()` counts 0. Re-measured, it does not — the builder
 * it hands its callback keeps soft deletes and drops the site and org scopes — and the reverse counts with
 * `withoutGlobalScopes()`, which counts the trash without being asked.
 */
it('counts an entry in a site no context names only past every scope', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    $site = reverseSite($this->org, 'elsewhere');
    reverseEntry('dispatch', $site);
    $trashed = reverseEntry('dispatch', $site);
    app(Context::class)->setSite($site);
    $trashed->delete();
    $type = reverseType('dispatch')->getKey();
    app(Context::class)->forget();

    $why = 'pinning the counts ADR-039 quotes';

    expect(Entry::query()->where('entry_type_id', $type)->count())->toBe(0)
        ->and(Entry::withoutScopeBecause($why, fn ($query) => $query->where('entry_type_id', $type)->count()))->toBe(1)
        ->and(Entry::withoutScopeBecause($why, fn ($query) => $query->withTrashed()->where('entry_type_id', $type)->count()))->toBe(2)
        ->and(Entry::withoutScopeBecause($why, fn () => Entry::query()->where('entry_type_id', $type)->count()))->toBe(0)
        ->and(Entry::query()->withoutGlobalScopes()->where('entry_type_id', $type)->count())->toBe(2)
        ->and(DB::table('entries')->where('entry_type_id', $type)->count())->toBe(2);
});
