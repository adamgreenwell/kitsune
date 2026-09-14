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
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
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
        return DB::transaction(function () use ($ids, $touch): int {
            foreach ($this->pairsBeingRemoved($ids) as [$orgId, $userId]) {
                /*
                 * ⚠️ THE ORG ROW FIRST, the same mutex every authority change takes — see
                 * `Role::lockSharedOrgRow()`. Without it this read joins the cycle it was written to avoid.
                 */
                Org::query()->withoutGlobalScopes()->whereKey($orgId)->lockForUpdate()->value('id');

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

            return (int) parent::detach($ids, $touch);
        });
    }

    /**
     * The (org, user) pairs this detach would remove.
     *
     * ⚠️ EITHER END MAY BE THE PARENT. The skeleton hangs this off `User::orgs()`, but a host may just as
     * reasonably write `Org::members()` — and guessing wrong would check the wrong pair and guard nothing.
     * The parent's own class settles it rather than the argument's shape.
     *
     * @param  mixed  $ids
     * @return list<array{0: int, 1: int}>
     */
    private function pairsBeingRemoved($ids): array
    {
        $given = $this->parseIds($ids);

        $fromOrg = $this->parent instanceof Org;

        $others = $given !== []
            ? array_map(intval(...), $given)
            : $this->newPivotQuery()
                ->pluck($fromOrg ? $this->relatedPivotKey : $this->foreignPivotKey)
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

        $parentKey = (int) $this->parent->getKey();

        return array_map(
            static fn (int $other): array => $fromOrg ? [$parentKey, $other] : [$other, $parentKey],
            array_values(array_unique($others)),
        );
    }
}
