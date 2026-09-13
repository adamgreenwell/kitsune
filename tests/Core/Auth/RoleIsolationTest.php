<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\RolePermission;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\TestUser;

/*
 * Cross-org isolation for the RBAC layer, written from the attacker's side — ADR-033, issue #81.
 *
 * ⚠️ THIS IS THE LEVEL WITH NO FRAMEWORK SAFETY NET. Filament's tenancy segment is the Site, so its
 * automatic scoping enforces SITE isolation; org is a level it does not model at all (ADR-021). And the
 * RBAC layer is the worst possible place for that gap, because the whole point of it is to answer "may
 * this person do that" — an answer that crosses a customer boundary is not a leak of data, it is a leak of
 * authority.
 *
 * ⚠️ AND TWO OF THE THREE TABLES INVOLVED CARRY NO SCOPE AT ALL. `role_permissions` has no `org_id` by
 * design and `role_user` lives in the skeleton, which knows nothing about orgs. Every guarantee below
 * therefore rests on one thing — that resolution starts at `Role`, which is `#[OrgScoped]` — plus a
 * membership check. If a later change reaches `role_permissions` or `role_user` directly for speed, these
 * are the tests that should stop it.
 */

beforeEach(function (): void {
    config(['auth.providers.users.model' => TestUser::class]);

    $this->alpha = Org::create(['slug' => 'alpha', 'name' => 'Alpha']);
    $this->beta = Org::create(['slug' => 'beta', 'name' => 'Beta']);

    /** @var TestUser $user */
    $user = TestUser::create(['email' => 'agent@kitsune.test']);
    $this->user = $user;

    /*
     * ⚠️ CONTEXT FIRST, THEN THE ROW, and getting that backwards is how this file started. `EnforcesScope`
     * refuses a write that NAMES a scope key while no context is established — "a scope key nobody vouched
     * for is how a row ends up visible to another org" — so the fixture has to establish alpha before
     * creating anything of alpha's. The guard caught it on the first run, which is the guard working.
     */
    app(Context::class)->setOrg($this->alpha);

    $this->alphaRole = Role::create(['handle' => 'editor', 'name' => 'Editor']);
    $this->alphaRole->grant('entry.article.update');
});

function assign(Role $role, TestUser $user): void
{
    DB::table('role_user')->insert(['role_id' => $role->getKey(), 'user_id' => $user->getKey()]);
}

/**
 * ⚠️ NOT NAMED `join`, AND PINT IS WHY. A helper called `join()` is a name PHP already has as an alias for
 * `implode()`, so `no_alias_functions` rewrote every call site to `implode($org, $user)` — silently, on
 * save, in a file whose whole purpose is to distrust things. The lesson is the one AGENTS.md invariant 13
 * already records in another form: the formatter edits this code too, so a name it recognises is a name it
 * will take.
 */
function joinOrg(Org $org, TestUser $user): void
{
    DB::table('org_user')->insert(['org_id' => $org->getKey(), 'user_id' => $user->getKey()]);
}

it('does not carry a grant from one org into another', function (): void {
    /*
     * The base case, and the one a memo can break: the same user, the same permission string, two org
     * contexts inside one process. Alpha's grant must not answer Beta's question.
     */
    assign($this->alphaRole, $this->user);
    joinOrg($this->alpha, $this->user);
    joinOrg($this->beta, $this->user);

    app(Context::class)->setOrg($this->alpha);
    expect(Permissions::allows($this->user, 'entry.article.update'))->toBeTrue();

    app(Context::class)->setOrg($this->beta);
    expect(Permissions::allows($this->user, 'entry.article.update'))->toBeFalse()
        ->and(Permissions::held($this->user))->toBe([]);
});

it('does not answer from a memo keyed on the user alone', function (): void {
    /*
     * ⚠️ THE AGENTS.md INVARIANT 13 REGRESSION GUARD, and the order is what makes it one. `once()` hashes
     * the variables a closure CAPTURES, and reflection reports only the ones the body mentions — so a memo
     * that captured `$orgId` without using it would be keyed on the user, and the SECOND question inside
     * one process would be answered with the first org's grants. Asking alpha first is what turns that into
     * a failure instead of a coincidence.
     */
    assign($this->alphaRole, $this->user);
    joinOrg($this->alpha, $this->user);
    joinOrg($this->beta, $this->user);

    app(Context::class)->setOrg($this->alpha);
    Permissions::held($this->user);

    app(Context::class)->setOrg($this->beta);
    expect(Permissions::held($this->user))->toBe([]);
});

it('does not treat a role assignment as membership', function (): void {
    /*
     * ⚠️ `role_user` IS IN THE SKELETON AND KNOWS NOTHING ABOUT ORGS, so a row pairing a user with a role
     * in an org they have never joined is a row nothing rejects. Resolution would find it the moment that
     * org is current — which in the panel it could not be, because Filament gates on `site_user` first. An
     * authorization answer that depends on a UI having refused the request first is the reasoning ADR-021
     * says has no safety net.
     */
    assign($this->alphaRole, $this->user);

    app(Context::class)->setOrg($this->alpha);

    expect(Permissions::allows($this->user, 'entry.article.update'))->toBeFalse()
        ->and(Permissions::held($this->user))->toBe([]);
});

it('does not let an owner role in one org bypass checks in another', function (): void {
    // An owner bypass is the widest grant in the system, so it is the one whose scope matters most.
    app(Context::class)->setOrg($this->alpha);
    $owner = Role::create(['handle' => 'owner', 'name' => 'Owner', 'is_owner' => true]);

    assign($owner, $this->user);
    joinOrg($this->alpha, $this->user);
    joinOrg($this->beta, $this->user);

    app(Context::class)->setOrg($this->alpha);
    expect(Permissions::isOwner($this->user))->toBeTrue();

    app(Context::class)->setOrg($this->beta);
    expect(Permissions::isOwner($this->user))->toBeFalse()
        ->and(Permissions::allows($this->user, 'entry.article.delete'))->toBeFalse();
});

it('refuses to read another org\'s role at all', function (): void {
    /*
     * ⚠️ The scope, asserted directly rather than only through the resolver. If `Role` ever stopped being
     * `#[OrgScoped]` — or stopped `use`-ing `EnforcesScope`, which is the half that actually applies it and
     * which `User` was missing for two phases — every test above would still pass through some other
     * mechanism, and this one would not.
     */
    app(Context::class)->setOrg($this->beta);

    expect(Role::query()->count())->toBe(0)
        ->and(Role::query()->find($this->alphaRole->getKey()))->toBeNull();
});

it('reaches a grant only through its role, never around it', function (): void {
    /*
     * ⚠️ `RolePermission` IS `#[Unscoped]`, AND THIS IS WHAT THAT COSTS. A direct query answers with every
     * customer's grants, which is why `Permissions` starts at `Role` and why a bare `RolePermission::query()`
     * in a read path is a defect. Asserted rather than commented, so the cost is visible instead of
     * remembered — and so a future change that quietly adds a scope here is noticed rather than assumed.
     */
    app(Context::class)->setOrg($this->beta);

    expect(RolePermission::query()->count())->toBe(1)
        ->and($this->beta->roles()->count())->toBe(0);
});
