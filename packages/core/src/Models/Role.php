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
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Audit\Auditor;
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
        static::saved(static function (self $role): void {
            $role->auditOwnerTransition();

            Permissions::forget();
        });

        static::deleted(static function (): void {
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

        $verb = $this->is_owner ? 'assigned' : 'unassigned';

        foreach (DB::table('role_user')->where('role_id', $this->getKey())->pluck('user_id') as $userId) {
            app(Auditor::class)->record("role.owner_{$verb}", $this->assignee((int) $userId));
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
        $model = Permissions::userModel() ?? config('auth.providers.users.model');

        if (! is_string($model) || ! class_exists($model) || ! is_subclass_of($model, Model::class)) {
            return null;
        }

        $user = $model::withoutGlobalScopes()->find($userId);

        return $user instanceof Model ? $user : null;
    }
}
