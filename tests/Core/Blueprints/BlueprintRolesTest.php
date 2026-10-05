<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Blueprints\Declarations\EntryTypeDeclaration;
use Kitsune\Core\Blueprints\Declarations\FieldDeclaration;
use Kitsune\Core\Blueprints\Declarations\RoleDeclaration;
use Kitsune\Core\Blueprints\OnCollision;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\RolePermission;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\FixtureBlueprint;
use Kitsune\Core\Tests\Fixtures\TestUser;

/*
 * Roles with their grants — ADR-039's second key, as built for the Blog blueprint.
 *
 * ⚠️ AUTHORITY IS WHAT THIS KEY WRITES, so these tests are about what it refuses as much as what it does: a grant
 * only on a type the blueprint itself declares, never the wildcard, never the owner flag, never a holder, never a
 * grant on a role somebody else made — and never in another organisation, asserted from that organisation's side.
 */

beforeEach(function (): void {
    FixtureBlueprint::reset();
    FixtureBlueprint::$roles = [new RoleDeclaration('dispatcher', 'Dispatcher', ['dispatch' => ['view', 'update']])];
    config(['auth.providers.users.model' => TestUser::class]);

    $this->org = Org::create(['slug' => 'acme', 'name' => 'Acme']);
    $this->rival = Org::create(['slug' => 'rival', 'name' => 'Rival']);

    app(Context::class)->setOrg($this->org);
});

afterEach(fn () => app(Context::class)->forget());

/** A role's grants, sorted, read past every scope. @return list<string> */
function blueprintGrantsOf(int|string $roleId): array
{
    return RolePermission::query()->withoutGlobalScopes()->where('role_id', $roleId)->orderBy('permission')->pluck('permission')->all();
}

/** A user who belongs to the org and holds the role — never through the blueprint, which assigns nobody. */
function blueprintHolder(Org $org, Role $role): TestUser
{
    /** @var TestUser $user */
    $user = TestUser::create(['email' => 'holder'.mt_rand(1, 1_000_000_000).'@kitsune.test']);
    DB::table('org_user')->insert(['org_id' => $org->getKey(), 'user_id' => $user->getKey()]);
    DB::table('role_user')->insert(['role_id' => $role->getKey(), 'user_id' => $user->getKey()]);

    return $user;
}

/** An operator's own role in the org in context, with its grants. */
function blueprintOperatorRole(string $handle, array $grants = [], bool $owner = false): Role
{
    $role = Role::create(['handle' => $handle, 'name' => ucfirst($handle), 'is_owner' => $owner]);

    foreach ($grants as $grant) {
        $role->grant($grant);
    }

    return $role;
}

/** Nothing a malformed definition might have written: no receipt, no type, no role. */
function blueprintNothingWritten(): void
{
    expect(Blueprint::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(EntryType::query()->where('handle', 'dispatch')->count())->toBe(0)
        ->and(Role::query()->withoutGlobalScopes()->where('handle', 'dispatcher')->count())->toBe(0);
}

it('creates the role in the org applied into, with exactly the grants it declares', function (): void {
    $result = BlueprintApplier::apply(new FixtureBlueprint);

    $role = Role::query()->where('handle', 'dispatcher')->firstOrFail();

    expect(blueprintGrantsOf($role->getKey()))->toBe(['entry.dispatch.update', 'entry.dispatch.view'])
        ->and($role->org_id)->toBe($this->org->getKey())
        ->and($role->is_owner)->toBeFalse()
        ->and($role->name)->toBe('Dispatcher')
        ->and($result['created'])->toContain('role dispatcher: entry.dispatch.update, entry.dispatch.view')
        ->and($result['roles_created'])->toBe(['dispatcher']);
});

it('audits each grant once, and a re-run at the same version writes nothing more', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    $role = Role::query()->where('handle', 'dispatcher')->firstOrFail();
    $granted = AuditLog::query()->withoutGlobalScopes()->where('action', 'role.granted');

    expect((clone $granted)->count())->toBe(2)
        ->and((clone $granted)->pluck('target_id')->unique()->all())->toBe([(string) $role->getKey()])
        ->and((clone $granted)->pluck('org_id')->unique()->all())->toBe([$this->org->getKey()])
        ->and((clone $granted)->whereNotNull('site_id')->count())->toBe(0);

    $again = BlueprintApplier::apply(new FixtureBlueprint);

    expect($again['skipped'])->toBe(['already applied at this version'])
        ->and((clone $granted)->count())->toBe(2)
        ->and(Role::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('writes no role outside the org applied into, and no grant beyond its own', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);

    expect(Role::query()->withoutGlobalScopes()->where('org_id', '!=', $this->org->getKey())->count())->toBe(0)
        ->and(Role::query()->withoutGlobalScopes()->whereNull('org_id')->count())->toBe(0)
        ->and(RolePermission::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('takes no notice of a role with the same handle in another org', function (): void {
    // From the rival's side: its own `dispatcher` is an owner holding a wildcard, and must come out untouched.
    app(Context::class)->setOrg($this->rival);
    $theirs = blueprintOperatorRole('dispatcher', ['entry.*.delete'], owner: true);
    app(Context::class)->setOrg($this->org);

    BlueprintApplier::apply(new FixtureBlueprint);

    $ours = Role::query()->where('handle', 'dispatcher')->firstOrFail();
    $theirs = Role::query()->withoutGlobalScopes()->findOrFail($theirs->getKey());

    expect($ours->getKey())->not->toBe($theirs->getKey())
        ->and($theirs->is_owner)->toBeTrue()
        ->and(blueprintGrantsOf($theirs->getKey()))->toBe(['entry.*.delete'])
        ->and(blueprintGrantsOf($ours->getKey()))->toBe(['entry.dispatch.update', 'entry.dispatch.view']);
});

it('refuses a grant on anything it does not declare, before writing anything', function (string $key, string $message): void {
    if ($key === 'image') {
        // A global type that exists everywhere: still not this blueprint's to grant on.
        EntryType::create(['handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);
    }

    if ($key === 'article') {
        EntryType::create(['org_id' => $this->org->getKey(), 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);
    }

    FixtureBlueprint::$roles = [new RoleDeclaration('dispatcher', 'Dispatcher', [$key => ['view']])];

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(InvalidArgumentException::class, $message);

    blueprintNothingWritten();
})->with([
    'a global type' => ['image', 'is not a type this blueprint declares. A blueprint grants only on what it declares (ADR-039); authority over somebody else\'s type is the operator\'s to give. It declares: dispatch.'],
    'the wildcard' => ['*', 'a blueprint never grants the wildcard — an owner writes it (ADR-033)'],
    'the org\'s own type' => ['article', 'is not a type this blueprint declares'],
    'a typo' => ['dispatchh', 'It declares: dispatch.'],
]);

/**
 * ⚠️ A GLOBAL TYPE'S HANDLE IS NOT A BLUEPRINT'S TO DECLARE. Grants are matched on the handle, so a type of the same
 * handle here would take the global one's place, and the role's grants would reach the global type's entries in this
 * org — rows the blueprint never brought. Refused under either policy, inside the transaction, with nothing left.
 */
it('refuses to declare a global type\'s handle, under either policy', function (OnCollision $policy): void {
    $global = EntryType::create(['handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);
    FixtureBlueprint::$override = [new EntryTypeDeclaration(handle: 'image', name: 'Image', pluralName: 'Images', fields: [
        new FieldDeclaration(handle: 'image_note', type: 'textarea', label: 'Note', piiClass: 'none'),
    ], onCollision: $policy)];
    FixtureBlueprint::$roles = [new RoleDeclaration('dispatcher', 'Dispatcher', ['image' => ['view', 'update', 'delete']])];

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))
        ->toThrow(RuntimeException::class, 'Entry type [image] is a global type, which every organisation has');

    expect(EntryType::query()->where('handle', 'image')->pluck('id')->all())->toBe([$global->getKey()])
        ->and(FieldStorage::query()->where('handle', 'image_note')->count())->toBe(0)
        ->and(Role::query()->withoutGlobalScopes()->where('handle', 'dispatcher')->count())->toBe(0)
        ->and(RolePermission::query()->withoutGlobalScopes()->count())->toBe(0);
})->with(['fail' => OnCollision::Fail, 'skip' => OnCollision::Skip]);

/**
 * ⚠️ NOR A GRANT ON A TYPE IT ADOPTED. Under Skip the operator's type is kept and the blueprint's fields added to it,
 * but the type stays the operator's, and so does authority over its entries — a role granting on it would hand that
 * to whoever an owner later assigns the role to, believing it the blueprint's.
 */
it('refuses a grant on a type the apply adopted rather than created', function (): void {
    $theirs = EntryType::create(['org_id' => $this->org->getKey(), 'handle' => 'dispatch', 'name' => 'Theirs', 'plural_name' => 'Theirs']);
    FixtureBlueprint::$onCollision = OnCollision::Skip;

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))
        ->toThrow(RuntimeException::class, 'Role [dispatcher] grants on [dispatch], which this apply adopted rather than created');

    expect(Role::query()->withoutGlobalScopes()->where('handle', 'dispatcher')->count())->toBe(0)
        ->and(RolePermission::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(FieldStorage::query()->where('handle', 'dispatch_body')->count())->toBe(0)
        ->and(EntryType::query()->where('handle', 'dispatch')->pluck('id')->all())->toBe([$theirs->getKey()]);

    /* And the same declaration without the role is the adoption it always was. */
    FixtureBlueprint::$roles = [];

    expect(BlueprintApplier::apply(new FixtureBlueprint)['skipped'])->toContain('entry type dispatch');
});

it('refuses an action it cannot grant, before writing anything', function (array $actions): void {
    FixtureBlueprint::$roles = [new RoleDeclaration('dispatcher', 'Dispatcher', ['dispatch' => $actions])];

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(InvalidArgumentException::class, 'role [dispatcher]');

    blueprintNothingWritten();
})->with([
    'restore' => [['restore']],
    'forceDelete' => [['forceDelete']],
    'manage' => [['manage']],
    'a capital' => [['View']],
    'none' => [[]],
    'twice' => [['view', 'view']],
    'not a list' => [['a' => 'view']],
]);

/* Each row names its own rule's words, so a row that reached a different rule than its name says fails. */
it('refuses a role its author got wrong, before writing anything', function (RoleDeclaration|array $roles, string $message): void {
    FixtureBlueprint::$roles = is_array($roles) ? $roles : [$roles];

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(InvalidArgumentException::class, $message);

    blueprintNothingWritten();
})->with([
    'an empty handle' => [new RoleDeclaration('', 'Dispatcher', ['dispatch' => ['view']]), 'a role handle is lowercase snake_case'],
    'a capital' => [new RoleDeclaration('Editor', 'Editor', ['dispatch' => ['view']]), 'a role handle is lowercase snake_case'],
    'a space' => [new RoleDeclaration('blog editor', 'Editor', ['dispatch' => ['view']]), 'a role handle is lowercase snake_case'],
    'a hyphen' => [new RoleDeclaration('blog-editor', 'Editor', ['dispatch' => ['view']]), 'a role handle is lowercase snake_case'],
    'a leading hyphen' => [new RoleDeclaration('-x', 'X', ['dispatch' => ['view']]), 'a role handle is lowercase snake_case'],
    'too long' => [new RoleDeclaration(str_repeat('a', 256), 'Long', ['dispatch' => ['view']]), 'at most 255 characters'],
    'a blank name' => [new RoleDeclaration('dispatcher', '   ', ['dispatch' => ['view']]), 'with no name, or one longer than 255 characters'],
    'a name too long' => [new RoleDeclaration('dispatcher', str_repeat('n', 256), ['dispatch' => ['view']]), 'with no name, or one longer than 255 characters'],
    'no grants' => [new RoleDeclaration('dispatcher', 'Dispatcher', []), 'a role that grants nothing'],
    'a numeric key' => [new RoleDeclaration('dispatcher', 'Dispatcher', [0 => ['view']]), 'grants are keyed by entry type handle'],
    'one handle twice' => [[
        new RoleDeclaration('dispatcher', 'Dispatcher', ['dispatch' => ['view']]),
        new RoleDeclaration('dispatcher', 'Again', ['dispatch' => ['update']]),
    ], 'declares role [dispatcher] twice'],
]);

/**
 * ⚠️ BEFORE THE RECEIPT IS READ, NOT ONLY BEFORE IT IS WRITTEN. A definition gone wrong without a version bump, applied
 * again where it is already applied, is refused — not answered "already applied" — and the refusal asks the database
 * nothing at all.
 */
it('refuses a malformed definition where it is already applied, asking the database nothing', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    FixtureBlueprint::$roles = [new RoleDeclaration('dispatcher', 'Dispatcher', ['*' => ['view']])];

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))
        ->toThrow(InvalidArgumentException::class, 'a blueprint never grants the wildcard');

    expect($queries)->toBe(0);
});

/**
 * ⚠️ `*` AMONG THEM. The grant grammar reads `*` as every type, so a type handled `*` would make every per-type grant
 * on it in the admin the wildcard; the admin's own shape refuses it, and so does the pre-flight.
 */
it('refuses an entry type its author got wrong, before the receipt', function (string $how, string $message): void {
    $field = new FieldDeclaration(handle: 'dispatch_body', type: $how === 'field type' ? 'no_such_type' : 'textarea', label: 'Body', piiClass: 'none');
    FixtureBlueprint::$roles = [];
    FixtureBlueprint::$override = match ($how) {
        'twice' => [
            new EntryTypeDeclaration(handle: 'dispatch', name: 'Dispatch', pluralName: 'Dispatches', fields: [$field]),
            new EntryTypeDeclaration(handle: 'dispatch', name: 'Again', pluralName: 'Agains', fields: []),
        ],
        'field type' => [new EntryTypeDeclaration(handle: 'dispatch', name: 'Dispatch', pluralName: 'Dispatches', fields: [$field])],
        default => [new EntryTypeDeclaration(handle: $how, name: 'Post', pluralName: 'Posts', fields: [$field])],
    };

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(InvalidArgumentException::class, $message);

    expect(Blueprint::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(EntryType::query()->withoutGlobalScopes()->whereNotNull('org_id')->count())->toBe(0);
})->with([
    'a hyphen' => ['blog-post', 'an entry type handle is lowercase snake_case'],
    'the wildcard' => ['*', 'declares entry type [*]: an entry type handle is lowercase snake_case'],
    'a capital' => ['Post', 'an entry type handle is lowercase snake_case'],
    'too long' => [str_repeat('p', 256), 'at most 255 characters'],
    'a handle the admin routes use' => ['create', 'a handle the admin\'s own routes use'],
    'declared twice' => ['twice', 'declares entry type [dispatch] twice'],
    'an unknown field type' => ['field type', 'of type [no_such_type], which no field type is registered as'],
]);

it('refuses a role the org already has under the default policy, and writes none of the apply', function (): void {
    $operator = blueprintOperatorRole('dispatcher', ['entry.article.view']);
    $audits = AuditLog::query()->withoutGlobalScopes()->count();

    try {
        BlueprintApplier::apply(new FixtureBlueprint);
        $thrown = null;
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown)->not->toBeInstanceOf(UniqueConstraintViolationException::class)
        ->and($thrown->getMessage())->toContain('Role [dispatcher] already exists in this organisation')
        ->and(EntryType::query()->where('handle', 'dispatch')->count())->toBe(0)
        ->and(FieldStorage::query()->where('handle', 'dispatch_body')->count())->toBe(0)
        ->and(blueprintGrantsOf($operator->getKey()))->toBe(['entry.article.view'])
        ->and(AuditLog::query()->withoutGlobalScopes()->count())->toBe($audits);

    $receipt = Blueprint::receiptFor('fixture');

    expect($receipt)->not->toBeNull()
        ->and($receipt->applied_at)->toBeNull()
        ->and($receipt->manifest)->toBeNull();
});

it('leaves a role the org already has exactly as it is under Skip', function (bool $owner): void {
    $operator = blueprintOperatorRole('dispatcher', ['entry.article.view'], owner: $owner);
    FixtureBlueprint::$roles = [new RoleDeclaration('dispatcher', 'Dispatcher', ['dispatch' => ['view', 'update']], OnCollision::Skip)];

    $result = BlueprintApplier::apply(new FixtureBlueprint);
    $operator->refresh();

    expect(blueprintGrantsOf($operator->getKey()))->toBe(['entry.article.view'])
        ->and($operator->is_owner)->toBe($owner)
        ->and($result['skipped'])->toContain('role dispatcher (already defined here; left as it is — its grants were not added)')
        ->and($result['roles_created'])->toBe([])
        ->and(EntryType::query()->where('handle', 'dispatch')->count())->toBe(1);

    $role = collect(Blueprint::receiptFor('fixture')->manifest['roles'])->firstWhere('handle', 'dispatcher');

    expect($role['id'])->toBeNull()
        ->and($role['outcome'])->toBe('skipped');
})->with(['an ordinary role' => false, 'an owner role' => true]);

it('assigns nobody, and grants nothing on a role it did not create', function (): void {
    $operator = blueprintOperatorRole('night_shift', ['entry.article.view']);
    blueprintHolder($this->org, $operator);
    blueprintOperatorRole('dispatcher', ['entry.article.update']);

    FixtureBlueprint::$roles = [
        new RoleDeclaration('dispatcher', 'Dispatcher', ['dispatch' => ['view']], OnCollision::Skip),
        new RoleDeclaration('router', 'Router', ['dispatch' => ['view']]),
    ];

    $holders = DB::table('role_user')->orderBy('role_id')->orderBy('user_id')->get()->map(fn ($row) => (array) $row)->all();
    $before = RolePermission::query()->withoutGlobalScopes()->orderBy('id')->get(['role_id', 'permission'])->toArray();

    BlueprintApplier::apply(new FixtureBlueprint);

    $router = Role::query()->where('handle', 'router')->firstOrFail();

    expect(DB::table('role_user')->orderBy('role_id')->orderBy('user_id')->get()->map(fn ($row) => (array) $row)->all())->toBe($holders)
        ->and(RolePermission::query()->withoutGlobalScopes()->where('role_id', '!=', $router->getKey())->orderBy('id')->get(['role_id', 'permission'])->toArray())->toBe($before)
        ->and(blueprintGrantsOf($router->getKey()))->toBe(['entry.dispatch.view']);
});

it('refuses, never as a raw constraint error, a role the org has in another case', function (): void {
    blueprintOperatorRole('Dispatcher', ['entry.article.view']);

    $folds = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);

    if ($folds) {
        // The default collation reads the two as one handle, as the unique index does: the named refusal.
        expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))
            ->toThrow(RuntimeException::class, 'Role [dispatcher] already exists in this organisation');

        return;
    }

    BlueprintApplier::apply(new FixtureBlueprint);

    expect(Role::query()->where('handle', 'dispatcher')->count())->toBe(1)
        ->and(Role::query()->where('handle', 'Dispatcher')->count())->toBe(1);
});

/**
 * ⚠️ MERGED, ~~REFUSED~~, AND NOTHING PARSES THE VERSION. A version that only adds merges whether its string reads as
 * newer or older; one that drops or changes what the receipt records is refused, whichever way it reads
 * (`BlueprintMergeTest`). The role it adds is created with its grants, and the recorded one is not touched.
 */
it('merges another version, newer or older, that only adds a role', function (string $to): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    $dispatcher = Role::query()->where('handle', 'dispatcher')->firstOrFail();
    $holder = blueprintHolder($this->org, $dispatcher);

    FixtureBlueprint::$version = $to;
    FixtureBlueprint::$roles[] = new RoleDeclaration('router', 'Router', ['dispatch' => ['view']]);

    $result = BlueprintApplier::apply(new FixtureBlueprint);
    $receipt = Blueprint::receiptFor('fixture');
    $router = Role::query()->where('handle', 'router')->firstOrFail();

    expect($result['roles_created'])->toBe(['router'])
        ->and($result['version'])->toBe($to)
        ->and(blueprintGrantsOf($router->getKey()))->toBe(['entry.dispatch.view'])
        ->and(blueprintGrantsOf($dispatcher->getKey()))->toBe(['entry.dispatch.update', 'entry.dispatch.view'])
        ->and(DB::table('role_user')->pluck('user_id')->all())->toBe([$holder->getKey()])
        ->and($receipt->version)->toBe($to)
        ->and($receipt->applied_at)->not->toBeNull()
        ->and($receipt->manifest['version'])->toBe($to)
        ->and(array_column($receipt->manifest['roles'], 'handle'))->toBe(['dispatcher', 'router'])
        ->and($receipt->manifest['roles'][0]['id'])->toBe($dispatcher->getKey());
})->with(['an upgrade' => '1.1.0', 'a downgrade' => '0.9.0']);

it('records each role and every field as declared in the manifest', function (): void {
    FixtureBlueprint::$override = [new EntryTypeDeclaration(
        handle: 'dispatch',
        name: 'Dispatch',
        pluralName: 'Dispatches',
        fields: [new FieldDeclaration(
            handle: 'dispatch_body',
            type: 'textarea',
            label: 'Body',
            piiClass: 'none',
            isRequired: true,
            helpText: 'What happened.',
            ordering: 5,
            group: 'Main',
        )],
        icon: 'heroicon-o-bolt',
        description: 'Dispatches.',
        ordering: 3,
    )];

    BlueprintApplier::apply(new FixtureBlueprint);

    $manifest = Blueprint::receiptFor('fixture')->manifest;
    $type = EntryType::query()->where('handle', 'dispatch')->firstOrFail();
    $role = Role::query()->where('handle', 'dispatcher')->firstOrFail();

    /*
     * ⚠️ BY KEY, NOT IN ORDER. MySQL stores a JSON object with its keys re-sorted — by length, then bytewise — so the
     * manifest reads back in a different order there than it was written, and an order-sensitive match fails on one
     * engine of four. The key set is pinned exactly, so nothing extra or missing passes.
     */
    expect($manifest['roles'])->toHaveCount(1)
        ->and(array_keys($manifest['roles'][0]))->toEqualCanonicalizing(['handle', 'id', 'outcome', 'name', 'on_collision', 'grants'])
        ->and($manifest['roles'][0])->toMatchArray([
            'handle' => 'dispatcher',
            'id' => $role->getKey(),
            'outcome' => 'created',
            'name' => 'Dispatcher',
            'on_collision' => 'fail',
            'grants' => ['entry.dispatch.update', 'entry.dispatch.view'],
        ])
        /* The type row's key set pinned exactly too, as the role row's is (ADR-039, the DAM as built). */
        ->and(array_keys($manifest['entry_types'][0]))->toEqualCanonicalizing(['handle', 'id', 'outcome', 'name', 'plural_name', 'icon', 'description', 'ordering', 'on_collision', 'is_media', 'fields'])
        ->and($manifest['entry_types'][0])->toMatchArray([
            'handle' => 'dispatch',
            'id' => $type->getKey(),
            'outcome' => 'created',
            'icon' => 'heroicon-o-bolt',
            'description' => 'Dispatches.',
            'ordering' => 3,
            'on_collision' => 'fail',
            'is_media' => false,
        ])
        ->and($manifest['entry_types'][0]['fields'][0])->toMatchArray([
            'handle' => 'dispatch_body',
            'outcome' => 'created',
            'is_required' => true,
            'help_text' => 'What happened.',
            'ordering' => 5,
            'group' => 'Main',
        ]);
});

it('gives a holder exactly what the role grants, and only in its own org', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    $role = Role::query()->where('handle', 'dispatcher')->firstOrFail();
    $holder = blueprintHolder($this->org, $role);

    expect(Permissions::allows($holder, 'entry.dispatch.view'))->toBeTrue()
        ->and(Permissions::allows($holder, 'entry.dispatch.update'))->toBeTrue()
        ->and(Permissions::allows($holder, 'entry.dispatch.delete'))->toBeFalse()
        ->and(Permissions::allows($holder, 'entry.dispatch.publish'))->toBeFalse()
        ->and(Permissions::allows($holder, 'entry.article.view'))->toBeFalse();

    app(Context::class)->setOrg($this->rival);
    Permissions::forget();

    expect(Permissions::allows($holder, 'entry.dispatch.view'))->toBeFalse();

    // Holding the role without belonging to the org is no authority either.
    app(Context::class)->setOrg($this->org);
    Permissions::forget();
    /** @var TestUser $stranger */
    $stranger = TestUser::create(['email' => 'stranger@kitsune.test']);
    DB::table('role_user')->insert(['role_id' => $role->getKey(), 'user_id' => $stranger->getKey()]);

    expect(Permissions::allows($stranger, 'entry.dispatch.view'))->toBeFalse();
});
