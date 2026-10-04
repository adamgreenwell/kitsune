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
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Blueprints\BlueprintDefinition;
use Kitsune\Core\Blueprints\Declarations\EntryTypeDeclaration;
use Kitsune\Core\Blueprints\Declarations\FieldDeclaration;
use Kitsune\Core\Blueprints\Declarations\RoleDeclaration;
use Kitsune\Core\Blueprints\OnCollision;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\RolePermission;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Schema\SchemaManager;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\FixtureBlueprint;
use Kitsune\Core\Tests\Fixtures\SchemaManagerStandIn;
use Kitsune\Core\Tests\Fixtures\TestUser;

/*
 * ADR-039's additive merge: a later version over a finished apply, as built.
 *
 * ⚠️ A NEW VERSION IS THE OLD ONE PLUS ADDITIONS. What the receipt records is compared with the definition before
 * anything is written, and any change to it or removal from it is refused, every difference named — so what a merge
 * writes can only be new types, new fields on the types this blueprint created, and new roles. These tests hold both
 * halves: each addition lands, and each change, removal and forgery is refused with nothing written.
 *
 * ⚠️ THE ORACLE: an upgraded org ends where a fresh apply of the new version ends — the same rows, and a manifest equal
 * once ids and the prose outcome are set aside.
 *
 * ⚠️ NO REAL DDL. The schema manager is a stand-in that records each sync: a generated column commits `RefreshDatabase`'s
 * wrapper on MySQL and MariaDB. `tests/LevelZero` asks for the real one.
 */

beforeEach(function (): void {
    FixtureBlueprint::reset();
    FixtureBlueprint::$override = mergeTypes();
    FixtureBlueprint::$roles = [mergeDispatcher()];
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

/** `dispatch_body` as 1.0.0 declares it, with any argument changed. */
function mergeBody(array $changes = []): FieldDeclaration
{
    return new FieldDeclaration(...array_replace(
        ['handle' => 'dispatch_body', 'type' => 'textarea', 'label' => 'Body', 'piiClass' => 'none', 'ordering' => 10],
        $changes,
    ));
}

/** `dispatch_code`: indexed, with settings of more than one key. */
function mergeCode(array $changes = []): FieldDeclaration
{
    return new FieldDeclaration(...array_replace([
        'handle' => 'dispatch_code',
        'type' => 'text',
        'label' => 'Code',
        'piiClass' => 'none',
        'settings' => ['maxLength' => 32, 'pattern' => null],
        'isIndexed' => true,
        'ordering' => 20,
    ], $changes));
}

/** A field nothing recorded: what a version adds. */
function mergeNew(string $handle = 'dispatch_note', array $changes = []): FieldDeclaration
{
    return new FieldDeclaration(...array_replace(
        ['handle' => $handle, 'type' => 'textarea', 'label' => 'Note', 'piiClass' => 'none', 'ordering' => 30],
        $changes,
    ));
}

/** `dispatch` as 1.0.0 declares it, with any argument changed. @param list<FieldDeclaration>|null $fields */
function mergeDispatch(array $changes = [], ?array $fields = null): EntryTypeDeclaration
{
    return new EntryTypeDeclaration(...array_replace([
        'handle' => 'dispatch',
        'name' => 'Dispatch',
        'pluralName' => 'Dispatches',
        'fields' => $fields ?? [mergeBody(), mergeCode()],
        'icon' => 'heroicon-o-bolt',
        'description' => 'Dispatches.',
        'ordering' => 3,
    ], $changes));
}

/** @param list<FieldDeclaration>|null $fields */
function mergeBulletin(array $changes = [], ?array $fields = null): EntryTypeDeclaration
{
    return new EntryTypeDeclaration(...array_replace([
        'handle' => 'bulletin',
        'name' => 'Bulletin',
        'pluralName' => 'Bulletins',
        'fields' => $fields ?? [new FieldDeclaration(handle: 'bulletin_text', type: 'textarea', label: 'Text', piiClass: 'none')],
    ], $changes));
}

/** 1.0.0's types, with `dispatch` replaced when given. @return list<EntryTypeDeclaration> */
function mergeTypes(?EntryTypeDeclaration $dispatch = null): array
{
    return [$dispatch ?? mergeDispatch(), mergeBulletin()];
}

function mergeDispatcher(array $changes = []): RoleDeclaration
{
    return new RoleDeclaration(...array_replace(
        ['handle' => 'dispatcher', 'name' => 'Dispatcher', 'grants' => ['dispatch' => ['view', 'update']]],
        $changes,
    ));
}

/** Version 1.1.0 of the fixture: 1.0.0 with these types and roles. @param list<EntryTypeDeclaration>|null $types */
function mergeNext(?array $types = null, array $roles = [], string $version = '1.1.0'): void
{
    FixtureBlueprint::$version = $version;
    FixtureBlueprint::$override = $types ?? FixtureBlueprint::$override;
    FixtureBlueprint::$roles = [...FixtureBlueprint::$roles, ...$roles];
}

/** The definition as it stands, frozen — for an apply staged inside another, which must not see it change. */
function mergeFrozen(): BlueprintDefinition
{
    $definition = new FixtureBlueprint;
    $version = $definition->version();
    $types = $definition->entryTypes();
    $roles = $definition->roles();

    return new class($version, $types, $roles) implements BlueprintDefinition
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

/** Everything a merge might write, in every org — the receipts as the database holds them, byte for byte. */
function mergeSnapshot(): array
{
    $counts = [];

    foreach (['entry_types', 'field_storage', 'fields', 'roles', 'role_permissions', 'role_user', 'audit_log'] as $table) {
        $counts[$table] = DB::table($table)->count();
    }

    return $counts + [
        'receipts' => DB::table('blueprints')->orderBy('id')->get(['org_id', 'handle', 'version', 'manifest', 'applied_at'])
            ->map(static fn (object $row): array => (array) $row)->all(),
    ];
}

/** A manifest without what only an org's own apply can say: its ids and its prose outcome — keys sorted, for MySQL. */
function mergeOracle(array $manifest): array
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

/** The refusal of a merge from 1.0.0, around the items it names. */
function mergeRefusal(string $items, string $to = '1.1.0', string $from = '1.0.0'): string
{
    return "Blueprint [fixture] cannot be merged from {$from} to {$to}: a merge adds what {$to} declares and {$from} did "
        ."not, and never changes or removes what {$from} recorded — {$items}. Nothing was written, and the receipt still "
        ."says {$from}.";
}

/** A user in the org holding the role. */
function mergeHolder(Org $org, Role $role): TestUser
{
    /** @var TestUser $user */
    $user = TestUser::create(['email' => 'holder'.mt_rand(1, 1_000_000_000).'@kitsune.test']);
    DB::table('org_user')->insert(['org_id' => $org->getKey(), 'user_id' => $user->getKey()]);
    DB::table('role_user')->insert(['role_id' => $role->getKey(), 'user_id' => $user->getKey()]);

    return $user;
}

/** A page's worth of data in `dispatch`, saved as an editor saves one — which arms the lock on what it holds. */
function mergeWriteEntry(Org $org, array $values): void
{
    $type = EntryType::query()->where('org_id', $org->getKey())->where('handle', 'dispatch')->firstOrFail();
    $site = Site::create(['org_id' => $org->getKey(), 'handle' => 'main', 'slug' => $org->slug.'-main', 'name' => 'Main']);
    app(Context::class)->setSite($site);
    Entry::create(['entry_type_id' => $type->getKey(), 'title' => 'First', 'values' => $values]);
    app(Context::class)->setOrg($org);
}

/** The receipt, rewritten below Eloquent — a forgery, or a bulk write nothing refuses for these two columns. */
function mergeForge(callable $change): void
{
    $receipt = Blueprint::receiptFor('fixture');
    $manifest = $change($receipt->manifest);

    DB::table('blueprints')->where('id', $receipt->getKey())->update(['manifest' => json_encode($manifest)]);
}

/** The manifest with one value set — `Arr::set()` returns the innermost array, not this one. */
function mergeSet(array $manifest, string $key, mixed $value): array
{
    Arr::set($manifest, $key, $value);

    return $manifest;
}

function mergeType(string $handle): EntryType
{
    return EntryType::query()->where('org_id', app(Context::class)->orgId())->where('handle', $handle)->firstOrFail();
}

/** M1 */
it('adds a field to a type an earlier version created, ending where a fresh apply of that version ends', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    $recorded = Blueprint::receiptFor('fixture')->manifest;
    $dispatch = mergeType('dispatch');

    mergeNext(mergeTypes(mergeDispatch(fields: [mergeBody(), mergeCode(), mergeNew()])));

    $result = BlueprintApplier::apply(new FixtureBlueprint);
    $receipt = Blueprint::receiptFor('fixture');

    expect($result)->toBe([
        'handle' => 'fixture',
        'version' => '1.1.0',
        'created' => ['field storage dispatch_note'],
        'adopted' => [],
        'skipped' => ['version: merged over 1.0.0, which the receipt recorded — what 1.1.0 adds was written, and nothing 1.0.0 wrote was changed'],
        'indexed' => 0,
        'roles_created' => [],
    ])
        ->and(Field::query()->where('entry_type_id', $dispatch->getKey())->count())->toBe(3)
        ->and($receipt->version)->toBe('1.1.0')
        ->and($receipt->applied_at)->not->toBeNull()
        ->and($receipt->manifest['version'])->toBe('1.1.0')
        /* The rows 1.0.0 wrote, under the ids it recorded. */
        ->and(array_column($receipt->manifest['entry_types'], 'id', 'handle'))->toBe(array_column($recorded['entry_types'], 'id', 'handle'))
        ->and(array_column($receipt->manifest['roles'], 'id', 'handle'))->toBe(array_column($recorded['roles'], 'id', 'handle'))
        ->and(array_column($receipt->manifest['entry_types'][0]['fields'], 'outcome', 'handle'))
        ->toBe(['dispatch_body' => 'created', 'dispatch_code' => 'created', 'dispatch_note' => 'created']);

    /* The oracle: a fresh apply of 1.1.0 into another org records the same, ids aside. */
    app(Context::class)->setOrg($this->rival);
    BlueprintApplier::apply(new FixtureBlueprint);
    $fresh = Blueprint::receiptFor('fixture')->manifest;
    app(Context::class)->setOrg($this->org);

    expect(mergeOracle($receipt->manifest))->toBe(mergeOracle($fresh))
        ->and(BlueprintApplier::apply(new FixtureBlueprint)['skipped'])->toBe(['already applied at this version']);
});

/** M2 */
it('adds a type with its fields, and a role granting on it and on a type an earlier version created', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    $dispatcher = Role::query()->where('handle', 'dispatcher')->firstOrFail();
    mergeHolder($this->org, $dispatcher);
    $holders = DB::table('role_user')->count();
    $granted = AuditLog::query()->withoutGlobalScopes()->where('action', 'role.granted');
    $grantedBefore = (clone $granted)->count();

    mergeNext(
        [...mergeTypes(), new EntryTypeDeclaration(handle: 'route', name: 'Route', pluralName: 'Routes', fields: [
            new FieldDeclaration(handle: 'route_name', type: 'text', label: 'Name', piiClass: 'none'),
        ])],
        [new RoleDeclaration('router', 'Router', ['route' => ['view', 'create'], 'dispatch' => ['view']])],
    );

    $result = BlueprintApplier::apply(new FixtureBlueprint);
    $router = Role::query()->where('handle', 'router')->firstOrFail();

    expect($result['roles_created'])->toBe(['router'])
        ->and($result['created'])->toBe([
            'entry type route',
            'field storage route_name',
            'role router: entry.dispatch.view, entry.route.create, entry.route.view',
        ])
        ->and(RolePermission::query()->withoutGlobalScopes()->where('role_id', $router->getKey())->orderBy('permission')->pluck('permission')->all())
        ->toBe(['entry.dispatch.view', 'entry.route.create', 'entry.route.view'])
        /* Each new grant audited once, against the new role, and nothing else. */
        ->and((clone $granted)->count() - $grantedBefore)->toBe(3)
        ->and((clone $granted)->where('target_id', (string) $router->getKey())->count())->toBe(3)
        ->and(RolePermission::query()->withoutGlobalScopes()->where('role_id', $dispatcher->getKey())->orderBy('permission')->pluck('permission')->all())
        ->toBe(['entry.dispatch.update', 'entry.dispatch.view'])
        ->and(DB::table('role_user')->count())->toBe($holders)
        ->and(DB::table('role_user')->where('role_id', $router->getKey())->exists())->toBeFalse();
});

/** M3 — and the cross-engine probe: the record projection round-trips through each engine's JSON column unchanged. */
it('merges a definition that differs only in its version, writing no row', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    $before = mergeSnapshot();
    $recorded = Blueprint::receiptFor('fixture')->manifest;

    mergeNext(version: '1.0.1');

    $result = BlueprintApplier::apply(new FixtureBlueprint);
    $after = mergeSnapshot();
    $manifest = Blueprint::receiptFor('fixture')->manifest;

    expect($result['created'])->toBe([])
        ->and($result['adopted'])->toBe([])
        ->and($result['skipped'])->toBe(['version: merged over 1.0.0, which the receipt recorded — what 1.0.1 adds was written, and nothing 1.0.0 wrote was changed'])
        ->and($result['roles_created'])->toBe([])
        ->and(Arr::except($after, ['receipts']))->toBe(Arr::except($before, ['receipts']))
        ->and(Arr::except($manifest, ['version', 'outcome']))->toBe(Arr::except($recorded, ['version', 'outcome']))
        ->and($manifest['version'])->toBe('1.0.1')
        /* By key: MySQL hands a JSON object back with its keys re-sorted, so its order says nothing. */
        ->and($manifest['outcome'])->toEqual(['created' => [], 'adopted' => [], 'skipped' => []]);
});

/** M4 */
it('indexes only what the merge adds', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);

    expect($this->schema->syncedHandles)->toBe(['dispatch_code']);

    mergeNext(mergeTypes(mergeDispatch(fields: [mergeBody(), mergeCode(), mergeNew('dispatch_ref', ['type' => 'text', 'isIndexed' => true])])));

    expect(BlueprintApplier::apply(new FixtureBlueprint)['indexed'])->toBe(1)
        ->and($this->schema->syncedHandles)->toBe(['dispatch_code', 'dispatch_ref']);
});

/**
 * One change to what 1.0.0 recorded, made to the definition, with the item the refusal names for it.
 *
 * @return string the item
 */
function mergeChange(string $case): string
{
    $field = static fn (array $changes): array => mergeTypes(mergeDispatch(fields: [mergeBody($changes), mergeCode()]));

    [$types, $roles, $item] = match ($case) {
        'type dropped' => [[mergeDispatch()], null, 'entry type bulletin is no longer declared'],
        'type name' => [mergeTypes(mergeDispatch(['name' => 'Despatch'])), null, 'entry type dispatch changes its name'],
        'type plural_name' => [mergeTypes(mergeDispatch(['pluralName' => 'Despatches'])), null, 'entry type dispatch changes its plural_name'],
        'type icon' => [mergeTypes(mergeDispatch(['icon' => null])), null, 'entry type dispatch changes its icon'],
        'type description' => [mergeTypes(mergeDispatch(['description' => ''])), null, 'entry type dispatch changes its description'],
        'type ordering' => [mergeTypes(mergeDispatch(['ordering' => 4])), null, 'entry type dispatch changes its ordering'],
        'type on_collision' => [mergeTypes(mergeDispatch(['onCollision' => OnCollision::Skip])), null, 'entry type dispatch changes its on_collision'],
        'field dropped' => [mergeTypes(mergeDispatch(fields: [mergeBody()])), null, 'field dispatch_code on dispatch is no longer declared'],
        'field moved' => [[mergeDispatch(fields: [mergeBody()]), mergeBulletin(fields: [new FieldDeclaration(handle: 'bulletin_text', type: 'textarea', label: 'Text', piiClass: 'none'), mergeCode()])], null, 'field dispatch_code on dispatch is no longer declared'],
        'field type' => [$field(['type' => 'text']), null, 'field dispatch_body on dispatch changes its type'],
        'field label' => [$field(['label' => 'Story']), null, 'field dispatch_body on dispatch changes its label'],
        'field pii_class' => [$field(['piiClass' => 'personal']), null, 'field dispatch_body on dispatch changes its pii_class'],
        'field cardinality' => [$field(['cardinality' => -1]), null, 'field dispatch_body on dispatch changes its cardinality'],
        'field is_indexed' => [$field(['isIndexed' => true]), null, 'field dispatch_body on dispatch changes its is_indexed'],
        'field settings' => [mergeTypes(mergeDispatch(fields: [mergeBody(), mergeCode(['settings' => ['maxLength' => 40, 'pattern' => null]])])), null, 'field dispatch_code on dispatch changes its settings'],
        /* ⚠️ Loosely, null equals '' and 32 equals '32': settings are compared strictly once their keys are sorted. */
        'field settings null to empty' => [mergeTypes(mergeDispatch(fields: [mergeBody(), mergeCode(['settings' => ['maxLength' => 32, 'pattern' => '']])])), null, 'field dispatch_code on dispatch changes its settings'],
        'field settings 32 to "32"' => [mergeTypes(mergeDispatch(fields: [mergeBody(), mergeCode(['settings' => ['maxLength' => '32', 'pattern' => null]])])), null, 'field dispatch_code on dispatch changes its settings'],
        'field is_required' => [$field(['isRequired' => true]), null, 'field dispatch_body on dispatch changes its is_required'],
        /* ⚠️ Loosely, null equals '' — so this is the row a loose comparison of scalars lets through. */
        'field help_text null to empty' => [$field(['helpText' => '']), null, 'field dispatch_body on dispatch changes its help_text'],
        'field ordering' => [$field(['ordering' => 11]), null, 'field dispatch_body on dispatch changes its ordering'],
        'field group' => [$field(['group' => 'Main']), null, 'field dispatch_body on dispatch changes its group'],
        'role dropped' => [null, [], 'role dispatcher is no longer declared'],
        'role name' => [null, [mergeDispatcher(['name' => 'Despatcher'])], 'role dispatcher changes its name'],
        'role on_collision' => [null, [mergeDispatcher(['onCollision' => OnCollision::Skip])], 'role dispatcher changes its on_collision'],
        'role loses a grant' => [null, [mergeDispatcher(['grants' => ['dispatch' => ['view']]])], 'role dispatcher loses entry.dispatch.update — a merge never revokes'],
    };

    FixtureBlueprint::$version = '1.1.0';
    FixtureBlueprint::$override = $types ?? FixtureBlueprint::$override;
    FixtureBlueprint::$roles = $roles ?? FixtureBlueprint::$roles;

    return $item;
}

/** M5 */
it('refuses each change to what an earlier version recorded, naming it, and writes nothing', function (string $case): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    $before = mergeSnapshot();

    $item = mergeChange($case);

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(RuntimeException::class, mergeRefusal($item));

    expect(mergeSnapshot())->toBe($before);
})->with([
    'type dropped', 'type name', 'type plural_name', 'type icon', 'type description', 'type ordering', 'type on_collision',
    'field dropped', 'field moved', 'field type', 'field label', 'field pii_class', 'field cardinality', 'field is_indexed',
    'field settings', 'field settings null to empty', 'field settings 32 to "32"', 'field is_required', 'field help_text null to empty', 'field ordering', 'field group',
    'role dropped', 'role name', 'role on_collision', 'role loses a grant',
]);

/** M5, the skipped role: its grants were never written, and it is held to the same rule. */
it('refuses a change to a role it skipped, as to one it created', function (): void {
    Role::create(['handle' => 'dispatcher', 'name' => 'Theirs']);
    FixtureBlueprint::$roles = [mergeDispatcher(['onCollision' => OnCollision::Skip])];
    BlueprintApplier::apply(new FixtureBlueprint);
    $before = mergeSnapshot();

    mergeNext();
    FixtureBlueprint::$roles = [mergeDispatcher(['onCollision' => OnCollision::Skip, 'grants' => ['dispatch' => ['view', 'update', 'delete']]])];

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(RuntimeException::class, mergeRefusal(
        'role dispatcher gains entry.dispatch.delete — a merge never adds to a role an earlier version created, because '
        .'whoever holds it would gain them with nobody choosing to (ADR-033); declare them on a new role'
    ));

    expect(mergeSnapshot())->toBe($before);
});

/**
 * M3's sibling, for the outcomes a merge must carry over as recorded: a type adopted under Skip, storage adopted from the
 * operator, and a role skipped. Seeded as "created", any of them would let the next version write onto the operator's.
 */
it('carries a skipped type, an adopted field and a skipped role over as they were recorded', function (): void {
    EntryType::create(['org_id' => $this->org->getKey(), 'handle' => 'bulletin', 'name' => 'Theirs', 'plural_name' => 'Theirs']);
    FieldStorage::create(['org_id' => $this->org->getKey(), 'handle' => 'dispatch_body', 'type' => 'textarea', 'cardinality' => 1, 'pii_class' => 'none']);
    Role::create(['handle' => 'dispatcher', 'name' => 'Theirs']);
    FixtureBlueprint::$override = [mergeDispatch(), mergeBulletin(['onCollision' => OnCollision::Skip])];
    FixtureBlueprint::$roles = [mergeDispatcher(['onCollision' => OnCollision::Skip])];
    BlueprintApplier::apply(new FixtureBlueprint);
    $recorded = Blueprint::receiptFor('fixture')->manifest;

    expect($recorded['entry_types'][1]['outcome'])->toBe('skipped')
        ->and($recorded['entry_types'][0]['fields'][0]['outcome'])->toBe('adopted')
        ->and($recorded['roles'][0]['outcome'])->toBe('skipped');

    mergeNext(version: '1.0.1');
    BlueprintApplier::apply(new FixtureBlueprint);

    expect(Arr::except(Blueprint::receiptFor('fixture')->manifest, ['version', 'outcome']))->toBe(Arr::except($recorded, ['version', 'outcome']));

    $fields = Field::query()->where('entry_type_id', mergeType('bulletin')->getKey())->count();
    mergeNext([mergeDispatch(), mergeBulletin(['onCollision' => OnCollision::Skip], [new FieldDeclaration(handle: 'bulletin_text', type: 'textarea', label: 'Text', piiClass: 'none'), mergeNew('bulletin_note')])], version: '1.1.0');

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(RuntimeException::class, mergeRefusal(
        'field bulletin_note is added to entry type bulletin, which this blueprint adopted (onCollision: skip) rather '
        .'than created — a merge adds fields only to types this blueprint created',
        from: '1.0.1',
    ));

    expect(Field::query()->where('entry_type_id', mergeType('bulletin')->getKey())->count())->toBe($fields);
});

/**
 * ⚠️ A NEW ROLE THAT MEETS THE OPERATOR'S UNDER SKIP GRANTS NOTHING, so its grants are not refused — as a fresh apply's
 * are not. Refusing them, on a type removed or adopted, was stricter than the apply it stands in for, and its reason
 * ("a grant on that handle would reach…") was not true of a role nothing would be granted to.
 */
it('leaves a new role it meets under Skip exactly as it is, granting nothing, as a fresh apply does', function (string $type): void {
    if ($type === 'adopted') {
        EntryType::create(['org_id' => $this->org->getKey(), 'handle' => 'bulletin', 'name' => 'Theirs', 'plural_name' => 'Theirs']);
        FixtureBlueprint::$override = [mergeDispatch(), mergeBulletin(['onCollision' => OnCollision::Skip])];
    }

    BlueprintApplier::apply(new FixtureBlueprint);

    if ($type === 'removed') {
        mergeType('bulletin')->delete();
    }

    $theirs = Role::create(['handle' => 'reader', 'name' => 'Theirs']);
    mergeNext(roles: [new RoleDeclaration('reader', 'Reader', ['bulletin' => ['view']], OnCollision::Skip)]);

    $result = BlueprintApplier::apply(new FixtureBlueprint);

    expect($result['skipped'])->toContain('role reader (already defined here; left as it is — its grants were not added)')
        ->and($result['roles_created'])->toBe([])
        ->and(RolePermission::query()->withoutGlobalScopes()->where('role_id', $theirs->getKey())->count())->toBe(0)
        ->and($theirs->fresh()->name)->toBe('Theirs');
})->with(['on a type the operator removed' => 'removed', 'on a type adopted under Skip' => 'adopted']);

/** And one that meets no role of its handle is created — so its grants are refused as any new role's are. */
it('refuses the grants of a new Skip role that meets no role of its handle, as any new role\'s', function (string $type, string $refusal): void {
    if ($type === 'adopted') {
        EntryType::create(['org_id' => $this->org->getKey(), 'handle' => 'bulletin', 'name' => 'Theirs', 'plural_name' => 'Theirs']);
        FixtureBlueprint::$override = [mergeDispatch(), mergeBulletin(['onCollision' => OnCollision::Skip])];
    }

    BlueprintApplier::apply(new FixtureBlueprint);

    if ($type === 'removed') {
        mergeType('bulletin')->delete();
    }

    mergeNext(roles: [new RoleDeclaration('reader', 'Reader', ['bulletin' => ['view']], OnCollision::Skip)]);

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(RuntimeException::class, $refusal);
})->with([
    'on a type the operator removed' => ['removed', 'role reader, new in 1.1.0, grants on bulletin, which this blueprint created and this organisation has since removed'],
    'on a type adopted under Skip' => ['adopted', 'role reader, new in 1.1.0, grants on bulletin, which this blueprint adopted (onCollision: skip) rather than created'],
]);

/**
 * M6 — ⚠️ AT ANY DEPTH, because MySQL re-sorts a JSON object's keys at every depth: compared in order, a select's options
 * read back from the manifest there would refuse every merge on one engine of four. So an options map only reordered is
 * not a change — the limit ADR-039 records — while a list, whose order every engine keeps, is compared in order.
 */
it('does not count reordered settings keys as a change, at any depth', function (): void {
    $kind = static fn (array $options): FieldDeclaration => new FieldDeclaration(
        handle: 'bulletin_kind', type: 'select', label: 'Kind', piiClass: 'none', settings: ['options' => $options],
    );
    $text = new FieldDeclaration(handle: 'bulletin_text', type: 'textarea', label: 'Text', piiClass: 'none');
    FixtureBlueprint::$override = [mergeDispatch(), mergeBulletin(fields: [$text, $kind(['urgent' => 'Urgent', 'routine' => 'Routine'])])];
    BlueprintApplier::apply(new FixtureBlueprint);

    mergeNext([
        mergeDispatch(fields: [mergeBody(), mergeCode(['settings' => ['pattern' => null, 'maxLength' => 32]])]),
        mergeBulletin(fields: [$text, $kind(['routine' => 'Routine', 'urgent' => 'Urgent'])]),
    ]);

    expect(BlueprintApplier::apply(new FixtureBlueprint)['version'])->toBe('1.1.0');
});

/** M7 */
it('lists every difference in one refusal, in manifest order', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    $before = mergeSnapshot();

    mergeNext([mergeDispatch(['name' => 'Despatch', 'ordering' => 9], [mergeBody(['label' => 'Story', 'group' => 'Main'])])], [
        new RoleDeclaration('router', 'Router', ['dispatch' => ['view']]),
    ]);
    FixtureBlueprint::$roles[0] = mergeDispatcher(['name' => 'Despatcher', 'grants' => ['dispatch' => ['view', 'delete']]]);

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(RuntimeException::class, mergeRefusal(implode('; ', [
        'entry type dispatch changes its name, ordering',
        'field dispatch_body on dispatch changes its label, group',
        'field dispatch_code on dispatch is no longer declared',
        'entry type bulletin is no longer declared',
        'role dispatcher changes its name',
        'role dispatcher gains entry.dispatch.delete — a merge never adds to a role an earlier version created, because whoever holds it would gain them with nobody choosing to (ADR-033); declare them on a new role',
        'role dispatcher loses entry.dispatch.update — a merge never revokes',
    ])));

    expect(mergeSnapshot())->toBe($before);
});

/** M8 — nothing parses a version: what decides is what the receipt records. */
it('refuses an older version that drops what the receipt records, and merges a lower one that only adds', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    $before = mergeSnapshot();

    mergeNext([mergeDispatch()], version: '0.9.0');

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))
        ->toThrow(RuntimeException::class, mergeRefusal('entry type bulletin is no longer declared', to: '0.9.0'));

    expect(mergeSnapshot())->toBe($before);

    mergeNext(mergeTypes(mergeDispatch(fields: [mergeBody(), mergeCode(), mergeNew()])), version: '0.9.0');

    expect(BlueprintApplier::apply(new FixtureBlueprint)['created'])->toBe(['field storage dispatch_note'])
        ->and(Blueprint::receiptFor('fixture')->version)->toBe('0.9.0');
});

/** M9 — ADR-039's open decision (a), answered no: a recorded role never gains. */
it('refuses to add a grant to a role an earlier version created, with somebody holding it', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    $holder = mergeHolder($this->org, Role::query()->where('handle', 'dispatcher')->firstOrFail());
    $before = mergeSnapshot();

    mergeNext();
    FixtureBlueprint::$roles = [mergeDispatcher(['grants' => ['dispatch' => ['view', 'update', 'delete']]])];

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(RuntimeException::class, mergeRefusal(
        'role dispatcher gains entry.dispatch.delete — a merge never adds to a role an earlier version created, because '
        .'whoever holds it would gain them with nobody choosing to (ADR-033); declare them on a new role'
    ));

    Permissions::forget();

    expect(mergeSnapshot())->toBe($before)
        ->and(Permissions::allows($holder, 'entry.dispatch.delete'))->toBeFalse()
        ->and(Permissions::allows($holder, 'entry.dispatch.update'))->toBeTrue();
});

/** M10 — ADR-039's open decision (b), answered no: a field the operator deleted stays deleted. */
it('keeps a field the operator deleted deleted, says so, and still does at the next version', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    $dispatch = mergeType('dispatch');
    $code = FieldStorage::query()->where('org_id', $this->org->getKey())->where('handle', 'dispatch_code')->firstOrFail();
    /* The operator's own use of the same storage on another type, which must not read as the deleted field. */
    Field::create(['entry_type_id' => mergeType('bulletin')->getKey(), 'field_storage_id' => $code->getKey(), 'label' => 'Their code']);
    Field::query()->where('entry_type_id', $dispatch->getKey())->where('field_storage_id', $code->getKey())->firstOrFail()->delete();

    $note = 'field dispatch_code on dispatch: removed since this blueprint wrote it; not written again';

    mergeNext(mergeTypes(mergeDispatch(fields: [mergeBody(), mergeCode(), mergeNew()])));
    $result = BlueprintApplier::apply(new FixtureBlueprint);

    expect($result['skipped'])->toContain($note)
        ->and(Field::query()->where('entry_type_id', $dispatch->getKey())->where('field_storage_id', $code->getKey())->exists())->toBeFalse()
        ->and(array_column(Blueprint::receiptFor('fixture')->manifest['entry_types'][0]['fields'], 'handle'))
        ->toBe(['dispatch_body', 'dispatch_code', 'dispatch_note']);

    mergeNext(mergeTypes(mergeDispatch(fields: [mergeBody(), mergeCode(), mergeNew(), mergeNew('dispatch_ref', ['ordering' => 40])])), version: '1.2.0');
    $again = BlueprintApplier::apply(new FixtureBlueprint);

    expect($again['skipped'])->toContain($note)
        ->and($again['created'])->toBe(['field storage dispatch_ref'])
        ->and(Field::query()->where('entry_type_id', $dispatch->getKey())->where('field_storage_id', $code->getKey())->exists())->toBeFalse()
        ->and(Field::query()->where('entry_type_id', $dispatch->getKey())->count())->toBe(3);
});

/** M11 */
it('reports a type the operator deleted, and refuses a version adding a field to it, or a role granting on it', function (): void {
    /* A role 1.0.0 created grants on the type the operator deletes — as every first-party type is granted on. */
    $crier = new RoleDeclaration('crier', 'Crier', ['bulletin' => ['view']]);
    FixtureBlueprint::$roles = [mergeDispatcher(), $crier];
    BlueprintApplier::apply(new FixtureBlueprint);
    mergeType('bulletin')->delete();
    $before = mergeSnapshot();

    mergeNext(
        [mergeDispatch(), mergeBulletin(fields: [new FieldDeclaration(handle: 'bulletin_text', type: 'textarea', label: 'Text', piiClass: 'none'), mergeNew('bulletin_note')])],
        [new RoleDeclaration('herald', 'Herald', ['bulletin' => ['view']])],
    );

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(RuntimeException::class,
        'Blueprint [fixture] cannot be merged from 1.0.0 to 1.1.0 in this organisation: it adds field bulletin_note to '
        .'entry type bulletin, which this blueprint created and this organisation has since removed — a merge never '
        .'re-creates what was removed; role herald, new in 1.1.0, grants on bulletin, which this blueprint created and '
        .'this organisation has since removed — a grant on that handle would reach whatever type takes it next. A merge '
        .'writes only onto what this blueprint created and this organisation still has as it was written. Nothing was '
        .'written, and the receipt still says 1.0.0.'
    );

    expect(mergeSnapshot())->toBe($before);

    /* A version that lands nothing on it merges — its recorded role's grant on it included — and says what became of it. */
    mergeNext(mergeTypes(mergeDispatch(fields: [mergeBody(), mergeCode(), mergeNew()])));
    FixtureBlueprint::$roles = [mergeDispatcher(), $crier];

    $result = BlueprintApplier::apply(new FixtureBlueprint);

    expect($result['skipped'])->toContain('entry type bulletin: removed since this blueprint wrote it; not written again')
        ->and($result['created'])->toBe(['field storage dispatch_note'])
        ->and(EntryType::query()->where('org_id', $this->org->getKey())->where('handle', 'bulletin')->exists())->toBeFalse();
});

/** M12 */
it('reports a deleted role and a renamed one, and touches neither', function (): void {
    FixtureBlueprint::$roles = [mergeDispatcher(), new RoleDeclaration('crier', 'Crier', ['bulletin' => ['view']])];
    BlueprintApplier::apply(new FixtureBlueprint);
    Role::query()->where('handle', 'crier')->firstOrFail()->delete();
    $dispatcher = Role::query()->where('handle', 'dispatcher')->firstOrFail();
    $dispatcher->update(['handle' => 'dispatch_lead']);
    mergeHolder($this->org, $dispatcher);
    $grants = RolePermission::query()->withoutGlobalScopes()->orderBy('id')->get(['role_id', 'permission'])->toArray();
    $holders = DB::table('role_user')->orderBy('user_id')->get()->map(static fn (object $row): array => (array) $row)->all();

    mergeNext(mergeTypes(mergeDispatch(fields: [mergeBody(), mergeCode(), mergeNew()])));

    $result = BlueprintApplier::apply(new FixtureBlueprint);

    expect($result['skipped'])->toBe([
        'version: merged over 1.0.0, which the receipt recorded — what 1.1.0 adds was written, and nothing 1.0.0 wrote was changed',
        'role dispatcher: renamed dispatch_lead since this blueprint wrote it; left as it is',
        'role crier: removed since this blueprint wrote it; not written again',
    ])
        ->and($result['roles_created'])->toBe([])
        ->and(Role::query()->pluck('handle')->all())->toBe(['dispatch_lead'])
        ->and(Role::query()->whereKey($dispatcher->getKey())->value('name'))->toBe('Dispatcher')
        ->and(RolePermission::query()->withoutGlobalScopes()->orderBy('id')->get(['role_id', 'permission'])->toArray())->toBe($grants)
        ->and(DB::table('role_user')->orderBy('user_id')->get()->map(static fn (object $row): array => (array) $row)->all())->toBe($holders);
});

/** M13 */
it('keeps a revoked grant revoked, an added grant added, and the owner flag as it is', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    $dispatcher = Role::query()->where('handle', 'dispatcher')->firstOrFail();
    $dispatcher->revoke('entry.dispatch.update');
    $dispatcher->grant('entry.bulletin.view');
    $dispatcher->update(['is_owner' => true]);

    mergeNext(mergeTypes(mergeDispatch(fields: [mergeBody(), mergeCode(), mergeNew()])));
    BlueprintApplier::apply(new FixtureBlueprint);

    expect(RolePermission::query()->withoutGlobalScopes()->where('role_id', $dispatcher->getKey())->orderBy('permission')->pluck('permission')->all())
        ->toBe(['entry.bulletin.view', 'entry.dispatch.view'])
        ->and($dispatcher->fresh()->is_owner)->toBeTrue();
});

/** M14 — DL:3344's invariant, for a merge: every grant it writes is on a role it created in that same run. */
it('writes no holder and no grant on any role it did not create', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    mergeHolder($this->org, Role::query()->where('handle', 'dispatcher')->firstOrFail());
    $theirs = Role::create(['handle' => 'editor', 'name' => 'Editor']);
    $theirs->grant('entry.dispatch.delete');
    mergeHolder($this->org, $theirs);
    $grants = RolePermission::query()->withoutGlobalScopes()->orderBy('id')->get(['id', 'role_id', 'permission'])->toArray();
    $holders = DB::table('role_user')->orderBy('user_id')->get()->map(static fn (object $row): array => (array) $row)->all();

    mergeNext(roles: [new RoleDeclaration('router', 'Router', ['dispatch' => ['view']])]);
    BlueprintApplier::apply(new FixtureBlueprint);
    $router = Role::query()->where('handle', 'router')->firstOrFail();

    expect(RolePermission::query()->withoutGlobalScopes()->where('role_id', '!=', $router->getKey())->orderBy('id')->get(['id', 'role_id', 'permission'])->toArray())->toBe($grants)
        ->and(DB::table('role_user')->orderBy('user_id')->get()->map(static fn (object $row): array => (array) $row)->all())->toBe($holders);
});

/** M15 */
it('refuses fields and grants on a type it adopted under Skip', function (): void {
    EntryType::create(['org_id' => $this->org->getKey(), 'handle' => 'bulletin', 'name' => 'Theirs', 'plural_name' => 'Theirs']);
    FixtureBlueprint::$override = [mergeDispatch(), mergeBulletin(['onCollision' => OnCollision::Skip])];
    BlueprintApplier::apply(new FixtureBlueprint);
    $before = mergeSnapshot();

    mergeNext(
        [mergeDispatch(), mergeBulletin(['onCollision' => OnCollision::Skip], [new FieldDeclaration(handle: 'bulletin_text', type: 'textarea', label: 'Text', piiClass: 'none'), mergeNew('bulletin_note')])],
        [new RoleDeclaration('crier', 'Crier', ['bulletin' => ['view'], 'dispatch' => ['view']])],
    );

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(RuntimeException::class, mergeRefusal(
        'field bulletin_note is added to entry type bulletin, which this blueprint adopted (onCollision: skip) rather '
        .'than created — a merge adds fields only to types this blueprint created; role crier, new in 1.1.0, grants on '
        .'bulletin, which this blueprint adopted (onCollision: skip) rather than created — authority over that type is '
        .'the operator\'s to give'
    ));

    expect(mergeSnapshot())->toBe($before);
});

/** M15, the global twin: a grant on that handle would reach the global type's entries in this org too. */
it('refuses a new role granting on a type an earlier version created that a global type now shadows', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    EntryType::create(['handle' => 'bulletin', 'name' => 'Bulletin', 'plural_name' => 'Bulletins']);
    $before = mergeSnapshot();

    mergeNext(roles: [new RoleDeclaration('crier', 'Crier', ['bulletin' => ['view']])]);

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(RuntimeException::class,
        'in this organisation: role crier, new in 1.1.0, grants on bulletin, and a global type with that handle now '
        .'exists — the grant would reach the global type\'s entries here too.'
    );

    expect(mergeSnapshot())->toBe($before);
});

/** M16 — asserted from the other organisation's side. */
it('refuses a manifest naming another organisation\'s rows, and leaves that organisation untouched', function (string $which): void {
    app(Context::class)->setOrg($this->rival);
    BlueprintApplier::apply(new FixtureBlueprint);
    $theirType = mergeType('dispatch');
    $theirRole = Role::query()->where('handle', 'dispatcher')->firstOrFail();
    app(Context::class)->setOrg($this->org);

    BlueprintApplier::apply(new FixtureBlueprint);
    $bulletin = mergeType('bulletin');

    [$forged, $named] = match ($which) {
        'type' => [static fn (array $m): array => mergeSet($m, 'entry_types.0.id', $theirType->getKey()), "entry type id {$theirType->getKey()} is not this organisation's dispatch"],
        'role' => [static fn (array $m): array => mergeSet($m, 'roles.0.id', $theirRole->getKey()), "role id {$theirRole->getKey()} is not this organisation's dispatcher"],
        'handle' => [static fn (array $m): array => mergeSet($m, 'entry_types.0.id', $bulletin->getKey()), "entry type id {$bulletin->getKey()} is not this organisation's dispatch"],
    };

    mergeForge($forged);
    $rival = static fn (): array => [
        DB::table('entry_types')->where('org_id', test()->rival->getKey())->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
        DB::table('field_storage')->where('org_id', test()->rival->getKey())->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
        DB::table('roles')->where('org_id', test()->rival->getKey())->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
        DB::table('role_permissions')->where('role_id', $theirRole->getKey())->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
        DB::table('blueprints')->where('org_id', test()->rival->getKey())->get()->map(static fn (object $row): array => (array) $row)->all(),
    ];
    $before = [mergeSnapshot(), $rival()];

    mergeNext(mergeTypes(mergeDispatch(fields: [mergeBody(), mergeCode(), mergeNew()])), [new RoleDeclaration('router', 'Router', ['dispatch' => ['view']])]);

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(RuntimeException::class,
        "The receipt for [fixture] cannot be merged: {$named}. Its manifest is not this organisation's record of this "
        .'blueprint\'s rows, so nothing was written and the receipt still says 1.0.0.'
    );

    expect([mergeSnapshot(), $rival()])->toBe($before);
})->with(['another org\'s type' => 'type', 'another org\'s role' => 'role', 'this org\'s type of another handle' => 'handle']);

/** M17 */
it('refuses a manifest it cannot read, naming why, and writes nothing', function (string $which, string $problem): void {
    BlueprintApplier::apply(new FixtureBlueprint);

    match ($which) {
        'version' => mergeForge(static fn (array $m): array => mergeSet($m, 'version', '0.9.0')),
        'type id' => mergeForge(static function (array $m): array {
            unset($m['entry_types'][0]['id']);

            return $m;
        }),
        'role outcome' => mergeForge(static fn (array $m): array => mergeSet($m, 'roles.0.outcome', 'pending')),
        'field outcome' => mergeForge(static fn (array $m): array => mergeSet($m, 'entry_types.0.fields.1.outcome', 'skipped')),
        'twice' => mergeForge(static function (array $m): array {
            $m['entry_types'][] = $m['entry_types'][1];

            return $m;
        }),
        /* #141's format: declarations with no ids and no outcomes. */
        'format 141' => mergeForge(static function (array $m): array {
            foreach (['entry_types', 'roles'] as $key) {
                foreach ($m[$key] as $i => $row) {
                    unset($m[$key][$i]['id'], $m[$key][$i]['outcome']);
                }
            }

            return $m;
        }),
        'null' => DB::table('blueprints')->update(['manifest' => null]),
        'role id' => mergeForge(static fn (array $m): array => mergeSet($m, 'roles.0.id', null)),
        'grants' => mergeForge(static fn (array $m): array => mergeSet($m, 'roles.0.grants', 'entry.dispatch.view')),
        'fields' => mergeForge(static fn (array $m): array => mergeSet($m, 'entry_types.0.fields', ['body' => $m['entry_types'][0]['fields'][0]])),
        'types' => mergeForge(static fn (array $m): array => mergeSet($m, 'entry_types', ['dispatch' => $m['entry_types'][0]])),
    };

    $before = mergeSnapshot();
    mergeNext(mergeTypes(mergeDispatch(fields: [mergeBody(), mergeCode(), mergeNew()])));

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(RuntimeException::class,
        "The receipt for [fixture] records 1.0.0, but its manifest is not a record a merge can read: {$problem}. Nothing "
        .'was written and the receipt is left as it is — and no command clears a receipt yet, because ADR-039\'s reverse '
        .'is not built.'
    );

    expect(mergeSnapshot())->toBe($before);
})->with([
    'its version is not the receipt\'s' => ['version', 'it records version 0.9.0'],
    'a type with no id' => ['type id', 'entry type dispatch has no id'],
    'a role outcome no apply writes' => ['role outcome', 'role dispatcher has the outcome "pending"'],
    'a field outcome no apply writes' => ['field outcome', 'field dispatch_code on dispatch has the outcome "skipped"'],
    'a handle recorded twice' => ['twice', 'it records entry type bulletin twice'],
    '#141\'s format' => ['format 141', 'entry type dispatch has no id, outcome; entry type bulletin has no id, outcome; role dispatcher has no id, outcome'],
    'no manifest, finished' => ['null', 'it records nothing'],
    'a created role with no id' => ['role id', 'role dispatcher has no id'],
    'grants that are not a list' => ['grants', 'role dispatcher\'s grants are not a list'],
    'fields that are not a list' => ['fields', 'its fields on dispatch are not a list'],
    'types that are not a list' => ['types', 'its entry_types are not a list'],
]);

/** M18 — what a version adds meets the collision policy exactly as a fresh apply's declaration does. */
it('applies the collision policy to what a version adds', function (string $case, ?string $refusal): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    $route = static fn (OnCollision $policy): EntryTypeDeclaration => new EntryTypeDeclaration(handle: 'route', name: 'Route', pluralName: 'Routes', fields: [
        new FieldDeclaration(handle: 'route_name', type: 'text', label: 'Name', piiClass: 'none'),
    ], onCollision: $policy);
    $withNote = mergeTypes(mergeDispatch(fields: [mergeBody(), mergeCode(), mergeNew()]));

    match ($case) {
        'type, fail', 'type, skip' => EntryType::create(['org_id' => $this->org->getKey(), 'handle' => 'route', 'name' => 'Theirs', 'plural_name' => 'Theirs']),
        'role, fail', 'role, skip' => Role::create(['handle' => 'router', 'name' => 'Theirs']),
        'global type' => EntryType::create(['handle' => 'route', 'name' => 'Route', 'plural_name' => 'Routes']),
        'identical storage' => FieldStorage::create(['org_id' => $this->org->getKey(), 'handle' => 'dispatch_note', 'type' => 'textarea', 'cardinality' => 1, 'pii_class' => 'none']),
        'divergent storage' => FieldStorage::create(['org_id' => $this->org->getKey(), 'handle' => 'dispatch_note', 'type' => 'textarea', 'cardinality' => 1, 'pii_class' => 'personal']),
        'their field on that storage' => Field::create([
            'entry_type_id' => mergeType('dispatch')->getKey(),
            'field_storage_id' => FieldStorage::create(['org_id' => $this->org->getKey(), 'handle' => 'dispatch_note', 'type' => 'textarea', 'cardinality' => 1, 'pii_class' => 'none'])->getKey(),
            'label' => 'Their note',
        ]),
    };

    match ($case) {
        'type, fail', 'global type' => mergeNext([...mergeTypes(), $route(OnCollision::Fail)]),
        'type, skip' => mergeNext([...mergeTypes(), $route(OnCollision::Skip)]),
        'role, fail' => mergeNext(roles: [new RoleDeclaration('router', 'Router', ['dispatch' => ['view']])]),
        'role, skip' => mergeNext(roles: [new RoleDeclaration('router', 'Router', ['dispatch' => ['view']], OnCollision::Skip)]),
        default => mergeNext($withNote),
    };

    $before = mergeSnapshot();

    if ($refusal !== null) {
        expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(RuntimeException::class, $refusal);
        expect(mergeSnapshot())->toBe($before);

        return;
    }

    $result = BlueprintApplier::apply(new FixtureBlueprint);

    match ($case) {
        'type, skip' => expect($result['skipped'])->toContain('entry type route')
            ->and($result['created'])->toBe(['field storage route_name'])
            ->and(Field::query()->where('entry_type_id', mergeType('route')->getKey())->count())->toBe(1),
        'role, skip' => expect($result['skipped'])->toContain('role router (already defined here; left as it is — its grants were not added)')
            ->and($result['roles_created'])->toBe([])
            ->and(RolePermission::query()->withoutGlobalScopes()->where('role_id', Role::query()->where('handle', 'router')->value('id'))->count())->toBe(0),
        'identical storage' => expect($result['adopted'])->toBe(['field storage dispatch_note'])
            ->and(array_column(Blueprint::receiptFor('fixture')->manifest['entry_types'][0]['fields'], 'outcome', 'handle')['dispatch_note'])->toBe('adopted'),
    };
})->with([
    'a new type against the operator\'s, fail' => ['type, fail', 'Entry type [route] already exists in this organisation'],
    'a new type against the operator\'s, skip' => ['type, skip', null],
    'a new role against the operator\'s, fail' => ['role, fail', 'Role [router] already exists in this organisation'],
    'a new role against the operator\'s, skip' => ['role, skip', null],
    'a new type with a global type\'s handle' => ['global type', 'Entry type [route] is a global type'],
    'the operator\'s identical storage' => ['identical storage', null],
    'the operator\'s divergent storage' => ['divergent storage', 'Handle [dispatch_note] already describes a field in this organisation'],
    'the operator\'s own field on that storage, on that type' => ['their field on that storage', 'Entry type [dispatch] already has a field using storage [dispatch_note]'],
]);

/** M19 — asserted from the locked side (DL:3315): the lock is armed by an editor's save, not set by hand. */
it('names a locked field when a version reshapes it, and only then', function (bool $locked): void {
    /* ⚠️ Storage is per org: another org's lock on the same handle is not this org's, whatever the wording reads. */
    app(Context::class)->setOrg($this->rival);
    BlueprintApplier::apply(new FixtureBlueprint);
    mergeWriteEntry($this->rival, ['dispatch_body' => 'Elsewhere.']);
    app(Context::class)->setOrg($this->org);

    BlueprintApplier::apply(new FixtureBlueprint);

    if ($locked) {
        mergeWriteEntry($this->org, ['dispatch_body' => 'It happened.']);
    }

    expect((bool) FieldStorage::query()->where('org_id', $this->org->getKey())->where('handle', 'dispatch_body')->value('is_locked'))->toBe($locked);

    $before = mergeSnapshot();
    mergeNext(mergeTypes(mergeDispatch(fields: [mergeBody(['type' => 'text', 'label' => 'Story']), mergeCode()])));

    $item = 'field dispatch_body on dispatch changes its type, label';
    $clause = ' — and dispatch_body is locked because entries hold data for it: create a new field, migrate the data, '
        .'verify, then remove the old one (ADR-006)';

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))
        ->toThrow(RuntimeException::class, mergeRefusal($locked ? $item.$clause : $item));

    expect(mergeSnapshot())->toBe($before);
})->with(['locked by a saved entry' => true, 'open' => false]);

/** M19, through settings: a type's settings are its shape too (`FieldStorage::guardProjectionSettings()`). */
it('names the lock when a version changes a locked field\'s settings', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    mergeWriteEntry($this->org, ['dispatch_code' => 'AB-1']);

    mergeNext(mergeTypes(mergeDispatch(fields: [mergeBody(), mergeCode(['settings' => ['maxLength' => 16, 'pattern' => null]])])));

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(RuntimeException::class, mergeRefusal(
        'field dispatch_code on dispatch changes its settings — and dispatch_code is locked because entries hold data '
        .'for it: create a new field, migrate the data, verify, then remove the old one (ADR-006)'
    ));
});

/** M19: a change that does not reshape names no lock, locked or not. */
it('names no lock for a change that does not reshape the field', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    mergeWriteEntry($this->org, ['dispatch_body' => 'It happened.']);

    mergeNext(mergeTypes(mergeDispatch(fields: [mergeBody(['label' => 'Story']), mergeCode()])));

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))
        ->toThrow(RuntimeException::class, mergeRefusal('field dispatch_body on dispatch changes its label'));
});

/**
 * M20 — ⚠️ TWO MERGES AT ONCE, AND THE ONE THAT LOSES SAYS SO. The rival runs in full at the moment this one has read
 * the receipt; this one planned from 1.0.0, and its transaction finds the receipt moved. Without that re-check, a
 * merge that meets no unique index would write its manifest over the rival's.
 */
it('names a concurrent merge, and leaves the winner\'s receipt as the winner wrote it', function (string $rivalVersion, string $rivalField): void {
    BlueprintApplier::apply(new FixtureBlueprint);

    mergeNext(mergeTypes(mergeDispatch(fields: [mergeBody(), mergeCode(), mergeNew($rivalField)])), version: $rivalVersion);
    $rival = mergeFrozen();
    mergeNext(mergeTypes(mergeDispatch(fields: [mergeBody(), mergeCode(), mergeNew()])));

    $staged = false;
    DB::listen(function (QueryExecuted $query) use (&$staged, $rival): void {
        if (! $staged && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'blueprints')) {
            $staged = true;
            BlueprintApplier::apply($rival);
        }
    });

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))
        ->toThrow(RuntimeException::class, 'another apply of it ran at the same moment');

    $receipt = Blueprint::receiptFor('fixture');

    expect($receipt->version)->toBe($rivalVersion)
        ->and($receipt->applied_at)->not->toBeNull()
        ->and(array_column($receipt->manifest['entry_types'][0]['fields'], 'handle'))->toBe(['dispatch_body', 'dispatch_code', $rivalField])
        ->and(Field::query()->where('entry_type_id', mergeType('dispatch')->getKey())->count())->toBe(3);
})->with(['the rival merged the same version' => ['1.1.0', 'dispatch_note'], 'the rival merged another' => ['1.2.0', 'dispatch_ref']]);

/** M21 */
it('reports its own refusal inside the transaction as itself, not as a concurrent apply', function (string $case, string $refusal): void {
    BlueprintApplier::apply(new FixtureBlueprint);

    if ($case === 'collision') {
        EntryType::create(['org_id' => $this->org->getKey(), 'handle' => 'route', 'name' => 'Theirs', 'plural_name' => 'Theirs']);
        mergeNext([...mergeTypes(), new EntryTypeDeclaration(handle: 'route', name: 'Route', pluralName: 'Routes')]);
    } else {
        mergeType('bulletin')->delete();
        mergeNext(roles: [new RoleDeclaration('crier', 'Crier', ['bulletin' => ['view']])]);
    }

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(RuntimeException::class, $refusal);
})->with([
    'a Fail collision' => ['collision', 'Entry type [route] already exists in this organisation'],
    'a removed type' => ['removed', 'cannot be merged from 1.0.0 to 1.1.0 in this organisation: role crier'],
]);

/**
 * M22 — a write that took a handle between the merge's read and its insert, with the receipt where it was: the unique
 * index stops it, and the refusal says what happened rather than surfacing the constraint.
 */
it('names a write that took a handle while it ran', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    $orgId = $this->org->getKey();

    $staged = false;
    DB::listen(function (QueryExecuted $query) use (&$staged, $orgId): void {
        if (! $staged && preg_match('/^select \* from [`"]field_storage[`"]/', $query->sql) === 1) {
            $staged = true;
            DB::table('field_storage')->insert([
                'org_id' => $orgId, 'handle' => 'dispatch_note', 'type' => 'textarea', 'cardinality' => 1, 'pii_class' => 'none',
            ]);
        }
    });

    mergeNext(mergeTypes(mergeDispatch(fields: [mergeBody(), mergeCode(), mergeNew()])));

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(RuntimeException::class,
        'The merge of [fixture] from 1.0.0 to 1.1.0 stopped: another write reached the same rows at the same moment, and '
        .'nothing this run wrote was kept. Run it again — it merges over whatever the receipt then records, or does '
        .'nothing if that is done.'
    );

    expect(Blueprint::receiptFor('fixture')->version)->toBe('1.0.0');
});

/** M23 — the plan read one receipt and the transaction finds another: never merged over. */
it('does not merge over a receipt changed between its plan and its transaction', function (string $change): void {
    $from = $change === 'manifest version' ? '1.1' : '1.0.0';
    FixtureBlueprint::$version = $from;
    BlueprintApplier::apply(new FixtureBlueprint);

    $staged = false;
    DB::listen(function (QueryExecuted $query) use (&$staged, $change): void {
        if ($staged || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'blueprints')) {
            return;
        }

        $staged = true;

        match ($change) {
            'applied_at' => Blueprint::query()->where('handle', 'fixture')->update(['applied_at' => null]),
            /* ⚠️ Loosely, '1.1' == '1.10': only a strict comparison of the manifest sees this move. */
            'manifest version' => mergeForge(static fn (array $m): array => mergeSet($m, 'version', '1.10')),
            'version' => (function (): void {
                $receipt = Blueprint::receiptFor('fixture');
                $receipt->version = '1.0.5';
                $receipt->save();
            })(),
        };
    });

    mergeNext(mergeTypes(mergeDispatch(fields: [mergeBody(), mergeCode(), mergeNew()])), version: '2.0.0');

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))
        ->toThrow(RuntimeException::class, 'another apply of it ran at the same moment');

    expect(FieldStorage::query()->where('handle', 'dispatch_note')->exists())->toBeFalse()
        ->and(Blueprint::receiptFor('fixture')->version)->toBe($change === 'version' ? '1.0.5' : $from);
})->with(['applied_at nulled in bulk' => 'applied_at', 'manifest version moved, loosely equal' => 'manifest version', 'version saved' => 'version']);

/** M24 */
it('refuses a field declared twice on one type before reading the receipt', function (bool $merging): void {
    if ($merging) {
        BlueprintApplier::apply(new FixtureBlueprint);
        FixtureBlueprint::$version = '1.1.0';
    }

    FixtureBlueprint::$override = mergeTypes(mergeDispatch(fields: [mergeBody(), mergeCode(), mergeNew(), mergeNew(changes: ['label' => 'Again'])]));
    $before = mergeSnapshot();
    $read = false;
    DB::listen(function (QueryExecuted $query) use (&$read): void {
        $read = $read || str_contains($query->sql, 'blueprints');
    });

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))
        ->toThrow(InvalidArgumentException::class, 'Blueprint [fixture] declares field [dispatch_note] on [dispatch] twice.');

    expect($read)->toBeFalse()
        ->and(mergeSnapshot())->toBe($before);
})->with(['a fresh apply' => false, 'a merge' => true]);
