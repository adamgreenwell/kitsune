<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Kitsune\Core\Auth\EntryPolicy;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\TestUser;

/*
 * `EntryPolicy` — ADR-033, and Phase 4's last unchecked line.
 *
 * ⚠️ NOTHING HERE IS PERSISTED BEYOND THE ROLES, and that is deliberate rather than lazy. The policy's
 * whole job is deciding WHICH permission string a question maps to — the record's own `type_handle` with a
 * record in hand, the container's bound type without one. An unsaved `Entry` exercises that mapping exactly,
 * and building a site, a type and a row first would test the fixture.
 *
 * ⚠️ THE ENFORCEMENT IS ASSERTED ELSEWHERE, ON PURPOSE. Whether the panel actually consults this is a
 * question about the application rather than about the class, and ADR-024 says this layer structurally
 * cannot see it — so `e2e/permissions.spec.js` measures the refusal at the URL.
 */

beforeEach(function (): void {
    config(['auth.providers.users.model' => TestUser::class]);

    $this->org = Org::create(['slug' => 'alpha', 'name' => 'Alpha']);
    app(Context::class)->setOrg($this->org);

    /** @var TestUser $user */
    $user = TestUser::create(['email' => 'editor@kitsune.test']);
    $this->user = $user;

    DB::table('org_user')->insert(['org_id' => $this->org->getKey(), 'user_id' => $user->getKey()]);

    $this->role = Role::create(['handle' => 'editor', 'name' => 'Editor']);
    DB::table('role_user')->insert(['role_id' => $this->role->getKey(), 'user_id' => $user->getKey()]);

    $this->policy = new EntryPolicy;
    $this->entry = new Entry(['type_handle' => 'article']);
});

it('resolves against the record\'s own type, not the request\'s', function (): void {
    /*
     * ⚠️ THE RECORD'S HANDLE IS THE ONE THAT COUNTS, and a record reached through a URL for another type is
     * exactly the confusion an attacker would arrange. So the container names `product` while the record
     * says `article`, and the `article` grant is what answers.
     */
    app()->instance(EntryType::class, new EntryType(['handle' => 'product']));
    $this->role->grant('entry.article.update');

    expect($this->policy->update($this->user, $this->entry))->toBeTrue()
        ->and($this->policy->update($this->user, new Entry(['type_handle' => 'product'])))->toBeFalse();
});

it('separates the actions rather than treating them as levels', function (): void {
    // Holding `update` is not holding `delete`, and holding `view` is not a floor under either.
    $this->role->grant('entry.article.update');

    expect($this->policy->update($this->user, $this->entry))->toBeTrue()
        ->and($this->policy->view($this->user, $this->entry))->toBeFalse()
        ->and($this->policy->delete($this->user, $this->entry))->toBeFalse();
});

it('reads the current type from the container when there is no record', function (): void {
    app()->instance(EntryType::class, new EntryType(['handle' => 'article']));
    $this->role->grant('entry.article.create');

    expect($this->policy->create($this->user))->toBeTrue()
        ->and($this->policy->viewAny($this->user))->toBeFalse();
});

it('refuses when no type has been established at all', function (): void {
    /*
     * ⚠️ FAIL CLOSED, and this is the case worth having a test for. A policy that fell back to "allowed"
     * because it could not tell which type it was being asked about would be an open door on every path
     * that forgot to establish one — and those paths are console commands, queue jobs and anything a module
     * adds, none of which go through `IdentifyEntryType`.
     */
    app()->forgetInstance(EntryType::class);
    $this->role->grant('entry.article.create');

    expect($this->policy->create($this->user))->toBeFalse()
        ->and($this->policy->viewAny($this->user))->toBeFalse()
        ->and($this->policy->publish($this->user))->toBeFalse();
});

it('maps restore and force-delete onto delete', function (): void {
    /*
     * ⚠️ NEITHER IS IN THE PUBLISHED VOCABULARY, so the question is which of the five they belong to. Both
     * operate on a deleted row, so the authority that removed it governs it — mapping them to `update`
     * would let an editor who may not delete an entry resurrect one, or erase it permanently.
     */
    $this->role->grant('entry.article.update');

    expect($this->policy->restore($this->user, $this->entry))->toBeFalse()
        ->and($this->policy->forceDelete($this->user, $this->entry))->toBeFalse();

    $this->role->grant('entry.article.delete');

    expect($this->policy->restore($this->user, $this->entry))->toBeTrue()
        ->and($this->policy->forceDelete($this->user, $this->entry))->toBeTrue();
});

it('refuses an entry whose type handle is empty', function (): void {
    // `type_handle` is denormalised and re-stamped on save, so this is a row written around the model —
    // and `entry..view` is a permission nobody can hold but which stops looking like a missing type.
    expect($this->policy->view($this->user, new Entry(['type_handle' => ''])))->toBeFalse();
});

it('is the policy the container resolves for an entry', function (): void {
    /*
     * ⚠️ THE REGISTRATION ITSELF, because every assertion above instantiates the policy directly and would
     * keep passing if nothing ever wired it up. That is the whole gap `e2e/permissions.spec.js` covers in a
     * browser; this is the cheap half of it, and it fails the moment the `Gate::policy()` line goes.
     */
    expect(Gate::getPolicyFor(Entry::class))->toBeInstanceOf(EntryPolicy::class);
});

it('lets an owner through every ability, without a grant', function (): void {
    $owner = Role::create(['handle' => 'owner', 'name' => 'Owner', 'is_owner' => true]);
    DB::table('role_user')->insert(['role_id' => $owner->getKey(), 'user_id' => $this->user->getKey()]);

    app()->instance(EntryType::class, new EntryType(['handle' => 'article']));

    expect($this->policy->viewAny($this->user))->toBeTrue()
        ->and($this->policy->create($this->user))->toBeTrue()
        ->and($this->policy->delete($this->user, $this->entry))->toBeTrue()
        ->and($this->policy->publish($this->user, $this->entry))->toBeTrue()
        ->and(Permissions::held($this->user))->toBe([]);
});
