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
 * The receipt for a blueprint applied into an org — ADR-039.
 *
 * `architecture.md` has published this table since the architecture was written; this is the migration
 * catching up to it, with the column list unchanged.
 *
 * ⚠️ `org_id` IS NOT NULL, WHICH IS THE WHOLE AXIS DECISION. ADR-039 settles that a blueprint is applied INTO
 * an org and may never create global (`org_id` NULL) rows — that is the line between it and ADR-038's modules,
 * which are code installed for the whole installation. Three things follow from the column being NOT NULL, and
 * they are the argument for it: the apply is auditable, because `audit_log.org_id` is NOT NULL too and an
 * install-level act has no org to file under; `UNIQUE (org_id, handle)` actually constrains, because NULLs
 * compare distinct on every engine and a nullable column would let the same blueprint be applied twice with no
 * complaint; and what the blueprint installs is adaptable in the admin, which a global entry type is not —
 * `EntryTypeResource::ownsRecord()` returns false for a null org, so its shape is editable nowhere.
 *
 * ⚠️ AGENTS.md §4 BITES HERE AND IS OBEYED. The composite unique index leads with `org_id`, the scope key, so
 * the index is usable by the scoped reads that always carry it. No carve-out is claimed: unlike `sites`, a
 * blueprint handle does not need to be globally unique — two orgs applying Blog is the ordinary case.
 *
 * ⚠️ `applied_at` RATHER THAN `created_at`/`updated_at`. The row is a receipt, not a record with a history: it
 * says this org has this version of this blueprint, as of then. A re-apply moves `version` and `applied_at`
 * forward on the same row rather than writing a second one — which is what makes the unique index meaningful
 * rather than an obstacle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blueprints', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('org_id')->constrained()->cascadeOnDelete();

            /* The blueprint's own name — `blog`, `marketing-site`. Not a Composer package name: ADR-039 keeps
             * the mechanism in core and lets the payload live anywhere, so a handle is not a package. */
            $table->string('handle');

            /* What was applied. A blueprint declares its own version; the receipt records which one this org
             * got, so a later apply can tell an upgrade from a re-run of the same thing. */
            $table->string('version');

            /*
             * ⚠️ WHAT WAS APPLIED, AS APPLIED — not a progress log, and not the blueprint's source.
             *
             * ADR-030 requires that re-applying upgrades rather than clobbers, and a merge needs three inputs:
             * the new bundle, the bundle as it was originally applied, and the current state of the rows. The
             * repository stores none of the second today, which is why "upgrade" could only ever have meant
             * "overwrite". This column is that second input.
             *
             * It records the RESOLVED declaration — every type, field and setting the apply actually wrote,
             * including the ones it adopted rather than created — so a later version can tell an operator's
             * deliberate edit from drift the blueprint should correct. Nothing reads it yet, and ADR-039's
             * `Enforced by` says so: it is written now because it cannot be reconstructed later.
             */
            $table->json('manifest')->nullable();

            /*
             * ⚠️ NULLABLE, BECAUSE THE ROW IS WRITTEN BEFORE THE WORK. The receipt is an intent record: it is
             * committed first, outside the transaction that writes the rows, so that a crash mid-apply leaves
             * something to find. `applied_at` null therefore means "an apply started and did not finish", which
             * is a state a retry can recognise and a completed apply never shows. `manifest` is null for the
             * same window, because there is nothing applied yet to record.
             */
            $table->timestamp('applied_at')->nullable();

            $table->unique(['org_id', 'handle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blueprints');
    }
};
