<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Models;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
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
     * Whether this instance's own guards have run for the write in flight.
     *
     * ⚠️ PRIVATE, AND A PUBLIC BOOLEAN WAS A FORGERY WAITING TO HAPPEN — review found it, and this project
     * had already recorded the attack once: `Builder::getModel()` is public, so
     * `$q = Role::query(); $q->getModel()->authorityGuarded = true; $q->update(['is_owner' => true])`
     * presented a proof nothing had earned and promoted every matching role. `RequiresModelSave`'s docblock
     * records the same shape for `exists` and `getIncrementing()`, measured, twice.
     *
     * So the proof is now two private facts a caller cannot arrange, and the builder asks for both:
     * `$guardsRan` is armed by the `saving`/`deleting` listeners, which only a model event reaches; and
     * `$writingThrough`/`$deletingItself` are set inside `performUpdate()`/`performDeleteOnModel()`, which
     * are true only for the dynamic extent of a real save. Neither has a setter.
     */
    private bool $guardsRan = false;

    /**
     * The builder this instance is being saved through, or null when no save is in flight.
     *
     * The identity check is what a quiet save cannot manufacture: `setModel()` can hand any builder any
     * model, but a model handed to `setModel()` is not inside its own `performUpdate()`.
     */
    private ?object $writingThrough = null;

    /** True only inside this instance's own `performDeleteOnModel()`. */
    private bool $deletingItself = false;

    /**
     * Whether the save that is finishing actually performed an UPDATE.
     *
     * ⚠️ `wasChanged()` OUTLIVES THE WRITE THAT SET IT, which review found and which a probe confirmed
     * immediately: Eloquent refreshes `$changes` in `finishSave()` from what the update wrote, and a later
     * `save()` with nothing dirty never calls `performUpdate()` at all — so `$changes` still describes the
     * PREVIOUS write, `wasChanged('is_owner')` is still true, and the owner transition was audited again.
     * Measured: one promotion, then three no-op saves, produced four `role.owner_assigned` rows per holder.
     *
     * An audit row is a statement that something happened. This is how the model knows something did.
     */
    private bool $updateWasPerformed = false;

    /**
     * The owner flag as the DATABASE held it before this write, captured under the write's own lock.
     *
     * Null means no write of the flag was attempted, which is how a save that did not touch it is told apart
     * from one that set it to the value it already had.
     */
    private ?bool $ownerFlagBeforeUpdate = null;

    /**
     * Whether a write to `role_permissions` is inside `grant()` or `revoke()`.
     *
     * ⚠️ THE OPENER IS NOT A METHOD, which is the whole point — review found the first version's window
     * opener PUBLIC on `RolePermission`, so any caller could hold it open around a write of their own and
     * `role_permissions` was as reachable as before. The flag is private static, armed inline by the two
     * methods below and closed in their `finally`; `GuardedGrantBuilder` can read it and nothing can set it.
     */
    private static bool $writingGrants = false;

    /** @param  Builder  $query */
    public function newEloquentBuilder($query): GuardedRoleBuilder
    {
        return new GuardedRoleBuilder($query, $this);
    }

    /**
     * Are this write's per-row guarantees proven — for THIS builder, by THIS instance's own guards?
     *
     * Both halves are needed and neither is enough. The guards having run says a lifecycle check validated
     * the values; the builder identity says the write in flight is the one they ran for, which is what a
     * `saveQuietly()` retry and a hand-armed `setModel()` both fail.
     */
    public function authorityProven(object $through): bool
    {
        return $this->guardsRan && $this->writingThrough === $through;
    }

    /** The same question for a deletion, where the builder is created inside `performDeleteOnModel()`. */
    public function deletionProven(): bool
    {
        return $this->guardsRan && $this->deletingItself;
    }

    /** Whether `role_permissions` is being written from inside `grant()` or `revoke()`. */
    public static function grantsAreBeingWritten(): bool
    {
        return self::$writingGrants;
    }

    /**
     * ⚠️ THE ONLY PLACE THE WRITE IDENTITY IS SET, and it is set on the way into Eloquent's own update path
     * rather than by anything a caller can reach. Cleared in a `finally`, so an aborted save leaves no proof
     * behind — the rule `DerivesGuardedColumns` records for the four `RequiresModelSave` models.
     *
     * @param  EloquentBuilder<static>  $query
     */
    protected function performUpdate(EloquentBuilder $query)
    {
        $this->writingThrough = $query;

        try {
            $updated = parent::performUpdate($query);

            // Consumed by `auditOwnerTransition()` in `saved`, which fires after this returns.
            $this->updateWasPerformed = (bool) $updated;

            return $updated;
        } finally {
            /*
             * ⚠️ BOTH FACTS, HOWEVER THIS EXITS — review found the first version clearing only the identity,
             * and measured the consequence: `auditOwnerTransition()` throwing in `saved` rolls the
             * transaction back without reaching the listener that clears `$guardsRan`, so a `saveQuietly()`
             * retry on the same instance supplied a fresh matching identity beside the stale guards flag and
             * wrote `is_owner` with no per-holder audit and no cache invalidation. Measured before the fix:
             * the quiet retry was accepted and the flag landed.
             *
             * Clearing both here makes the proof's lifetime local to the attempt, rather than spread across
             * this method, a `saved` listener and `save()`'s own `finally` — three places that all had to
             * agree.
             */
            $this->writingThrough = null;
            $this->guardsRan = false;
        }
    }

    /**
     * ⚠️ AND FOR A DELETION THERE IS NO QUERY TO CAPTURE, because Eloquent builds it inside this method — so
     * the proof is the dynamic extent itself. A caller cannot be inside another instance's
     * `performDeleteOnModel()`, and `newModelQuery()` sets that builder's model to this instance, so the
     * builder is asking the object that is actually being deleted.
     */
    protected function performDeleteOnModel(): void
    {
        $this->deletingItself = true;

        try {
            parent::performDeleteOnModel();
        } finally {
            $this->deletingItself = false;
            $this->guardsRan = false;
        }
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
        return (bool) DB::transaction(fn (): bool => parent::save($options));
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

        return DB::transaction(function (): ?bool {
            /*
             * ⚠️ THE HOLDERS ARE READ BEFORE THE DELETE AND RECORDED AFTER IT, and doing both before was a
             * false record waiting to happen — review found it. An application observer returning `false`
             * from `deleting` aborts the delete, `parent::delete()` returns false, and this transaction
             * commits normally: the role and every assignment survive while the log says their authority was
             * revoked. Repeating the attempt would add another set of false rows.
             *
             * They cannot be read afterwards either, because the database cascades `role_user` away with the
             * role — so the read has to come first and the write has to come second.
             */
            /*
             * ⚠️ THE ROLE FIRST, THEN ITS PIVOT ROWS — and the order was inverted here, which review caught.
             * Every other path takes the role's lock before touching `role_user` (`assignTo()` records why),
             * and this one read the holders first: two paths in opposite orders is a deadlock waiting for
             * load, and in between the two statements an assignment could commit, so the cascade removed a
             * different set of rows than the audit recorded. A holder assigned in that window lost authority
             * with no `role.unassigned`; one removed in it could be audited twice.
             *
             * `storedOwnerFlag()` is a locked read of the role, so calling it first takes that lock and costs
             * no extra query — the flag was needed anyway.
             */
            $wasOwner = $this->storedOwnerFlag();

            $holders = DB::table('role_user')
                ->where('role_id', $this->getKey())
                ->lockForUpdate()
                ->pluck('user_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            $deleted = parent::delete();

            if ($deleted === false) {
                /*
                 * ⚠️ A VETOED DELETION LEAVES THE PROOF STANDING, which review found. The `deleting`
                 * listener earns `$guardsRan` and `performDeleteOnModel()` clears it in a `finally` — but an
                 * application observer returning false means `parent::delete()` returns before that method
                 * is ever entered. The proof then survives on the instance, and a later `saveQuietly()`
                 * supplies the other half: `authorityProven()` accepts it and the quiet save writes
                 * `is_owner` or `org_id` with no org check, no per-holder audit and no cache invalidation.
                 *
                 * The same shape as every other finding in this family — a proof that outlives the write it
                 * was earned for — reached this time through somebody else's veto rather than through a
                 * forged attribute.
                 */
                $this->guardsRan = false;

                return $deleted;
            }

            /*
             * ⚠️ EVERY HOLDER LOSES AUTHORITY, NOT ONLY AN OWNER'S, which review found the first version
             * missing: the condition recorded revocations only for owner roles, while the database cascades
             * `role_user` for every role and a role carrying ordinary grants is authority too. ADR-033's
             * guarantee is about authority, so the log has to be as well.
             */
            $this->recordOwnerChange($wasOwner ? 'unassigned' : null, holders: $holders);

            return $deleted;
        });
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

                /*
                 * ⚠️ THE TRANSITION IS A FACT ABOUT THE ROW, NOT ABOUT THIS INSTANCE'S ORIGINALS — review
                 * found the audit double-counting because of the difference. Two requests that both load a
                 * non-owner role and both set the flag serialise on the lock, and the second one's update
                 * writes `true` over `true`: nothing transitions, but `wasChanged('is_owner')` compares its
                 * own stale original and says it did, so every holder got a second `role.owner_assigned`.
                 * A log that reports two promotions where one happened is the same defect as one that
                 * reports none.
                 *
                 * Captured here because this is inside the write's transaction and `storedOwnerFlag()` is a
                 * locking read: the value cannot move between this line and the commit.
                 */
                $role->ownerFlagBeforeUpdate = $role->storedOwnerFlag();
            }

            /*
             * ⚠️ AND EVERY SAVE OF AN EXISTING ROLE, not only one that touches the flag. The check above fires
             * on a dirty `is_owner`; `EnforcesScope` fires on a dirty scope key and validates the value being
             * WRITTEN — which is the value a transfer arranges. So a role loaded under org A and saved while
             * the context is B moved there with its grants and its assignments, and an ordinary rename wrote
             * to another customer's row. Both are the same question — is this ROW ours — and the scoped query
             * inside the guard is what answers it.
             */
            if ($role->exists) {
                $role->refuseIfNotCurrentOrg('save');
            }

            // Earned for this write only; `saved` clears it so the next one has to earn it again.
            $role->guardsRan = true;
        });

        static::deleting(static function (self $role): void {
            $role->guardsRan = true;
        });

        static::saved(static function (self $role): void {
            $role->auditOwnerTransition();
            $role->guardsRan = false;

            Permissions::forget();
        });

        static::deleted(static function (self $role): void {
            $role->guardsRan = false;

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
        /*
         * ⚠️ CONSUMED FIRST, AND BEFORE THE `wasChanged()` TEST. A save that wrote nothing must not replay
         * the previous write's transition, and it must not leave the flag standing for the save after it
         * either — so this is a one-shot proof of "an update just ran", taken whether or not the flag moved.
         */
        $performed = $this->updateWasPerformed;
        $this->updateWasPerformed = false;

        $before = $this->ownerFlagBeforeUpdate;
        $this->ownerFlagBeforeUpdate = null;

        if (! $performed || $before === null) {
            return;
        }

        $after = (bool) $this->is_owner;

        /*
         * ⚠️ THE STORED VALUE BEFORE THE WRITE AGAINST THE VALUE WRITTEN, not `wasChanged()`. The instance's
         * originals describe what it was loaded with, which a concurrent promotion has already made false —
         * see the capture in `booted()`. Equal values mean the row did not move, whatever this object thought.
         */
        if ($before === $after) {
            return;
        }

        $this->recordOwnerChange($after ? 'assigned' : 'unassigned');
        // (the transition always concerns the owner flag, so the owner action is always the right one)
    }

    /**
     * One row per person who holds this role right now.
     *
     * ⚠️ THE ACTION NAMES WHAT WAS LOST, which is why `null` is a case rather than an oversight: deleting a
     * role revokes it from every holder whether or not it carried the owner bypass, and `role.unassigned` is
     * what an ordinary role's holders lost. Recording only the owner case left a grant-bearing role
     * disappearing from everybody with nothing in the log, which is the same gap one level down.
     *
     * @param  list<int>|null  $holders
     */
    private function recordOwnerChange(?string $ownerVerb, string $plain = 'unassigned', ?array $holders = null): void
    {
        $action = $ownerVerb === null ? "role.{$plain}" : "role.owner_{$ownerVerb}";

        /*
         * ⚠️ THE HOLDERS MAY HAVE TO BE SUPPLIED, because on the deletion path they are gone by the time the
         * rows are written: the database cascades `role_user`, and the record has to be made AFTER the delete
         * succeeds — an observer can veto it (see `delete()`). Reading them here is right for the owner-flag
         * transition, where nothing has been removed.
         */
        /*
         * ⚠️ A LOCKING READ, which is the other half of the interleaving `assignTo()` describes. An owner
         * transition that read the holders without a lock could not see a pivot row an assignment had
         * inserted but not yet committed — so the promotion audited nobody while the assignment audited an
         * ordinary `role.assigned`, and the person ended up an owner with no row saying so. Under a lock the
         * transition waits for that assignment and then counts it.
         */
        $holders ??= DB::table('role_user')
            ->where('role_id', $this->getKey())
            ->lockForUpdate()
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        foreach ($holders as $userId) {
            app(Auditor::class)->record($action, $this->assignee((int) $userId));
        }
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
    /**
     * ⚠️ PUBLIC SO THE BUILDER CAN ASK IT, which review made necessary: `saveQuietly()` and `updateQuietly()`
     * suppress the `saving` listener that calls this, and `GuardedRoleBuilder` was refusing only the per-row
     * COLUMNS on that path — so a quiet `name` or `handle` change on another org's role went through by
     * primary key, and round 11's "every save of an existing role asks it" was true of noisy saves only.
     *
     * Public is safe in the direction that matters: calling a guard can only refuse, never permit.
     */
    public function refuseIfNotCurrentOrg(string $operation): void
    {
        /*
         * ⚠️ THE ESCAPE HATCH WAS HONOURED HERE FOR ONE ROUND AND IS NOT ANY MORE, which review was right
         * about. `withoutScopeBecause()` suspends the SCOPE; it cannot suspend `Auditor`, which derives the
         * audit org from the context — so a grant made under the hatch on another org's role committed the
         * authority change and filed the audit row under the wrong org, or under none at all, and ADR-020
         * refuses an unaudited authority change outright.
         *
         * Nothing in the codebase called it, so nothing needed it: the way to act on another org's role is to
         * establish that org's context, which is also what makes the audit true. An inconvenience beats a log
         * that names the wrong customer.
         */
        $current = app(Context::class)->orgId();

        /*
         * ⚠️ THE KEY THE WRITE WILL USE IS NOT ALWAYS THE ONE IN THE ATTRIBUTE, which review found next.
         * `Model::getKeyForSaveQuery()` returns the ORIGINAL key, so an instance whose `id` has been changed
         * in memory deletes and updates its old row while every check here looked at the new one: point the
         * attribute at one of the current org's roles and the guard passed, then `parent::delete()` removed
         * the row it came from. The pivot writes in `assignTo()`/`removeFrom()` use the CURRENT key, so the
         * two halves of an authority change could even name different roles.
         *
         * A role whose primary key has been edited is not something to reconcile — it is refused.
         */
        $stored = $this->getKeyForSaveQuery();

        if ($this->exists && (string) $stored !== (string) $this->getKey()) {
            throw new RuntimeException(sprintf(
                'Refusing [%s] on role %s: its primary key has been changed in memory — the row it was '
                .'loaded from is %s, and an instance update or delete writes to THAT one while everything '
                .'else here would name this one. Load the role you mean (ADR-021, ADR-033).',
                $operation,
                (string) $this->getKey(),
                (string) $stored,
            ));
        }

        /*
         * ⚠️ THE ROW IS ASKED, NOT THE ATTRIBUTE, which review found to be the difference between a guard and
         * a suggestion. `$this->org_id` is a mutable property: after retaining an org A role, code in org B
         * could set it to B in memory and every one of these paths passed — while the writes they perform go
         * by PRIMARY KEY, so A's authority changed and the audit row named B. `getOriginal()` is no better,
         * because `syncOriginal()` is public too.
         *
         * The scoped query cannot be arranged: it asks the database, through `OrgScope`, whether the stored
         * row is one this context may see. It also covers a transfer in flight — the row the key names still
         * belongs to the org it came from — which is why `save()` asks the same question.
         */
        if ($current !== null && $stored !== null && static::query()->whereKey($stored)->exists()) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing [%s] on role %s: the current context is %s and no role with that key belongs to it. A '
            .'role loaded under one org and used under another writes authority into the wrong customer, and '
            .'records an audit row that names the wrong one (ADR-021, ADR-033). Establish that org\'s '
            .'context if the write is deliberate — the audit row is derived from it.',
            $operation,
            $this->getKey() === null ? 'none' : (string) $this->getKey(),
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
        /*
         * ⚠️ AND THE WRITE IS THE ONLY ONE `role_permissions` ALLOWS, which review found it was not:
         * `#[Unscoped]` means nothing narrows a direct write either, and that table has no `org_id` for a
         * clause to narrow — so `RolePermission::create([...])` could attach a grant to another org's role
         * and `RolePermission::query()->delete()` could revoke every org's. The window says the write came
         * through here, where the org question has already been asked.
         *
         * ⚠️ ARMED INLINE RATHER THAN THROUGH A HELPER ANYTHING CAN CALL, which is the correction review
         * asked for: the first version's opener was a public method on `RolePermission`, so a caller could
         * hold the window open around a write of their own and nothing had changed.
         */
        self::$writingGrants = true;

        try {
            /** @var RolePermission $row */
            $row = DB::transaction(function () use ($permission): RolePermission {
                /** @var RolePermission $created */
                $created = $this->permissions()->firstOrCreate(['permission' => $permission]);

                if ($created->wasRecentlyCreated) {
                    app(Auditor::class)->record('role.granted', $this);
                }

                return $created;
            });
        } finally {
            self::$writingGrants = false;
        }

        Permissions::forget();

        return $row;
    }

    /** Remove a grant. Silent when it was not held, because the end state is what was asked for. */
    public function revoke(string $permission): void
    {
        $this->refuseIfNotCurrentOrg('revoke');

        self::$writingGrants = true;

        try {
            DB::transaction(function () use ($permission): void {
                if ($this->permissions()->where('permission', $permission)->delete() > 0) {
                    app(Auditor::class)->record('role.revoked', $this);
                }
            });
        } finally {
            self::$writingGrants = false;
        }

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

        DB::transaction(function () use ($userId): void {
            /*
             * ⚠️ THE ROLE IS LOCKED BEFORE THE PIVOT, and review found the interleaving that needs it: an
             * assignment and a concurrent promotion were each locally transactional and together produced an
             * incomplete trail. The assignment inserted the pivot and read the flag as false — `role.assigned`
             * — while the promotion could not see the uncommitted pivot and so audited no holder. Both
             * committed, and the person was an owner with nothing in the log saying so.
             *
             * Locking the role row first is what makes them one order: a promotion writes that row, so it
             * takes the same lock, and whichever arrives second sees the other's work. `recordOwnerChange()`
             * reads the holders under a lock for the same reason, from the other side.
             */
            $this->lockRow();

            /*
             * ⚠️ ASKED AFTER THE LOCK, AND IT USED TO BE ASKED BEFORE THE TRANSACTION — review found the
             * collision. Two requests assigning the same person to the same role both passed an `exists()`
             * check outside any lock; the first inserted and committed, and the second then inserted into the
             * `(role_id, user_id)` primary key and died with a constraint violation — where the documented
             * behaviour of this method is to be idempotent and silent.
             *
             * Inside the lock the second request sees the first's row and returns, which is what "already
             * holds it" is supposed to mean.
             *
             * ⚠️ AND IT IS A LOCKING READ, WHICH IS NOT THE SAME AS A READ INSIDE THE LOCK — review found the
             * half the previous fix left. Under MySQL's default REPEATABLE READ a plain `select` answers from
             * the transaction's SNAPSHOT, and inside a caller-owned outer transaction that snapshot is older
             * than this method: the role lock makes this request wait for the rival to commit, and the
             * non-locking read then still cannot see the row it committed. `lockForUpdate()` reads the latest
             * committed version, which is the only version this decision may be made from.
             *
             * `insertOrIgnore()` would be atomic and was rejected on driver divergence (AGENTS.md #5): MySQL's
             * `INSERT IGNORE` downgrades a foreign-key violation to a warning, so assigning an id that names
             * nobody would report "already holds it" and write nothing, while Postgres would still refuse.
             */
            if (DB::table('role_user')
                ->where('role_id', $this->getKey())
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first() !== null
            ) {
                return;
            }

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
            // ⚠️ Locked before the pivot, for the reason `assignTo()` records: a removal and a concurrent
            // owner transition have to be one order, or the trail records one of them and not the other.
            $this->lockRow();

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
        return $this->storedOwnerFlag() ? "role.owner_{$verb}" : "role.{$verb}";
    }

    /**
     * Take the role row's write lock, so an authority change and a concurrent one are ordered.
     *
     * ⚠️ THE ROLE ROW IS THE MUTEX FOR EVERYTHING THAT CHANGES ITS AUTHORITY, which is the shape review
     * asked for: a promotion writes it, so it takes this lock on its own account, and an assignment or a
     * removal takes it explicitly before touching `role_user`. One order, one lock, no interleaving that
     * records half of what happened.
     *
     * SQLite compiles the clause away and serialises writers at the database level, which is the same
     * guarantee by another route — `Site::lockHostClaim()` records the same pair of facts.
     */
    private function lockRow(): void
    {
        static::query()
            ->withoutGlobalScopes()
            ->whereKey($this->getKeyForSaveQuery())
            ->lockForUpdate()
            ->value('id');
    }

    /**
     * The owner flag as the DATABASE holds it, which is the only one an audit row may be named from.
     *
     * ⚠️ `$this->is_owner` IS A PENDING EDIT UNTIL IT IS SAVED, which review found this trusting: setting the
     * attribute on a loaded ordinary role and calling `assignTo()` recorded `role.owner_assigned` while the
     * assignment conferred nothing but the role's persisted ordinary grants — a log entry describing a grant
     * that does not exist. The inverse understates the removal of a real owner role. An audit row is a
     * statement about what happened, so it has to be named from what is there.
     *
     * ⚠️ AND `auditOwnerTransition()` DELIBERATELY DOES NOT USE THIS. It runs in `saved`, after the row has
     * been written inside the transaction, so the attribute IS the persisted value there — and it is the only
     * caller for which the pending edit is the subject rather than a lie.
     */
    private function storedOwnerFlag(): bool
    {
        /*
         * ⚠️ A LOCKED READ, because it is the first lock every authority path takes and the order depends on
         * it. `delete()` calls this before enumerating the holders precisely so the role's lock comes first —
         * an unlocked read there left two paths taking their locks in opposite orders, and left the window
         * where an assignment could commit between the flag read and the cascade. It also cannot answer from
         * a snapshot: under REPEATABLE READ an ordinary read would name the audit action from a value a
         * concurrent promotion had already replaced.
         */
        return (bool) static::query()
            ->withoutGlobalScopes()
            ->whereKey($this->getKeyForSaveQuery())
            ->lockForUpdate()
            ->value('is_owner');
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
        $model = Permissions::userModel() ?? config('auth.providers.users.model');

        if (! is_string($model) || ! class_exists($model) || ! is_subclass_of($model, Model::class)) {
            return null;
        }

        /*
         * ⚠️ AND IT HAS TO BE THE MODEL THE ASSIGNMENT IS ABOUT, which review found it need not be. An
         * assignment is a row in `role_user`, whose `user_id` names the table that table's foreign key
         * references — so if the panel's provider reads some OTHER table, this would name an unrelated row
         * with the same id and the audit would be a false statement about a real person. `Permissions`
         * settles that question for resolution; the audit target has to ask it too, or the log and the
         * resolver disagree about who a grant belongs to.
         *
         * A thin row beats a wrong one: null target rather than the wrong name, and the row is still written.
         */
        if (! Permissions::assignmentsAreAbout($model)) {
            return null;
        }

        $user = $model::withoutGlobalScopes()->find($userId);

        return $user instanceof Model ? $user : null;
    }
}
