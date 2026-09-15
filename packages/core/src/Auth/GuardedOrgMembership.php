<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Auth;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Audit\Auditor;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Tenancy\Context;
use RuntimeException;

/**
 * Org membership that cannot quietly take an organisation's last administrator away.
 *
 * ⚠️ MEMBERSHIP IS THE OTHER HALF OF AUTHORITY, and every guard on this branch protected only one of them.
 * `Permissions` requires a role assignment AND membership of the org, so `$user->orgs()->detach($orgId)`
 * takes somebody's authority away exactly as removing their role does — and a `belongsToMany` detach fires
 * no model event, consults no guard and writes no audit row. The surviving `role_user` row then resolves
 * nothing, and the organisation has nobody who can administer it: the lock-out ADR-033 exists to prevent,
 * reached by removing a different row.
 *
 * ⚠️ AND IT IS AN AUTHORITY CHANGE IN BOTH DIRECTIONS, which review made plain one round later. Removing a
 * role holder's membership takes every grant they hold there; attaching it again gives them all back, because
 * the assignments survived. So both are treated the way `Role` treats an assignment: one transaction, the org
 * row first, an audit row naming the person, and the permission memo dropped once it commits.
 *
 * ⚠️ A RELATION RATHER THAN AN OBSERVER, because Laravel fires no events for `attach()` and `detach()` — not
 * even with a pivot model. `GuardedBelongsToMany` makes the same move one layer over for entry relations,
 * for the same reason: the only place a pivot write can be guarded is the relation that performs it.
 *
 * ⚠️ AND THE HOST OPTS IN, because core owns no user model. The skeleton's `User::orgs()` returns this, which
 * is what makes the reference host correct rather than merely documented.
 *
 * @extends BelongsToMany<Org, Model, Pivot>
 */
class GuardedOrgMembership extends BelongsToMany
{
    /**
     * `sync()`, `syncWithoutDetaching()` and `toggle()` attach through here, the same way they detach below.
     *
     * @param  mixed  $ids
     * @param  array<string, mixed>  $attributes
     * @param  bool  $touch
     * @return void
     */
    public function attach($ids, array $attributes = [], $touch = true)
    {
        DB::transaction(function () use ($ids, $attributes, $touch): void {
            $pairs = $this->pairsFor($this->attachedIds($ids));

            $this->lockOrgRows($pairs);

            // Only a membership that did not exist is one being given; attaching an existing one changes nothing.
            $joining = array_values(array_filter($pairs, fn (array $pair): bool => ! $this->isMember(...$pair)));

            parent::attach($ids, $attributes, $touch);

            $this->recordAuthorityChange($joining, 'added');
        });

        Permissions::forget();
    }

    /**
     * @param  mixed  $ids
     * @param  bool  $touch
     * @return int
     */
    public function detach($ids = null, $touch = true)
    {
        /*
         * ⚠️ `sync()` DETACHES THROUGH HERE TOO, which is why only this method is overridden: Laravel's
         * `sync()` collects what must go and calls `$this->detach($detach)`. Overriding both would be two
         * copies of one rule.
         */
        $removed = DB::transaction(function () use ($ids, $touch): int {
            $others = $this->detachedIds($ids);
            $pairs = $this->pairsFor($others);

            $this->lockOrgRows($pairs);

            foreach ($pairs as [$orgId, $userId]) {
                if (! Role::orgWouldLoseItsLastOwner($orgId, $userId)) {
                    continue;
                }

                throw new RuntimeException(sprintf(
                    'Refusing to remove user %s from organisation %s: they are the last member of it '
                    .'holding an owner role, and owner is the only role that may administer roles or edit '
                    .'the schema (ADR-033). Membership and the assignment are two halves of the same '
                    .'authority, so taking either away leaves nobody able to put it back.',
                    (string) $userId,
                    (string) $orgId,
                ));
            }

            $leaving = array_values(array_filter($pairs, fn (array $pair): bool => $this->isMember(...$pair)));

            /*
             * ⚠️ THE MEMBERSHIPS JUST CHECKED, NOT WHATEVER EXISTS BY THE TIME OF THE DELETE — review found the
             * gap. A detach of every membership read the list, locked and checked those orgs, then deleted every
             * row the user had: a membership another transaction attached after the read was never locked,
             * checked or audited, and went anyway. Naming the ids that were read makes the delete the set the
             * guard saw; an attach that lands after the read survives, and is detached by whoever asks next.
             */
            $removed = (int) parent::detach($ids ?? $others, $touch);

            $this->recordAuthorityChange($leaving, 'removed');

            return $removed;
        });

        /*
         * ⚠️ AFTER THE COMMIT, AS EVERY ROLE AUTHORITY CHANGE DOES — review found this missing. `isOwner()` and
         * `held()` memoise per request, so a check made before the detach went on granting the departed user
         * their owner bypass and their grants for the rest of the request or job. A rollback drops the memo
         * through `TransactionRolledBack` instead.
         */
        Permissions::forget();

        return $removed;
    }

    /**
     * ⚠️ `sync()` IS ONE CHANGE, AND LARAVEL MAKES IT TWO — review found it. It calls `detach()` and then `attach()`,
     * each of which commits on its own, so a sync that removed one membership and then failed to add another — a
     * foreign key naming an org that does not exist — had already committed and audited the removal, and reported
     * failure after taking the person's authority away. One transaction around the call makes a sync stand or fall
     * as the single change it was asked for. `syncWithoutDetaching()` and `syncWithPivotValues()` come through here.
     *
     * @param  mixed  $ids
     * @param  bool  $detaching
     * @return array<string, array<int, mixed>>
     */
    public function sync($ids, $detaching = true)
    {
        return DB::transaction(fn (): array => parent::sync($ids, $detaching));
    }

    /**
     * `toggle()` detaches and attaches in turn as well, so it is one transaction for the same reason as `sync()`.
     *
     * @param  mixed  $ids
     * @param  bool  $touch
     * @return array<string, array<int, mixed>>
     */
    public function toggle($ids, $touch = true)
    {
        return DB::transaction(fn (): array => parent::toggle($ids, $touch));
    }

    /**
     * ⚠️ A MEMBERSHIP'S KEYS ARE THE MEMBERSHIP, SO CHANGING ONE IS A DETACH AND AN ATTACH — review found this door
     * open. `updateExistingPivot()` writes the pivot row directly and calls neither override above, so
     * `$user->orgs()->updateExistingPivot($old, ['org_id' => $new])` moved the last owner out of an org with no
     * last-owner check, no audit row, no lock and no memo drop. A key change is refused with the way to make it;
     * any other column a host's pivot carries is updated as before.
     *
     * @param  mixed  $id
     * @param  array<string, mixed>  $attributes
     * @param  bool  $touch
     * @return int
     */
    public function updateExistingPivot($id, array $attributes, $touch = true)
    {
        foreach (array_keys($attributes) as $column) {
            $bare = str_contains($column, '.') ? substr($column, (int) strrpos($column, '.') + 1) : $column;

            if ($bare === $this->foreignPivotKey || $bare === $this->relatedPivotKey) {
                throw new RuntimeException(sprintf(
                    'Refusing to change [%s] on an org membership in place: the keys are the membership, so moving '
                    .'one removes a person from one organisation and adds them to another, and both of those go '
                    .'through detach() and attach(), where the last-owner guard, the audit and the permission memo '
                    .'are (ADR-033).',
                    $column,
                ));
            }
        }

        return parent::updateExistingPivot($id, $attributes, $touch);
    }

    /**
     * The other end of each membership an attach names, read the way Laravel reads it.
     *
     * ⚠️ `attach([5 => ['role' => …]])` NAMES 5, NOT ITS ATTRIBUTES. Laravel's `extractAttachIdAndAttributes()`
     * takes the key when the value is an array, so this does too — casting the value would have named org 1.
     *
     * @param  mixed  $ids
     * @return list<int|string>
     */
    private function attachedIds($ids): array
    {
        $others = [];

        foreach ($this->parseIds($ids) as $key => $value) {
            $others[] = is_array($value) ? $key : $value;
        }

        return $this->otherEnds($others);
    }

    /**
     * The other end of each membership a detach removes, read the way Laravel reads it.
     *
     * ⚠️ NULL MEANS EVERY MEMBERSHIP AND AN EMPTY LIST MEANS NONE, because that is what `detach()` does with
     * each. The earlier version treated both as "every", so `detach([])` ran the last-owner check over pairs
     * nothing was going to remove.
     *
     * @param  mixed  $ids
     * @return list<int|string>
     */
    private function detachedIds($ids): array
    {
        if ($ids !== null) {
            return $this->otherEnds(array_values($this->parseIds($ids)));
        }

        /*
         * ⚠️ THE RELATED PIVOT KEY FROM EITHER END, and this picked the parent's own column from the user's side.
         * Whichever model is the parent, `relatedPivotKey` names the other end — so plucking `foreignPivotKey`
         * for `User::orgs()` returned the user's own id once per membership, and `$user->orgs()->detach()`
         * asked the last-owner guard about "organisation <user id>" while removing every real membership,
         * the last owner's included. Found while ordering the locks.
         */
        return $this->otherEnds($this->newPivotQuery()->pluck($this->relatedPivotKey)->all());
    }

    /**
     * The other end of each membership, in the type that end's key has.
     *
     * ⚠️ AN ORG ID IS CORE'S AND A USER KEY IS THE HOST'S — #91. Both ends were cast to `int`, which is right for
     * `Org` and wrong for a host whose users carry ULIDs: `(int) '01J…'` is `1`, so `$org->members()->detach('01J…')`
     * checked, locked and audited the membership of whoever user 1 is. Which end is the other one is settled by the
     * parent's class, as `pairsFor()` settles it.
     *
     * @param  array<mixed>  $ids
     * @return list<int|string>
     */
    private function otherEnds(array $ids): array
    {
        if ($this->parent instanceof Org) {
            return Permissions::userKeys($ids, $this->related::class);
        }

        return array_values(array_map(static fn (mixed $id): int => (int) $id, $ids));
    }

    /**
     * The (org, user) pairs a write names, in the order their locks are taken.
     *
     * ⚠️ EITHER END MAY BE THE PARENT. The skeleton hangs this off `User::orgs()`, but a host may just as
     * reasonably write `Org::members()` — and guessing wrong would check the wrong pair and guard nothing.
     * The parent's own class settles it rather than the argument's shape.
     *
     * ⚠️ SORTED, BECAUSE EACH PAIR TAKES AN ORG MUTEX — review found the cycle the deletion sweep had already
     * been fixed out of. Two detaches naming the same orgs in opposite orders each held the first org row while
     * waiting for the other's; input order is the caller's, and a detach-all's is whatever plan the database
     * chose. `(org, user)` is one order for every write through this relation.
     *
     * @param  list<int|string>  $others
     * @return list<array{0: int, 1: int|string}>
     */
    private function pairsFor(array $others): array
    {
        $unique = array_values(array_unique($others));

        if ($this->parent instanceof Org) {
            $orgId = (int) $this->parent->getKey();
            $pairs = array_map(static fn (int|string $user): array => [$orgId, $user], $unique);
        } else {
            $userKey = Permissions::userKey($this->parent->getKey(), $this->parent::class);

            if ($userKey === null) {
                return [];
            }

            $pairs = array_map(static fn (int|string $org): array => [(int) $org, $userKey], $unique);
        }

        usort($pairs, static fn (array $a, array $b): int => $a <=> $b);

        return $pairs;
    }

    /**
     * Take each named org's row, in pair order — the mutex every authority change takes.
     *
     * ⚠️ See `Role::lockSharedOrgRow()`. Without it the last-owner read joins the cycle it was written to avoid,
     * and the audit below could name an owner flag a concurrent demotion was changing.
     *
     * @param  list<array{0: int, 1: int|string}>  $pairs
     */
    private function lockOrgRows(array $pairs): void
    {
        foreach (array_values(array_unique(array_column($pairs, 0))) as $orgId) {
            Org::query()->withoutGlobalScopes()->whereKey($orgId)->lockForUpdate()->value('id');
        }
    }

    private function isMember(int $orgId, int|string $userId): bool
    {
        [$orgColumn, $userColumn] = $this->parent instanceof Org
            ? [$this->foreignPivotKey, $this->relatedPivotKey]
            : [$this->relatedPivotKey, $this->foreignPivotKey];

        return $this->newPivotStatement()->where($orgColumn, $orgId)->where($userColumn, $userId)->exists();
    }

    /**
     * Record the authority a membership change gave or took, under the organisation it happened in.
     *
     * ⚠️ ONLY WHERE AUTHORITY MOVED. A member who holds no role in the org gains or loses nothing `Permissions`
     * resolves, so their membership is not an authority change and writes no row. A role holder's is, and the
     * trail otherwise went on showing assignments with nothing to say when, or at whose hand, they stopped
     * meaning anything.
     *
     * ⚠️ UNDER THE ORG THE MEMBERSHIP BELONGS TO, switched for the row and restored after, because an audit row
     * takes its org from the context and the caller's context may name another org or none. The deletion
     * observer does the same, for the same reason; see `RevokesRoleAssignments`.
     *
     * ⚠️ OWNER-NESS IN THE ACTION, as ADR-033 puts it for assignments: `org.owner_removed` beside
     * `org.member_removed`, because whether the bypass went with them is the fact the log exists to answer.
     *
     * @param  list<array{0: int, 1: int|string}>  $pairs
     */
    private function recordAuthorityChange(array $pairs, string $verb): void
    {
        if ($pairs === []) {
            return;
        }

        $context = app(Context::class);

        // The site too, because `setOrg()` clears a site that belongs to another org — see the observer.
        $restoreOrg = $context->org();
        $restoreSite = $context->site();

        try {
            foreach ($pairs as [$orgId, $userId]) {
                $owner = $this->holdsOwnerRole($orgId, $userId);

                if ($owner === null) {
                    continue;
                }

                $org = Org::query()->withTrashed()->whereKey($orgId)->first();

                if (! $org instanceof Org) {
                    continue;
                }

                $context->setOrg($org);

                app(Auditor::class)->record(
                    sprintf('org.%s_%s', $owner ? 'owner' : 'member', $verb),
                    $this->userModel($userId),
                );
            }
        } finally {
            if ($restoreSite !== null) {
                $context->setSite($restoreSite);
            } else {
                $context->setOrg($restoreOrg);
            }
        }
    }

    /**
     * Whether this user holds an owner role in this org — or null when they hold no role there at all.
     *
     * ⚠️ AND NULL WHEN `role_user` IS NOT ABOUT THIS RELATION'S USERS. Its `user_id` names the table its foreign
     * key references, so a relation over some other user model shares ids with somebody else's assignments —
     * and `Permissions` resolves none of them for this model. Reading them here would audit an authority change
     * that did not happen, about a person it did not happen to.
     */
    private function holdsOwnerRole(int $orgId, int|string $userId): ?bool
    {
        $userClass = $this->parent instanceof Org ? $this->related::class : $this->parent::class;

        if (! Permissions::assignmentsAreAbout($userClass)) {
            return null;
        }

        $roleIds = DB::table('role_user')->where('user_id', $userId)->pluck('role_id')->all();

        if ($roleIds === []) {
            return null;
        }

        $flags = Role::query()
            ->withoutGlobalScopes()
            ->where('org_id', $orgId)
            ->whereIn('id', $roleIds)
            ->pluck('is_owner');

        if ($flags->isEmpty()) {
            return null;
        }

        return $flags->contains(static fn (mixed $flag): bool => (bool) $flag);
    }

    /** The user a membership is about, as a model, so the audit row can name them. */
    private function userModel(int|string $userId): ?Model
    {
        if (! $this->parent instanceof Org) {
            return $this->parent;
        }

        return $this->related->newQueryWithoutScopes()->find($userId);
    }
}
