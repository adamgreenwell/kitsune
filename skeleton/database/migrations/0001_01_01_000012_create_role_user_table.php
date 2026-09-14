<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Role assignment — ADR-033, issue #81.
 *
 * In the skeleton rather than in core, alongside `org_user` and `site_user`, because it references the
 * host application's `users` table — core does not own the user model and cannot constrain a table it did
 * not create. The consequence is stated in ADR-033: core's RBAC enforces nothing until the host has run
 * this migration, which is already true of org scoping.
 *
 * ⚠️ A ROW PAIRING A USER WITH A ROLE IN AN ORG THEY DO NOT BELONG TO RESOLVES NOTHING, and no constraint
 * here is what makes that true. Resolution runs through the org-scoped `Role` query under the current org
 * context, so a foreign role is simply not among the rows that come back — fail-closed by construction
 * rather than by a check somebody has to remember. `RoleIsolationTest` asserts it from the attacker's
 * side, because "it cannot happen" is a claim and not a test.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_user', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            /*
             * ⚠️ RESTRICT RATHER THAN CASCADE, which review found the difference between. A cascade removes
             * every assignment a deleted user held — with no `role.unassigned` row, and without consulting
             * the guard that refuses taking the last owner away, so deleting one person could lock an
             * organisation out of role and schema administration permanently (ADR-033).
             *
             * `RevokesRoleAssignmentsOnDeletion` is the path that makes an ordinary deletion work: it
             * revokes through `Role::removeFrom()` first, so by the time the user row goes there is nothing
             * here to restrict. This constraint is what happens to a host that has not applied it — a loud
             * failure instead of a quiet loss of authority.
             */
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->primary(['role_id', 'user_id']);

            // The resolver reads the other direction — "which roles does this user hold?" — and the
            // primary key's leading column cannot serve it. The same index `org_user` carries, for the
            // same reason.
            $table->index(['user_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
    }
};
