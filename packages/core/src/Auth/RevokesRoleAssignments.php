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

        /*
         * ⚠️ IN THE KEY TYPE THIS MODEL HAS, NOT CAST — #91. The sweep read the assignments by the real key and then
         * revoked through `removeFrom((int) $id)`, and `(int) '01J…'` is `1`: on a ULID-keyed host it would have tried to
         * take roles away from whoever user 1 is.
         */
        $key = Permissions::userKey($user->getKey(), $user::class);

        if ($key === null) {
            return;
        }

        $roleIds = DB::table('role_user')
            ->where('user_id', $key)
            ->pluck('role_id')
            ->all();

        if ($roleIds === []) {
            return;
        }

        $context = app(Context::class);

        /*
         * ⚠️ THE SITE TOO, because `setOrg()` CLEARS IT when the site belongs to another org — review found
         * the request coming back from this sweep with no site at all. Everything site-scoped afterwards then
         * fails closed, and any audit row written later loses its site attribution. Restoring the site is what
         * restores the org as well (setting a site implies its org), so the org is only put back on its own
         * when there was no site to begin with.
         */
        $restoreOrg = $context->org();
        $restoreSite = $context->site();

        try {
            /*
             * ⚠️ ONE TRANSACTION FOR THE WHOLE SWEEP, which review found missing and which is this project's
             * most familiar failure: two individually correct operations, wrong together. A user holding
             * roles in several orgs could have the first revoked and audited and the second refused by the
             * last-owner guard — leaving authority permanently removed from somebody the deletion then
             * spared. All of it or none of it.
             *
             * ⚠️ WHAT THIS DOES NOT COVER IS STATED RATHER THAN IMPLIED: `Model::delete()` opens no
             * transaction of its own, so if the DELETE itself fails after this commits, the revocations
             * stand. A host that needs the pair atomic wraps the call — `DB::transaction(fn () =>
             * $user->delete())` — and with the assignments gone, the restrictive foreign key that made this
             * observer necessary is no longer a reason for that delete to fail.
             */
            DB::transaction(function () use ($context, $roleIds, $key): void {
                /*
                 * ⚠️ THE ROLE'S OWN ORG, ONE AT A TIME, because `removeFrom()` refuses a role that does not
                 * belong to the current context and derives its audit row from that context. A user may hold
                 * roles in several organisations, and a revocation recorded under the wrong one is worse than
                 * no row at all — that is the same reasoning `Role::refuseIfNotCurrentOrg()` records.
                 */
                /*
                 * ⚠️ A DETERMINISTIC ORDER, BECAUSE EACH ITERATION TAKES AN ORG MUTEX. Review found the
                 * cycle: two users holding roles in the same pair of organisations, swept concurrently,
                 * can meet those orgs in opposite orders — and each outer transaction keeps the first org
                 * lock while waiting for the other's. Unordered, the order comes from whatever plan the
                 * database chose, which is not a guarantee at all. `(org_id, id)` is shared by every sweep,
                 * so two of them queue instead of holding each other's rows.
                 */
                foreach (
                    Role::query()
                        ->withoutGlobalScopes()
                        ->whereIn('id', $roleIds)
                        ->orderBy('org_id')
                        ->orderBy('id')
                        ->get() as $role
                ) {
                    /*
                     * ⚠️ `withTrashed()`, because an org can be soft-deleted and its roles' assignments
                     * cannot. Review found the gap the restrictive foreign key opened: skipping a trashed
                     * org's role left its pivot row in place, so the deletion died on a constraint error
                     * instead of being either audited or refused.
                     */
                    $org = Org::query()->withTrashed()->whereKey($role->org_id)->first();

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
                    $role->removeFrom($key);
                }
            });
        } finally {
            if ($restoreSite !== null) {
                $context->setSite($restoreSite);
            } else {
                $context->setOrg($restoreOrg);
            }
        }
    }
}
