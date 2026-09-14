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
 * RBAC storage — ADR-033, issue #81.
 *
 * Two tables here and a third in the skeleton. `roles` and `role_permissions` are org-owned
 * configuration, like `entry_types`, so core owns them. `role_user` references the host application's
 * `users` table, which core did not create and cannot constrain, so it lives beside `org_user` and
 * `site_user` in the skeleton for the reason those do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('org_id')->constrained()->cascadeOnDelete();
            $table->string('handle');
            $table->string('name');

            /*
             * ⚠️ THE BOOTSTRAP HOLE, AND IT IS A FLAG RATHER THAN A MAGIC HANDLE. Somebody has to be able
             * to create the first entry type, which is before any permission naming that type can exist.
             * A role named `owner` would make the bypass depend on a string an org can rename; a column
             * makes it a property of the role.
             *
             * ⚠️ AND THE BYPASS ITSELF IS NOT AUDITED — the ASSIGNMENT is. Issue #81 proposed the reverse
             * and ADR-033 withdraws it on volume: an authorization check runs per row, so the entry list
             * alone fires ten `view` checks a page and a bulk delete fires one per record. ADR-020's log is
             * for actions, and "was allowed to look at a row" is not one.
             */
            $table->boolean('is_owner')->default(false);
            $table->timestamps();

            // ADR-021: composite indexes lead with the scope key. A handle is unique within an org and
            // deliberately not across the installation — two customers may both have an `editor`.
            $table->unique(['org_id', 'handle']);
        });

        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();

            /*
             * ⚠️ A STRING RATHER THAN A FOREIGN KEY, and ADR-033 records the trade. A normalised
             * `permissions` table would need rows created and destroyed as entry types come and go, so
             * deleting a type would become a cascade decision about authorization — a content-modelling
             * change turning into a security change, whose failure mode is silent over-permission.
             *
             * The cost is that a misspelled grant is silently never granted. `Permissions::validate()` is
             * the mitigation and it fails closed: a grant whose shape or action is not on the published
             * registry is refused with the reason, the `pii_class` pattern from ADR-020.
             */
            $table->string('permission');

            /*
             * ⚠️ LEADING WITH `role_id` SATISFIES INVARIANT 4 BY THE INVARIANT'S OWN ARGUMENT. It requires
             * a composite index to lead with the scope key, because `site_id` "is globally unique and
             * belongs to exactly one org, so leading with it enforces org isolation transitively". A role
             * is globally unique and belongs to exactly one org. This table carries no `org_id` of its own
             * precisely so it cannot drift from the role's — the alternative is a denormalised copy and a
             * guard to keep it honest, which is a mechanism to maintain in exchange for a join this never
             * makes.
             */
            $table->unique(['role_id', 'permission']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
    }
};
