<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Audit\Auditor;
use Kitsune\Core\Auth\GuardedRoleBuilder;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tenancy\ScopeWrites;
use RuntimeException;

/**
 * A named set of permissions, owned by one org — ADR-033.
 *
 * ⚠️ THIS IS THE ONLY SCOPED THING IN THE RBAC LAYER, and everything else leans on it. `role_permissions`
 * carries no `org_id` and `role_user` carries no constraint about membership; both are safe because every
 * read goes through this model, which is `#[OrgScoped]` and enforces it. A resolver that reached
 * `role_permissions` directly would be unscoped, which is why `Permissions` does not.
 *
 * @property int $id
 * @property int $org_id
 * @property string $handle
 * @property string $name
 * @property bool $is_owner
 */
#[OrgScoped]
class Role extends Model
{
    use EnforcesScope;

    protected $guarded = [];

    protected $casts = ['is_owner' => 'boolean'];

    /**
     * True only while THIS instance's guards have run for the write in flight.
     *
     * ⚠️ How `GuardedRoleBuilder` tells an instance save from a bulk one. Both arrive at the builder,
     * because `Model::performUpdate()` writes through it — so refusing every bulk-shaped write would refuse
     * `$role->save()` as well. The flag is set by the `saving` guard, which only a model event reaches; a
     * bulk update dispatches nothing, so it can never be set and the builder refuses.
     *
     * The same mechanism `FieldStorage::$shapeGuarded` uses, for the same reason.
     */
    public bool $authorityGuarded = false;

    /** @param  Builder  $query */
    public function newEloquentBuilder($query): GuardedRoleBuilder
    {
        return new GuardedRoleBuilder($query, $this);
    }

    /**
     * ⚠️ THE WHOLE SAVE IS ONE TRANSACTION, because the owner audit runs in `saved` — AFTER the row has
     * committed under autocommit. Review found it: an audit insert failing there left the caller with an
     * exception and every holder's authority already changed, and with several holders it could leave a
     * PARTIAL trail, which is worse than none because it reads as complete.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        /*
         * ⚠️ THE FLAG IS CONSUMED BY THE ATTEMPT, HOWEVER THE ATTEMPT ENDS, which review found it was not.
         * `saved` clears it, and an aborted save never reaches `saved` — so an audit insert throwing in that
         * listener (the stale-site foreign key, which `RoleIsolationTest` already exercises) rolled the
         * transaction back and left this instance still claiming its guards had run. Catching that and
         * retrying with `saveQuietly()` would then present a proof to `GuardedRoleBuilder` for a write whose
         * lifecycle checks never ran at all.
         *
         * `DerivesGuardedColumns` records the same rule for the same reason, in a `finally` around
         * `performInsert()`/`performUpdate()`: a proof belongs to ONE attempt.
         */
        try {
            return (bool) DB::transaction(fn (): bool => parent::save($options));
        } finally {
            $this->authorityGuarded = false;
        }
    }

    /**
     * ⚠️ AND DELETING A ROLE REVOKES IT FROM EVERY HOLDER, which the database does by cascade and nothing
     * was recording. Review found it: deleting an owner role took the bypass away from all of them with no
     * `role.owner_unassigned` anywhere, while ADR-033 says the trail answers who gained or lost it.
     *
     * The rows are written BEFORE the delete and inside the same transaction, because afterwards there is
     * no `role_user` left to read them from.
     */
    public function delete(): ?bool
    {
        /*
         * ⚠️ THE FIFTH AUTHORITY PATH HAS TO ASK THE SAME QUESTION, and this one was not — review found it.
         * Eloquent's instance delete writes by primary key without reapplying the global scope, and the
         * `deleting` event enables the guarded builder, so a role that outlived an org-context switch could
         * be deleted while running in another org — with its revocation audits attributed to that org.
         */
        $this->refuseIfNotCurrentOrg('delete');

        // ⚠️ And cleared however this ends — see `save()`. `deleted` is not reached when the revocation
        // audit throws, and the instance would otherwise keep a proof it no longer earned.
        try {
            return DB::transaction(function (): ?bool {
                /*
                 * ⚠️ EVERY HOLDER LOSES AUTHORITY, NOT ONLY AN OWNER'S, which review found the first
                 * version missing: the condition recorded revocations only for owner roles, while the
                 * database cascades `role_user` for every role and a role carrying ordinary grants is
                 * authority too. ADR-033's guarantee is about authority, so the log has to be as well.
                 */
                $this->recordOwnerChange($this->is_owner ? 'unassigned' : null);

                return parent::delete();
            });
        } finally {
            $this->authorityGuarded = false;
        }
    }

    /**
     * Any change to a role can change who may do what, so the memo goes — review found the gap.
     *
     * ⚠️ THE FOUR HELPERS WERE NOT ENOUGH, and `is_owner` is why. Flipping it through an ordinary
     * `save()` or `update()`, or deleting the role outright, changed the widest grant in the system while
     * `Permissions::isOwner()` kept answering from before the write. A demoted owner kept the bypass for
     * the rest of the request; a promotion did not take.
     *
     * ⚠️ A MODEL EVENT HERE, THOUGH ADR-020 REJECTS THEM FOR AUDITING, and the difference is what a miss
     * costs. A missed audit row is a permanent hole in a record that cannot be reconstructed, so that guard
     * has to sit at the builder where `saveQuietly()` cannot step around it. A missed cache flush is a
     * stale answer inside one process, and `saveQuietly()` on a role is the same visible back door as
     * `attach()` — worth closing cheaply here rather than not at all.
     */
    protected static function booted(): void
    {
        /*
         * ⚠️ THE GUARDS RUN BEFORE THE WRITE AND THE AUDIT AFTER IT, which is the only ordering that is
         * correct: a change refused by `refuseIfLastOwner()` must leave no audit row, and a change that
         * happened must leave one.
         */
        static::saving(static function (self $role): void {
            /*
             * ⚠️ A STALE INSTANCE MAY NOT CHANGE THE OWNER FLAG, which review found: `EnforcesScope`
             * revalidates a scope key only when it is DIRTY, so an org A role retained after a worker moved
             * to org B could still be promoted — and the audit row was then written under B, or dropped
             * silently when there was no context at all. The four authority helpers already refuse this;
             * the flag is the fifth way authority changes and was not asking.
             */
            if ($role->exists && $role->isDirty('is_owner')) {
                $role->refuseIfNotCurrentOrg('change the owner flag on');
            }

            $role->refuseWritingAnotherOrgsRole();

            $role->refuseIfLastOwner('clear the owner flag on');

            // Earned for this write only; `saved` clears it so the next one has to earn it again.
            $role->authorityGuarded = true;
        });

        static::deleting(static function (self $role): void {
            $role->refuseIfLastOwner('delete');

            $role->authorityGuarded = true;
        });

        static::saved(static function (self $role): void {
            $role->auditOwnerTransition();
            $role->authorityGuarded = false;

            Permissions::forget();
        });

        static::deleted(static function (self $role): void {
            $role->authorityGuarded = false;

            Permissions::forget();
        });
    }

    /**
     * Record an owner bypass gained or lost by flipping the flag rather than by assignment.
     *
     * ⚠️ REVIEW FOUND THE HOLE, AND IT IS THE WIDEST GRANT IN THE SYSTEM ARRIVING UNRECORDED. Turning
     * `is_owner` on for a role that already has holders gives every one of them the bypass immediately —
     * and their assignment rows were logged as `role.assigned`, so nothing in the log says they are owners
     * now. ADR-033's claim is that the trail answers *who was made an owner*, and this path defeated it.
     *
     * ⚠️ ONE ROW PER AFFECTED PERSON, using the same actions an assignment writes, because the question is
     * about people rather than about the role. A single `role.updated` would record that something changed
     * and leave the answer exactly where it was.
     *
     * Silent when the flag did not move, and silent when nobody holds the role: a flag flipped on a role
     * with no holders grants nothing, which is the same line ADR-033 draws about creating one.
     */
    private function auditOwnerTransition(): void
    {
        if (! $this->wasChanged('is_owner')) {
            return;
        }

        $this->recordOwnerChange($this->is_owner ? 'assigned' : 'unassigned');
        // (the transition always concerns the owner flag, so the owner action is always the right one)
    }

    /**
     * One row per person who holds this role right now.
     *
     * ⚠️ THE ACTION NAMES WHAT WAS LOST, which is why `null` is a case rather than an oversight: deleting a
     * role revokes it from every holder whether or not it carried the owner bypass, and `role.unassigned` is
     * what an ordinary role's holders lost. Recording only the owner case left a grant-bearing role
     * disappearing from everybody with nothing in the log, which is the same gap one level down.
     */
    private function recordOwnerChange(?string $ownerVerb, string $plain = 'unassigned'): void
    {
        $action = $ownerVerb === null ? "role.{$plain}" : "role.owner_{$ownerVerb}";

        foreach (DB::table('role_user')->where('role_id', $this->getKey())->pluck('user_id') as $userId) {
            app(Auditor::class)->record($action, $this->assignee((int) $userId));
        }
    }

    /**
     * Refuse any save of a role the current context does not own.
     *
     * ⚠️ THE STORED ORG IS WHAT HAS TO MATCH, AND THE NEW ONE PROVES NOTHING — which is the hole review
     * found, one step past the owner-flag guard above. `EnforcesScope` revalidates a scope key when it is
     * dirty, and what it validates is the value being WRITTEN against the current context: so a role loaded
     * under org A, with `org_id` then set to B while the context is B, passes every check. `is_owner` was not
     * dirty, so the guard above stood aside; the builder's flag was armed by this very listener; and A's role
     * moved to B carrying its grants and its assignments, taking A's last owner with it and conferring
     * authority in B with no audit row anywhere.
     *
     * ⚠️ SO THIS ASKS THE ORIGINAL, and it covers the ordinary stale write as well as the transfer: a role
     * loaded in A and saved while the context is B is a write to another customer's row whatever column
     * changed, and the five authority helpers already refuse exactly that. `save()` was the path that did
     * not.
     *
     * ⚠️ THE OTHER DIRECTION IS REFUSED ONE LAYER DOWN, and `RoleIsolationTest` asserts it rather than this
     * docblock claiming it: moving one of the CURRENT org's roles to another org writes a scope key the
     * context cannot vouch for, which `EnforcesScope::guardScopeKey()` refuses. Two guards, two different
     * questions — "is this row mine to write" and "is this value mine to write" — and only the first one can
     * be asked here.
     *
     * ⚠️ IT HONOURS `withoutScopeBecause()`, like every other write guard in the tenancy layer. Provisioning
     * and cross-org admin tooling are the legitimate callers, and the escape hatch is named to be greppable
     * and uncomfortable rather than absent.
     */
    private function refuseWritingAnotherOrgsRole(): void
    {
        if (! $this->exists || ScopeWrites::suspended()) {
            return;
        }

        // `getOriginal()` is the stored value; the fallback covers an instance whose `org_id` was never
        // loaded at all, where there is nothing to compare and `EnforcesScope` owns the write.
        $stored = $this->getOriginal('org_id') ?? $this->getAttribute('org_id');

        if ($stored === null) {
            return;
        }

        $current = app(Context::class)->orgId();

        if ((int) $stored === $current) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to save role %s: it is stored under org %s and the current context is %s. A role '
            .'loaded under one org and written under another writes authority into the wrong customer — '
            .'and setting `org_id` to the context on the way past would MOVE it there with its grants and '
            .'its assignments, which is the same defect with a receipt (ADR-021, ADR-033). Use '
            .'withoutScopeBecause() if this is deliberate.',
            (string) $this->getKey(),
            (string) $stored,
            $current === null ? 'none' : (string) $current,
        ));
    }

    /**
     * Refuse a change that would leave this org with nobody who can administer it — issue #84.
     *
     * ⚠️ AN ORG THAT LOSES ITS LAST OWNER CANNOT GET ONE BACK, which is what makes this worth a guard rather
     * than a warning. Schema editing and role administration are both owner-only in v1.0 (ADR-033), so the
     * only person who could restore the flag is the person who just removed it — and the vocabulary has no
     * permission that would let anyone else. A support ticket is the recovery path, and there is no support.
     *
     * ⚠️ AT THE MODEL RATHER THAN IN THE FORM, which is the rule this project keeps relearning: the ROUTE is
     * the boundary, not the button. A form guard is bypassed by the API, by a console command and by the
     * next page somebody writes.
     *
     * ⚠️ AND IT ONLY FIRES WHERE THERE IS SOMETHING TO LOSE. An org with no held owner role yet — a fresh
     * install, mid-seed — is not being locked out by creating one, so the check asks whether this change
     * would take the LAST one rather than whether the result has any.
     *
     * ⚠️ IT RUNS INSIDE THE WRITE'S OWN TRANSACTION, which is what makes the lock in `effectiveOwners()`
     * worth anything: a lock released before the write lands serialises nothing. Both callers are model
     * events — `saving` and `deleting` — and `save()` and `delete()` each wrap `parent::` in a transaction
     * for the audit rows, so the check, the lock and the write are one unit. `removeFrom()` had its check
     * OUTSIDE its transaction and review found it; it does not any more.
     */
    private function refuseIfLastOwner(string $operation): void
    {
        // Turning the flag ON, or any other edit to a role that is not an owner, cannot remove an owner.
        if (! $this->exists || ! $this->getOriginal('is_owner')) {
            return;
        }

        if ($operation !== 'delete' && $this->is_owner) {
            return;
        }

        if (! $this->isOnlyHeldOwnerRole()) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to %s role %s: it is the only owner role in this organisation with anybody holding '
            .'it, and owner is the only role that may administer roles or edit the schema (ADR-033). '
            .'Removing it would leave nobody able to put it back.',
            $operation,
            (string) $this->getKey(),
        ));
    }

    /** Is this the only owner role in its org that anybody actually holds? */
    private function isOnlyHeldOwnerRole(): bool
    {
        return $this->effectiveOwners() !== [] && $this->effectiveOwners(exceptRole: (int) $this->getKey()) === [];
    }

    /**
     * The people who can actually administer this org right now.
     *
     * ⚠️ HOLDING AN OWNER ROLE IS NOT ENOUGH — THEY HAVE TO BE A MEMBER, which review found the guard
     * ignoring. `assignTo()` is public and membership can be removed afterwards, so a `role_user` row may
     * name somebody this org no longer contains. The old check counted that inert pivot as a held owner role
     * — so demoting the last role held by a real member passed, while `Permissions` refuses the remaining
     * assignee and nobody can administer the org. The guard has to ask the same question the resolver asks.
     *
     * ⚠️ MEMBERSHIP IS ASKED THROUGH THE USER MODEL'S OWN SCOPED QUERY under this role's org, which is the
     * one place that knows what membership means (`#[OrgScopedThroughPivot]`). With no resolvable user model
     * — a console context with no panel — every holder counts, because refusing to believe in any of them
     * would make the guard refuse every change on an installation it cannot inspect.
     *
     * ⚠️ THE OWNER READS ARE LOCKING READS, AND THAT IS WHAT SERIALISES THE GUARD — review found the race.
     * This is a check-then-act on a condition no index can express, so two transactions demoting the last
     * two held owner roles could each read the OTHER, pass, and commit: an org with no administrator, from
     * two changes that were each individually safe. `FOR UPDATE` on the org's owner roles is the mutex,
     * because both transactions want a lock on the same row set — whichever arrives second waits, and then
     * re-reads and refuses.
     *
     * ⚠️ AND IT IS ALSO WHAT MAKES THE RE-READ CURRENT. Under MySQL's and MariaDB's REPEATABLE READ an
     * ordinary read answers from the transaction's snapshot, so a demotion committed while this change
     * queued would be invisible and the guard would pass on data from before it waited. A locking read sees
     * the latest committed row. `Site::lockHostClaim()` records the same pair of reasons; SQLite compiles the
     * clause to nothing and serialises writers at the database level, which is the same guarantee by another
     * route.
     *
     * ⚠️ THE MEMBERSHIP QUERY IS DELIBERATELY NOT LOCKED. Locking the host's `users` rows would put this
     * guard in a lock order with every unrelated write to them, and membership changing under a concurrent
     * owner change is a different invariant — `Permissions` asks the same question at check time, so the
     * answer is re-derived rather than frozen here.
     *
     * ⚠️ `$exceptHolder` EXCLUDES ONE ASSIGNMENT, NOT ONE PERSON, which review found this getting wrong: a
     * member holding TWO owner roles who gives up one is still an owner through the other, and excluding
     * them from every owner role reported nobody left and refused a safe removal. The pair is what is being
     * removed, so the pair is what the count has to leave out.
     *
     * @return list<int>
     */
    private function effectiveOwners(?int $exceptRole = null, ?int $exceptHolder = null): array
    {
        $roles = static::query()
            ->withoutGlobalScopes()
            ->where('org_id', $this->org_id)
            ->where('is_owner', true)
            ->when($exceptRole !== null, fn ($query) => $query->whereKeyNot($exceptRole))
            ->lockForUpdate()
            ->pluck('id');

        $holders = DB::table('role_user')
            ->whereIn('role_id', $roles)
            /*
             * ⚠️ Written as `role_id <> this OR user_id <> them` rather than as a negated pair, because
             * that is one clause every engine plans the same way and neither column is nullable. It means
             * NOT (this role AND this holder) — every other row of theirs still counts.
             */
            ->when($exceptHolder !== null, fn (Builder $query) => $query->where(
                fn (Builder $row) => $row->where('role_id', '!=', $this->getKey())
                    ->orWhere('user_id', '!=', $exceptHolder),
            ))
            ->lockForUpdate()
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($holders === []) {
            return [];
        }

        $model = Permissions::userModel();

        if ($model === null) {
            return $holders;
        }

        return $model::query()
            ->whereIn('id', $holders)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Refuse to act on a role that does not belong to the org the context names.
     *
     * ⚠️ A STALE INSTANCE OUTLIVES ITS SCOPE, which is what review found. `OrgScope` filters the QUERY that
     * loaded a role; it says nothing about the object afterwards. In a multi-org command or a long-lived
     * worker the context moves on and the instance does not, so `assignTo()` would have written org A's role
     * onto a user while operating in org B — and recorded the audit row under B, which is worse than no row
     * because it is a false one.
     *
     * The scope cannot catch it: `grant()` reaches `role_permissions`, which is deliberately unscoped, and
     * `assignTo()` writes `role_user` with a raw id. So the check belongs where the authority changes.
     */
    private function refuseIfNotCurrentOrg(string $operation): void
    {
        $current = app(Context::class)->orgId();

        if ($current !== null && (int) $this->org_id === $current) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing [%s] on role %s: it belongs to org %s and the current context is %s. A role loaded '
            .'under one org and used under another writes authority into the wrong customer, and records '
            .'an audit row that names the wrong one (ADR-021, ADR-033).',
            $operation,
            (string) $this->getKey(),
            (string) $this->org_id,
            $current === null ? 'none' : (string) $current,
        ));
    }

    /** @return BelongsTo<Org, $this> */
    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class);
    }

    /** @return HasMany<RolePermission, $this> */
    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    /**
     * Grant a permission, refusing anything the registry does not recognise.
     *
     * ⚠️ VALIDATED HERE RATHER THAN TRUSTED, because a permission is a string and a misspelled one is
     * silently never granted — it fails closed, which is right, and invisibly, which is not. ADR-033 takes
     * the `pii_class` pattern from ADR-020: the answer is required now, and getting it wrong is a security
     * defect rather than a formatting one.
     */
    public function grant(string $permission): RolePermission
    {
        $this->refuseIfNotCurrentOrg('grant');

        $permission = Permissions::validated($permission);

        /*
         * ⚠️ ONE TRANSACTION, BECAUSE AN UNAUDITED AUTHORITY CHANGE IS THE THING ADR-020 REFUSES. Review
         * found the split write: under autocommit the grant lands, and if the audit insert then fails — a
         * context holding a site that was concurrently deleted is enough, since `audit_log.site_id` is a
         * foreign key — the caller gets an exception while the authority change stays. `AuditedBuilder` puts
         * an entry's insert and its audit row in one transaction for exactly this reason; the same rule
         * applies to a write that is not an entry.
         */
        /** @var RolePermission $row */
        $row = DB::transaction(function () use ($permission): RolePermission {
            /** @var RolePermission $created */
            $created = $this->permissions()->firstOrCreate(['permission' => $permission]);

            if ($created->wasRecentlyCreated) {
                app(Auditor::class)->record('role.granted', $this);
            }

            return $created;
        });

        Permissions::forget();

        return $row;
    }

    /** Remove a grant. Silent when it was not held, because the end state is what was asked for. */
    public function revoke(string $permission): void
    {
        $this->refuseIfNotCurrentOrg('revoke');

        DB::transaction(function () use ($permission): void {
            if ($this->permissions()->where('permission', $permission)->delete() > 0) {
                app(Auditor::class)->record('role.revoked', $this);
            }
        });

        Permissions::forget();
    }

    /**
     * Give this role to a user, and record that somebody did.
     *
     * ⚠️ A METHOD IN CORE RATHER THAN `$user->roles()->attach()`, and the reason is the audit rather than
     * convenience. `role_user` is a skeleton pivot with no core model in front of it, so there is no builder
     * to audit at — the mechanism `AuditedBuilder` exists to provide for entries has nothing to attach to
     * here. What core can offer is a path that records, and `attach()` remains the visible back door, in
     * exactly the sense ADR-020 already says of `toBase()`: the guarantee is about the path core provides,
     * and reaching past it is explicit in review.
     *
     * ⚠️ IT IS AN AUTHORITY CHANGE, WHICH IS WHY IT IS THE ONE WORTH RECORDING. ADR-033 withdrew auditing
     * the owner BYPASS on volume — a check runs per row — and this is the other end of that decision: rare,
     * high-value, and the question an auditor actually asks.
     */
    public function assignTo(int $userId): void
    {
        $this->refuseIfNotCurrentOrg('assignTo');

        $existing = DB::table('role_user')
            ->where('role_id', $this->getKey())
            ->where('user_id', $userId)
            ->exists();

        if ($existing) {
            return;
        }

        DB::transaction(function () use ($userId): void {
            DB::table('role_user')->insert(['role_id' => $this->getKey(), 'user_id' => $userId]);

            app(Auditor::class)->record($this->assignmentAction('assigned'), $this->assignee($userId));
        });

        Permissions::forget();
    }

    /** Take it away again. Silent when the user did not hold it, because the end state is what was asked. */
    public function removeFrom(int $userId): void
    {
        $this->refuseIfNotCurrentOrg('removeFrom');

        DB::transaction(function () use ($userId): void {
            $this->refuseLosingTheLastOwner($userId);

            $removed = DB::table('role_user')
                ->where('role_id', $this->getKey())
                ->where('user_id', $userId)
                ->delete();

            if ($removed > 0) {
                app(Auditor::class)->record($this->assignmentAction('unassigned'), $this->assignee($userId));
            }
        });

        Permissions::forget();
    }

    /**
     * Refuse to take the last owner role away from the last member holding one.
     *
     * ⚠️ TAKING THE LAST OWNER'S ROLE AWAY IS THE SAME LOCK-OUT AS DELETING THE ROLE, and review found this
     * door open while the other was shut: the role form calls `removeFrom()` for every holder dropped from
     * the selection, so an owner could remove the final holder — themselves — and permanently lose role and
     * schema administration on the next request. `refuseIfLastOwner()` guards the ROLE; this guards its last
     * holder.
     *
     * ⚠️ INSIDE THE TRANSACTION THAT DELETES THE ROW, which review found it was not: the check ran before
     * `DB::transaction()` opened, so the lock `effectiveOwners()` takes was released before the delete and
     * two concurrent removals could each see the other's holder. A guard that does not hold its lock until
     * the write commits is a guard that reads the past.
     *
     * ⚠️ AND THE EXCLUSION IS THE ASSIGNMENT RATHER THAN THE PERSON — see `effectiveOwners()`. A member
     * holding two owner roles who gives up one keeps the other, and the earlier version refused that.
     */
    private function refuseLosingTheLastOwner(int $userId): void
    {
        if (! $this->is_owner) {
            return;
        }

        if ($this->effectiveOwners() === [] || $this->effectiveOwners(exceptHolder: $userId) !== []) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to take role %s from user %s: they are the last member of this organisation '
            .'holding an owner role, and owner is the only role that may administer roles or edit the '
            .'schema (ADR-033). Removing it would leave nobody able to put it back.',
            (string) $this->getKey(),
            (string) $userId,
        ));
    }

    /**
     * The action name for an assignment, which is where owner-ness has to live.
     *
     * ⚠️ THE LOG HOLDS ONE TARGET AND AN ASSIGNMENT HAS THREE PARTIES — who did it, which user, which role.
     * `audit_log` carries actor, action and target and deliberately no payload (ADR-020), so one of the
     * three has to be expressed some other way. Review found the first version recording the ROLE, which
     * made ADR-033's own claim — *who was made an owner* — unanswerable the moment two people held the
     * same role.
     *
     * So the target is the USER, because that is the irreplaceable half: a named role is still there to be
     * read while it exists, and the person whose authority changed is the question. And owner-ness goes in
     * the action, because it is the fact the ADR singles out and an action is a vocabulary rather than a
     * payload — `role.owner_assigned` is greppable in a way that a target alone is not.
     */
    private function assignmentAction(string $verb): string
    {
        return $this->is_owner ? "role.owner_{$verb}" : "role.{$verb}";
    }

    /**
     * The user an assignment is about, as a model, so the audit row can name them.
     *
     * ⚠️ WITHOUT GLOBAL SCOPES, because the host's user model is org-scoped through a pivot and this runs
     * where the answer must not depend on the reader's context — the same reason `Permissions` resolves a
     * user that way. Null when the provider names no model this class can load; the row is then written
     * with no target rather than not written at all, because a change of authority that went unrecorded is
     * worse than one recorded thinly.
     */
    private function assignee(int $userId): ?Model
    {
        /*
         * ⚠️ THE PANEL'S PROVIDER NAMES THE MODEL, and this hard-coded `users` until review found it — the
         * same fail-open shape as the membership check, one file along. A panel authenticating through a
         * provider with another name recorded an unrelated model with the same id, or no target at all, so
         * the audit row still could not say whose authority changed.
         *
         * ⚠️ THE CONFIG IS THE CONSOLE FALLBACK AND NOTHING MORE. `Permissions::userModel()` needs a panel,
         * and a seeder or a command has none — so the default provider is the best available answer there,
         * and a wrong one costs a thin audit row rather than a wrong guarantee. The row is written either
         * way: an authority change that went unrecorded is worse than one recorded without a name.
         */
        $model = Permissions::userModel();

        if (! is_string($model) || ! class_exists($model) || ! is_subclass_of($model, Model::class)) {
            return null;
        }

        $user = $model::withoutGlobalScopes()->find($userId);

        return $user instanceof Model ? $user : null;
    }
}
