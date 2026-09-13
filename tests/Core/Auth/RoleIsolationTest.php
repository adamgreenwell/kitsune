<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

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

    $impostor = TestImpostor::create(['name' => 'Also number one']);
    DB::table('pivot_scoped_thing_org')->insert([
        'org_id' => $this->alpha->getKey(),
        'pivot_scoped_thing_id' => $impostor->getKey(),
    ]);

    /*
     * ⚠️ THE IDS HAVE TO COLLIDE OR THIS MEASURES NOTHING — two tables, two sequences, both starting at 1.
     * Asserted rather than assumed, because a fixture that drifted to id 2 would make the test pass against
     * the defect.
     */
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
