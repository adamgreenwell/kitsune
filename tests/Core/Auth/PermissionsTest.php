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
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\TestUser;

/*
 * The permission vocabulary and its resolution — ADR-033.
 *
 * ⚠️ A PERMISSION IS A STRING, so the registry is the only thing standing between a grant and a typo that
 * is silently never held. These tests are the registry's, and they are as much about what is REFUSED as
 * what is accepted: a grant that stores and never matches fails closed and invisibly, which is the failure
 * mode ADR-033 accepts a table of strings in order to avoid a worse one.
 */

function member(Org $org, bool $owner = false, array $grants = []): TestUser
{
    /** @var TestUser $user */
    $user = TestUser::create(['email' => 'u'.mt_rand(1, 1_000_000_000).'@kitsune.test']);

    $role = Role::create([
        'org_id' => $org->getKey(),
        'handle' => 'r'.mt_rand(1, 1_000_000_000),
        'name' => 'Role',
        'is_owner' => $owner,
    ]);

    foreach ($grants as $grant) {
        $role->grant($grant);
    }

    DB::table('role_user')->insert(['role_id' => $role->getKey(), 'user_id' => $user->getKey()]);
    DB::table('org_user')->insert(['org_id' => $org->getKey(), 'user_id' => $user->getKey()]);

    return $user;
}

beforeEach(function (): void {
    // `Permissions` asks membership through the user model's own scoped query, so the provider has to name
    // a model that carries the skeleton's declaration — see `TestUser`.
    config(['auth.providers.users.model' => TestUser::class]);

    $this->org = Org::create(['slug' => 'alpha', 'name' => 'Alpha']);
    app(Context::class)->setOrg($this->org);
});

it('names a permission the way architecture.md §4 says', function (): void {
    expect(Permissions::forEntryType('article', 'publish'))->toBe('entry.article.publish');
});

it('accepts a grant on a type that does not exist yet', function (): void {
    /*
     * ⚠️ SHAPE, NOT EXISTENCE, and it is deliberate rather than lax. A blueprint seeds permissions
     * alongside the entry type it creates, and the two arrive in one operation — so requiring the type
     * first would make the normal case impossible.
     */
    expect(Permissions::validated('entry.product.view'))->toBe('entry.product.view');
});

it('refuses an action that is not registered', function (): void {
    // The typo that a string-valued permission makes possible, caught at the write rather than at the
    // check — where it would look like a role that simply does not have the grant.
    expect(fn () => Permissions::validated('entry.article.viwe'))
        ->toThrow(InvalidArgumentException::class, 'not a registered action');
});

it('refuses a shape that is not a permission at all', function (): void {
    foreach (['article.view', 'entry.article', 'entry.article.view.extra', 'site.article.view', ''] as $bad) {
        expect(fn () => Permissions::validated($bad))->toThrow(InvalidArgumentException::class);
    }
});

it('refuses a type segment that could never match', function (): void {
    // `entry..view` and `entry.art*cle.view` store happily and match nothing, which is the shape of grant
    // that becomes a belief about what a role can do.
    foreach (['entry..view', 'entry.art*cle.view', 'entry.Article.view', 'entry.1article.view'] as $bad) {
        expect(fn () => Permissions::validated($bad))->toThrow(InvalidArgumentException::class);
    }
});

it('grants and revokes, and grants once', function (): void {
    $role = Role::create(['org_id' => $this->org->getKey(), 'handle' => 'editor', 'name' => 'Editor']);

    $role->grant('entry.article.update');
    $role->grant('entry.article.update');

    expect($role->permissions()->count())->toBe(1);

    $role->revoke('entry.article.update');

    expect($role->permissions()->count())->toBe(0);
});

it('refuses to store a grant the registry rejects', function (): void {
    $role = Role::create(['org_id' => $this->org->getKey(), 'handle' => 'editor', 'name' => 'Editor']);

    expect(fn () => $role->grant('entry.article.approve'))->toThrow(InvalidArgumentException::class)
        ->and($role->permissions()->count())->toBe(0);
});

it('resolves a grant the user holds, and nothing else', function (): void {
    $user = member($this->org, grants: ['entry.article.view']);

    expect(Permissions::allows($user, 'entry.article.view'))->toBeTrue()
        ->and(Permissions::allows($user, 'entry.article.update'))->toBeFalse()
        ->and(Permissions::allows($user, 'entry.product.view'))->toBeFalse();
});

it('resolves the explicit wildcard for the action it names, and not for others', function (): void {
    /*
     * ⚠️ AT CHECK TIME, WHICH IS THE POINT OF IT. Expanding `entry.*.view` at grant time would cover
     * exactly the types that existed when it was written — the one thing the wildcard is for not doing. So
     * a type nobody had thought of resolves, and a different ACTION still does not.
     */
    $user = member($this->org, grants: ['entry.*.view']);

    expect(Permissions::allows($user, 'entry.article.view'))->toBeTrue()
        ->and(Permissions::allows($user, 'entry.invented_later.view'))->toBeTrue()
        ->and(Permissions::allows($user, 'entry.article.delete'))->toBeFalse();
});

it('lets an owner through without holding a grant', function (): void {
    // The bootstrap hole: somebody has to create the first entry type, which is before any permission
    // naming that type can exist.
    $owner = member($this->org, owner: true);

    expect(Permissions::allows($owner, 'entry.article.delete'))->toBeTrue()
        ->and(Permissions::isOwner($owner))->toBeTrue()
        ->and(Permissions::held($owner))->toBe([]);
});

it('refuses everything with no user and with no org context', function (): void {
    /*
     * ⚠️ NO CONTEXT MEANS NO, not "unconstrained" — the same answer `OrgScope` gives a query in that state,
     * and the opposite of the convenient one. A console command or a queue job that has not established an
     * org is exactly where an authorization check would otherwise pass by accident.
     */
    $user = member($this->org, grants: ['entry.article.view']);

    expect(Permissions::allows(null, 'entry.article.view'))->toBeFalse();

    app(Context::class)->forget();

    expect(Permissions::allows($user, 'entry.article.view'))->toBeFalse()
        ->and(Permissions::isOwner($user))->toBeFalse();
});
