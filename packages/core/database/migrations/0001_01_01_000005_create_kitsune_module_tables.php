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
 * The kernel's receipt for a module it has installed — ADR-038.
 *
 * ADR-038 decided the kernel is "a record and a refusal". This is the record: one row per module the
 * installation has installed, saying which version was installed and whether it is on. The refusal lives in
 * `ModuleManifest` and `ModuleVerifier`.
 *
 * ⚠️ AN ABSENT ROW MEANS DISABLED, AND `is_enabled` DEFAULTS TO FALSE. This is the opposite of
 * `entry_type_availability`, where absent means available, and the difference is real rather than an
 * inconsistency: an absent availability row means an org has not narrowed a type that already exists, while an
 * absent module row means the kernel has no receipt for the code at all. Kitsune is a fail-closed house, and
 * the failure being closed here is "do not run code nobody recorded installing".
 *
 * ⚠️ NO `settings` COLUMN, THOUGH `architecture.md` PUBLISHED ONE. Module settings belong in ADR-022's
 * org → site group → site store, which shipped in Phase 3 and already carries provenance, audited writes and
 * invalidation. A second settings mechanism beside it would be a second set of bugs, and the published sketch
 * predates the store existing. ADR-038 amends that document rather than implementing it.
 *
 * ⚠️ NO `org_modules` TABLE. ADR-038 defers per-org enablement until a consumer asks for it: Phase 3 ships one
 * switch, on or off for the installation. Building per-org enablement now would mean a second inheritance
 * model beside ADR-022's, invented before anything needs it.
 *
 * ⚠️ AGENTS.md §4 DOES NOT BITE HERE, and the reason is worth stating rather than leaving to a reader to
 * re-derive. The rule is that a composite index leads with the scope key, and its carve-out is for a scoped
 * model that nonetheless needs a globally unique name. `modules` is `#[Unscoped]` — code is code, installed
 * once for the installation, exactly as `orgs` is — so there is no scope key for an index to lead with and no
 * exemption being claimed. A future per-org table WOULD be scoped and its indexes would lead with `org_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table): void {
            $table->id();

            /*
             * The Composer package name — `kitsune/person`. Unique because a package is installed once: two
             * rows for one handle would be two receipts for one module, and the kernel could not say which
             * version it booted.
             */
            $table->string('handle')->unique();

            /*
             * What Composer reported at install. Kept so an upgrade is VISIBLE: the lifecycle compares this
             * against what Composer reports now, and a module whose code moved under a stale receipt is
             * refused rather than booted against a schema its migrations have not reached.
             */
            $table->string('version');

            /*
             * ⚠️ DEFAULT FALSE. An installed module is not a running one — install puts the code in place and
             * runs its migrations; enabling is the separate, deliberate act. A default of true would mean a
             * row created by any path at all turns code on, which is the fail-open reading of the one column
             * whose whole job is to be a switch.
             */
            $table->boolean('is_enabled')->default(false);

            $table->timestamp('installed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
