<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Filament\Resources\Roles\RoleResource;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\TestUser;
use Kitsune\Core\Tests\Fixtures\UlidUser;

/*
 * Role-based access control on a host whose users carry ULIDs — #91, ADR-033.
 *
 * ⚠️ RBAC WAS INTEGER-ONLY, AND THE CAST WAS NOT HARMLESS. `Role::assignTo(int)` threw a `TypeError` for a ULID,
 * `Permissions::key()` turned one into null, and the holder picker filtered them out. Where a cast did run it named
 * somebody: PHP reads a string's leading digits, and every ULID minted this century starts with them, so
 * `(int) '01J…'` is `1`. The key type is the host's to choose, and core now reads an identifier as the user model's
 * own type. Everything here runs on the schema `UlidHostTestCase` builds: `users` keyed by ULID, and `org_user` and
 * `role_user` referencing it with `foreignUlid`.
 */

beforeEach(function (): void {
    config(['auth.providers.users.model' => UlidUser::class]);

    $this->org = Org::create(['slug' => 'ulid-host', 'name' => 'ULID host']);
    app(Context::class)->setOrg($this->org);
});

afterEach(fn () => app(Context::class)->forget());

/** A member of the current org, keyed by ULID. */
function ulidMember(Org $org, string $email): UlidUser
{
    /** @var UlidUser $user */
    $user = UlidUser::create(['email' => $email]);
    DB::table('org_user')->insert(['org_id' => $org->getKey(), 'user_id' => $user->getKey()]);

    return $user;
}

/** @return list<array{0: string, 1: ?string}> each audit row after `$mark` as [action, target_id] */
function ulidAuditSince(int $mark): array
{
    return AuditLog::query()
        ->where('id', '>', $mark)
        ->orderBy('id')
        ->get(['action', 'target_id'])
        ->map(static fn (AuditLog $row): array => [(string) $row->action, $row->target_id === null ? null : (string) $row->target_id])
        ->all();
}

it('assigns a role to a ULID-keyed user, resolves it, and takes it away again', function (): void {
    $user = ulidMember($this->org, 'editor@kitsune.test');

    $role = Role::create(['handle' => 'editor', 'name' => 'Editor']);
    $role->grant('entry.article.update');

    // ⚠️ Not vacuous: this key is a string whose leading digits an integer cast would turn into a different user.
    expect($user->getKey())->toBeString()->toHaveLength(26)
        ->and((int) $user->getKey())->toBeLessThan(10);

    $mark = (int) AuditLog::query()->max('id');

    $role->assignTo($user->getKey());

    expect(Permissions::allows($user, 'entry.article.update'))->toBeTrue()
        ->and(Permissions::allows($user, 'entry.article.delete'))->toBeFalse();

    $role->removeFrom($user->getKey());

    expect(Permissions::allows($user, 'entry.article.update'))->toBeFalse()
        ->and(DB::table('role_user')->where('role_id', $role->getKey())->exists())->toBeFalse()
        ->and(ulidAuditSince($mark))->toBe([
            ['role.assigned', $user->getKey()],
            ['role.unassigned', $user->getKey()],
        ]);
});

it('resolves an owner, and refuses to take the last owner role away from them', function (): void {
    $owner = ulidMember($this->org, 'owner@kitsune.test');

    $role = Role::create(['handle' => 'owner', 'name' => 'Owner', 'is_owner' => true]);
    $role->assignTo($owner->getKey());

    expect(Permissions::isOwner($owner))->toBeTrue()
        ->and(fn () => $role->removeFrom($owner->getKey()))->toThrow(RuntimeException::class, 'last member')
        ->and(DB::table('role_user')->where('user_id', $owner->getKey())->exists())->toBeTrue();
});

it('refuses to assign a role to an identifier that cannot be a key', function (): void {
    $role = Role::create(['handle' => 'editor', 'name' => 'Editor']);

    expect(fn () => $role->assignTo(''))->toThrow(InvalidArgumentException::class, 'not a key the user model')
        ->and(DB::table('role_user')->count())->toBe(0);
});

it('normalises a list of identifiers in the key type each host has', function (): void {
    $ulid = '01J8Z3Q4R5S6T7V8W9X0Y1Z2A3';

    expect(Permissions::userKeys([$ulid, $ulid, '', null, 7], UlidUser::class))->toBe([$ulid, '7'])
        // The integer host of `tests/Core`, asked the same thing: digits are a key, the ULID is nobody.
        ->and(Permissions::userKeys(['5', 5, $ulid, '5abc'], TestUser::class))->toBe([5]);
});

it('guards a ULID-keyed membership: audits a role holder joining, and refuses the last owner leaving', function (): void {
    $owner = ulidMember($this->org, 'owner@kitsune.test');
    Role::create(['handle' => 'owner', 'name' => 'Owner', 'is_owner' => true])->assignTo($owner->getKey());

    $editor = UlidUser::create(['email' => 'editor@kitsune.test']);
    Role::create(['handle' => 'editor', 'name' => 'Editor'])->assignTo($editor->getKey());

    $mark = (int) AuditLog::query()->max('id');

    $editor->orgs()->attach($this->org->getKey());

    expect(ulidAuditSince($mark))->toBe([['org.member_added', $editor->getKey()]])
        ->and(fn () => $owner->orgs()->detach($this->org->getKey()))->toThrow(RuntimeException::class, 'last member')
        ->and(DB::table('org_user')->where('user_id', $owner->getKey())->exists())->toBeTrue();
});

it('revokes a deleted ULID-keyed user\'s roles through the audited path, and refuses to delete the last owner', function (): void {
    $owner = ulidMember($this->org, 'owner@kitsune.test');
    Role::create(['handle' => 'owner', 'name' => 'Owner', 'is_owner' => true])->assignTo($owner->getKey());

    $editor = ulidMember($this->org, 'editor@kitsune.test');
    Role::create(['handle' => 'editor', 'name' => 'Editor'])->assignTo($editor->getKey());

    $mark = (int) AuditLog::query()->max('id');
    $editorKey = $editor->getKey();

    $editor->delete();

    expect(UlidUser::query()->withoutGlobalScopes()->whereKey($editorKey)->exists())->toBeFalse()
        ->and(DB::table('role_user')->where('user_id', $editorKey)->exists())->toBeFalse()
        ->and(ulidAuditSince($mark))->toBe([['role.unassigned', $editorKey]]);

    expect(fn () => $owner->delete())->toThrow(RuntimeException::class, 'last member')
        ->and(UlidUser::query()->withoutGlobalScopes()->whereKey($owner->getKey())->exists())->toBeTrue();
});

it('offers, labels and names ULID-keyed holders in the Roles resource', function (): void {
    $member = ulidMember($this->org, 'finder@kitsune.test');
    $former = ulidMember($this->org, 'former@kitsune.test');

    $role = Role::create(['handle' => 'editor', 'name' => 'Editor']);
    $role->assignTo($former->getKey());

    // The former holder leaves the org and keeps the assignment, which `role_user` has no constraint against.
    DB::table('org_user')->where('user_id', $former->getKey())->delete();

    expect(RoleResource::holdersAreAdministrable())->toBeTrue();

    $found = RoleResource::searchHolders('finder');

    expect(array_keys($found))->toBe([$member->getKey()])
        ->and($found[$member->getKey()])->toContain('finder@kitsune.test');

    $labels = RoleResource::holderLabels([$member->getKey(), $former->getKey()], $role->getKey());

    expect(array_keys($labels))->toBe([$member->getKey(), $former->getKey()])
        ->and($labels[$former->getKey()])->toBe("User #{$former->getKey()} — no longer a member of this organisation");
});
