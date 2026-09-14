<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Auth;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Tenancy\Context;

/**
 * Revoke a user's role assignments through the audited path before the user row goes.
 *
 * ⚠️ THE CASCADE IS A LOCK-OUT WAITING TO HAPPEN, which review found in the skeleton's own migration.
 * `role_user.user_id` references the host's users table, so deleting a user removed every assignment they
 * held — silently. No `role.unassigned` rows, no `role.owner_unassigned` rows, and
 * `refuseLosingTheLastOwner()` never consulted: delete the only effective owner's user and the organisation
 * can no longer administer roles or edit its schema, with nothing in the log to say what happened. ADR-033
 * promises that an authority change is audited and that the last owner cannot be taken away; a foreign key
 * knows neither rule.
 *
 * ⚠️ AN OBSERVER RATHER THAN A TRAIT, because core owns no user model and must not. The host attaches it with
 * `#[ObservedBy(RevokesRoleAssignments::class)]`, which is the same greppable, declarative shape
 * `#[OrgScopedThroughPivot]` uses — and unlike a trait it is shipped code that the analyser can see, rather
 * than a file whose only consumers live outside the package.
 *
 * ⚠️ AND THE MIGRATION'S FOREIGN KEY IS `restrictOnDelete()` BESIDE IT, so a host that has not attached this
 * fails loudly instead of quietly losing an owner. Belt and braces, and the braces are the database's.
 */
class RevokesRoleAssignments
{
    /**
     * ⚠️ NOT ON A SOFT DELETE. A soft-deleted user cannot authenticate, so their assignments confer nothing
     * while the row is trashed — and revoking them would mean a restore silently returned somebody with no
     * authority. The cascade only fires on a real delete, so this fires where the cascade would.
     */
    public function deleting(Model $user): void
    {
        if (method_exists($user, 'isForceDeleting') && ! $user->isForceDeleting()) {
            return;
        }

        $id = $user->getKey();

        if ($id === null) {
            return;
        }

        $roleIds = DB::table('role_user')
            ->where('user_id', $id)
            ->pluck('role_id')
            ->all();

        if ($roleIds === []) {
            return;
        }

        $context = app(Context::class);
        $restore = $context->org();

        try {
            /*
             * ⚠️ THE ROLE'S OWN ORG, ONE AT A TIME, because `removeFrom()` refuses a role that does not
             * belong to the current context and derives its audit row from that context. A user may hold
             * roles in several organisations, and a revocation recorded under the wrong one is worse than
             * no row at all — that is the same reasoning `Role::refuseIfNotCurrentOrg()` records.
             */
            foreach (Role::query()->withoutGlobalScopes()->whereIn('id', $roleIds)->get() as $role) {
                $org = Org::query()->whereKey($role->org_id)->first();

                if (! $org instanceof Org) {
                    continue;
                }

                $context->setOrg($org);

                /*
                 * ⚠️ AND THE LAST-OWNER GUARD IS ALLOWED TO REFUSE. Deleting a user who holds the only
                 * effective owner role throws from here, so the deletion fails with a message naming the
                 * organisation instead of succeeding and locking it out. That is the point of routing
                 * through `removeFrom()` rather than letting the database do it.
                 */
                $role->removeFrom((int) $id);
            }
        } finally {
            $context->setOrg($restore);
        }
    }
}
