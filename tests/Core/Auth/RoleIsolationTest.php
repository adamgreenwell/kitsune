<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\RolePermission;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\TestImpostor;
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

it('refuses to change authority on a role from another org', function (): void {
    /*
     * ⚠️ A STALE INSTANCE OUTLIVES ITS SCOPE — review found it. `OrgScope` filters the QUERY that loaded a
     * role and says nothing about the object afterwards, so in a multi-org command or a long-lived worker
     * the context moves on while the instance does not. `assignTo()` would have written org A's role onto a
     * user while operating in org B, and recorded the audit row under B — worse than no row, because it is
     * a false one.
     *
     * The scope cannot catch it: `grant()` reaches the deliberately unscoped `role_permissions`, and
     * `assignTo()` writes `role_user` with a raw id. So the refusal belongs where the authority changes.
     */
    joinOrg($this->beta, $this->user);

    app(Context::class)->setOrg($this->beta);

    expect(fn () => $this->alphaRole->grant('entry.article.view'))
        ->toThrow(RuntimeException::class, 'Refusing [grant]')
        ->and(fn () => $this->alphaRole->revoke('entry.article.update'))
        ->toThrow(RuntimeException::class, 'Refusing [revoke]')
        ->and(fn () => $this->alphaRole->assignTo($this->user->getKey()))
        ->toThrow(RuntimeException::class, 'Refusing [assignTo]')
        ->and(fn () => $this->alphaRole->removeFrom($this->user->getKey()))
        ->toThrow(RuntimeException::class, 'Refusing [removeFrom]');

    // And nothing was written on the way to the exception.
    expect(DB::table('role_user')->count())->toBe(0)
        ->and($this->alphaRole->permissions()->count())->toBe(1);
});

it('refuses the same operations with no org context at all', function (): void {
    // The console case the refusal is really for: a command that never established one.
    app(Context::class)->forget();

    expect(fn () => $this->alphaRole->assignTo($this->user->getKey()))
        ->toThrow(RuntimeException::class, 'the current context is none');
});

it('drops the owner memo when the flag changes, not only when a helper runs', function (): void {
    /*
     * ⚠️ THE FOUR HELPERS WERE NOT ENOUGH, and `is_owner` is the widest grant in the system. Flipping it
     * through an ordinary `save()` left `Permissions::isOwner()` answering from before the write — a
     * demoted owner keeping the bypass for the rest of the request, and a promotion that did not take.
     */
    assign($this->alphaRole, $this->user);
    joinOrg($this->alpha, $this->user);

    app(Context::class)->setOrg($this->alpha);

    expect(Permissions::isOwner($this->user))->toBeFalse();

    $this->alphaRole->is_owner = true;
    $this->alphaRole->save();

    expect(Permissions::isOwner($this->user))->toBeTrue();

    /*
     * ⚠️ A SPARE OWNER FIRST, held by SOMEBODY ELSE. An org may not lose its last held owner role (#84), so
     * without this the change below is refused — and held by another person because the same one would keep
     * the bypass and the assertion would measure the guard instead of the thing under test. Two features
     * written hours apart, and only their combination states the rule.
     */
    /** @var TestUser $spareHolder */
    $spareHolder = TestUser::create(['email' => 'spare'.mt_rand(1, 1_000_000_000).'@kitsune.test']);
    joinOrg($this->alpha, $spareHolder);

    $spareOwner = Role::create(['handle' => 'owner-spare'.mt_rand(1, 1_000_000_000), 'name' => 'Owner spare', 'is_owner' => true]);
    $spareOwner->assignTo($spareHolder->getKey());

    // And deleting it takes the bypass away again, in the same process.
    $this->alphaRole->delete();

    expect(Permissions::isOwner($this->user))->toBeFalse();
});

it('asks membership of the model that is actually signed in, not a configured name', function (): void {
    /*
     * ⚠️ REVIEW FOUND A FAIL-OPEN. The membership check read `config('auth.providers.users.model')` — but a
     * panel may authenticate through a provider that is not named `users`, and the name is the host's to
     * choose. Point the config elsewhere and the check either read an unrelated model or found none and took
     * a permissive fallback, so a `role_user` row conferred grants on somebody who is not a member of the
     * org at all.
     *
     * It asks the authenticated instance's own class now, which cannot be wrong about which model it is.
     * Here the provider is deliberately misconfigured and the answer must be unchanged.
     */
    assign($this->alphaRole, $this->user);
    joinOrg($this->alpha, $this->user);

    app(Context::class)->setOrg($this->alpha);

    config(['auth.providers.users.model' => null]);

    expect(Permissions::allows($this->user, 'entry.article.update'))->toBeTrue();

    // And a non-member is still refused with the config pointing nowhere.
    /** @var TestUser $outsider */
    $outsider = TestUser::create(['email' => 'outsider@kitsune.test']);
    assign($this->alphaRole, $outsider);

    expect(Permissions::allows($outsider, 'entry.article.update'))->toBeFalse();
});

it('writes no authority change when its audit row cannot be written', function (): void {
    /*
     * ⚠️ AN UNAUDITED AUTHORITY CHANGE IS THE THING ADR-020 REFUSES, and review found the write split in
     * two: under autocommit the grant landed and the audit insert failed after it, so the caller saw an
     * exception while the change stayed. `AuditedBuilder` puts an entry's insert and its audit row in one
     * transaction for this reason; a write that is not an entry needs the same.
     *
     * The failure is forced the way it would really happen — a context naming a site that no longer exists,
     * which `audit_log.site_id` refuses.
     */
    app(Context::class)->setOrg($this->alpha);

    $site = Site::create(['org_id' => $this->alpha->getKey(), 'handle' => 'ghost', 'slug' => 'ghost', 'name' => 'Ghost']);
    app(Context::class)->setSite($site);

    DB::table('sites')->where('id', $site->getKey())->delete();

    // ⚠️ `QueryException` rather than `Throwable`: Pest's `toThrow()` decides class-versus-message with
    // `class_exists()`, which is false for an INTERFACE — so `Throwable::class` was compared against the
    // exception's message and the test failed on a throw that had happened exactly as intended.
    expect(fn () => $this->alphaRole->grant('entry.note.view'))->toThrow(QueryException::class);

    // The grant did not survive the audit's failure.
    expect($this->alphaRole->permissions()->where('permission', 'entry.note.view')->exists())->toBeFalse();
})->skip(fn (): bool => DB::connection()->getDriverName() === 'sqlite' && ! DB::connection()->getPdo()->query('PRAGMA foreign_keys')->fetchColumn(),
    'foreign keys are not enforced on this connection, so the audit insert cannot be made to fail');

it('records an owner bypass gained by flipping the flag, not only by assignment', function (): void {
    /*
     * ⚠️ THE WIDEST GRANT IN THE SYSTEM ARRIVING UNRECORDED — review found it. Turning `is_owner` on for a
     * role that already has holders gives every one of them the bypass immediately, and their assignment
     * rows were logged as `role.assigned`, so nothing in the log said they were owners now. ADR-033 claims
     * the trail answers *who was made an owner*, and this path defeated it.
     */
    app(Context::class)->setOrg($this->alpha);

    assign($this->alphaRole, $this->user);
    joinOrg($this->alpha, $this->user);

    /** @var TestUser $colleague */
    $colleague = TestUser::create(['email' => 'colleague@kitsune.test']);
    assign($this->alphaRole, $colleague);
    joinOrg($this->alpha, $colleague);

    /*
     * ⚠️ A HIGH-WATER MARK RATHER THAN A TRUNCATE, because the log is append-only by construction —
     * `AuditLog::query()->delete()` throws: *"Retention is an operator policy applied to the table, not
     * something application code decides (ADR-020)."* A test that wanted to clear it is a test arguing with
     * the design.
     */
    $mark = (int) AuditLog::query()->max('id');

    $this->alphaRole->update(['is_owner' => true]);

    // ⚠️ One row per affected PERSON, because the question is about people rather than about the role. A
    // single `role.updated` would record that something changed and leave the answer where it was.
    $elevations = AuditLog::query()->where('id', '>', $mark)->get();

    expect($elevations->pluck('action')->all())
        ->toBe(['role.owner_assigned', 'role.owner_assigned'])
        ->and($elevations->pluck('target_id')->map(intval(...))->sort()->values()->all())
        ->toBe(collect([$this->user->getKey(), $colleague->getKey()])->sort()->values()->all());

    /*
     * ⚠️ A SPARE OWNER FIRST, held by SOMEBODY ELSE. An org may not lose its last held owner role (#84), so
     * without this the change below is refused — and held by another person because the same one would keep
     * the bypass and the assertion would measure the guard instead of the thing under test. Two features
     * written hours apart, and only their combination states the rule.
     */
    /** @var TestUser $spareHolder */
    $spareHolder = TestUser::create(['email' => 'spare'.mt_rand(1, 1_000_000_000).'@kitsune.test']);
    joinOrg($this->alpha, $spareHolder);

    $spareOwner = Role::create(['handle' => 'owner-spare'.mt_rand(1, 1_000_000_000), 'name' => 'Owner spare', 'is_owner' => true]);
    $spareOwner->assignTo($spareHolder->getKey());

    $mark = (int) AuditLog::query()->max('id');

    $this->alphaRole->update(['is_owner' => false]);

    expect(AuditLog::query()->where('id', '>', $mark)->pluck('action')->all())
        ->toBe(['role.owner_unassigned', 'role.owner_unassigned']);
});

it('records nothing when the flag did not move, or when nobody holds the role', function (): void {
    // A flag flipped on a role nobody holds grants nothing — the same line ADR-033 draws about creating one.
    app(Context::class)->setOrg($this->alpha);

    $mark = (int) AuditLog::query()->max('id');

    $this->alphaRole->update(['name' => 'Renamed']);
    $this->alphaRole->update(['is_owner' => true]);

    expect(AuditLog::query()->where('id', '>', $mark)->count())->toBe(0);
});

it('refuses a bulk write to the owner flag, and a bulk delete', function (): void {
    /*
     * ⚠️ EVERY GUARANTEE ABOUT THE OWNER FLAG LIVED IN A LIFECYCLE HOOK, which is to say it was true of the
     * row-at-a-time path and of nothing else — review named the idiom: `Role::query()->update(['is_owner' =>
     * …])` dispatches no event, so holders gained the bypass with no audit rows and a memoised answer stayed
     * stale. The third time this project has learned that a guard belongs where the write is (`AuditedBuilder`
     * for entries, `GuardedStorageBuilder` for field storage).
     *
     * Bulk DELETE is refused outright, because deleting a role revokes it from every holder by cascade and
     * that audit is per holder too.
     */
    app(Context::class)->setOrg($this->alpha);

    expect(fn () => Role::query()->update(['is_owner' => true]))
        ->toThrow(RuntimeException::class, 'bulk write to `is_owner`')
        ->and(fn () => Role::query()->whereKey($this->alphaRole->getKey())->delete())
        ->toThrow(RuntimeException::class, 'bulk delete of roles');

    // The ordinary paths still work — the flag is what tells them apart, not the shape of the call.
    $this->alphaRole->update(['name' => 'Still editable']);
    $this->alphaRole->delete();

    expect(Role::query()->count())->toBe(0);
});

it('refuses to change the owner flag on a role from another org', function (): void {
    /*
     * ⚠️ `EnforcesScope` REVALIDATES A SCOPE KEY ONLY WHEN IT IS DIRTY, which review found: an org A role
     * retained after a worker moved to org B could still be promoted, and the audit row was then written
     * under B — or dropped silently with no context at all. The four authority helpers already refused this;
     * the flag was the fifth way authority changes and was not asking.
     */
    joinOrg($this->beta, $this->user);
    app(Context::class)->setOrg($this->beta);

    expect(fn () => $this->alphaRole->update(['is_owner' => true]))
        ->toThrow(RuntimeException::class, 'Refusing [change the owner flag on]');

    // A change that does not touch the flag is still refused by nothing here — that is the scope's job.
    expect($this->alphaRole->fresh()?->is_owner)->toBeFalse();
});

it('records the revocation when an owner role is deleted out from under its holders', function (): void {
    /*
     * ⚠️ THE DATABASE CASCADES `role_user` AND NOTHING WAS RECORDING IT — review found it. Deleting an owner
     * role took the bypass away from every holder with no `role.owner_unassigned` anywhere, while ADR-033
     * says the trail answers who gained or lost that authority.
     *
     * The rows go in before the delete and inside the same transaction, because afterwards there is no
     * `role_user` left to read them from.
     */
    app(Context::class)->setOrg($this->alpha);

    $owner = Role::create(['handle' => 'owner', 'name' => 'Owner', 'is_owner' => true]);
    $owner->assignTo($this->user->getKey());

    /*
     * ⚠️ A SPARE OWNER FIRST, held by SOMEBODY ELSE. An org may not lose its last held owner role (#84), so
     * without this the change below is refused — and held by another person because the same one would keep
     * the bypass and the assertion would measure the guard instead of the thing under test. Two features
     * written hours apart, and only their combination states the rule.
     */
    /** @var TestUser $spareHolder */
    $spareHolder = TestUser::create(['email' => 'spare'.mt_rand(1, 1_000_000_000).'@kitsune.test']);
    joinOrg($this->alpha, $spareHolder);

    $spareOwner = Role::create(['handle' => 'owner-spare'.mt_rand(1, 1_000_000_000), 'name' => 'Owner spare', 'is_owner' => true]);
    $spareOwner->assignTo($spareHolder->getKey());

    $mark = (int) AuditLog::query()->max('id');

    $owner->delete();

    expect(AuditLog::query()->where('id', '>', $mark)->pluck('action')->all())
        ->toBe(['role.owner_unassigned'])
        ->and(AuditLog::query()->where('id', '>', $mark)->value('target_id'))
        ->toBe($this->user->getKey());
});

it('leaves no authority change behind when its audit cannot be written', function (): void {
    /*
     * ⚠️ THE AUDIT RUNS IN `saved`, WHICH IS AFTER THE ROW COMMITS under autocommit — so an audit failure
     * left the caller with an exception and every holder's authority already changed. With several holders
     * it could leave a PARTIAL trail, which is worse than none because it reads as complete. The whole save
     * is one transaction now.
     */
    app(Context::class)->setOrg($this->alpha);

    $owner = Role::create(['handle' => 'owner', 'name' => 'Owner']);
    $owner->assignTo($this->user->getKey());

    $site = Site::create(['org_id' => $this->alpha->getKey(), 'handle' => 'ghost2', 'slug' => 'ghost2', 'name' => 'Ghost']);
    app(Context::class)->setSite($site);
    DB::table('sites')->where('id', $site->getKey())->delete();

    expect(fn () => $owner->update(['is_owner' => true]))->toThrow(QueryException::class);

    expect($owner->fresh()?->is_owner)->toBeFalse();
})->skip(fn (): bool => DB::connection()->getDriverName() === 'sqlite'
    && ! DB::connection()->getPdo()->query('PRAGMA foreign_keys')->fetchColumn(),
    'foreign keys are not enforced on this connection, so the audit insert cannot be made to fail');

it('refuses to remove the last owner role an org actually holds', function (): void {
    /*
     * ⚠️ AN ORG THAT LOSES ITS LAST OWNER CANNOT GET ONE BACK — issue #84. Schema editing and role
     * administration are both owner-only in v1.0 (ADR-033), so the only person who could restore the flag is
     * the one who just removed it, and the vocabulary has no permission that would let anybody else. A
     * support ticket is the recovery path, and there is no support.
     *
     * ⚠️ AT THE MODEL RATHER THAN IN A FORM, which is the rule this project keeps relearning: the ROUTE is
     * the boundary, not the button. A form guard is bypassed by the API, by a console command, and by the
     * next page somebody writes.
     */
    app(Context::class)->setOrg($this->alpha);

    $owner = Role::create(['handle' => 'owner', 'name' => 'Owner', 'is_owner' => true]);
    $owner->assignTo($this->user->getKey());

    // ⚠️ Membership as well as assignment, because the guard counts only holders who are MEMBERS — holding
    // a role in an org you have left confers nothing, so it cannot be the org's safety net either.
    joinOrg($this->alpha, $this->user);

    expect(fn () => $owner->update(['is_owner' => false]))
        ->toThrow(RuntimeException::class, 'only owner role')
        ->and(fn () => $owner->delete())
        ->toThrow(RuntimeException::class, 'only owner role');

    // Still an owner role, and still held.
    expect(Role::query()->where('is_owner', true)->count())->toBe(1);
});

it('allows it once a second owner role is held', function (): void {
    // The guard is about the LAST one. A second holder is the way out, and it has to work.
    app(Context::class)->setOrg($this->alpha);

    $first = Role::create(['handle' => 'owner', 'name' => 'Owner', 'is_owner' => true]);
    $first->assignTo($this->user->getKey());

    /** @var TestUser $second */
    $second = TestUser::create(['email' => 'second-owner@kitsune.test']);
    joinOrg($this->alpha, $second);

    $spare = Role::create(['handle' => 'owner-2', 'name' => 'Owner 2', 'is_owner' => true]);
    $spare->assignTo($second->getKey());

    $first->delete();

    expect(Role::query()->where('is_owner', true)->count())->toBe(1);
});

it('does not stand in the way of an owner role nobody holds yet', function (): void {
    /*
     * ⚠️ IT ASKS WHETHER THIS CHANGE TAKES THE LAST ONE, not whether the result has any. A fresh install
     * mid-seed has an owner role with no holders, and a guard that read the second question would refuse to
     * let a seeder correct one.
     */
    app(Context::class)->setOrg($this->alpha);

    $unheld = Role::create(['handle' => 'owner', 'name' => 'Owner', 'is_owner' => true]);

    $unheld->update(['is_owner' => false]);
    $unheld->delete();

    expect(Role::query()->where('is_owner', true)->count())->toBe(0);
});

it('does not count another org\'s owners as this org\'s safety net', function (): void {
    // The cross-org version: beta having owners must not make alpha safe to strip.
    app(Context::class)->setOrg($this->beta);

    /** @var TestUser $theirs */
    $theirs = TestUser::create(['email' => 'beta-owner@kitsune.test']);
    joinOrg($this->beta, $theirs);

    $betaOwner = Role::create(['handle' => 'owner', 'name' => 'Owner', 'is_owner' => true]);
    $betaOwner->assignTo($theirs->getKey());

    app(Context::class)->setOrg($this->alpha);

    $alphaOwner = Role::create(['handle' => 'owner', 'name' => 'Owner', 'is_owner' => true]);
    $alphaOwner->assignTo($this->user->getKey());
    joinOrg($this->alpha, $this->user);

    expect(fn () => $alphaOwner->delete())->toThrow(RuntimeException::class, 'only owner role');
});

it('does not take away an owner role from its last member holder', function (): void {
    /*
     * ⚠️ THE OTHER HALF OF THE LOCK-OUT, which review found open while deleting the role was shut. The role
     * form calls `removeFrom()` for every holder taken out of the selection, so an owner could remove the
     * final holder — themselves — and lose role and schema administration on the next request.
     * `refuseIfLastOwner()` guards the ROLE; this guards its last holder.
     */
    app(Context::class)->setOrg($this->alpha);

    $owner = Role::create(['handle' => 'owner', 'name' => 'Owner', 'is_owner' => true]);
    $owner->assignTo($this->user->getKey());
    joinOrg($this->alpha, $this->user);

    expect(fn () => $owner->removeFrom($this->user->getKey()))
        ->toThrow(RuntimeException::class, 'last member of this organisation holding an owner role');

    // A second member holding one is the way out, and it has to work.
    /** @var TestUser $colleague */
    $colleague = TestUser::create(['email' => 'second-admin@kitsune.test']);
    joinOrg($this->alpha, $colleague);
    $owner->assignTo($colleague->getKey());

    $owner->removeFrom($this->user->getKey());

    expect(DB::table('role_user')->where('role_id', $owner->getKey())->count())->toBe(1);
});

it('counts only holders who are members of the org as owners', function (): void {
    /*
     * ⚠️ HOLDING AN OWNER ROLE IS NOT ENOUGH — review found the guard ignoring membership. `assignTo()` is
     * public and membership can be removed afterwards, so a `role_user` row may name somebody this org no
     * longer contains. Counting that inert pivot let the last role held by a REAL member be demoted, while
     * `Permissions` refuses the remaining assignee and nobody can administer the org.
     */
    app(Context::class)->setOrg($this->alpha);

    $held = Role::create(['handle' => 'owner', 'name' => 'Owner', 'is_owner' => true]);
    $held->assignTo($this->user->getKey());
    joinOrg($this->alpha, $this->user);

    // An owner role assigned to somebody who is NOT a member of this org.
    /** @var TestUser $outsider */
    $outsider = TestUser::create(['email' => 'not-a-member@kitsune.test']);

    $inert = Role::create(['handle' => 'owner-inert', 'name' => 'Owner inert', 'is_owner' => true]);
    $inert->assignTo($outsider->getKey());

    // The inert one must not count as this org's safety net.
    expect(fn () => $held->delete())
        ->toThrow(RuntimeException::class, 'only owner role');
});

it('refuses to count owners through a model the assignments are not about', function (): void {
    /*
     * ⚠️ A NUMERIC ID IS NOT AN IDENTITY, AND THE LOCK-OUT GUARD WAS TAKING IT FOR ONE — review found the
     * count doing what `Permissions::roleIdsFor()` had already been fixed not to do. `role_user.user_id`
     * means whatever table it references; a host running two panels has two user models on two tables with
     * two sequences, so both have a user with this id. Filtering the holders through the WRONG one invents
     * an owner out of an unrelated row — and the guard then permits the removal of the only real one.
     *
     * That failure has no recovery path: owner is the only role that may administer roles (ADR-033), so an
     * org with no effective owner cannot get one back. Refusing is the other guess, and it is the reversible
     * one — in this configuration `Permissions` confers no role authority at all, so there is nothing being
     * withheld that would otherwise work.
     */
    app(Context::class)->setOrg($this->alpha);

    $owner = Role::create(['handle' => 'owner', 'name' => 'Owner', 'is_owner' => true]);
    $owner->assignTo($this->user->getKey());
    joinOrg($this->alpha, $this->user);

    /*
     * A departed holder of the same role: the assignment survives, the membership does not, so they are not
     * an effective owner — which is what makes the impostor with their id the phantom.
     */
    /** @var TestUser $departed */
    $departed = TestUser::create(['email' => 'departed-owner@kitsune.test']);
    $owner->assignTo($departed->getKey());

    // The collision is constructed rather than hoped for; a Postgres sequence does not roll back.
    $impostor = new TestImpostor(['name' => 'Not the holder']);
    $impostor->id = $departed->getKey();
    $impostor->save();

    DB::table('pivot_scoped_thing_org')->insert([
        'org_id' => $this->alpha->getKey(),
        'pivot_scoped_thing_id' => $impostor->getKey(),
    ]);

    expect($impostor->getKey())->toBe($departed->getKey());

    // The provider now names that model: on another table, with a row for the departed holder's id.
    config(['auth.providers.users.model' => TestImpostor::class]);
    Permissions::forget();

    expect(fn () => $owner->removeFrom($this->user->getKey()))
        ->toThrow(RuntimeException::class, 'is not the one that column references');

    // And the real owner still holds the role, which is the consequence the guard exists for.
    expect(DB::table('role_user')
        ->where('role_id', $owner->getKey())
        ->where('user_id', $this->user->getKey())
        ->exists())->toBeTrue();
});

it('refuses to delete a role that belongs to another org', function (): void {
    /*
     * ⚠️ THE FIFTH AUTHORITY PATH, and it was not asking — review found it. Eloquent's instance delete writes
     * by primary key without reapplying the global scope, and the `deleting` event enables the guarded
     * builder, so a role that outlived an org-context switch could be deleted while running in another org —
     * with its revocation audits attributed to that org.
     */
    joinOrg($this->beta, $this->user);
    app(Context::class)->setOrg($this->beta);

    expect(fn () => $this->alphaRole->delete())
        ->toThrow(RuntimeException::class, 'Refusing [delete]');

    app(Context::class)->setOrg($this->alpha);

    expect(Role::query()->whereKey($this->alphaRole->getKey())->exists())->toBeTrue();
});

it('records what every holder lost when a role is deleted, owner or not', function (): void {
    /*
     * ⚠️ NOT ONLY AN OWNER'S — review found the first version recording revocations for owner roles alone,
     * while the database cascades `role_user` for every role and a role carrying ordinary grants is authority
     * too. ADR-033's guarantee is about authority, so the log has to be as well.
     */
    app(Context::class)->setOrg($this->alpha);

    $plain = Role::create(['handle' => 'contributor', 'name' => 'Contributor']);
    $plain->grant('entry.article.update');
    $plain->assignTo($this->user->getKey());
    joinOrg($this->alpha, $this->user);

    $mark = (int) AuditLog::query()->max('id');

    $plain->delete();

    expect(AuditLog::query()->where('id', '>', $mark)->pluck('action')->all())
        ->toBe(['role.unassigned'])
        ->and(AuditLog::query()->where('id', '>', $mark)->value('target_id'))
        ->toBe($this->user->getKey());
});

it('refuses the owner flag through the arithmetic family too', function (): void {
    /*
     * ⚠️ `update()` WAS ONE DOOR OF FOUR, which review found by name: *"inherited `increment()`,
     * `decrement()`, `incrementEach()` and `decrementEach()` bypass this override and write through the
     * query builder"*. Laravel's arithmetic methods take an `$extra` map of ORDINARY assignments, so
     * `increment('id', 0, ['is_owner' => true])` is a bulk promotion wearing another method's name — no
     * per-holder audit, no `Permissions::forget()`, no instance org check, and the memoised answer left
     * standing. The same lesson as the bulk update above, one API call along.
     *
     * ⚠️ THE INCREMENTED COLUMN COUNTS AS WELL AS THE EXTRAS: adding to a per-row column is no safer than
     * assigning it, so `increment('is_owner')` is refused on its own account.
     */
    app(Context::class)->setOrg($this->alpha);
    assign($this->alphaRole, $this->user);

    $key = $this->alphaRole->getKey();
    $mark = (int) AuditLog::query()->max('id');

    expect(fn () => Role::query()->whereKey($key)->increment('id', 0, ['is_owner' => true]))
        ->toThrow(RuntimeException::class, 'arithmetic write to `is_owner`')
        ->and(fn () => Role::query()->whereKey($key)->decrement('id', 0, ['is_owner' => true]))
        ->toThrow(RuntimeException::class, 'arithmetic write to `is_owner`')
        ->and(fn () => Role::query()->whereKey($key)->incrementEach(['id' => 0], ['is_owner' => true]))
        ->toThrow(RuntimeException::class, 'arithmetic write to `is_owner`')
        ->and(fn () => Role::query()->whereKey($key)->decrementEach(['id' => 0], ['is_owner' => true]))
        ->toThrow(RuntimeException::class, 'arithmetic write to `is_owner`')
        ->and(fn () => Role::query()->whereKey($key)->increment('is_owner'))
        ->toThrow(RuntimeException::class, 'arithmetic write to `is_owner`')
        // And the scope key, which the same list guards for the same reason.
        ->and(fn () => Role::query()->whereKey($key)->increment('id', 0, ['org_id' => $this->beta->getKey()]))
        ->toThrow(RuntimeException::class, 'increment or decrement [org_id]');

    // Nothing moved, and nothing was recorded as having moved.
    expect(Role::query()->whereKey($key)->first()?->is_owner)->toBeFalse()
        ->and(AuditLog::query()->where('id', '>', $mark)->count())->toBe(0);

    // ⚠️ An arithmetic write to an ORDINARY column still works, or this is a refusal of the method rather
    // than of the column — which would be a different and wrong guarantee.
    Role::query()->whereKey($key)->increment('id', 0, ['name' => 'Renamed in bulk']);

    expect(Role::query()->whereKey($key)->first()?->name)->toBe('Renamed in bulk');
});

it('refuses a bulk force-delete of roles', function (): void {
    /*
     * ⚠️ ELOQUENT SENDS `forceDelete()` STRAIGHT TO THE QUERY BUILDER rather than through `delete()`, which
     * review found: the roles and their cascading `role_user` rows went with none of the per-holder
     * revocation audits and no `Permissions::forget()`, so cached grants stayed usable for the rest of the
     * process — answering for authority that no longer existed.
     */
    app(Context::class)->setOrg($this->alpha);
    assign($this->alphaRole, $this->user);

    $key = $this->alphaRole->getKey();
    $mark = (int) AuditLog::query()->max('id');

    expect(fn () => Role::query()->whereKey($key)->forceDelete())
        ->toThrow(RuntimeException::class, 'bulk force-delete of roles');

    expect(Role::query()->whereKey($key)->exists())->toBeTrue()
        ->and(DB::table('role_user')->where('role_id', $key)->count())->toBe(1)
        ->and(AuditLog::query()->where('id', '>', $mark)->count())->toBe(0);

    // The instance path still records the revocation, which is what the refusal is protecting.
    $this->alphaRole->delete();

    expect(AuditLog::query()->where('id', '>', $mark)->where('action', 'role.unassigned')->count())->toBe(1);
});

it('refuses to transfer a role into the org the caller has moved to', function (): void {
    /*
     * ⚠️ THE OWNER-FLAG GUARD STOOD ASIDE AND `EnforcesScope` WAS SATISFIED, which is the hole review found
     * one step past both. The flag check fires only when `is_owner` is dirty; `EnforcesScope` revalidates a
     * scope key when it is dirty and validates the value being WRITTEN against the current context. So a
     * role loaded under alpha, with `org_id` set to beta while the context is beta, passed everything — and
     * moved alpha's role to beta carrying its grants and its assignments, taking alpha's owner with it and
     * conferring authority in beta with no audit row anywhere.
     *
     * The stored org is what has to match. The value being written is the one a transfer is arranging.
     */
    assign($this->alphaRole, $this->user);
    joinOrg($this->beta, $this->user);
    app(Context::class)->setOrg($this->beta);

    $this->alphaRole->org_id = $this->beta->getKey();

    /*
     * ⚠️ THE MESSAGE MOVED WITH THE GUARD, and the guard moved because review found the first one forgeable:
     * it compared `getOriginal('org_id')` to the context, and `syncOriginal()` is public. The refusal now
     * comes from `refuseIfNotCurrentOrg('save')`, which asks `OrgScope`'s own query about the stored row —
     * same behaviour, stronger evidence, and one guard instead of two.
     */
    expect(fn () => $this->alphaRole->save())
        ->toThrow(RuntimeException::class, 'Refusing [save] on role '.$this->alphaRole->getKey());

    app(Context::class)->setOrg($this->alpha);

    expect(Role::query()->whereKey($this->alphaRole->getKey())->value('org_id'))->toBe($this->alpha->getKey());

    // And the grants and assignments went nowhere either, which is what the transfer would have carried.
    expect(RolePermission::query()->where('role_id', $this->alphaRole->getKey())->count())->toBe(1)
        ->and(DB::table('role_user')->where('role_id', $this->alphaRole->getKey())->count())->toBe(1);
});

it('refuses an ordinary save of another org\'s role, not only a transfer', function (): void {
    /*
     * The same guard, on the case that changes no scope key at all: a role loaded in alpha and saved while
     * the context is beta is a write to another customer's row whatever column moved. The five authority
     * helpers already refused it; `save()` was the path that did not.
     */
    app(Context::class)->setOrg($this->beta);

    $this->alphaRole->name = 'Renamed from another org';

    expect(fn () => $this->alphaRole->save())->toThrow(RuntimeException::class, 'Refusing [save] on role');

    app(Context::class)->setOrg($this->alpha);

    expect(Role::query()->whereKey($this->alphaRole->getKey())->value('name'))->toBe('Editor');
});

it('leaves the move in the other direction to the scope, which refuses it', function (): void {
    /*
     * ⚠️ ASSERTED RATHER THAN CLAIMED IN A DOCBLOCK. The guard in `saving` asks "is this ROW mine to write",
     * which cannot see a role of MINE being pushed into somebody else's org — that is a question about the
     * VALUE, and `EnforcesScope::guardScopeKey()` is what refuses it. Two guards, two questions, and this is
     * what keeps the second one from being assumed.
     */
    app(Context::class)->setOrg($this->alpha);

    $this->alphaRole->org_id = $this->beta->getKey();

    expect(fn () => $this->alphaRole->save())
        ->toThrow(RuntimeException::class, 'Refusing to write Kitsune\Core\Models\Role with [org_id]');

    expect(Role::query()->whereKey($this->alphaRole->getKey())->value('org_id'))->toBe($this->alpha->getKey());
});

it('does not keep the authority proof after a save that threw', function (): void {
    /*
     * ⚠️ `saved` CLEARS THE FLAG AND AN ABORTED SAVE NEVER REACHES `saved` — review found it. The audit
     * insert in that listener can fail for a reason that has nothing to do with the role: a context holding
     * a site another request has deleted is enough, since `audit_log.site_id` is a foreign key. The
     * transaction rolls back, the caller catches, and the instance is left claiming its guards had run — so
     * a retry through `saveQuietly()`, which fires no listener at all, would present that proof to
     * `GuardedRoleBuilder` for a write nothing checked.
     *
     * `DerivesGuardedColumns` records the same rule: a proof belongs to one attempt, however the attempt
     * ends.
     */
    app(Context::class)->setOrg($this->alpha);
    assign($this->alphaRole, $this->user);

    $site = Site::create([
        'org_id' => $this->alpha->getKey(), 'handle' => 'doomed', 'slug' => 'doomed', 'name' => 'Doomed',
        'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://doomed.test',
    ]);
    app(Context::class)->setSite($site);

    // The audit row's `site_id` now names a site that is gone, so `saved`'s insert fails inside the save.
    Site::query()->whereKey($site->getKey())->withoutGlobalScopes()->delete();

    $this->alphaRole->is_owner = true;

    /*
     * ⚠️ `QueryException`, NOT `Throwable`. Pest's `toThrow()` treats a first argument that is not a
     * `class_exists()` CLASS as a message to match, and an interface is not one — so `toThrow(Throwable::class)`
     * silently compares the string "Throwable" against the exception's message and fails on a test that is
     * working. Recorded once already in this project; naming the concrete class is the answer.
     */
    expect(fn () => $this->alphaRole->save())->toThrow(QueryException::class);

    /*
     * ⚠️ ASSERTED THROUGH THE BUILDER RATHER THAN ON A PROPERTY, because the property is private now — review
     * found the public version forgeable and the fix removed the thing this line used to read. What matters
     * was never the boolean but what it buys: a retry that fires no listener must not be accepted as guarded.
     */
    app(Context::class)->setOrg($this->alpha);

    expect(fn () => $this->alphaRole->saveQuietly())
        ->toThrow(RuntimeException::class, 'bulk write to `is_owner`')
        ->and(Role::query()->whereKey($this->alphaRole->getKey())->value('is_owner'))->toBeFalsy();
});

it('does not resolve one user model\'s assignments for another model\'s matching id', function (): void {
    /*
     * ⚠️ A NUMERIC ID IS NOT AN IDENTITY, which review found after the memo and the membership check had
     * both already learned to carry the authenticated model's CLASS. `roleIdsFor()` still matched on
     * `user_id` alone — so in a host running two panels through two providers, two user models sit on two
     * tables with two independent sequences, both have a user 1, and the second one was handed the first's
     * roles the moment they belonged to the current org. A grant resolving for the wrong person is the one
     * failure this layer exists to prevent.
     *
     * What says whose ids these are is the table `role_user.user_id` REFERENCES — `users`, by the skeleton's
     * `constrained()`. `TestImpostor` is a member of this org, is authenticatable, and lives on another
     * table.
     */
    app(Context::class)->setOrg($this->alpha);

    assign($this->alphaRole, $this->user);
    joinOrg($this->alpha, $this->user);

    $owner = Role::create(['handle' => 'owner', 'name' => 'Owner', 'is_owner' => true]);
    $owner->assignTo($this->user->getKey());

    /*
     * ⚠️ THE COLLISION IS CONSTRUCTED, NOT HOPED FOR — and hoping for it failed on Postgres in the full
     * suite. Two tables have two sequences, and a Postgres sequence does not roll back with the transaction
     * `RefreshDatabase` wraps each test in, so which ids these two tables hand out depends on how many rows
     * every earlier test created in each. The assertion below caught it rather than the test going quiet,
     * which is what that assertion is for; the id is now assigned so the shape holds on every engine.
     */
    $impostor = new TestImpostor(['name' => 'Also number one']);
    $impostor->id = $this->user->getKey();
    $impostor->save();

    DB::table('pivot_scoped_thing_org')->insert([
        'org_id' => $this->alpha->getKey(),
        'pivot_scoped_thing_id' => $impostor->getKey(),
    ]);

    expect($impostor->getKey())->toBe($this->user->getKey());

    // The real user holds both, which is what makes the impostor's answers meaningful.
    expect(Permissions::held($this->user))->toBe(['entry.article.update'])
        ->and(Permissions::isOwner($this->user))->toBeTrue();

    expect(Permissions::held($impostor))->toBe([])
        ->and(Permissions::isOwner($impostor))->toBeFalse()
        ->and(Permissions::allows($impostor, 'entry.article.update'))->toBeFalse();
});

it('refuses a direct write to the unscoped grants table', function (): void {
    /*
     * ⚠️ `#[Unscoped]` IS AN ARGUMENT ABOUT READS AND IT WAS COVERING WRITES TOO — review found it. Nothing
     * narrows a write to `role_permissions` either, and the table deliberately has no `org_id` for a clause
     * to narrow: so the ordinary published model API could revoke every org's grants in one call, or attach
     * one to another org's role, with no validation, no audit row and no memo flush.
     *
     * "A reviewer should treat a bare `RolePermission::query()` as a defect" is attention rather than
     * enforcement, which is what this test replaces.
     */
    app(Context::class)->setOrg($this->alpha);

    $betaRole = null;

    app(Context::class)->setOrg($this->beta);
    $betaRole = Role::create(['handle' => 'rival', 'name' => 'Rival']);
    $betaRole->grant('entry.article.view');
    app(Context::class)->setOrg($this->alpha);

    $before = RolePermission::query()->count();

    expect($before)->toBe(2);

    expect(fn () => RolePermission::query()->delete())
        ->toThrow(RuntimeException::class, 'Refusing delete() on role_permissions')
        ->and(fn () => RolePermission::query()->where('role_id', $betaRole->getKey())->delete())
        ->toThrow(RuntimeException::class, 'Refusing delete() on role_permissions')
        ->and(fn () => RolePermission::create(['role_id' => $betaRole->getKey(), 'permission' => 'entry.article.delete']))
        ->toThrow(RuntimeException::class, 'Refusing insertGetId() on role_permissions')
        ->and(fn () => RolePermission::query()->update(['permission' => 'entry.article.delete']))
        ->toThrow(RuntimeException::class, 'Refusing update() on role_permissions')
        ->and(fn () => RolePermission::query()->increment('role_id', 0, ['permission' => 'entry.article.delete']))
        ->toThrow(RuntimeException::class, 'Refusing an arithmetic write on role_permissions');

    // Nothing moved, in either org.
    expect(RolePermission::query()->count())->toBe($before);

    // ⚠️ And the legitimate path still works, or this would be a refusal of the table rather than of the door.
    $this->alphaRole->grant('entry.article.publish');
    $this->alphaRole->revoke('entry.article.publish');

    expect(RolePermission::query()->count())->toBe($before);
});

it('does not record a revocation for a deletion an observer vetoed', function (): void {
    /*
     * ⚠️ THE ROWS WENT IN BEFORE THE DELETE, AND A VETO LEFT THEM THERE — review found it. An application
     * observer returning `false` from `deleting` aborts the delete; `parent::delete()` returns false and the
     * transaction commits normally, so the role and every assignment survive while the log says their
     * authority was revoked. A retry would add another set of false rows.
     *
     * The holders still have to be READ first, because the database cascades `role_user` away with the role.
     */
    app(Context::class)->setOrg($this->alpha);
    assign($this->alphaRole, $this->user);

    $mark = (int) AuditLog::query()->max('id');

    Role::deleting(fn (): bool => false);

    expect($this->alphaRole->delete())->toBeFalse()
        ->and(Role::query()->whereKey($this->alphaRole->getKey())->exists())->toBeTrue()
        ->and(DB::table('role_user')->where('role_id', $this->alphaRole->getKey())->count())->toBe(1)
        ->and(AuditLog::query()->where('id', '>', $mark)->count())->toBe(0);
});

it('cannot be handed a forged proof that the guards ran', function (): void {
    /*
     * ⚠️ THE PROOF WAS A PUBLIC BOOLEAN, AND `Builder::getModel()` IS PUBLIC — review found the forgery, and
     * this project had already measured the same attack twice on `exists` and `getIncrementing()`
     * (`RequiresModelSave`'s docblock records both). The old code allowed this:
     *
     *     $q = Role::query(); $q->getModel()->authorityGuarded = true; $q->update(['is_owner' => true]);
     *
     * — every matching role promoted, no per-holder audit, no cache invalidation, no org check.
     *
     * The proof is now two private facts: the listeners record that this instance's guards ran, and
     * `performUpdate()` records the builder they ran for. `setModel()` can still hand a builder any model —
     * what it cannot do is put that model inside its own save.
     */
    app(Context::class)->setOrg($this->alpha);

    // A real save first, so the instance has genuinely been through its guards at some point.
    $this->alphaRole->update(['name' => 'Legitimately saved']);

    /*
     * ⚠️ THIS ASSERTS THE SHAPE, BECAUSE THE ATTACK IS NO LONGER EXPRESSIBLE — and saying so is more honest
     * than a behavioural test that would pass either way. The old hole needed a public property to assign;
     * there is none now, so `$q->getModel()->… = true` does not compile into anything. What can still be
     * checked is that nothing outside the model's own lifecycle arms the proof, which is the property the
     * fix actually buys.
     */
    expect(property_exists(Role::class, 'authorityGuarded'))->toBeFalse();

    foreach (['guardsRan', 'writingThrough', 'deletingItself'] as $name) {
        expect((new ReflectionProperty(Role::class, $name))->isPrivate())
            ->toBeTrue($name.' has to be private, or the proof is settable from outside');
    }

    $source = explode("\n", (string) file_get_contents((string) (new ReflectionClass(Role::class))->getFileName()));
    $armedIn = [];

    foreach ($source as $number => $line) {
        if (preg_match('/\$(role|this)->(guardsRan|writingThrough|deletingItself) = (true|\$query)/', $line) !== 1) {
            continue;
        }

        for ($back = $number; $back >= 0; $back--) {
            if (preg_match('/function (\w+)\(/', $source[$back], $match) === 1) {
                $armedIn[] = $match[1];

                break;
            }
        }
    }

    /*
     * The closures inside `booted()` report as `booted`, which is the point: a model event is the only
     * caller-reachable way in, and `performUpdate()`/`performDeleteOnModel()` are Eloquent's own.
     */
    sort($armedIn);

    expect(array_values(array_unique($armedIn)))->toBe(['booted', 'performDeleteOnModel', 'performUpdate']);

    // And the ordinary instance path still works, or the proof would be unobtainable rather than unforgeable.
    $this->alphaRole->update(['is_owner' => true]);

    expect(Role::query()->whereKey($this->alphaRole->getKey())->value('is_owner'))->toBeTruthy();

    // A builder handed this model is still refused, which is the half that was already true and must stay so.
    $query = Role::query();
    $query->setModel($this->alphaRole);

    expect(fn () => $query->update(['is_owner' => false]))
        ->toThrow(RuntimeException::class, 'bulk write to `is_owner`');
});

it('asks the stored row which org a role belongs to, not the attribute', function (): void {
    /*
     * ⚠️ `$role->org_id` IS A MUTABLE PROPERTY, so the guard was a suggestion — review found it. After
     * retaining an org A role, code in org B could set the attribute to B in memory and every authority
     * method passed: they write by PRIMARY KEY, so A's grants and A's assignments changed while the audit row
     * named B. `getOriginal()` is no better, because `syncOriginal()` is public too.
     *
     * The scoped query cannot be arranged. It asks the database whether the row this key names is one the
     * current context may see.
     */
    assign($this->alphaRole, $this->user);

    /*
     * ⚠️ THE MARK IS TAKEN UNDER THE ORG IT WILL BE READ UNDER, and taking it under beta made this test
     * fail against a correct fix: `AuditLog` is `#[OrgScoped]`, so `max('id')` from beta could not see
     * alpha's rows at all and returned null — a mark of 0, which then counted the assignment row this
     * fixture had just written. The log obeying the boundary it records is the point of that scope; a test
     * reading across it is measuring its own confusion.
     */
    $mark = (int) AuditLog::query()->max('id');

    joinOrg($this->beta, $this->user);
    app(Context::class)->setOrg($this->beta);

    // The forgery: the instance now claims to belong to beta, which is the current context.
    $this->alphaRole->org_id = $this->beta->getKey();

    expect((int) $this->alphaRole->org_id)->toBe($this->beta->getKey());

    foreach ([
        'grant' => fn () => $this->alphaRole->grant('entry.article.delete'),
        'revoke' => fn () => $this->alphaRole->revoke('entry.article.update'),
        'assignTo' => fn () => $this->alphaRole->assignTo($this->user->getKey()),
        'removeFrom' => fn () => $this->alphaRole->removeFrom($this->user->getKey()),
        'delete' => fn () => $this->alphaRole->delete(),
        'save' => fn () => $this->alphaRole->save(),
    ] as $operation => $attempt) {
        expect($attempt)->toThrow(RuntimeException::class, 'Refusing ['.($operation === 'save' ? 'save' : $operation).']');
    }

    // Alpha's authority is untouched, and nothing was recorded under beta.
    app(Context::class)->setOrg($this->alpha);

    expect(Role::query()->whereKey($this->alphaRole->getKey())->value('org_id'))->toBe($this->alpha->getKey())
        ->and(RolePermission::query()->where('role_id', $this->alphaRole->getKey())->pluck('permission')->all())
        ->toBe(['entry.article.update'])
        ->and(DB::table('role_user')->where('role_id', $this->alphaRole->getKey())->count())->toBe(1)
        ->and(AuditLog::query()->where('id', '>', $mark)->count())->toBe(0);
});

it('names no audit target when the panel\'s provider is not what assignments are about', function (): void {
    /*
     * ⚠️ THE ASSIGNMENT AND THE AUDIT TARGET HAVE TO BE THE SAME IDENTITY, which review found they need not
     * be: `assignTo($id)` writes `role_user.user_id`, whose meaning comes from the table that column
     * references — and the audit target was resolved from the PANEL's provider, which may read another table
     * entirely. The row would then name an unrelated person with the same id, which is a false statement
     * about somebody real, in the log that exists to be trusted.
     *
     * A thin row beats a wrong one, so the target is null and the row is still written.
     */
    app(Context::class)->setOrg($this->alpha);

    // The provider now names a model on another table — `TestImpostor` lives on `pivot_scoped_things`.
    config(['auth.providers.users.model' => TestImpostor::class]);

    /*
     * ⚠️ AND THERE HAS TO BE A ROW FOR IT TO NAME WRONGLY, which the first version of this test forgot: with
     * that table empty, `find()` returned null and the target was null for a reason that had nothing to do
     * with the fix. Measured — the test passed with the guard reverted. The impostor is given the same id as
     * the real assignee, which is the whole shape of the defect.
     */
    $impostor = new TestImpostor(['name' => 'Not the assignee']);
    $impostor->id = $this->user->getKey();
    $impostor->save();

    expect($impostor->getKey())->toBe($this->user->getKey());

    $mark = (int) AuditLog::query()->max('id');

    $this->alphaRole->assignTo($this->user->getKey());

    $row = AuditLog::query()->where('id', '>', $mark)->where('action', 'role.assigned')->first();

    expect($row)->not->toBeNull()
        ->and($row->target_type)->toBeNull()
        ->and($row->target_id)->toBeNull();
});

it('refuses a role whose primary key has been edited in memory', function (): void {
    /*
     * ⚠️ ELOQUENT WRITES BY THE ORIGINAL KEY AND THE GUARD READ THE CURRENT ONE — review found the gap.
     * `Model::getKeyForSaveQuery()` returns `$this->original[$key] ?? $this->getKey()`, so an instance whose
     * `id` attribute has been changed updates and deletes the row it was LOADED from, while every check that
     * reads `getKey()` is looking somewhere else. Point the attribute at one of the current org's roles and
     * the org check passed; `parent::delete()` then removed the role it came from.
     *
     * Worse, the two halves of one authority change could name different roles: the pivot writes in
     * `assignTo()`/`removeFrom()` use the CURRENT key while an update or delete uses the original.
     */
    app(Context::class)->setOrg($this->alpha);

    $decoy = Role::create(['handle' => 'decoy', 'name' => 'Decoy']);

    // Both roles belong to alpha, so nothing here is about crossing an org boundary.
    $this->alphaRole->id = $decoy->getKey();

    foreach (['grant', 'assignTo', 'delete'] as $operation) {
        $attempt = match ($operation) {
            'grant' => fn () => $this->alphaRole->grant('entry.article.delete'),
            'assignTo' => fn () => $this->alphaRole->assignTo($this->user->getKey()),
            'delete' => fn () => $this->alphaRole->delete(),
        };

        expect($attempt)->toThrow(RuntimeException::class, 'its primary key has been changed in memory');
    }

    // Neither role moved, and neither gained or lost anything.
    expect(Role::query()->count())->toBe(2)
        ->and(RolePermission::query()->where('role_id', $decoy->getKey())->count())->toBe(0)
        ->and(DB::table('role_user')->count())->toBe(0);
});

it('does not let withoutScopeBecause carry an authority change past the audit', function (): void {
    /*
     * ⚠️ THE ESCAPE HATCH WAS HONOURED HERE FOR ONE ROUND, AND THAT WAS A REGRESSION I INTRODUCED — review
     * found it. `withoutScopeBecause()` suspends the SCOPE; it cannot suspend `Auditor`, which derives the
     * audit row's org from the context. So a grant made under the hatch on another org's role committed the
     * authority change and filed the audit under the wrong org — or under none at all, which is the
     * unaudited authority change ADR-020 refuses outright.
     *
     * Nothing in the codebase called it, so nothing needed it. The way to act on another org's role is to
     * establish that org's context, which is also what makes the audit true.
     */
    /*
     * ⚠️ THE MARK IS TAKEN UNDER ALPHA, WHOSE ROWS IT WILL BE COMPARED AGAINST — the second time this file
     * has been caught by it. `AuditLog` is `#[OrgScoped]`, so a high-water mark read from beta cannot see
     * alpha's rows, comes back null, and then counts the fixture's own `role.granted` as new.
     */
    $mark = (int) AuditLog::query()->max('id');

    app(Context::class)->setOrg($this->beta);

    Role::withoutScopeBecause('a test standing in for cross-org tooling', function () {
        $alphas = Role::withoutGlobalScopes()->where('org_id', $this->alpha->getKey())->get();

        expect($alphas)->toHaveCount(1);

        expect(fn () => $alphas->first()->grant('entry.article.delete'))
            ->toThrow(RuntimeException::class, 'Refusing [grant]');
    });

    app(Context::class)->setOrg($this->alpha);

    expect(RolePermission::query()->where('role_id', $this->alphaRole->getKey())->pluck('permission')->all())
        ->toBe(['entry.article.update'])
        ->and(AuditLog::query()->where('id', '>', $mark)->count())->toBe(0);
});

it('names an assignment audit from the stored owner flag, not a pending edit', function (): void {
    /*
     * ⚠️ AN UNSAVED `is_owner` MADE THE LOG DESCRIBE A GRANT THAT DOES NOT EXIST — review found it. Setting
     * the attribute on a loaded ordinary role and calling `assignTo()` recorded `role.owner_assigned`, while
     * the assignment conferred nothing but the role's persisted ordinary grants. The inverse understates the
     * removal of a real owner role.
     *
     * An audit row is a statement about what happened, so it is named from what is stored.
     */
    app(Context::class)->setOrg($this->alpha);

    $mark = (int) AuditLog::query()->max('id');

    // The pending promotion: in memory only.
    $this->alphaRole->is_owner = true;

    expect($this->alphaRole->is_owner)->toBeTrue()
        ->and((bool) Role::query()->whereKey($this->alphaRole->getKey())->value('is_owner'))->toBeFalse();

    $this->alphaRole->assignTo($this->user->getKey());

    expect(AuditLog::query()->where('id', '>', $mark)->pluck('action')->all())->toBe(['role.assigned']);

    // And the real thing is still recorded as what it is.
    $fresh = Role::query()->whereKey($this->alphaRole->getKey())->firstOrFail();
    $fresh->update(['is_owner' => true]);
    $mark = (int) AuditLog::query()->max('id');
    $fresh->removeFrom($this->user->getKey());

    expect(AuditLog::query()->where('id', '>', $mark)->pluck('action')->all())->toBe(['role.owner_unassigned']);
});

it('does not keep the lifecycle proof when a save aborts in its saved listener', function (): void {
    /*
     * ⚠️ MEASURED, AND THE FINDING WAS RIGHT WHERE I THOUGHT IT WAS NOT. `auditOwnerTransition()` runs in
     * `saved`; when its insert fails — a context naming a site another request has deleted is enough, since
     * `audit_log.site_id` is a foreign key — the transaction rolls back and the listener that clears
     * `$guardsRan` is never reached. A `saveQuietly()` retry then supplied a fresh matching save identity
     * beside the stale guards flag, and `is_owner` landed with no per-holder audit and no cache invalidation.
     *
     * Probed before the fix: "QUIET RETRY ACCEPTED — the proof survived", and the flag was true in the
     * database. Both facts are cleared in `performUpdate()`'s `finally` now, so the proof's lifetime is the
     * attempt rather than the listener.
     */
    app(Context::class)->setOrg($this->alpha);
    assign($this->alphaRole, $this->user);

    $site = Site::create([
        'org_id' => $this->alpha->getKey(), 'handle' => 'doomed', 'slug' => 'doomed', 'name' => 'Doomed',
        'locale' => 'en', 'url_strategy' => 'domain', 'base_url' => 'https://doomed.test',
    ]);
    app(Context::class)->setSite($site);
    Site::query()->whereKey($site->getKey())->withoutGlobalScopes()->delete();

    $this->alphaRole->is_owner = true;

    expect(fn () => $this->alphaRole->save())->toThrow(QueryException::class);

    app(Context::class)->setOrg($this->alpha);

    $this->alphaRole->is_owner = true;

    expect(fn () => $this->alphaRole->saveQuietly())
        ->toThrow(RuntimeException::class, 'bulk write to `is_owner`')
        ->and((bool) Role::query()->whereKey($this->alphaRole->getKey())->value('is_owner'))->toBeFalse();
});

it('counts only the stored org\'s owners as its safety net', function (): void {
    /*
     * ⚠️ FOUND BY SWEEPING THE FAMILY RATHER THAN BY THE NEXT REVIEW ROUND. Five findings had established
     * that an in-memory attribute cannot decide an authority question; `effectiveOwners()` was still filtering
     * on `$this->org_id`. `refuseIfNotCurrentOrg()` establishes that the stored ROW is in the current org — it
     * says nothing about the attribute — so pointing `org_id` at another org made this count THEIR owners as
     * this org's safety net, and the last held owner role here could be demoted because a different customer
     * has one.
     */
    app(Context::class)->setOrg($this->alpha);

    /*
     * ⚠️ THE HOLDER HAS TO BE A MEMBER, or there is nothing to lose and the guard stands aside — the fixture's
     * `beforeEach` does not join anybody to alpha. Without this line the test passed with the fix reverted,
     * because `effectiveOwners()` was empty for a reason that had nothing to do with which org it asked about.
     */
    joinOrg($this->alpha, $this->user);

    // Alpha has exactly one held owner role: taking it away is the lock-out the guard exists to refuse.
    $owner = Role::create(['handle' => 'owner', 'name' => 'Owner', 'is_owner' => true]);
    $owner->assignTo($this->user->getKey());

    // Beta has one too, held by a member of beta — irrelevant to alpha, and the attribute now claims it.
    app(Context::class)->setOrg($this->beta);
    /** @var TestUser $betaUser */
    $betaUser = TestUser::create(['email' => 'beta-owner@kitsune.test']);
    joinOrg($this->beta, $betaUser);
    $betaOwner = Role::create(['handle' => 'owner', 'name' => 'Owner', 'is_owner' => true]);
    $betaOwner->assignTo($betaUser->getKey());

    app(Context::class)->setOrg($this->alpha);

    $owner->org_id = $this->beta->getKey();

    /*
     * ⚠️ THROUGH `removeFrom()`, BECAUSE A SAVE CANNOT REACH IT — worth recording, since the first version of
     * this test tried `update(['is_owner' => false])` and was refused by `EnforcesScope` instead: a dirty
     * scope key is caught by the `updating` guard, so the forged org never produces an outcome there.
     * `removeFrom()` performs a raw pivot delete with no scope guard behind it, so it is the path where a
     * wrong answer from `effectiveOwners()` actually locks an org out.
     */
    expect(fn () => $owner->removeFrom($this->user->getKey()))
        ->toThrow(RuntimeException::class, 'last member of this organisation');

    expect(DB::table('role_user')->where('role_id', $owner->getKey())->count())->toBe(1)
        ->and((bool) Role::query()->whereKey($owner->getKey())->value('is_owner'))->toBeTrue();
});

it('refuses a quiet save of another org\'s role, columns or not', function (): void {
    /*
     * ⚠️ ROUND 11's "EVERY SAVE OF AN EXISTING ROLE ASKS IT" WAS TRUE OF NOISY SAVES ONLY — review found the
     * hole in the boundary I had just drawn. `saveQuietly()` and `updateQuietly()` suppress the `saving`
     * listener that asks whether the row belongs to the current org, and the builder was refusing only the
     * per-row COLUMNS on that path: so a quiet `name` or `handle` change on another org's role went through,
     * written by primary key, with nothing in the log and nothing to stop it.
     *
     * The builder asks the row question itself now, for an instance write that has lost its proof.
     */
    joinOrg($this->beta, $this->user);
    app(Context::class)->setOrg($this->beta);

    $this->alphaRole->name = 'Renamed quietly from another org';

    expect(fn () => $this->alphaRole->saveQuietly())
        ->toThrow(RuntimeException::class, 'Refusing [a save with no lifecycle guards]');

    $this->alphaRole->handle = 'quietly-rehandled';

    expect(fn () => $this->alphaRole->updateQuietly(['handle' => 'quietly-rehandled']))
        ->toThrow(RuntimeException::class, 'Refusing [a save with no lifecycle guards]');

    app(Context::class)->setOrg($this->alpha);

    expect(Role::query()->whereKey($this->alphaRole->getKey())->first()->only(['name', 'handle']))
        ->toBe(['name' => 'Editor', 'handle' => 'editor']);

    /*
     * ⚠️ And a quiet save of one's OWN role still works, or this would be a refusal of the method rather than
     * of the boundary — the distinction every guard on this class has had to make.
     */
    $mine = Role::query()->whereKey($this->alphaRole->getKey())->firstOrFail();
    $mine->name = 'Renamed quietly at home';
    $mine->saveQuietly();

    expect(Role::query()->whereKey($mine->getKey())->value('name'))->toBe('Renamed quietly at home');

    // ⚠️ And a genuine BULK update is still narrowed by the scope rather than refused outright.
    Role::query()->update(['name' => 'Renamed in bulk']);

    expect(Role::query()->whereKey($mine->getKey())->value('name'))->toBe('Renamed in bulk');
});

it('locks the role before it touches the pivot, and reads holders under that lock', function (): void {
    /*
     * ⚠️ EACH OPERATION WAS LOCALLY TRANSACTIONAL AND THE PAIR STILL LOST A ROW FROM THE TRAIL — review found
     * the interleaving. An assignment inserted the pivot and read the owner flag as false, recording
     * `role.assigned`; a concurrent promotion could not see the uncommitted pivot and so audited no holder at
     * all. Both committed, and the person was an owner with nothing in the log saying so — which is exactly
     * the per-person guarantee ADR-033 claims.
     *
     * The role row is the mutex: a promotion writes it, so it takes that lock on its own account, and an
     * assignment or removal takes it explicitly BEFORE touching `role_user`. The holders read takes it too,
     * from the other side, so a transition waits for an assignment in flight rather than counting past it.
     *
     * ⚠️ WHAT THIS ASSERTS IS THE ORDER AND THE CLAUSE, not the interleaving. That `FOR UPDATE` makes the
     * second transaction wait is the engine's guarantee, tested by its authors; `OwnerLockOutRaceTest` makes
     * the same division. What can go wrong HERE is the lock being dropped, taken after the write, or the
     * holders read losing its clause — and all three are visible in the emitted SQL.
     */
    app(Context::class)->setOrg($this->alpha);

    $seen = [];

    DB::listen(function (QueryExecuted $query) use (&$seen): void {
        if ($query->connectionName !== DB::getDefaultConnection()) {
            return;
        }

        // ⚠️ Quotes stripped: identifier quoting is dialect, and matching it counts nothing on half the matrix.
        $sql = str_replace(['"', '`'], '', $query->sql);

        foreach (['pg_constraint', 'information_schema', 'sqlite_master'] as $introspection) {
            if (str_contains($sql, $introspection)) {
                return;
            }
        }

        if (str_contains($sql, 'from roles') || str_contains($sql, 'role_user')) {
            $seen[] = $sql;
        }
    });

    $this->alphaRole->assignTo($this->user->getKey());

    $lockedRole = null;
    $pivotWrite = null;

    foreach ($seen as $position => $sql) {
        if ($lockedRole === null && str_contains($sql, 'from roles') && str_contains($sql, 'for update')) {
            $lockedRole = $position;
        }

        if ($pivotWrite === null && str_starts_with($sql, 'insert into role_user')) {
            $pivotWrite = $position;
        }
    }

    /*
     * ⚠️ SQLITE EMITS NO CLAUSE AT ALL, and that is not a gap: it serialises writers at the database level,
     * so the order is what matters there and the lock is what matters elsewhere. The pivot write is asserted
     * on every engine; the clause and its position only where the engine has one.
     */
    expect($pivotWrite)->not->toBeNull('the assignment never wrote the pivot, so this measured nothing');

    if (DB::connection()->getDriverName() !== 'sqlite') {
        expect($lockedRole)->not->toBeNull('the role row was never locked during the assignment')
            ->and($lockedRole)->toBeLessThan($pivotWrite, 'the role was locked AFTER the pivot was written');

    }

    /*
     * ⚠️ THE HOLDERS READ BELONGS TO A DIFFERENT OPERATION, which the first version of this test got wrong:
     * `assignTo()` names the audit action and never enumerates holders, so asserting that read here was
     * asserting about SQL the operation does not emit — it failed on an empty list while the guard worked.
     * The read happens on an owner TRANSITION and on deletion, so it is measured where it lives.
     */
    /*
     * ⚠️ AND DELETION TAKES THE SAME TWO LOCKS IN THE SAME ORDER, which review found inverted: it read the
     * holders first and locked the role afterwards, so two paths ran in opposite orders — a deadlock waiting
     * for load — and in the window between them an assignment could commit, leaving the cascade to remove a
     * different set of rows than the audit recorded.
     */
    $seen = [];

    $doomed = Role::create(['handle' => 'doomed', 'name' => 'Doomed']);
    $doomed->assignTo($this->user->getKey());
    $seen = [];
    $doomed->delete();

    if (DB::connection()->getDriverName() !== 'sqlite') {
        $lockedRoleOnDelete = null;
        $pivotReadOnDelete = null;

        foreach ($seen as $position => $sql) {
            if ($lockedRoleOnDelete === null && str_contains($sql, 'from roles') && str_contains($sql, 'for update')) {
                $lockedRoleOnDelete = $position;
            }

            if ($pivotReadOnDelete === null && str_starts_with($sql, 'select user_id from role_user')) {
                $pivotReadOnDelete = $position;
            }
        }

        expect($lockedRoleOnDelete)->not->toBeNull('the deletion never locked the role')
            ->and($pivotReadOnDelete)->not->toBeNull('the deletion never enumerated the holders')
            ->and($lockedRoleOnDelete)->toBeLessThan(
                $pivotReadOnDelete,
                'the deletion locked the role AFTER reading its holders, which is the opposite order to assignTo()',
            );
    }

    $seen = [];

    $this->alphaRole->update(['is_owner' => true]);

    if (DB::connection()->getDriverName() !== 'sqlite') {
        $holderReads = array_values(array_filter(
            $seen,
            static fn (string $sql): bool => str_starts_with($sql, 'select user_id from role_user'),
        ));

        expect($holderReads)->not->toBe([], 'the owner transition enumerated no holders, so this measured nothing');

        foreach ($holderReads as $sql) {
            expect($sql)->toContain('for update');
        }
    }
});

it('records an owner transition once, however many times the instance is saved', function (): void {
    /*
     * ⚠️ `wasChanged()` OUTLIVES THE WRITE THAT SET IT — review found it and a probe confirmed it in one run.
     * Eloquent refreshes `$changes` in `finishSave()` from what the update wrote, and a later `save()` with
     * nothing dirty never calls `performUpdate()` at all: `$changes` still describes the PREVIOUS write, so
     * `wasChanged('is_owner')` was still true and the transition was audited again. Measured before the fix —
     * one promotion and three no-op saves produced FOUR `role.owner_assigned` rows per holder:
     *
     *   after the real transition: 1
     *   after a no-op save:        2
     *   after two more no-op saves: 4
     *
     * An audit row is a statement that something happened, and a trail that grows every time somebody calls
     * `save()` is worse than a thin one: it reports authority changes that did not occur, to whoever is
     * reading the log to find out what did.
     */
    app(Context::class)->setOrg($this->alpha);
    assign($this->alphaRole, $this->user);

    $mark = (int) AuditLog::query()->max('id');

    $this->alphaRole->update(['is_owner' => true]);

    $promotions = fn (): int => AuditLog::query()
        ->where('id', '>', $mark)
        ->where('action', 'role.owner_assigned')
        ->count();

    expect($promotions())->toBe(1);

    $this->alphaRole->save();
    $this->alphaRole->save();
    $this->alphaRole->save();

    expect($promotions())->toBe(1);

    /*
     * ⚠️ And a REAL second transition is still recorded, or the fix would have traded a false trail for a
     * missing one — which is the direction that cannot be noticed from the log itself.
     */
    $this->alphaRole->update(['is_owner' => false]);

    expect(AuditLog::query()->where('id', '>', $mark)->where('action', 'role.owner_unassigned')->count())->toBe(1);

    $this->alphaRole->save();

    expect(AuditLog::query()->where('id', '>', $mark)->where('action', 'role.owner_unassigned')->count())->toBe(1);
});

it('stays idempotent when the same assignment arrives twice', function (): void {
    /*
     * ⚠️ THE EXISTENCE CHECK WAS OUTSIDE ANY LOCK, which review found: two requests assigning the same person
     * to the same role both passed it, the first inserted and committed, and the second then inserted into the
     * `(role_id, user_id)` primary key and died on a constraint violation — where this method's documented
     * behaviour is to be idempotent and silent. "Already holds it" has to be asked where the answer cannot
     * change underneath, which is inside the transaction, after the role's lock.
     *
     * The sequential case is what a test can assert directly: a second call adds no row and no audit trail.
     * The concurrent case is the same statement one lock down, and the lock ordering is asserted separately.
     */
    app(Context::class)->setOrg($this->alpha);

    $this->alphaRole->assignTo($this->user->getKey());

    $mark = (int) AuditLog::query()->max('id');

    $this->alphaRole->assignTo($this->user->getKey());
    $this->alphaRole->assignTo($this->user->getKey());

    expect(DB::table('role_user')->where('role_id', $this->alphaRole->getKey())->count())->toBe(1)
        ->and(AuditLog::query()->where('id', '>', $mark)->count())->toBe(0);

    // ⚠️ And the check is inside the lock, not merely inside the transaction: the role must be locked first,
    // or the second request can still read `role_user` before the first one's insert is visible to it.
    $seen = [];

    DB::listen(function (QueryExecuted $query) use (&$seen): void {
        if ($query->connectionName !== DB::getDefaultConnection()) {
            return;
        }

        $sql = str_replace(['"', '`'], '', $query->sql);

        foreach (['pg_constraint', 'information_schema', 'sqlite_master'] as $introspection) {
            if (str_contains($sql, $introspection)) {
                return;
            }
        }

        if (str_contains($sql, 'from roles') || str_contains($sql, 'role_user')) {
            $seen[] = $sql;
        }
    });

    $other = TestUser::create(['email' => 'second-holder@kitsune.test']);
    joinOrg($this->alpha, $other);
    $this->alphaRole->assignTo($other->getKey());

    if (DB::connection()->getDriverName() !== 'sqlite') {
        $lock = null;
        $check = null;

        foreach ($seen as $position => $sql) {
            if ($lock === null && str_contains($sql, 'from roles') && str_contains($sql, 'for update')) {
                $lock = $position;
            }

            if ($check === null && str_contains($sql, 'exists') && str_contains($sql, 'role_user')) {
                $check = $position;
            }
        }

        expect($lock)->not->toBeNull('the assignment never locked the role')
            ->and($check)->not->toBeNull('the assignment never asked whether the pivot row already existed')
            ->and($lock)->toBeLessThan($check, 'the existence check ran before the role was locked');
    }
});
