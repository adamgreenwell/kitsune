<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Filament\Resources\Roles\RoleResource;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\TestImpostor;
use Kitsune\Core\Tests\Fixtures\TestUser;

/*
 * Who may administer roles — issue #84, ADR-033.
 *
 * ⚠️ ADMINISTERING ROLES IS ADMINISTERING THE PERMISSION SYSTEM ITSELF, which is why this is the narrowest
 * gate in the panel: somebody who can open this page can grant themselves anything, including the owner flag
 * that bypasses every check there is. Owner-only in v1.0 — there is no `role.manage` in the published
 * vocabulary, and inventing a subject widens the extension surface that stays shut until v1.2.
 *
 * ⚠️ THE 403 IS ASSERTED IN THE BROWSER (ADR-024): there is no HTTP harness in this suite, so what is
 * testable here is the predicate Filament aborts on. `e2e/permissions.spec.js` measures the refusal.
 */

beforeEach(function (): void {
    config(['auth.providers.users.model' => TestUser::class]);

    $this->org = Org::create(['slug' => 'alpha', 'name' => 'Alpha']);
    app(Context::class)->setOrg($this->org);

    /** @var TestUser $user */
    $user = TestUser::create(['email' => 'member@kitsune.test']);
    $this->user = $user;

    DB::table('org_user')->insert(['org_id' => $this->org->getKey(), 'user_id' => $user->getKey()]);

    Auth::guard('web')->setUser($user);
});

afterEach(function (): void {
    Auth::guard('web')->logout();
    app(Context::class)->forget();
});

it('refuses every entry point to somebody who is not an owner', function (): void {
    // A member with real grants is still not an administrator: holding `entry.article.delete` says nothing
    // about who may decide who holds it.
    $role = Role::create(['handle' => 'editor', 'name' => 'Editor']);
    $role->grant('entry.article.delete');
    $role->assignTo($this->user->getKey());

    expect(RoleResource::canViewAny())->toBeFalse()
        ->and(RoleResource::canCreate())->toBeFalse()
        ->and(RoleResource::canDeleteAny())->toBeFalse()
        ->and(RoleResource::canEdit($role))->toBeFalse()
        ->and(RoleResource::canDelete($role))->toBeFalse();
});

it('opens every entry point to an owner', function (): void {
    $owner = Role::create(['handle' => 'owner', 'name' => 'Owner', 'is_owner' => true]);
    $owner->assignTo($this->user->getKey());

    expect(RoleResource::canViewAny())->toBeTrue()
        ->and(RoleResource::canCreate())->toBeTrue()
        ->and(RoleResource::canDeleteAny())->toBeTrue()
        ->and(RoleResource::canEdit($owner))->toBeTrue();
});

it('refuses everything with nobody signed in', function (): void {
    // Fail closed, the same answer `Permissions::allows()` gives with no user.
    Auth::guard('web')->logout();

    expect(RoleResource::canViewAny())->toBeFalse()
        ->and(RoleResource::canCreate())->toBeFalse();
});

it('does not let an owner of another org administer this one\'s roles', function (): void {
    /*
     * ⚠️ The cross-org case, which has no framework safety net (ADR-021). The owner bypass is the widest
     * grant in the system, so its scope is the one that matters most — `Permissions::isOwner()` resolves
     * through the org-scoped `Role` query under the current context, and this is that claim made testable
     * from the panel's side rather than the resolver's.
     */
    $beta = Org::create(['slug' => 'beta', 'name' => 'Beta']);

    DB::table('org_user')->insert(['org_id' => $beta->getKey(), 'user_id' => $this->user->getKey()]);

    app(Context::class)->setOrg($beta);
    $elsewhere = Role::create(['handle' => 'owner', 'name' => 'Owner', 'is_owner' => true]);
    $elsewhere->assignTo($this->user->getKey());

    expect(RoleResource::canViewAny())->toBeTrue();

    app(Context::class)->setOrg($this->org);

    expect(RoleResource::canViewAny())->toBeFalse()
        ->and(RoleResource::canCreate())->toBeFalse();
});

it('refuses to administer holders through a model the pivot is not about', function (): void {
    /*
     * ⚠️ THE SELECTOR WAS THE THIRD PLACE THIS CHECK BELONGED, and the one I missed twice: `roleIdsFor()`
     * refuses assignments resolved through the wrong model and `effectiveOwners()` refuses to count owners
     * through one, while the picker went on listing that model's users. `role_user.user_id` means a row in the
     * table it REFERENCES, so an id from somewhere else names a different person — the panel shows one name and
     * the grant lands on another.
     */
    app(Context::class)->setOrg($this->org);

    // ⚠️ Not vacuous: the fixture's provider IS the model the pivot references, so this starts true.
    expect(RoleResource::holdersAreAdministrable())->toBeTrue();

    // The provider now names a model on another table — `TestImpostor` lives on `pivot_scoped_things`.
    config(['auth.providers.users.model' => TestImpostor::class]);
    Permissions::forget();

    expect(RoleResource::holdersAreAdministrable())->toBeFalse();
});

it('keeps a label for a holder who is no longer a member of the org', function (): void {
    /*
     * ⚠️ A MISSING LABEL IS AN INVALID OPTION, AND IT FROZE THE FORM — the same defect review found on the
     * relation picker, in the place it was always going to appear next. `role_user` carries no membership
     * constraint (ADR-033 says so in as many words), so somebody removed from the org can keep an assignment:
     * `mutateFormDataBeforeFill()` hydrates that id, the org-scoped user query cannot see it, and Filament
     * validates a multiple select's submitted options through the label resolver — so the owner could not
     * rename the role or change a grant until they noticed the one chip that would not save.
     *
     * The id is named and the person is not: they are not in this org, so the panel has no business resolving
     * their name through a query that deliberately cannot see them.
     */
    app(Context::class)->setOrg($this->org);

    /** @var TestUser $departed */
    $departed = TestUser::create(['email' => 'departed@kitsune.test']);
    DB::table('org_user')->insert(['org_id' => $this->org->getKey(), 'user_id' => $departed->getKey()]);

    $role = Role::create(['handle' => 'editor', 'name' => 'Editor']);
    $role->assignTo($departed->getKey());

    // They leave the org; ADR-033 is explicit that the assignment row survives and resolves nothing.
    DB::table('org_user')->where('user_id', $departed->getKey())->delete();
    Permissions::forget();

    expect(TestUser::query()->whereKey($departed->getKey())->exists())->toBeFalse()
        ->and(DB::table('role_user')->where('user_id', $departed->getKey())->exists())->toBeTrue();

    $labels = RoleResource::holderLabels([$departed->getKey()], (int) $role->getKey());

    expect($labels)->toHaveKey($departed->getKey())
        ->and($labels[$departed->getKey()])->toContain('no longer a member')
        ->and($labels[$departed->getKey()])->not->toContain('departed@kitsune.test');

    /*
     * ⚠️ AND ONLY FOR AN ID THAT IS ALREADY ASSIGNED, or this would become a way to add somebody the org
     * cannot see. The set comes from `role_user`, not from the request.
     */
    /** @var TestUser $stranger */
    $stranger = TestUser::create(['email' => 'stranger@kitsune.test']);

    expect(RoleResource::holderLabels([$stranger->getKey()], (int) $role->getKey()))->toBe([]);

    /*
     * ⚠️ AND ONLY FOR AN ID THIS ROLE HOLDS, which the first version of the fallback did not ask — review
     * found it filtering on `user_id` alone. A `role_user` row ANYWHERE was enough for a label, Filament
     * accepts a labelled option, and `syncHolders()` assigns every submitted id: the exception that keeps a
     * form saveable would have become a way to add somebody this org cannot see.
     */
    $elsewhere = Org::create(['name' => 'Elsewhere', 'slug' => 'elsewhere-holders']);

    /** @var TestUser $outsider */
    $outsider = TestUser::create(['email' => 'outsider@kitsune.test']);
    DB::table('org_user')->insert(['org_id' => $elsewhere->getKey(), 'user_id' => $outsider->getKey()]);

    app(Context::class)->setOrg($elsewhere);
    $theirRole = Role::create(['handle' => 'editor', 'name' => 'Their editor']);
    $theirRole->assignTo($outsider->getKey());
    app(Context::class)->setOrg($this->org);

    // They hold a role — just not this one, and not in this org.
    expect(DB::table('role_user')->where('user_id', $outsider->getKey())->exists())->toBeTrue()
        ->and(RoleResource::holderLabels([$outsider->getKey()], (int) $role->getKey()))->toBe([]);

    /* And the create form, which is editing no role at all, has no holder to make an exception for. */
    expect(RoleResource::holderLabels([$departed->getKey()], null))->toBe([]);
});
